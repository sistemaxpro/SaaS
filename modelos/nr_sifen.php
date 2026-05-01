<?php

/**
 * Notas de Remisión Electrónicas - Vista Principal con AG Grid
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_empresa = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int) $_SESSION['id_empresa'] : 169;

$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

// Constante para tipo de documento NR (Nota de Remisión Electrónica)
define('TIPO_DOC_NR', 7);

// Variables de configuración SIFEN
$nombreEmpresa = 'Notas de Remisión';
$ruc = '';
$dv = '';
$razonSocial = '';
$modo = 'test';
$cert_pass = '';
$cert_path = '';
$cert_nombre = '';
$idc = '';
$csc = '';
$empresaDb = '';
$timbrado = '';
$timbradoFecha = '';
$establecimiento = '001';
$punto_expedicion = '001';
$ruc_empresa = '';

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->exec("SET NAMES utf8");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Query para obtener TODA la configuración desde habilitacion_sifen
    $sqlHabilitacion = "
    SELECT 
        h.ruc,
        h.dv,
        h.razon_social,
        h.empresa,
        h.numero_timbrado,
        h.fecha_inicio_vigencia,
        h.csc,
        h.id_csc,
        h.cert_nombre,
        h.cert_pass,
        h.cert_path,
        h.ambiente,
        h.cod_depto,
        h.cod_ciudad,
        h.direccion,
        h.numero_casa,
        h.telefono,
        h.email,
        d.codigo_establecimiento,
        d.punto_expedicion,
        e.dbase
    FROM $masterDb.habilitacion_sifen h
    JOIN $masterDb.habilitacion_sifen_documentos d ON d.id_habilitacion = h.id
    LEFT JOIN $masterDb.empresa e ON e.id_empresa = h.id_empresa
    WHERE h.id_empresa = :id_empresa 
      AND d.tipo_documento = :tipo_documento
      AND h.activo = 1
      AND d.activo = 1
    ORDER BY h.id DESC
    LIMIT 1
    ";

    $stmtHab = $pdo->prepare($sqlHabilitacion);
    $stmtHab->execute([':id_empresa' => $id_empresa, ':tipo_documento' => TIPO_DOC_NR]);

    if ($rowHab = $stmtHab->fetch(PDO::FETCH_ASSOC)) {
        // Datos del emisor
        $ruc = trim((string) $rowHab['ruc']);
        $dv = trim((string) $rowHab['dv']);
        $razonSocial = trim((string) $rowHab['razon_social']);
        $nombreEmpresa = trim((string) $rowHab['empresa']) ?: $razonSocial;
        $empresaDb = trim((string) $rowHab['dbase']);
        $ruc_empresa = $ruc . '-' . $dv;

        // Datos del timbrado
        $timbrado = trim((string) $rowHab['numero_timbrado']);
        $timbradoFecha = trim((string) $rowHab['fecha_inicio_vigencia']);

        // Establecimiento y punto de expedición (ya vienen formateados a 3 dígitos)
        $establecimiento = trim((string) $rowHab['codigo_establecimiento']) ?: '001';
        $punto_expedicion = trim((string) $rowHab['punto_expedicion']) ?: '001';

        // Credenciales SIFEN
        $csc = trim((string) $rowHab['csc']);
        $idc = trim((string) $rowHab['id_csc']);
        $cert_pass = trim((string) $rowHab['cert_pass']);
        $cert_nombre = trim((string) $rowHab['cert_nombre']);
        $cert_path_dir = trim((string) $rowHab['cert_path']);

        // Construir ruta completa del certificado
        if ($cert_path_dir && $cert_nombre) {
            $cert_path = rtrim($cert_path_dir, '/') . '/' . $cert_nombre;
        } elseif ($cert_nombre) {
            $cert_path = __DIR__ . '/_lib/php-sifen3/certificados/' . $cert_nombre;
        } else {
            $cert_path = __DIR__ . '/_lib/php-sifen3/certificados/' . $ruc . '.p12';
        }

        // Ambiente
        $amb_hab = strtolower(trim((string) $rowHab['ambiente']));
        if ($amb_hab === 'prod' || $amb_hab === '2') {
            $modo = 'prod';
        } else {
            $modo = 'test';
        }

        // Datos adicionales para el emisor
        $emisorData = [
            'cod_depto' => (int) $rowHab['cod_depto'],
            'cod_ciudad' => (int) $rowHab['cod_ciudad'],
            'direccion' => trim((string) $rowHab['direccion']),
            'numero_casa' => trim((string) $rowHab['numero_casa']),
            'telefono' => trim((string) $rowHab['telefono']),
            'email' => trim((string) $rowHab['email']),
        ];
    } else {
        // Fallback: obtener datos básicos de empresa si no hay habilitación
        $stmt = $pdo->prepare("SELECT empresa, timbrado, ruc, dv, dbase FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmt->execute([':id' => $id_empresa]);
        $rowEmpresa = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rowEmpresa) {
            $nombreEmpresa = $rowEmpresa['empresa'] ?? $nombreEmpresa;
            $timbrado = $rowEmpresa['timbrado'] ?? '';
            $ruc = $rowEmpresa['ruc'] ?? '';
            $dv = $rowEmpresa['dv'] ?? '';
            $ruc_empresa = $ruc . '-' . $dv;
            $empresaDb = $rowEmpresa['dbase'] ?? "smx_{$id_empresa}";
        } else {
            $empresaDb = "smx_{$id_empresa}";
        }
        error_log("NR_SIFEN: No se encontró habilitación para empresa $id_empresa y tipo_documento " . TIPO_DOC_NR);
    }

    // Resolver ruta del certificado
    $resolvedCertPath = '';
    if ($cert_path && file_exists($cert_path)) {
        $resolvedCertPath = realpath($cert_path);
    } elseif ($ruc) {
        $fallbackPath = __DIR__ . '/_lib/php-sifen3/certificados/' . preg_replace('/\D+/', '', $ruc) . '.p12';
        if (file_exists($fallbackPath)) {
            $resolvedCertPath = realpath($fallbackPath);
        }
    }

    // Configuración para SIFEN
    $configFe = [
        'ruc' => $ruc,
        'dv' => $dv,
        'razon_social' => $razonSocial,
        'modo' => $modo,
        'cert_pass' => $cert_pass,
        'cert_path' => $resolvedCertPath,
        'idc' => $idc,
        'csc' => $csc,
        'timbrado' => $timbrado,
        'establecimiento' => $establecimiento,
        'punto_expedicion' => $punto_expedicion,
        'timbradoFecha' => $timbradoFecha,
    ];

    // Crear tablas de nota de remisión si no existen
    crearTablasNotaRemision($pdo, $empresaDb, $masterDb);
} catch (Exception $e) {
    // Silenciar
    error_log("NR_SIFEN Error: " . $e->getMessage());
}

/**
 * Crea las tablas de nota de remisión en la base de datos de la empresa si no existen
 */
