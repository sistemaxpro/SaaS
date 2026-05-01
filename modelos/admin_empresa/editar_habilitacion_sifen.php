<?php

/**
 * Formulario Exclusivo para Editar Habilitación SIFEN
 * Página completa sin modal - para edición directa de registros
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Obtener id_empresa desde GET o SESSION
$id_empresa = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : (isset($_SESSION['id_empresa']) ? (int)$_SESSION['id_empresa'] : 0);
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

// Tipos de documento SIFEN
$tiposDocumento = [
    1 => 'Factura Electrónica',
    4 => 'Autofactura Electrónica',
    5 => 'Nota de Crédito Electrónica',
    6 => 'Nota de Débito Electrónica',
    7 => 'Nota de Remisión Electrónica'
];

// Conexión PDO
try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Cargar datos existentes
$habilitacion = null;
$documentos = [];
$actividades = [];
$empresaData = null;

if ($id_empresa > 0) {
    // Primero obtener datos de la empresa
    $stmtEmp = $pdo->prepare("SELECT id_empresa, empresa, ruc, dv, timbrado, telefono, email, direccion FROM {$masterDb}.empresa WHERE id_empresa = ?");
    $stmtEmp->execute([$id_empresa]);
    $empresaData = $stmtEmp->fetch(PDO::FETCH_ASSOC);

    // Buscar habilitación existente
    $stmt = $pdo->prepare("SELECT h.*, e.empresa as nombre_empresa FROM {$masterDb}.habilitacion_sifen h LEFT JOIN {$masterDb}.empresa e ON h.id_empresa = e.id_empresa WHERE h.id_empresa = ? ORDER BY h.id DESC LIMIT 1");
    $stmt->execute([$id_empresa]);
    $habilitacion = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($habilitacion) {
        $stmt2 = $pdo->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen_documentos WHERE id_habilitacion = ?");
        $stmt2->execute([$habilitacion['id']]);
        $documentos = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $stmt3 = $pdo->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen_actividades WHERE id_habilitacion = ?");
        $stmt3->execute([$habilitacion['id']]);
        $actividades = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($empresaData) {
        // Si no existe habilitación, crear datos iniciales desde empresa
        $habilitacion = [
            'id' => null,
            'id_empresa' => $empresaData['id_empresa'],
            'empresa' => $empresaData['empresa'],
            'ruc' => $empresaData['ruc'] ?? '',
            'dv' => $empresaData['dv'] ?? '',
            'razon_social' => $empresaData['empresa'],
            'nombre_fantasia' => '',
            'estado_contribuyente' => 'ACTIVO',
            'representante_tipo_doc' => 'CI',
            'representante_documento' => '',
            'representante_nombre' => '',
            'cod_depto' => null,
            'departamento' => '',
            'cod_distrito' => null,
            'distrito' => '',
            'cod_ciudad' => null,
            'localidad' => '',
            'cod_barrio' => null,
            'barrio' => '',
            'direccion' => $empresaData['direccion'] ?? '',
            'numero_casa' => '',
            'telefono' => $empresaData['telefono'] ?? '',
            'email' => $empresaData['email'] ?? '',
            'numero_formulario' => '',
            'fecha_formulario' => '',
            'sistema_contribuyente' => 'SISTEMAX',
            'numero_timbrado' => $empresaData['timbrado'] ?? '',
            'fecha_inicio_vigencia' => '',
            'fecha_fin_vigencia' => '',
            'estado_timbrado' => 'ACTIVO',
            'csc' => '',
            'id_csc' => '0001',
            'cert_nombre' => '',
            'cert_pass' => '',
            'cert_fecha_vencimiento' => '',
            'ambiente' => 'TEST',
            'activo' => 1
        ];
    }
}

// Cargar empresas para autocomplete
$stmtEmpresas = $pdo->query("SELECT id_empresa, empresa, ruc, dv, timbrado FROM {$masterDb}.empresa WHERE activo = 1 ORDER BY empresa");
$empresas = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Habilitación SIFEN</title>
    <!-- Detectar tema ANTES de cargar estilos para evitar flash -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            // Si el tema guardado es 'dark', o si no hay tema guardado (null) y el sistema es oscuro
            // También aplicar dark si el tema es 'auto' y el sistema es oscuro
            if (savedTheme === 'dark' || ((!savedTheme || savedTheme === 'auto') && systemDark)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/5.0.4/tesseract.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        // Configurar worker de PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    </script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        .form-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
        }

        html.dark .form-container {
            background: rgba(15, 23, 42, 0.95);
        }

        .form-section {
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
        }

        html.dark .form-section {
            border-color: rgba(255, 255, 255, 0.1);
        }

        .form-section-title {
            font-size: 14px;
            font-weight: 600;
            color: #3b82f6;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.span-2 {
            grid-column: span 2;
        }

        .form-group.span-3 {
            grid-column: span 3;
        }

        .form-group.span-4,
        .form-group.full-width {
            grid-column: span 4;
        }

        .form-group label {
            font-size: 12px;
            font-weight: 500;
            color: #64748b;
            margin-bottom: 4px;
        }

        html.dark .form-group label {
            color: #94a3b8;
        }

        .form-group label.required::after {
            content: ' *';
            color: #ef4444;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
            color: #1e293b;
        }

        html.dark .form-group input,
        html.dark .form-group select,
        html.dark .form-group textarea {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .form-group input:disabled {
            background: #f1f5f9;
            cursor: not-allowed;
        }

        html.dark .form-group input:disabled {
            background: #0f172a;
        }

        /* Autocomplete */
        .autocomplete-container {
            position: relative;
        }

        .autocomplete-input {
            width: 100%;
            padding-right: 32px;
        }

        .autocomplete-clear {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px;
        }

        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            max-height: 250px;
            overflow-y: auto;
            z-index: 100;
        }

        html.dark .autocomplete-dropdown {
            background: #1e293b;
            border-color: #334155;
        }

        .autocomplete-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
        }

        html.dark .autocomplete-item {
            border-color: #334155;
        }

        .autocomplete-item:hover,
        .autocomplete-item.active {
            background: #f1f5f9;
        }

        html.dark .autocomplete-item:hover,
        html.dark .autocomplete-item.active {
            background: #334155;
        }

        .autocomplete-item-name {
            font-weight: 500;
            color: #1e293b;
        }

        html.dark .autocomplete-item-name {
            color: #f1f5f9;
        }

        .autocomplete-item-ruc {
            font-size: 11px;
            color: #64748b;
        }

        .autocomplete-no-results {
            padding: 16px;
            text-align: center;
            color: #94a3b8;
        }

        /* Phone container */
        .phone-container {
            display: flex;
            gap: 8px;
        }

        .phone-country-select {
            width: 110px;
            flex-shrink: 0;
        }

        .phone-number-input {
            flex: 1;
        }

        /* Botones */
        .btn {
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            border: 1px solid transparent;
            font-size: 13px;
            background: transparent;
        }

        .btn-primary {
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .btn-primary:hover {
            background: rgba(59, 130, 246, 0.08);
            transform: translateY(-1px);
        }

        .btn-success {
            border-color: #22c55e;
            color: #22c55e;
        }

        .btn-success:hover {
            background: rgba(34, 197, 94, 0.08);
            transform: translateY(-1px);
        }

        .btn-secondary {
            border-color: #64748b;
            color: #64748b;
        }

        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.08);
            transform: translateY(-1px);
        }

        .btn-danger {
            border-color: #ef4444;
            color: #ef4444;
        }

        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.08);
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* Tabla mini */
        .tabla-mini {
            overflow-x: auto;
        }

        .tabla-mini table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .tabla-mini th,
        .tabla-mini td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        html.dark .tabla-mini th,
        html.dark .tabla-mini td {
            border-color: #334155;
        }

        .tabla-mini th {
            background: #f8fafc;
            font-weight: 600;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
        }

        html.dark .tabla-mini th {
            background: #0f172a;
            color: #94a3b8;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
        }

        .badge-success {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.15);
            color: #2563eb;
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #d97706;
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #dc2626;
        }

        /* Alert Modal */
        .alert-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 9998;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .alert-modal {
            background: white;
            border-radius: 16px;
            padding: 28px 32px;
            min-width: 320px;
            max-width: 420px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            text-align: center;
        }

        .dark .alert-modal {
            background: #1e293b;
        }

        .alert-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .alert-icon-success {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .alert-icon-error {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }

        .alert-icon-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }

        .alert-icon-info {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .alert-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .dark .alert-title {
            color: #f1f5f9;
        }

        .alert-message {
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .dark .alert-message {
            color: #94a3b8;
        }

        .alert-btn {
            padding: 10px 32px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .alert-btn-success {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
        }

        .alert-btn-error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .alert-btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .alert-btn-info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .alert-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Header fijo */
        .page-header {
            position: sticky;
            top: 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 16px 24px;
            margin: -24px -24px 24px -24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 50;
        }

        html.dark .page-header {
            background: rgba(15, 23, 42, 0.95);
            border-color: #334155;
        }

        /* Scrollable content */
        .form-content {
            flex: 1;
            overflow-y: auto;
            padding-right: 8px;
        }

        .form-content::-webkit-scrollbar {
            width: 6px;
        }

        .form-content::-webkit-scrollbar-track {
            background: transparent;
        }

        .form-content::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        html.dark .form-content::-webkit-scrollbar-thumb {
            background: #475569;
        }
    </style>
</head>

<body class="min-h-screen bg-slate-100 dark:bg-slate-900 text-slate-900 dark:text-slate-100"
    x-data="habilitacionForm()"
    x-init="init()">

    <div class="form-container min-h-screen max-w-6xl mx-auto shadow-2xl flex flex-col" style="padding: 24px;">
        <!-- Header -->
        <div class="page-header">
            <div class="flex items-center gap-4">
                <button @click="volver()" class="text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                    <i class="fas fa-arrow-left text-xl"></i>
                </button>
                <div>
                    <h1 class="text-xl font-semibold text-slate-800 dark:text-white">
                        <i class="fas fa-file-invoice text-blue-500"></i>
                        <span x-text="formData.id ? 'Editar Habilitación SIFEN' : 'Nueva Habilitación SIFEN'"></span>
                    </h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400" x-show="formData.empresa" x-text="formData.empresa"></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button class="btn btn-success" @click="guardar()" :disabled="guardando">
                    <i class="fas fa-save"></i>
                    <span x-text="guardando ? 'Guardando...' : 'Guardar'"></span>
                </button>
                <button class="btn btn-danger" @click="volver()">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </button>
            </div>
        </div>

        <!-- Contenido del Formulario -->
        <div class="form-content">

            <!-- Formulario 364-3 -->
            <div class="form-section">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="form-section-title"><i class="fas fa-file-alt"></i> Formulario 364-3</h3>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="extraerConOCR()" :disabled="ocrProcessing" class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white rounded-lg transition" :class="ocrProcessing ? 'opacity-50 cursor-not-allowed' : ''">
                            <i :class="ocrProcessing ? 'fas fa-spinner fa-spin' : 'fas fa-image'"></i>
                            <span x-text="ocrProcessing ? 'Procesando OCR...' : 'OCR'"></span>
                        </button>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group"><label>Nº Formulario</label><input type="text" x-model="formData.numero_formulario" placeholder="364010024412"></div>
                    <div class="form-group"><label>Fecha</label><input type="date" x-model="formData.fecha_formulario"></div>
                </div>
            </div>

            <!-- Contribuyente -->
            <div class="form-section">
                <h3 class="form-section-title"><i class="fas fa-user-tie"></i> Contribuyente</h3>
                <div class="form-grid">
                    <div class="form-group"><label>RUC</label><input type="text" x-model="formData.ruc"></div>
                    <div class="form-group" style="display:flex;align-items:flex-end;">
                        <button type="button" class="btn btn-primary btn-sm" @click="buscarRucSifen()" title="Consultar RUC en SIFEN">
                            <i class="fas fa-search"></i> SIFEN
                        </button>
                    </div>
                    <div class="form-group"><label>DV</label><input type="number" x-model="formData.dv" min="0" max="9"></div>
                    <div class="form-group span-2"><label>Razón Social</label><input type="text" x-model="formData.razon_social"></div>
                    <div class="form-group span-2"><label>Nombre Fantasía</label><input type="text" x-model="formData.nombre_fantasia"></div>
                    <div class="form-group"><label>Estado</label>
                        <select x-model="formData.estado_contribuyente">
                            <option value="ACTIVO">Activo</option>
                            <option value="INACTIVO">Inactivo</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Representante -->
            <div class="form-section">
                <h3 class="form-section-title"><i class="fas fa-user-shield"></i> Representante Legal</h3>
                <div class="form-grid">
                    <div class="form-group"><label>Tipo Doc</label>
                        <select x-model="formData.representante_tipo_doc">
                            <option value="CI">CI</option>
                            <option value="RUC">RUC</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Documento</label><input type="text" x-model="formData.representante_documento"></div>
                    <div class="form-group span-2"><label>Nombre</label><input type="text" x-model="formData.representante_nombre"></div>
                </div>
            </div>

            <!-- Ubicación -->
            <div class="form-section">
                <h3 class="form-section-title"><i class="fas fa-map-marker-alt"></i> Ubicación</h3>
                <div class="form-grid">
                    <!-- Departamento -->
                    <div class="form-group">
                        <label>Departamento</label>
                        <div class="autocomplete-container">
                            <input type="text" class="autocomplete-input"
                                x-model="geoSearch.departamento"
                                @input="buscarGeo('departamento')"
                                @focus="buscarGeo('departamento')"
                                @keydown.arrow-down.prevent="navegarGeo('departamento', 1)"
                                @keydown.arrow-up.prevent="navegarGeo('departamento', -1)"
                                @keydown.enter.prevent="seleccionarGeoActivo('departamento')"
                                @keydown.escape="cerrarGeoDropdown('departamento')"
                                @click.outside="cerrarGeoDropdown('departamento')"
                                placeholder="Buscar departamento...">
                            <button type="button" class="autocomplete-clear" x-show="geoSearch.departamento" @click="limpiarGeo('departamento')">
                                <i class="fas fa-times"></i>
                            </button>
                            <div class="autocomplete-dropdown" x-show="showGeoDropdown.departamento && geoResultados.departamento.length > 0">
                                <template x-for="(item, idx) in geoResultados.departamento" :key="item.cod_depto">
                                    <div class="autocomplete-item" :class="{'active': geoActiveIndex.departamento === idx}" @click="seleccionarGeo('departamento', item)">
                                        <div class="autocomplete-item-name" x-html="resaltarCoincidencias(item.desc_depto, geoSearch.departamento)"></div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <!-- Distrito -->
                    <div class="form-group">
                        <label>Distrito</label>
                        <div class="autocomplete-container">
                            <input type="text" class="autocomplete-input"
                                x-model="geoSearch.distrito"
                                @input="buscarGeo('distrito')"
                                @focus="buscarGeo('distrito')"
                                @keydown.arrow-down.prevent="navegarGeo('distrito', 1)"
                                @keydown.arrow-up.prevent="navegarGeo('distrito', -1)"
                                @keydown.enter.prevent="seleccionarGeoActivo('distrito')"
                                @keydown.escape="cerrarGeoDropdown('distrito')"
                                @click.outside="cerrarGeoDropdown('distrito')"
                                placeholder="Buscar distrito..."
                                :disabled="!formData.cod_depto">
                            <button type="button" class="autocomplete-clear" x-show="geoSearch.distrito" @click="limpiarGeo('distrito')">
                                <i class="fas fa-times"></i>
                            </button>
                            <div class="autocomplete-dropdown" x-show="showGeoDropdown.distrito && geoResultados.distrito.length > 0">
                                <template x-for="(item, idx) in geoResultados.distrito" :key="item.cod_distrito">
                                    <div class="autocomplete-item" :class="{'active': geoActiveIndex.distrito === idx}" @click="seleccionarGeo('distrito', item)">
                                        <div class="autocomplete-item-name" x-html="resaltarCoincidencias(item.desc_distrito, geoSearch.distrito)"></div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <!-- Ciudad/Localidad -->
                    <div class="form-group">
                        <label>Ciudad</label>
                        <div class="autocomplete-container">
                            <input type="text" class="autocomplete-input"
                                x-model="geoSearch.ciudad"
                                @input="buscarGeo('ciudad')"
                                @focus="buscarGeo('ciudad')"
                                @keydown.arrow-down.prevent="navegarGeo('ciudad', 1)"
                                @keydown.arrow-up.prevent="navegarGeo('ciudad', -1)"
                                @keydown.enter.prevent="seleccionarGeoActivo('ciudad')"
                                @keydown.escape="cerrarGeoDropdown('ciudad')"
                                @click.outside="cerrarGeoDropdown('ciudad')"
                                placeholder="Buscar ciudad..."
                                :disabled="!formData.cod_distrito">
                            <button type="button" class="autocomplete-clear" x-show="geoSearch.ciudad" @click="limpiarGeo('ciudad')">
                                <i class="fas fa-times"></i>
                            </button>
                            <div class="autocomplete-dropdown" x-show="showGeoDropdown.ciudad && geoResultados.ciudad.length > 0">
                                <template x-for="(item, idx) in geoResultados.ciudad" :key="item.cod_ciudad">
                                    <div class="autocomplete-item" :class="{'active': geoActiveIndex.ciudad === idx}" @click="seleccionarGeo('ciudad', item)">
                                        <div class="autocomplete-item-name" x-html="resaltarCoincidencias(item.desc_ciudad, geoSearch.ciudad)"></div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <!-- Barrio -->
                    <div class="form-group">
                        <label>Barrio</label>
                        <div class="autocomplete-container">
                            <input type="text" class="autocomplete-input"
                                x-model="geoSearch.barrio"
                                @input="buscarGeo('barrio')"
                                @focus="buscarGeo('barrio')"
                                @keydown.arrow-down.prevent="navegarGeo('barrio', 1)"
                                @keydown.arrow-up.prevent="navegarGeo('barrio', -1)"
                                @keydown.enter.prevent="seleccionarGeoActivo('barrio')"
                                @keydown.escape="cerrarGeoDropdown('barrio')"
                                @click.outside="cerrarGeoDropdown('barrio')"
                                placeholder="Buscar barrio..."
                                :disabled="!formData.cod_ciudad">
                            <button type="button" class="autocomplete-clear" x-show="geoSearch.barrio" @click="limpiarGeo('barrio')">
                                <i class="fas fa-times"></i>
                            </button>
                            <div class="autocomplete-dropdown" x-show="showGeoDropdown.barrio && geoResultados.barrio.length > 0">
                                <template x-for="(item, idx) in geoResultados.barrio" :key="item.cod_barrio">
                                    <div class="autocomplete-item" :class="{'active': geoActiveIndex.barrio === idx}" @click="seleccionarGeo('barrio', item)">
                                        <div class="autocomplete-item-name" x-html="resaltarCoincidencias(item.desc_barrio, geoSearch.barrio)"></div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <!-- Dirección -->
                    <div class="form-group span-2">
                        <label>Dirección</label>
                        <input type="text" x-model="formData.direccion" placeholder="Ingrese la dirección...">
                    </div>
                    <div class="form-group"><label>Nº Casa</label><input type="text" x-model="formData.numero_casa"></div>
                    <!-- Teléfono con código de país -->
                    <div class="form-group">
                        <label>Teléfono</label>
                        <div class="phone-container">
                            <select class="phone-country-select" x-model="formData.telefono_pais">
                                <option value="+595">🇵🇾 +595</option>
                                <option value="+54">🇦🇷 +54</option>
                                <option value="+55">🇧🇷 +55</option>
                                <option value="+598">🇺🇾 +598</option>
                                <option value="+591">🇧🇴 +591</option>
                                <option value="+56">🇨🇱 +56</option>
                                <option value="+51">🇵🇪 +51</option>
                                <option value="+593">🇪🇨 +593</option>
                                <option value="+57">🇨🇴 +57</option>
                                <option value="+58">🇻🇪 +58</option>
                                <option value="+52">🇲🇽 +52</option>
                                <option value="+1">🇺🇸 +1</option>
                                <option value="+34">🇪🇸 +34</option>
                            </select>
                            <input type="tel" class="phone-number-input"
                                x-model="formData.telefono_numero"
                                placeholder="981 123456"
                                @input="actualizarTelefono()">
                        </div>
                    </div>
                    <div class="form-group span-2"><label>Email</label><input type="email" x-model="formData.email"></div>
                </div>
            </div>

            <!-- Timbrado y SIFEN -->
            <div class="form-section">
                <h3 class="form-section-title"><i class="fas fa-stamp"></i> Timbrado y SIFEN</h3>
                <div class="form-grid">
                    <div class="form-group"><label>Nº Timbrado</label><input type="text" x-model="formData.numero_timbrado"></div>
                    <div class="form-group"><label>Estado</label>
                        <select x-model="formData.estado_timbrado">
                            <option value="ACTIVO">Activo</option>
                            <option value="INACTIVO">Inactivo</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Inicio Vigencia</label><input type="date" x-model="formData.fecha_inicio_vigencia"></div>
                    <div class="form-group"><label>Fin Vigencia</label><input type="date" x-model="formData.fecha_fin_vigencia"></div>
                    <div class="form-group"><label>Ambiente</label>
                        <select x-model="formData.ambiente">
                            <option value="TEST">Pruebas</option>
                            <option value="PROD">Producción</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Sistema</label><input type="text" x-model="formData.sistema_contribuyente" value="SISTEMAX"></div>
                </div>
            </div>

            <!-- CSC y Certificado -->
            <div class="form-section">
                <h3 class="form-section-title"><i class="fas fa-key"></i> Seguridad</h3>
                <div class="form-grid">
                    <div class="form-group"><label>ID CSC</label><input type="text" x-model="formData.id_csc" placeholder="0001"></div>
                    <div class="form-group span-2"><label>CSC</label><input type="text" x-model="formData.csc"></div>
                    <div class="form-group">
                        <label>Cargar CSC</label>
                        <label class="btn btn-info btn-sm" style="cursor:pointer;margin:0;display:inline-flex;align-items:center;gap:6px;" :class="{'opacity-50': cscProcessing}">
                            <i class="fas fa-file-pdf" x-show="!cscProcessing"></i>
                            <i class="fas fa-spinner fa-spin" x-show="cscProcessing"></i>
                            <span x-text="cscProcessing ? 'Procesando...' : 'PDF CSC'"></span>
                            <input type="file" accept=".pdf,image/*" @change="procesarPDFCSC($event)" style="display:none;" :disabled="cscProcessing">
                        </label>
                    </div>
                    <div class="form-group span-2">
                        <label>Certificado (.p12)</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="text" x-model="formData.cert_nombre" placeholder="archivo.p12" style="flex:1;" readonly>
                            <label class="btn btn-primary btn-sm" style="cursor:pointer;margin:0;">
                                <i class="fas fa-upload"></i> Subir
                                <input type="file" accept=".p12,.pfx" @change="subirCertificado($event)" style="display:none;">
                            </label>
                            <button type="button" class="btn btn-secondary btn-sm" @click="seleccionarCertificado()">
                                <i class="fas fa-folder-open"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group" x-data="{ showPass: false }">
                        <label>Contraseña</label>
                        <div style="position:relative;">
                            <input :type="showPass ? 'text' : 'password'" x-model="formData.cert_pass" style="width:100%;padding-right:35px;">
                            <button type="button" @click="showPass = !showPass" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;padding:4px;">
                                <i :class="showPass ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
                            </button>
                        </div>
                        <div style="margin-top:8px;">
                            <button type="button" class="btn btn-secondary btn-sm" @click="validarCertPass()">
                                <i class="fas fa-check-circle"></i> Validar contraseña
                            </button>
                        </div>
                    </div>
                    <div class="form-group"><label>Vencimiento</label><input type="date" x-model="formData.cert_fecha_vencimiento"></div>
                </div>
            </div>

            <!-- Documentos Habilitados -->
            <div class="form-section" x-show="formData.id || documentosLista.length > 0">
                <h3 class="form-section-title">
                    <i class="fas fa-file-invoice"></i> Documentos Habilitados
                    <button type="button" class="btn btn-success btn-sm" style="margin-left:auto;" @click="agregarDocumento()">
                        <i class="fas fa-plus"></i> Agregar
                    </button>
                </h3>
                <div class="tabla-mini">
                    <table>
                        <thead>
                            <tr>
                                <th>Tipo Documento</th>
                                <th>Establecimiento</th>
                                <th>Punto Exp.</th>
                                <th style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="doc in documentosLista" :key="doc.id">
                                <tr>
                                    <td x-text="doc.tipo_documento_nombre"></td>
                                    <td x-text="doc.codigo_establecimiento"></td>
                                    <td x-text="doc.punto_expedicion"></td>
                                    <td>
                                        <button type="button" class="btn btn-danger btn-sm" @click="eliminarDocumento(doc.id)" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="documentosLista.length === 0">
                                <td colspan="4" style="text-align:center;color:#64748b;">Sin documentos habilitados</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <!-- Form agregar documento -->
                <div x-show="showAddDocumento" style="margin-top:16px;padding:16px;background:rgba(59,130,246,0.05);border-radius:8px;">
                    <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr auto;">
                        <div class="form-group">
                            <label>Tipo Documento</label>
                            <select x-model="nuevoDocumento.tipo_documento">
                                <template x-for="(nombre, tipo) in tiposDocumento" :key="tipo">
                                    <option :value="tipo" x-text="nombre"></option>
                                </template>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Establecimiento</label>
                            <input type="text" x-model="nuevoDocumento.codigo_establecimiento" placeholder="001" maxlength="3">
                        </div>
                        <div class="form-group">
                            <label>Punto Exp.</label>
                            <input type="text" x-model="nuevoDocumento.punto_expedicion" placeholder="001" maxlength="3">
                        </div>
                        <div class="form-group" style="justify-content:flex-end;">
                            <label>&nbsp;</label>
                            <div style="display:flex;gap:6px;">
                                <button type="button" class="btn btn-success btn-sm" @click="guardarDocumento()"><i class="fas fa-check"></i></button>
                                <button type="button" class="btn btn-secondary btn-sm" @click="showAddDocumento=false"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actividades Económicas -->
            <div class="form-section" x-show="formData.id || actividadesLista.length > 0">
                <h3 class="form-section-title">
                    <i class="fas fa-briefcase"></i> Actividades Económicas
                    <button type="button" class="btn btn-success btn-sm" style="margin-left:auto;" @click="agregarActividad()">
                        <i class="fas fa-plus"></i> Agregar
                    </button>
                </h3>
                <div class="tabla-mini">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:100px;">Código</th>
                                <th>Descripción</th>
                                <th style="width:80px;">Principal</th>
                                <th style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="act in actividadesLista" :key="act.id">
                                <tr>
                                    <td x-text="act.codigo"></td>
                                    <td x-text="act.descripcion"></td>
                                    <td style="text-align:center;">
                                        <span x-show="act.principal == 's' || act.principal == 1" class="badge badge-success">Sí</span>
                                        <span x-show="act.principal != 's' && act.principal != 1" class="badge badge-info">No</span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-danger btn-sm" @click="eliminarActividad(act.id)" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="actividadesLista.length === 0">
                                <td colspan="4" style="text-align:center;color:#64748b;">Sin actividades económicas</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <!-- Form agregar actividad -->
                <div x-show="showAddActividad" style="margin-top:16px;padding:16px;background:rgba(59,130,246,0.05);border-radius:8px;">
                    <div class="form-grid" style="grid-template-columns: 1fr 3fr auto auto;">
                        <div class="form-group">
                            <label>Código</label>
                            <input type="text" x-model="nuevaActividad.codigo" placeholder="47300" maxlength="20">
                        </div>
                        <div class="form-group">
                            <label>Descripción</label>
                            <input type="text" x-model="nuevaActividad.descripcion" placeholder="Descripción de la actividad">
                        </div>
                        <div class="form-group">
                            <label>Principal</label>
                            <select x-model="nuevaActividad.principal">
                                <option value="0">No</option>
                                <option value="1">Sí</option>
                            </select>
                        </div>
                        <div class="form-group" style="justify-content:flex-end;">
                            <label>&nbsp;</label>
                            <div style="display:flex;gap:6px;">
                                <button type="button" class="btn btn-success btn-sm" @click="guardarActividad()"><i class="fas fa-check"></i></button>
                                <button type="button" class="btn btn-secondary btn-sm" @click="showAddActividad=false"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Modal -->
    <div x-show="alert.show"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="alert-overlay"
        @keydown.escape.window="alert.show = false">
        <div x-show="alert.show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 transform scale-95"
            x-transition:enter-end="opacity-100 transform scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 transform scale-100"
            x-transition:leave-end="opacity-0 transform scale-95"
            class="alert-modal"
            @click.stop>
            <!-- Icono -->
            <div :class="'alert-icon alert-icon-' + alert.type">
                <!-- Success -->
                <template x-if="alert.type === 'success'">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </template>
                <!-- Error -->
                <template x-if="alert.type === 'error'">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </template>
                <!-- Warning -->
                <template x-if="alert.type === 'warning'">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </template>
                <!-- Info -->
                <template x-if="alert.type === 'info'">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </template>
            </div>
            <!-- Título -->
            <div class="alert-title" x-text="alert.title"></div>
            <!-- Mensaje -->
            <div class="alert-message" x-text="alert.message"></div>
            <!-- Botón -->
            <button :class="'alert-btn alert-btn-' + alert.type" @click="alert.show = false" x-ref="alertBtn">
                Aceptar
            </button>
        </div>
    </div>

    <!-- Modal Certificado Validado -->
    <div x-show="certModal.show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[9999] flex items-center justify-center p-4"
        @click="certModal.show = false"
        @keydown.escape.window="certModal.show = false">
        <div x-show="certModal.show"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-90"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-90"
            class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-md w-full overflow-hidden"
            @click.stop>
            <!-- Header con icono -->
            <div class="bg-gradient-to-r from-emerald-500 to-green-600 px-6 py-5 text-center">
                <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-3">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                </div>
                <h3 class="text-xl font-semibold text-white">Certificado Válido</h3>
                <p class="text-emerald-100 text-sm mt-1" x-show="certModal.legacy">Modo Legacy Detectado</p>
            </div>
            <!-- Contenido -->
            <div class="p-6 space-y-4">
                <!-- Nombre del archivo -->
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Archivo</p>
                        <p class="text-sm font-semibold text-slate-800 dark:text-slate-200 break-all" x-text="certModal.nombre"></p>
                    </div>
                </div>
                <!-- Ubicación -->
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Ubicación</p>
                        <p class="text-xs text-slate-600 dark:text-slate-300 break-all font-mono bg-slate-100 dark:bg-slate-700/50 px-2 py-1 rounded mt-1" x-text="certModal.ubicacion"></p>
                    </div>
                </div>
                <!-- Contraseña -->
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 bg-amber-100 dark:bg-amber-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Contraseña</p>
                        <p class="text-sm font-mono text-slate-800 dark:text-slate-200" x-text="certModal.password"></p>
                    </div>
                </div>
            </div>
            <!-- Footer -->
            <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/50 flex justify-end">
                <button @click="certModal.show = false"
                    class="px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-green-600 text-white font-medium rounded-xl hover:from-emerald-600 hover:to-green-700 transition-all duration-200 shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/40">
                    Aceptar
                </button>
            </div>
        </div>
    </div>

    <script>
        const API_URL = `${window.location.origin}/admin_empresa/habilitacion_sifen.php`;
        const ID_EMPRESA_URL = <?php echo (int)$id_empresa; ?>;
        const DATOS_INICIALES = <?php echo json_encode($habilitacion ?: null); ?>;
        const DOCUMENTOS_INICIALES = <?php echo json_encode($documentos ?: []); ?>;
        const ACTIVIDADES_INICIALES = <?php echo json_encode($actividades ?: []); ?>;

        function habilitacionForm() {
            return {
                empresas: <?php echo json_encode($empresas); ?>,
                guardando: false,

                alert: {
                    show: false,
                    type: 'success',
                    title: '',
                    message: ''
                },

                // Modal de certificado validado
                certModal: {
                    show: false,
                    nombre: '',
                    ubicacion: '',
                    password: '',
                    legacy: false
                },

                tiposDocumento: {
                    1: 'Factura Electrónica',
                    4: 'Autofactura Electrónica',
                    5: 'Nota de Crédito Electrónica',
                    6: 'Nota de Débito Electrónica',
                    7: 'Nota de Remisión Electrónica'
                },

                documentosSeleccionados: [1, 5, 6, 7],
                documentosLista: [],
                mostrarDocumentosCargados: false,
                actividadesLista: [],
                showAddDocumento: false,
                showAddActividad: false,
                nuevoDocumento: {
                    tipo_documento: 1,
                    codigo_establecimiento: '001',
                    punto_expedicion: '001'
                },
                nuevaActividad: {
                    codigo: '',
                    descripcion: '',
                    principal: 0
                },
                formData: {},

                // Autocomplete Empresa
                empresaSearch: '',
                empresasFiltradas: [],
                showEmpresaDropdown: false,
                empresaActiveIndex: 0,

                // Autocomplete Geográfico
                geoSearch: {
                    departamento: '',
                    distrito: '',
                    ciudad: '',
                    barrio: ''
                },
                geoResultados: {
                    departamento: [],
                    distrito: [],
                    ciudad: [],
                    barrio: []
                },
                showGeoDropdown: {
                    departamento: false,
                    distrito: false,
                    ciudad: false,
                    barrio: false
                },
                geoActiveIndex: {
                    departamento: 0,
                    distrito: 0,
                    ciudad: 0,
                    barrio: 0
                },

                // OCR
                ocrProcessing: false,
                cscProcessing: false,

                async init() {
                    this.resetFormData();
                    this.initBackground();

                    // Cargar datos iniciales desde PHP (ya cargados en el servidor)
                    if (DATOS_INICIALES) {
                        this.formData = {
                            ...this.formData,
                            ...DATOS_INICIALES
                        };
                        this.documentosLista = DOCUMENTOS_INICIALES || [];
                        this.documentosSeleccionados = this.documentosLista.map(d => parseInt(d.tipo_documento));
                        this.actividadesLista = ACTIVIDADES_INICIALES || [];
                        this.initGeoSearch();
                        this.parsearTelefono();
                        this.empresaSearch = DATOS_INICIALES.empresa || DATOS_INICIALES.nombre_empresa || '';
                    } else if (ID_EMPRESA_URL > 0) {
                        // Fallback: cargar desde API si no hay datos iniciales
                        await this.cargarHabilitacion();
                    }
                },

                initBackground() {
                    const tema = localStorage.getItem('theme');
                    const urlImagenO = localStorage.getItem('urlImagenOscuro') || '';
                    const urlImagenC = localStorage.getItem('urlImagenClaro') || '';

                    if (tema === 'dark' && urlImagenO) {
                        document.body.style.backgroundImage = 'url(' + urlImagenO + ')';
                    } else if (urlImagenC) {
                        document.body.style.backgroundImage = 'url(' + urlImagenC + ')';
                    }
                },

                resetFormData() {
                    this.formData = {
                        id: null,
                        id_empresa: '',
                        empresa: '',
                        numero_formulario: '',
                        fecha_formulario: '',
                        ruc: '',
                        dv: '',
                        razon_social: '',
                        nombre_fantasia: '',
                        estado_contribuyente: 'ACTIVO',
                        representante_tipo_doc: 'CI',
                        representante_documento: '',
                        representante_nombre: '',
                        cod_depto: null,
                        departamento: '',
                        cod_distrito: null,
                        distrito: '',
                        cod_ciudad: null,
                        localidad: '',
                        cod_barrio: null,
                        barrio: '',
                        direccion: '',
                        numero_casa: '',
                        telefono: '',
                        telefono_pais: '+595',
                        telefono_numero: '',
                        email: '',
                        sistema_contribuyente: 'SISTEMAX',
                        numero_timbrado: '',
                        fecha_inicio_vigencia: '',
                        fecha_fin_vigencia: '',
                        estado_timbrado: 'ACTIVO',
                        csc: '',
                        id_csc: '0001',
                        cert_nombre: '',
                        cert_pass: '',
                        cert_fecha_vencimiento: '',
                        ambiente: 'TEST',
                        activo: 1
                    };
                },

                async cargarHabilitacion() {
                    try {
                        const res = await fetch(`${API_URL}?action=get_by_empresa&id_empresa=${ID_EMPRESA_URL}`);
                        const data = await res.json();
                        if (data.success && data.data) {
                            this.formData = {
                                ...data.data
                            };
                            this.documentosSeleccionados = (data.data.documentos || []).map(d => parseInt(d.tipo_documento));
                            this.documentosLista = data.data.documentos || [];
                            await this.cargarActividades(data.data.id);
                            this.initGeoSearch();
                            this.parsearTelefono();
                            this.empresaSearch = data.data.empresa || data.data.nombre_empresa || '';
                        }
                    } catch (e) {
                        console.error('Error cargando habilitación:', e);
                    }
                },

                async cargarDocumentos(id) {
                    const res = await fetch(`${API_URL}?action=documentos&id=${id}`);
                    const data = await res.json();
                    if (data.success) this.documentosLista = data.data;
                },

                async cargarActividades(id) {
                    const res = await fetch(`${API_URL}?action=actividades&id=${id}`);
                    const data = await res.json();
                    if (data.success) this.actividadesLista = data.data;
                },

                showToast(message, type = 'success') {
                    const titles = {
                        'success': '¡Éxito!',
                        'error': 'Error',
                        'warning': 'Atención',
                        'info': 'Información'
                    };
                    this.alert = {
                        show: true,
                        type,
                        title: titles[type] || 'Aviso',
                        message
                    };
                    this.$nextTick(() => {
                        if (this.$refs.alertBtn) this.$refs.alertBtn.focus();
                    });
                },

                volver() {
                    try {
                        if (window.parent && window.parent !== window && typeof window.parent.cerrarApp === 'function') {
                            window.parent.cerrarApp();
                            setTimeout(() => {
                                try {
                                    if (window.top && window.top.location) {
                                        window.top.location.href = '/public/menu/menu.php';
                                    }
                                } catch (e) {
                                    window.location.href = '/public/menu/menu.php';
                                }
                            }, 500);
                            return;
                        }
                    } catch (e) {}
                    try {
                        if (window.top && window.top !== window) {
                            window.top.location.href = '/public/menu/menu.php';
                            return;
                        }
                    } catch (e) {}
                    window.location.href = '/public/menu/menu.php';
                },

                // Limpiar Formulario
                limpiarFormulario() {
                    if (confirm('¿Está seguro de que desea limpiar todos los campos?')) {
                        this.resetFormData();
                        this.documentosLista = [];
                        this.mostrarDocumentosCargados = false;
                        alert('Formulario limpiado correctamente');
                    }
                },

                // OCR
                async extraerConOCR() {
                    this.ocrProcessing = true;
                    try {
                        const input = document.createElement('input');
                        input.type = 'file';
                        input.accept = 'image/*,application/pdf';
                        input.onchange = async (e) => {
                            const file = e.target.files[0];
                            if (!file) {
                                this.ocrProcessing = false;
                                return;
                            }

                            const reader = new FileReader();
                            reader.onload = async (event) => {
                                try {
                                    const isMime = file.type.includes('pdf');
                                    if (isMime) {
                                        await this.procesarPDF(event.target.result);
                                    } else {
                                        const result = await Tesseract.recognize(event.target.result, 'spa+eng', {
                                            logger: m => console.log('OCR Progress:', m)
                                        });
                                        const text = result.data.text;
                                        await this.extraerDatosDelOCR(text);
                                    }
                                } catch (err) {
                                    console.error('Error OCR:', err);
                                    alert('Error procesando archivo: ' + err.message);
                                } finally {
                                    this.ocrProcessing = false;
                                }
                            };
                            if (file.type.includes('pdf')) {
                                reader.readAsArrayBuffer(file);
                            } else {
                                reader.readAsDataURL(file);
                            }
                        };
                        input.click();
                    } catch (e) {
                        console.error('Error:', e);
                        alert('Error abriendo selector de archivo');
                        this.ocrProcessing = false;
                    }
                },

                async procesarPDF(arrayBuffer) {
                    const pdf = await pdfjsLib.getDocument(arrayBuffer).promise;
                    let textoCompleto = '';

                    for (let pageNum = 1; pageNum <= Math.min(pdf.numPages, 5); pageNum++) {
                        const page = await pdf.getPage(pageNum);
                        const textContent = await page.getTextContent();
                        const pageText = textContent.items.map(item => item.str).join(' ');
                        textoCompleto += pageText + '\n';
                    }

                    await this.extraerDatosDelOCR(textoCompleto);
                },

                // Procesar PDF del CSC
                async procesarPDFCSC(event) {
                    const file = event.target.files[0];
                    if (!file) return;

                    this.cscProcessing = true;

                    try {
                        const arrayBuffer = await file.arrayBuffer();
                        const isPDF = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');

                        let texto = '';

                        if (isPDF) {
                            // Procesar PDF
                            const pdf = await pdfjsLib.getDocument(arrayBuffer).promise;
                            for (let pageNum = 1; pageNum <= Math.min(pdf.numPages, 3); pageNum++) {
                                const page = await pdf.getPage(pageNum);
                                const textContent = await page.getTextContent();
                                const pageText = textContent.items.map(item => item.str).join(' ');
                                texto += pageText + '\n';
                            }
                        } else {
                            // Procesar imagen con OCR
                            const blob = new Blob([arrayBuffer], {
                                type: file.type
                            });
                            const imageUrl = URL.createObjectURL(blob);
                            const result = await Tesseract.recognize(imageUrl, 'spa+eng', {
                                logger: m => console.log('OCR CSC Progress:', m)
                            });
                            texto = result.data.text;
                            URL.revokeObjectURL(imageUrl);
                        }

                        // Extraer CSC del texto
                        this.extraerCSCDelTexto(texto);

                    } catch (err) {
                        console.error('Error procesando PDF CSC:', err);
                        alert('Error al procesar el archivo CSC: ' + err.message);
                    } finally {
                        this.cscProcessing = false;
                        event.target.value = ''; // Limpiar input para permitir resubir
                    }
                },

                // Extraer ID CSC y CSC del texto
                extraerCSCDelTexto(texto) {
                    const textoUpper = texto.toUpperCase(); // Solo para búsqueda de etiquetas
                    const textoOriginal = texto; // Mantener original para el CSC
                    let datosExtraidos = {};

                    // Buscar ID CSC - patrones comunes: "ID CSC: 0001", "ID: 0001", "IDCSC: 0001"
                    const idCscMatch = textoUpper.match(/ID[\s_-]*(?:CSC)?[\s:]*(\d{1,4})/i);
                    if (idCscMatch) {
                        const idCsc = idCscMatch[1].padStart(4, '0');
                        this.formData.id_csc = idCsc;
                        datosExtraidos.id_csc = idCsc;
                    }

                    // Buscar CSC - código alfanumérico largo (32-44 caracteres)
                    // El CSC puede tener mayúsculas y minúsculas, buscar en texto original
                    const cscPatterns = [
                        /CSC[\s:]+([A-Fa-f0-9]{32,44})/i,
                        /CODIGO[\s_-]*(?:DE)?[\s_-]*SEGURIDAD[\s:]+([A-Fa-f0-9]{32,44})/i,
                        /CODIGO[\s_-]*SEGURIDAD[\s_-]*CONTRIBUYENTE[\s:]+([A-Fa-f0-9]{32,44})/i,
                        /([A-Fa-f0-9]{32,44})/ // Fallback: cualquier código hex largo (case sensitive)
                    ];

                    for (let pattern of cscPatterns) {
                        const cscMatch = textoOriginal.match(pattern);
                        if (cscMatch && cscMatch[1]) {
                            this.formData.csc = cscMatch[1]; // Mantener mayúsculas/minúsculas originales
                            datosExtraidos.csc = cscMatch[1];
                            break;
                        }
                    }

                    // Mostrar resultado
                    if (Object.keys(datosExtraidos).length > 0) {
                        let mensaje = 'Datos CSC extraídos:\n';
                        if (datosExtraidos.id_csc) mensaje += `• ID CSC: ${datosExtraidos.id_csc}\n`;
                        if (datosExtraidos.csc) mensaje += `• CSC: ${datosExtraidos.csc.substring(0, 20)}...`;
                        this.showToast(mensaje.replace(/\n/g, ' | '), 'success');
                    } else {
                        this.showToast('No se encontró CSC en el documento', 'warning');
                    }

                    console.log('CSC extraído:', datosExtraidos);
                },

                extraerActividades(texto) {
                    // Buscar actividades económicas en el PDF
                    // Patrón: buscar líneas que contengan números de 6 dígitos seguidos de descripción
                    const actividades = [];
                    const lineas = texto.split('\n');

                    let enSeccionActividades = false;
                    let codigoAnterior = null;

                    // Buscar sección de actividades económicas
                    for (let i = 0; i < lineas.length; i++) {
                        const linea = lineas[i].trim();

                        // Detectar inicio de sección de actividades
                        if (linea.match(/ACTIVIDAD|ACTIVIDADES|CODIGO\s*ACTIVIDAD/i)) {
                            enSeccionActividades = true;
                            continue;
                        }

                        // Fin de sección (si encuentra otras secciones conocidas)
                        if (enSeccionActividades && linea.match(/DOCUMENTO|CERTIFICADO|TOTAL|FIRMA|FECHA|SELLO/i)) {
                            break;
                        }

                        if (enSeccionActividades && linea.length > 0) {
                            const match = linea.match(/^(\d{6})[\s\-]+(.+?)$/);
                            if (match) {
                                const codigo = match[1];
                                const descripcion = match[2].trim();

                                if (descripcion.length > 3 && !descripcion.match(/^[\d\s]+$/)) {
                                    actividades.push({
                                        id: null,
                                        id_habilitacion: null,
                                        codigo: codigo,
                                        descripcion: descripcion.substring(0, 100),
                                        principal: actividades.length === 0 ? 's' : '' // Primera actividad como principal
                                    });
                                    codigoAnterior = codigo;
                                }
                            }
                        }
                    }

                    // Si no encontró con patrón de sección, buscar patrones individuales de códigos
                    if (actividades.length === 0) {
                        const codigosEncontrados = new Set();
                        const regex = /(\d{6})[\s\-]+([A-ZÁ-Ÿ][^\\n]{3,100}?)(?=\d{6}|$)/gim;
                        let match;

                        while ((match = regex.exec(texto)) !== null) {
                            const codigo = match[1];
                            const descripcion = match[2].trim();

                            // Evitar duplicados
                            if (!codigosEncontrados.has(codigo) && descripcion.length > 3) {
                                codigosEncontrados.add(codigo);
                                actividades.push({
                                    id: null,
                                    id_habilitacion: null,
                                    codigo: codigo,
                                    descripcion: descripcion.substring(0, 100),
                                    principal: actividades.length === 0 ? 's' : ''
                                });
                            }
                        }
                    }

                    console.log('Actividades extraídas del OCR:', actividades);
                    return actividades;
                },

                async extraerDatosDelOCR(texto) {
                    texto = texto.toUpperCase();
                    let datosExtraidos = {};

                    // Buscar número de formulario (364010XXXXXX)
                    const formularioMatch = texto.match(/364\s*01\s*0\s*0?(\d{4,6})/);
                    if (formularioMatch) {
                        const numeroFormulario = formularioMatch[0].replace(/\s/g, '');
                        this.formData.numero_formulario = numeroFormulario;
                        datosExtraidos.numero_formulario = numeroFormulario;
                    }

                    // Buscar fecha (DD/MM/YYYY o DD-MM-YYYY)
                    const fechaMatch = texto.match(/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/);
                    if (fechaMatch) {
                        const dia = fechaMatch[1].padStart(2, '0');
                        const mes = fechaMatch[2].padStart(2, '0');
                        const año = fechaMatch[3];
                        const fecha = `${año}-${mes}-${dia}`;
                        this.formData.fecha_formulario = fecha;
                        datosExtraidos.fecha_formulario = fecha;
                    }

                    // Buscar RUC y DV - primero buscar después de etiqueta "RUC"
                    let ruc = '';
                    let dv = '';

                    // Patrón 1: "RUC: 80118689" con DV opcional
                    const rucLabelMatch = texto.match(/RUC[\s:]+(\d{6,8})(?:[\s\-:]+DV[\s:]*(\d))?/i);
                    if (rucLabelMatch) {
                        ruc = rucLabelMatch[1];
                        if (rucLabelMatch[2]) {
                            dv = rucLabelMatch[2];
                        }
                    }

                    // Patrón 2: "RUC: 80118689    DV: 7" (SET format)
                    if (!dv) {
                        const dvLabelMatch = texto.match(/DV[\s:]+(\d)/i);
                        if (dvLabelMatch) {
                            dv = dvLabelMatch[1];
                        }
                    }

                    // Patrón 3: Si no encontró con etiqueta, buscar cerca de "DATOS DEL CONTRIBUYENTE"
                    if (!ruc) {
                        const contribuyenteMatch = texto.match(/DATOS\s+DEL\s+CONTRIBUYENTE[\s\S]{0,50}?(\d{6,8})/i);
                        if (contribuyenteMatch) {
                            ruc = contribuyenteMatch[1];
                        }
                    }

                    // Patrón 4: Fallback - buscar 8 dígitos que NO sean parte de teléfono
                    if (!ruc) {
                        // Excluir patrones de teléfono (09XX, 021, etc)
                        const allNumbers = texto.match(/\b(\d{6,8})\b/g) || [];
                        for (let num of allNumbers) {
                            // Excluir números de teléfono (empiezan con 09, 021)
                            if (!num.match(/^0[9234]|^02[123]/)) {
                                ruc = num;
                                break;
                            }
                        }
                    }

                    if (ruc) {
                        this.formData.ruc = ruc;
                        datosExtraidos.ruc = ruc;
                    }
                    if (dv) {
                        this.formData.dv = dv;
                        datosExtraidos.dv = dv;
                    }

                    // Buscar Razón Social (línea después de "Razón Social" o campo con mayúsculas)
                    const razonMatch = texto.match(/RAZON\s*SOCIAL[:\s]*([\w\s\-\.&Á-ÿ]+?)(?=\n|DV|ESTADO|$)/i);
                    if (razonMatch) {
                        const razon = razonMatch[1].trim().substring(0, 100);
                        if (razon && razon.length > 3) {
                            this.formData.razon_social = razon;
                            datosExtraidos.razon_social = razon;
                            // Asignar también como nombre fantasía por defecto
                            this.formData.nombre_fantasia = razon;
                            datosExtraidos.nombre_fantasia = razon;
                        }
                    }

                    // Buscar Nombre Fantasía (si existe, sobreescribe el valor de razón social)
                    const fantasiaMatch = texto.match(/NOMBRE\s*FANTASIA[:\s]*([\w\s\-\.&Á-ÿ]+?)(?=\n|ESTADO|DEPARTAMENTO|$)/i);
                    if (fantasiaMatch) {
                        const fantasia = fantasiaMatch[1].trim().substring(0, 100);
                        if (fantasia && fantasia.length > 3) {
                            this.formData.nombre_fantasia = fantasia;
                            datosExtraidos.nombre_fantasia = fantasia;
                        }
                    }

                    // Buscar Número de Timbrado - formato SET: "Timbrado: 12345678" o "Nº Timbrado 12345678"
                    const timbradoMatch = texto.match(/(?:TIMBRADO|N[^ÚMERO]*TIMBRADO)[:\s]*(\d{7,15})/i);
                    if (timbradoMatch) {
                        this.formData.numero_timbrado = timbradoMatch[1];
                        datosExtraidos.numero_timbrado = timbradoMatch[1];
                    }

                    // ===== DATOS DE UBICACIÓN (formato SET) =====
                    // Buscar sección "DATOS DE UBICACION"
                    const seccionUbicacion = texto.match(/DATOS\s+DE\s+UBICACION([\s\S]*?)(?:REPRESENTANTE|ACTIVIDADES|$)/i);

                    // Buscar Departamento - formato SET: "Departamento ALTO PARANA"
                    let departamentoExtraido = '';
                    const deptoMatch = texto.match(/DEPARTAMENTO[\s:]+([A-ZÁ-Ÿ\s]+?)(?:\s{2,}|DISTRITO|LOCALIDAD|\n|$)/i);
                    if (deptoMatch && deptoMatch[1]) {
                        departamentoExtraido = deptoMatch[1].trim();
                        datosExtraidos.departamento_texto = departamentoExtraido;
                    }

                    // Buscar Distrito - formato SET: "Distrito HERNANDARIAS"
                    let distritoExtraido = '';
                    const distritoMatch = texto.match(/DISTRITO[\s:]+([A-ZÁ-Ÿ\s]+?)(?:\s{2,}|LOCALIDAD|BARRIO|\n|$)/i);
                    if (distritoMatch && distritoMatch[1]) {
                        distritoExtraido = distritoMatch[1].trim();
                        datosExtraidos.distrito_texto = distritoExtraido;
                    }

                    // Buscar Localidad/Ciudad - formato SET: "Localidad HERNANDARIAS"
                    let localidadExtraida = '';
                    const localidadMatch = texto.match(/LOCALIDAD[\s:]+([A-ZÁ-Ÿ\s]+?)(?:\s{2,}|BARRIO|DOMICILIO|\n|$)/i);
                    if (localidadMatch && localidadMatch[1]) {
                        localidadExtraida = localidadMatch[1].trim();
                        datosExtraidos.localidad_texto = localidadExtraida;
                    }

                    // Buscar Barrio - formato SET: "Barrio XXXXX"
                    let barrioExtraido = '';
                    const barrioMatch = texto.match(/BARRIO[\s:]+([A-ZÁ-Ÿ\s]+?)(?:\s{2,}|DOMICILIO|TELEFONO|\n|$)/i);
                    if (barrioMatch && barrioMatch[1]) {
                        barrioExtraido = barrioMatch[1].trim();
                        datosExtraidos.barrio_texto = barrioExtraido;
                    }

                    // Buscar Domicilio/Dirección - formato SET: "Domicilio PANCHITO LOPEZ CASI VILLARICA"
                    const domicilioMatch = texto.match(/DOMICILIO[\s:]+([A-ZÁ-Ÿa-zá-ÿ0-9\s\-\.,ºª#]+?)(?:\s{2,}|TELEFONO|CORREO|\n|$)/i);
                    if (domicilioMatch && domicilioMatch[1]) {
                        const domicilio = domicilioMatch[1].trim().substring(0, 150);
                        if (domicilio && domicilio.length > 3) {
                            this.formData.direccion = domicilio;
                            datosExtraidos.direccion = domicilio;
                        }
                    }

                    // Fallback: Buscar Dirección con otro patrón
                    if (!datosExtraidos.direccion) {
                        const direccionMatch = texto.match(/DIRECCION[\s:]*([A-ZÁ-Ÿa-zá-ÿ0-9\s\-\.,ºª#]+?)(?=\n|TELEFO|EMAIL|$)/i);
                        if (direccionMatch) {
                            const direccion = direccionMatch[1].trim().substring(0, 150);
                            if (direccion && direccion.length > 3) {
                                this.formData.direccion = direccion;
                                datosExtraidos.direccion = direccion;
                            }
                        }
                    }

                    // Buscar Teléfono - formato SET: "Telefono (0983)657691"
                    let telefonoExtraido = '';
                    const telefonoSetMatch = texto.match(/TELEFONO[\s:]*\(?(\d{3,4})\)?[\s\-]?(\d{6,7})/i);
                    if (telefonoSetMatch) {
                        telefonoExtraido = telefonoSetMatch[1] + telefonoSetMatch[2];
                        this.formData.telefono_numero = telefonoExtraido;
                        datosExtraidos.telefono = telefonoExtraido;
                    } else {
                        // Fallback: formato genérico
                        const telefonoMatch = texto.match(/(?:TEL|TELEFO|TELEFONO)[:\s]*(\d{7,}|\d{2,3}[-.]\d{3,4}[-.]\d{3,4})/i);
                        if (telefonoMatch) {
                            telefonoExtraido = telefonoMatch[1].replace(/[\s\-\.]/g, '');
                            this.formData.telefono_numero = telefonoExtraido;
                            datosExtraidos.telefono = telefonoExtraido;
                        }
                    }

                    // Buscar Correo Electrónico - formato SET: "Correo Electronico FABIO@SISTEMAX.COM.PY"
                    const correoSetMatch = texto.match(/CORREO[\s]*(?:ELECTRONICO)?[\s:]*([A-Z0-9._%-]+@[A-Z0-9.-]+\.[A-Z]{2,})/i);
                    if (correoSetMatch) {
                        this.formData.email = correoSetMatch[1];
                        datosExtraidos.email = correoSetMatch[1];
                    } else {
                        // Fallback: buscar email genérico
                        const emailMatch = texto.match(/([a-zA-Z0-9._%-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/);
                        if (emailMatch) {
                            this.formData.email = emailMatch[1];
                            datosExtraidos.email = emailMatch[1];
                        }
                    }

                    // Autocompletar datos geográficos desde la base de datos
                    if (departamentoExtraido || distritoExtraido || localidadExtraida) {
                        await this.autocompletarDatosGeograficos(departamentoExtraido, distritoExtraido, localidadExtraida, barrioExtraido);
                    }

                    // Buscar Representante Legal - CI (múltiples estrategias)
                    let ciRepresentante = '';

                    // Estrategia 1: Formato SET - "CI: 5255712" dentro de sección REPRESENTANTE LEGAL
                    const ciSetMatch = texto.match(/REPRESENTANTE\s*LEGAL[\s\S]{0,50}?CI[\s:]+(\d{5,8})/i);
                    if (ciSetMatch) {
                        ciRepresentante = ciSetMatch[1];
                    }

                    // Estrategia 2: Buscar "CI:" seguido de números cerca de REPRESENTANTE
                    if (!ciRepresentante) {
                        const representanteCIMatch = texto.match(/REPRESENTANTE[:\s]*([A-Z\s]+)[:\s]*(\d{5,8}[-.]?\d{0,1})/i);
                        if (representanteCIMatch) {
                            ciRepresentante = representanteCIMatch[2].replace(/[\s\-\.]/g, '');
                        }
                    }

                    // Estrategia 3: Buscar solo números de CI (5-8 dígitos)
                    if (!ciRepresentante) {
                        // Buscar CI después de la sección REPRESENTANTE LEGAL y antes de ACTIVIDADES
                        const seccionRepresentante = texto.match(/REPRESENTANTE\s*LEGAL([\s\S]*?)(?:ACTIVIDADES|ECONOMICAS|TELEFONO|$)/i);
                        if (seccionRepresentante && seccionRepresentante[1]) {
                            const ciInSeccion = seccionRepresentante[1].match(/CI[\s:]+(\d{5,8})/i);
                            if (ciInSeccion) {
                                ciRepresentante = ciInSeccion[1];
                            }
                        }
                    }

                    if (ciRepresentante) {
                        this.formData.representante_documento = ciRepresentante;
                        datosExtraidos.representante_documento = ciRepresentante;
                    }

                    // Buscar nombre del Representante Legal (múltiples estrategias mejoradas)
                    let nombreRepresentante = '';

                    // Estrategia 1: Formato SET - "REPRESENTANTE LEGAL" seguido de línea "Nombre: XXXXX"
                    const nombreSetMatch = texto.match(/REPRESENTANTE\s*LEGAL[\s\S]{0,100}?NOMBRE[\s:]+([A-ZÁ-Ÿ][A-ZÁ-Ÿa-zá-ÿ\s\-']+?)(?:\s{2,}|\n|CI|CEDULA|TELEFONO|CORREO|\d{5,}|$)/i);
                    if (nombreSetMatch && nombreSetMatch[1]) {
                        nombreRepresentante = nombreSetMatch[1].trim();
                    }

                    // Estrategia 2: Buscar después de "REPRESENTANTE LEGAL" - excluir la palabra LEGAL del capturado
                    if (!nombreRepresentante || nombreRepresentante.length < 3) {
                        const nombreRepMatch1 = texto.match(/REPRESENTANTE\s+LEGAL\s*[:\s]*([A-ZÁ-Ÿ][A-ZÁ-Ÿa-zá-ÿ\s\-']+?)(?:\s{2,}|\n|CI|CEDULA|DOCUMENTO|C\.I\.|DNI|\d{5,}|$)/i);
                        if (nombreRepMatch1 && nombreRepMatch1[1]) {
                            let temp = nombreRepMatch1[1].trim();
                            // Excluir palabras que no son nombres
                            temp = temp.replace(/^(LEGAL|DOCUMENTO|C\.I\.|DNI|CEDULA|NOMBRE|CI)[\s:]*/i, '').trim();
                            if (temp && temp.length > 2 && !temp.match(/^\d+$/)) {
                                nombreRepresentante = temp;
                            }
                        }
                    }

                    // Estrategia 3: Buscar línea por línea - buscar "Nombre:" después de REPRESENTANTE LEGAL
                    if (!nombreRepresentante || nombreRepresentante.length < 3) {
                        const lineas = texto.split('\n');
                        let enSeccionRepresentante = false;
                        for (let i = 0; i < lineas.length; i++) {
                            const linea = lineas[i].trim();

                            // Detectar inicio de sección REPRESENTANTE LEGAL
                            if (linea.match(/REPRESENTANTE\s*LEGAL/i)) {
                                enSeccionRepresentante = true;
                                continue;
                            }

                            // Si estamos en la sección, buscar línea "Nombre:"
                            if (enSeccionRepresentante) {
                                const nombreLineaMatch = linea.match(/^NOMBRE[\s:]+(.+)/i);
                                if (nombreLineaMatch && nombreLineaMatch[1]) {
                                    nombreRepresentante = nombreLineaMatch[1].trim();
                                    break;
                                }
                                // Salir si llegamos a otra sección
                                if (linea.match(/ACTIVIDADES|ECONOMICAS|TELEFONO|CORREO|DIRECCION/i)) {
                                    break;
                                }
                            }
                        }
                    }

                    // Estrategia 4: Buscar patrón más genérico
                    if (!nombreRepresentante || nombreRepresentante.length < 3) {
                        const lineas = texto.split('\n');
                        for (let i = 0; i < lineas.length; i++) {
                            const linea = lineas[i];
                            if (linea.match(/REPRESENTANTE/i)) {
                                // Remover la palabra REPRESENTANTE y LEGAL de la línea
                                let resto = linea.replace(/REPRESENTANTE\s*(LEGAL)?\s*/i, '').trim();
                                // Si quedó algo después de remover REPRESENTANTE
                                if (resto && !resto.match(/^[\d\-\.]+$|^C\.?I\.?$/) && resto.length > 2) {
                                    // Tomar solo hasta encontrar espacios dobles, números o salto de línea
                                    nombreRepresentante = resto.split(/\s{2,}|\t|[\(\[]|CI|CEDULA|DOCUMENTO|DNI|DV|\d{4,}/i)[0].trim();
                                    if (nombreRepresentante.length > 2) break;
                                }
                                // Si nada en línea actual, buscar siguiente línea
                                if ((!nombreRepresentante || nombreRepresentante.length < 3) && i + 1 < lineas.length) {
                                    const proximaLinea = lineas[i + 1].trim();
                                    if (proximaLinea && !proximaLinea.match(/^[\d\-\.]+$/) && proximaLinea.length > 2) {
                                        nombreRepresentante = proximaLinea.split(/\s{2,}|\t|[\(\[]|CI|CEDULA|DOCUMENTO|DNI|DV|\d{4,}/i)[0].trim();
                                        if (nombreRepresentante.length > 2) break;
                                    }
                                }
                            }
                        }
                    }

                    // Estrategia 5: Buscar patrón "REPRESENTANTE [LEGAL] [NOMBRE] [CI]"
                    if (!nombreRepresentante || nombreRepresentante.length < 3) {
                        const match = texto.match(/REPRESENTANTE[\s]*LEGAL?[\s]+([A-ZÁ-Ÿ][^\d\n]+?)(?:\s+\d{4,}|\s+\d{5,}[-.]?\d|$)/i);
                        if (match && match[1]) {
                            nombreRepresentante = match[1].trim().replace(/LEGAL\s*/i, '').trim();
                        }
                    }

                    // Limpiar nombre extraído
                    if (nombreRepresentante) {
                        // Remover caracteres especiales y normalizar espacios
                        nombreRepresentante = nombreRepresentante.replace(/[\s\-\.]+/g, ' ').trim();
                        // Asegurar que no contenga caracteres inválidos
                        nombreRepresentante = nombreRepresentante.replace(/[^A-ZÁ-Ÿa-zá-ÿ\s\-']/g, '').trim();
                        if (nombreRepresentante.length > 2 && nombreRepresentante.length < 101) {
                            this.formData.representante_nombre = nombreRepresentante;
                            datosExtraidos.representante_nombre = nombreRepresentante;
                        }
                    }

                    // Buscar Estado (Activo/Inactivo)
                    if (texto.includes('ACTIVO')) {
                        this.formData.estado_contribuyente = 'ACTIVO';
                        datosExtraidos.estado_contribuyente = 'ACTIVO';
                    } else if (texto.includes('INACTIVO')) {
                        this.formData.estado_contribuyente = 'INACTIVO';
                        datosExtraidos.estado_contribuyente = 'INACTIVO';
                    }

                    // Extraer Actividades Económicas
                    const actividadesExtraidas = this.extraerActividades(texto);

                    // Mostrar alertas
                    const datosEncontrados = Object.keys(datosExtraidos).length;
                    if (datosEncontrados > 0) {
                        let mensaje = `Datos extraídos y sobrescritos (${datosEncontrados} campos):\n`;
                        if (datosExtraidos.numero_formulario) mensaje += `• Formulario: ${datosExtraidos.numero_formulario}\n`;
                        if (datosExtraidos.fecha_formulario) mensaje += `• Fecha: ${datosExtraidos.fecha_formulario}\n`;
                        if (datosExtraidos.ruc) mensaje += `• RUC: ${datosExtraidos.ruc}\n`;
                        if (datosExtraidos.razon_social) mensaje += `• Razón Social: ${datosExtraidos.razon_social}\n`;
                        if (datosExtraidos.nombre_fantasia) mensaje += `• Nombre Fantasía: ${datosExtraidos.nombre_fantasia}\n`;
                        if (datosExtraidos.numero_timbrado) mensaje += `• Timbrado: ${datosExtraidos.numero_timbrado}\n`;
                        if (datosExtraidos.departamento) mensaje += `• Departamento: ${datosExtraidos.departamento}\n`;
                        if (datosExtraidos.direccion) mensaje += `• Dirección: ${datosExtraidos.direccion.substring(0, 50)}...\n`;
                        if (datosExtraidos.telefono) mensaje += `• Teléfono: ${datosExtraidos.telefono}\n`;
                        if (datosExtraidos.email) mensaje += `• Email: ${datosExtraidos.email}\n`;
                        if (datosExtraidos.representante_documento) mensaje += `• CI Representante: ${datosExtraidos.representante_documento}\n`;
                        if (datosExtraidos.representante_nombre) mensaje += `• Nombre Representante: ${datosExtraidos.representante_nombre}`;
                        if (actividadesExtraidas.length > 0) mensaje += `\n\n• Actividades Económicas: ${actividadesExtraidas.length} encontradas`;

                        // Cargar documentos y actividades por defecto
                        this.cargarDocumentosActividadesPorDefecto(actividadesExtraidas);

                        // Guardar automáticamente habilitación y documentos/actividades extraídos
                        this.guardarYCargarDocumentosActividades();

                        alert(mensaje);
                    } else {
                        alert('No se pudieron extraer datos del archivo. Verifique que sea una imagen o PDF válido.');
                    }

                    console.log('Datos extraídos y sobrescritos:', datosExtraidos);
                },

                // Cargar documentos y actividades por defecto
                cargarDocumentosActividadesPorDefecto(actividadesExternas = []) {
                    // Documentos estándar (Factura, Nota de Crédito, Nota de Débito, Nota de Remisión)
                    const documentosPorDefecto = [1, 5, 6, 7];

                    // Limpiar documentos y actividades actuales
                    this.documentosLista = [];
                    this.actividadesLista = [];
                    this.documentosSeleccionados = documentosPorDefecto;
                    this.mostrarDocumentosCargados = true; // Mostrar sección de documentos cargados

                    // Cargar documentos por defecto
                    documentosPorDefecto.forEach(tipo => {
                        this.documentosLista.push({
                            id: null,
                            id_habilitacion: null,
                            tipo_documento: tipo,
                            tipo_documento_nombre: this.tiposDocumento[tipo] || 'Documento',
                            codigo_establecimiento: '001',
                            punto_expedicion: '001',
                            estado: 'ACTIVO',
                            descripcion: this.tiposDocumento[tipo] || 'Documento'
                        });
                    });

                    // Cargar actividades económicas
                    let actividadesPorDefecto = [];

                    // Si se pasaron actividades extraídas del PDF, usarlas
                    if (actividadesExternas && actividadesExternas.length > 0) {
                        actividadesPorDefecto = actividadesExternas;
                    } else {
                        // Sino, usar actividades por defecto
                        actividadesPorDefecto = [{
                                id: null,
                                id_habilitacion: null,
                                codigo: '101000',
                                descripcion: 'Venta de bienes y mercaderías',
                                principal: 's'
                            },
                            {
                                id: null,
                                id_habilitacion: null,
                                codigo: '102000',
                                descripcion: 'Prestación de servicios',
                                principal: ''
                            }
                        ];
                    }

                    this.actividadesLista = actividadesPorDefecto;

                    console.log('Documentos y actividades cargados', {
                        documentos: this.documentosLista.length,
                        actividades: this.actividadesLista.length
                    });
                },

                // Autocomplete Empresa
                filtrarEmpresas() {
                    const busqueda = this.empresaSearch.toLowerCase().trim();
                    if (!busqueda) {
                        this.empresasFiltradas = [];
                        return;
                    }
                    const palabras = busqueda.split(/\s+/).filter(p => p.length > 0);
                    this.empresasFiltradas = this.empresas.filter(emp => {
                        const texto = `${emp.empresa.toLowerCase()} ${emp.ruc.toLowerCase()}`;
                        return palabras.every(palabra => texto.includes(palabra));
                    }).slice(0, 10);
                    this.empresaActiveIndex = 0;
                    this.showEmpresaDropdown = true;
                },

                seleccionarEmpresa(emp) {
                    this.formData.id_empresa = emp.id_empresa;
                    this.formData.empresa = emp.empresa;
                    this.formData.ruc = emp.ruc;
                    this.formData.dv = emp.dv;
                    this.formData.razon_social = emp.empresa;
                    this.formData.numero_timbrado = emp.timbrado || '';
                    this.empresaSearch = emp.empresa;
                    this.showEmpresaDropdown = false;
                },

                seleccionarEmpresaActiva() {
                    if (this.empresasFiltradas.length > 0 && this.empresaActiveIndex >= 0) {
                        this.seleccionarEmpresa(this.empresasFiltradas[this.empresaActiveIndex]);
                    }
                },

                navegarEmpresa(dir) {
                    if (this.empresasFiltradas.length === 0) return;
                    this.empresaActiveIndex += dir;
                    if (this.empresaActiveIndex < 0) this.empresaActiveIndex = this.empresasFiltradas.length - 1;
                    if (this.empresaActiveIndex >= this.empresasFiltradas.length) this.empresaActiveIndex = 0;
                },

                limpiarEmpresa() {
                    this.empresaSearch = '';
                    this.formData.id_empresa = '';
                    this.formData.empresa = '';
                    this.empresasFiltradas = [];
                },

                resaltarCoincidencias(texto, busqueda) {
                    if (!busqueda) return texto;
                    const palabras = busqueda.split(/\s+/).filter(p => p.length > 0);
                    let result = texto;
                    palabras.forEach(palabra => {
                        const regex = new RegExp(`(${palabra})`, 'gi');
                        result = result.replace(regex, '<mark style="background:#fef08a;padding:0 2px;border-radius:2px;">$1</mark>');
                    });
                    return result;
                },

                // Buscar RUC en SIFEN
                async buscarRucSifen() {
                    // Tomar el RUC del campo del formulario
                    const ruc = this.formData.ruc;
                    if (!ruc || ruc.trim() === '') {
                        this.showToast('Ingrese un RUC en el campo para consultar', 'warning');
                        return;
                    }

                    this.showToast('Consultando SIFEN...', 'info');
                    try {
                        const res = await fetch(`${API_URL}?action=sifen_lookup&ruc=${encodeURIComponent(ruc)}`);
                        const data = await res.json();

                        if (data.success) {
                            this.formData.ruc = data.data.ruc_base;
                            this.formData.dv = data.data.dv;
                            this.formData.razon_social = data.data.razon_social;
                            this.showToast(`✓ SIFEN: ${data.data.ruc} - ${data.data.razon_social}`);
                        } else {
                            this.showToast(data.error || 'RUC no encontrado en SIFEN', 'error');
                        }
                    } catch (e) {
                        this.showToast('Error al consultar SIFEN: ' + e.message, 'error');
                    }
                },

                // Geografía - Autocompletar desde OCR
                async autocompletarDatosGeograficos(departamento, distrito, localidad, barrio) {
                    try {
                        // Buscar departamento
                        if (departamento) {
                            const resDpto = await fetch(`${API_URL}?action=geo_search&tipo=depto&q=${encodeURIComponent(departamento)}`);
                            const dataDpto = await resDpto.json();
                            if (dataDpto.success && dataDpto.data.length > 0) {
                                const item = dataDpto.data[0];
                                this.formData.cod_depto = item.cod_depto;
                                this.formData.departamento = item.desc_depto;
                                this.geoSearch.departamento = item.desc_depto;
                            }
                        }

                        // Buscar distrito (requiere cod_depto)
                        if (distrito && this.formData.cod_depto) {
                            const resDistrito = await fetch(`${API_URL}?action=geo_search&tipo=distrito&q=${encodeURIComponent(distrito)}&cod_depto=${this.formData.cod_depto}`);
                            const dataDistrito = await resDistrito.json();
                            if (dataDistrito.success && dataDistrito.data.length > 0) {
                                const item = dataDistrito.data[0];
                                this.formData.cod_distrito = item.cod_distrito;
                                this.formData.distrito = item.desc_distrito;
                                this.geoSearch.distrito = item.desc_distrito;
                            }
                        }

                        // Buscar ciudad/localidad (requiere cod_distrito)
                        // Usar localidad extraída, o si no hay, usar distrito como ciudad
                        const ciudadBuscar = localidad || distrito;
                        if (ciudadBuscar && this.formData.cod_distrito) {
                            const resCiudad = await fetch(`${API_URL}?action=geo_search&tipo=ciudad&q=${encodeURIComponent(ciudadBuscar)}&cod_distrito=${this.formData.cod_distrito}`);
                            const dataCiudad = await resCiudad.json();
                            if (dataCiudad.success && dataCiudad.data.length > 0) {
                                const item = dataCiudad.data[0];
                                this.formData.cod_ciudad = item.cod_ciudad;
                                this.formData.localidad = item.desc_ciudad;
                                this.geoSearch.ciudad = item.desc_ciudad;
                            }
                        }

                        // Buscar barrio (requiere cod_ciudad)
                        if (barrio && this.formData.cod_ciudad) {
                            const resBarrio = await fetch(`${API_URL}?action=geo_search&tipo=barrio&q=${encodeURIComponent(barrio)}&cod_ciudad=${this.formData.cod_ciudad}`);
                            const dataBarrio = await resBarrio.json();
                            if (dataBarrio.success && dataBarrio.data.length > 0) {
                                const item = dataBarrio.data[0];
                                this.formData.cod_barrio = item.cod_barrio;
                                this.formData.barrio = item.desc_barrio;
                                this.geoSearch.barrio = item.desc_barrio;
                            }
                        }
                    } catch (e) {
                        console.error('Error al autocompletar datos geográficos:', e);
                    }
                },

                // Geografía
                async buscarGeo(tipo) {
                    const q = this.geoSearch[tipo];
                    if (!q || q.length < 2) {
                        this.geoResultados[tipo] = [];
                        return;
                    }

                    let url = `${API_URL}?action=geo_search&tipo=${tipo === 'ciudad' ? 'ciudad' : tipo === 'departamento' ? 'depto' : tipo}&q=${encodeURIComponent(q)}`;
                    if (tipo === 'distrito' && this.formData.cod_depto) url += `&cod_depto=${this.formData.cod_depto}`;
                    if (tipo === 'ciudad' && this.formData.cod_distrito) url += `&cod_distrito=${this.formData.cod_distrito}`;
                    if (tipo === 'barrio' && this.formData.cod_ciudad) url += `&cod_ciudad=${this.formData.cod_ciudad}`;

                    const res = await fetch(url);
                    const data = await res.json();
                    if (data.success) {
                        this.geoResultados[tipo] = data.data;
                        this.showGeoDropdown[tipo] = true;
                        this.geoActiveIndex[tipo] = 0;
                    }
                },

                seleccionarGeo(tipo, item) {
                    if (tipo === 'departamento') {
                        this.formData.cod_depto = item.cod_depto;
                        this.formData.departamento = item.desc_depto;
                        this.geoSearch.departamento = item.desc_depto;
                        this.limpiarGeoCascada('departamento');
                    } else if (tipo === 'distrito') {
                        this.formData.cod_distrito = item.cod_distrito;
                        this.formData.distrito = item.desc_distrito;
                        this.geoSearch.distrito = item.desc_distrito;
                        this.limpiarGeoCascada('distrito');
                    } else if (tipo === 'ciudad') {
                        this.formData.cod_ciudad = item.cod_ciudad;
                        this.formData.localidad = item.desc_ciudad;
                        this.geoSearch.ciudad = item.desc_ciudad;
                        this.limpiarGeoCascada('ciudad');
                    } else if (tipo === 'barrio') {
                        this.formData.cod_barrio = item.cod_barrio;
                        this.formData.barrio = item.desc_barrio;
                        this.geoSearch.barrio = item.desc_barrio;
                    }
                    this.showGeoDropdown[tipo] = false;
                },

                limpiarGeoCascada(desde) {
                    const niveles = ['departamento', 'distrito', 'ciudad', 'barrio'];
                    const idx = niveles.indexOf(desde);
                    for (let i = idx + 1; i < niveles.length; i++) {
                        const nivel = niveles[i];
                        this.geoSearch[nivel] = '';
                        this.geoResultados[nivel] = [];
                        this.showGeoDropdown[nivel] = false;
                        if (nivel === 'distrito') {
                            this.formData.cod_distrito = null;
                            this.formData.distrito = '';
                        } else if (nivel === 'ciudad') {
                            this.formData.cod_ciudad = null;
                            this.formData.localidad = '';
                        } else if (nivel === 'barrio') {
                            this.formData.cod_barrio = null;
                            this.formData.barrio = '';
                        }
                    }
                },

                seleccionarGeoActivo(tipo) {
                    if (this.geoResultados[tipo].length > 0 && this.geoActiveIndex[tipo] >= 0) {
                        this.seleccionarGeo(tipo, this.geoResultados[tipo][this.geoActiveIndex[tipo]]);
                    }
                },

                navegarGeo(tipo, dir) {
                    if (this.geoResultados[tipo].length === 0) return;
                    this.geoActiveIndex[tipo] += dir;
                    if (this.geoActiveIndex[tipo] < 0) this.geoActiveIndex[tipo] = this.geoResultados[tipo].length - 1;
                    if (this.geoActiveIndex[tipo] >= this.geoResultados[tipo].length) this.geoActiveIndex[tipo] = 0;
                },

                cerrarGeoDropdown(tipo) {
                    this.showGeoDropdown[tipo] = false;
                },

                limpiarGeo(tipo) {
                    this.geoSearch[tipo] = '';
                    this.geoResultados[tipo] = [];
                    this.showGeoDropdown[tipo] = false;
                    if (tipo === 'departamento') {
                        this.formData.cod_depto = null;
                        this.formData.departamento = '';
                        this.limpiarGeoCascada('departamento');
                    } else if (tipo === 'distrito') {
                        this.formData.cod_distrito = null;
                        this.formData.distrito = '';
                        this.limpiarGeoCascada('distrito');
                    } else if (tipo === 'ciudad') {
                        this.formData.cod_ciudad = null;
                        this.formData.localidad = '';
                        this.limpiarGeoCascada('ciudad');
                    } else if (tipo === 'barrio') {
                        this.formData.cod_barrio = null;
                        this.formData.barrio = '';
                    }
                },

                initGeoSearch() {
                    this.geoSearch.departamento = this.formData.departamento || '';
                    this.geoSearch.distrito = this.formData.distrito || '';
                    this.geoSearch.ciudad = this.formData.localidad || '';
                    this.geoSearch.barrio = this.formData.barrio || '';
                },

                // Teléfono
                actualizarTelefono() {
                    const pais = this.formData.telefono_pais || '+595';
                    const numero = (this.formData.telefono_numero || '').replace(/\D/g, '');
                    this.formData.telefono = numero ? pais + numero : '';
                },

                parsearTelefono() {
                    const tel = this.formData.telefono || '';
                    if (!tel) {
                        this.formData.telefono_pais = '+595';
                        this.formData.telefono_numero = '';
                        return;
                    }
                    const codigos = ['+595', '+54', '+55', '+598', '+591', '+56', '+51', '+593', '+57', '+58', '+52', '+1', '+34'];
                    for (const cod of codigos) {
                        if (tel.startsWith(cod)) {
                            this.formData.telefono_pais = cod;
                            this.formData.telefono_numero = tel.substring(cod.length);
                            return;
                        }
                    }
                    this.formData.telefono_pais = '+595';
                    this.formData.telefono_numero = tel.replace(/^\+/, '');
                },

                toggleDocumento(tipo) {
                    const idx = this.documentosSeleccionados.indexOf(tipo);
                    if (idx >= 0) this.documentosSeleccionados.splice(idx, 1);
                    else this.documentosSeleccionados.push(tipo);
                },

                // Documentos y Actividades
                agregarDocumento() {
                    this.nuevoDocumento = {
                        tipo_documento: 1,
                        codigo_establecimiento: '001',
                        punto_expedicion: '001'
                    };
                    this.showAddDocumento = true;
                },

                async guardarDocumento() {
                    if (!this.formData.id) return;
                    await fetch(`${API_URL}?action=add_documento`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            id_habilitacion: this.formData.id,
                            ...this.nuevoDocumento
                        })
                    });
                    await this.cargarDocumentos(this.formData.id);
                    this.showAddDocumento = false;
                    this.showToast('Documento agregado');
                },

                async eliminarDocumento(id) {
                    if (!confirm('¿Eliminar este documento?')) return;
                    await fetch(`${API_URL}?action=remove_documento&id=${id}`);
                    await this.cargarDocumentos(this.formData.id);
                    this.showToast('Documento eliminado', 'warning');
                },

                agregarActividad() {
                    this.nuevaActividad = {
                        codigo: '',
                        descripcion: '',
                        principal: 0
                    };
                    this.showAddActividad = true;
                },

                async guardarActividad() {
                    if (!this.formData.id || !this.nuevaActividad.codigo) {
                        this.showToast('Ingrese el código de actividad', 'error');
                        return;
                    }
                    await fetch(`${API_URL}?action=add_actividad`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            id_habilitacion: this.formData.id,
                            ...this.nuevaActividad
                        })
                    });
                    await this.cargarActividades(this.formData.id);
                    this.showAddActividad = false;
                    this.showToast('Actividad agregada');
                },

                async eliminarActividad(id) {
                    if (!confirm('¿Eliminar esta actividad?')) return;
                    await fetch(`${API_URL}?action=remove_actividad&id=${id}`);
                    await this.cargarActividades(this.formData.id);
                    this.showToast('Actividad eliminada', 'warning');
                },

                // Certificados
                async subirCertificado(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (!['p12', 'pfx'].includes(ext)) {
                        this.showToast('Solo se permiten archivos .p12 o .pfx', 'error');
                        event.target.value = '';
                        return;
                    }
                    if (file.size > 512000) {
                        this.showToast('El archivo no puede superar 500KB', 'error');
                        event.target.value = '';
                        return;
                    }
                    const formData = new FormData();
                    formData.append('certificado', file);
                    try {
                        const res = await fetch(`${API_URL}?action=upload_certificado`, {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.formData.cert_nombre = data.filename;
                            this.showToast('Certificado subido: ' + data.filename);
                        } else {
                            this.showToast(data.error || 'Error al subir archivo', 'error');
                        }
                    } catch (e) {
                        this.showToast('Error de conexión', 'error');
                    }
                    event.target.value = '';
                },

                async seleccionarCertificado() {
                    try {
                        const res = await fetch(`${API_URL}?action=list_certificados`);
                        const data = await res.json();
                        if (!data.success || data.data.length === 0) {
                            this.showToast('No hay certificados. Use el botón "Subir".', 'warning');
                            return;
                        }
                        const nombre = prompt('Certificados disponibles:\n' + data.data.map(c => c.nombre).join('\n') + '\n\nIngrese el nombre:');
                        if (nombre) this.formData.cert_nombre = nombre;
                    } catch (e) {
                        this.showToast('Error al cargar certificados', 'error');
                    }
                },

                async validarCertPass() {
                    if (!this.formData.cert_nombre) {
                        this.showToast('Seleccione un certificado', 'warning');
                        return;
                    }
                    if (!this.formData.cert_pass) {
                        this.showToast('Ingrese la contraseña', 'warning');
                        return;
                    }
                    try {
                        const res = await fetch(`${API_URL}?action=validar_cert_pass`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                cert_nombre: this.formData.cert_nombre,
                                cert_pass: this.formData.cert_pass
                            })
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.certModal = {
                                show: true,
                                nombre: this.formData.cert_nombre,
                                ubicacion: data.cert_path || 'No disponible',
                                password: this.formData.cert_pass,
                                legacy: data.legacy || false
                            };
                        } else {
                            this.showToast(data.error || 'Contraseña inválida', 'error');
                        }
                    } catch (e) {
                        this.showToast('Error al validar', 'error');
                    }
                },

                // Guardar habilitación y luego cargar documentos y actividades extraídos del OCR
                async guardarYCargarDocumentosActividades() {
                    try {
                        // 1. Verificar que hay empresa seleccionada
                        if (!this.formData.id_empresa) {
                            this.showToast('Seleccione una empresa primero', 'error');
                            return;
                        }

                        // 2. Guardar habilitación
                        const action = this.formData.id ? 'update' : 'create';
                        const url = this.formData.id ? `${API_URL}?action=${action}&id=${this.formData.id}` : `${API_URL}?action=${action}`;

                        const res = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(this.formData)
                        });
                        const data = await res.json();

                        if (!data.success) {
                            this.showToast('Error al guardar habilitación', 'error');
                            return;
                        }

                        const habId = data.id || this.formData.id;
                        if (!this.formData.id) {
                            this.formData.id = habId;
                        }

                        // 4. Guardar documentos extraídos uno por uno
                        let docsInsertados = 0;
                        for (const doc of this.documentosLista) {
                            if (!doc.id) { // Solo insertar nuevos
                                const docRes = await fetch(`${API_URL}?action=add_documento`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify({
                                        id_habilitacion: habId,
                                        tipo_documento: doc.tipo_documento,
                                        codigo_establecimiento: doc.codigo_establecimiento || '001',
                                        punto_expedicion: doc.punto_expedicion || '001',
                                        estado: doc.estado || 'ACTIVO'
                                    })
                                });
                                const docData = await docRes.json();
                                if (docData.success) docsInsertados++;
                            }
                        }

                        // 5. Guardar actividades extraídas uno por uno
                        let actsInsertadas = 0;
                        for (const act of this.actividadesLista) {
                            if (!act.id) { // Solo insertar nuevas
                                const actRes = await fetch(`${API_URL}?action=add_actividad`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify({
                                        id_habilitacion: habId,
                                        codigo: act.codigo,
                                        descripcion: act.descripcion,
                                        principal: act.principal || 0
                                    })
                                });
                                const actData = await actRes.json();
                                if (actData.success) actsInsertadas++;
                            }
                        }

                        // 6. Recargar datos y mostrar resultado
                        if (this.formData.id) {
                            await this.cargarHabilitacion();
                        }

                        this.showToast(`✓ Guardado: Habilitación + ${docsInsertados} documentos + ${actsInsertadas} actividades`);
                    } catch (e) {
                        console.error('Error en guardarYCargarDocumentosActividades:', e);
                        this.showToast('Error al procesar OCR', 'error');
                    }
                },

                // Guardar
                async guardar() {
                    if (!this.formData.id_empresa && !this.formData.id) {
                        this.showToast('Seleccione una empresa', 'error');
                        return;
                    }

                    this.guardando = true;
                    try {
                        const action = this.formData.id ? 'update' : 'create';
                        const url = this.formData.id ? `${API_URL}?action=${action}&id=${this.formData.id}` : `${API_URL}?action=${action}`;

                        const res = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(this.formData)
                        });
                        const data = await res.json();

                        if (data.success) {
                            const habId = data.id || this.formData.id;

                            // Actualizar documentos
                            const docsRes = await fetch(`${API_URL}?action=documentos&id=${habId}`);
                            const docsData = await docsRes.json();
                            const actuales = (docsData.data || []).map(d => parseInt(d.tipo_documento));

                            for (const doc of docsData.data || []) {
                                if (!this.documentosSeleccionados.includes(parseInt(doc.tipo_documento))) {
                                    await fetch(`${API_URL}?action=remove_documento&id=${doc.id}`);
                                }
                            }
                            for (const tipo of this.documentosSeleccionados) {
                                if (!actuales.includes(tipo)) {
                                    await fetch(`${API_URL}?action=add_documento`, {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json'
                                        },
                                        body: JSON.stringify({
                                            id_habilitacion: habId,
                                            tipo_documento: tipo
                                        })
                                    });
                                }
                            }

                            this.showToast('Guardado correctamente');

                            // Si es nuevo, recargar con el ID
                            if (!this.formData.id && habId) {
                                this.formData.id = habId;
                                await this.cargarDocumentos(habId);
                            }
                        } else {
                            this.showToast(data.error || 'Error al guardar', 'error');
                        }
                    } catch (e) {
                        this.showToast('Error de conexión', 'error');
                    }
                    this.guardando = false;
                }
            };
        }
    </script>
</body>

</html>
