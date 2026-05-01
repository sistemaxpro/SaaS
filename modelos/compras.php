<?php

/**
 * Módulo de Compras - Grid y Formulario con Alpine.js y ag-grid
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_empresa = (int)($_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? $_SESSION['id_usuario'] ?? 0);
$nombreEmpresa = '';

// Get company name
try {
    $masterDb = $_SESSION['dbu'] ?? 'serproc1';
    $dbHost = $_SESSION['server'] ?? '168.231.95.50';
    $dbUser = $_SESSION['user'] ?? 'sistemax';
    $dbPass = $_SESSION['password'] ?? 'Armagedon123';

    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT empresa FROM empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $nombreEmpresa = $stmt->fetchColumn() ?: '';
} catch (Exception $e) { /* Silently fail */
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Compras</title>
    <link rel="stylesheet" href="_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css">
    <link rel="stylesheet" href="_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="_lib/ag-grid/ag-grid-enterprise/package/dist/ag-grid-enterprise.min.noStyle.js"></script>
    <script src="_lib/ag-grid/license.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
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
        };
        window.ID_EMPRESA = <?php echo $id_empresa; ?>;
    </script>
    <script>
        // Detectar tema al inicio
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && systemDark)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
    <style>
        .ag-theme-quartz {
            --ag-font-family: "Segoe UI", "Roboto", sans-serif;
            --ag-font-size: 13px;
            --ag-odd-row-background-color: rgba(0, 0, 0, 0.05);
        }

        .ag-theme-quartz-dark {
            --ag-font-family: "Segoe UI", "Roboto", sans-serif;
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
            --ag-font-family: "Segoe UI", "Roboto", sans-serif;
        }

        :root {
            color-scheme: light;
            --bg: rgba(243, 246, 251, 0.45);
            --text: #1f2933;
            --border-color: rgba(0, 0, 0, 0.4);
            --ag-background: rgba(255, 255, 255, 0.4) !important;
            --ag-header-background: rgba(241, 245, 249, 0.6) !important;
        }

        html.dark {
            color-scheme: dark;
            --bg: rgba(15, 23, 42, 0.60);
            --text: #f1f5f9;
            --border-color: rgba(255, 255, 255, 0.4);
            --ag-background: rgba(30, 41, 59, 0.4) !important;
            --ag-header-background: rgba(15, 23, 42, 0.6) !important;
        }

        body {
            font-family: "Segoe UI", "Roboto", sans-serif;
            margin: 12px;
            padding: 24px;
            background: var(--bg);
            color: var(--text);
            min-height: calc(100vh - 24px);
            box-sizing: border-box;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            backdrop-filter: blur(8px);
            overflow-x: hidden;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .empresa-name {
            margin: 4px 0 0;
            color: #38bdf8;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .btn-chip {
            padding: 10px 22px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #2563eb;
            color: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: transform 0.1s, box-shadow 0.1s;
        }

        .btn-chip:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-chip--alt {
            background: #64748b;
        }

        .btn-chip--danger {
            background: #ef4444;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
        }

        html.dark .glass-card {
            background: rgba(15, 23, 42, 0.4);
        }

        .input-dark {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: inherit;
        }

        html.dark .input-dark {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(100, 116, 139, 0.5);
        }
    </style>
</head>

<body x-data="comprasApp()">
    <div class="page-header">
        <div>
            <h1 class="text-2xl font-bold">Compras</h1>
            <p class="empresa-name"><?php echo htmlspecialchars($nombreEmpresa); ?></p>
        </div>
        <div class="toolbar">
            <button @click="openForm()" class="btn-chip">
                <i class="fa-solid fa-plus"></i> Nueva Compra
            </button>
            <button @click="loadData()" class="btn-chip btn-chip--alt">
                <i class="fa-solid fa-sync"></i> Actualizar
            </button>
            <button @click="if(gridApi.getSelectedRows().length) openPaymentModal(gridApi.getSelectedRows()[0])" class="btn-chip btn-chip--success">
                <i class="fa-solid fa-money-bill-wave"></i> Pagar
            </button>
            <button class="btn-chip btn-chip--danger" onclick="exitSystem()">
                <i class="fa-solid fa-arrow-left"></i> Salir
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="glass-card p-4 mb-4 flex flex-wrap gap-4 items-end">
        <div>
            <label class="block text-xs uppercase font-bold mb-1 opacity-70">Desde</label>
            <input type="date" x-model="fechaDesde" @change="loadData()" class="input-dark px-3 py-2 rounded-lg">
        </div>
        <div>
            <label class="block text-xs uppercase font-bold mb-1 opacity-70">Hasta</label>
            <input type="date" x-model="fechaHasta" @change="loadData()" class="input-dark px-3 py-2 rounded-lg">
        </div>
        <div>
            <label class="block text-xs uppercase font-bold mb-1 opacity-70">Estado</label>
            <select x-model="filtroEstado" @change="loadData()" class="input-dark px-3 py-2 rounded-lg">
                <option value="">Todos</option>
                <option value="1">Activas</option>
                <option value="0">Anuladas</option>
            </select>
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs uppercase font-bold mb-1 opacity-70">Buscar</label>
            <div class="relative">
                <input type="text" x-model="buscar" @input.debounce.500ms="loadData()" placeholder="Proveedor o Nro. Factura..." class="input-dark px-3 py-2 pl-10 rounded-lg w-full">
                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 opacity-50"></i>
            </div>
        </div>
    </div>

    <!-- Grid -->
    <div class="glass-card p-2">
        <div id="gridCompras" class="ag-theme-quartz" style="height: calc(100vh - 280px); min-height: 400px;"></div>
    </div>

    <!-- Form Modal -->
    <div x-show="showForm" x-transition class="fixed inset-0 bg-black/80 flex items-center justify-center z-50 p-4" @click.self="showForm = false">
        <div class="glass-card w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col" @click.stop>
            <!-- Modal Header -->
            <div class="p-4 border-b border-slate-700 flex justify-between items-center">
                <h2 class="text-xl font-bold text-white" x-text="editingId ? 'Editar Compra' : 'Nueva Compra'"></h2>
                <div class="flex items-center gap-3">
                    <!-- OCR Scan Button - Camera Modal -->
                    <button @click="openCameraWithPermission()" class="cursor-pointer bg-purple-600 hover:bg-purple-700 px-4 py-2 rounded-lg text-white font-bold text-sm flex items-center gap-2 transition-all" :class="{'opacity-50 pointer-events-none': scanning}">
                        <span x-show="!scanning">📷 Escanear Factura</span>
                        <span x-show="scanning" class="flex items-center gap-2"><svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg> Procesando...</span>
                    </button>
                    <button @click="if(confirm('¿Desea descartar este borrador?')) resetForm()" class="text-xs bg-red-600/20 hover:bg-red-600 text-red-400 hover:text-white px-3 py-1 rounded transition-colors" x-show="!editingId && (formItems.length > 0 || proveedorSearch)">Descartar</button>
                    <button @click="showForm = false" class="text-slate-400 hover:text-white text-2xl">&times;</button>
                </div>
            </div>

            <!-- Modal Body -->
            <div class="p-4 overflow-y-auto flex-1 space-y-4">
                <!-- Proveedor y datos factura -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="lg:col-span-2">
                        <label class="block text-xs text-slate-400 uppercase font-bold mb-1">Proveedor *</label>
                        <div class="flex gap-2">
                            <div class="relative flex-1">
                                <input type="text" x-ref="proveedorInput" x-model="proveedorSearch" @input.debounce.300ms="searchProveedores()" @focus="showProveedorDropdown = true" placeholder="Buscar proveedor..." class="input-dark px-3 py-2 rounded-lg w-full">
                                <div x-show="showProveedorDropdown && proveedorResults.length > 0" @click.outside="showProveedorDropdown = false" class="absolute top-full left-0 right-0 mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl z-50 max-h-40 overflow-y-auto">
                                    <template x-for="p in proveedorResults" :key="p.id">
                                        <button @click="selectProveedor(p)" class="w-full px-3 py-2 text-left text-sm hover:bg-slate-700 text-white border-b border-slate-700 last:border-0">
                                            <div class="font-bold" x-text="p.nombre"></div>
                                            <div class="text-xs text-slate-400" x-text="'RUC: ' + p.ruc"></div>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <div class="w-32">
                                <input type="text" x-model="formData.ruc_proveedor" placeholder="RUC" class="input-dark px-3 py-2 rounded-lg w-full text-sm" title="RUC del proveedor">
                            </div>
                        </div>
                        <div x-show="selectedProveedor" class="mt-2 bg-blue-900/30 p-2 rounded-lg flex justify-between items-center">
                            <span class="text-sm font-bold text-blue-200" x-text="selectedProveedor?.nombre"></span>
                            <button @click="selectedProveedor = null; proveedorSearch = ''" class="text-blue-400 text-xs">✕</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 uppercase font-bold mb-1">Nro. Factura *</label>
                        <input type="text" x-model="formData.nro_factura" class="input-dark px-3 py-2 rounded-lg w-full" placeholder="001-001-0000001">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 uppercase font-bold mb-1">Fecha</label>
                        <input type="date" x-model="formData.fecha" class="input-dark px-3 py-2 rounded-lg w-full">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs text-slate-400 uppercase font-bold mb-1">Timbrado</label>
                        <input type="text" x-model="formData.timbrado" class="input-dark px-3 py-2 rounded-lg w-full">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 uppercase font-bold mb-1">Venc. Timbrado</label>
                        <input type="date" x-model="formData.vencimiento_timbrado" class="input-dark px-3 py-2 rounded-lg w-full">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 uppercase font-bold mb-1">Vencimiento</label>
                        <input type="date" x-model="formData.vencimiento" class="input-dark px-3 py-2 rounded-lg w-full">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 uppercase font-bold mb-1">Forma Pago</label>
                        <select x-model="formData.forma_pago" class="input-dark px-3 py-2 rounded-lg w-full">
                            <option value="1">Contado</option>
                            <option value="2">Crédito</option>
                        </select>
                    </div>
                </div>

                <!-- Items -->
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <label class="text-xs text-slate-400 uppercase font-bold">Ítems</label>
                        <button @click="addItem()" class="text-xs bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded font-bold">+ Agregar Ítem</button>
                    </div>
                    <div class="bg-slate-800/50 rounded-lg overflow-x-auto">
                        <table class="w-full text-sm min-w-[800px]">
                            <thead class="bg-slate-700/50">
                                <tr class="text-slate-300 text-xs uppercase">
                                    <th class="p-2 text-left w-10">#</th>
                                    <th class="p-2 text-left w-28">Código</th>
                                    <th class="p-2 text-left">Producto</th>
                                    <th class="p-2 text-right w-20">Cant.</th>
                                    <th class="p-2 text-right w-28">Costo</th>
                                    <th class="p-2 text-right w-32">Precio Sugerido</th>
                                    <th class="p-2 text-center w-20">IVA</th>
                                    <th class="p-2 text-right w-28">Subtotal</th>
                                    <th class="p-2 w-10"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(item, index) in formItems" :key="index">
                                    <tr class="border-t border-slate-700">
                                        <td class="p-2 text-slate-400" x-text="index + 1"></td>
                                        <td class="p-2"><input type="text" x-model="item.codigo" placeholder="Código" class="input-dark px-2 py-1 rounded w-full text-sm"></td>
                                        <td class="p-2">
                                            <div class="relative">
                                                <input type="text" x-model="item.descripcion" @input.debounce.300ms="searchProductos(index)" @focus="item.showDropdown = true" placeholder="Buscar producto..." class="input-dark px-2 py-1 rounded w-full text-sm">
                                                <div x-show="item.showDropdown && item.productoResults?.length > 0" @click.outside="item.showDropdown = false" class="absolute top-full left-0 right-0 mt-1 bg-slate-800 border border-slate-700 rounded-lg shadow-xl z-50 max-h-32 overflow-y-auto">
                                                    <template x-for="p in item.productoResults" :key="p.id">
                                                        <button @click="selectProducto(index, p)" class="w-full px-2 py-1 text-left text-xs hover:bg-slate-700 text-white border-b border-slate-700 last:border-0">
                                                            <span class="font-bold" x-text="p.descripcion"></span>
                                                            <span class="text-slate-400 ml-2" x-text="'Gs. ' + formatNum(p.costo)"></span>
                                                        </button>
                                                    </template>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-2"><input type="text" :value="formatNum(item.cantidad)" @input="item.cantidad = parseNum($event.target.value); calcTotals()" class="input-dark px-2 py-1 rounded w-full text-right text-sm"></td>
                                        <td class="p-2"><input type="text" :value="formatNum(item.costo)" @input="item.costo = parseNum($event.target.value); item.venta_ocr = Math.round(item.costo * 1.35); calcTotals()" class="input-dark px-2 py-1 rounded w-full text-right text-sm"></td>
                                        <td class="p-2"><input type="text" :value="formatNum(item.venta_ocr)" @input="item.venta_ocr = parseNum($event.target.value)" class="input-dark px-2 py-1 rounded w-full text-right text-sm text-green-400 font-bold"></td>
                                        <td class="p-2">
                                            <select x-model.number="item.tipo_iva" @change="calcTotals()" class="input-dark px-2 py-1 rounded w-full text-sm text-center">
                                                <option value="1">Exenta</option>
                                                <option value="2">5%</option>
                                                <option value="3">10%</option>
                                            </select>
                                        </td>
                                        <td class="p-2 text-right font-bold text-white" x-text="'Gs. ' + formatNum(item.cantidad * item.costo)"></td>
                                        <td class="p-2"><button @click="removeItem(index)" class="text-red-400 hover:text-red-300 text-lg">&times;</button></td>
                                    </tr>
                                </template>
                                <tr x-show="formItems.length === 0">
                                    <td colspan="9" class="p-4 text-center text-slate-500">No hay ítems. Haga clic en "Agregar Ítem"</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Totals -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-slate-700/30 p-3 rounded-lg">
                        <div class="text-xs text-slate-400 uppercase">Exenta</div>
                        <div class="text-lg font-bold text-white" x-text="'Gs. ' + formatNum(totals.exenta)"></div>
                    </div>
                    <div class="bg-slate-700/30 p-3 rounded-lg">
                        <div class="text-xs text-slate-400 uppercase">IVA 5%</div>
                        <div class="text-lg font-bold text-white" x-text="'Gs. ' + formatNum(totals.iva5)"></div>
                    </div>
                    <div class="bg-slate-700/30 p-3 rounded-lg">
                        <div class="text-xs text-slate-400 uppercase">IVA 10%</div>
                        <div class="text-lg font-bold text-white" x-text="'Gs. ' + formatNum(totals.iva10)"></div>
                    </div>
                    <div class="bg-blue-600/30 p-3 rounded-lg border border-blue-500/50">
                        <div class="text-xs text-blue-300 uppercase">Total Compra</div>
                        <div class="text-xl font-black text-white" x-text="'Gs. ' + formatNum(totals.total)"></div>
                    </div>
                </div>

                <!-- Nota -->
                <div>
                    <label class="block text-xs text-slate-400 uppercase font-bold mb-1">Nota</label>
                    <textarea x-model="formData.nota" rows="2" class="input-dark px-3 py-2 rounded-lg w-full" placeholder="Observaciones..."></textarea>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="p-4 border-t border-slate-700 flex justify-end gap-3">
                <button @click="showForm = false" class="px-5 py-2.5 bg-slate-600 hover:bg-slate-500 text-white rounded-lg font-bold">Cancelar</button>
                <button @click="saveCompra()" :disabled="saving" class="btn-primary px-6 py-2.5 text-white rounded-lg font-bold disabled:opacity-50">
                    <span x-show="!saving">Guardar</span>
                    <span x-show="saving">Guardando...</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="fixed bottom-4 right-4 z-50 space-y-2">
        <template x-for="t in toasts" :key="t.id">
            <div x-show="t.show" x-transition :class="{'bg-green-600': t.type==='success', 'bg-red-600': t.type==='error', 'bg-blue-600': t.type==='info'}" class="px-4 py-3 rounded-lg shadow-xl text-white min-w-64" x-text="t.message"></div>
        </template>
    </div>

    <!-- Payment Modal -->
    <div x-show="showPaymentModal" x-transition class="fixed inset-0 bg-black/80 flex items-center justify-center z-[60] p-4" @click.self="showPaymentModal = false">
        <div class="glass-card w-full max-w-md overflow-hidden flex flex-col" @click.stop>
            <div class="p-4 border-b border-white/10 flex justify-between items-center">
                <h2 class="text-xl font-bold">Registrar Pago</h2>
                <button @click="showPaymentModal = false" class="text-2xl opacity-50 hover:opacity-100">&times;</button>
            </div>
            <div class="p-5 space-y-4">
                <div class="bg-blue-600/20 p-3 rounded-lg border border-blue-500/30">
                    <p class="text-xs uppercase opacity-70 font-bold mb-1">Factura</p>
                    <p class="font-bold text-lg" x-text="paymentData.nro_factura"></p>
                    <div class="flex justify-between mt-2 text-sm">
                        <span>Pendiente:</span>
                        <span class="font-bold text-red-400" x-text="Number(paymentData.pendiente).toLocaleString('es-PY') + ' Gs'"></span>
                    </div>
                </div>

                <div>
                    <label class="block text-xs uppercase font-bold mb-1 opacity-70">Monto a Pagar</label>
                    <input type="number" x-model="paymentForm.monto" class="input-dark w-full px-4 py-3 rounded-xl text-xl font-bold text-green-400" autofocus>
                </div>

                <div>
                    <label class="block text-xs uppercase font-bold mb-1 opacity-70">Forma de Pago</label>
                    <select x-model="paymentForm.forma_pago" class="input-dark w-full px-4 py-3 rounded-xl">
                        <option value="Efectivo">Efectivo</option>
                        <option value="Transferencia">Transferencia</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Tarjeta">Tarjeta</option>
                        <option value="PIX">PIX</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs uppercase font-bold mb-1 opacity-70">Observación</label>
                    <textarea x-model="paymentForm.observacion" class="input-dark w-full px-4 py-2 rounded-xl h-20" placeholder="Nro de comprobante, banco..."></textarea>
                </div>
            </div>
            <div class="p-4 bg-black/20 flex gap-3">
                <button @click="showPaymentModal = false" class="flex-1 px-4 py-3 rounded-xl font-bold bg-white/10 hover:bg-white/20 transition-all">Cancelar</button>
                <button @click="submitPayment()" :disabled="paying" class="flex-1 btn-chip justify-center" :class="{'opacity-50 pointer-events-none': paying}">
                    <i x-show="!paying" class="fa-solid fa-check"></i>
                    <i x-show="paying" class="fa-solid fa-circle-notch animate-spin"></i>
                    <span x-text="paying ? 'Procesando...' : 'Confirmar Pago'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Permission Request Modal -->
    <div x-show="showPermissionModal" x-transition class="fixed inset-0 bg-black/80 flex items-center justify-center z-[75] p-4" @click.self="showPermissionModal = false">
        <div class="glass-card w-full max-w-md" @click.stop>
            <div class="p-6 text-center space-y-4">
                <i class="fa-solid fa-lock-open text-5xl text-blue-400 block"></i>
                <h2 class="text-2xl font-bold text-white">Permiso de Cámara</h2>
                <p class="text-slate-300 text-sm">
                    Para escanear facturas automáticamente necesitamos acceso a tu cámara.
                </p>
                <div class="bg-blue-900/30 border border-blue-500/50 rounded-lg p-3 text-left text-xs text-blue-200">
                    <p class="font-bold mb-2">¿Qué vamos a hacer?</p>
                    <ul class="space-y-1 text-xs">
                        <li>✓ Capturar foto de tu factura</li>
                        <li>✓ Extraer datos automáticamente (proveedor, items, total)</li>
                        <li>✓ Llenar el formulario por ti</li>
                    </ul>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-3 text-left text-xs text-slate-400">
                    <p class="font-bold text-slate-300 mb-2">Nota de privacidad:</p>
                    <p>Solo nosotros vemos tu factura. No se almacena sin tu consentimiento.</p>
                </div>
            </div>
            <div class="p-4 bg-black/20 flex gap-3">
                <button @click="showPermissionModal = false" class="flex-1 px-4 py-3 rounded-xl font-bold bg-white/10 hover:bg-white/20 transition-all text-white">
                    Cancelar
                </button>
                <button @click="requestCameraPermission()" class="flex-1 px-4 py-3 rounded-xl font-bold bg-blue-600 hover:bg-blue-700 transition-all text-white">
                    <i class="fa-solid fa-camera"></i> Permitir Acceso
                </button>
            </div>
        </div>
    </div>

    <!-- Camera Capture Modal -->
    <div x-show="showCameraModal" x-transition class="fixed inset-0 bg-black/80 flex items-center justify-center z-[70] p-4" @click.self="showCameraModal = false">
        <div class="glass-card w-full max-w-2xl" @click.stop>
            <div class="p-4 border-b border-slate-700 flex justify-between items-center">
                <h2 class="text-xl font-bold text-white">Capturar Factura</h2>
                <button @click="closeCameraModal()" class="text-slate-400 hover:text-white text-2xl">&times;</button>
            </div>
            <div class="p-4 space-y-4">
                <!-- Camera Section -->
                <div id="cameraContainer" class="relative bg-black rounded-lg overflow-hidden" style="display: none;">
                    <video id="cameraFeed" autoplay playsinline muted style="width: 100%; height: auto; max-height: 500px; transform: scaleX(-1);"></video>
                    <canvas id="photoCanvas" style="display: none;"></canvas>
                </div>

                <!-- Camera Status -->
                <div id="cameraStatus" class="text-center text-slate-400 py-8">
                    <p class="mb-2">Preparando cámara...</p>
                    <i class="fa-solid fa-camera text-3xl opacity-50"></i>
                </div>

                <!-- File Upload Alternative -->
                <div class="border-2 border-dashed border-slate-600 rounded-lg p-6 text-center cursor-pointer hover:border-slate-400 transition-colors" id="fileUploadArea">
                    <i class="fa-solid fa-image text-2xl text-slate-400 mb-2"></i>
                    <p class="text-slate-300 font-bold">O carga una imagen</p>
                    <p class="text-xs text-slate-500 mt-1">JPG, PNG, hasta 5MB</p>
                    <input type="file" id="fileUploadInput" accept="image/*" class="hidden">
                </div>

                <!-- Buttons -->
                <div class="flex gap-3">
                    <button @click="closeCameraModal()" class="flex-1 px-4 py-3 rounded-xl font-bold bg-white/10 hover:bg-white/20 transition-all">Cancelar</button>
                    <button id="retryBtn" @click="initCamera()" style="display: none;" class="flex-1 px-4 py-3 rounded-xl font-bold bg-yellow-600 hover:bg-yellow-700 text-white transition-all">
                        <i class="fa-solid fa-redo"></i> Reintentar
                    </button>
                    <button id="captureBtn" @click="capturePhoto()" style="display: none;" class="flex-1 px-4 py-3 rounded-xl font-bold bg-green-600 hover:bg-green-700 text-white transition-all">
                        <i class="fa-solid fa-camera"></i> Capturar
                    </button>
                    <button id="uploadCapturedBtn" @click="uploadCapturedPhoto()" style="display: none;" class="flex-1 px-4 py-3 rounded-xl font-bold bg-blue-600 hover:bg-blue-700 text-white transition-all">
                        <i class="fa-solid fa-upload"></i> Enviar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function comprasApp() {
            return {
                fechaDesde: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
                fechaHasta: new Date().toISOString().split('T')[0],
                filtroEstado: '1',
                buscar: '',
                gridApi: null,
                compras: [],

                // Form
                showForm: false,
                editingId: null,
                saving: false,
                selectedProveedor: null,
                proveedorSearch: '',
                proveedorResults: [],
                showProveedorDropdown: false,
                formData: {
                    nro_factura: '',
                    fecha: '',
                    timbrado: '',
                    vencimiento_timbrado: '',
                    vencimiento: '',
                    forma_pago: 1,
                    nota: '',
                    ruc_proveedor: ''
                },
                formItems: [],
                totals: {
                    exenta: 0,
                    iva5: 0,
                    iva10: 0,
                    piva5: 0,
                    piva10: 0,
                    total: 0
                },

                // Toast
                toasts: [],
                toastId: 0,

                // OCR
                scanning: false,
                filtering: false,
                
                // Camera Modal
                showCameraModal: false,
                showPermissionModal: false,
                cameraStream: null,
                capturedCanvas: null,
                cameraSupported: navigator.mediaDevices !== undefined,

                // Payment Modal State
                showPaymentModal: false,
                paying: false,
                paymentData: {},
                paymentForm: {
                    id_factura: 0,
                    monto: 0,
                    forma_pago: 'Efectivo',
                    observacion: ''
                },

                init() {
                    this.formData.fecha = new Date().toISOString().split('T')[0];
                    this.restoreDraft();
                    this.$nextTick(() => this.initGrid());

                    // Watch for changes to persist
                    this.$watch('formData', () => this.persist());
                    this.$watch('formItems', () => {
                        this.persist();
                        this.calcTotals();
                    }, {
                        deep: true
                    });
                    this.$watch('selectedProveedor', () => this.persist());
                    this.$watch('proveedorSearch', () => this.persist());
                },

                persist() {
                    if (this.editingId) return; // Don't persist when editing existing records
                    const draft = {
                        formData: this.formData,
                        formItems: this.formItems,
                        selectedProveedor: this.selectedProveedor,
                        proveedorSearch: this.proveedorSearch
                    };
                    localStorage.setItem('compra_draft', JSON.stringify(draft));
                },

                restoreDraft() {
                    const saved = localStorage.getItem('compra_draft');
                    if (saved) {
                        try {
                            const draft = JSON.parse(saved);
                            this.formData = {
                                ...this.formData,
                                ...draft.formData
                            };
                            this.formItems = draft.formItems || [];
                            this.selectedProveedor = draft.selectedProveedor;
                            this.proveedorSearch = draft.proveedorSearch || '';
                            this.calcTotals();
                        } catch (e) {
                            console.error('Error restoring draft', e);
                        }
                    }
                },

                clearDraft() {
                    localStorage.removeItem('compra_draft');
                },

                initGrid() {
                    const gridDiv = document.getElementById('gridCompras');
                    const self = this;

                    const columnDefs = [{
                            field: 'fecha',
                            headerName: 'Fecha',
                            width: 130,
                            cellRenderer: 'agGroupCellRenderer',
                            valueFormatter: p => p.value ? new Date(p.value).toLocaleDateString('es-PY') : ''
                        },
                        {
                            field: 'proveedor',
                            headerName: 'Proveedor',
                            flex: 1,
                            minWidth: 200
                        },
                        {
                            field: 'nro_factura',
                            headerName: 'Nro. Factura',
                            width: 140
                        },
                        {
                            field: 'total',
                            headerName: 'Total',
                            width: 120,
                            type: 'numericColumn',
                            valueFormatter: p => p.value ? Number(p.value).toLocaleString('es-PY') : '0'
                        },
                        {
                            field: 'pagado',
                            headerName: 'Pagado',
                            width: 110,
                            type: 'numericColumn',
                            valueFormatter: p => p.value ? Number(p.value).toLocaleString('es-PY') : '0'
                        },
                        {
                            field: 'pendiente',
                            headerName: 'Pendiente',
                            width: 110,
                            type: 'numericColumn',
                            cellStyle: p => ({
                                color: p.value > 0 ? '#ef4444' : '#22c55e',
                                fontWeight: 'bold'
                            }),
                            valueFormatter: p => p.value ? Number(p.value).toLocaleString('es-PY') : '0'
                        },
                        {
                            field: 'estado',
                            headerName: 'Estado',
                            width: 100,
                            cellRenderer: p => `<span class="${p.value == 1 ? 'text-green-400' : 'text-red-400'} font-bold">${p.value == 1 ? 'Activa' : 'Anulada'}</span>`
                        }
                    ];

                    const gridOptions = {
                        columnDefs,
                        rowData: [],
                        theme: "legacy",
                        masterDetail: true,
                        rowModelType: 'clientSide',
                        animateRows: true,
                        rowSelection: {
                            mode: 'singleRow'
                        },
                        rowGroupPanelShow: 'always',
                        onRowDoubleClicked: (e) => self.editCompra(e.data),

                        detailCellRendererParams: {
                            detailGridOptions: {
                                columnDefs: [{
                                        field: 'nro_recibo',
                                        headerName: '🧾 Nro Recibo',
                                        minWidth: 120
                                    },
                                    {
                                        field: 'fecha',
                                        headerName: 'Fecha',
                                        minWidth: 150,
                                        valueFormatter: p => p.value ? new Date(p.value).toLocaleDateString('es-PY') + ' ' + new Date(p.value).toLocaleTimeString('es-PY', {
                                            hour: '2-digit',
                                            minute: '2-digit'
                                        }) : ''
                                    },
                                    {
                                        field: 'monto',
                                        headerName: 'Monto Pagado',
                                        minWidth: 120,
                                        cellStyle: {
                                            textAlign: 'right',
                                            fontWeight: 'bold',
                                            color: '#16a34a'
                                        },
                                        valueFormatter: p => Number(p.value).toLocaleString('es-PY') + ' Gs'
                                    },
                                    {
                                        field: 'forma_pago',
                                        headerName: 'Forma Pago',
                                        minWidth: 120
                                    },
                                    {
                                        field: 'observacion',
                                        headerName: 'Observación',
                                        flex: 1,
                                        minWidth: 200
                                    }
                                ],
                                defaultColDef: {
                                    flex: 1,
                                    resizable: true,
                                    sortable: true
                                },
                                domLayout: 'autoHeight'
                            },
                            getDetailRowData: (params) => {
                                fetch(`compras_api.php?action=get_payments&id_factura=${params.data.id_factura}&id_empresa=${ID_EMPRESA}`)
                                    .then(res => res.json())
                                    .then(data => params.successCallback(data.payments || []))
                                    .catch(e => {
                                        console.error(e);
                                        params.successCallback([]);
                                    });
                            }
                        },
                        isRowMaster: (dataItem) => true,

                        defaultColDef: {
                            flex: 1,
                            minWidth: 100,
                            resizable: true,
                            sortable: true,
                            filter: true,
                            enableValue: true,
                            enableRowGroup: true,
                            enablePivot: true
                        },

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
                                        suppressPivotMode: false
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

                        localeText: {
                            page: 'Página',
                            more: 'Más',
                            to: 'a',
                            of: 'de',
                            next: 'Siguiente',
                            last: 'Último',
                            first: 'Primero',
                            previous: 'Anterior',
                            loadingOoo: 'Cargando...',
                            noRowsToShow: 'No hay registros',
                            pinColumn: 'Fijar Columna',
                            valueAggregation: 'Agregación de Valor',
                            autosizeThiscolumn: 'Autoajustar esta columna',
                            autosizeAllColumns: 'Autoajustar todas las columnas',
                            groupBy: 'Agrupar por',
                            ungroupBy: 'Desagrupar por',
                            resetColumns: 'Restablecer Columnas',
                            expandAll: 'Expandir Todo',
                            collapseAll: 'Contraer Todo',
                            toolPanel: 'Panel de Herramientas',
                            filterOoo: 'Filtrar...',
                            equals: 'Igual',
                            notEqual: 'No igual',
                            contains: 'Contiene',
                            notContains: 'No contiene',
                            startsWith: 'Empieza con',
                            endsWith: 'Termina con',
                            lessThan: 'Menor que',
                            lessThanOrEqual: 'Menor o igual que',
                            greaterThan: 'Mayor que',
                            greaterThanOrEqual: 'Mayor o igual que',
                            inRange: 'En rango',
                            copy: 'Copiar',
                            ctrlC: 'Ctrl+C',
                            paste: 'Pegar',
                            ctrlV: 'Ctrl+V',
                            export: 'Exportar'
                        },

                        getRowStyle: params => {
                            if (params.data && params.data.estado == 0) {
                                const isDark = document.documentElement.classList.contains('dark');
                                return {
                                    background: isDark ? '#1e293b' : '#f1f5f9',
                                    opacity: 0.7
                                };
                            }
                        },

                        getContextMenuItems: (params) => {
                            if (!params.node) return [];
                            const data = params.node.data;
                            return [{
                                    name: 'Editar',
                                    action: () => self.editCompra(data),
                                    icon: '<i class="fa-solid fa-edit text-blue-500"></i>'
                                },
                                {
                                    name: 'Anular',
                                    action: () => self.voidCompra(data),
                                    icon: '<i class="fa-solid fa-times text-red-500"></i>',
                                    disabled: data.estado != 1
                                },
                                {
                                    name: 'Registrar Pago',
                                    action: () => self.openPaymentModal(data),
                                    icon: '<i class="fa-solid fa-money-bill-wave text-green-500"></i>',
                                    disabled: data.estado != 1 || parseFloat(data.pendiente) <= 0
                                },
                                'separator',
                                'copy', 'export'
                            ];
                        }
                    };

                    this.gridApi = agGrid.createGrid(gridDiv, gridOptions);
                    this.loadData();
                },

                async loadData() {
                    try {
                        const url = `compras_api.php?action=list&id_empresa=${ID_EMPRESA}&fecha_desde=${this.fechaDesde}&fecha_hasta=${this.fechaHasta}&estado=${this.filtroEstado}&buscar=${encodeURIComponent(this.buscar)}`;
                        const res = await fetch(url);
                        const data = await res.json();
                        if (data.success) {
                            this.compras = data.compras || [];
                            this.gridApi.setGridOption('rowData', this.compras);
                        }
                    } catch (e) {
                        console.error(e);
                    }
                },

                openForm() {
                    if (this.editingId) {
                        this.resetForm();
                    }
                    this.showForm = true;
                    this.$nextTick(() => this.$refs.proveedorInput?.focus());
                },

                resetForm() {
                    this.editingId = null;
                    this.selectedProveedor = null;
                    this.proveedorSearch = '';
                    this.formData = {
                        nro_factura: '',
                        fecha: new Date().toISOString().split('T')[0],
                        timbrado: '',
                        vencimiento_timbrado: '',
                        vencimiento: '',
                        forma_pago: 1,
                        nota: '',
                        ruc_proveedor: ''
                    };
                    this.formItems = [];
                    this.totals = {
                        exenta: 0,
                        iva5: 0,
                        iva10: 0,
                        piva5: 0,
                        piva10: 0,
                        total: 0,
                        costoOcr: 0,
                        ventaOcr: 0
                    };
                    this.showPaymentModal = false;
                    this.clearDraft();
                },

                async editCompra(row) {
                    try {
                        const res = await fetch(`compras_api.php?action=get&id_factura=${row.id_factura}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();
                        if (data.success) {
                            this.editingId = row.id_factura;
                            this.selectedProveedor = {
                                id: data.compra.id_cliente,
                                nombre: data.compra.proveedor,
                                ruc: data.compra.ruc
                            };
                            this.proveedorSearch = data.compra.proveedor;
                            this.formData = {
                                nro_factura: data.compra.nro_factura || '',
                                fecha: data.compra.fecha?.split(' ')[0] || '',
                                timbrado: data.compra.timbrado || '',
                                vencimiento_timbrado: data.compra.vencimiento_timbrado || '',
                                vencimiento: data.compra.vencimiento || '',
                                forma_pago: data.compra.forma_pago || 1,
                                nota: data.compra.nota || ''
                            };
                            this.formItems = (data.items || []).map(it => ({
                                idproducto: it.idproducto,
                                codigo: it.codigo,
                                descripcion: it.descripcion,
                                cantidad: parseFloat(it.cantidad) || 1,
                                costo: parseFloat(it.costo_gs) || 0,
                                tipo_iva: parseInt(it.tipo_iva) || 3,
                                showDropdown: false,
                                productoResults: []
                            }));
                            this.calcTotals();
                            this.showForm = true;
                        }
                    } catch (e) {
                        console.error(e);
                        this.toast('Error al cargar compra', 'error');
                    }
                },

                openPaymentModal(row) {
                    if (row.estado != 1) {
                        this.toast('No se puede pagar una factura anulada', 'error');
                        return;
                    }
                    if (parseFloat(row.pendiente) <= 0) {
                        this.toast('Esta factura ya está totalmente pagada', 'info');
                        return;
                    }
                    this.paymentData = row;
                    this.paymentForm = {
                        id_factura: row.id_factura,
                        monto: parseFloat(row.pendiente),
                        forma_pago: 'Efectivo',
                        observacion: ''
                    };
                    this.showPaymentModal = true;
                },

                async submitPayment() {
                    if (this.paymentForm.monto <= 0) {
                        this.toast('El monto debe ser mayor a 0', 'error');
                        return;
                    }
                    this.paying = true;
                    try {
                        const res = await fetch(`compras_api.php?action=save_payment&id_empresa=${ID_EMPRESA}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(this.paymentForm)
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.toast(`Pago registrado: ${data.nro_recibo}`, 'success');
                            this.showPaymentModal = false;
                            this.loadData();
                        } else {
                            this.toast(data.message || 'Error al procesar pago', 'error');
                        }
                    } catch (e) {
                        console.error(e);
                        this.toast('Error de conexión', 'error');
                    } finally {
                        this.paying = false;
                    }
                },

                async voidCompra(row) {
                    if (!confirm(`¿Anular la factura #${row.nro_factura}?`)) return;
                    try {
                        const res = await fetch(`compras_api.php?action=void&id_empresa=${ID_EMPRESA}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                id_factura: row.id_factura
                            })
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.toast('Compra anulada', 'success');
                            this.loadData();
                        } else {
                            this.toast(data.message || 'Error', 'error');
                        }
                    } catch (e) {
                        this.toast('Error de conexión', 'error');
                    }
                },

                async searchProveedores() {
                    if (this.proveedorSearch.length < 2) {
                        this.proveedorResults = [];
                        return;
                    }
                    try {
                        const res = await fetch(`compras_api.php?action=search_proveedores&q=${encodeURIComponent(this.proveedorSearch)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();
                        if (data.success) {
                            this.proveedorResults = data.proveedores || [];
                            this.showProveedorDropdown = true;
                        }
                    } catch (e) {
                        console.error(e);
                    }
                },

                selectProveedor(p) {
                    this.selectedProveedor = p;
                    this.proveedorSearch = p.nombre;
                    this.showProveedorDropdown = false;
                },

                addItem() {
                    this.formItems.push({
                        idproducto: 0,
                        codigo: '',
                        descripcion: '',
                        cantidad: 1,
                        costo: 0,
                        tipo_iva: 3,
                        costo_ocr: 0,
                        venta_ocr: 0,
                        showDropdown: false,
                        productoResults: []
                    });
                },

                removeItem(index) {
                    this.formItems.splice(index, 1);
                    this.calcTotals();
                },

                async searchProductos(index) {
                    const item = this.formItems[index];
                    if (item.descripcion.length < 2) {
                        item.productoResults = [];
                        return;
                    }
                    try {
                        const res = await fetch(`compras_api.php?action=search_productos&q=${encodeURIComponent(item.descripcion)}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();
                        if (data.success) {
                            item.productoResults = data.productos || [];
                            item.showDropdown = true;
                        }
                    } catch (e) {
                        console.error(e);
                    }
                },

                selectProducto(index, p) {
                    const item = this.formItems[index];
                    item.idproducto = p.id;
                    item.codigo = p.codigo;
                    item.descripcion = p.descripcion;
                    item.costo = parseFloat(p.costo) || 0;
                    item.tipo_iva = parseInt(p.tipo_iva) || 3;
                    item.showDropdown = false;
                    this.calcTotals();
                },

                calcTotals() {
                    let exenta = 0,
                        iva5 = 0,
                        iva10 = 0,
                        costoOcr = 0,
                        ventaOcr = 0;
                    this.formItems.forEach(it => {
                        const sub = (it.cantidad || 0) * (it.costo || 0);
                        if (it.tipo_iva == 1) exenta += sub;
                        else if (it.tipo_iva == 2) iva5 += sub;
                        else iva10 += sub;
                        // OCR totals
                        costoOcr += (it.cantidad || 0) * (it.costo_ocr || it.costo || 0);
                        ventaOcr += (it.cantidad || 0) * (it.venta_ocr || Math.round((it.costo_ocr || it.costo || 0) * 1.3));
                    });
                    this.totals = {
                        exenta,
                        iva5,
                        iva10,
                        piva5: Math.round(iva5 / 21),
                        piva10: Math.round(iva10 / 11),
                        total: exenta + iva5 + iva10,
                        costoOcr,
                        ventaOcr
                    };
                },

                async saveCompra() {
                    if (!this.selectedProveedor && !this.proveedorSearch) {
                        this.toast('Seleccione o ingrese un proveedor', 'error');
                        return;
                    }
                    if (!this.formData.nro_factura) {
                        this.toast('Ingrese número de factura', 'error');
                        return;
                    }
                    if (this.formItems.length === 0) {
                        this.toast('Agregue al menos un ítem', 'error');
                        return;
                    }

                    this.saving = true;
                    try {
                        const payload = {
                            id_factura: this.editingId || 0,
                            id_proveedor: this.selectedProveedor?.id || 0,
                            proveedor_nombre: this.proveedorSearch,
                            ruc: this.formData.ruc_proveedor || '', // Assuming we have ruc_proveedor in formData
                            ...this.formData,
                            items: this.formItems.map(it => ({
                                idproducto: it.idproducto,
                                codigo: it.codigo,
                                descripcion: it.descripcion,
                                cantidad: it.cantidad,
                                costo: it.costo,
                                tipo_iva: it.tipo_iva,
                                venta_ocr: it.venta_ocr
                            }))
                        };
                        const res = await fetch(`compras_api.php?action=save&id_empresa=${ID_EMPRESA}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(payload)
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.toast(data.message || 'Compra guardada', 'success');
                            this.clearDraft();
                            this.showForm = false;
                            this.editingId = null;
                            this.resetForm();
                            this.loadData();
                        } else {
                            this.toast(data.message || 'Error al guardar', 'error');
                        }
                    } catch (e) {
                        console.error(e);
                        this.toast('Error de conexión', 'error');
                    } finally {
                        this.saving = false;
                    }
                },

                formatNum(n) {
                    return Number(n || 0).toLocaleString('es-PY');
                },
                parseNum(s) {
                    return parseFloat(String(s).replace(/\./g, '').replace(',', '.')) || 0;
                },

                toast(message, type = 'info') {
                    const id = ++this.toastId;
                    this.toasts.push({
                        id,
                        message,
                        type,
                        show: true
                    });
                    setTimeout(() => {
                        this.toasts = this.toasts.filter(t => t.id !== id);
                    }, 3000);
                },

                async scanInvoice(event) {
                    const file = event.target.files?.[0];
                    if (!file) return;

                    this.scanning = true;
                    this.toast('Analizando factura con IA...', 'info');

                    try {
                        const formData = new FormData();
                        formData.append('imagen', file);

                        const res = await fetch('ocr_factura_api.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();

                        if (data.success && data.data) {
                            const d = data.data;

                            // Auto-fill form fields
                            if (d.proveedor) {
                                this.proveedorSearch = d.proveedor;
                                // Try to find matching proveedor
                                await this.searchProveedores();
                                if (this.proveedorResults.length > 0) {
                                    // Select best match
                                    const match = this.proveedorResults.find(p =>
                                        p.nombre.toLowerCase().includes(d.proveedor.toLowerCase()) ||
                                        p.ruc === d.ruc
                                    ) || this.proveedorResults[0];
                                    this.selectProveedor(match);
                                }
                            }

                            this.formData.nro_factura = d.nro_factura || '';
                            this.formData.timbrado = d.timbrado || '';
                            this.formData.ruc_proveedor = d.ruc || '';
                            if (d.fecha) this.formData.fecha = d.fecha;
                            if (d.vencimiento_timbrado) this.formData.vencimiento_timbrado = d.vencimiento_timbrado;

                            // Load items - APPEND to existing items (allows multiple scans)
                            if (d.items && d.items.length > 0) {
                                const newItems = d.items.map(it => {
                                    const costo = parseFloat(it.costo) || 0;
                                    return {
                                        idproducto: 0,
                                        codigo: it.codigo || '',
                                        descripcion: it.descripcion || '',
                                        cantidad: parseFloat(it.cantidad) || 1,
                                        costo: costo,
                                        tipo_iva: parseInt(it.tipo_iva) || 3,
                                        costo_ocr: costo,
                                        venta_ocr: Math.round(costo * 1.3),
                                        showDropdown: false,
                                        productoResults: []
                                    };
                                });
                                // Append new items to existing
                                this.formItems = [...this.formItems, ...newItems];
                                this.calcTotals();
                            }

                            this.toast('Datos extraídos correctamente', 'success');
                        } else {
                            this.toast(data.message || 'Error al procesar imagen', 'error');
                        }
                    } catch (e) {
                        console.error(e);
                        this.toast('Error de conexión con OCR', 'error');
                    } finally {
                        this.scanning = false;
                        event.target.value = ''; // Reset file input
                    }
                },

                // Camera Functions
                async initCamera() {
                    const statusDiv = document.getElementById('cameraStatus');
                    
                    try {
                        // Verificar soporte de navegador
                        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                            throw new Error('Tu navegador no soporta acceso a cámara. Usa Chrome, Firefox, Edge o Safari 14.5+');
                        }

                        // Mostrar status de intento
                        statusDiv.innerHTML = `
                            <p class="mb-2">Solicitando acceso a cámara...</p>
                            <svg class="animate-spin h-8 w-8 opacity-50 mx-auto" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        `;

                        // Intentar con configuración óptima primero
                        let stream = null;
                        let lastError = null;

                        // Intento 1: Configuración óptima (cámara trasera con resolución HD)
                        try {
                            const constraints1 = {
                                video: {
                                    facingMode: { exact: 'environment' },
                                    width: { ideal: 1280 },
                                    height: { ideal: 720 }
                                },
                                audio: false
                            };
                            stream = await navigator.mediaDevices.getUserMedia(constraints1);
                        } catch (e) {
                            lastError = e;
                            console.log('Intento 1 falló, intentando sin facingMode exacto...');

                            // Intento 2: Sin exact facingMode
                            try {
                                const constraints2 = {
                                    video: {
                                        facingMode: 'environment',
                                        width: { ideal: 1280 },
                                        height: { ideal: 720 }
                                    },
                                    audio: false
                                };
                                stream = await navigator.mediaDevices.getUserMedia(constraints2);
                            } catch (e2) {
                                lastError = e2;
                                console.log('Intento 2 falló, intentando con resolución menor...');

                                // Intento 3: Resolución menor
                                try {
                                    const constraints3 = {
                                        video: {
                                            width: { ideal: 640 },
                                            height: { ideal: 480 }
                                        },
                                        audio: false
                                    };
                                    stream = await navigator.mediaDevices.getUserMedia(constraints3);
                                } catch (e3) {
                                    lastError = e3;
                                    console.log('Intento 3 falló, intentando sin restricciones...');

                                    // Intento 4: Sin restricciones (fallback)
                                    const constraints4 = { video: true, audio: false };
                                    stream = await navigator.mediaDevices.getUserMedia(constraints4);
                                }
                            }
                        }

                        if (!stream) {
                            throw new Error('No se pudo obtener acceso a la cámara después de múltiples intentos');
                        }

                        this.cameraStream = stream;
                        const videoElement = document.getElementById('cameraFeed');
                        
                        if (!videoElement) {
                            throw new Error('Elemento de video no encontrado en el DOM');
                        }

                        videoElement.srcObject = stream;
                        
                        // Asegurar que el video se reproduce
                        return new Promise((resolve, reject) => {
                            videoElement.onloadedmetadata = () => {
                                videoElement.play()
                                    .then(() => {
                                        console.log('Video reproduciéndose correctamente');
                                        resolve();
                                    })
                                    .catch(err => {
                                        console.error('Error reproduciendo video:', err);
                                        reject(new Error('No se pudo reproducir el video'));
                                    });
                            };

                            videoElement.onerror = () => {
                                reject(new Error('Error en el elemento de video'));
                            };

                            // Timeout de 5 segundos
                            setTimeout(() => {
                                reject(new Error('Timeout esperando video'));
                            }, 5000);
                        }).then(() => {
                            document.getElementById('cameraContainer').style.display = 'block';
                            document.getElementById('cameraStatus').style.display = 'none';
                            document.getElementById('captureBtn').style.display = 'block';
                            this.toast('✅ Cámara activada correctamente', 'success');
                        });

                    } catch (error) {
                        console.error('Error completo accediendo a la cámara:', error);
                        
                        // Determinar el tipo de error
                        let errorMessage = '';
                        let errorDetails = '';
                        let errorAdvice = '';

                        if (error.name === 'NotAllowedError' || error.message.includes('permission')) {
                            errorMessage = '❌ Permiso Denegado';
                            errorDetails = 'No autorizaste acceso a la cámara';
                            errorAdvice = `
                                <div class="mt-3 text-xs text-slate-300 bg-slate-800/50 p-2 rounded">
                                    <b>Cómo permitir:</b>
                                    <br>1. Ve a <b>Ajustes</b> del dispositivo
                                    <br>2. Aplicaciones → ${this.getNavigatorName() || 'Navegador'}
                                    <br>3. Permisos → Cámara → Permitir
                                    <br>4. Vuelve a la app y haz clic en "Reintentar"
                                </div>
                            `;
                        } else if (error.name === 'NotFoundError' || error.message.includes('camera')) {
                            errorMessage = '❌ Cámara No Disponible';
                            errorDetails = 'Tu dispositivo no tiene cámara o está ocupada';
                            errorAdvice = '<b>Alternativa:</b> Carga una foto desde tu galería';
                        } else if (error.message.includes('navegador')) {
                            errorMessage = '❌ Navegador No Soportado';
                            errorDetails = error.message;
                            errorAdvice = '<b>Intenta con:</b> Chrome, Firefox, Edge o Safari 14.5+';
                        } else {
                            errorMessage = '❌ Error de Cámara';
                            errorDetails = error.message || 'Error desconocido';
                            errorAdvice = '<b>Intenta:</b> Recargar la página o usar otro navegador';
                        }

                        document.getElementById('cameraStatus').innerHTML = `
                            <i class="fa-solid fa-camera-slash text-4xl text-red-500 mb-3"></i>
                            <p class="text-red-400 font-bold text-sm">${errorMessage}</p>
                            <p class="text-slate-400 text-xs mt-1">${errorDetails}</p>
                            ${errorAdvice}
                            <div class="mt-4">
                                <p class="text-xs text-slate-500">📋 Usando alternativa: Carga una imagen</p>
                            </div>
                        `;

                        // Mostrar botón de reintentar
                        document.getElementById('retryBtn').style.display = 'block';
                        document.getElementById('captureBtn').style.display = 'none';
                        document.getElementById('uploadCapturedBtn').style.display = 'none';
                        document.getElementById('cameraStatus').style.display = 'block';
                        document.getElementById('cameraContainer').style.display = 'none';

                        this.toast(`${errorMessage}: ${errorDetails}`, 'error');
                    }
                },

                // Abrir cámara con verificación de permisos
                openCameraWithPermission() {
                    if (!this.cameraSupported) {
                        this.toast('Tu navegador no soporta acceso a cámara', 'error');
                        return;
                    }
                    
                    // Mostrar modal de permisos primero
                    this.showPermissionModal = true;
                },

                // Solicitar permisos de cámara
                async requestCameraPermission() {
                    try {
                        // Intentar acceder a la cámara para solicitar permisos
                        const constraints = {
                            video: { facingMode: 'environment' },
                            audio: false
                        };
                        
                        const stream = await navigator.mediaDevices.getUserMedia(constraints);
                        
                        // Si obtuvimos el stream, cerrar el modal de permisos y abrir el de cámara
                        stream.getTracks().forEach(track => track.stop());
                        
                        this.showPermissionModal = false;
                        this.showCameraModal = true;
                        
                        this.$nextTick(() => {
                            this.initCamera();
                        });
                        
                        this.toast('✅ Permisos otorgados. Iniciando cámara...', 'success');
                        
                    } catch (error) {
                        console.error('Error solicitando permisos:', error);
                        
                        if (error.name === 'NotAllowedError') {
                            this.toast('❌ Permiso denegado. Permite acceso en Ajustes → Permisos', 'error');
                        } else if (error.name === 'NotFoundError') {
                            this.toast('❌ No se encontró cámara en tu dispositivo', 'error');
                        } else {
                            this.toast(`❌ Error: ${error.message}`, 'error');
                        }
                        this.showPermissionModal = false;
                    }
                },

                getNavigatorName() {
                    const ua = navigator.userAgent;
                    if (ua.includes('Chrome')) return 'Chrome';
                    if (ua.includes('Firefox')) return 'Firefox';
                    if (ua.includes('Safari')) return 'Safari';
                    if (ua.includes('Edge')) return 'Edge';
                    return 'Navegador';
                },

                capturePhoto() {
                    try {
                        const video = document.getElementById('cameraFeed');
                        const canvas = document.getElementById('photoCanvas');

                        if (!video || !canvas) {
                            this.toast('Error: Elementos del DOM no encontrados', 'error');
                            return;
                        }

                        if (!video.videoWidth || !video.videoHeight) {
                            this.toast('Error: Video aún no está listo. Intenta de nuevo', 'error');
                            return;
                        }

                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;

                        const ctx = canvas.getContext('2d');
                        if (!ctx) {
                            this.toast('Error: No se puede acceder al contexto del canvas', 'error');
                            return;
                        }

                        // Dibujar la imagen del video en el canvas
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                        // Verificar que la imagen se haya dibujado correctamente
                        const imageData = ctx.getImageData(0, 0, 1, 1);
                        if (!imageData.data || imageData.data[0] === 0 && imageData.data[1] === 0 && imageData.data[2] === 0) {
                            console.warn('Posible imagen negra capturada');
                        }

                        this.capturedCanvas = canvas;

                        // Hide camera, show upload button
                        document.getElementById('cameraContainer').style.display = 'none';
                        document.getElementById('captureBtn').style.display = 'none';
                        document.getElementById('uploadCapturedBtn').style.display = 'block';

                        // Show preview
                        const previewContainer = document.getElementById('cameraContainer');
                        previewContainer.innerHTML = '';
                        
                        const previewImg = document.createElement('img');
                        previewImg.src = canvas.toDataURL('image/jpeg', 0.95);
                        previewImg.style.width = '100%';
                        previewImg.style.height = 'auto';
                        previewImg.style.maxHeight = '500px';
                        previewImg.style.objectFit = 'contain';
                        
                        previewContainer.appendChild(previewImg);
                        previewContainer.style.display = 'block';

                        this.toast('✅ Foto capturada correctamente', 'success');
                    } catch (error) {
                        console.error('Error capturando foto:', error);
                        this.toast(`Error al capturar: ${error.message}`, 'error');
                    }
                },

                async uploadCapturedPhoto() {
                    if (!this.capturedCanvas) return;

                    this.scanning = true;
                    this.toast('Procesando imagen...', 'info');

                    try {
                        this.capturedCanvas.toBlob(async (blob) => {
                            const formData = new FormData();
                            formData.append('imagen', blob, 'capture.jpg');

                            const res = await fetch('ocr_factura_api.php', {
                                method: 'POST',
                                body: formData
                            });
                            const data = await res.json();

                            if (data.success && data.data) {
                                const d = data.data;

                                // Auto-fill form fields
                                if (d.proveedor) {
                                    this.proveedorSearch = d.proveedor;
                                    await this.searchProveedores();
                                    if (this.proveedorResults.length > 0) {
                                        const match = this.proveedorResults.find(p =>
                                            p.nombre.toLowerCase().includes(d.proveedor.toLowerCase()) ||
                                            p.ruc === d.ruc
                                        ) || this.proveedorResults[0];
                                        this.selectProveedor(match);
                                    }
                                }

                                this.formData.nro_factura = d.nro_factura || '';
                                this.formData.timbrado = d.timbrado || '';
                                this.formData.ruc_proveedor = d.ruc || '';
                                if (d.fecha) this.formData.fecha = d.fecha;
                                if (d.vencimiento_timbrado) this.formData.vencimiento_timbrado = d.vencimiento_timbrado;

                                if (d.items && d.items.length > 0) {
                                    const newItems = d.items.map(it => {
                                        const costo = parseFloat(it.costo) || 0;
                                        return {
                                            idproducto: 0,
                                            codigo: it.codigo || '',
                                            descripcion: it.descripcion || '',
                                            cantidad: parseFloat(it.cantidad) || 1,
                                            costo: costo,
                                            tipo_iva: parseInt(it.tipo_iva) || 3,
                                            costo_ocr: costo,
                                            venta_ocr: Math.round(costo * 1.3),
                                            showDropdown: false,
                                            productoResults: []
                                        };
                                    });
                                    this.formItems = [...this.formItems, ...newItems];
                                    this.calcTotals();
                                }

                                this.toast('✓ Datos extraídos correctamente', 'success');
                                this.closeCameraModal();
                            } else {
                                this.toast(data.message || 'Error al procesar imagen', 'error');
                            }
                        }, 'image/jpeg', 0.95);
                    } catch (e) {
                        console.error(e);
                        this.toast('Error: ' + e.message, 'error');
                    } finally {
                        this.scanning = false;
                    }
                },

                async handleFileUpload(e) {
                    const file = e.target.files?.[0];
                    if (!file) return;

                    this.scanning = true;
                    this.toast('Procesando imagen...', 'info');

                    try {
                        const formData = new FormData();
                        formData.append('imagen', file);

                        const res = await fetch('ocr_factura_api.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();

                        if (data.success && data.data) {
                            const d = data.data;

                            if (d.proveedor) {
                                this.proveedorSearch = d.proveedor;
                                await this.searchProveedores();
                                if (this.proveedorResults.length > 0) {
                                    const match = this.proveedorResults.find(p =>
                                        p.nombre.toLowerCase().includes(d.proveedor.toLowerCase()) ||
                                        p.ruc === d.ruc
                                    ) || this.proveedorResults[0];
                                    this.selectProveedor(match);
                                }
                            }

                            this.formData.nro_factura = d.nro_factura || '';
                            this.formData.timbrado = d.timbrado || '';
                            this.formData.ruc_proveedor = d.ruc || '';
                            if (d.fecha) this.formData.fecha = d.fecha;
                            if (d.vencimiento_timbrado) this.formData.vencimiento_timbrado = d.vencimiento_timbrado;

                            if (d.items && d.items.length > 0) {
                                const newItems = d.items.map(it => {
                                    const costo = parseFloat(it.costo) || 0;
                                    return {
                                        idproducto: 0,
                                        codigo: it.codigo || '',
                                        descripcion: it.descripcion || '',
                                        cantidad: parseFloat(it.cantidad) || 1,
                                        costo: costo,
                                        tipo_iva: parseInt(it.tipo_iva) || 3,
                                        costo_ocr: costo,
                                        venta_ocr: Math.round(costo * 1.3),
                                        showDropdown: false,
                                        productoResults: []
                                    };
                                });
                                this.formItems = [...this.formItems, ...newItems];
                                this.calcTotals();
                            }

                            this.toast('✓ Datos extraídos correctamente', 'success');
                            this.closeCameraModal();
                        } else {
                            this.toast(data.message || 'Error al procesar imagen', 'error');
                        }
                    } catch (e) {
                        console.error(e);
                        this.toast('Error: ' + e.message, 'error');
                    } finally {
                        this.scanning = false;
                    }
                },

                closeCameraModal() {
                    try {
                        // Stop camera stream completamente
                        if (this.cameraStream) {
                            const tracks = this.cameraStream.getTracks();
                            console.log(`Deteniendo ${tracks.length} tracks de cámara`);
                            tracks.forEach(track => {
                                track.stop();
                                console.log(`Track detenido: ${track.kind}`);
                            });
                            this.cameraStream = null;
                        }

                        // Detener video
                        const videoElement = document.getElementById('cameraFeed');
                        if (videoElement) {
                            videoElement.pause();
                            videoElement.srcObject = null;
                        }

                        // Reset UI
                        const cameraContainer = document.getElementById('cameraContainer');
                        if (cameraContainer) {
                            cameraContainer.style.display = 'none';
                            cameraContainer.innerHTML = '';
                        }

                        const cameraStatus = document.getElementById('cameraStatus');
                        if (cameraStatus) {
                            cameraStatus.style.display = 'block';
                            cameraStatus.innerHTML = '<p class="mb-2">Preparando cámara...</p><i class="fa-solid fa-camera text-3xl opacity-50"></i>';
                        }

                        const captureBtn = document.getElementById('captureBtn');
                        if (captureBtn) captureBtn.style.display = 'none';

                        const retryBtn = document.getElementById('retryBtn');
                        if (retryBtn) retryBtn.style.display = 'none';

                        const uploadBtn = document.getElementById('uploadCapturedBtn');
                        if (uploadBtn) uploadBtn.style.display = 'none';

                        const fileInput = document.getElementById('fileUploadInput');
                        if (fileInput) fileInput.value = '';

                        this.capturedCanvas = null;
                        this.showCameraModal = false;
                        this.showPermissionModal = false;

                        console.log('Modal de cámara cerrado correctamente');
                    } catch (error) {
                        console.error('Error cerrando modal de cámara:', error);
                    }
                }
            };
        }

        function exitSystem() {
            console.log('Saliendo del sistema...');
            try {
                if (window.top && window.top !== window) {
                    window.top.location.href = 'menu/menu.php';
                    return;
                }
                window.location.href = 'menu/menu.php';
            } catch (e) {
                console.error(e);
                window.location.href = 'index.php';
            }
        }
    </script>

    <!-- Camera Modal Event Listeners -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // File upload area click
            const fileUploadArea = document.getElementById('fileUploadArea');
            const fileUploadInput = document.getElementById('fileUploadInput');

            if (fileUploadArea && fileUploadInput) {
                fileUploadArea.addEventListener('click', () => fileUploadInput.click());
                fileUploadInput.addEventListener('change', (e) => {
                    // Get Alpine app instance
                    const el = document.querySelector('[x-data="comprasApp()"]');
                    if (el && el.__x) {
                        el.__x.scope.handleFileUpload(e);
                    }
                });
            }
        });
    </script>
</body>

</html>