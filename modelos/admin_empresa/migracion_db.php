<?php

/**
 * Módulo de Migración de Base de Datos
 * Permite copiar estructura y datos entre bases de datos
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

$action = $_GET['action'] ?? '';

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Para APIs, retornar JSON
    if ($action) {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['success' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]));
    }
    die("Error de conexión: " . $e->getMessage());
}

// ======================= API: Obtener bases de datos =======================
if ($action === 'get_databases') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        // Obtener lista de BDs con su tamaño
        $stmt = $pdo->query("
            SELECT 
                SCHEMA_NAME as name,
                ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
            GROUP BY SCHEMA_NAME
            ORDER BY SCHEMA_NAME
        ");
        $databases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Si no hay datos, obtener al menos la lista de esquemas
        if (empty($databases)) {
            $stmt = $pdo->query("SELECT SCHEMA_NAME as name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys') ORDER BY SCHEMA_NAME");
            $databases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['success' => true, 'databases' => $databases]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================= API: Obtener tablas de BD =======================
if ($action === 'get_tables') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $dbName = trim($_GET['db'] ?? '');
        if (empty($dbName)) {
            echo json_encode(['success' => false, 'error' => 'Base de datos no especificada']);
            exit;
        }

        $stmt = $pdo->query("SELECT TABLE_NAME as name FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$dbName}' ORDER BY TABLE_NAME");
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'tables' => $tables]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================= API: Migrar BD =======================
if ($action === 'migrate') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $sourceDb = trim($payload['source_db'] ?? '');
        $targetDb = trim($payload['target_db'] ?? '');
        $includeData = (bool)($payload['include_data'] ?? false);
        $dropTarget = (bool)($payload['drop_target'] ?? false);

        if (empty($sourceDb) || empty($targetDb)) {
            echo json_encode(['success' => false, 'error' => 'Base de datos de origen y destino son obligatorias']);
            exit;
        }

        if ($sourceDb === $targetDb) {
            echo json_encode(['success' => false, 'error' => 'La base de datos de origen y destino no pueden ser iguales']);
            exit;
        }

        $result = [
            'source' => $sourceDb,
            'target' => $targetDb,
            'include_data' => $includeData,
            'tables_migrated' => 0,
            'tables_errors' => 0,
            'total_rows' => 0,
            'migrated_rows' => 0,
            'errors' => [],
            'tables_details' => []
        ];

        // 1. Verificar que la BD de origen existe
        $checkSource = $pdo->query("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$sourceDb}'")->fetch();
        if (!$checkSource) {
            echo json_encode(['success' => false, 'error' => "Base de datos de origen '{$sourceDb}' no existe"]);
            exit;
        }

        // 2. Si existe BD de destino y drop_target es true, eliminarla
        $checkTarget = $pdo->query("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$targetDb}'")->fetch();
        if ($checkTarget && $dropTarget) {
            $pdo->exec("DROP DATABASE `{$targetDb}`");
        }

        // 3. Crear BD de destino si no existe
        if (!$checkTarget || $dropTarget) {
            $pdo->exec("CREATE DATABASE `{$targetDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        // 4. Obtener todas las tablas de la BD de origen
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$sourceDb}' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);

        // 5. Migrar cada tabla
        foreach ($tables as $table) {
            try {
                $tableResult = [
                    'table' => $table,
                    'status' => 'success',
                    'rows' => 0,
                    'error' => null
                ];

                // Obtener estructura de la tabla
                $createStmt = $pdo->query("SHOW CREATE TABLE `{$sourceDb}`.`{$table}`")->fetch(PDO::FETCH_ASSOC);
                $createSql = $createStmt['Create Table'];

                // Adaptar CREATE TABLE para la nueva BD
                $createSql = str_replace("CREATE TABLE `{$table}`", "CREATE TABLE IF NOT EXISTS `{$table}`", $createSql);

                // Ejecutar CREATE TABLE en la BD de destino
                $pdo->exec("USE `{$targetDb}`");
                $pdo->exec($createSql);
                $pdo->exec("USE `{$masterDb}`");

                // Copiar datos si está habilitado
                if ($includeData) {
                    $pdo->exec("INSERT INTO `{$targetDb}`.`{$table}` SELECT * FROM `{$sourceDb}`.`{$table}`");

                    // Contar filas migradas
                    $rowCount = $pdo->query("SELECT COUNT(*) FROM `{$targetDb}`.`{$table}`")->fetchColumn();
                    $tableResult['rows'] = (int)$rowCount;
                    $result['migrated_rows'] += $rowCount;
                }

                // Contar filas en origen
                $sourceRowCount = $pdo->query("SELECT COUNT(*) FROM `{$sourceDb}`.`{$table}`")->fetchColumn();
                $result['total_rows'] += $sourceRowCount;

                $result['tables_migrated']++;
                $result['tables_details'][] = $tableResult;
            } catch (Exception $e) {
                $result['tables_errors']++;
                $result['errors'][] = "Error en tabla '{$table}': " . $e->getMessage();
                $result['tables_details'][] = [
                    'table' => $table,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'data' => $result
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ======================= Verificar permisos de administrador para la página HTML =======================
if (!isset($_SESSION['usr_priv_admin']) || !$_SESSION['usr_priv_admin']) {
    die('<h1>Acceso Denegado</h1><p>Se requieren permisos de administrador para acceder a este módulo.</p>');
}

?>
<!DOCTYPE html>
<html lang="es" x-data="migracionApp()" class="h-full">

<head>
    <meta charset="utf-8">
    <title>Migración de Base de Datos</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        [x-cloak] {
            display: none;
        }

        .progress-bar {
            background: linear-gradient(90deg, #3b82f6, #2dd4bf);
            transition: width 0.3s ease;
        }

        .table-card {
            border-left: 4px solid #3b82f6;
        }

        .table-card.error {
            border-left-color: #ef4444;
        }

        .table-card.success {
            border-left-color: #10b981;
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-gray-900 h-full">
    <div class="min-h-screen flex flex-col">
        <!-- Header -->
        <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 p-6">
            <div class="max-w-7xl mx-auto">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white">
                    <i class="fas fa-database mr-2"></i> Migración de Base de Datos
                </h1>
                <p class="text-gray-600 dark:text-gray-300 mt-2">Copia de estructura y datos entre bases de datos</p>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 max-w-7xl w-full mx-auto p-6">

            <!-- Formulario -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-8 mb-8" x-show="!migrando && !resultado">

                <!-- Servidores Origen -->
                <div class="mb-8 p-4 bg-green-50 dark:bg-green-900/30 rounded-lg border border-green-200 dark:border-green-800">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                        <i class="fas fa-arrow-right text-green-600 mr-2"></i> Servidor de Origen
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Host Origen -->
                        <div>Armagedon

                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Host/IP</label>
                            <input type="text" x-model="form.source_server" placeholder="localhost" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-green-500">
                        </div>
                        <!-- Puerto Origen -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Puerto</label>
                            <input type="text" x-model="form.source_port" placeholder="3306" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-green-500">
                        </div>
                        <!-- Usuario Origen -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Usuario</label>
                            <input type="text" x-model="form.source_user" placeholder="root" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-green-500">
                        </div>
                        <!-- Contraseña Origen -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contraseña</label>
                            <input type="password" x-model="form.source_pass" placeholder="••••••••" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-green-500">
                        </div>
                    </div>
                </div>

                <!-- Servidores Destino -->
                <div class="mb-8 p-4 bg-blue-50 dark:bg-blue-900/30 rounded-lg border border-blue-200 dark:border-blue-800">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                        <i class="fas fa-arrow-right text-blue-600 mr-2"></i> Servidor de Destino
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Host Destino -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Host/IP</label>
                            <input type="text" x-model="form.target_server" placeholder="localhost" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-blue-500">
                        </div>
                        <!-- Puerto Destino -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Puerto</label>
                            <input type="text" x-model="form.target_port" placeholder="3306" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-blue-500">
                        </div>
                        <!-- Usuario Destino -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Usuario</label>
                            <input type="text" x-model="form.target_user" placeholder="root" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-blue-500">
                        </div>
                        <!-- Contraseña Destino -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contraseña</label>
                            <input type="password" x-model="form.target_pass" placeholder="••••••••" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <!-- BD Origen -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-database mr-2"></i> Base de Datos de Origen
                        </label>
                        <select x-model="form.source_db" @change="cargarTablasOrigen()" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-blue-500">
                            <option value="">Seleccionar base de datos...</option>
                            <template x-for="db in databases" :key="db.name">
                                <option :value="db.name" x-text="db.size_mb ? `${db.name} (${db.size_mb} MB)` : db.name"></option>
                            </template>
                        </select>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            <span x-text="`${databases.length} bases de datos disponibles`"></span>
                            <span x-show="form.source_db" class="ml-2" x-text="`| ${tablasOrigen.length} tablas seleccionadas`"></span>
                        </p>
                    </div>

                    <!-- BD Destino -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-arrow-right mr-2"></i> Base de Datos de Destino
                        </label>
                        <select x-model="form.target_db" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-blue-500">
                            <option value="">Seleccionar existente...</option>
                            <template x-for="db in databases" :key="db.name">
                                <option :value="db.name" x-text="db.size_mb ? `${db.name} (${db.size_mb} MB)` : db.name"></option>
                            </template>
                        </select>
                        <input type="text" x-model="form.target_db_new" placeholder="O escriba nombre de nueva BD aquí" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-blue-500 mt-2">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Selecciona una existente o escribe el nombre de una nueva</p>
                    </div>
                </div>

                <!-- Opciones -->
                <div class="space-y-4 mb-8">
                    <div class="flex items-center">
                        <input type="checkbox" x-model="form.include_data" id="include_data" class="h-4 w-4 rounded">
                        <label for="include_data" class="ml-3 text-gray-700 dark:text-gray-300">
                            <strong>Incluir datos</strong> - Copiar registros además de estructura
                        </label>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" x-model="form.drop_target" id="drop_target" class="h-4 w-4 rounded">
                        <label for="drop_target" class="ml-3 text-gray-700 dark:text-gray-300">
                            <strong>Eliminar BD destino</strong> - Borra la BD destino si existe (crear nueva)
                        </label>
                    </div>
                </div>

                <!-- Botones -->
                <div class="flex gap-4">
                    <button @click="iniciarMigracion()" :disabled="!puedeIniciar()" class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white font-bold py-3 px-6 rounded-lg transition">
                        <i class="fas fa-arrow-right mr-2"></i> Iniciar Migración
                    </button>
                    <button @click="cargarDatabases()" class="px-6 py-3 bg-gray-300 dark:bg-gray-700 hover:bg-gray-400 dark:hover:bg-gray-600 text-gray-900 dark:text-white rounded-lg transition">
                        <i class="fas fa-sync mr-2"></i> Refrescar
                    </button>
                </div>
            </div>

            <!-- Progreso -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-8" x-show="migrando">
                <div class="text-center mb-6">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full mb-4">
                        <i class="fas fa-spinner fa-spin text-blue-600 dark:text-blue-400 text-2xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Migrando...</h2>
                    <p class="text-gray-600 dark:text-gray-300 mt-2">Procesando tablas de la base de datos</p>
                </div>

                <div class="mb-4">
                    <div class="flex justify-between text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <span>Progreso</span>
                        <span x-text="`${progreso}%`"></span>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                        <div class="progress-bar h-2 rounded-full" :style="`width: ${progreso}%`"></div>
                    </div>
                </div>

                <div class="text-center text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <p x-text="`${tablaActual}/${tablasOrigen.length} tablas procesadas`"></p>
                </div>

                <div class="flex gap-4 justify-center">
                    <button @click="cancelarMigracion()"
                            :disabled="cancelando"
                            class="px-6 py-3 bg-red-600 hover:bg-red-700 disabled:bg-gray-400 text-white font-bold rounded-lg transition flex items-center gap-2">
                        <i class="fas fa-times mr-2"></i>
                        <span x-text="cancelando ? 'Cancelando...' : 'Cancelar Migración'"></span>
                    </button>
                </div>
            </div>

            <!-- Resultado -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-8" x-show="resultado">
                <!-- Alerta de Cancelación -->
                <div x-show="resultado.cancelada" class="bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4 mb-8">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-exclamation-triangle text-yellow-600 dark:text-yellow-400 text-lg"></i>
                        <div>
                            <h4 class="font-bold text-yellow-800 dark:text-yellow-300">Migración Cancelada</h4>
                            <p class="text-sm text-yellow-700 dark:text-yellow-400" x-text="resultado.mensaje"></p>
                        </div>
                    </div>
                </div>

                <!-- Resumen -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                    <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg">
                        <p class="text-sm text-gray-600 dark:text-gray-400">Tablas Migradas</p>
                        <p class="text-3xl font-bold text-blue-600 dark:text-blue-400" x-text="resultado.tables_migrated"></p>
                    </div>
                    <div class="bg-red-50 dark:bg-red-900/30 p-4 rounded-lg" x-show="resultado.tables_errors > 0">
                        <p class="text-sm text-gray-600 dark:text-gray-400">Errores</p>
                        <p class="text-3xl font-bold text-red-600 dark:text-red-400" x-text="resultado.tables_errors"></p>
                    </div>
                    <div class="bg-green-50 dark:bg-green-900/30 p-4 rounded-lg">
                        <p class="text-sm text-gray-600 dark:text-gray-400">Filas Total (Origen)</p>
                        <p class="text-3xl font-bold text-green-600 dark:text-green-400" x-text="resultado.total_rows.toLocaleString()"></p>
                    </div>
                    <div class="bg-purple-50 dark:bg-purple-900/30 p-4 rounded-lg" x-show="form.include_data">
                        <p class="text-sm text-gray-600 dark:text-gray-400">Filas Migradas</p>
                        <p class="text-3xl font-bold text-purple-600 dark:text-purple-400" x-text="resultado.migrated_rows.toLocaleString()"></p>
                    </div>
                </div>

                <!-- Detalle de Tablas -->
                <div class="mb-8">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Detalles de Migración</h3>
                    <div class="space-y-2 max-h-96 overflow-y-auto">
                        <template x-for="table in resultado.tables_details" :key="table.table">
                            <div class="table-card p-4 bg-gray-50 dark:bg-gray-700 rounded" :class="table.status">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900 dark:text-white" x-text="table.table"></p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400" x-text="table.status === 'success' ? `${table.rows.toLocaleString()} filas migradas` : table.error"></p>
                                    </div>
                                    <div class="text-2xl">
                                        <i x-show="table.status === 'success'" class="fas fa-check text-green-500"></i>
                                        <i x-show="table.status === 'error'" class="fas fa-times text-red-500"></i>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Errores -->
                <div x-show="resultado.errors.length > 0" class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg p-4 mb-8">
                    <h4 class="font-bold text-red-800 dark:text-red-300 mb-2">Errores durante la migración:</h4>
                    <ul class="space-y-1">
                        <template x-for="error in resultado.errors" :key="error">
                            <li class="text-sm text-red-700 dark:text-red-400" x-text="error"></li>
                        </template>
                    </ul>
                </div>

                <!-- Botones -->
                <div class="flex gap-4">
                    <button @click="reiniciar()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg transition">
                        <i class="fas fa-arrow-left mr-2"></i> Nueva Migración
                    </button>
                    <button @click="descargarReporte()" class="px-6 py-3 bg-gray-300 dark:bg-gray-700 hover:bg-gray-400 dark:hover:bg-gray-600 text-gray-900 dark:text-white rounded-lg transition">
                        <i class="fas fa-download mr-2"></i> Descargar Reporte
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function migracionApp() {
            const app = {
                databases: [],
                tablasOrigen: [],
                form: {
                    source_server: '<?php echo $_SESSION['server'] ?? 'localhost'; ?>',
                    source_port: '3306',
                    source_user: '<?php echo $_SESSION['user'] ?? 'root'; ?>',
                    source_pass: '',
                    source_db: '',
                    target_server: '',
                    target_port: '3306',
                    target_user: 'root',
                    target_pass: '',
                    target_db: '',
                    target_db_new: '',
                    include_data: false,
                    drop_target: false
                },
                migrando: false,
                resultado: null,
                progreso: 0,
                tablaActual: 0,
                abortController: null,
                cancelando: false,

                async cargarDatabases() {
                    try {
                        const response = await fetch('?action=get_databases');
                        const data = await response.json();
                        if (data.success) {
                            this.databases = data.databases;
                            console.log('BDs cargadas:', this.databases.length);
                        } else {
                            console.error('Error al cargar BDs:', data.error);
                        }
                    } catch (error) {
                        console.error('Error al cargar bases de datos:', error);
                        alert('Error al cargar bases de datos: ' + error);
                    }
                },

                async cargarTablasOrigen() {
                    if (!this.form.source_db) return;
                    try {
                        const response = await fetch(`?action=get_tables&db=${encodeURIComponent(this.form.source_db)}`);
                        const data = await response.json();
                        if (data.success) {
                            this.tablasOrigen = data.tables;
                            console.log('Tablas cargadas:', this.tablasOrigen.length);
                        } else {
                            console.error('Error al cargar tablas:', data.error);
                        }
                    } catch (error) {
                        console.error('Error al cargar tablas:', error);
                        alert('Error al cargar tablas: ' + error);
                    }
                },

                puedeIniciar() {
                    return this.form.source_db && (this.form.target_db || this.form.target_db_new);
                },

                async iniciarMigracion() {
                    if (!confirm('¿Iniciar migración de base de datos?')) return;

                    this.migrando = true;
                    this.cancelando = false;
                    this.progreso = 0;
                    this.tablaActual = 0;
                    this.abortController = new AbortController();

                    const targetDb = this.form.target_db_new || this.form.target_db;

                    try {
                        const response = await fetch('?action=migrate', {
                            method: 'POST',
                            signal: this.abortController.signal,
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                source_db: this.form.source_db,
                                target_db: targetDb,
                                include_data: this.form.include_data,
                                drop_target: this.form.drop_target
                            })
                        });

                        if (!response.ok && this.cancelando) {
                            return;
                        }

                        const data = await response.json();

                        if (data.success && !this.cancelando) {
                            this.resultado = data.data;
                            this.progreso = 100;
                        } else if (!this.cancelando) {
                            alert('Error: ' + data.error);
                        }
                    } catch (error) {
                        if (error.name === 'AbortError') {
                            console.log('Migración cancelada por el usuario');
                            this.resultado = {
                                source: this.form.source_db,
                                target: targetDb,
                                cancelada: true,
                                mensaje: 'Migración cancelada por el usuario'
                            };
                        } else {
                            alert('Error en migración: ' + error);
                        }
                    } finally {
                        this.migrando = false;
                        this.abortController = null;
                    }
                },

                cancelarMigracion() {
                    if (!confirm('¿Realmente desea cancelar la migración? Se detendrá el proceso.')) {
                        return;
                    }

                    this.cancelando = true;
                    if (this.abortController) {
                        this.abortController.abort();
                    }
                },

                reiniciar() {
                    this.resultado = null;
                    this.progreso = 0;
                    this.tablaActual = 0;
                    this.form = {
                        source_server: '<?php echo $_SESSION['server'] ?? 'localhost'; ?>',
                        source_port: '3306',
                        source_user: '<?php echo $_SESSION['user'] ?? 'root'; ?>',
                        source_pass: '',
                        source_db: '',
                        target_server: '',
                        target_port: '3306',
                        target_user: 'root',
                        target_pass: '',
                        target_db: '',
                        target_db_new: '',
                        include_data: false,
                        drop_target: false
                    };
                    this.cargarDatabases();
                },

                descargarReporte() {
                    const reporte = `
REPORTE DE MIGRACIÓN DE BASE DE DATOS
======================================
Fecha: ${new Date().toLocaleString()}
BD Origen: ${this.resultado.source}
BD Destino: ${this.resultado.target}

RESUMEN
-------
Tablas migradas: ${this.resultado.tables_migrated}
Errores: ${this.resultado.tables_errors}
Total de filas (origen): ${this.resultado.total_rows.toLocaleString()}
${this.resultado.include_data ? `Filas migradas: ${this.resultado.migrated_rows.toLocaleString()}` : ''}

DETALLE DE TABLAS
-----------------
${this.resultado.tables_details.map(t => 
    `${t.table}: ${t.status === 'success' ? `✓ ${t.rows.toLocaleString()} filas` : `✗ ${t.error}`}`
).join('\n')}

${this.resultado.errors.length > 0 ? '\nERRORES\n-------\n' + this.resultado.errors.join('\n') : ''}
                    `;

                    const blob = new Blob([reporte], {
                        type: 'text/plain'
                    });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `migracion_${this.resultado.source}_${this.resultado.target}_${Date.now()}.txt`;
                    a.click();
                    window.URL.revokeObjectURL(url);
                },

                init() {
                    console.log('Inicializando módulo de migración...');
                    this.cargarDatabases();
                }
            };

            // Llamar init automáticamente
            setTimeout(() => app.init(), 100);

            return app;
        }

        // Asegurar que se carga después de Alpine
        document.addEventListener('alpine:init', () => {
            console.log('Alpine inicializado');
        });

        window.addEventListener('load', () => {
            console.log('Página completamente cargada');
            const el = document.querySelector('[x-data="migracionApp()"]');
            if (el && el.__x && el.__x.cargarDatabases) {
                el.__x.cargarDatabases();
            }
        });
    </script>
</body>

</html>