function crearTablasNotaRemision($pdo, $empresaDb, $masterDb)
{
    // Verificar si la tabla nota_remision existe y tiene la estructura correcta
    $stmt = $pdo->query("SHOW TABLES FROM $empresaDb LIKE 'nota_remision'");
    $tableExists = $stmt->fetch();

    $needsRecreate = false;

    if ($tableExists) {
        // Verificar si tiene la columna id_remision (estructura correcta)
        $stmt = $pdo->query("SHOW COLUMNS FROM $empresaDb.nota_remision LIKE 'id_remision'");
        $hasIdRemision = $stmt->fetch();
        if (!$hasIdRemision) {
            // Tabla existe pero con estructura incorrecta, verificar si está vacía
            $stmt = $pdo->query("SELECT COUNT(*) FROM $empresaDb.nota_remision");
            $count = $stmt->fetchColumn();
            if ($count == 0) {
                $needsRecreate = true;
                $pdo->exec("DROP TABLE IF EXISTS $empresaDb.nota_remision");
            }
        }
    }

    if (!$tableExists || $needsRecreate) {
        // Crear tabla nota_remision
        $sql = "CREATE TABLE IF NOT EXISTS $empresaDb.nota_remision (
            id_remision INT(11) NOT NULL AUTO_INCREMENT,
            id_empresa INT(11) NOT NULL,
            nro_documento VARCHAR(20) NOT NULL,
            fecha DATE NOT NULL,
            fecha_inicio_traslado DATE NULL,
            fecha_fin_traslado DATE NULL,
            motivo_remision INT(11) NOT NULL,
            motivo_descripcion VARCHAR(255) NULL,
            establecimiento VARCHAR(3) NULL DEFAULT '001',
            punto_expedicion VARCHAR(3) NULL DEFAULT '001',
            receptor_ruc VARCHAR(20) NULL,
            receptor_dv VARCHAR(2) NULL,
            receptor_nombre VARCHAR(255) NULL,
            receptor_direccion VARCHAR(255) NULL,
            receptor_ciudad INT(11) NULL,
            receptor_ciudad_nombre VARCHAR(100) NULL,
            conductor_documento VARCHAR(20) NULL,
            conductor_nombre VARCHAR(255) NULL,
            conductor_direccion VARCHAR(255) NULL,
            vehiculo_tipo VARCHAR(50) NULL,
            vehiculo_marca VARCHAR(50) NULL,
            vehiculo_chapa VARCHAR(20) NULL,
            km_estimado DECIMAL(10,2) NULL DEFAULT '0.00',
            timbrado VARCHAR(20) NULL,
            cdc VARCHAR(50) NULL,
            xml_firmado LONGTEXT NULL,
            xml_respuesta LONGTEXT NULL,
            estado_sifen ENUM('Pendiente','Enviado','Aprobado','Rechazado','Anulado') NULL DEFAULT 'Pendiente',
            mensaje_sifen TEXT NULL,
            fecha_aprobacion DATETIME NULL,
            cdc_asociado VARCHAR(50) NULL,
            timbrado_asociado VARCHAR(20) NULL,
            estado ENUM('activo','anulado') NULL DEFAULT 'activo',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_remision),
            INDEX idx_id_empresa (id_empresa),
            INDEX idx_fecha (fecha),
            INDEX idx_cdc (cdc),
            INDEX idx_estado_sifen (estado_sifen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($sql);
    }

    // Agregar columnas establecimiento y punto_expedicion si no existen (para tablas existentes)
    try {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM $empresaDb.nota_remision LIKE 'establecimiento'");
        if (!$stmtCol->fetch()) {
            $pdo->exec("ALTER TABLE $empresaDb.nota_remision 
                ADD COLUMN establecimiento VARCHAR(3) NULL DEFAULT '001' AFTER motivo_descripcion,
                ADD COLUMN punto_expedicion VARCHAR(3) NULL DEFAULT '001' AFTER establecimiento");
        }
    } catch (Exception $e) {
        // Ignorar error si las columnas ya existen
    }

    // Agregar columna id_sucursal si no existe
    try {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM $empresaDb.nota_remision LIKE 'id_sucursal'");
        if (!$stmtCol->fetch()) {
            $pdo->exec("ALTER TABLE $empresaDb.nota_remision 
                ADD COLUMN id_sucursal INT(11) NULL AFTER id_empresa");
        }
    } catch (Exception $e) {
        // Ignorar error si la columna ya existe
    }

    // Verificar si la tabla nota_remision_items existe
    $stmt = $pdo->query("SHOW TABLES FROM $empresaDb LIKE 'nota_remision_items'");
    if (!$stmt->fetch()) {
        $sql = "CREATE TABLE IF NOT EXISTS $empresaDb.nota_remision_items (
            id_item INT(11) NOT NULL AUTO_INCREMENT,
            id_remision INT(11) NOT NULL,
            id_producto INT(11) NULL,
            codigo VARCHAR(50) NOT NULL,
            descripcion VARCHAR(255) NOT NULL,
            cantidad DECIMAL(15,4) NOT NULL DEFAULT '1.0000',
            unidad_medida VARCHAR(10) NULL DEFAULT '77',
            precio_unitario DECIMAL(15,2) NULL DEFAULT '0.00',
            id_factura_origen INT(11) NULL,
            PRIMARY KEY (id_item),
            INDEX idx_id_remision (id_remision)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($sql);
    }

    // Agregar columna id_producto si no existe
    try {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM $empresaDb.nota_remision_items LIKE 'id_producto'");
        if (!$stmtCol->fetch()) {
            $pdo->exec("ALTER TABLE $empresaDb.nota_remision_items 
                ADD COLUMN id_producto INT(11) NULL AFTER id_remision");
        }
    } catch (Exception $e) {
        // Ignorar error si la columna ya existe
    }

    // Agregar columna id_factura_origen si no existe
    try {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM $empresaDb.nota_remision_items LIKE 'id_factura_origen'");
        if (!$stmtCol->fetch()) {
            $pdo->exec("ALTER TABLE $empresaDb.nota_remision_items 
                ADD COLUMN id_factura_origen INT(11) NULL AFTER precio_unitario");
        }
    } catch (Exception $e) {
        // Ignorar error si la columna ya existe
    }

    // Verificar si la tabla remisiones_motivos existe en masterDb (serproc1)
    $stmt = $pdo->query("SHOW TABLES FROM $masterDb LIKE 'remisiones_motivos'");
    if (!$stmt->fetch()) {
        $sql = "CREATE TABLE IF NOT EXISTS $masterDb.remisiones_motivos (
            codigo INT(11) NOT NULL,
            descripcion VARCHAR(255) NOT NULL,
            PRIMARY KEY (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($sql);
    }

    // Verificar si la tabla remisiones_motivos tiene datos, si no, insertar motivos estándar
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM $masterDb.remisiones_motivos");
    $countMotivos = $stmtCount->fetchColumn();

    if ($countMotivos == 0) {
        // Insertar motivos estándar de SIFEN
        $motivos = [
            [1, 'Traslado por ventas'],
            [2, 'Traslado por consignación'],
            [3, 'Exportación'],
            [4, 'Traslado por compra'],
            [5, 'Importación'],
            [6, 'Traslado por devolución'],
            [7, 'Traslado entre locales de la empresa'],
            [8, 'Traslado de bienes por transformación'],
            [9, 'Traslado de bienes por reparación'],
            [10, 'Traslado por emisor móvil'],
            [11, 'Exhibición o demostración'],
            [12, 'Participación en ferias'],
            [13, 'Traslado de encomienda'],
            [14, 'Decomiso'],
            [99, 'Otro']
        ];

        $stmt = $pdo->prepare("INSERT INTO $masterDb.remisiones_motivos (codigo, descripcion) VALUES (?, ?)");
        foreach ($motivos as $motivo) {
            try {
                $stmt->execute($motivo);
            } catch (Exception $e) {
                // Ignorar duplicados
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Notas de Remisión Electrónicas - <?php echo htmlspecialchars($nombreEmpresa); ?></title>
    <link rel="stylesheet" href="_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css">
    <link rel="stylesheet" href="_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="_lib/ag-grid/ag-grid-enterprise/package/dist/ag-grid-enterprise.min.noStyle.js"></script>
    <script src="_lib/ag-grid/license.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: {
                            850: '#151e2e'
                        }
                    }
                }
            }
        }
    </script>
    <script>
        (function() {
            window.idEmpresaGlobal = <?php echo $id_empresa; ?>;
            const savedTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && systemDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <style>
        /* Botones estilo chip */
        .btn-chip {
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 400;
            cursor: pointer;
            border: 1px solid #3b82f6;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            color: #2d7be5;
            box-shadow: none;
            transition: all 0.2s;
            font-size: 13px;
        }

        .btn-chip:hover {
            background: rgba(59, 130, 246, 0.05);
            transform: translateY(-1px);
        }

        .btn-chip--primary {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        .btn-chip--primary:hover {
            background: #2563eb;
        }

        .btn-chip--success {
            border-color: #22c55e;
            color: #22c55e;
        }

        .btn-chip--success:hover {
            background: rgba(34, 197, 94, 0.05);
        }

        .btn-chip--danger {
            border-color: #ef4444;
            color: #ef4444;
        }

        .btn-chip--danger:hover {
            background: rgba(239, 68, 68, 0.05);
        }

        .btn-chip--warning {
            border-color: #f59e0b;
            color: #f59e0b;
        }

        .btn-chip--warning:hover {
            background: rgba(245, 158, 11, 0.05);
        }

        /* Dark mode para btn-chip */
        html.dark .btn-chip {
            border-color: #60a5fa;
            color: #60a5fa;
        }

        html.dark .btn-chip:hover {
            background: rgba(96, 165, 250, 0.1);
        }

        html.dark .btn-chip--primary {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        html.dark .btn-chip--danger {
            border-color: #f87171;
            color: #f87171;
        }

        html.dark .btn-chip--success {
            border-color: #4ade80;
            color: #4ade80;
        }

        /* Seleccionar todo al enfocar inputs */
        input[type="text"]:focus,
        input[type="number"]:focus,
        input[type="date"]:focus,
        input[type="search"]:focus {
            user-select: all;
        }

        .ag-theme-quartz {
            --ag-font-family: "Poppins", sans-serif;
            --ag-font-size: 13px;
            --ag-odd-row-background-color: rgba(0, 0, 0, 0.05);
        }

        .ag-theme-quartz-dark {
            --ag-font-family: "Poppins", sans-serif;
            --ag-font-size: 13px;
            --ag-odd-row-background-color: rgba(255, 255, 255, 0.04);
        }

        html.dark .ag-theme-quartz-dark,
        html.dark .ag-theme-quartz {
            --ag-background-color: #0b121e !important;
            --ag-foreground-color: #e2e8f0 !important;
            --ag-header-background-color: #0f172a !important;
            --ag-header-foreground-color: #94a3b8 !important;
            --ag-border-color: #1e293b !important;
            --ag-row-border-color: #1e293b !important;
            --ag-row-hover-color: #1e293b !important;
            --ag-selected-row-background-color: rgba(59, 130, 246, 0.15) !important;
            --ag-input-focus-border-color: #3b82f6 !important;
            --ag-data-color: #f1f5f9 !important;
            --ag-font-family: "Poppins", sans-serif;
        }

        .status-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-pendiente {
            background: #fef3c7;
            color: #92400e;
        }

        .status-enviado {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-aprobado {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rechazado {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-anulado {
            background: #e5e7eb;
            color: #374151;
        }

        [x-cloak] {
            display: none !important;
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-900 text-gray-900 dark:text-gray-100">
    <div x-data="nrApp()" x-init="init()" class="h-screen flex flex-col">

        <!-- Sistema de Notificaciones Toast -->
        <div class="fixed top-4 right-4 z-[9999] flex flex-col gap-2 max-w-md" style="pointer-events: none;">
            <template x-for="(toast, index) in toasts" :key="toast.id">
                <div x-show="toast.visible"
                    x-transition:enter="transform transition ease-out duration-300"
                    x-transition:enter-start="translate-x-full opacity-0"
                    x-transition:enter-end="translate-x-0 opacity-100"
                    x-transition:leave="transform transition ease-in duration-200"
                    x-transition:leave-start="translate-x-0 opacity-100"
                    x-transition:leave-end="translate-x-full opacity-0"
                    :class="{
                        'bg-green-50 dark:bg-green-900/50 border-green-500': toast.type === 'success',
                        'bg-red-50 dark:bg-red-900/50 border-red-500': toast.type === 'error',
                        'bg-yellow-50 dark:bg-yellow-900/50 border-yellow-500': toast.type === 'warning',
                        'bg-blue-50 dark:bg-blue-900/50 border-blue-500': toast.type === 'info',
                        'bg-white dark:bg-slate-800 border-gray-300': toast.type === 'loading'
                    }"
                    class="rounded-lg shadow-lg border-l-4 p-4 flex items-start gap-3 backdrop-blur-sm"
                    style="pointer-events: auto;">
                    <!-- Icono -->
                    <div class="flex-shrink-0">
                        <template x-if="toast.type === 'success'">
                            <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-800 flex items-center justify-center">
                                <i class="fa-solid fa-check text-green-600 dark:text-green-400"></i>
                            </div>
                        </template>
                        <template x-if="toast.type === 'error'">
                            <div class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-800 flex items-center justify-center">
                                <i class="fa-solid fa-xmark text-red-600 dark:text-red-400"></i>
                            </div>
                        </template>
                        <template x-if="toast.type === 'warning'">
                            <div class="w-8 h-8 rounded-full bg-yellow-100 dark:bg-yellow-800 flex items-center justify-center">
                                <i class="fa-solid fa-exclamation text-yellow-600 dark:text-yellow-400"></i>
                            </div>
                        </template>
                        <template x-if="toast.type === 'info'">
                            <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-800 flex items-center justify-center">
                                <i class="fa-solid fa-info text-blue-600 dark:text-blue-400"></i>
                            </div>
                        </template>
                        <template x-if="toast.type === 'loading'">
                            <div class="w-8 h-8 rounded-full bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                                <i class="fa-solid fa-spinner fa-spin text-gray-600 dark:text-gray-400"></i>
                            </div>
                        </template>
                    </div>
                    <!-- Contenido -->
                    <div class="flex-1 min-w-0">
                        <p x-show="toast.title" class="font-semibold text-gray-900 dark:text-gray-100" x-text="toast.title"></p>
                        <p class="text-sm text-gray-600 dark:text-gray-300" x-text="toast.message"></p>
                    </div>
                    <!-- Botón cerrar -->
                    <button x-show="toast.type !== 'loading'" @click="removeToast(toast.id)"
                        class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
            </template>
        </div>

        <!-- Modal de Confirmación -->
        <div x-show="confirmModal.show" x-cloak
            class="fixed inset-0 z-[9998] flex items-center justify-center bg-black/50 backdrop-blur-sm"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                @click.outside="confirmModal.show = false; confirmModal.onCancel && confirmModal.onCancel()">
                <!-- Icono -->
                <div class="pt-6 pb-2 flex justify-center">
                    <div :class="{
                        'bg-red-100 dark:bg-red-900/50': confirmModal.type === 'danger',
                        'bg-yellow-100 dark:bg-yellow-900/50': confirmModal.type === 'warning',
                        'bg-blue-100 dark:bg-blue-900/50': confirmModal.type === 'info'
                    }" class="w-16 h-16 rounded-full flex items-center justify-center">
                        <i :class="{
                            'fa-solid fa-trash text-red-600 dark:text-red-400 text-2xl': confirmModal.type === 'danger',
                            'fa-solid fa-exclamation-triangle text-yellow-600 dark:text-yellow-400 text-2xl': confirmModal.type === 'warning',
                            'fa-solid fa-question text-blue-600 dark:text-blue-400 text-2xl': confirmModal.type === 'info'
                        }"></i>
                    </div>
                </div>
                <!-- Contenido -->
                <div class="px-6 py-4 text-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100" x-text="confirmModal.title"></h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400" x-text="confirmModal.message"></p>
                </div>
                <!-- Botones -->
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700/50 flex gap-3 justify-end">
                    <button @click="confirmModal.show = false; confirmModal.onCancel && confirmModal.onCancel()"
                        class="px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-600 transition">
                        Cancelar
                    </button>
                    <button @click="confirmModal.show = false; confirmModal.onConfirm && confirmModal.onConfirm()"
                        :class="{
                            'bg-red-600 hover:bg-red-700': confirmModal.type === 'danger',
                            'bg-yellow-600 hover:bg-yellow-700': confirmModal.type === 'warning',
                            'bg-blue-600 hover:bg-blue-700': confirmModal.type === 'info'
                        }"
                        class="px-4 py-2 rounded-lg text-white font-medium transition"
                        x-text="confirmModal.confirmText || 'Confirmar'">
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal de Detalles/Info -->
        <div x-show="detalleModal.show" x-cloak
            class="fixed inset-0 z-[9998] flex items-center justify-center bg-black/50 backdrop-blur-sm"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-lg w-full mx-4 overflow-hidden"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                @click.outside="detalleModal.show = false">
                <!-- Icono -->
                <div class="pt-6 pb-2 flex justify-center">
                    <div :class="{
                        'bg-green-100 dark:bg-green-900/50': detalleModal.type === 'success',
                        'bg-red-100 dark:bg-red-900/50': detalleModal.type === 'error',
                        'bg-blue-100 dark:bg-blue-900/50': detalleModal.type === 'info'
                    }" class="w-16 h-16 rounded-full flex items-center justify-center">
                        <i :class="{
                            'fa-solid fa-circle-check text-green-600 dark:text-green-400 text-2xl': detalleModal.type === 'success',
                            'fa-solid fa-circle-exclamation text-red-600 dark:text-red-400 text-2xl': detalleModal.type === 'error',
                            'fa-solid fa-circle-info text-blue-600 dark:text-blue-400 text-2xl': detalleModal.type === 'info'
                        }"></i>
                    </div>
                </div>
                <!-- Contenido -->
                <div class="px-6 py-4 text-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100" x-text="detalleModal.title"></h3>
                    <pre class="mt-4 text-sm text-left text-gray-600 dark:text-gray-400 whitespace-pre-wrap bg-gray-50 dark:bg-slate-700/50 p-4 rounded-lg max-h-80 overflow-y-auto" x-text="detalleModal.content"></pre>
                </div>
                <!-- Botón -->
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700/50 flex justify-center">
                    <button @click="detalleModal.show = false"
                        :class="{
                            'bg-green-600 hover:bg-green-700': detalleModal.type === 'success',
                            'bg-red-600 hover:bg-red-700': detalleModal.type === 'error',
                            'bg-blue-600 hover:bg-blue-700': detalleModal.type === 'info'
                        }"
                        class="px-6 py-2 rounded-lg text-white font-medium transition">
                        Aceptar
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal de Loading -->
        <div x-show="loadingModal.show" x-cloak
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 backdrop-blur-sm"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-sm w-full mx-4 overflow-hidden"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100">
                <!-- Spinner -->
                <div class="pt-8 pb-4 flex justify-center">
                    <div class="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center">
                        <i class="fa-solid fa-spinner fa-spin text-blue-600 dark:text-blue-400 text-3xl"></i>
                    </div>
                </div>
                <!-- Mensaje -->
                <div class="px-6 py-4 text-center">
                    <p class="text-lg font-medium text-gray-900 dark:text-gray-100" x-text="loadingModal.message"></p>
                </div>
                <!-- Botón Cancelar -->
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700/50 flex justify-center">
                    <button @click="loadingModal.show = false"
                        class="px-6 py-2 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-600 transition">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal de Error -->
        <div x-show="errorModal.show" x-cloak
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 backdrop-blur-sm"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-md w-full mx-4 overflow-hidden"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100">
                <!-- Icono -->
                <div class="pt-6 pb-2 flex justify-center">
                    <div class="w-16 h-16 rounded-full bg-red-100 dark:bg-red-900/50 flex items-center justify-center">
                        <i class="fa-solid fa-circle-exclamation text-red-600 dark:text-red-400 text-3xl"></i>
                    </div>
                </div>
                <!-- Contenido -->
                <div class="px-6 py-4 text-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100" x-text="errorModal.title"></h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400" x-text="errorModal.message"></p>
                </div>
                <!-- Botón -->
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700/50 flex justify-center">
                    <button @click="errorModal.show = false"
                        class="px-6 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition">
                        Aceptar
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal de Impresión Preview -->
        <div x-show="printModal.show" x-cloak
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 backdrop-blur-sm"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-[90vw] h-[90vh] max-w-6xl overflow-hidden flex flex-col"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100">
                <!-- Header del modal -->
                <div class="flex items-center justify-between px-6 py-4 border-b dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <i class="fa-solid fa-print text-blue-600"></i>
                        <span x-text="printModal.title"></span>
                    </h3>
                    <div class="flex items-center gap-3">
                        <button @click="imprimirDesdeModal()"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition flex items-center gap-2">
                            <i class="fa-solid fa-print"></i> Imprimir
                        </button>
                        <button @click="window.open(printModal.url, '_blank')"
                            class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg font-medium transition flex items-center gap-2">
                            <i class="fa-solid fa-external-link"></i> Abrir en pestaña
                        </button>
                        <button @click="printModal.show = false"
                            class="p-2 rounded-lg hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-500 dark:text-gray-400 transition">
                            <i class="fa-solid fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                <!-- Iframe con el KUDE -->
                <div class="flex-1 bg-gray-200 dark:bg-slate-900">
                    <iframe id="printFrame" :src="printModal.url" class="w-full h-full border-0"></iframe>
                </div>
            </div>
        </div>

        <!-- Header -->
        <div class="bg-white dark:bg-slate-800 shadow-sm border-b dark:border-slate-700 px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <!-- Botón Volver -->
                    <button onclick="history.back()" class="p-2 rounded-lg bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-600 dark:text-gray-300" title="Volver">
                        <i class="fa-solid fa-arrow-left"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-truck text-blue-600 dark:text-blue-400 text-2xl"></i>
                        <div>
                            <h1 class="text-xl font-bold text-gray-800 dark:text-gray-100">Notas de Remisión Electrónicas</h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($nombreEmpresa); ?></p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Filtro de fecha -->
                    <div class="flex items-center gap-2">
                        <input type="date" x-model="filtroFechaDesde" @change="loadGridData()"
                            class="px-3 py-2 border rounded-lg text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-gray-200">
                        <span class="text-gray-500">a</span>
                        <input type="date" x-model="filtroFechaHasta" @change="loadGridData()"
                            class="px-3 py-2 border rounded-lg text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-gray-200">
                    </div>
                    <!-- Filtro estado -->
                    <select x-model="filtroEstado" @change="loadGridData()"
                        class="px-3 py-2 border rounded-lg text-sm dark:bg-slate-700 dark:border-slate-600 dark:text-gray-200">
                        <option value="">Todos los estados</option>
                        <option value="Pendiente">Pendiente</option>
                        <option value="Aprobado">Aprobado</option>
                        <option value="Rechazado">Rechazado</option>
                        <option value="Anulado">Anulado</option>
                    </select>
                    <!-- Botón Recargar -->
                    <button @click="loadGridData()" class="btn-chip" title="Recargar">
                        <i class="fa-solid fa-sync"></i>
                    </button>
                    <!-- Dropdown Más -->
                    <div x-data="{ openMas: false }" class="relative inline-block">
                        <button @click="openMas = !openMas" @click.away="openMas = false" class="btn-chip flex items-center gap-1">
                            <i class="fa-solid fa-bars"></i> Más <i class="fa-solid fa-chevron-down text-xs"></i>
                        </button>
                        <div x-show="openMas" x-cloak
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="transform opacity-0 scale-95"
                            x-transition:enter-end="transform opacity-100 scale-100"
                            class="absolute right-0 z-50 mt-2 w-48 origin-top-right rounded-md bg-white dark:bg-slate-800 shadow-lg ring-1 ring-black ring-opacity-5">
                            <div class="py-1">
                                <a href="facturas_sifen.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700">
                                    <i class="fa-solid fa-file-invoice w-5 text-center"></i> Facturas
                                </a>
                                <a href="nc_sifen.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700">
                                    <i class="fa-solid fa-file-circle-minus w-5 text-center"></i> Nota Crédito
                                </a>
                                <a href="nd_sifen.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700">
                                    <i class="fa-solid fa-file-circle-plus w-5 text-center"></i> Nota Débito
                                </a>
                            </div>
                        </div>
                    </div>
                    <!-- Botón Nueva NR -->
                    <button @click="editingId = null; nuevaRemision = getEmptyRemision(); destinatarioSearch = ''; conductorSearch = ''; vehiculoSearch = ''; showNuevaRemision = true"
                        class="btn-chip btn-chip--primary">
                        <i class="fa-solid fa-plus"></i>
                        Nueva Remisión
                    </button>
                </div>
            </div>
        </div>

        <!-- AG Grid -->
        <div class="flex-1 p-4">
            <div id="nrGrid" class="ag-theme-quartz w-full h-full rounded-lg shadow-sm"></div>
        </div>

        <!-- Modal Nueva Remisión -->
        <div x-show="showNuevaRemision" x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            @keydown.escape.window="!showNuevoClienteModal && !showNuevoVehiculoModal && (showNuevaRemision = false)">
            <div x-show="showNuevaRemision"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 transform scale-95"
                x-transition:enter-end="opacity-100 transform scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 transform scale-100"
                x-transition:leave-end="opacity-0 transform scale-95"
                class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-[90%] max-h-[90vh] overflow-hidden"
                @click.outside="!showNuevoClienteModal && !showNuevoVehiculoModal && (showNuevaRemision = false)">
                <div class="px-6 py-4 border-b dark:border-slate-700 flex items-center justify-between">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">
                        <i class="fa-solid fa-truck mr-2 text-blue-600"></i>
                        <span x-text="editingId ? 'Editar Nota de Remisión #' + nuevaRemision.nro_documento : 'Nueva Nota de Remisión'"></span>
                    </h2>
                    <button @click="showNuevaRemision = false" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-times text-xl"></i>
                    </button>
                </div>
                <div class="p-6 overflow-y-auto max-h-[calc(90vh-140px)]">
                    <form @submit.prevent="guardarRemision()" @keydown.enter.prevent="focusSiguienteCampo($event)">
                        <!-- Datos Generales -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fecha</label>
                                <input type="date" x-model="nuevaRemision.fecha" required
                                    class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fecha Inicio Traslado</label>
                                <input type="date" x-model="nuevaRemision.fecha_inicio_traslado" required
                                    class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fecha Fin Traslado</label>
                                <input type="date" x-model="nuevaRemision.fecha_fin_traslado" required
                                    class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600">
                            </div>
                        </div>

                        <!-- Motivo -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Motivo de Traslado</label>
                                <select x-model="nuevaRemision.motivo_remision" required
                                    class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600">
                                    <option value="">Seleccionar motivo...</option>
                                    <template x-for="motivo in motivos" :key="String(motivo.codigo)">
                                        <option :value="String(motivo.codigo)" x-text="motivo.codigo + ' - ' + motivo.descripcion"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">KM Estimado</label>
                                <input type="number" x-model="nuevaRemision.km_estimado" min="0" step="0.01"
                                    class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600">
                            </div>
                        </div>

                        <!-- Establecimiento y Punto de Expedición -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fa-solid fa-building text-blue-500 mr-1"></i>Establecimiento - Punto Exp.
                            </label>
                            <select x-model="nuevaRemision.establecimiento_punto"
                                @change="const [est, punto] = $event.target.value.split('-'); nuevaRemision.establecimiento = est; nuevaRemision.punto_expedicion = punto;"
                                class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600">
                                <template x-for="est in establecimientosHabilitados" :key="est.label">
                                    <option :value="est.codigo_establecimiento + '-' + est.punto_expedicion" x-text="est.label"></option>
                                </template>
                            </select>
                        </div>

                        <!-- Destinatario -->
                        <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-user"></i> Destinatario
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                            <div class="md:col-span-3 relative">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Buscar Cliente</label>
                                <div class="relative">
                                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                                        <i class="fa-solid fa-magnifying-glass"></i>
                                    </div>
                                    <input type="text"
                                        x-model="destinatarioSearch"
                                        @input="destinatarioSearch = destinatarioSearch.toUpperCase()"
                                        @input.debounce.300ms="buscarDestinatarios(false)"
                                        @focus="showDestinatarioDropdown = true; destinatarioIndex = 0"
                                        @keydown.arrow-down.prevent="if(destinatariosLista.length) destinatarioIndex = (destinatarioIndex + 1) % destinatariosLista.length"
                                        @keydown.arrow-up.prevent="if(destinatariosLista.length) destinatarioIndex = (destinatarioIndex - 1 + destinatariosLista.length) % destinatariosLista.length"
                                        @keydown.enter.prevent="if(destinatariosLista.length > 0 && showDestinatarioDropdown) { seleccionarDestinatario(destinatariosLista[destinatarioIndex]); } else { buscarDestinatarios(true); }"
                                        @keydown.escape="showDestinatarioDropdown = false"
                                        placeholder="Buscar por RUC, nombre o documento..."
                                        autocomplete="off"
                                        class="w-full pl-10 pr-10 py-2.5 border rounded-xl dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 uppercase shadow-sm">
                                    <div x-show="buscandoDestinatario" class="absolute right-3 top-1/2 -translate-y-1/2">
                                        <i class="fa-solid fa-spinner fa-spin text-blue-500"></i>
                                    </div>
                                    <div x-show="!buscandoDestinatario && destinatarioSearch.length > 0"
                                        @click="destinatarioSearch = ''; destinatariosLista = []"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 cursor-pointer text-gray-400 hover:text-gray-600">
                                        <i class="fa-solid fa-times"></i>
                                    </div>
                                </div>

                                <!-- Dropdown clientes estilo Google -->
                                <div x-show="showDestinatarioDropdown && (destinatariosLista.length > 0 || (destinatarioSearch.length >= 2 && !buscandoDestinatario))" x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    @click.away="showDestinatarioDropdown = false"
                                    class="absolute z-50 w-full mt-1 bg-white dark:bg-slate-700 border dark:border-slate-600 rounded-xl shadow-2xl overflow-hidden">

                                    <!-- Resultados -->
                                    <div class="max-h-64 overflow-y-auto">
                                        <template x-for="(cli, idx) in destinatariosLista" :key="cli.id || idx">
                                            <div @mousedown.prevent
                                                @click="seleccionarDestinatario(cli)"
                                                @mouseenter="destinatarioIndex = idx"
                                                :class="destinatarioIndex === idx ? 'bg-blue-50 dark:bg-blue-900/30' : 'hover:bg-gray-50 dark:hover:bg-slate-600'"
                                                class="px-4 py-3 cursor-pointer transition-colors border-b dark:border-slate-600 last:border-none flex items-center gap-3">
                                                <div class="shrink-0 w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-sm">
                                                    <span x-text="(cli.nombre || 'C').charAt(0).toUpperCase()"></span>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="font-medium text-gray-800 dark:text-gray-200 truncate"
                                                        x-html="resaltarTexto(cli.nombre, destinatarioSearch)"></div>
                                                    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                                        <span class="font-mono" x-html="resaltarTexto(cli.ruc || cli.documento || '', destinatarioSearch)"></span>
                                                        <span x-show="cli.source === 'sifen'" class="px-1.5 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded text-[10px] font-semibold">SET</span>
                                                        <span x-show="cli.source !== 'sifen'" class="px-1.5 py-0.5 bg-gray-100 dark:bg-slate-600 text-gray-600 dark:text-gray-400 rounded text-[10px]">LOCAL</span>
                                                    </div>
                                                </div>
                                                <div class="shrink-0 text-gray-400">
                                                    <i class="fa-solid fa-arrow-turn-down-left fa-rotate-90 text-xs"></i>
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- Opción crear nuevo cuando no hay resultados -->
                                    <div x-show="destinatariosLista.length === 0 && destinatarioSearch.length >= 2"
                                        @mousedown.prevent.stop
                                        @click.prevent.stop="abrirNuevoClienteModal('destinatario')"
                                        class="px-4 py-4 cursor-pointer bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 hover:from-green-100 hover:to-emerald-100 dark:hover:from-green-900/30 dark:hover:to-emerald-900/30 flex items-center gap-3">
                                        <div class="shrink-0 w-10 h-10 rounded-full bg-gradient-to-br from-green-500 to-emerald-500 flex items-center justify-center text-white">
                                            <i class="fa-solid fa-user-plus"></i>
                                        </div>
                                        <div class="flex-1">
                                            <div class="font-medium text-green-700 dark:text-green-400">Crear nuevo destinatario</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">No se encontró "<span x-text="destinatarioSearch"></span>"</div>
                                        </div>
                                        <div class="shrink-0 text-green-500">
                                            <i class="fa-solid fa-plus"></i>
                                        </div>
                                    </div>

                                    <!-- Footer -->
                                    <div x-show="destinatariosLista.length > 0" class="px-4 py-2 bg-gray-50 dark:bg-slate-800/80 text-xs text-gray-500 flex justify-between items-center border-t dark:border-slate-600">
                                        <span class="flex items-center gap-2">
                                            <kbd class="px-1.5 py-0.5 bg-white dark:bg-slate-700 rounded border dark:border-slate-600 text-[10px]">↑↓</kbd>
                                            <span>navegar</span>
                                            <kbd class="px-1.5 py-0.5 bg-white dark:bg-slate-700 rounded border dark:border-slate-600 text-[10px]">Enter</kbd>
                                            <span>seleccionar</span>
                                        </span>
                                        <span class="font-medium" x-text="destinatariosLista.length + ' resultados'"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Tarjeta del Destinatario Seleccionado -->
                            <div x-show="nuevaRemision.receptor_nombre" x-cloak
                                class="md:col-span-3 p-4 bg-gradient-to-r from-blue-50 to-cyan-50 dark:from-slate-700 dark:to-slate-600 rounded-xl border border-blue-200 dark:border-slate-500 shadow-sm">
                                <div class="flex items-start gap-4">
                                    <!-- Avatar -->
                                    <div class="shrink-0 w-14 h-14 rounded-full bg-gradient-to-br from-blue-500 to-cyan-500 flex items-center justify-center text-white text-xl font-bold shadow-lg">
                                        <span x-text="(nuevaRemision.receptor_nombre || 'D').charAt(0).toUpperCase()"></span>
                                    </div>

                                    <!-- Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <h4 class="text-lg font-bold text-gray-800 dark:text-gray-100 truncate" x-text="nuevaRemision.receptor_nombre"></h4>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-3 text-sm">
                                            <div class="flex items-center gap-1.5 text-blue-600 dark:text-blue-400">
                                                <i class="fa-solid fa-id-card text-xs"></i>
                                                <span class="font-mono font-semibold" x-text="nuevaRemision.receptor_ruc || 'Sin RUC'"></span>
                                            </div>
                                            <div x-show="nuevaRemision.receptor_direccion" class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                                                <i class="fa-solid fa-location-dot text-xs"></i>
                                                <span class="truncate max-w-xs" x-text="nuevaRemision.receptor_direccion"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Botón Cambiar -->
                                    <button type="button"
                                        @click="nuevaRemision.receptor_ruc = ''; nuevaRemision.receptor_nombre = ''; nuevaRemision.receptor_direccion = ''; destinatarioSearch = '';"
                                        class="shrink-0 p-2.5 text-red-500 hover:text-red-700 hover:bg-red-100 dark:hover:bg-red-900/30 rounded-lg transition"
                                        title="Cambiar destinatario">
                                        <i class="fa-solid fa-times text-lg"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Conductor -->
                        <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-id-card"></i> Conductor
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                            <div class="md:col-span-3 relative">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Buscar Conductor</label>
                                <div class="relative">
                                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                                        <i class="fa-solid fa-magnifying-glass"></i>
                                    </div>
                                    <input type="text"
                                        x-model="conductorSearch"
                                        @input="conductorSearch = conductorSearch.toUpperCase()"
                                        @input.debounce.300ms="buscarConductores(false)"
                                        @focus="showConductorDropdown = true; conductorIndex = 0"
                                        @keydown.arrow-down.prevent="if(conductoresLista.length) conductorIndex = (conductorIndex + 1) % conductoresLista.length"
                                        @keydown.arrow-up.prevent="if(conductoresLista.length) conductorIndex = (conductorIndex - 1 + conductoresLista.length) % conductoresLista.length"
                                        @keydown.enter.prevent="if(conductoresLista.length > 0 && showConductorDropdown) { seleccionarConductor(conductoresLista[conductorIndex]); } else { buscarConductores(true); }"
                                        @keydown.escape="showConductorDropdown = false"
                                        placeholder="Buscar por documento o nombre..."
                                        autocomplete="off"
                                        class="w-full pl-10 pr-10 py-2.5 border rounded-xl dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 uppercase shadow-sm">
                                    <div x-show="buscandoConductor" class="absolute right-3 top-1/2 -translate-y-1/2">
                                        <i class="fa-solid fa-spinner fa-spin text-blue-500"></i>
                                    </div>
                                    <div x-show="!buscandoConductor && conductorSearch.length > 0"
                                        @click="conductorSearch = ''; conductoresLista = []"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 cursor-pointer text-gray-400 hover:text-gray-600">
                                        <i class="fa-solid fa-times"></i>
                                    </div>
                                </div>

                                <!-- Dropdown conductores estilo Google -->
                                <div x-show="showConductorDropdown && (conductoresLista.length > 0 || (conductorSearch.length >= 2 && !buscandoConductor))" x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    @click.away="showConductorDropdown = false"
                                    class="absolute z-50 w-full mt-1 bg-white dark:bg-slate-700 border dark:border-slate-600 rounded-xl shadow-2xl overflow-hidden">

                                    <!-- Resultados -->
                                    <div class="max-h-64 overflow-y-auto">
                                        <template x-for="(cli, idx) in conductoresLista" :key="cli.ruc || cli.id || idx">
                                            <div @mousedown.prevent
                                                @click="seleccionarConductor(cli)"
                                                @mouseenter="conductorIndex = idx"
                                                :class="conductorIndex === idx ? 'bg-blue-50 dark:bg-blue-900/30' : 'hover:bg-gray-50 dark:hover:bg-slate-600'"
                                                class="px-4 py-3 cursor-pointer transition-colors border-b dark:border-slate-600 last:border-none flex items-center gap-3">
                                                <div class="shrink-0 w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm">
                                                    <span x-text="(cli.nombre || 'C').charAt(0).toUpperCase()"></span>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="font-medium text-gray-800 dark:text-gray-200 truncate"
                                                        x-html="resaltarTexto(cli.nombre, conductorSearch)"></div>
                                                    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                                        <i class="fa-solid fa-id-card text-[10px]"></i>
                                                        <span class="font-mono" x-html="resaltarTexto(cli.ruc || cli.numero || '', conductorSearch)"></span>
                                                        <span x-show="cli.telefono" class="flex items-center gap-1">
                                                            <i class="fa-solid fa-phone text-[10px]"></i>
                                                            <span x-text="cli.telefono"></span>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="shrink-0 text-gray-400">
                                                    <i class="fa-solid fa-arrow-turn-down-left fa-rotate-90 text-xs"></i>
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- Opción crear nuevo cuando no hay resultados -->
                                    <div x-show="conductoresLista.length === 0 && conductorSearch.length >= 2"
                                        @mousedown.prevent.stop
                                        @click.prevent.stop="abrirNuevoClienteModal('conductor')"
                                        class="px-4 py-4 cursor-pointer bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 hover:from-green-100 hover:to-emerald-100 dark:hover:from-green-900/30 dark:hover:to-emerald-900/30 flex items-center gap-3">
                                        <div class="shrink-0 w-10 h-10 rounded-full bg-gradient-to-br from-green-500 to-emerald-500 flex items-center justify-center text-white">
                                            <i class="fa-solid fa-user-plus"></i>
                                        </div>
                                        <div class="flex-1">
                                            <div class="font-medium text-green-700 dark:text-green-400">Crear nuevo conductor</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">No se encontró "<span x-text="conductorSearch"></span>"</div>
                                        </div>
                                        <div class="shrink-0 text-green-500">
                                            <i class="fa-solid fa-plus"></i>
                                        </div>
                                    </div>

                                    <!-- Footer -->
                                    <div x-show="conductoresLista.length > 0" class="px-4 py-2 bg-gray-50 dark:bg-slate-800/80 text-xs text-gray-500 flex justify-between items-center border-t dark:border-slate-600">
                                        <span class="flex items-center gap-2">
                                            <kbd class="px-1.5 py-0.5 bg-white dark:bg-slate-700 rounded border dark:border-slate-600 text-[10px]">↑↓</kbd>
                                            <span>navegar</span>
                                            <kbd class="px-1.5 py-0.5 bg-white dark:bg-slate-700 rounded border dark:border-slate-600 text-[10px]">Enter</kbd>
                                            <span>seleccionar</span>
                                        </span>
                                        <span class="font-medium" x-text="conductoresLista.length + ' resultados'"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Tarjeta del Conductor Seleccionado -->
                            <div x-show="nuevaRemision.conductor_nombre" x-cloak
                                class="md:col-span-3 p-4 bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-slate-700 dark:to-slate-600 rounded-xl border border-indigo-200 dark:border-slate-500 shadow-sm">
                                <div class="flex items-start gap-4">
                                    <!-- Avatar -->
                                    <div class="shrink-0 w-14 h-14 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-xl font-bold shadow-lg">
                                        <i class="fa-solid fa-user-tie"></i>
                                    </div>

                                    <!-- Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <h4 class="text-lg font-bold text-gray-800 dark:text-gray-100 truncate" x-text="nuevaRemision.conductor_nombre"></h4>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-3 text-sm">
                                            <div class="flex items-center gap-1.5 text-indigo-600 dark:text-indigo-400">
                                                <i class="fa-solid fa-id-card text-xs"></i>
                                                <span class="font-mono font-semibold" x-text="nuevaRemision.conductor_documento || 'Sin documento'"></span>
                                            </div>
                                            <div class="flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                                                <i class="fa-solid fa-steering-wheel text-xs"></i>
                                                <span>Conductor</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Botón Cambiar -->
                                    <button type="button"
                                        @click="nuevaRemision.conductor_documento = ''; nuevaRemision.conductor_nombre = ''; conductorSearch = '';"
                                        class="shrink-0 p-2.5 text-red-500 hover:text-red-700 hover:bg-red-100 dark:hover:bg-red-900/30 rounded-lg transition"
                                        title="Cambiar conductor">
                                        <i class="fa-solid fa-times text-lg"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Vehículo -->
                        <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center gap-2">
                            <i class="fa-solid fa-truck"></i> Vehículo
                            <a href="vehiculos.php?id_empresa=<?php echo $id_empresa; ?>" target="_blank"
                                class="ml-auto text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 flex items-center gap-1">
                                <i class="fa-solid fa-cog"></i> Gestionar
                            </a>
                        </h3>
                        <div class="grid grid-cols-1 gap-4 mb-6">
                            <div class="relative">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Chapa del Vehículo</label>
                                <input type="text"
                                    x-model="vehiculoSearch"
                                    @input="vehiculoSearch = vehiculoSearch.toUpperCase(); buscarVehiculos()"
                                    @focus="showVehiculoDropdown = true; vehiculoIndex = 0"
                                    @keydown.arrow-down.prevent="if(vehiculosLista.length) vehiculoIndex = (vehiculoIndex + 1) % vehiculosLista.length"
                                    @keydown.arrow-up.prevent="if(vehiculosLista.length) vehiculoIndex = (vehiculoIndex - 1 + vehiculosLista.length) % vehiculosLista.length"
                                    @keydown.enter.prevent="if(vehiculosLista.length > 0 && showVehiculoDropdown) { seleccionarVehiculo(vehiculosLista[vehiculoIndex]); }"
                                    @keydown.escape="showVehiculoDropdown = false"
                                    placeholder="Ej: ABC 123"
                                    autocomplete="off"
                                    x-show="!nuevaRemision.vehiculo_chapa"
                                    class="w-full md:w-1/3 px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500 uppercase font-mono">

                                <!-- Dropdown vehículos -->
                                <div x-show="showVehiculoDropdown && (vehiculosLista.length > 0 || (vehiculoSearch.length >= 2 && !buscandoVehiculo))" x-cloak
                                    x-transition:enter="transition ease-out duration-100"
                                    x-transition:enter-start="opacity-0 scale-95"
                                    x-transition:enter-end="opacity-100 scale-100"
                                    @click.away="showVehiculoDropdown = false"
                                    class="absolute z-50 w-full md:w-1/2 mt-1 bg-white dark:bg-slate-700 border dark:border-slate-600 rounded-lg shadow-xl max-h-64 overflow-y-auto">
                                    <template x-for="(veh, idx) in vehiculosLista" :key="veh.id || idx">
                                        <div @mousedown.prevent
                                            @click="seleccionarVehiculo(veh)"
                                            @mouseenter="vehiculoIndex = idx"
                                            :class="vehiculoIndex === idx ? 'bg-blue-500/20 dark:bg-blue-600/30' : 'hover:bg-gray-100 dark:hover:bg-slate-600'"
                                            class="px-3 py-2 cursor-pointer transition-colors border-b dark:border-slate-600 last:border-none">
                                            <div class="flex justify-between items-start">
                                                <span class="font-mono font-bold text-blue-600 dark:text-blue-400" x-text="veh.chapa"></span>
                                                <span class="text-xs text-gray-500" x-text="veh.tipo"></span>
                                            </div>
                                            <div class="text-sm" x-text="veh.marca + (veh.modelo ? ' ' + veh.modelo : '') + (veh.color ? ' - ' + veh.color : '')"></div>
                                        </div>
                                    </template>
                                    <!-- Opción crear nuevo cuando no hay resultados -->
                                    <div x-show="vehiculosLista.length === 0 && vehiculoSearch.length >= 2"
                                        @mousedown.prevent.stop
                                        @click.prevent.stop="abrirNuevoVehiculoModal()"
                                        class="px-3 py-3 cursor-pointer bg-green-50 dark:bg-green-900/20 hover:bg-green-100 dark:hover:bg-green-900/30 border-t dark:border-slate-600">
                                        <div class="flex items-center gap-2 text-green-700 dark:text-green-400">
                                            <i class="fa-solid fa-truck-medical"></i>
                                            <span class="font-medium">Crear nuevo vehículo</span>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            No se encontró la chapa "<span x-text="vehiculoSearch"></span>". Click para crear nuevo.
                                        </div>
                                    </div>
                                    <!-- Footer -->
                                    <div x-show="vehiculosLista.length > 0" class="px-3 py-1.5 bg-gray-50 dark:bg-slate-800/50 text-xs text-gray-500 flex justify-between sticky bottom-0">
                                        <span>↑↓ navegar • Enter seleccionar</span>
                                        <span x-text="vehiculosLista.length + ' resultados'"></span>
                                    </div>
                                </div>

                                <!-- Loading indicator -->
                                <div x-show="buscandoVehiculo && !nuevaRemision.vehiculo_chapa" class="absolute right-3 top-9 md:right-auto md:left-[calc(33.33%-2rem)]">
                                    <i class="fa-solid fa-spinner fa-spin text-blue-500"></i>
                                </div>
                            </div>

                            <!-- Tarjeta del Vehículo Seleccionado -->
                            <div x-show="nuevaRemision.vehiculo_chapa" x-cloak
                                class="p-4 bg-gradient-to-r from-emerald-50 to-teal-50 dark:from-slate-700 dark:to-slate-600 rounded-xl border border-emerald-200 dark:border-slate-500 shadow-sm">
                                <div class="flex items-start gap-4">
                                    <!-- Icono -->
                                    <div class="shrink-0 w-14 h-14 rounded-full bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white text-xl shadow-lg">
                                        <i class="fa-solid fa-truck"></i>
                                    </div>

                                    <!-- Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <h4 class="text-2xl font-mono font-bold text-emerald-700 dark:text-emerald-300" x-text="nuevaRemision.vehiculo_chapa"></h4>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-3 text-sm">
                                            <div class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                                <i class="fa-solid fa-car text-xs"></i>
                                                <span class="font-semibold" x-text="nuevaRemision.vehiculo_tipo || 'Vehículo'"></span>
                                            </div>
                                            <div x-show="nuevaRemision.vehiculo_marca" class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                                                <i class="fa-solid fa-tag text-xs"></i>
                                                <span x-text="nuevaRemision.vehiculo_marca"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Botón Cambiar -->
                                    <button type="button"
                                        @click="nuevaRemision.vehiculo_tipo = ''; nuevaRemision.vehiculo_marca = ''; nuevaRemision.vehiculo_chapa = ''; vehiculoSearch = '';"
                                        class="shrink-0 p-2.5 text-red-500 hover:text-red-700 hover:bg-red-100 dark:hover:bg-red-900/30 rounded-lg transition"
                                        title="Cambiar vehículo">
                                        <i class="fa-solid fa-times text-lg"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Facturas Vinculadas -->
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center gap-2">
                                <i class="fa-solid fa-file-invoice"></i> Facturas Vinculadas
                                <span class="text-xs font-normal text-gray-500">(opcional)</span>
                            </h3>
                            <div class="border rounded-lg dark:border-slate-600 overflow-hidden">
                                <!-- Buscador de Facturas -->
                                <div class="p-3 border-b dark:border-slate-600 bg-gray-50 dark:bg-slate-700">
                                    <div class="relative" @click.away="showFacturaDropdown = false">
                                        <input type="text"
                                            x-model="facturaSearch"
                                            @input.debounce.300ms="buscarFacturas()"
                                            @keydown.enter.prevent="agregarFacturaSeleccionada()"
                                            @keydown.arrow-down.prevent="facturaSelectedIdx = Math.min(facturaSelectedIdx + 1, facturasResultados.length - 1)"
                                            @keydown.arrow-up.prevent="facturaSelectedIdx = Math.max(facturaSelectedIdx - 1, 0)"
                                            @keydown.escape="showFacturaDropdown = false; facturaSearch = ''"
                                            @focus="if(facturasResultados.length) showFacturaDropdown = true"
                                            placeholder="Buscar factura por número, cliente o CDC..."
                                            class="w-full px-4 py-2.5 pl-10 border rounded-lg dark:bg-slate-600 dark:border-slate-500 focus:ring-2 focus:ring-blue-500">
                                        <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                                            <i class="fa-solid fa-file-invoice"></i>
                                        </div>
                                        <div x-show="buscandoFacturas" class="absolute right-3 top-1/2 -translate-y-1/2">
                                            <i class="fa-solid fa-spinner fa-spin text-blue-500"></i>
                                        </div>

                                        <!-- Dropdown de resultados -->
                                        <div x-show="showFacturaDropdown && facturasResultados.length > 0" x-cloak
                                            class="absolute z-50 left-0 right-0 mt-1 bg-white dark:bg-slate-700 border dark:border-slate-600 rounded-lg shadow-xl max-h-64 overflow-y-auto">
                                            <template x-for="(fac, idx) in facturasResultados" :key="fac.id_factura">
                                                <div @click="agregarFactura(fac)"
                                                    @mouseenter="facturaSelectedIdx = idx"
                                                    :class="facturaSelectedIdx === idx ? 'bg-blue-50 dark:bg-blue-900/30' : ''"
                                                    class="px-4 py-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-600 border-b dark:border-slate-600 last:border-0 transition-colors">
                                                    <div class="flex items-center justify-between">
                                                        <div class="flex-1">
                                                            <div class="flex items-center gap-2">
                                                                <span class="font-mono font-bold text-blue-600 dark:text-blue-400" x-text="fac.nro_factura"></span>
                                                                <span class="text-xs px-2 py-0.5 rounded-full"
                                                                    :class="fac.estado_sifen === 'Aprobado' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'"
                                                                    x-text="fac.estado_sifen || 'Pendiente'"></span>
                                                            </div>
                                                            <div class="text-sm text-gray-600 dark:text-gray-300 mt-0.5" x-text="fac.nombre_cliente"></div>
                                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                                <span x-text="fac.fecha"></span>
                                                                <span class="mx-1">•</span>
                                                                <span class="font-semibold" x-text="'Gs. ' + Number(fac.total || 0).toLocaleString('es-PY')"></span>
                                                            </div>
                                                        </div>
                                                        <button type="button" class="shrink-0 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                                                            <i class="fa-solid fa-plus"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <!-- Lista de facturas vinculadas -->
                                <div class="divide-y dark:divide-slate-600">
                                    <template x-if="nuevaRemision.facturas_vinculadas.length > 0">
                                        <template x-for="(fac, idx) in nuevaRemision.facturas_vinculadas" :key="fac.id_factura">
                                            <div class="px-4 py-3 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors">
                                                <div class="flex items-center gap-3">
                                                    <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-lg">
                                                        <i class="fa-solid fa-file-invoice text-blue-600 dark:text-blue-400"></i>
                                                    </div>
                                                    <div>
                                                        <div class="flex items-center gap-2">
                                                            <span class="font-mono font-bold text-gray-800 dark:text-gray-200" x-text="fac.nro_factura"></span>
                                                            <span class="text-xs text-gray-500" x-text="fac.fecha"></span>
                                                        </div>
                                                        <div class="text-sm text-gray-600 dark:text-gray-400" x-text="fac.cliente"></div>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <span class="font-semibold text-gray-700 dark:text-gray-300" x-text="'Gs. ' + Number(fac.total || 0).toLocaleString('es-PY')"></span>
                                                    <button type="button" @click="quitarFactura(idx)"
                                                        class="p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition">
                                                        <i class="fa-solid fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                    </template>
                                    <template x-if="nuevaRemision.facturas_vinculadas.length === 0">
                                        <div class="px-4 py-6 text-center text-gray-400">
                                            <i class="fa-solid fa-file-circle-plus text-2xl mb-2"></i>
                                            <p class="text-sm">No hay facturas vinculadas</p>
                                            <p class="text-xs">Busque una factura para importar sus items automáticamente</p>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Mercaderías - Estilo POS -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                            <!-- Panel Izquierdo: Buscador de Productos -->
                            <div class="border rounded-lg dark:border-slate-600 overflow-hidden">
                                <div class="bg-gray-50 dark:bg-slate-700 px-4 py-3 border-b dark:border-slate-600">
                                    <h3 class="font-semibold text-gray-700 dark:text-gray-200 flex items-center gap-2">
                                        <i class="fa-solid fa-search"></i> Buscar Productos
                                    </h3>
                                </div>

                                <!-- Buscador -->
                                <div class="p-3 border-b dark:border-slate-600">
                                    <div class="relative" @click.away="showProductoDropdown = false">
                                        <input type="text"
                                            x-model="productoSearch"
                                            @input.debounce.300ms="buscarProductos()"
                                            @keydown.enter.prevent="agregarProductoSeleccionado()"
                                            @keydown.arrow-down.prevent="productoSelectedIdx = Math.min(productoSelectedIdx + 1, productosResultados.length - 1)"
                                            @keydown.arrow-up.prevent="productoSelectedIdx = Math.max(productoSelectedIdx - 1, 0)"
                                            @keydown.escape="showProductoDropdown = false; productoSearch = ''"
                                            @focus="if(productosResultados.length) showProductoDropdown = true"
                                            x-ref="productoSearchInput"
                                            placeholder="Buscar por código o descripción..."
                                            class="w-full px-4 py-2.5 pl-10 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                                        <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                        </div>
                                        <button x-show="productoSearch" type="button"
                                            @click="productoSearch = ''; productosResultados = []; showProductoDropdown = false"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                            <i class="fa-solid fa-times"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Resultados de búsqueda -->
                                <div class="max-h-[300px] overflow-y-auto">
                                    <template x-if="buscandoProductos">
                                        <div class="p-4 text-center text-gray-500">
                                            <i class="fa-solid fa-spinner fa-spin mr-2"></i> Buscando...
                                        </div>
                                    </template>
                                    <template x-if="!buscandoProductos && productosResultados.length > 0">
                                        <div class="divide-y dark:divide-slate-600">
                                            <template x-for="(prod, idx) in productosResultados" :key="prod.id">
                                                <div @click="agregarProductoAlCarrito(prod)"
                                                    @mouseenter="productoSelectedIdx = idx"
                                                    :class="productoSelectedIdx === idx ? 'bg-blue-50 dark:bg-blue-900/30' : ''"
                                                    class="px-4 py-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
                                                    <div class="flex items-start justify-between gap-2">
                                                        <div class="flex-1 min-w-0">
                                                            <div class="font-medium text-sm text-gray-800 dark:text-gray-200 truncate" x-text="prod.descripcion"></div>
                                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                                <span class="font-mono bg-gray-100 dark:bg-slate-600 px-1.5 py-0.5 rounded" x-text="prod.codigo"></span>
                                                                <span x-show="prod.stock" class="ml-2">Stock: <span x-text="prod.stock"></span></span>
                                                            </div>
                                                        </div>
                                                        <button type="button" class="shrink-0 p-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                                                            <i class="fa-solid fa-plus"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="!buscandoProductos && productoSearch && productosResultados.length === 0">
                                        <div class="p-4 text-center text-gray-500">
                                            <i class="fa-solid fa-box-open text-2xl mb-2"></i>
                                            <p>No se encontraron productos</p>
                                        </div>
                                    </template>
                                    <template x-if="!buscandoProductos && !productoSearch">
                                        <div class="p-6 text-center text-gray-400">
                                            <i class="fa-solid fa-barcode text-3xl mb-2"></i>
                                            <p class="text-sm">Escriba código o descripción para buscar</p>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Panel Derecho: Carrito de Mercaderías -->
                            <div class="border rounded-lg dark:border-slate-600 overflow-hidden flex flex-col">
                                <div class="bg-gray-50 dark:bg-slate-700 px-4 py-3 border-b dark:border-slate-600 flex items-center justify-between">
                                    <h3 class="font-semibold text-gray-700 dark:text-gray-200 flex items-center gap-2">
                                        <i class="fa-solid fa-boxes-stacked"></i> Mercaderías
                                        <span x-show="nuevaRemision.items.length > 0"
                                            class="ml-2 text-xs bg-blue-600 text-white px-2 py-0.5 rounded-full"
                                            x-text="nuevaRemision.items.length + ' item(s)'"></span>
                                    </h3>
                                    <button type="button" x-show="nuevaRemision.items.length > 0"
                                        @click="if(confirm('¿Limpiar todos los items?')) nuevaRemision.items = []"
                                        class="text-xs text-red-500 hover:text-red-700">
                                        <i class="fa-solid fa-trash mr-1"></i> Limpiar
                                    </button>
                                </div>

                                <!-- Lista de Items -->
                                <div class="flex-1 max-h-[300px] overflow-y-auto">
                                    <template x-if="nuevaRemision.items.length > 0">
                                        <div class="divide-y dark:divide-slate-600">
                                            <template x-for="(item, index) in nuevaRemision.items" :key="index">
                                                <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors">
                                                    <div class="flex items-start gap-3">
                                                        <div class="flex-1 min-w-0">
                                                            <div class="font-medium text-sm text-gray-800 dark:text-gray-200 truncate" x-text="item.descripcion"></div>
                                                            <div class="text-xs text-gray-500 mt-0.5">
                                                                <span class="font-mono bg-gray-100 dark:bg-slate-600 px-1.5 py-0.5 rounded" x-text="item.codigo"></span>
                                                            </div>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <input type="number"
                                                                x-model.number="item.cantidad"
                                                                min="0.0001"
                                                                step="0.0001"
                                                                class="w-20 px-2 py-1 text-center text-sm border rounded dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                                                            <button type="button" @click="eliminarItem(index)"
                                                                class="p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition">
                                                                <i class="fa-solid fa-trash text-sm"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="nuevaRemision.items.length === 0">
                                        <div class="p-8 text-center text-gray-400">
                                            <i class="fa-solid fa-cart-shopping text-4xl mb-3 text-gray-300"></i>
                                            <p class="text-sm">No hay mercaderías agregadas</p>
                                            <p class="text-xs mt-1">Busque y agregue productos desde el panel izquierdo</p>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Botones -->
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showNuevaRemision = false"
                                class="btn-chip">
                                <i class="fa-solid fa-times"></i>
                                Cancelar
                            </button>
                            <button type="submit" :disabled="guardando"
                                class="btn-chip btn-chip--primary disabled:opacity-50">
                                <i class="fa-solid fa-save"></i>
                                <span x-text="guardando ? 'Guardando...' : 'Guardar'"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal Crear Nuevo Cliente -->
        <div x-show="showNuevoClienteModal" x-cloak
            @click.self="showNuevoClienteModal = false"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <div @click.stop
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md overflow-hidden">

                <!-- Header -->
                <div class="px-6 py-4 border-b dark:border-slate-700 bg-gradient-to-r from-green-600 to-green-700">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i class="fa-solid fa-user-plus"></i>
                        <span x-text="nuevoClienteTipo === 'destinatario' ? 'Nuevo Destinatario' : 'Nuevo Conductor'"></span>
                    </h3>
                </div>

                <!-- Form -->
                <form @submit.prevent="guardarNuevoCliente()" class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            RUC/CI <span class="text-red-500">*</span>
                        </label>
                        <div class="flex gap-2">
                            <input type="text" x-model="nuevoCliente.ruc" required
                                placeholder="80012345-6 o 1234567"
                                class="flex-1 px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-green-500">
                            <button type="button" @click="buscarRucSifen()" :disabled="buscandoRucNuevo"
                                class="px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                                title="Buscar en SET">
                                <i class="fa-solid" :class="buscandoRucNuevo ? 'fa-spinner fa-spin' : 'fa-search'"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Nombre/Razón Social <span class="text-red-500">*</span>
                        </label>
                        <input type="text" x-model="nuevoCliente.nombre" required
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Dirección</label>
                        <input type="text" x-model="nuevoCliente.direccion"
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Teléfono</label>
                        <input type="text" x-model="nuevoCliente.telefono" placeholder="0981 123456"
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-green-500">
                    </div>

                    <!-- Botones -->
                    <div class="flex justify-end gap-3 pt-4 border-t dark:border-slate-700">
                        <button type="button" @click="showNuevoClienteModal = false"
                            class="btn-chip">
                            <i class="fa-solid fa-times"></i>
                            Cancelar
                        </button>
                        <button type="submit" :disabled="guardandoNuevoCliente"
                            class="btn-chip btn-chip--success disabled:opacity-50">
                            <i class="fa-solid fa-save"></i>
                            <span x-text="guardandoNuevoCliente ? 'Guardando...' : 'Guardar'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal Crear Nuevo Vehículo -->
        <div x-show="showNuevoVehiculoModal" x-cloak
            @click.self="showNuevoVehiculoModal = false"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <div @click.stop
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md overflow-hidden">

                <!-- Header -->
                <div class="px-6 py-4 border-b dark:border-slate-700 bg-gradient-to-r from-blue-600 to-indigo-700">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i class="fa-solid fa-truck-medical"></i>
                        Nuevo Vehículo
                    </h3>
                </div>

                <!-- Form -->
                <form @submit.prevent="guardarNuevoVehiculo()" class="p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Chapa <span class="text-red-500">*</span>
                            </label>
                            <input type="text" x-model="nuevoVehiculo.chapa" required
                                @input="nuevoVehiculo.chapa = nuevoVehiculo.chapa.toUpperCase()"
                                placeholder="ABC 123"
                                class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500 uppercase font-mono text-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Tipo <span class="text-red-500">*</span>
                            </label>
                            <select x-model="nuevoVehiculo.tipo" required
                                class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                                <option value="">Seleccionar...</option>
                                <template x-for="tipo in tiposVehiculo" :key="tipo">
                                    <option :value="tipo" x-text="tipo"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Marca <span class="text-red-500">*</span>
                            </label>
                            <input type="text" x-model="nuevoVehiculo.marca" required placeholder="Toyota, Ford..."
                                class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Modelo</label>
                            <input type="text" x-model="nuevoVehiculo.modelo" placeholder="Hilux, Ranger..."
                                class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Color</label>
                            <input type="text" x-model="nuevoVehiculo.color" placeholder="Blanco, Negro..."
                                class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <!-- Botones -->
                    <div class="flex justify-end gap-3 pt-4 border-t dark:border-slate-700">
                        <button type="button" @click="showNuevoVehiculoModal = false"
                            class="btn-chip">
                            <i class="fa-solid fa-times"></i>
                            Cancelar
                        </button>
                        <button type="submit" :disabled="guardandoNuevoVehiculo"
                            class="btn-chip btn-chip--primary disabled:opacity-50">
                            <i class="fa-solid fa-save"></i>
                            <span x-text="guardandoNuevoVehiculo ? 'Guardando...' : 'Guardar'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div> <!-- Cierre del div x-data="nrApp()" -->

    <script>
        const API_URL = 'nr_api.php';
        const SIFEN_API_URL = 'nr_sifen_api.php';
        const ID_EMPRESA = <?php echo $id_empresa; ?>;
        const RUC_EMPRESA = '<?php echo $ruc_empresa; ?>';
        const TIMBRADO_EMPRESA = '<?php echo $timbrado; ?>';
        const CONFIG_SIFEN = <?php echo json_encode($configFe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        let gridApi = null;

        function nrApp() {
            const hoy = new Date().toISOString().split('T')[0];
            return {
                showNuevaRemision: false,
                guardando: false,
                motivos: [],
                filtroFechaDesde: '',
                filtroFechaHasta: '',
                filtroEstado: '',

                // Sistema de notificaciones Toast
                toasts: [],
                toastCounter: 0,
                loadingToastId: null,

                // Modal de confirmación
                confirmModal: {
                    show: false,
                    type: 'info',
                    title: '',
                    message: '',
                    confirmText: 'Confirmar',
                    onConfirm: null,
                    onCancel: null
                },

                // Modal de detalles/info
                detalleModal: {
                    show: false,
                    type: 'info',
                    title: '',
                    content: ''
                },

                // Modal de impresión
                printModal: {
                    show: false,
                    url: '',
                    title: ''
                },

                // Modal de loading
                loadingModal: {
                    show: false,
                    message: 'Cargando...'
                },

                // Modal de error
                errorModal: {
                    show: false,
                    title: 'Error',
                    message: ''
                },

                // Buscador Destinatario (estilo POS)
                destinatarioSearch: '',
                destinatariosLista: [],
                destinatarioIndex: 0,
                showDestinatarioDropdown: false,
                buscandoDestinatario: false,

                // Buscador Conductor (estilo POS)
                conductorSearch: '',
                conductoresLista: [],
                conductorIndex: 0,
                showConductorDropdown: false,
                buscandoConductor: false,

                // Buscador Vehículo (estilo POS)
                vehiculoSearch: '',
                vehiculosLista: [],
                vehiculoIndex: 0,
                showVehiculoDropdown: false,
                buscandoVehiculo: false,

                // Buscador de productos (estilo POS)
                productoSearch: '',
                productosResultados: [],
                productoSelectedIdx: 0,
                showProductoDropdown: false,
                buscandoProductos: false,

                // Modal nuevo cliente
                showNuevoClienteModal: false,
                nuevoClienteTipo: '', // 'destinatario' o 'conductor'
                guardandoNuevoCliente: false,
                buscandoRucNuevo: false,
                nuevoCliente: {
                    ruc: '',
                    nombre: '',
                    direccion: '',
                    telefono: ''
                },

                // Modal nuevo vehículo
                showNuevoVehiculoModal: false,
                guardandoNuevoVehiculo: false,
                tiposVehiculo: ['Camión', 'Camioneta', 'Furgón', 'Motocicleta', 'Automóvil', 'Semi-remolque', 'Trailer'],
                nuevoVehiculo: {
                    chapa: '',
                    tipo: '',
                    marca: '',
                    modelo: '',
                    color: ''
                },

                // Modo edición
                editingId: null,

                nuevaRemision: {
                    fecha: hoy,
                    fecha_inicio_traslado: hoy,
                    fecha_fin_traslado: hoy,
                    motivo_remision: '1',
                    km_estimado: 0,
                    establecimiento: '001',
                    punto_expedicion: '001',
                    receptor_ruc: '',
                    receptor_dv: '',
                    receptor_nombre: '',
                    receptor_direccion: '',
                    conductor_documento: '',
                    conductor_nombre: '',
                    vehiculo_tipo: '',
                    vehiculo_marca: '',
                    vehiculo_chapa: '',
                    facturas_vinculadas: [],
                    items: []
                },

                // Establecimientos habilitados para SIFEN
                establecimientosHabilitados: [],

                // Buscador de facturas
                facturaSearch: '',
                facturasResultados: [],
                facturaSelectedIdx: 0,
                showFacturaDropdown: false,
                buscandoFacturas: false,

                // Función para resaltar texto buscado (estilo Google)
                resaltarTexto(texto, busqueda) {
                    if (!texto || !busqueda || busqueda.length < 2) return texto || '';
                    const textoStr = String(texto);
                    const busquedaStr = String(busqueda).trim();

                    // Escapar caracteres especiales de regex
                    const escapedSearch = busquedaStr.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    const regex = new RegExp(`(${escapedSearch})`, 'gi');

                    return textoStr.replace(regex, '<mark class="bg-yellow-200 dark:bg-yellow-500/40 px-0.5 rounded">$1</mark>');
                },

                getEmptyRemision() {
                    const hoy = new Date().toISOString().split('T')[0];
                    // Obtener establecimiento default
                    const estDefault = this.establecimientosHabilitados.length > 0 ?
                        this.establecimientosHabilitados[0] : {
                            codigo_establecimiento: '001',
                            punto_expedicion: '001'
                        };
                    return {
                        fecha: hoy,
                        fecha_inicio_traslado: hoy,
                        fecha_fin_traslado: hoy,
                        motivo_remision: '1',
                        km_estimado: 0,
                        establecimiento: estDefault.codigo_establecimiento,
                        punto_expedicion: estDefault.punto_expedicion,
                        establecimiento_punto: estDefault.codigo_establecimiento + '-' + estDefault.punto_expedicion,
                        receptor_ruc: '',
                        receptor_dv: '',
                        receptor_nombre: '',
                        receptor_direccion: '',
                        conductor_documento: '',
                        conductor_nombre: '',
                        vehiculo_tipo: '',
                        vehiculo_marca: '',
                        vehiculo_chapa: '',
                        facturas_vinculadas: [],
                        items: []
                    };
                },

                async init() {
                    await this.cargarMotivos();
                    await this.cargarEstablecimientos();
                    this.initGrid();

                    // Event listeners para acciones del grid
                    window.addEventListener('editar-remision', (e) => this.editarRemision(e.detail));
                    window.addEventListener('enviar-sifen', (e) => this.enviarSifen(e.detail));
                    window.addEventListener('ver-remision', (e) => this.verRemision(e.detail));

                    // Seleccionar todo al enfocar inputs
                    document.addEventListener('focus', (e) => {
                        if (e.target.matches('input[type="text"], input[type="number"], input[type="search"]')) {
                            setTimeout(() => e.target.select(), 0);
                        }
                    }, true);
                },

                // ========== SISTEMA DE NOTIFICACIONES ==========
                showToast(message, type = 'info', title = '', duration = 4000) {
                    const id = ++this.toastCounter;
                    const toast = {
                        id,
                        message,
                        type,
                        title,
                        visible: true
                    };
                    this.toasts.push(toast);

                    if (type !== 'loading' && duration > 0) {
                        setTimeout(() => this.removeToast(id), duration);
                    }
                    return id;
                },

                removeToast(id) {
                    const toast = this.toasts.find(t => t.id === id);
                    if (toast) {
                        toast.visible = false;
                        setTimeout(() => {
                            this.toasts = this.toasts.filter(t => t.id !== id);
                        }, 300);
                    }
                },

                showLoading(message = 'Cargando...') {
                    this.loadingModal.message = message;
                    this.loadingModal.show = true;
                    return true;
                },

                hideLoading() {
                    this.loadingModal.show = false;
                },

                showSuccess(message, title = 'Éxito') {
                    this.hideLoading();
                    return this.showToast(message, 'success', title, 4000);
                },

                showError(message, title = 'Error') {
                    this.hideLoading();
                    this.errorModal.title = title;
                    this.errorModal.message = message;
                    this.errorModal.show = true;
                },

                showWarning(message, title = 'Aviso') {
                    this.hideLoading();
                    return this.showToast(message, 'warning', title, 5000);
                },

                showInfo(message, title = '') {
                    this.hideLoading();
                    return this.showToast(message, 'info', title, 4000);
                },

                async confirm(title, message, type = 'warning', confirmText = 'Confirmar') {
                    return new Promise((resolve) => {
                        this.confirmModal = {
                            show: true,
                            type,
                            title,
                            message,
                            confirmText,
                            onConfirm: () => resolve(true),
                            onCancel: () => resolve(false)
                        };
                    });
                },

                // ========== EDITAR REMISIÓN ==========
                async editarRemision(id) {
                    try {
                        this.showLoading('Cargando remisión...');

                        const res = await fetch(`${API_URL}?action=get&id=${id}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (!data.success) {
                            this.showError(data.error || 'No se pudo cargar la remisión');
                            return;
                        }

                        const rem = data.data;

                        // Verificar que esté pendiente
                        if (rem.estado_sifen && rem.estado_sifen !== 'Pendiente') {
                            this.showWarning('Esta nota de remisión ya fue enviada a SIFEN y no puede editarse', 'No editable');
                            return;
                        }

                        // Cargar datos en el formulario
                        this.editingId = rem.id_remision;
                        this.nuevaRemision = {
                            nro_documento: rem.nro_documento,
                            fecha: rem.fecha ? rem.fecha.split(' ')[0] : '',
                            fecha_inicio_traslado: rem.fecha_inicio_traslado ? rem.fecha_inicio_traslado.split(' ')[0] : '',
                            fecha_fin_traslado: rem.fecha_fin_traslado ? rem.fecha_fin_traslado.split(' ')[0] : '',
                            motivo_remision: String(rem.motivo_remision || '1'),
                            motivo_descripcion: rem.motivo_descripcion || '',
                            km_estimado: rem.km_estimado || 0,
                            // Establecimiento y Punto de Expedición
                            establecimiento: rem.establecimiento || '001',
                            punto_expedicion: rem.punto_expedicion || '001',
                            establecimiento_punto: (rem.establecimiento || '001') + '-' + (rem.punto_expedicion || '001'),
                            // Receptor
                            receptor_ruc: rem.receptor_ruc || '',
                            receptor_dv: rem.receptor_dv || '',
                            receptor_nombre: rem.receptor_nombre || '',
                            receptor_direccion: rem.receptor_direccion || '',
                            receptor_ciudad: rem.receptor_ciudad || '',
                            receptor_ciudad_nombre: rem.receptor_ciudad_nombre || '',
                            // Conductor
                            conductor_documento: rem.conductor_documento || '',
                            conductor_nombre: rem.conductor_nombre || '',
                            conductor_direccion: rem.conductor_direccion || '',
                            // Vehículo
                            vehiculo_tipo: rem.vehiculo_tipo || '',
                            vehiculo_marca: rem.vehiculo_marca || '',
                            vehiculo_chapa: rem.vehiculo_chapa || '',
                            // CDC asociado
                            cdc_asociado: rem.cdc_asociado || '',
                            timbrado_asociado: rem.timbrado_asociado || '',
                            // Facturas vinculadas
                            facturas_vinculadas: (rem.facturas_vinculadas || []).map(f => ({
                                id_factura: f.id_factura,
                                nro_factura: f.nro_factura,
                                cdc: f.cdc,
                                fecha: f.fecha,
                                total: parseFloat(f.total || 0)
                            })),
                            // Items
                            items: (rem.items || []).map(item => ({
                                id_producto: item.id_producto || null,
                                codigo: item.codigo || '',
                                descripcion: item.descripcion || '',
                                cantidad: parseFloat(item.cantidad || 1),
                                unidad_medida: item.unidad_medida || '77',
                                precio_unitario: parseFloat(item.precio_unitario || 0),
                                id_factura_origen: item.id_factura_origen || null
                            }))
                        };

                        // Actualizar campos de búsqueda
                        this.destinatarioSearch = rem.receptor_nombre || '';
                        this.conductorSearch = rem.conductor_nombre || '';
                        this.vehiculoSearch = rem.vehiculo_chapa || '';

                        this.hideLoading();
                        this.showNuevaRemision = true;
                    } catch (e) {
                        console.error('Error cargando remisión:', e);
                        this.showError('Error de conexión');
                    }
                },

                async verRemision(id) {
                    // Por ahora redirigir a una página de impresión o mostrar modal
                    window.open(`nr_print.php?id=${id}&id_empresa=${ID_EMPRESA}`, '_blank');
                },

                // Pasar al siguiente campo con Enter
                focusSiguienteCampo(event) {
                    const form = event.target.form;
                    if (!form) return;

                    // Obtener todos los elementos focuseables del formulario
                    const focusables = Array.from(form.querySelectorAll('input:not([type="hidden"]), select, textarea, button[type="submit"]'));
                    const currentIndex = focusables.indexOf(event.target);

                    if (currentIndex > -1 && currentIndex < focusables.length - 1) {
                        // Pasar al siguiente campo
                        focusables[currentIndex + 1].focus();
                    }
                },

                async cargarMotivos() {
                    try {
                        const res = await fetch(`${API_URL}?action=motivos&id_empresa=${ID_EMPRESA}`);
                        const text = await res.text();
                        console.log('📝 Respuesta motivos:', text);
                        const data = JSON.parse(text);
                        if (data.success && data.data && data.data.length > 0) {
                            this.motivos = data.data;
                            console.log('✅ Motivos cargados:', this.motivos.length);
                        } else {
                            console.warn('⚠️ Sin motivos:', data);
                            this.showWarning('No hay motivos disponibles');
                        }
                    } catch (e) {
                        console.error('❌ Error cargando motivos:', e);
                        this.showError('Error al cargar motivos: ' + e.message);
                    }
                },

                async cargarEstablecimientos() {
                    try {
                        const res = await fetch(`nr_sifen_api.php?action=getEstablecimientos&id_empresa=<?= $id_empresa ?>`);
                        const data = await res.json();
                        if (data.success) {
                            this.establecimientosHabilitados = data.data;
                            // Establecer valor por defecto
                            if (data.data.length > 0) {
                                this.nuevaRemision.establecimiento = data.data[0].codigo_establecimiento;
                                this.nuevaRemision.punto_expedicion = data.data[0].punto_expedicion;
                            }
                        }
                    } catch (e) {
                        console.error('Error cargando establecimientos:', e);
                        // Default si hay error
                        this.establecimientosHabilitados = [{
                            codigo_establecimiento: '001',
                            punto_expedicion: '001',
                            label: '001-001'
                        }];
                    }
                },

                initGrid() {
                    // Evitar crear el grid múltiples veces
                    if (gridApi) {
                        console.log('Grid ya inicializado');
                        return;
                    }

                    const columnDefs = [{
                            headerName: 'ID',
                            field: 'id_remision',
                            width: 80
                        },
                        {
                            headerName: 'Nro Doc',
                            field: 'nro_documento_formateado',
                            width: 140
                        },
                        {
                            headerName: 'Fecha',
                            field: 'fecha',
                            width: 110,
                            valueFormatter: p => p.value ? new Date(p.value).toLocaleDateString('es-PY') : ''
                        },
                        {
                            headerName: 'Motivo',
                            field: 'motivo_descripcion',
                            width: 180
                        },
                        {
                            headerName: 'Destinatario',
                            field: 'receptor_nombre',
                            flex: 1
                        },
                        {
                            headerName: 'RUC',
                            field: 'receptor_ruc',
                            width: 100
                        },
                        {
                            headerName: 'Conductor',
                            field: 'conductor_nombre',
                            width: 150
                        },
                        {
                            headerName: 'Chapa',
                            field: 'vehiculo_chapa',
                            width: 100
                        },
                        {
                            headerName: 'Estado',
                            field: 'estado_sifen',
                            width: 120,
                            cellRenderer: params => {
                                const estado = params.value || 'Pendiente';
                                const clases = {
                                    'Pendiente': 'status-pendiente',
                                    'Enviado': 'status-enviado',
                                    'Aprobado': 'status-aprobado',
                                    'Rechazado': 'status-rechazado',
                                    'Anulado': 'status-anulado'
                                };
                                return `<span class="status-badge ${clases[estado] || ''}">${estado}</span>`;
                            }
                        },
                        {
                            headerName: 'CDC',
                            field: 'cdc',
                            width: 120,
                            valueFormatter: p => p.value ? p.value.substring(0, 15) + '...' : ''
                        }
                    ];

                    const gridOptions = {
                        theme: "legacy",
                        columnDefs: columnDefs,
                        rowModelType: 'clientSide',
                        masterDetail: false,
                        pagination: false,
                        suppressPaginationPanel: true,

                        defaultColDef: {
                            flex: 1,
                            minWidth: 100,
                            resizable: true,
                            sortable: true,
                            filter: true,
                            enableValue: true,
                            enableRowGroup: true,
                            enablePivot: true,
                            menuTabs: ['generalMenuTab', 'filterMenuTab', 'columnsMenuTab']
                        },

                        // Habilitar menú de columnas en header
                        suppressMenuHide: false,
                        columnMenu: 'legacy',

                        // Sidebar con todas las herramientas
                        sideBar: {
                            toolPanels: [{
                                    id: 'columns',
                                    labelDefault: 'Columnas',
                                    labelKey: 'columns',
                                    iconKey: 'columns',
                                    toolPanel: 'agColumnsToolPanel',
                                    toolPanelParams: {
                                        suppressRowGroups: false,
                                        suppressValues: false,
                                        suppressPivots: false,
                                        suppressPivotMode: false,
                                        suppressColumnFilter: false,
                                        suppressColumnSelectAll: false,
                                        suppressColumnExpandAll: false
                                    }
                                },
                                {
                                    id: 'filters',
                                    labelDefault: 'Filtros',
                                    labelKey: 'filters',
                                    iconKey: 'filter',
                                    toolPanel: 'agFiltersToolPanel'
                                }
                            ],
                            defaultToolPanel: ''
                        },
                        pivotMode: false,

                        // Habilitar funciones enterprise
                        enableRangeSelection: true,
                        enableCharts: true,
                        rowGroupPanelShow: 'always',
                        groupDisplayType: 'groupRows',

                        // Status bar
                        statusBar: {
                            statusPanels: [{
                                    statusPanel: 'agTotalAndFilteredRowCountComponent',
                                    align: 'left'
                                },
                                {
                                    statusPanel: 'agSelectedRowCountComponent',
                                    align: 'center'
                                },
                                {
                                    statusPanel: 'agAggregationComponent',
                                    align: 'right'
                                }
                            ]
                        },

                        localeText: {
                            loadingOoo: 'Cargando...',
                            noRowsToShow: 'No hay notas de remisión',
                            pinColumn: 'Fijar Columna',
                            pinLeft: 'Fijar a la Izquierda',
                            pinRight: 'Fijar a la Derecha',
                            noPin: 'Sin Fijar',
                            autosizeThiscolumn: 'Autoajustar esta columna',
                            autosizeAllColumns: 'Autoajustar todas las columnas',
                            resetColumns: 'Restablecer Columnas',
                            filterOoo: 'Filtrar...',
                            equals: 'Igual',
                            notEqual: 'No igual',
                            contains: 'Contiene',
                            notContains: 'No contiene',
                            startsWith: 'Empieza con',
                            endsWith: 'Termina con',
                            lessThan: 'Menor que',
                            greaterThan: 'Mayor que',
                            inRange: 'En rango',
                            copy: 'Copiar',
                            copyWithHeaders: 'Copiar con Encabezados',
                            copyWithGroupHeaders: 'Copiar con Encabezados de Grupo',
                            paste: 'Pegar',
                            export: 'Exportar',
                            csvExport: 'Exportar CSV',
                            excelExport: 'Exportar Excel',
                            columns: 'Columnas',
                            filters: 'Filtros',
                            rowGroupColumns: 'Agrupar por',
                            rowGroupColumnsEmptyMessage: 'Arrastre columnas aquí para agrupar',
                            valueColumns: 'Valores',
                            pivotColumns: 'Pivote',
                            pivotMode: 'Modo Pivote',
                            groups: 'Grupos',
                            values: 'Valores',
                            pivots: 'Pivotes',
                            toolPanelButton: 'Panel de Herramientas',
                            sum: 'Suma',
                            min: 'Mínimo',
                            max: 'Máximo',
                            avg: 'Promedio',
                            count: 'Cantidad',
                            first: 'Primero',
                            last: 'Último',
                            none: 'Ninguno',
                            selectAll: 'Seleccionar Todo',
                            searchOoo: 'Buscar...',
                            selectAllSearchResults: 'Seleccionar Todo (Búsqueda)',
                            blanks: '(Vacíos)',
                            blank: 'Vacío',
                            notBlank: 'No Vacío'
                        },

                        getRowStyle: params => {
                            const data = params.data;
                            if (data) {
                                const estadoSifen = data.estado_sifen || '';
                                if (estadoSifen.toLowerCase() === 'anulado') {
                                    const isDark = document.documentElement.classList.contains('dark');
                                    return {
                                        background: isDark ? '#1e293b' : '#f1f5f9',
                                        color: isDark ? '#94a3b8' : '#94a3b8',
                                        borderLeft: '5px solid #ef4444'
                                    };
                                }
                            }
                            return null;
                        },

                        popupParent: document.body,
                        suppressAnimationFrame: false,
                        animateRows: false,

                        rowSelection: 'single',
                        getContextMenuItems: (params) => this.getContextMenu(params)
                    };

                    const gridDiv = document.querySelector('#nrGrid');
                    gridApi = agGrid.createGrid(gridDiv, gridOptions);

                    // Gestión de tema dinámico
                    let currentThemeClass = '';
                    const updateGridTheme = () => {
                        const isDark = document.documentElement.classList.contains('dark');
                        const newThemeClass = isDark ? 'ag-theme-quartz-dark' : 'ag-theme-quartz';
                        if (currentThemeClass !== newThemeClass) {
                            currentThemeClass = newThemeClass;
                            gridDiv.className = newThemeClass + ' w-full h-full rounded-lg shadow-sm';
                        }
                    };
                    updateGridTheme();

                    // Observer para cambios de tema
                    const observer = new MutationObserver((mutations) => {
                        for (const mutation of mutations) {
                            if (mutation.attributeName === 'class') {
                                updateGridTheme();
                                break;
                            }
                        }
                    });
                    observer.observe(document.documentElement, {
                        attributes: true,
                        attributeFilter: ['class']
                    });

                    // Cargar datos iniciales
                    this.loadGridData();
                },

                async loadGridData() {
                    if (!gridApi) return;

                    gridApi.setGridOption('loading', true);

                    try {
                        let url = `${API_URL}?action=list&id_empresa=${ID_EMPRESA}&startRow=0&endRow=10000`;
                        if (this.filtroFechaDesde) url += `&fecha_desde=${this.filtroFechaDesde}`;
                        if (this.filtroFechaHasta) url += `&fecha_hasta=${this.filtroFechaHasta}`;
                        if (this.filtroEstado) url += `&estado_sifen=${this.filtroEstado}`;

                        const res = await fetch(url);
                        const data = await res.json();

                        if (data.success) {
                            gridApi.setGridOption('rowData', data.rows || []);
                        } else {
                            gridApi.setGridOption('rowData', []);
                        }
                    } catch (e) {
                        console.error('Error cargando datos:', e);
                        gridApi.setGridOption('rowData', []);
                    }

                    gridApi.setGridOption('loading', false);
                },

                getContextMenu(params) {
                    if (!params.node || !params.node.data) return ['copy'];

                    const data = params.node.data;
                    const items = [];

                    // Editar (solo pendientes)
                    if (data.estado_sifen === 'Pendiente') {
                        items.push({
                            name: 'Editar Nota de Remisión',
                            icon: '<i class="fa-solid fa-pen-to-square"></i>',
                            action: () => this.editarRemision(data.id_remision)
                        });
                    }

                    // Enviar a SIFEN
                    if (data.estado_sifen === 'Pendiente' || data.estado_sifen === 'Rechazado') {
                        items.push({
                            name: 'Enviar a SIFEN',
                            icon: '<i class="fa-solid fa-paper-plane"></i>',
                            action: () => this.enviarSifen(data.id_remision)
                        });
                    }

                    // Motivo de Rechazo
                    if (data.estado_sifen === 'Rechazado') {
                        items.push({
                            name: 'Motivo Rechazo',
                            icon: '<i class="fa-solid fa-circle-exclamation text-red-500"></i>',
                            action: () => this.mostrarMotivoRechazo(data)
                        });
                        items.push({
                            name: 'Anular NR localmente',
                            icon: '<i class="fa-solid fa-ban text-orange-500"></i>',
                            action: () => this.anularLocal(data.id_remision)
                        });
                    }

                    // Consultar en SIFEN
                    if (data.cdc) {
                        items.push({
                            name: 'Consultar en SIFEN',
                            icon: '<i class="fa-solid fa-magnifying-glass"></i>',
                            action: () => this.consultarSifen(data.id_remision, data.cdc)
                        });
                    }

                    // Separador si hay items anteriores
                    if (items.length > 0) {
                        items.push('separator');
                    }

                    // Ver detalles
                    items.push({
                        name: 'Ver Detalles',
                        icon: '<i class="fa-solid fa-eye"></i>',
                        action: () => this.verDetalles(data.id_remision)
                    });

                    // Imprimir (siempre disponible)
                    items.push({
                        name: 'Imprimir KUDE',
                        icon: '<i class="fa-solid fa-print"></i>',
                        action: () => this.mostrarPrintPreview(data.id_remision, data.nro_documento)
                    });

                    // Eliminar (solo pendientes)
                    if (data.estado_sifen === 'Pendiente') {
                        items.push('separator');
                        items.push({
                            name: 'Eliminar',
                            icon: '<i class="fa-solid fa-trash text-red-500"></i>',
                            cssClasses: ['text-red-600'],
                            action: () => this.eliminarRemision(data.id_remision)
                        });
                    }

                    return items;
                },

                agregarItem() {
                    this.nuevaRemision.items.push({
                        codigo: '',
                        descripcion: '',
                        cantidad: 1,
                        unidad_medida: '77',
                        productosLista: [],
                        productosDescLista: [],
                        productoIndex: 0,
                        productoDescIndex: 0
                    });
                },

                eliminarItem(index) {
                    this.nuevaRemision.items.splice(index, 1);
                },

                // ========== AUTOCOMPLETADO PRODUCTOS ==========
                async buscarProductosPorCodigo(index) {
                    const item = this.nuevaRemision.items[index];
                    const term = item.codigo;
                    if (!term || term.length < 2) {
                        item.productosLista = [];
                        return;
                    }
                    try {
                        const res = await fetch(`${API_URL}?action=buscar_productos&term=${encodeURIComponent(term)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();
                        if (data.success) {
                            item.productosLista = data.data || [];
                            item.productoIndex = 0;
                        }
                    } catch (e) {
                        console.error('Error buscando productos:', e);
                    }
                },

                async buscarProductosPorDescripcion(index) {
                    const item = this.nuevaRemision.items[index];
                    const term = item.descripcion;
                    if (!term || term.length < 2) {
                        item.productosDescLista = [];
                        return;
                    }
                    try {
                        const res = await fetch(`${API_URL}?action=buscar_productos&term=${encodeURIComponent(term)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();
                        if (data.success) {
                            item.productosDescLista = data.data || [];
                            item.productoDescIndex = 0;
                        }
                    } catch (e) {
                        console.error('Error buscando productos:', e);
                    }
                },

                seleccionarProducto(index, producto) {
                    const item = this.nuevaRemision.items[index];
                    item.codigo = producto.codigo || '';
                    item.descripcion = producto.descripcion || '';
                    item.productosLista = [];
                    item.productosDescLista = [];
                },

                // ========== BUSCADOR DE PRODUCTOS ESTILO POS ==========
                async buscarProductos() {
                    const query = this.productoSearch.trim().toUpperCase();
                    if (query.length < 2) {
                        this.productosResultados = [];
                        this.showProductoDropdown = false;
                        return;
                    }

                    this.buscandoProductos = true;
                    this.showProductoDropdown = true;

                    try {
                        const res = await fetch(`${API_URL}?action=buscar_productos&term=${encodeURIComponent(query)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success) {
                            this.productosResultados = data.data || [];
                            this.productoSelectedIdx = 0;
                        }
                    } catch (e) {
                        console.error('Error buscando productos:', e);
                        this.productosResultados = [];
                    }
                    this.buscandoProductos = false;
                },

                agregarProductoSeleccionado() {
                    if (this.productosResultados.length > 0 && this.productoSelectedIdx >= 0) {
                        this.agregarProductoAlCarrito(this.productosResultados[this.productoSelectedIdx]);
                    }
                },

                agregarProductoAlCarrito(producto) {
                    // Verificar si ya existe en el carrito
                    const existente = this.nuevaRemision.items.find(item =>
                        item.codigo === producto.codigo || item.id_producto === producto.id
                    );

                    if (existente) {
                        // Incrementar cantidad
                        existente.cantidad = parseFloat(existente.cantidad || 1) + 1;
                        this.showInfo(`${producto.descripcion}: ${existente.cantidad}`, 'Cantidad actualizada');
                    } else {
                        // Agregar nuevo item
                        this.nuevaRemision.items.push({
                            id_producto: producto.id,
                            codigo: producto.codigo || '',
                            descripcion: producto.descripcion || '',
                            cantidad: 1,
                            unidad_medida: producto.unidad_medida || '77',
                            stock: producto.stock || 0
                        });
                        this.showSuccess(producto.descripcion, 'Agregado');
                    }

                    // Limpiar búsqueda y mantener foco
                    this.productoSearch = '';
                    this.productosResultados = [];
                    this.showProductoDropdown = false;
                    this.$nextTick(() => {
                        if (this.$refs.productoSearchInput) {
                            this.$refs.productoSearchInput.focus();
                        }
                    });
                },

                // ========== BUSCADOR FACTURAS VINCULADAS ==========
                async buscarFacturas() {
                    const query = this.facturaSearch.trim();
                    if (query.length < 2) {
                        this.facturasResultados = [];
                        this.showFacturaDropdown = false;
                        return;
                    }

                    this.buscandoFacturas = true;
                    this.showFacturaDropdown = true;

                    try {
                        const res = await fetch(`${API_URL}?action=buscar_facturas&term=${encodeURIComponent(query)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success) {
                            // Filtrar facturas que ya están vinculadas
                            const idsVinculados = this.nuevaRemision.facturas_vinculadas.map(f => f.id_factura);
                            this.facturasResultados = (data.data || []).filter(f => !idsVinculados.includes(f.id_factura));
                        } else {
                            this.facturasResultados = [];
                        }
                    } catch (e) {
                        console.error('Error buscando facturas:', e);
                        this.facturasResultados = [];
                    }

                    this.buscandoFacturas = false;
                },

                agregarFacturaSeleccionada() {
                    if (this.facturasResultados.length > 0 && this.facturaSelectedIdx >= 0) {
                        this.agregarFactura(this.facturasResultados[this.facturaSelectedIdx]);
                    }
                },

                agregarFactura(factura) {
                    console.log('🔵 agregarFactura LLAMADO para:', factura.nro_factura);
                    console.log('🔵 Items actuales ANTES:', this.nuevaRemision.items.length, JSON.parse(JSON.stringify(this.nuevaRemision.items)));
                    console.log('🔵 Facturas vinculadas ANTES:', this.nuevaRemision.facturas_vinculadas.length);

                    // Verificar si ya está vinculada
                    const yaVinculada = this.nuevaRemision.facturas_vinculadas.find(f => f.id_factura === factura.id_factura);
                    if (yaVinculada) {
                        this.showWarning(`La factura ${factura.nro_factura} ya está vinculada`, 'Ya vinculada');
                        return;
                    }

                    // Agregar factura a la lista de vinculadas (permitir múltiples)
                    this.nuevaRemision.facturas_vinculadas.push({
                        id_factura: factura.id_factura,
                        nro_factura: factura.nro_factura,
                        fecha: factura.fecha,
                        cliente: factura.nombre_cliente,
                        ruc_cliente: factura.ruc_cliente,
                        total: factura.total,
                        cdc: factura.cdc || '',
                        items: factura.items || []
                    });

                    // Auto-poblar receptor si no está lleno
                    if (!this.nuevaRemision.receptor_ruc && factura.ruc_cliente) {
                        this.nuevaRemision.receptor_ruc = factura.ruc_cliente;
                        this.nuevaRemision.receptor_nombre = factura.nombre_cliente || '';
                        this.destinatarioSearch = factura.nombre_cliente || '';
                    }

                    // Importar items de esta factura (sin duplicar por id_factura_origen)
                    console.log('📦 Factura seleccionada:', factura.nro_factura, '| Items recibidos:', factura.items?.length || 0, factura.items);
                    if (factura.items && factura.items.length > 0) {
                        let importados = 0;
                        factura.items.forEach(item => {
                            // Agregar item marcando su origen
                            this.nuevaRemision.items.push({
                                id_producto: item.id_producto || null,
                                codigo: item.codigo || '',
                                descripcion: item.descripcion || item.producto || '',
                                cantidad: parseFloat(item.cantidad || 1),
                                unidad_medida: item.unidad_medida || '77',
                                id_factura_origen: factura.id_factura
                            });
                            importados++;
                        });

                        this.showSuccess(`${factura.nro_factura} - ${importados} items agregados`, 'Factura vinculada');
                    } else {
                        this.showInfo(`${factura.nro_factura} (sin items)`, 'Factura vinculada');
                    }

                    console.log('🟢 Items actuales DESPUÉS:', this.nuevaRemision.items.length, JSON.parse(JSON.stringify(this.nuevaRemision.items)));

                    // Limpiar búsqueda
                    this.facturaSearch = '';
                    this.facturasResultados = [];
                    this.showFacturaDropdown = false;
                },

                quitarFactura(index) {
                    const factura = this.nuevaRemision.facturas_vinculadas[index];
                    if (!factura) return;

                    // Quitar factura y sus items directamente
                    this.nuevaRemision.items = this.nuevaRemision.items.filter(
                        item => item.id_factura_origen !== factura.id_factura
                    );
                    this.nuevaRemision.facturas_vinculadas.splice(index, 1);
                    this.showSuccess('Factura e items eliminados', 'Eliminado');
                },

                // ========== BUSCADOR DESTINATARIO (estilo POS) ==========
                async buscarDestinatarios(checkSifen = false) {
                    const query = this.destinatarioSearch.trim();
                    if (query.length < 2) {
                        this.destinatariosLista = [];
                        return;
                    }

                    this.buscandoDestinatario = true;
                    this.showDestinatarioDropdown = true;

                    try {
                        // Búsqueda local
                        const res = await fetch(`${API_URL}?action=buscar_clientes&term=${encodeURIComponent(query)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success) {
                            this.destinatariosLista = (data.data || []).map(c => ({
                                ...c,
                                source: 'local'
                            }));
                        }

                        // Si checkSifen=true (Enter), no hay resultados y parece RUC/Cédula, buscar en SIFEN
                        if (checkSifen && this.destinatariosLista.length === 0 && /^[0-9.-]+$/.test(query) && query.length >= 5) {
                            const resSifen = await fetch(`${API_URL}?action=consultar_ruc_sifen&ruc=${encodeURIComponent(query)}&id_empresa=${ID_EMPRESA}`);
                            const dataSifen = await resSifen.json();

                            if (dataSifen.success && dataSifen.data) {
                                const cliente = {
                                    id: null,
                                    ruc: dataSifen.data.ruc + (dataSifen.data.dv ? '-' + dataSifen.data.dv : ''),
                                    nombre: dataSifen.data.nombre || '',
                                    direccion: dataSifen.data.direccion || '',
                                    source: 'sifen'
                                };
                                // Auto-seleccionar
                                this.seleccionarDestinatario(cliente);
                            } else if (dataSifen.error) {
                                console.log('SIFEN error:', dataSifen.error);
                            }
                        }
                    } catch (e) {
                        console.error('Error buscando destinatarios:', e);
                    }

                    this.buscandoDestinatario = false;
                },

                seleccionarDestinatario(cliente) {
                    this.nuevaRemision.receptor_ruc = cliente.ruc || cliente.documento || '';
                    this.nuevaRemision.receptor_nombre = cliente.nombre || '';
                    this.nuevaRemision.receptor_direccion = cliente.direccion || '';
                    this.destinatarioSearch = cliente.nombre || '';
                    this.destinatariosLista = [];
                    this.showDestinatarioDropdown = false;
                },

                // ========== BUSCADOR CONDUCTOR (estilo POS) ==========
                async buscarConductores(checkSifen = false) {
                    const query = this.conductorSearch.trim();
                    if (query.length < 2) {
                        this.conductoresLista = [];
                        return;
                    }

                    this.buscandoConductor = true;
                    this.showConductorDropdown = true;

                    try {
                        // Búsqueda local
                        const res = await fetch(`${API_URL}?action=buscar_clientes&term=${encodeURIComponent(query)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success) {
                            this.conductoresLista = (data.data || []).map(c => ({
                                ...c,
                                source: 'local'
                            }));
                        }

                        // Si checkSifen=true (Enter) y no hay resultados y parece RUC/Cédula, buscar en SIFEN
                        if (checkSifen && this.conductoresLista.length === 0 && /^[0-9.-]+$/.test(query) && query.length >= 5) {
                            const resSifen = await fetch(`${API_URL}?action=consultar_ruc_sifen&ruc=${encodeURIComponent(query)}&id_empresa=${ID_EMPRESA}`);
                            const dataSifen = await resSifen.json();

                            if (dataSifen.success && dataSifen.data) {
                                const cliente = {
                                    id: null,
                                    documento: dataSifen.data.ruc + (dataSifen.data.dv ? '-' + dataSifen.data.dv : ''),
                                    nombre: dataSifen.data.nombre || '',
                                    source: 'sifen'
                                };
                                // Auto-seleccionar
                                this.seleccionarConductor(cliente);
                            }
                        }
                    } catch (e) {
                        console.error('Error buscando conductores:', e);
                    }

                    this.buscandoConductor = false;
                },

                seleccionarConductor(cliente) {
                    // Usar 'numero' del cliente (viene como 'ruc' en la API) en lugar de 'documento'
                    this.nuevaRemision.conductor_documento = cliente.ruc || cliente.numero || cliente.documento || '';
                    this.nuevaRemision.conductor_nombre = cliente.nombre || '';
                    this.conductorSearch = cliente.nombre || '';
                    this.conductoresLista = [];
                    this.showConductorDropdown = false;
                },

                // ========== BUSCADOR VEHÍCULO (estilo POS) ==========
                async buscarVehiculos() {
                    const query = this.vehiculoSearch.trim();
                    if (query.length < 1) {
                        this.vehiculosLista = [];
                        return;
                    }

                    this.buscandoVehiculo = true;
                    this.showVehiculoDropdown = true;

                    try {
                        const res = await fetch(`vehiculos_api.php?action=buscar&term=${encodeURIComponent(query)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success) {
                            this.vehiculosLista = data.data || [];
                        }
                    } catch (e) {
                        console.error('Error buscando vehículos:', e);
                    }

                    this.buscandoVehiculo = false;
                },

                seleccionarVehiculo(vehiculo) {
                    this.nuevaRemision.vehiculo_tipo = vehiculo.tipo || '';
                    this.nuevaRemision.vehiculo_marca = vehiculo.marca + (vehiculo.modelo ? ' ' + vehiculo.modelo : '');
                    this.nuevaRemision.vehiculo_chapa = vehiculo.chapa || '';
                    this.vehiculoSearch = '';
                    this.vehiculosLista = [];
                    this.showVehiculoDropdown = false;

                    // Si el vehículo tiene conductor por defecto y no hay conductor seleccionado, asignarlo
                    if (vehiculo.conductor_nombre && !this.nuevaRemision.conductor_nombre) {
                        this.nuevaRemision.conductor_documento = vehiculo.conductor_documento || '';
                        this.nuevaRemision.conductor_nombre = vehiculo.conductor_nombre || '';
                        this.conductorSearch = vehiculo.conductor_nombre || '';
                    }
                },

                // ========== MODAL NUEVO VEHÍCULO ==========
                abrirNuevoVehiculoModal() {
                    this.nuevoVehiculo = {
                        chapa: this.vehiculoSearch.toUpperCase(),
                        tipo: '',
                        marca: '',
                        modelo: '',
                        color: ''
                    };
                    this.showVehiculoDropdown = false;
                    this.showNuevoVehiculoModal = true;
                },

                async guardarNuevoVehiculo() {
                    if (!this.nuevoVehiculo.chapa || !this.nuevoVehiculo.tipo || !this.nuevoVehiculo.marca) {
                        this.showError('Chapa, Tipo y Marca son requeridos');
                        return;
                    }

                    this.guardandoNuevoVehiculo = true;
                    try {
                        const res = await fetch(`vehiculos_api.php?action=create&id_empresa=${ID_EMPRESA}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(this.nuevoVehiculo)
                        });
                        const data = await res.json();

                        if (data.success) {
                            // Seleccionar el nuevo vehículo
                            this.nuevaRemision.vehiculo_chapa = this.nuevoVehiculo.chapa;
                            this.nuevaRemision.vehiculo_tipo = this.nuevoVehiculo.tipo;
                            this.nuevaRemision.vehiculo_marca = this.nuevoVehiculo.marca + (this.nuevoVehiculo.modelo ? ' ' + this.nuevoVehiculo.modelo : '');
                            this.vehiculoSearch = '';

                            this.showNuevoVehiculoModal = false;
                            this.showSuccess(`${this.nuevoVehiculo.chapa} ha sido registrado correctamente`, 'Vehículo creado');
                        } else {
                            this.showError(data.error || 'Error al guardar vehículo');
                        }
                    } catch (e) {
                        console.error('Error guardando vehículo:', e);
                        this.showError('Error de conexión');
                    }
                    this.guardandoNuevoVehiculo = false;
                },

                // ========== MODAL NUEVO CLIENTE ==========
                abrirNuevoClienteModal(tipo) {
                    this.nuevoClienteTipo = tipo;
                    const valorBusqueda = (tipo === 'destinatario' ? this.destinatarioSearch : this.conductorSearch).trim();

                    // Detectar si es RUC (contiene números) o nombre (solo texto)
                    const esRuc = /\d/.test(valorBusqueda);

                    this.nuevoCliente = {
                        ruc: esRuc ? valorBusqueda : '',
                        nombre: esRuc ? '' : valorBusqueda,
                        direccion: '',
                        telefono: ''
                    };
                    this.showDestinatarioDropdown = false;
                    this.showConductorDropdown = false;
                    this.showNuevoClienteModal = true;
                },

                async buscarRucSifen() {
                    const ruc = this.nuevoCliente.ruc.trim();
                    if (!ruc || ruc.length < 3) {
                        this.showWarning('Ingrese un RUC/CI válido');
                        return;
                    }

                    this.buscandoRucNuevo = true;
                    try {
                        const res = await fetch(`${API_URL}?action=consultar_ruc_sifen&ruc=${encodeURIComponent(ruc)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success && data.data) {
                            this.nuevoCliente.ruc = data.data.ruc + (data.data.dv ? '-' + data.data.dv : '');
                            this.nuevoCliente.nombre = data.data.nombre || '';
                            this.nuevoCliente.direccion = data.data.direccion || '';
                        } else {
                            this.showInfo(data.error || 'RUC no encontrado en SET', 'No encontrado');
                        }
                    } catch (e) {
                        console.error('Error buscando RUC:', e);
                        this.showError('Error de conexión');
                    }
                    this.buscandoRucNuevo = false;
                },

                async guardarNuevoCliente() {
                    if (!this.nuevoCliente.ruc || !this.nuevoCliente.nombre) {
                        this.showError('RUC/CI y Nombre son requeridos');
                        return;
                    }

                    this.guardandoNuevoCliente = true;
                    try {
                        const res = await fetch(`${API_URL}?action=guardar_cliente&id_empresa=${ID_EMPRESA}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                ruc: this.nuevoCliente.ruc,
                                nombre: this.nuevoCliente.nombre,
                                direccion: this.nuevoCliente.direccion,
                                telefono: this.nuevoCliente.telefono,
                                documento: this.nuevoCliente.ruc
                            })
                        });
                        const data = await res.json();

                        if (data.success) {
                            // Seleccionar el nuevo cliente según el tipo
                            if (this.nuevoClienteTipo === 'destinatario') {
                                this.nuevaRemision.receptor_ruc = this.nuevoCliente.ruc;
                                this.nuevaRemision.receptor_nombre = this.nuevoCliente.nombre;
                                this.nuevaRemision.receptor_direccion = this.nuevoCliente.direccion;
                                this.destinatarioSearch = this.nuevoCliente.nombre;
                            } else {
                                this.nuevaRemision.conductor_documento = this.nuevoCliente.ruc;
                                this.nuevaRemision.conductor_nombre = this.nuevoCliente.nombre;
                                this.conductorSearch = this.nuevoCliente.nombre;
                            }

                            this.showNuevoClienteModal = false;
                            this.showSuccess(`${this.nuevoCliente.nombre} ha sido registrado correctamente`, 'Cliente creado');
                        } else {
                            this.showError(data.error || 'Error al guardar cliente');
                        }
                    } catch (e) {
                        console.error('Error guardando cliente:', e);
                        this.showError('Error de conexión');
                    }
                    this.guardandoNuevoCliente = false;
                },

                // ========== GUARDAR CLIENTE AUTOMÁTICO ==========
                async guardarClienteAutomatico(tipo) {
                    let ruc, nombre, direccion, documento;
                    if (tipo === 'destinatario') {
                        ruc = this.nuevaRemision.receptor_ruc;
                        nombre = this.nuevaRemision.receptor_nombre;
                        direccion = this.nuevaRemision.receptor_direccion;
                        documento = '';
                    } else {
                        ruc = '';
                        nombre = this.nuevaRemision.conductor_nombre;
                        direccion = '';
                        documento = this.nuevaRemision.conductor_documento;
                    }

                    if (!nombre) return;

                    try {
                        const res = await fetch(`${API_URL}?action=guardar_cliente&id_empresa=${ID_EMPRESA}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                ruc,
                                nombre,
                                direccion,
                                documento
                            })
                        });
                        const data = await res.json();
                        if (data.success && !data.exists) {
                            console.log('Cliente guardado:', data.id);
                        }
                    } catch (e) {
                        console.error('Error guardando cliente:', e);
                    }
                },

                async guardarRemision() {
                    if (this.nuevaRemision.items.length === 0) {
                        this.showError('Debe agregar al menos una mercadería');
                        return;
                    }

                    this.guardando = true;
                    this.showLoading('Guardando...');
                    try {
                        // Guardar automáticamente destinatario y conductor si son nuevos
                        await this.guardarClienteAutomatico('destinatario');
                        await this.guardarClienteAutomatico('conductor');

                        const isEditing = !!this.editingId;
                        const action = isEditing ? 'update' : 'create';
                        const payload = {
                            ...this.nuevaRemision,
                            id_remision: this.editingId
                        };

                        console.log('📤 Enviando payload:', action, payload);

                        const res = await fetch(`${API_URL}?action=${action}&id_empresa=${ID_EMPRESA}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(payload)
                        });

                        const text = await res.text();
                        console.log('📥 Respuesta raw:', text);

                        let data;
                        try {
                            data = JSON.parse(text);
                        } catch (parseError) {
                            console.error('Error parseando JSON:', parseError, text);
                            this.showError('Error del servidor: ' + text.substring(0, 200));
                            this.guardando = false;
                            return;
                        }

                        if (data.success) {
                            const mensaje = isEditing ?
                                `Nota de Remisión #${this.nuevaRemision.nro_documento} actualizada correctamente` :
                                `Nota de Remisión ${data.nro_documento} creada correctamente`;

                            // Si hubo cambios en documento/establecimiento/punto, avisar que se limpió el estado SIFEN
                            let mensajeAdicional = '';
                            if (isEditing && data.sifen_resetted) {
                                mensajeAdicional = ' - Estado SIFEN reseteado (debe enviar nuevamente)';
                            }

                            this.showNuevaRemision = false;
                            this.editingId = null;
                            this.nuevaRemision = this.getEmptyRemision();
                            await this.loadGridData();
                            this.showSuccess(mensaje + mensajeAdicional);
                        } else {
                            this.showError(data.error || 'Error al guardar');
                        }
                    } catch (e) {
                        console.error('Error guardando:', e);
                        this.showError('Error de conexión: ' + e.message);
                    }
                    this.guardando = false;
                },

                async enviarSifen(id) {
                    const confirmed = await this.confirm(
                        '¿Enviar a SIFEN?',
                        'La nota de remisión será firmada y enviada a la SET',
                        'info',
                        'Sí, Enviar'
                    );

                    if (!confirmed) return;

                    this.showLoading('Enviando a SIFEN...');

                    try {
                        const res = await fetch(`${SIFEN_API_URL}?action=enviar&id=${id}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        this.hideLoading();
                        if (data.success) {
                            this.detalleModal = {
                                show: true,
                                title: '¡Enviado Correctamente!',
                                content: data.message || 'Nota de Remisión enviada a SIFEN',
                                type: 'success'
                            };
                        } else {
                            this.detalleModal = {
                                show: true,
                                title: 'Error al Enviar',
                                content: data.error || 'Error al enviar a SIFEN',
                                type: 'error'
                            };
                        }
                        await this.loadGridData();
                    } catch (e) {
                        this.hideLoading();
                        this.showError('Error de conexión');
                    }
                },

                async consultarSifen(id, cdc) {
                    this.showLoading('Consultando SIFEN...');

                    try {
                        const res = await fetch(`${SIFEN_API_URL}?action=consultar&id=${id}&cdc=${cdc}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();
                        this.hideLoading();

                        if (data.success) {
                            // Mostrar resultado en modal centrado
                            this.detalleModal = {
                                show: true,
                                title: 'Consulta SIFEN',
                                content: data.message || 'Consulta realizada',
                                type: data.estado === 'Aprobado' ? 'success' : (data.estado === 'Rechazado' ? 'error' : 'info')
                            };
                        } else {
                            this.detalleModal = {
                                show: true,
                                title: 'Error de Consulta',
                                content: data.error || 'Error al consultar',
                                type: 'error'
                            };
                        }
                        this.loadGridData();
                    } catch (e) {
                        this.showError('Error de conexión');
                    }
                },

                async verDetalles(id) {
                    try {
                        const res = await fetch(`${API_URL}?action=get&id=${id}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success) {
                            const nr = data.data;
                            let itemsText = nr.items.map(i => `• ${i.cantidad} x ${i.descripcion}`).join('\n');
                            const detalles = `Fecha: ${nr.fecha}\nMotivo: ${nr.motivo_descripcion || 'N/A'}\nDestinatario: ${nr.receptor_nombre}\nConductor: ${nr.conductor_nombre}\nVehículo: ${nr.vehiculo_chapa}\n\nMercaderías:\n${itemsText}`;

                            // Mostrar modal con detalles
                            this.detalleModal = {
                                show: true,
                                title: `Remisión #${nr.nro_documento}`,
                                content: detalles,
                                type: 'info'
                            };
                        } else {
                            this.showError(data.error || 'Error al cargar detalles');
                        }
                    } catch (e) {
                        console.error('Error verDetalles:', e);
                        this.showError('Error cargando detalles: ' + e.message);
                    }
                },

                mostrarMotivoRechazo(data) {
                    let mensaje = 'Sin información de rechazo';

                    if (data.mensaje_sifen) {
                        try {
                            const info = JSON.parse(data.mensaje_sifen);
                            // Estructura puede variar: {codigo, mensaje} o {data: {cod, msg}}
                            let codigo = info.codigo || info.data?.cod || 'N/A';
                            let msg = info.mensaje || info.data?.msg || info.msg || 'Sin mensaje';
                            let fecha = info.fecha_proceso || info.xml?.['env:Envelope']?.['env:Body']?.['ns2:rRetEnviDe']?.['ns2:rProtDe']?.['ns2:dFecProc'] || 'N/A';

                            mensaje = `RUC Emisor: ${RUC_EMPRESA}\nTimbrado: ${data.timbrado || TIMBRADO_EMPRESA}\n\nCódigo Error: ${codigo}\nMensaje: ${msg}\nFecha: ${fecha}`;
                        } catch (e) {
                            mensaje = `RUC Emisor: ${RUC_EMPRESA}\nTimbrado: ${data.timbrado || TIMBRADO_EMPRESA}\n\n${data.mensaje_sifen}`;
                        }
                    } else {
                        mensaje = `RUC Emisor: ${RUC_EMPRESA}\nTimbrado: ${data.timbrado || TIMBRADO_EMPRESA}\n\nSin información de rechazo`;
                    }

                    // Mostrar modal con motivo de rechazo
                    this.detalleModal = {
                        show: true,
                        title: 'Motivo de Rechazo',
                        content: mensaje,
                        type: 'error'
                    };
                },

                mostrarPrintPreview(id, nroDocumento) {
                    this.printModal = {
                        show: true,
                        url: `kude_nr.php?id=${id}&id_empresa=${ID_EMPRESA}`,
                        title: `Vista previa - NR ${nroDocumento || id}`
                    };
                },

                imprimirDesdeModal() {
                    const iframe = document.getElementById('printFrame');
                    if (iframe && iframe.contentWindow) {
                        iframe.contentWindow.print();
                    }
                },

                async eliminarRemision(id) {
                    const confirmed = await this.confirm(
                        '¿Eliminar?',
                        'Esta acción no se puede deshacer',
                        'danger',
                        'Sí, Eliminar'
                    );

                    if (!confirmed) return;

                    try {
                        const res = await fetch(`${API_URL}?action=delete&id=${id}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success) {
                            this.showSuccess('Nota de remisión eliminada', 'Eliminado');
                            this.loadGridData();
                        } else {
                            this.showError(data.error);
                        }
                    } catch (e) {
                        this.showError('Error de conexión');
                    }
                },

                async anularLocal(id) {
                    const confirmed = await this.confirm(
                        '¿Anular NR localmente?',
                        'Esta NR fue rechazada por SIFEN y será marcada como Anulada',
                        'warning',
                        'Sí, Anular'
                    );

                    if (!confirmed) return;

                    try {
                        const res = await fetch(`nr_sifen_api.php?action=anular_local&id=${id}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success) {
                            this.showSuccess('Nota de remisión anulada localmente', 'Anulado');
                            this.loadGridData();
                        } else {
                            this.showError(data.error);
                        }
                    } catch (e) {
                        this.showError('Error de conexión');
                    }
                },

                toggleDarkMode() {
                    document.documentElement.classList.toggle('dark');
                    localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
                }
            };
        }
    </script>
</body>

</html>