<?php

/**
 * Facturas de Venta - Vista Principal con AG Grid
 * La carga de datos se hace vía facturas_api.php para evitar conflictos con Five Server
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_empresa = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int) $_SESSION['id_empresa'] : 169;

// Cargar nombre de empresa para el título
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

$nombreEmpresa = 'Gestión de Facturas';
$timbrado_empresa = '';
$ancho_columna_ticket = 0;
$debug_db = '';
try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->exec("SET NAMES utf8");
    $stmt = $pdo->prepare("SELECT empresa, timbrado FROM $masterDb.empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $rowEmpresa = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rowEmpresa) {
        $nombreEmpresa = $rowEmpresa['empresa'] ?? $nombreEmpresa;
        $timbrado_empresa = $rowEmpresa['timbrado'] ?? '';
    }
    // Intentar obtener ancho_columna_ticket (puede no existir en algunas instalaciones)
    try {
        $stmtC = $pdo->prepare("SELECT ancho_columna_ticket FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtC->execute([':id' => $id_empresa]);
        $ancho_columna_ticket = (int)$stmtC->fetchColumn();
    } catch (Exception $e2) {
        // Columna no existe, usar default
        $ancho_columna_ticket = 0;
    }
    $debug_db = "OK - timbrado: $timbrado_empresa";
} catch (Exception $e) {
    $debug_db = "ERROR: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Facturas de Venta</title>
    <link rel="stylesheet" href="_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css">
    <link rel="stylesheet" href="_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="_lib/ag-grid/ag-grid-enterprise/package/dist/ag-grid-enterprise.min.noStyle.js"></script>
    <script src="_lib/ag-grid/license.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
    <script src="https://unpkg.com/feather-icons"></script>
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
        // Detectar tema al inicio
        (function() {
            window.idEmpresaGlobal = <?php echo $id_empresa; ?>;
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

        :root {
            color-scheme: light;
            --bg: transparent;
            --text: #1f2933;
            --border-color: rgba(0, 0, 0, 0.4);
            --ag-background: rgba(255, 255, 255, 0.2) !important;
            --ag-header-background: rgba(241, 245, 249, 0.6) !important;
        }

        html.dark {
            color-scheme: dark;
            --bg: transparent !important;
            --text: #f1f5f9;
            --border-color: rgba(255, 255, 255, 0.4);
            --ag-background: rgba(30, 41, 59, 0.4) !important;
            --ag-header-background: rgba(15, 23, 42, 0.6) !important;
            background: transparent !important;
            background-color: transparent !important;
        }

        body {
            font-family: "Poppins", sans-serif;
            margin: 12px;
            background: transparent !important;
            color: var(--text);
            min-height: calc(100vh - 0px);
            overflow: hidden;
        }

        html.dark body {
            margin: 0;
        }

        .container {
            height: 100vh;
            background-color: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(2px);
            border: 0px;
            border-radius: 20px;
            margin: 0;
            padding: 12px;
            width: 100% !important;
            max-width: 100% !important;
        }

        html.dark .container {
            background-color: rgba(12, 23, 39, 0.9) !important;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            /* Reduced margin */
            flex-wrap: nowrap;
            /* Force single line */
            gap: 10px;
        }

        h1 {
            color: #2d7be5;
            font-size: 1.2rem !important;
            font-weight: 500 !important;
        }

        .empresa-name {
            margin: 4px 0 0;
            color: #38bdf8;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .toolbar {
            display: flex;
            gap: 12px;
        }

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

        .btn-chip--danger {
            border-color: #ef4444;
            color: #ef4444;
        }

        .btn-chip--danger:hover {
            background: rgba(239, 68, 68, 0.05);
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 10px 10px 35px;
            border-radius: 8px;
            border: 1px solid #ccc;
            width: 200px;
            background: var(--bg);
            color: var(--text);
            font-size: 13px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            s color: #94a3b8;
        }

        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            height: calc(100vh - 78px);
            min-height: 500px;
            border-radius: 8px;
        }

        html.dark #initialLoader {
            background-color: transparent !important;
        }

        html.dark #gridFacturas {
            background-color: transparent !important;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-chart-pie"></i> Ventas</h1>
                <!-- DEPRECADO: Nombre de empresa debajo del título
                <p class="empresa-name"><?php echo htmlspecialchars($nombreEmpresa); ?></p>
                -->
            </div>
            <div class="toolbar" style="display:flex; align-items:center;">
                <div style="display:flex; gap:8px; align-items:center; margin-right:10px;">
                    <input type="date" id="fechaDesde" class="p-2 border rounded rounded-xl bg-white dark:bg-slate-700 dark:text-white dark:border-slate-600 text-sm" title="Desde">
                    <span class="dark:text-white">-</span>
                    <input type="date" id="fechaHasta" class="p-2 border rounded rounded-xl bg-white dark:bg-slate-700 dark:text-white dark:border-slate-600 text-sm" title="Hasta">
                </div>

                <div class="search-box">
                    <input type="text" id="globalFilter" placeholder="Buscar..." style="padding-top:8px; padding-bottom:8px;">
                    <i class="fa-solid fa-search"></i>
                </div>

                <!-- Dropdown Más -->
                <div x-data="{ open: false }" class="relative inline-block text-left">
                    <button type="button" @click="open = !open" @click.away="open = false" class="btn-chip flex items-center gap-1">
                        <i class="fa-solid fa-bars"></i> Más <i class="fa-solid fa-chevron-down text-xs"></i>
                    </button>
                    <div x-show="open"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="transform opacity-0 scale-95"
                        x-transition:enter-end="transform opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="transform opacity-100 scale-100"
                        x-transition:leave-end="transform opacity-0 scale-95"
                        class="absolute right-0 z-10 mt-2 w-48 origin-top-right rounded-md bg-white dark:bg-slate-800 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none"
                        style="display: none;">
                        <div class="py-1">
                            <a href="nr_sifen.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700">
                                <i class="fa-solid fa-truck w-5 text-center"></i> Nota Remisión
                            </a>
                            <a href="nc_sifen.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700">
                                <i class="fa-solid fa-file-circle-minus w-5 text-center"></i> Nota Crédito
                            </a>
                            <a href="nd_sifen.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700">
                                <i class="fa-solid fa-file-circle-plus w-5 text-center"></i> Nota Débito
                            </a>
                            <?php if ($id_empresa == 169): ?>
                                <div class="border-t border-gray-200 dark:border-slate-600 my-1"></div>
                                <a href="habilitacion_sifen.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700">
                                    <i class="fa-solid fa-certificate w-5 text-center"></i> Habilitar Factura Electrónica
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <button id="reloadGrid" class="btn-chip">
                    <i class="fa-solid fa-rotate"></i> Actualizar
                </button>
                <button class="btn-chip btn-chip--danger" onclick="exitSystem()">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Salir
                </button>
            </div>
        </div>
        <!-- Loader Inicial -->
        <div id="initialLoader" class="flex flex-col items-center justify-center bg-white dark:bg-slate-800 rounded-lg shadow-inner border border-gray-200 dark:border-slate-700" style="height: calc(100vh - 140px); min-height: 500px;">
            <i class="fa-solid fa-circle-notch fa-spin text-5xl text-blue-500 mb-4"></i>
            <div class="text-gray-600 dark:text-gray-300 font-medium text-lg">Cargando Sistema de Facturación...</div>
            <div class="text-gray-400 text-sm mt-2">Sincronizando con SIFEN</div>
        </div>

        <!-- Grid Oculto Inicialmente -->
        <div id="gridFacturas" class="ag-theme-quartz hidden opacity-0 transition-opacity duration-500"></div>

        <script>
            // Init Dates (Current Month)
            (function() {
                const now = new Date();
                const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
                const today = new Date();

                document.getElementById('fechaDesde').valueAsDate = firstDay;
                document.getElementById('fechaHasta').valueAsDate = today;
            })();

            // Listen to date changes
            document.getElementById('fechaDesde').addEventListener('change', () => loadGridData());
            document.getElementById('fechaHasta').addEventListener('change', () => loadGridData());

            function exitSystem() {
                console.log('Saliendo del sistema...');

                // Intentar cerrar usando la función del padre
                try {
                    if (typeof parent.cerrarApp === 'function') {
                        parent.cerrarApp();
                        return;
                    }
                } catch (e) {
                    console.error("Error al intentar cerrarApp:", e);
                }

                try {
                    // Si estamos en un iframe de Scriptcase, intentar redirigir el top
                    if (window.top && window.top !== window) {
                        window.top.location.href = 'menu/menu.php';
                        return;
                    }
                } catch (e) {
                    console.error('Error redirigiendo top:', e);
                }

                // Fallback: Redirigir la ventana actual al menú
                window.location.href = 'menu/menu.php';
            }

            async function retrySifen(idFactura) {
                window.dispatchEvent(new CustomEvent('open-loading-modal', {
                    detail: {
                        title: 'Re-enviando a SIFEN',
                        text: 'Por favor espere...'
                    }
                }));

                try {
                    const formData = new FormData();
                    formData.append('id_factura', idFactura);
                    formData.append('id_empresa', ID_EMPRESA);

                    const response = await fetch('pos/api/sifen_retry.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        window.dispatchEvent(new CustomEvent('open-alert-modal', {
                            detail: {
                                title: result.estado === 'Aprobado' ? '✅ Aprobado' : 'ℹ️ ' + result.estado,
                                text: result.mensaje || 'Procesado correctamente',
                                icon: result.estado === 'Aprobado' ? 'success' : 'info',
                                onClose: () => loadGridData()
                            }
                        }));
                    } else {
                        window.dispatchEvent(new CustomEvent('open-alert-modal', {
                            detail: {
                                title: 'Error',
                                text: result.message || 'Error en el envío',
                                icon: 'error'
                            }
                        }));
                    }
                    window.dispatchEvent(new CustomEvent('close-loading-modal'));
                } catch (error) {
                    console.error('Retry Error:', error);
                    window.dispatchEvent(new CustomEvent('close-loading-modal'));
                    window.dispatchEvent(new CustomEvent('open-alert-modal', {
                        detail: {
                            title: 'Error',
                            text: 'No se pudo conectar con el servidor',
                            icon: 'error'
                        }
                    }));
                }
            }

            async function consultarSifen(idFactura, cdc) {
                window.dispatchEvent(new CustomEvent('open-loading-modal', {
                    detail: {
                        title: 'Consultando SIFEN',
                        text: 'Obteniendo estado actual del documento...'
                    }
                }));

                try {
                    const response = await fetch('_lib/php-sifen3/consulta_smx.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            cdc: cdc,
                            modo: 'prod' // Ajustar segun entorno si es necesario
                        })
                    });

                    const result = await response.json();

                    if (result.success) {
                        // Parsear XML para obtener resumen amigable
                        const parser = new DOMParser();
                        const xmlDoc = parser.parseFromString(result.xml, "text/xml");

                        // Helper para extracción robusta (DOM o Regex)
                        const getTagContent = (tagName) => {
                            // 1. Intentar DOM directo
                            const el = xmlDoc.getElementsByTagName(tagName)[0] ||
                                xmlDoc.getElementsByTagNameNS("*", tagName)[0];
                            if (el) return el.textContent;

                            // 2. Intentar Regex en el string crudo (para casos escapados como &lt;dProtAut>)
                            const regex = new RegExp(`(?:<|&lt;)${tagName}(?:>|&gt;)(.*?)(?:<|&lt;)\/${tagName}(?:>|&gt;)`);
                            const match = result.xml.match(regex);
                            return match ? match[1] : null;
                        };

                        const codRes = getTagContent('dCodRes');
                        const msgRes = getTagContent('dMsgRes');
                        let estRes = getTagContent('dEstRes');
                        const protAut = getTagContent('dProtAut') || getTagContent('dProtConsLote');
                        const fecRes = getTagContent('dFecProc');

                        // Lógica robusta para determinar Estado
                        if (!estRes) {
                            // Caso común: Respuesta 0422 (CDC Encontrado) y con Protocolo de Autorización
                            // Esto significa que la factura está Aprobada, aunque no venga explícitamente "Aprobado"
                            if (codRes === '0422' && protAut) {
                                estRes = 'Aprobado';
                            }
                        }

                        let statusIcon = 'info';
                        let statusColor = '#3b82f6';

                        if (estRes === 'Aprobado') {
                            statusIcon = 'success';
                            statusColor = '#16a34a';
                        } else if (estRes === 'Anulado') {
                            statusIcon = 'warning';
                            statusColor = '#dc2626';
                        } else if (codRes && codRes !== '0502' && codRes !== '0500' && codRes !== '0422') {
                            // Si no es aprobado, ni pendiente (0502), ni lote recibido (0500), ni encontrado (0422) -> Error/Rechazo
                            statusIcon = 'error';
                            statusColor = '#ef4444';
                        }

                        window.dispatchEvent(new CustomEvent('close-loading-modal'));

                        // Actualizar Grid si existe
                        if (window.gridApi && estRes) {
                            window.gridApi.forEachNode(node => {
                                if (node.data && node.data.id_factura == idFactura) {
                                    const newData = {
                                        ...node.data,
                                        estado_sifen: estRes
                                    };
                                    // Si tenemos mensaje, lo guardamos también para que se lea en el futuro
                                    if (msgRes) newData.xml_respuesta = `[${codRes}] ${msgRes}`;
                                    node.setData(newData);
                                    // Forzar refresco de celda de estado por si acaso
                                    window.gridApi.refreshCells({
                                        rowNodes: [node],
                                        columns: ['estado_sifen']
                                    });
                                }
                            });
                        }

                        // Persistir el estado en la base de datos
                        if (estRes) {
                            fetch(API_URL, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: new URLSearchParams({
                                    action: 'update_sifen_status',
                                    id_factura: idFactura,
                                    id_empresa: ID_EMPRESA,
                                    estado: estRes,
                                    xml: result.xml,
                                    mensaje: `[${codRes}] ${msgRes}`
                                })
                            }).then(r => r.json()).then(resp => {
                                if (!resp.success) console.error('Error persistiendo estado SIFEN:', resp.message);
                                else console.log('Estado SIFEN guardado correctamente');
                            }).catch(err => console.error('Error persistiendo estado:', err));
                        }

                        // Dispatch event to Alpine modal
                        window.dispatchEvent(new CustomEvent('open-sifen-modal', {
                            detail: {
                                cdc: cdc,
                                status: estRes || '-',
                                message: `[${codRes || ''}] ${msgRes || '-'}`,
                                protocol: protAut,
                                date: fecRes,
                                xml: result.xml,
                                color: statusColor,
                                icon: statusIcon
                            }
                        }));

                    } else {
                        window.dispatchEvent(new CustomEvent('close-loading-modal'));
                        window.dispatchEvent(new CustomEvent('open-alert-modal', {
                            detail: {
                                title: 'Error',
                                text: result.message || 'Error en la consulta',
                                icon: 'error'
                            }
                        }));
                    }
                } catch (error) {
                    console.error('Consulta Error:', error);
                    window.dispatchEvent(new CustomEvent('close-loading-modal'));
                    window.dispatchEvent(new CustomEvent('open-alert-modal', {
                        detail: {
                            title: 'Error',
                            text: 'No se pudo consultar SIFEN',
                            icon: 'error'
                        }
                    }));
                }
            }
        </script>
        <script>
            // Configuración de la empresa (inyectado desde PHP)
            const ID_EMPRESA = <?php echo $id_empresa; ?>;

            // URL de la API
            // Si estamos en Five Server (puerto 5555), debemos ir directamente a Apache
            // Usamos la IP real del servidor, no 127.0.0.1
            const SERVER_IP = '<?php echo $_SERVER["SERVER_ADDR"] ?? "168.231.95.50"; ?>';
            let API_URL;

            if (window.location.port === '5555') {
                // Estamos en Five Server - usar IP real del servidor
                API_URL = 'http://' + SERVER_IP + '/scriptcase/app/smx/facturas_api.php';
            } else if (window.location.hostname === '127.0.0.1' || window.location.hostname === 'localhost') {
                // Acceso local directo - usar ruta relativa
                API_URL = 'facturas_api.php';
            } else {
                // Acceso remoto directo a Apache - usar ruta relativa
                API_URL = 'facturas_api.php';
            }
            console.log('API URL:', API_URL, '| Port:', window.location.port, '| Host:', window.location.hostname);

            console.log('API URL:', API_URL, '| Port:', window.location.port, '| Host:', window.location.hostname);

            const columnDefs = [
                // 1. ID
                {
                    headerName: 'ID',
                    field: 'id_factura',
                    width: 120, // Aumentar un poco para el icono (+ ID)
                    sortable: true,
                    filter: true,

                    valueFormatter: p => p.node.rowPinned ? '' : p.value
                },
                // 2. Tipo Factura (REAL)
                {
                    headerName: 'Tipo',
                    field: 'tipo_documento',
                    width: 160,
                    valueFormatter: params => {
                        let val = parseInt(params.value || 0);
                        // Fallback para registros antiguos: Si tiene CDC, considerar Electrónica
                        if (!val || val === 0 || val === 1) {
                            if (params.data && params.data.cdc && params.data.cdc.trim().length > 10) val = 3;
                        }

                        if (val === 3) return 'Electronica';
                        if (val === 2) return 'Factura Autoimpreso';
                        return 'Nota Común';
                    },
                    cellRenderer: params => {
                        if (params.node.rowPinned || params.node.group) return '';
                        let val = parseInt(params.value || 0);

                        // Fallback para registros antiguos
                        if (!val || val === 0 || val === 1) {
                            if (params.data && params.data.cdc && params.data.cdc.trim().length > 10) val = 3;
                        }

                        let label = 'Venta común';
                        let color = '#64748b';

                        if (val === 3) {
                            label = 'Electrónica';
                            color = '#2c7ae5';
                        } else if (val === 2) {
                            label = 'Autoimpresa';
                            color = '#d97706';
                        }

                        return `<span style="color: ${color}; font-weight: 600;">${label}</span>`;
                    }
                },
                // 2.5. Estado (virtual - basado en xml_respuesta y est_res_anul)
                {
                    headerName: 'Estado',
                    field: 'estado_sifen',
                    width: 120,
                    valueGetter: params => {
                        if (!params.data) return '';

                        // Prioridad 1: Si está anulado (Verificar campo manual y respuesta SIFEN)
                        const estResAnul = params.data.est_res_anul || '';
                        if (estResAnul.toLowerCase().includes('anulado')) {
                            return 'Anulado';
                        }

                        if (params.data.estado_sifen) return params.data.estado_sifen;

                        // Solo aplicar si es Factura Electrónica (tiene CDC)
                        const cdc = params.data.cdc || '';
                        if (!cdc || cdc.trim() === '') {
                            return ''; // No es factura electrónica
                        }

                        // Prioridad 2: Verificar xml_respuesta
                        const xmlResp = params.data.xml_respuesta || '';
                        if (xmlResp.includes('Aprobado')) {
                            return 'Aprobado';
                        } else if (xmlResp.includes('Rechazado')) {
                            return 'Rechazado';
                        }

                        // Tiene CDC pero no tiene respuesta = Pendiente
                        return 'Pendiente';
                    },
                    cellRenderer: params => {
                        if (params.node.rowPinned || params.node.group) return '';

                        const value = params.value || '';
                        let color, bgColor, icon;

                        switch (value) {
                            case 'Aprobado':
                                color = '#166534';
                                bgColor = '#dcfce7';
                                icon = '✓';
                                break;
                            case 'Rechazado':
                            case 'Error Envío':
                                color = '#dc2626';
                                bgColor = '#fee2e2';
                                icon = '✗';
                                break;
                            case 'Pendiente':
                                color = '#7c2d12';
                                bgColor = '#fef3c7';
                                icon = '⏳';
                                break;
                            case 'Guardado Local':
                                color = '#2563eb';
                                bgColor = '#dbeafe';
                                icon = '💾';
                                break;
                            case 'Anulado':
                                color = '#ffffff';
                                bgColor = '#64748b';
                                icon = '⊘';
                                break;
                            default:
                                return `<span style="color:#9ca3af;">-</span>`;
                        }

                        return `<span style="color:${color}; background:${bgColor}; padding:2px 8px; border-radius:4px; font-weight:600; font-size:11px; cursor:default;">${icon} ${value}</span>`;
                    }
                },

                // 4. Nro Factura
                {
                    headerName: 'Factura nro.',
                    field: 'nro_factura',
                    width: 170,
                    sortable: true,
                    filter: true,
                    valueFormatter: p => p.node.rowPinned ? '' : p.value
                },
                // 5. Fecha
                {
                    headerName: 'Fecha',
                    field: 'fecha',
                    width: 150,
                    sortable: true,
                    filter: 'agDateColumnFilter',
                    valueFormatter: p => {
                        if (!p.value || p.node.rowPinned) return '';
                        const d = new Date(p.value);
                        return d.toLocaleDateString('es-PY') + ' ' + d.toLocaleTimeString('es-PY', {
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                    }
                },
                // 6. Cliente
                {
                    headerName: 'Cliente',
                    field: 'nombre_cliente',
                    width: 250,
                    sortable: true,
                    filter: true
                },
                // 7. Total
                {
                    headerName: 'Total',
                    field: 'total',
                    width: 150,
                    type: 'numericColumn',
                    aggFunc: 'sum',
                    valueFormatter: p => {
                        const val = p.value || 0;
                        return Number(val).toLocaleString('es-PY') + ' Gs';
                    },
                    cellStyle: params => {
                        const style = {
                            textAlign: 'right',
                            fontWeight: 'normal'
                        };
                        if (params.node.group || params.node.footer || params.node.rowPinned) {
                            style.fontWeight = 'bold';
                            style.color = '#2563eb';
                        }
                        return style;
                    }
                },

                // Ocultas
                {
                    headerName: 'RUC',
                    field: 'ruc_cliente',
                    width: 120,
                    // hide: true
                },
                {
                    headerName: 'Estado',
                    field: 'estado',
                    width: 120,
                    hide: true
                },
                {
                    headerName: 'CDC',
                    field: 'cdc',
                    width: 250,
                    hide: true
                },
                {
                    headerName: 'XML Firmado',
                    field: 'xml_firmado',
                    width: 100,
                    hide: true
                }
            ];

            const gridOptions = {
                theme: "legacy",
                columnDefs: columnDefs,

                // Modelo cliente para soportar agrupación
                rowModelType: 'clientSide',
                masterDetail: false,

                // Totales al final
                groupTotalRow: 'bottom',


                // Configuración por defecto
                defaultColDef: {
                    flex: 1,
                    minWidth: 100,
                    resizable: true,
                    sortable: true,
                    filter: true,
                    enableValue: true,
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
                                suppressPivots: true,
                                suppressPivotMode: true
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
                    position: 'right',
                    defaultToolPanel: ''
                },

                // Pivot desactivado
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
                    noRowsToShow: 'No hay facturas',

                    // Menús y Columnas
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

                    // Sidebar / Panel de columnas
                    columns: 'Columnas',
                    filters: 'Filtros',
                    rowGroupColumns: 'Columnas de Agrupación',
                    rowGroupColumnsEmptyMessage: 'Arrastre columnas aquí para agrupar',
                    valueColumns: 'Columnas de Valor',
                    valueColumnsEmptyMessage: 'Arrastre columnas aquí para agregar',
                    pivotColumns: 'Columnas de Pivote',
                    pivotColumnsEmptyMessage: 'Arrastre columnas aquí para pivotar',
                    pivotMode: 'Modo Pivote',
                    groups: 'Grupos de Filas',
                    values: 'Valores',
                    pivots: 'Etiquetas de Columna',

                    // Status bar
                    totalRows: 'Filas Totales',
                    totalAndFilteredRows: 'Filas',
                    filteredRows: 'Filtradas',
                    selectedRows: 'Seleccionadas',
                    more: 'Más',
                    to: 'a',
                    of: 'de',
                    page: 'Página',
                    nextPage: 'Siguiente',
                    lastPage: 'Última',
                    firstPage: 'Primera',
                    previousPage: 'Anterior',

                    // Filtros de Texto/Número
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
                    blank: 'Vacío',
                    notBlank: 'No Vacío',
                    andCondition: 'Y',
                    orCondition: 'O',
                    applyFilter: 'Aplicar',
                    resetFilter: 'Restablecer',
                    clearFilter: 'Limpiar',

                    // Operaciones
                    copy: 'Copiar',
                    ctrlC: 'Ctrl+C',
                    paste: 'Pegar',
                    ctrlV: 'Ctrl+V',
                    export: 'Exportar',
                    csvExport: 'Exportar CSV',
                    excelExport: 'Exportar Excel',

                    // Agregaciones
                    sum: 'Suma',
                    min: 'Mínimo',
                    max: 'Máximo',
                    avg: 'Promedio',
                    count: 'Cantidad',
                    none: 'Ninguno',

                    // Arrastrar
                    rowDragRows: 'filas'
                },

                // Estilos especiales para fila de total y facturas anuladas
                getRowStyle: params => {
                    if (params.node.rowPinned === 'bottom') {
                        return {
                            fontWeight: 'bold'
                        };
                    }
                    const data = params.data;
                    if (data) {
                        // Usar la misma lógica que el valueGetter del estado
                        const estResAnul = data.est_res_anul || '';
                        const estadoSifen = data.estado_sifen || '';
                        if (estResAnul.toLowerCase().includes('anulado') || estadoSifen.toLowerCase() === 'anulado') {
                            const isDark = document.documentElement.classList.contains('dark');
                            return {
                                background: isDark ? '#1e293b' : '#f1f5f9',
                                color: isDark ? '#94a3b8' : '#94a3b8', // Color de texto tenue
                                borderLeft: '5px solid #ef4444' // Franja roja
                            };
                        }
                    }
                    return null;
                },


                // Evitar problemas de renderizado con popups
                popupParent: document.body,
                suppressAnimationFrame: false,
                animateRows: false, // Desactivar animaciones que pueden causar parpadeo

                // Menú contextual personalizado (clic derecho)
                getContextMenuItems: (params) => {
                    if (!params.node || !params.node.data) {
                        return ['copy', 'export'];
                    }

                    const data = params.node.data;
                    const cdc = data.cdc || '';

                    // Fallback para esElectronica: si tiene CDC largo, es FE
                    let tipo = parseInt(data.tipo_documento || 1);
                    if ((tipo !== 3) && cdc.trim().length > 10) tipo = 3;

                    const isElectronica = (tipo === 3);
                    const idFactura = data.id_factura;

                    const menuItems = [];
                    const anchoTicket = parseInt('<?php echo isset($ancho_columna_ticket) ? $ancho_columna_ticket : 0; ?>');
                    const isTicketMode = (anchoTicket === 40 || anchoTicket === 80);

                    // Obtener estado para lógica de visibilidad
                    const colEstado = gridOptions.columnDefs.find(c => c.field === 'estado_sifen');
                    const estadoActual = colEstado.valueGetter({
                        data: data
                    });
                    const isAnulado = (estadoActual === 'Anulado');

                    // Editar Factura - solo para facturas NO electrónicas
                    if (!isElectronica) {
                        menuItems.push({
                            name: 'Editar',
                            action: () => {
                                console.log('Editar factura:', idFactura);
                                window.open(`pos/index.php?edit_id=${idFactura}`, '_blank');
                            }
                        });

                        if (isTicketMode) {
                            menuItems.push({
                                name: 'Imprimir ticket',
                                action: () => {
                                    let baseUrl = (window.location.port === '5555') ? 'http://168.231.95.50/scriptcase/app/smx/' : '';
                                    window.open(`${baseUrl}nota_ticket.php?id=${idFactura}`, '_blank');
                                }
                            });
                        } else {
                            menuItems.push({
                                name: 'Reimprimir',
                                action: () => {
                                    // Abrir formulario de reimpresión en modal
                                    let baseUrl = (window.location.port === '5555') ? 'http://168.231.95.50/scriptcase/app/smx/' : '';
                                    window.dispatchEvent(new CustomEvent('open-pos-modal', {
                                        detail: {
                                            idVenta: idFactura,
                                            posUrl: `${baseUrl}reimprimir.php?id_factura=${idFactura}`
                                        }
                                    }));
                                }
                            });
                        }

                        // Anular Factura Común
                        if (!isAnulado) {
                            menuItems.push({
                                name: 'Anular',
                                action: () => {
                                    window.dispatchEvent(new CustomEvent('open-confirm-modal', {
                                        detail: {
                                            title: '¿Anular venta?',
                                            text: 'Esta acción anulará la factura en el sistema. Asegúrese de que no sea una Factura Electrónica.',
                                            icon: 'warning',
                                            confirmText: 'Sí, Anular',
                                            confirmColor: 'bg-red-600 hover:bg-red-700',
                                            onConfirm: () => {
                                                // Usar la misma lógica de anulación local que para rechazados
                                                fetch('anular_local.php', {
                                                        method: 'POST',
                                                        headers: {
                                                            'Content-Type': 'application/json'
                                                        },
                                                        body: JSON.stringify({
                                                            id_factura: idFactura,
                                                            id_empresa: window.idEmpresaGlobal || ID_EMPRESA
                                                        })
                                                    })
                                                    .then(r => r.json())
                                                    .then(res => {
                                                        if (res.success) {
                                                            window.dispatchEvent(new CustomEvent('open-alert-modal', {
                                                                detail: {
                                                                    title: 'Anulada',
                                                                    text: 'La factura ha sido anulada localmente.',
                                                                    icon: 'success'
                                                                }
                                                            }));
                                                            loadGridData();
                                                        } else {
                                                            throw new Error(res.error || 'Error desconocido');
                                                        }
                                                    })
                                                    .catch(e => {
                                                        window.dispatchEvent(new CustomEvent('open-alert-modal', {
                                                            detail: {
                                                                title: 'Error',
                                                                text: e.message,
                                                                icon: 'error'
                                                            }
                                                        }));
                                                    });
                                            }
                                        }
                                    }));
                                }
                            });
                        }
                        menuItems.push('separator');
                    }

                    // Print Kude Ticket - solo para Factura Electrónica
                    if (isElectronica) {
                        if (isTicketMode) {
                            // Solo Ticket
                            menuItems.push({
                                name: 'Reimprimir',
                                action: () => {
                                    // Abrir formulario de reimpresión en modal
                                    let baseUrl = (window.location.port === '5555') ? 'http://168.231.95.50/scriptcase/app/smx/' : '';
                                    window.dispatchEvent(new CustomEvent('open-pos-modal', {
                                        detail: {
                                            idVenta: idFactura,
                                            posUrl: `${baseUrl}reimprimir.php?id_factura=${idFactura}`
                                        }
                                    }));
                                }
                            });
                        } else {
                            // Solo A4
                            menuItems.push({
                                name: 'Reimprimir',
                                action: () => {
                                    // Abrir formulario de reimpresión en modal
                                    let baseUrl = (window.location.port === '5555') ? 'http://168.231.95.50/scriptcase/app/smx/' : '';
                                    window.dispatchEvent(new CustomEvent('open-pos-modal', {
                                        detail: {
                                            idVenta: idFactura,
                                            posUrl: `${baseUrl}reimprimir.php?id_factura=${idFactura}`
                                        }
                                    }));
                                }
                            });
                        }


                        // Consultar SIFEN - Disponible para FE si no está anulado Y NO APROBADO
                        if (!isAnulado && estadoActual !== 'Aprobado') {
                            menuItems.push({
                                name: 'Consultar en SIFEN',
                                action: () => consultarSifen(idFactura, cdc)
                            });
                        }

                        // Anular en SIFEN - Solo si está Aprobado
                        if (estadoActual === 'Aprobado') {
                            menuItems.push({
                                name: 'Anular en SIFEN',
                                action: () => confirmarAnulacion(data)
                            });
                        }

                        // Si no está aprobado ni anulado, permitir Re-enviar
                        if (estadoActual !== 'Aprobado' && estadoActual !== 'Anulado') {
                            menuItems.push({
                                name: 'Reenviar a SIFEN',
                                action: () => retrySifen(idFactura)
                            });
                        }

                        // SOLO SI ES RECHAZADO: Opciones de corrección y anulación local
                        if (estadoActual === 'Rechazado') {
                            menuItems.push('separator');

                            menuItems.push({
                                name: '⚠ Ver Motivo de Rechazo',
                                action: () => {
                                    const event = new CustomEvent('show-rejection-modal', {
                                        detail: {
                                            reason: data.xml_respuesta,
                                            payload: data.xml_firmado
                                        },
                                        bubbles: true,
                                        composed: true
                                    });
                                    window.dispatchEvent(event);
                                }
                            });

                            menuItems.push({
                                name: 'Anular Localmente (Rechazado)',
                                action: () => {
                                    window.dispatchEvent(new CustomEvent('open-confirm-modal', {
                                        detail: {
                                            title: '¿Anular localmente?',
                                            text: 'Esta factura fue rechazada por SIFEN. Al anularla localmente, dejará de computar en tus ventas. Como ya fue rechazada, no existe legalmente en SIFEN.',
                                            icon: 'warning',
                                            confirmText: 'Sí, Anular Local',
                                            confirmColor: 'bg-red-600 hover:bg-red-700',
                                            onConfirm: () => {
                                                fetch('anular_local.php', {
                                                        method: 'POST',
                                                        headers: {
                                                            'Content-Type': 'application/json'
                                                        },
                                                        body: JSON.stringify({
                                                            id_factura: idFactura,
                                                            id_empresa: window.idEmpresaGlobal || ID_EMPRESA
                                                        })
                                                    })
                                                    .then(response => {
                                                        if (!response.ok) throw new Error('HTTP ' + response.status);
                                                        return response.json();
                                                    })
                                                    .then(result => {
                                                        if (result.success) {
                                                            window.dispatchEvent(new CustomEvent('open-alert-modal', {
                                                                detail: {
                                                                    title: '✅ Éxito',
                                                                    text: result.message,
                                                                    icon: 'success',
                                                                    onClose: () => loadGridData()
                                                                }
                                                            }));
                                                        } else {
                                                            window.dispatchEvent(new CustomEvent('open-alert-modal', {
                                                                detail: {
                                                                    title: 'Error',
                                                                    text: result.message,
                                                                    icon: 'error'
                                                                }
                                                            }));
                                                        }
                                                    })
                                                    .catch(err => {
                                                        console.error('Fetch error:', err);
                                                        window.dispatchEvent(new CustomEvent('open-alert-modal', {
                                                            detail: {
                                                                title: 'Error',
                                                                text: 'Error de red o servidor: ' + err.message,
                                                                icon: 'error'
                                                            }
                                                        }));
                                                    });
                                            }
                                        }
                                    }));
                                }
                            });

                            menuItems.push({
                                name: 'Editar / Re-enviar',
                                action: () => {
                                    const url = `pos/index.php?id_venta=${idFactura}`;
                                    window.open(url, '_blank');
                                }
                            });
                        }
                    }

                    // Notas Electrónicas removidas del menú contextual
                    // Acceder desde nc_sifen.php y nd_sifen.php directamente

                    menuItems.push('separator');

                    // Recibo de Dinero - para facturas a crédito
                    const formaPago = (data.forma_pago || '').toLowerCase();
                    if (formaPago.includes('crédito') || formaPago.includes('credito')) {
                        menuItems.push({
                            name: '💰 Emitir Recibo de Cobro',
                            action: () => {
                                showReciboModal(idFactura, data);
                            }
                        });
                        menuItems.push('separator');
                    }

                    // Opciones estándar de AG Grid
                    menuItems.push('copy');
                    menuItems.push('export');

                    return menuItems;
                }
            };

            // Inicializar Grid
            const gridDiv = document.querySelector('#gridFacturas');
            window.gridApi = agGrid.createGrid(gridDiv, gridOptions);
            const api = window.gridApi;

            // Función para cargar datos
            async function loadGridData() {
                try {
                    // Mostrar loading
                    api.setGridOption('loading', true);

                    const sDate = document.getElementById('fechaDesde').value;
                    const eDate = document.getElementById('fechaHasta').value;

                    // Construir URL - cargar datos filtrados por fecha y tipo (3 = FE)
                    const url = `${API_URL}?action=get_rows&startRow=0&endRow=10000&id_empresa=${ID_EMPRESA}&startDate=${sDate}&endDate=${eDate}&_t=${Date.now()}`;
                    console.log('Loading data from:', url);

                    const response = await fetch(url);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }

                    const data = await response.json();
                    console.log('Data loaded:', data.rows?.length, 'rows');

                    if (data.error) {
                        throw new Error(data.error);
                    }

                    const rows = data.rows || [];
                    const filteredRows = rows.filter(row => parseInt(row.estado, 10) === 1);

                    // Calcular total
                    const totalSum = filteredRows.reduce((sum, row) => sum + (parseFloat(row.total) || 0), 0);

                    // Fila de total fija en el fondo
                    const pinnedBottomRow = [{
                        id_factura: '',
                        nro_factura: '',
                        fecha: '',
                        nombre_cliente: 'TOTAL GENERAL',
                        ruc_cliente: '',
                        forma_pago: `${filteredRows.length} facturas`,
                        total: totalSum,
                        estado: '',
                        cdc: ''
                    }];

                    // Establecer datos en el grid
                    api.setGridOption('rowData', filteredRows);
                    api.setGridOption('pinnedBottomRowData', pinnedBottomRow);
                    api.setGridOption('loading', false);

                    // REVEAL GRID
                    const loader = document.getElementById('initialLoader');
                    const gridEl = document.getElementById('gridFacturas');

                    if (loader && gridEl) {
                        loader.style.display = 'none';
                        gridEl.classList.remove('hidden');
                        // Small delay to allow render before fading in
                        setTimeout(() => {
                            gridEl.classList.remove('opacity-0');
                        }, 50);
                    }

                } catch (error) {
                    console.error('Load Error:', error);
                    api.showNoRowsOverlay();
                    console.error('Load Error:', error);
                    api.showNoRowsOverlay();
                    window.dispatchEvent(new CustomEvent('open-alert-modal', {
                        detail: {
                            title: 'Error',
                            text: error.message,
                            icon: 'error'
                        }
                    }));
                }
            }

            // Cargar datos al inicio
            loadGridData();

            // Función para recalcular totales según filas visibles/filtradas
            function updateTotals() {
                let totalSum = 0;
                let rowCount = 0;

                // Recorrer solo las filas visibles (después del filtro)
                api.forEachNodeAfterFilter(node => {
                    if (node.data && !node.group) {
                        totalSum += parseFloat(node.data.total) || 0;
                        rowCount++;
                    }
                });

                // Actualizar fila de total
                const pinnedBottomRow = [{
                    id_factura: '',
                    nro_factura: '',
                    fecha: '',
                    nombre_cliente: 'TOTAL GENERAL',
                    ruc_cliente: '',
                    forma_pago: `${rowCount} facturas`,
                    total: totalSum,
                    estado: '',
                    cdc: ''
                }];

                api.setGridOption('pinnedBottomRowData', pinnedBottomRow);
            }

            // Escuchar cambios de filtro para recalcular totales
            api.addEventListener('filterChanged', updateTotals);
            api.addEventListener('rowDataUpdated', updateTotals);

            // ========== PERSISTENCIA DE CONFIGURACIÓN (SERVER-SIDE) ==========
            let saveTimeout;

            // Guardar estado del grid (Debounced)
            function saveGridState() {
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(() => {
                    // Capturar el estado completo manualmente para máxima compatibilidad
                    const state = {
                        columnState: api.getColumnState(),
                        filterState: api.getFilterModel(),
                        sortState: api.getColumnState().filter(c => c.sort).map(c => ({
                            colId: c.colId,
                            sort: c.sort,
                            sortIndex: c.sortIndex
                        })),
                        groupState: api.getColumnGroupState ? api.getColumnGroupState() : null
                    };

                    // Si api.getState existe (versiones nuevas), úsalo también o como principal
                    // const modernState = api.getState ? api.getState() : null;

                    fetch(API_URL, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({
                            action: 'save_grid_state',
                            id_empresa: ID_EMPRESA,
                            grid_name: 'facturas_sifen',
                            state: JSON.stringify(state)
                        })
                    }).then(r => r.json()).then(data => {
                        if (data.success) console.log('Grid state saved to server');
                    }).catch(err => console.error('Error saving grid state:', err));
                }, 1000);
            }

            // Restaurar estado del grid
            function restoreGridState() {
                fetch(API_URL + '?action=load_grid_state&grid_name=facturas_sifen&id_empresa=' + ID_EMPRESA)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.state) {
                            try {
                                const state = JSON.parse(data.state);
                                console.log('Restoring state:', state);

                                if (state.columnState) {
                                    api.applyColumnState({
                                        state: state.columnState,
                                        applyOrder: true
                                    });
                                }
                                if (state.filterState) {
                                    api.setFilterModel(state.filterState);
                                }
                                // Si guardamos sortState aparte, se aplica con columnState, pero lo verificamos

                                console.log('Grid state restored from server');
                            } catch (e) {
                                console.error('Error parsing grid state:', e);
                            }
                        }
                    })
                    .catch(err => console.error('Error loading grid state:', err));
            }

            // Restaurar estado después de cargar datos
            api.addEventListener('firstDataRendered', () => {
                restoreGridState();
            });

            // Guardar estado cuando cambia (Eventos clave)
            const events = ['columnMoved', 'columnResized', 'columnVisible', 'columnPinned', 'sortChanged', 'filterChanged', 'columnRowGroupChanged', 'columnPivotChanged'];
            events.forEach(e => api.addEventListener(e, saveGridState));

            // Gestión de tema - solo actualizar si realmente cambia
            let currentThemeClass = '';

            function updateGridTheme() {
                const isDark = document.documentElement.classList.contains('dark');
                const newThemeClass = isDark ? 'ag-theme-quartz-dark' : 'ag-theme-quartz';

                // Solo actualizar si el tema realmente cambió
                if (currentThemeClass !== newThemeClass) {
                    currentThemeClass = newThemeClass;
                    gridDiv.className = newThemeClass;
                }
            }
            updateGridTheme();

            // Observer solo para cambios reales de tema (no para otros atributos)
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

            // Event listeners
            document.getElementById('globalFilter').addEventListener('input', (e) => {
                api.setGridOption('quickFilterText', e.target.value);
            });

            document.getElementById('reloadGrid').addEventListener('click', () => {
                loadGridData();
            });

            // Botón para resetear configuración (opcional - agregar al HTML si se desea)
            window.resetGridState = function() {
                localStorage.removeItem(STORAGE_KEY);
                location.reload();
            };

            // ========== MODAL PARA EMITIR NC/ND ELECTRÓNICA ==========
            function showNotaModal(tipoNota, idFactura, cdc, facturaData) {
                window.dispatchEvent(new CustomEvent('open-nota-modal', {
                    detail: {
                        tipo: tipoNota,
                        idFactura: idFactura,
                        cdc: cdc,
                        facturaData: facturaData,
                        onSuccess: () => loadGridData()
                    }
                }));
            }

            // ========== MODAL PARA RECIBO DE DINERO ==========
            function showReciboModal(idFactura, facturaData) {
                window.dispatchEvent(new CustomEvent('open-recibo-modal', {
                    detail: {
                        idFactura: idFactura,
                        facturaData: facturaData
                    }
                }));
            }

            const API_ANULAR_URL = 'anular_factura_api.php';

            function confirmarAnulacion(row) {
                if (!row) return;
                window.dispatchEvent(new CustomEvent('open-input-modal', {
                    detail: {
                        title: '¿Anular factura en SIFEN?',
                        subtitle: `Factura: ${row.nro_factura}\nCDC: ${row.cdc}`,
                        label: 'Motivo de anulación:',
                        placeholder: 'Ingrese el motivo...',
                        defaultValue: 'Error de digitación',
                        confirmText: 'Sí, Anular en SIFEN',
                        confirmColor: 'bg-red-600 hover:bg-red-700',
                        icon: 'warning',
                        onConfirm: (motivo) => {
                            if (!motivo || motivo.trim() === '') {
                                window.dispatchEvent(new CustomEvent('open-alert-modal', {
                                    detail: {
                                        title: 'Error',
                                        text: 'Debe ingresar un motivo de anulación',
                                        icon: 'error'
                                    }
                                }));
                                return;
                            }
                            anularFactura(row, motivo);
                        }
                    }
                }));
            }

            async function anularFactura(row, motivo) {
                window.dispatchEvent(new CustomEvent('open-loading-modal', {
                    detail: {
                        title: 'Procesando anulación...',
                        text: 'Comunicando con SIFEN'
                    }
                }));

                try {
                    const response = await fetch(API_ANULAR_URL, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            id_factura: row.id_factura,
                            cdc: row.cdc,
                            motivo_anulacion: motivo,
                            id_empresa: window.idEmpresaGlobal
                        })
                    });

                    const result = await response.json();
                    window.dispatchEvent(new CustomEvent('close-loading-modal'));

                    if (result.success) {
                        window.dispatchEvent(new CustomEvent('open-alert-modal', {
                            detail: {
                                title: '✅ Éxito',
                                text: result.message,
                                icon: 'success',
                                onClose: () => loadGridData()
                            }
                        }));
                    } else {
                        window.dispatchEvent(new CustomEvent('open-alert-modal', {
                            detail: {
                                title: 'Error',
                                text: result.message,
                                icon: 'error'
                            }
                        }));
                    }
                } catch (error) {
                    console.error('Error al anular:', error);
                    window.dispatchEvent(new CustomEvent('close-loading-modal'));
                    window.dispatchEvent(new CustomEvent('open-alert-modal', {
                        detail: {
                            title: 'Error',
                            text: 'No se pudo procesar la anulación.',
                            icon: 'error'
                        }
                    }));
                }
            }

            let showAnuladasOnly = false;

            function toggleAnuladas() {
                showAnuladasOnly = !showAnuladasOnly;
                const btn = document.getElementById('btnAnuladas');
                const icon = btn.querySelector('i');

                if (showAnuladasOnly) {
                    // Active Style
                    btn.style.backgroundColor = 'rgba(59, 130, 246, 0.1)';
                    icon.className = 'fa-solid fa-check'; // Change icon to indicate active/check

                    // Aplicar filtro
                    if (window.gridApi) {
                        window.gridApi.setFilterModel({
                            estado_sifen: {
                                filterType: 'text',
                                type: 'contains',
                                filter: 'Anulado'
                            }
                        });
                    }
                } else {
                    // Inactive Style
                    btn.style.backgroundColor = 'transparent';
                    icon.className = 'fa-solid fa-ban'; // Revert icon

                    // Limpiar filtro
                    if (window.gridApi) {
                        window.gridApi.setFilterModel(null);
                    }
                }
            }
        </script>
        <!-- Generic Alert Modal -->
        <div x-data="{ open: false, title: '', text: '', icon: '', onClose: null }"
            @open-alert-modal.window="open = true; title = $event.detail.title; text = $event.detail.text; icon = $event.detail.icon; onClose = $event.detail.onClose"
            @keydown.escape.window="open = false; if(onClose) onClose()"
            class="relative z-[10001]" role="dialog" aria-modal="true" x-show="open" style="display: none;">
            <div x-show="open" class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>
            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div x-show="open" @click.outside="open = false; if(onClose) onClose()" class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6 border border-gray-100 dark:border-slate-700">
                        <div>
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full"
                                :class="{
                                  'bg-green-100 dark:bg-green-900/30': icon === 'success',
                                  'bg-red-100 dark:bg-red-900/30': icon === 'error',
                                  'bg-blue-100 dark:bg-blue-900/30': icon === 'info',
                                  'bg-yellow-100 dark:bg-yellow-900/30': icon === 'warning'
                              }">
                                <template x-if="icon === 'success'"><svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg></template>
                                <template x-if="icon === 'error'"><svg class="h-6 w-6 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg></template>
                                <template x-if="icon === 'info'"><svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg></template>
                                <template x-if="icon === 'warning'"><svg class="h-6 w-6 text-yellow-600 dark:text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg></template>
                            </div>
                            <div class="mt-3 text-center sm:mt-5">
                                <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white" x-text="title"></h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500 dark:text-gray-400" x-text="text"></p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 sm:mt-6">
                            <button type="button" @click="open = false; if(onClose) onClose()" class="inline-flex w-full justify-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">Entendido</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Generic Confirm Modal -->
        <div x-data="{ open: false, title: '', text: '', icon: '', confirmText: 'Sí', confirmColor: 'bg-blue-600 hover:bg-blue-700', onConfirm: null }"
            @open-confirm-modal.window="open = true; title = $event.detail.title; text = $event.detail.text; icon = $event.detail.icon; confirmText = $event.detail.confirmText; confirmColor = $event.detail.confirmColor || 'bg-blue-600 hover:bg-blue-700'; onConfirm = $event.detail.onConfirm"
            class="relative z-[10002]" role="dialog" aria-modal="true" x-show="open" style="display: none;">
            <div x-show="open" class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>
            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div x-show="open" @click.outside="open = false" class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 border border-gray-100 dark:border-slate-700">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full sm:mx-0 sm:h-10 sm:w-10" :class="icon === 'warning' ? 'bg-yellow-100 dark:bg-yellow-900/30' : 'bg-blue-100 dark:bg-blue-900/30'">
                                <template x-if="icon === 'warning'"><svg class="h-6 w-6 text-yellow-600 dark:text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg></template>
                            </div>
                            <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white" x-text="title"></h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500 dark:text-gray-400" x-text="text"></p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                            <button type="button" @click="open = false; if(onConfirm) onConfirm()" class="inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm sm:ml-3 sm:w-auto" :class="confirmColor" x-text="confirmText"></button>
                            <button type="button" @click="open = false" class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-slate-700 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600 sm:mt-0 sm:w-auto">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Generic Input Modal -->
        <div x-data="{ open: false, title: '', subtitle: '', label: '', placeholder: '', inputValue: '', confirmText: 'Confirmar', confirmColor: '', onConfirm: null }"
            @open-input-modal.window="open = true; title = $event.detail.title; subtitle = $event.detail.subtitle; label = $event.detail.label; placeholder = $event.detail.placeholder; inputValue = $event.detail.defaultValue || ''; confirmText = $event.detail.confirmText; confirmColor = $event.detail.confirmColor; onConfirm = $event.detail.onConfirm"
            class="relative z-[10003]" role="dialog" aria-modal="true" x-show="open" style="display: none;">
            <div x-show="open" class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>
            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div x-show="open" class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 border border-gray-100 dark:border-slate-700">
                        <div>
                            <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white" x-text="title"></h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1" x-text="subtitle"></p>
                            <div class="mt-4">
                                <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200" x-text="label"></label>
                                <div class="mt-2">
                                    <textarea x-model="inputValue" rows="3" class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-slate-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-slate-700 sm:text-sm sm:leading-6" :placeholder="placeholder"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                            <button type="button" @click="if(onConfirm) onConfirm(inputValue); open = false;" class="inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm sm:col-start-2" :class="confirmColor" x-text="confirmText"></button>
                            <button type="button" @click="open = false" class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-slate-700 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600 sm:col-start-1 sm:mt-0">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- DEBUG: timbrado_empresa = <?php echo htmlspecialchars($timbrado_empresa ?? 'VACIO'); ?> | id_empresa = <?php echo $id_empresa; ?> | db_status = <?php echo $debug_db; ?> -->
        <div x-data="{ 
            open: false, 
            errorMessage: '',
            payload: '',
            timbrado: '<?php echo htmlspecialchars($timbrado_empresa ?? ''); ?>',
            parseAndShow(raw, payload = null) {
                console.log('Parsing:', raw);
                console.log('Timbrado:', this.timbrado);
                this.errorMessage = raw;
                this.payload = payload || '';
                try {
                    // Si es JSON, intentar parsear
                    let xml = raw;
                    if (raw.trim().startsWith('{')) {
                        const json = JSON.parse(raw);
                        if (json.response) xml = json.response;
                    }
                    
                    // Extraer mensaje (dMsgRes)
                    // Match con ns2: o sin namespace
                    const msgMatch = xml.match(/:dMsgRes>(.*?)<\//) || xml.match(/<dMsgRes>(.*?)<\//);
                    const codMatch = xml.match(/:dCodRes>(.*?)<\//) || xml.match(/<dCodRes>(.*?)<\//);
                    
                    if (msgMatch && msgMatch[1]) {
                        let msg = msgMatch[1];
                        // Decodificar entidades HTML si hay
                        const txt = document.createElement('textarea');
                        txt.innerHTML = msg;
                        msg = txt.value;
                        
                        this.errorMessage = msg;
                        
                        if (codMatch && codMatch[1]) {
                           this.errorMessage = `[Código: ${codMatch[1]}] ${msg}`;
                        }
                    }
                } catch (e) { console.error('Error parsing rejection:', e); }
                this.open = true;
                console.log('Modal OPEN state set to TRUE');
            }
         }"
            @show-rejection-modal.window="parseAndShow($event.detail.reason, $event.detail.payload)"
            @keydown.escape.window="open = false"
            x-show="open"
            class="fixed inset-0 z-[9999] overflow-y-auto"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0">

            <!-- Backdrop -->
            <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity" @click="open = false"></div>

            <!-- Panel -->
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="relative bg-white dark:bg-gray-800 rounded-lg max-w-2xl w-full shadow-xl overflow-hidden transform transition-all"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">

                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xl font-bold text-red-600 flex items-center gap-2">
                                <i class="fa-solid fa-triangle-exclamation"></i> Motivo de Rechazo SIFEN
                            </h3>
                            <button @click="open = false" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                                <i class="fa-solid fa-times text-xl"></i>
                            </button>
                        </div>

                        <div class="mt-2 bg-red-50 dark:bg-red-900/20 p-4 rounded-md border border-red-200 dark:border-red-800">
                            <p class="text-lg text-gray-800 dark:text-gray-200 font-semibold mb-2">Mensaje del Sistema:</p>
                            <div class="text-base text-gray-700 dark:text-gray-300 whitespace-pre-wrap break-words"
                                x-text="errorMessage"></div>
                            <div class="mt-3 pt-3 border-t border-red-200 dark:border-red-700">
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    <strong>Timbrado configurado:</strong> <span x-text="timbrado || 'No configurado'" class="font-mono"></span>
                                </p>
                            </div>
                        </div>

                        <div class="mt-4" x-show="payload && payload.length > 0">
                            <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Payload Enviado:</p>
                            <textarea class="w-full h-32 text-xs font-mono p-2 border rounded bg-gray-50 dark:bg-gray-900 dark:text-gray-300"
                                x-text="payload"
                                readonly></textarea>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <button type="button"
                                class="inline-flex justify-center px-4 py-2 text-sm font-medium text-white bg-red-600 border border-transparent rounded-md hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-red-500"
                                @click="open = false">
                                Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modern Alpine.js Modal for SIFEN Response -->
        <div x-data="{ 
            open: false, 
            data: { cdc: '', status: '', message: '', protocol: '', date: '', xml: '', color: '', icon: '' },
            showXml: false
         }"
            @open-sifen-modal.window="open = true; data = $event.detail; showXml = false"
            @keydown.escape.window="open = false"
            class="relative z-[9999]"
            aria-labelledby="modal-title"
            role="dialog"
            aria-modal="true"
            x-show="open"
            style="display: none;">

            <!-- Backdrop -->
            <div x-show="open"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <!-- Modal Panel -->
                    <div x-show="open"
                        x-transition:enter="ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-200"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        class="relative transform overflow-hidden rounded-xl bg-white dark:bg-slate-800 text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-2xl border border-gray-100 dark:border-slate-700">

                        <!-- Header with Icon -->
                        <div class="bg-gray-50 dark:bg-slate-750 px-4 py-5 sm:px-6 flex items-center justify-between border-b border-gray-100 dark:border-slate-700">
                            <div class="flex items-center gap-3">
                                <div class="flex-shrink-0">
                                    <template x-if="data.icon === 'success'">
                                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                                            <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                        </div>
                                    </template>
                                    <template x-if="data.icon === 'info'">
                                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                                            <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                                            </svg>
                                        </div>
                                    </template>
                                    <template x-if="data.icon === 'error' || data.icon === 'warning'">
                                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                                            <svg class="h-6 w-6 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                            </svg>
                                        </div>
                                    </template>
                                </div>
                                <h3 class="text-lg font-semibold leading-6 text-gray-900 dark:text-white" id="modal-title">
                                    Respuesta SIFEN
                                </h3>
                            </div>
                            <button @click="open = false" type="button" class="text-gray-400 hover:text-gray-500 dark:text-slate-400 dark:hover:text-slate-300 transition-colors">
                                <span class="sr-only">Cerrar</span>
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="px-4 py-5 sm:p-6 bg-white dark:bg-slate-800">
                            <!-- CDC Box -->
                            <div class="mb-6 rounded-lg bg-slate-50 dark:bg-slate-900/50 p-4 border border-slate-100 dark:border-slate-700">
                                <div class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">CDC (Código de Control)</div>
                                <div class="font-mono text-xs sm:text-sm text-slate-700 dark:text-slate-300 break-all select-all" x-text="data.cdc"></div>
                            </div>

                            <!-- Info Grid -->
                            <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                                <div class="sm:col-span-1">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-slate-400">Estado</dt>
                                    <dd class="mt-1 text-sm font-bold"
                                        :class="{
                                        'text-green-600 dark:text-green-400': data.status === 'Aprobado',
                                        'text-red-600 dark:text-red-400': data.status === 'Rechazado' || data.status === 'Anulado' || data.icon === 'error',
                                        'text-blue-600 dark:text-blue-400': !['Aprobado', 'Rechazado', 'Anulado'].includes(data.status) && data.icon !== 'error'
                                    }"
                                        x-text="data.status || '-'">
                                    </dd>
                                </div>
                                <div class="sm:col-span-1">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-slate-400">Mensaje</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200" x-text="data.message"></dd>
                                </div>
                                <template x-if="data.protocol">
                                    <div class="sm:col-span-1">
                                        <dt class="text-sm font-medium text-gray-500 dark:text-slate-400">Protocolo</dt>
                                        <dd class="mt-1 text-sm font-mono text-gray-900 dark:text-gray-200" x-text="data.protocol"></dd>
                                    </div>
                                </template>
                                <template x-if="data.date">
                                    <div class="sm:col-span-1">
                                        <dt class="text-sm font-medium text-gray-500 dark:text-slate-400">Fecha Proceso</dt>
                                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-200" x-text="data.date"></dd>
                                    </div>
                                </template>
                            </dl>

                            <!-- XML Toggle -->
                            <div class="mt-8 border-t border-gray-100 dark:border-slate-700 pt-4">
                                <button type="button"
                                    @click="showXml = !showXml"
                                    class="flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 hover:underline">
                                    <svg class="h-4 w-4 transition-transform" :class="showXml ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                    <span>Ver XML de Respuesta</span>
                                </button>

                                <div x-show="showXml"
                                    x-collapse
                                    class="mt-3">
                                    <div class="relative">
                                        <pre class="overflow-x-auto rounded-lg bg-gray-900 p-4 text-xs text-green-400 font-mono shadow-inner max-h-60 custom-scrollbar" x-text="data.xml"></pre>
                                        <button @click="navigator.clipboard.writeText(data.xml)" class="absolute top-2 right-2 p-1 text-gray-400 hover:text-white bg-gray-800 rounded opacity-50 hover:opacity-100 transition-opacity">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="bg-gray-50 dark:bg-slate-750 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                            <button type="button"
                                class="inline-flex w-full justify-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 sm:ml-3 sm:w-auto transition-colors"
                                @click="open = false">
                                Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modern Alpine.js Loading Modal -->
        <div x-data="{ open: false, title: '', text: '' }"
            @open-loading-modal.window="open = true; title = $event.detail.title; text = $event.detail.text"
            @close-loading-modal.window="open = false"
            class="relative z-[10000]"
            aria-labelledby="modal-title"
            role="dialog"
            aria-modal="true"
            x-show="open"
            style="display: none;">

            <div x-show="open"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
                    <div x-show="open"
                        x-transition:enter="ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-200"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6 border border-gray-100 dark:border-slate-700">
                        <div>
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                                <svg class="animate-spin h-6 w-6 text-blue-600 dark:text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-5">
                                <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white" x-text="title"></h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500 dark:text-gray-400" x-text="text"></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Generic Alert Modal -->
        <div x-data="{ open: false, title: '', text: '', icon: '', onClose: null }"
            @open-alert-modal.window="open = true; title = $event.detail.title; text = $event.detail.text; icon = $event.detail.icon; onClose = $event.detail.onClose"
            @keydown.escape.window="open = false; if(onClose) onClose()"
            class="relative z-[10001]" role="dialog" aria-modal="true" x-show="open" style="display: none;">
            <div x-show="open" class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>
            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div x-show="open" @click.outside="open = false; if(onClose) onClose()" class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-sm sm:p-6 border border-gray-100 dark:border-slate-700">
                        <div>
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full"
                                :class="{
                                  'bg-green-100 dark:bg-green-900/30': icon === 'success',
                                  'bg-red-100 dark:bg-red-900/30': icon === 'error',
                                  'bg-blue-100 dark:bg-blue-900/30': icon === 'info',
                                  'bg-yellow-100 dark:bg-yellow-900/30': icon === 'warning'
                              }">
                                <template x-if="icon === 'success'"><svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg></template>
                                <template x-if="icon === 'error'"><svg class="h-6 w-6 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg></template>
                                <template x-if="icon === 'info'"><svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg></template>
                                <template x-if="icon === 'warning'"><svg class="h-6 w-6 text-yellow-600 dark:text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg></template>
                            </div>
                            <div class="mt-3 text-center sm:mt-5">
                                <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white" x-text="title"></h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500 dark:text-gray-400" x-text="text"></p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 sm:mt-6">
                            <button type="button" @click="open = false; if(onClose) onClose()" class="inline-flex w-full justify-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">Entendido</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Generic Confirm Modal -->
        <div x-data="{ open: false, title: '', text: '', icon: '', confirmText: 'Sí', confirmColor: 'bg-blue-600 hover:bg-blue-700', onConfirm: null }"
            @open-confirm-modal.window="open = true; title = $event.detail.title; text = $event.detail.text; icon = $event.detail.icon; confirmText = $event.detail.confirmText; confirmColor = $event.detail.confirmColor || 'bg-blue-600 hover:bg-blue-700'; onConfirm = $event.detail.onConfirm"
            class="relative z-[10002]" role="dialog" aria-modal="true" x-show="open" style="display: none;">
            <div x-show="open" class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>
            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div x-show="open" @click.outside="open = false" class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 border border-gray-100 dark:border-slate-700">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full sm:mx-0 sm:h-10 sm:w-10" :class="icon === 'warning' ? 'bg-yellow-100 dark:bg-yellow-900/30' : 'bg-blue-100 dark:bg-blue-900/30'">
                                <template x-if="icon === 'warning'"><svg class="h-6 w-6 text-yellow-600 dark:text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg></template>
                            </div>
                            <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white" x-text="title"></h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500 dark:text-gray-400" x-text="text"></p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                            <button type="button" @click="open = false; if(onConfirm) onConfirm()" class="inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm sm:ml-3 sm:w-auto" :class="confirmColor" x-text="confirmText"></button>
                            <button type="button" @click="open = false" class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-slate-700 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600 sm:mt-0 sm:w-auto">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Generic Input Modal -->
        <div x-data="{ open: false, title: '', subtitle: '', label: '', placeholder: '', inputValue: '', confirmText: 'Confirmar', confirmColor: '', onConfirm: null }"
            @open-input-modal.window="open = true; title = $event.detail.title; subtitle = $event.detail.subtitle; label = $event.detail.label; placeholder = $event.detail.placeholder; inputValue = $event.detail.defaultValue || ''; confirmText = $event.detail.confirmText; confirmColor = $event.detail.confirmColor; onConfirm = $event.detail.onConfirm"
            class="relative z-[10003]" role="dialog" aria-modal="true" x-show="open" style="display: none;">
            <div x-show="open" class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>
            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div x-show="open" class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 border border-gray-100 dark:border-slate-700">
                        <div>
                            <h3 class="text-base font-semibold leading-6 text-gray-900 dark:text-white" x-text="title"></h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1" x-text="subtitle"></p>
                            <div class="mt-4">
                                <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200" x-text="label"></label>
                                <div class="mt-2">
                                    <textarea x-model="inputValue" rows="3" class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-slate-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-slate-700 sm:text-sm sm:leading-6" :placeholder="placeholder"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                            <button type="button" @click="if(onConfirm) onConfirm(inputValue); open = false;" class="inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm sm:col-start-2" :class="confirmColor" x-text="confirmText"></button>
                            <button type="button" @click="open = false" class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-slate-700 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600 sm:col-start-1 sm:mt-0">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Nota Modal -->
        <div x-data="{ 
            open: false, type: 'NC', idFactura: '', cdc: '', motivo: '1', descripcion: '', cantidad: 1, precio: 0, iva: '10', 
            facturaData: {}, onSuccess: null, loading: false,
            submit() {
                if (this.precio <= 0) {
                    window.dispatchEvent(new CustomEvent('open-alert-modal', { detail: { title: 'Error', text: 'El precio debe ser mayor a 0', icon: 'error' } }));
                    return;
                }
                this.loading = true;
                
                const payload = {
                    tipo_nota: this.type,
                    id_factura: this.idFactura,
                    cdc: this.cdc,
                    motivo: parseInt(this.motivo),
                    conceptos: [{
                        codigo: this.type + '-001',
                        descripcion: this.descripcion || (this.type === 'NC' ? 'Devolución' : 'Ajuste'),
                        precio: parseFloat(this.precio),
                        cantidad: parseFloat(this.cantidad),
                        tasa_iva: parseInt(this.iva),
                        descuento: 0,
                        unidad_medida: '77'
                    }]
                };

                fetch('emitir_nota.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(r => r.json())
                .then(result => {
                    this.loading = false;
                    this.open = false;
                    if (result.success) {
                        // Success Logic with XML visualization (simplified)
                        window.dispatchEvent(new CustomEvent('open-alert-modal', { 
                            detail: { 
                                title: result.estado === 'Aprobado' ? '✅ Aprobada' : '⚠️ Rechazada',
                                text: result.mensaje || result.message,
                                icon: result.estado === 'Aprobado' ? 'success' : 'warning',
                                onClose: this.onSuccess
                            } 
                        }));
                    } else {
                         window.dispatchEvent(new CustomEvent('open-alert-modal', { detail: { title: 'Error', text: result.message, icon: 'error' } }));
                    }
                })
                .catch(e => {
                    this.loading = false;
                    console.error(e);
                    window.dispatchEvent(new CustomEvent('open-alert-modal', { detail: { title: 'Error', text: 'Error de conexión', icon: 'error' } }));
                });
            }
         }"
            @open-nota-modal.window="open = true; type = $event.detail.tipo; idFactura = $event.detail.idFactura; cdc = $event.detail.cdc; facturaData = $event.detail.facturaData; onSuccess = $event.detail.onSuccess; descripcion = ''; cantidad = 1; precio = 0; motivo = '1';"
            class="relative z-[10004]" role="dialog" aria-modal="true" x-show="open" style="display: none;">

            <div x-show="open" class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>
            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div x-show="open" class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 border border-gray-100 dark:border-slate-700">
                        <h3 class="text-lg font-semibold leading-6 text-gray-900 dark:text-white" x-text="'Emitir ' + (type === 'NC' ? 'Nota de Crédito' : 'Nota de Débito')"></h3>

                        <div class="mt-4 grid grid-cols-1 gap-y-4">
                            <!-- Motivo -->
                            <div>
                                <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200">Motivo</label>
                                <select x-model="motivo" class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 dark:text-white dark:bg-slate-700 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 focus:ring-2 focus:ring-blue-600 sm:text-sm sm:leading-6">
                                    <template x-if="type === 'NC'">
                                        <optgroup label="Notas de Crédito">
                                            <option value="1">Devolución</option>
                                            <option value="2">Descuento</option>
                                            <option value="3">Error en facturación</option>
                                        </optgroup>
                                    </template>
                                    <template x-if="type === 'ND'">
                                        <optgroup label="Notas de Débito">
                                            <option value="1">Intereses por mora</option>
                                            <option value="2">Recupero de gastos</option>
                                            <option value="3">Diferencia de precio</option>
                                        </optgroup>
                                    </template>
                                </select>
                            </div>

                            <!-- Descripcion -->
                            <div>
                                <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200">Descripción</label>
                                <input type="text" x-model="descripcion" :placeholder="type === 'NC' ? 'Devolución de mercadería' : 'Intereses por mora'" class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-slate-700 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 focus:ring-2 focus:ring-blue-600 sm:text-sm sm:leading-6">
                            </div>

                            <div class="flex gap-4">
                                <div class="flex-1">
                                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200">Cant.</label>
                                    <input type="number" x-model="cantidad" class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-slate-700 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 focus:ring-2 focus:ring-blue-600 sm:text-sm sm:leading-6">
                                </div>
                                <div class="flex-1">
                                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200">Precio</label>
                                    <input type="number" x-model="precio" class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-slate-700 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 focus:ring-2 focus:ring-blue-600 sm:text-sm sm:leading-6">
                                </div>
                                <div class="w-1/3">
                                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200">IVA</label>
                                    <select x-model="iva" class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-slate-700 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 focus:ring-2 focus:ring-blue-600 sm:text-sm sm:leading-6">
                                        <option value="10">10%</option>
                                        <option value="5">5%</option>
                                        <option value="0">Exento</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 sm:flex sm:flex-row-reverse">
                            <button type="button" @click="submit" :disabled="loading" class="inline-flex w-full justify-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 sm:ml-3 sm:w-auto disabled:opacity-50">
                                <span x-show="!loading">Emitir Nota</span>
                                <span x-show="loading">Procesando...</span>
                            </button>
                            <button type="button" @click="open = false" class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-slate-700 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600 sm:mt-0 sm:w-auto">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recibo Modal -->
        <div x-data="{ 
            open: false, idFactura: '', monto: 0, forma: 'Efectivo', obs: '', 
            facturaData: {}, loading: false,
            submit() {
                if (this.monto <= 0) {
                    window.dispatchEvent(new CustomEvent('open-alert-modal', { detail: { title: 'Error', text: 'El monto debe ser mayor a 0', icon: 'error' } }));
                    return;
                }
                this.loading = true;

                fetch('emitir_recibo.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id_factura: this.idFactura,
                        monto: parseFloat(this.monto),
                        forma_pago: this.forma,
                        observacion: this.obs
                    })
                })
                .then(r => r.json())
                .then(result => {
                    this.loading = false;
                    this.open = false;
                    if (result.success) {
                        window.dispatchEvent(new CustomEvent('open-confirm-modal', {
                            detail: {
                                title: '✅ Recibo Emitido',
                                text: `Recibo #${result.data.nro_recibo} generado por ${parseFloat(result.data.monto).toLocaleString()} Gs.`,
                                icon: 'success',
                                confirmText: 'Imprimir Recibo',
                                confirmColor: 'bg-green-600 hover:bg-green-700',
                                onConfirm: () => {
                                    // Abrir impresión de recibo
                                    let baseUrl = (window.location.port === '5555') ? 'http://168.231.95.50/scriptcase/app/smx/' : '';
                                    window.open(`${baseUrl}imprimir_recibo.php?id=${result.data.id_recibo}`, '_blank');
                                    loadGridData();
                                }
                            }
                        }));
                    } else {
                         window.dispatchEvent(new CustomEvent('open-alert-modal', { detail: { title: 'Error', text: result.message, icon: 'error' } }));
                    }
                })
                .catch(e => {
                    this.loading = false;
                    console.error(e);
                    window.dispatchEvent(new CustomEvent('open-alert-modal', { detail: { title: 'Error', text: 'Error de conexión', icon: 'error' } }));
                });
            }
         }"
            @open-recibo-modal.window="open = true; idFactura = $event.detail.idFactura; facturaData = $event.detail.facturaData; monto = facturaData.total || 0; obs = ''; forma = 'Efectivo';"
            class="relative z-[10005]" role="dialog" aria-modal="true" x-show="open" style="display: none;">

            <div x-show="open" class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>
            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <div x-show="open" class="relative transform overflow-hidden rounded-lg bg-white dark:bg-slate-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 border border-gray-100 dark:border-slate-700">
                        <h3 class="text-lg font-semibold leading-6 text-gray-900 dark:text-white">Emitir Recibo de Cobro</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Factura: <span x-text="facturaData.nro_factura"></span></p>

                        <div class="mt-4 grid grid-cols-1 gap-y-4">
                            <div>
                                <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200">Monto a Cobrar</label>
                                <input type="number" x-model="monto" class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-slate-700 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 focus:ring-2 focus:ring-blue-600 sm:text-sm sm:leading-6">
                            </div>
                            <div>
                                <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200">Forma de Pago</label>
                                <select x-model="forma" class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-slate-700 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 focus:ring-2 focus:ring-blue-600 sm:text-sm sm:leading-6">
                                    <option value="Efectivo">Efectivo</option>
                                    <option value="T. Debito">Tarjeta Débito</option>
                                    <option value="T. Credito">Tarjeta Crédito</option>
                                    <option value="Transferencia">Transferencia</option>
                                    <option value="Cheque">Cheque</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200">Observación</label>
                                <input type="text" x-model="obs" placeholder="Nro de comprobante, banco..." class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-slate-700 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 focus:ring-2 focus:ring-blue-600 sm:text-sm sm:leading-6">
                            </div>
                        </div>

                        <div class="mt-6 sm:flex sm:flex-row-reverse">
                            <button type="button" @click="submit" :disabled="loading" class="inline-flex w-full justify-center rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 sm:ml-3 sm:w-auto disabled:opacity-50">
                                <span x-show="!loading">Cobrar</span>
                                <span x-show="loading">Procesando...</span>
                            </button>
                            <button type="button" @click="open = false" class="mt-3 inline-flex w-full justify-center rounded-md bg-white dark:bg-slate-700 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-slate-600 hover:bg-gray-50 dark:hover:bg-slate-600 sm:mt-0 sm:w-auto">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal POS para Re-imprimir -->
        <div x-data="{ open: false, idVenta: '', posUrl: '' }"
            @open-pos-modal.window="open = true; idVenta = $event.detail.idVenta; posUrl = $event.detail.posUrl;"
            @keydown.escape.window="open = false"
            x-init="window.addEventListener('message', (e) => { if (e.data?.action === 'close-modal') open = false; })"
            class="relative z-[10006]"
            role="dialog"
            aria-modal="true"
            x-show="open"
            style="display: none;">

            <!-- Backdrop -->
            <div x-show="open"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="open = false"
                class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 backdrop-blur-sm transition-opacity"></div>

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <!-- Modal Panel -->
                    <div x-show="open"
                        x-transition:enter="ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-200"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        class="relative transform overflow-hidden rounded-xl bg-white dark:bg-slate-800 shadow-2xl transition-all w-[95%] h-[95vh] border border-gray-100 dark:border-slate-700">

                        <!-- iFrame Content (sin header) -->
                        <div class="relative h-full bg-gray-100 dark:bg-slate-900">
                            <iframe
                                :src="posUrl"
                                class="w-full h-full border-0"
                                frameborder="0">
                            </iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>