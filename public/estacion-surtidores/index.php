<?php
/**
 * Módulo de Gestión de Surtidores - Estación de Servicio
 * Permite configurar surtidores, picos, combustibles y pruebas de conexión
 */

require_once __DIR__ . '/../../config/bootstrap.php';

// Verificar sesión
Session::requireLogin('/public/login.php');

// Verificar permisos
Permission::requireAccess('app_grid_estacion');

// Obtener conexión empresa
$pdo = Database::getSessionEmpresaConnection();
$id_empresa = Session::getIdEmpresa();
$usuario = Session::get('usuario');

// Obtener sucursales
$sucursales = [];
try {
    $sucursalCols = $pdo->query("SHOW COLUMNS FROM sucursales")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $sucursalIdCol = in_array('id_sucursal', $sucursalCols, true) ? 'id_sucursal' : (in_array('id', $sucursalCols, true) ? 'id' : 'id_sucursal');
    $sucursalNameCol = in_array('nombre', $sucursalCols, true)
        ? 'nombre'
        : (in_array('sucursal', $sucursalCols, true) ? 'sucursal' : $sucursalIdCol);
    $stmt = $pdo->query("
        SELECT
            {$sucursalIdCol} AS id,
            {$sucursalNameCol} AS nombre
        FROM sucursales
        ORDER BY {$sucursalIdCol}
    ");
    $sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $sucursales = [];
}

// Obtener combustibles
$combustibles = [];
try {
    $stmt = $pdo->query("SELECT id, nombre, unidad_medida FROM estacion_combustibles WHERE activo = 'Y' ORDER BY nombre");
    $combustibles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $combustibles = [];
}

// Obtener surtidores
$surtidores = [];
try {
    $sucursalCols = $pdo->query("SHOW COLUMNS FROM sucursales")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $sucursalIdCol = in_array('id_sucursal', $sucursalCols, true) ? 'id_sucursal' : (in_array('id', $sucursalCols, true) ? 'id' : 'id_sucursal');
    $sucursalNameCol = in_array('nombre', $sucursalCols, true)
        ? 'nombre'
        : (in_array('sucursal', $sucursalCols, true) ? 'sucursal' : $sucursalIdCol);
    $stmt = $pdo->query("SELECT
        s.id, s.nombre, s.nro_surtidor, s.id_sucursal,
        s.tipo_control, s.controladora_ip, s.controladora_puerto,
        s.controladora_protocolo, s.activo,
        COALESCE(suc.{$sucursalNameCol}, 'N/A') AS sucursal,
        COUNT(p.id) AS cant_picos
    FROM estacion_surtidores s
    LEFT JOIN sucursales suc ON s.id_sucursal = suc.{$sucursalIdCol}
    LEFT JOIN estacion_picos p ON s.id = p.id_surtidor
    GROUP BY s.id
    ORDER BY s.nro_surtidor");
    $surtidores = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $surtidores = [];
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$webhookUrl = $scheme . '://' . $host . '/public/estacion-despachos/api/recibir_surtidor.php';
$webhookToken = trim((string)(getenv('ESTACION_SURTIDOR_TOKEN') ?: ''));
$webhookHeader = 'X-Estacion-Token';
$webhookPayloadExample = [
    'id_empresa' => (int)$id_empresa,
    'id_surtidor' => 1,
    'id_pico' => 1,
    'id_combustible' => 1,
    'litros' => 35.123,
    'monto_total' => 250000,
    'precio_unitario' => 7120,
    'totalizador_inicio' => 120000.000,
    'totalizador_fin' => 120035.123,
    'nro_comprobante' => '001-001-0000123',
    'observacion' => 'Despacho automático desde surtidor',
];

?><!DOCTYPE html>
<html lang="es" class="dark-mode">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surtidores - Estación de Servicio</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/public/assets/css/dark-mode.css">
</head>
<body class="bg-gray-50">
    <div x-data="surtidoresApp()" x-init="init()" class="min-h-screen">
        <!-- Encabezado -->
        <div class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex justify-between items-center">
                    <h1 class="text-3xl font-bold text-gray-900">
                        <i class="fas fa-tint text-blue-500 mr-2"></i> Surtidores y Picos
                    </h1>
                    <button @click="openNewSurtidor()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg flex items-center gap-2">
                        <i class="fas fa-plus"></i> Nuevo Surtidor
                    </button>
                    <a href="/public/estacion-picos/" class="bg-cyan-500 hover:bg-cyan-400 text-slate-950 px-4 py-2 rounded-lg flex items-center gap-2 font-semibold">
                        <i class="fas fa-gas-pump"></i> Picos
                    </a>
                </div>
            </div>
        </div>

        <!-- Tabla de Surtidores -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="bg-slate-900 text-white rounded-2xl shadow-xl p-6 mb-8 border border-slate-700">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                    <div class="max-w-3xl">
                        <div class="text-xs uppercase tracking-[0.24em] text-cyan-300 font-semibold">Integración automática</div>
                        <h2 class="mt-2 text-2xl font-bold">Webhook de surtidores</h2>
                        <p class="mt-2 text-sm text-slate-300">
                            Usa este endpoint para despachar de forma automática desde la controladora. El módulo sigue soportando carga manual.
                        </p>
                        <div class="mt-4 grid grid-cols-1 gap-3 text-sm">
                            <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                                <div class="text-slate-400 text-xs uppercase tracking-wide">URL</div>
                                <div class="mt-1 break-all font-mono text-cyan-200"><?= htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                                <div class="text-slate-400 text-xs uppercase tracking-wide">Header</div>
                                <div class="mt-1 font-mono text-cyan-200"><?= htmlspecialchars($webhookHeader, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="rounded-xl bg-white/5 border border-white/10 p-3">
                                <div class="text-slate-400 text-xs uppercase tracking-wide">Token</div>
                                <div class="mt-1 break-all font-mono text-cyan-200">
                                    <?= $webhookToken !== '' ? htmlspecialchars($webhookToken, ENT_QUOTES, 'UTF-8') : 'No configurado' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 min-w-[240px]">
                        <button onclick='navigator.clipboard.writeText(<?= json_encode($webhookUrl) ?>)' class="rounded-xl bg-cyan-500 hover:bg-cyan-400 text-slate-950 font-semibold px-4 py-3 transition">
                            Copiar URL
                        </button>
                        <button onclick='navigator.clipboard.writeText(<?= json_encode($webhookToken) ?>)' class="rounded-xl bg-white/10 hover:bg-white/20 text-white font-semibold px-4 py-3 transition">
                            Copiar token
                        </button>
                    </div>
                </div>
                <div class="mt-6">
                    <div class="text-xs uppercase tracking-[0.24em] text-slate-400 font-semibold mb-2">Payload ejemplo</div>
                    <pre class="overflow-auto rounded-xl bg-black/40 border border-white/10 p-4 text-xs leading-6 text-slate-200"><?= htmlspecialchars(json_encode($webhookPayloadExample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-lg shadow-md overflow-hidden">
                <table class="w-full">
                    <thead class="bg-gray-200 dark:bg-slate-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-sm font-semibold dark:text-gray-200">N° Surtidor</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold dark:text-gray-200">Nombre</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold dark:text-gray-200">Tipo Control</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold dark:text-gray-200">Sucursal</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold dark:text-gray-200">Picos</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold dark:text-gray-200">Estado</th>
                            <th class="px-6 py-3 text-center text-sm font-semibold dark:text-gray-200">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="surtidor in surtidores" :key="surtidor.id">
                            <tr class="border-t dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700">
                                <td class="px-6 py-3 dark:text-gray-200">#<span x-text="surtidor.nro_surtidor"></span></td>
                                <td class="px-6 py-3 font-medium dark:text-gray-200" x-text="surtidor.nombre"></td>
                                <td class="px-6 py-3">
                                    <span :class="surtidor.tipo_control === 'automatico' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'" class="px-3 py-1 rounded-full text-sm">
                                        <span x-text="surtidor.tipo_control === 'automatico' ? 'Automático' : 'Manual'"></span>
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-sm dark:text-gray-200" x-text="surtidor.sucursal"></td>
                                <td class="px-6 py-3 text-sm font-medium dark:text-gray-200" x-text="surtidor.cant_picos"></td>
                                <td class="px-6 py-3">
                                    <span :class="surtidor.activo === 'Y' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="text-sm font-medium">
                                        <i :class="surtidor.activo === 'Y' ? 'fas fa-check-circle' : 'fas fa-times-circle'" class="mr-1"></i>
                                        <span x-text="surtidor.activo === 'Y' ? 'Activo' : 'Inactivo'"></span>
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-center">
                                    <button @click="editarSurtidor(surtidor)" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 mr-3">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button @click="verPicos(surtidor)" class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 mr-3">
                                        <i class="fas fa-list"></i>
                                    </button>
                                    <button v-if="surtidor.tipo_control === 'automatico'" @click="testConexion(surtidor)" class="text-purple-600 hover:text-purple-800">
                                        <i class="fas fa-network-wired"></i>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div v-if="surtidores.length === 0" class="px-6 py-8 text-center text-gray-500">
                    <i class="fas fa-inbox text-4xl mb-4 block"></i>
                    No hay surtidores registrados
                </div>
            </div>
        </div>

        <!-- Modal: Nuevo/Editar Surtidor -->
        <div x-show="showFormSurtidor" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white dark:bg-slate-800 rounded-lg shadow-lg max-w-md w-full mx-4">
                <div class="px-6 py-4 border-b dark:border-slate-700 flex justify-between items-center">
                    <h2 class="text-xl font-bold dark:text-white" x-text="editingId ? 'Editar Surtidor' : 'Nuevo Surtidor'"></h2>
                    <button @click="showFormSurtidor = false" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">N° Surtidor</label>
                        <input x-model="form.nro_surtidor" type="number" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Nombre</label>
                        <input x-model="form.nombre" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400" placeholder="Ej: Surtidor 1">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Tipo de Control</label>
                        <select x-model="form.tipo_control" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white dark:border-gray-600">
                            <option value="manual">Manual</option>
                            <option value="automatico">Automático</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Sucursal</label>
                        <select x-model="form.id_sucursal" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white dark:border-gray-600">
                            <option value="">Seleccionar...</option>
                            <template x-for="suc in sucursales">
                                <option :value="suc.id" x-text="suc.nombre"></option>
                            </template>
                        </select>
                    </div>

                    <div x-show="form.tipo_control === 'automatico'" class="space-y-3 bg-blue-50 dark:bg-gray-700 p-3 rounded border border-blue-200 dark:border-blue-400">
                        <h3 class="font-semibold text-blue-900 dark:text-blue-300">Configuración Controladora</h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">IP Controladora</label>
                            <input x-model="form.controladora_ip" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-600 dark:text-white dark:border-gray-500 dark:placeholder-gray-400" placeholder="192.168.1.100">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Puerto</label>
                            <input x-model="form.controladora_puerto" type="number" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-600 dark:text-white dark:border-gray-500 dark:placeholder-gray-400" placeholder="502">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Protocolo</label>
                            <select x-model="form.controladora_protocolo" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-600 dark:text-white dark:border-gray-500">
                                <option value="">Seleccionar...</option>
                                <option value="Modbus">Modbus</option>
                                <option value="Veeder-Root">Veeder-Root</option>
                                <option value="RS485">RS485</option>
                                <option value="TCP">TCP</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 border-t dark:border-slate-700 bg-gray-50 dark:bg-slate-700 flex justify-end gap-3">
                    <button @click="showFormSurtidor = false" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600 dark:text-gray-200">Cancelar</button>
                    <button @click="guardarSurtidor()" class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg">Guardar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function surtidoresApp() {
            return {
                surtidores: <?= json_encode($surtidores) ?>,
                sucursales: <?= json_encode($sucursales) ?>,
                combustibles: <?= json_encode($combustibles) ?>,
                showFormSurtidor: false,
                editingId: null,
                form: {
                    nro_surtidor: '',
                    nombre: '',
                    tipo_control: 'manual',
                    id_sucursal: '',
                    controladora_ip: '',
                    controladora_puerto: '',
                    controladora_protocolo: '',
                    activo: 'Y'
                },

                init() {
                    // Inicialización
                },

                openNewSurtidor() {
                    this.editingId = null;
                    this.form = {
                        nro_surtidor: '',
                        nombre: '',
                        tipo_control: 'manual',
                        id_sucursal: '',
                        controladora_ip: '',
                        controladora_puerto: '',
                        controladora_protocolo: '',
                        activo: 'Y'
                    };
                    this.showFormSurtidor = true;
                },

                editarSurtidor(surtidor) {
                    this.editingId = surtidor.id;
                    this.form = { ...surtidor };
                    this.showFormSurtidor = true;
                },

                async guardarSurtidor() {
                    try {
                        const response = await fetch('api/guardar.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ ...this.form, id: this.editingId })
                        });
                        const data = await response.json();
                        if (data.ok) {
                            location.reload();
                        } else {
                            alert('Error: ' + data.error);
                        }
                    } catch (error) {
                        alert('Error al guardar: ' + error.message);
                    }
                },

                verPicos(surtidor) {
                    // Abre modal con picos del surtidor
                    alert('Funcionalidad de gestión de picos próximamente');
                },

                async testConexion(surtidor) {
                    try {
                        const response = await fetch('api/test_conexion.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: surtidor.id })
                        });
                        const data = await response.json();
                        if (data.ok) {
                            alert('✓ Conexión exitosa');
                        } else {
                            alert('✗ Error: ' + data.error);
                        }
                    } catch (error) {
                        alert('Error en la conexión: ' + error.message);
                    }
                }
            };
        }
    </script>
</body>
</html>
