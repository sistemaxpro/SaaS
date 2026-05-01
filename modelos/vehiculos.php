<?php

/**
 * Gestión de Vehículos
 * Módulo para administrar vehículos usados en notas de remisión
 */
require_once '_lib/config.php';

// Obtener id_empresa de la sesión o parámetro
$id_empresa = isset($_GET['id_empresa']) ? intval($_GET['id_empresa']) : (isset($_SESSION['id_empresa']) ? intval($_SESSION['id_empresa']) : 0);
if (!$id_empresa) {
    die('Error: id_empresa no especificado');
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Vehículos - SIFEN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/ag-grid-enterprise/dist/ag-grid-enterprise.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        [x-cloak] {
            display: none !important;
        }

        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            --ag-font-family: system-ui, -apple-system, sans-serif;
        }
    </style>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {}
            }
        }
        // Detectar tema del sistema
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    </script>
</head>

<body class="h-full bg-gray-100 dark:bg-slate-900 text-gray-900 dark:text-gray-100" x-data="vehiculosApp()">
    <div class="h-full flex flex-col">
        <!-- Header -->
        <div class="bg-white dark:bg-slate-800 shadow-sm border-b dark:border-slate-700">
            <div class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="nr_sifen.php?id_empresa=<?php echo $id_empresa; ?>"
                        class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 transition">
                        <i class="fa-solid fa-arrow-left"></i>
                    </a>
                    <h1 class="text-xl font-bold flex items-center gap-2">
                        <i class="fa-solid fa-truck text-blue-600 dark:text-blue-400"></i>
                        Gestión de Vehículos
                    </h1>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Filtro activos/todos -->
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" x-model="mostrarInactivos" @change="loadGridData()"
                            class="rounded text-blue-600 focus:ring-blue-500">
                        Mostrar inactivos
                    </label>

                    <!-- Botón Nuevo Vehículo -->
                    <button @click="abrirModal()"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg flex items-center gap-2 transition">
                        <i class="fa-solid fa-plus"></i>
                        Nuevo Vehículo
                    </button>
                </div>
            </div>
        </div>

        <!-- AG Grid -->
        <div class="flex-1 p-4">
            <div id="vehiculosGrid"
                :class="document.documentElement.classList.contains('dark') ? 'ag-theme-quartz-dark' : 'ag-theme-quartz'"
                class="w-full h-full rounded-lg shadow-sm">
            </div>
        </div>
    </div>

    <!-- Modal Nuevo/Editar Vehículo -->
    <div x-show="showModal" x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
        <div @click.away="showModal = false"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden flex flex-col">

            <!-- Header Modal -->
            <div class="px-6 py-4 border-b dark:border-slate-700 flex items-center justify-between bg-gradient-to-r from-blue-600 to-blue-700">
                <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                    <i class="fa-solid fa-truck"></i>
                    <span x-text="vehiculo.id ? 'Editar Vehículo' : 'Nuevo Vehículo'"></span>
                </h2>
                <button @click="showModal = false" class="text-white/80 hover:text-white">
                    <i class="fa-solid fa-times text-xl"></i>
                </button>
            </div>

            <!-- Form -->
            <form @submit.prevent="guardarVehiculo()" class="flex-1 overflow-y-auto p-6 space-y-6">
                <!-- Datos principales -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Tipo <span class="text-red-500">*</span>
                        </label>
                        <select x-model="vehiculo.tipo" required
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
                        <input type="text" x-model="vehiculo.marca" required placeholder="Toyota, Ford, Mercedes..."
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Modelo
                        </label>
                        <input type="text" x-model="vehiculo.modelo" placeholder="Hilux, Ranger..."
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Chapa <span class="text-red-500">*</span>
                        </label>
                        <input type="text" x-model="vehiculo.chapa" required placeholder="ABC 123"
                            @input="vehiculo.chapa = vehiculo.chapa.toUpperCase()"
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500 uppercase">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Color</label>
                        <input type="text" x-model="vehiculo.color" placeholder="Blanco, Negro..."
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Año</label>
                        <input type="number" x-model="vehiculo.anho" min="1900" max="2100" placeholder="2024"
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Capacidad (kg)</label>
                        <input type="number" x-model="vehiculo.capacidad_kg" min="0" step="0.01" placeholder="5000"
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <!-- Conductor por defecto -->
                <h3 class="text-md font-semibold text-gray-700 dark:text-gray-200 flex items-center gap-2 pt-2 border-t dark:border-slate-700">
                    <i class="fa-solid fa-id-card text-blue-500"></i> Conductor por Defecto (Opcional)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Documento CI</label>
                        <input type="text" x-model="vehiculo.conductor_documento" placeholder="1234567"
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nombre Conductor</label>
                        <input type="text" x-model="vehiculo.conductor_nombre" placeholder="Juan Pérez"
                            class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <!-- Observaciones -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Observaciones</label>
                    <textarea x-model="vehiculo.observaciones" rows="2" placeholder="Notas adicionales sobre el vehículo..."
                        class="w-full px-3 py-2 border rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:ring-2 focus:ring-blue-500"></textarea>
                </div>

                <!-- Estado (solo en edición) -->
                <div x-show="vehiculo.id" class="flex items-center gap-2">
                    <input type="checkbox" x-model="vehiculo.activo" :true-value="1" :false-value="0" id="chkActivo"
                        class="rounded text-blue-600 focus:ring-blue-500">
                    <label for="chkActivo" class="text-sm font-medium text-gray-700 dark:text-gray-300">Vehículo activo</label>
                </div>

                <!-- Botones -->
                <div class="flex justify-end gap-3 pt-4 border-t dark:border-slate-700">
                    <button type="button" @click="showModal = false"
                        class="px-4 py-2 bg-gray-200 dark:bg-slate-600 rounded-lg hover:bg-gray-300 dark:hover:bg-slate-500">
                        Cancelar
                    </button>
                    <button type="submit" :disabled="guardando"
                        class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 flex items-center gap-2">
                        <i class="fa-solid fa-save"></i>
                        <span x-text="guardando ? 'Guardando...' : 'Guardar'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_URL = 'vehiculos_api.php';
        const ID_EMPRESA = <?php echo $id_empresa; ?>;

        let gridApi = null;

        function vehiculosApp() {
            return {
                showModal: false,
                guardando: false,
                mostrarInactivos: false,
                tiposVehiculo: ['Camión', 'Camioneta', 'Furgón', 'Motocicleta', 'Automóvil', 'Semi-remolque', 'Trailer'],

                vehiculo: this.getEmptyVehiculo(),

                getEmptyVehiculo() {
                    return {
                        id: null,
                        tipo: '',
                        marca: '',
                        modelo: '',
                        chapa: '',
                        color: '',
                        anho: null,
                        capacidad_kg: null,
                        conductor_id: null,
                        conductor_nombre: '',
                        conductor_documento: '',
                        observaciones: '',
                        activo: 1
                    };
                },

                toggleTheme() {
                    document.documentElement.classList.add('dark');
                    // Actualizar tema del grid
                    const gridDiv = document.querySelector('#vehiculosGrid');
                    gridDiv.className = 'ag-theme-quartz-dark w-full h-full rounded-lg shadow-sm';
                },

                init() {
                    this.initGrid();
                    this.cargarTipos();
                },

                async cargarTipos() {
                    try {
                        const res = await fetch(`${API_URL}?action=tipos&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();
                        if (data.success && data.data) {
                            this.tiposVehiculo = data.data;
                        }
                    } catch (e) {
                        console.error('Error cargando tipos:', e);
                    }
                },

                initGrid() {
                    const columnDefs = [{
                            field: 'chapa',
                            headerName: 'Chapa',
                            width: 120,
                            cellClass: 'font-mono font-bold'
                        },
                        {
                            field: 'tipo',
                            headerName: 'Tipo',
                            width: 120
                        },
                        {
                            field: 'marca',
                            headerName: 'Marca',
                            width: 120
                        },
                        {
                            field: 'modelo',
                            headerName: 'Modelo',
                            width: 120
                        },
                        {
                            field: 'color',
                            headerName: 'Color',
                            width: 100
                        },
                        {
                            field: 'anho',
                            headerName: 'Año',
                            width: 80
                        },
                        {
                            field: 'capacidad_kg',
                            headerName: 'Capacidad (kg)',
                            width: 120,
                            valueFormatter: params => params.value ? parseFloat(params.value).toLocaleString('es-PY') + ' kg' : ''
                        },
                        {
                            field: 'conductor_nombre',
                            headerName: 'Conductor',
                            flex: 1,
                            minWidth: 150
                        },
                        {
                            field: 'conductor_documento',
                            headerName: 'Doc. Conductor',
                            width: 120
                        },
                        {
                            field: 'activo',
                            headerName: 'Estado',
                            width: 100,
                            cellRenderer: params => {
                                const activo = params.value == 1;
                                const clase = activo ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
                                const texto = activo ? 'Activo' : 'Inactivo';
                                return `<span class="px-2 py-0.5 rounded text-xs font-medium ${clase}">${texto}</span>`;
                            }
                        }
                    ];

                    const gridOptions = {
                        columnDefs,
                        defaultColDef: {
                            sortable: true,
                            filter: true,
                            resizable: true
                        },
                        rowData: [],
                        animateRows: false,
                        rowSelection: 'single',
                        localeText: {
                            noRowsToShow: 'No hay vehículos registrados',
                            loadingOoo: 'Cargando...',
                            filterOoo: 'Filtrar...',
                            equals: 'Igual',
                            contains: 'Contiene',
                            copy: 'Copiar'
                        },
                        getContextMenuItems: (params) => this.getContextMenu(params),
                        onRowDoubleClicked: (event) => this.editarVehiculo(event.data)
                    };

                    const gridDiv = document.querySelector('#vehiculosGrid');
                    gridApi = agGrid.createGrid(gridDiv, gridOptions);

                    // Gestión de tema
                    const updateGridTheme = () => {
                        const isDark = document.documentElement.classList.contains('dark');
                        gridDiv.className = (isDark ? 'ag-theme-quartz-dark' : 'ag-theme-quartz') + ' w-full h-full rounded-lg shadow-sm';
                    };
                    updateGridTheme();

                    const observer = new MutationObserver(() => updateGridTheme());
                    observer.observe(document.documentElement, {
                        attributes: true,
                        attributeFilter: ['class']
                    });

                    this.loadGridData();
                },

                getContextMenu(params) {
                    if (!params.node || !params.node.data) return ['copy'];

                    const data = params.node.data;
                    const items = [];

                    items.push({
                        name: 'Editar',
                        icon: '<i class="fa-solid fa-edit"></i>',
                        action: () => this.editarVehiculo(data)
                    });

                    if (data.activo == 1) {
                        items.push({
                            name: 'Desactivar',
                            icon: '<i class="fa-solid fa-ban text-red-500"></i>',
                            action: () => this.eliminarVehiculo(data.id)
                        });
                    } else {
                        items.push({
                            name: 'Reactivar',
                            icon: '<i class="fa-solid fa-check text-green-500"></i>',
                            action: () => this.restaurarVehiculo(data.id)
                        });
                    }

                    items.push('separator', 'copy');
                    return items;
                },

                async loadGridData() {
                    if (!gridApi) return;
                    gridApi.setGridOption('loading', true);

                    try {
                        let url = `${API_URL}?action=list&id_empresa=${ID_EMPRESA}`;
                        if (this.mostrarInactivos) url += '&include_inactive=1';

                        const res = await fetch(url);
                        const data = await res.json();

                        gridApi.setGridOption('rowData', data.success ? data.rows : []);
                    } catch (e) {
                        console.error('Error cargando datos:', e);
                        gridApi.setGridOption('rowData', []);
                    }

                    gridApi.setGridOption('loading', false);
                },

                abrirModal() {
                    this.vehiculo = this.getEmptyVehiculo();
                    this.showModal = true;
                },

                editarVehiculo(data) {
                    this.vehiculo = {
                        id: data.id,
                        tipo: data.tipo || '',
                        marca: data.marca || '',
                        modelo: data.modelo || '',
                        chapa: data.chapa || '',
                        color: data.color || '',
                        anho: data.anho || null,
                        capacidad_kg: data.capacidad_kg || null,
                        conductor_id: data.conductor_id || null,
                        conductor_nombre: data.conductor_nombre || '',
                        conductor_documento: data.conductor_documento || '',
                        observaciones: data.observaciones || '',
                        activo: data.activo
                    };
                    this.showModal = true;
                },

                async guardarVehiculo() {
                    if (!this.vehiculo.tipo || !this.vehiculo.marca || !this.vehiculo.chapa) {
                        Swal.fire('Error', 'Tipo, Marca y Chapa son requeridos', 'error');
                        return;
                    }

                    this.guardando = true;
                    try {
                        const action = this.vehiculo.id ? 'update' : 'create';
                        const res = await fetch(`${API_URL}?action=${action}&id_empresa=${ID_EMPRESA}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(this.vehiculo)
                        });
                        const data = await res.json();

                        if (data.success) {
                            Swal.fire('Éxito', data.message || 'Vehículo guardado', 'success');
                            this.showModal = false;
                            this.loadGridData();
                            this.cargarTipos(); // Actualizar lista de tipos
                        } else {
                            Swal.fire('Error', data.error || 'Error al guardar', 'error');
                        }
                    } catch (e) {
                        Swal.fire('Error', 'Error de conexión', 'error');
                    }
                    this.guardando = false;
                },

                async eliminarVehiculo(id) {
                    const result = await Swal.fire({
                        title: '¿Desactivar vehículo?',
                        text: 'El vehículo no aparecerá en las listas de selección',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Sí, desactivar',
                        cancelButtonText: 'Cancelar',
                        confirmButtonColor: '#ef4444'
                    });

                    if (!result.isConfirmed) return;

                    try {
                        const res = await fetch(`${API_URL}?action=delete&id=${id}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success) {
                            Swal.fire('Desactivado', 'Vehículo desactivado correctamente', 'success');
                            this.loadGridData();
                        } else {
                            Swal.fire('Error', data.error, 'error');
                        }
                    } catch (e) {
                        Swal.fire('Error', 'Error de conexión', 'error');
                    }
                },

                async restaurarVehiculo(id) {
                    try {
                        const res = await fetch(`${API_URL}?action=restore&id=${id}&id_empresa=${ID_EMPRESA}`);
                        const data = await res.json();

                        if (data.success) {
                            Swal.fire('Restaurado', 'Vehículo activado correctamente', 'success');
                            this.loadGridData();
                        } else {
                            Swal.fire('Error', data.error, 'error');
                        }
                    } catch (e) {
                        Swal.fire('Error', 'Error de conexión', 'error');
                    }
                }
            };
        }
    </script>
</body>

</html>
