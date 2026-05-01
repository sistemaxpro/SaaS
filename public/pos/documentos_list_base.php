<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$docListContext = $GLOBALS['SMX_DOC_LIST_CONTEXT'] ?? [];
$docListContext = array_merge([
    'page_title' => 'Documentos',
    'heading' => 'Documentos',
    'document_label' => 'Documento',
    'api_endpoint' => '/public/pos/api/presupuestos_manage.php',
    'editor_path' => '/public/pos/presupuestos_desktop.php',
    'print_path' => '/public/pos/ticket_presupuesto.php',
], $docListContext);
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($docListContext['page_title'], ENT_QUOTES, 'UTF-8') ?> - SistemaX</title>
    <link rel="stylesheet" href="/public/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css">
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css">
    <script src="/public/assets/vendor/alpine.min.js" defer></script>
    <script src="/public/assets/js/ag-grid-locale.js"></script>
    <script src="/public/pos/js/smx-printer.js?v=3"></script>
    <script>
        window.__DOC_LIST_CONFIG__ = <?= json_encode($docListContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.__agGridReady = new Promise(function(resolve){
            if (window.agGrid) { resolve(); return; }
            var s = document.createElement('script');
            s.src = '/public/_lib/ag-grid/ag-grid-enterprise/package/dist/ag-grid-enterprise.min.noStyle.js';
            s.onload = function(){
                var l = document.createElement('script');
                l.src = '/public/_lib/ag-grid/license.js';
                l.onload = resolve;
                document.head.appendChild(l);
            };
            document.head.appendChild(s);
        });
    </script>
    <style>
        :root { color-scheme: dark; }
        html, body { height:100%; }
        body { margin:0; overflow:hidden; font-family: ui-sans-serif, system-ui, sans-serif; background:#0b1220; color:#e5edf7; }
        .shell { min-height:100dvh; height:100dvh; padding:24px; box-sizing:border-box; display:flex; background:
            radial-gradient(circle at top left, rgba(37,99,235,.18), transparent 30%),
            radial-gradient(circle at top right, rgba(14,165,233,.14), transparent 28%),
            linear-gradient(180deg, #0b1220 0%, #0f172a 100%);
        }
        .card { background:rgba(15,23,42,.88); border:1px solid rgba(148,163,184,.18); border-radius:24px; box-shadow:0 30px 80px rgba(2,6,23,.45); display:flex; flex-direction:column; flex:1; min-height:0; }
        .toolbar { display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:space-between; padding:18px 20px; border-bottom:1px solid rgba(148,163,184,.12); }
        .toolbar h1 { margin:0; font-size:26px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; }
        .controls { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .input, .select, .btn { border-radius:14px; border:1px solid rgba(148,163,184,.18); background:#0f172a; color:#e5edf7; }
        .input, .select { padding:11px 14px; min-height:44px; }
        .input { min-width:240px; }
        .btn { padding:11px 16px; font-weight:800; cursor:pointer; }
        .btn-primary { background:linear-gradient(135deg, #2563eb, #1d4ed8); border-color:rgba(59,130,246,.4); }
        .btn-ghost { background:#111827; }
        .grid-wrap { padding:18px; flex:1; min-height:0; display:flex; }
        .grid-box { height:100%; min-height:320px; width:100%; flex:1; }
        .ag-theme-quartz-dark {
            --ag-background-color: #0f172a;
            --ag-foreground-color: #e5edf7;
            --ag-header-background-color: #111827;
            --ag-odd-row-background-color: rgba(15,23,42,.88);
            --ag-row-hover-color: rgba(37,99,235,.12);
            --ag-border-color: rgba(148,163,184,.16);
            --ag-secondary-border-color: rgba(148,163,184,.14);
            --ag-header-foreground-color: #93c5fd;
            --ag-selected-row-background-color: rgba(37,99,235,.18);
        }
        .doc-cell-stack { display:flex; flex-direction:column; gap:4px; line-height:1.2; padding-top:6px; padding-bottom:6px; }
        .doc-cell-stack .main { font-weight:800; color:#f8fafc; }
        .doc-cell-stack .meta { font-size:11px; color:#94a3b8; }
        .badge { display:inline-flex; align-items:center; gap:6px; padding:4px 9px; border-radius:999px; font-size:11px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; }
        .badge-ok { background:rgba(16,185,129,.18); color:#6ee7b7; border:1px solid rgba(16,185,129,.28); }
        .badge-off { background:rgba(239,68,68,.18); color:#fca5a5; border:1px solid rgba(239,68,68,.28); }
        .ag-menu-option-part { align-items:center; }
        .doc-modal-backdrop { position:fixed; inset:0; background:rgba(2,6,23,.72); backdrop-filter:blur(12px); display:flex; align-items:center; justify-content:center; padding:24px; z-index:1200; }
        .doc-modal { width:min(560px, 100%); background:linear-gradient(180deg, rgba(15,23,42,.98), rgba(15,23,42,.94)); border:1px solid rgba(148,163,184,.2); border-radius:28px; box-shadow:0 30px 80px rgba(2,6,23,.5); overflow:hidden; }
        .doc-modal-head { padding:22px 24px 14px; border-bottom:1px solid rgba(148,163,184,.12); }
        .doc-modal-body { padding:22px 24px 24px; display:grid; gap:18px; }
        .doc-modal-title { margin:0; font-size:22px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; color:#f8fafc; }
        .doc-modal-subtitle { margin:6px 0 0; color:#94a3b8; font-size:13px; }
        .doc-actions { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; }
        .doc-action-btn { border:none; border-radius:18px; padding:16px 14px; min-height:112px; cursor:pointer; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; color:#e5edf7; transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease, background .18s ease; }
        .doc-action-btn:hover { transform:translateY(-1px); }
        .doc-action-btn i { font-size:20px; }
        .doc-action-print { background:linear-gradient(180deg, rgba(37,99,235,.18), rgba(37,99,235,.1)); border:1px solid rgba(96,165,250,.35); box-shadow:0 14px 30px rgba(37,99,235,.14); }
        .doc-action-email { background:linear-gradient(180deg, rgba(16,185,129,.18), rgba(16,185,129,.1)); border:1px solid rgba(52,211,153,.35); box-shadow:0 14px 30px rgba(16,185,129,.14); }
        .doc-action-whatsapp { background:linear-gradient(180deg, rgba(34,197,94,.2), rgba(34,197,94,.1)); border:1px solid rgba(74,222,128,.35); box-shadow:0 14px 30px rgba(34,197,94,.14); }
        .doc-channel { display:grid; gap:8px; }
        .doc-channel label { font-size:12px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#93c5fd; }
        .doc-help { font-size:12px; color:#94a3b8; line-height:1.45; }
        .doc-status { font-size:12px; font-weight:700; }
        .doc-status.ok { color:#6ee7b7; }
        .doc-status.error { color:#fca5a5; }
        .doc-modal-close { width:42px; height:42px; border-radius:999px; border:1px solid rgba(148,163,184,.18); background:#111827; color:#e5edf7; cursor:pointer; font-size:18px; font-weight:900; }
        .doc-agent-box { display:grid; gap:10px; padding:14px; border-radius:18px; border:1px solid rgba(148,163,184,.16); background:rgba(15,23,42,.42); }
        .doc-agent-row { display:flex; gap:10px; align-items:center; }
        .doc-phone-row { display:flex; gap:10px; }
        .doc-country-select { min-width:140px; }
        @media (max-width: 900px) {
            body { overflow:auto; }
            .shell { min-height:100dvh; height:auto; padding:12px; }
            .toolbar { padding:14px; }
            .grid-wrap { padding:12px; }
            .grid-box { min-height:420px; }
            .input { min-width: 100%; }
            .doc-modal-backdrop { padding:14px; }
            .doc-actions { grid-template-columns:1fr; }
            .doc-phone-row { flex-direction:column; }
            .doc-country-select { min-width:100%; }
        }
    </style>
</head>
<body>
<div class="shell" x-data="documentosListApp()">
    <div class="card">
        <div class="toolbar">
            <div>
                <h1 x-text="config.heading"></h1>
                <div style="font-size:12px;color:#94a3b8;margin-top:4px;">Listado operativo con acciones rápidas</div>
            </div>
            <div class="controls">
                <input class="input" type="text" x-model.trim="q" @keydown.enter.prevent="loadRows()" :placeholder="'Buscar ' + config.document_label.toLowerCase() + ', cliente o importe'">
                <select class="select" x-model="estado" @change="loadRows()">
                    <option value="activo">Activos</option>
                    <option value="anulado">Anulados</option>
                    <option value="todos">Todos</option>
                </select>
                <select class="select" x-model="periodo" @change="handlePeriodoChange()">
                    <option value="hoy">Hoy</option>
                    <option value="semana">Semana</option>
                    <option value="mes">Mes</option>
                    <option value="anio">Año</option>
                    <option value="todo">Todo</option>
                    <option value="custom">Rango</option>
                </select>
                <input x-show="periodo === 'custom'" class="input" type="date" x-model="fechaDesde" @change="loadRows()">
                <input x-show="periodo === 'custom'" class="input" type="date" x-model="fechaHasta" @change="loadRows()">
                <button class="btn btn-ghost" @click="loadRows()"><i class="fas fa-rotate-right"></i> Recargar</button>
                <button class="btn btn-primary" @click="openNew()"><i class="fas fa-plus"></i> Nuevo</button>
            </div>
        </div>
        <div class="grid-wrap">
            <div x-ref="grid" class="grid-box ag-theme-quartz-dark"></div>
        </div>
    </div>

    <template x-if="showDocumentActionsModal">
        <div class="doc-modal-backdrop" @click.self="closeDocumentActionsModal()">
            <div class="doc-modal">
                <div class="doc-modal-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:18px;">
                    <div>
                        <h2 class="doc-modal-title">Acciones de <span x-text="config.document_label || 'Documento'"></span></h2>
                        <p class="doc-modal-subtitle">
                            <span x-text="documentActionRow?.nro_factura || ('ID ' + (documentActionRow?.id_factura || '-'))"></span>
                            ·
                            <span x-text="documentActionRow?.cliente_nombre || '-'"></span>
                        </p>
                    </div>
                    <button type="button" class="doc-modal-close" @click="closeDocumentActionsModal()" aria-label="Cerrar">×</button>
                </div>
                <div class="doc-modal-body">
                    <div class="doc-actions">
                        <button type="button" class="doc-action-btn doc-action-print" @click="printRow(documentActionRow)">
                            <i class="fas fa-print" aria-hidden="true"></i>
                            <span>Imprimir</span>
                        </button>
                        <button type="button" class="doc-action-btn doc-action-email" @click="sendDocumentByEmail()">
                            <i class="fas fa-envelope" aria-hidden="true"></i>
                            <span>Email</span>
                        </button>
                        <button type="button" class="doc-action-btn doc-action-whatsapp" @click="sendDocumentByWhatsApp()">
                            <i class="fab fa-whatsapp" aria-hidden="true"></i>
                            <span>WhatsApp</span>
                        </button>
                    </div>

                    <div class="doc-channel">
                        <label for="doc-email-input">Email</label>
                        <input id="doc-email-input" class="input" type="email" x-model.trim="documentActionEmail" placeholder="cliente@correo.com">
                    </div>

                    <div class="doc-channel">
                        <label for="doc-phone-input">WhatsApp</label>
                        <div class="doc-phone-row">
                            <select class="select doc-country-select" x-model="documentActionCountryCode">
                                <template x-for="country in countries" :key="country.code">
                                    <option :value="country.code" x-text="country.flag + ' +' + country.code"></option>
                                </template>
                            </select>
                            <input id="doc-phone-input" class="input" type="text" x-model.trim="documentActionPhone" placeholder="981123456">
                        </div>
                    </div>

                    <div class="doc-agent-box">
                        <div class="doc-agent-row">
                            <span class="doc-status" :class="nativePrintConnected ? 'ok' : 'error'" x-text="nativePrintConnected ? ('Agent conectado (' + nativePrintProvider + ')') : 'Agent no conectado'"></span>
                            <button type="button" class="btn btn-ghost" style="margin-left:auto;" @click="refreshNativePrinters()">Actualizar impresoras</button>
                        </div>
                        <div class="doc-channel">
                            <label for="doc-print-mode">Formato</label>
                            <select id="doc-print-mode" class="select" x-model="selectedPrintMode" @change="persistPrintPreferences()">
                                <option value="auto">Auto detectar</option>
                                <option value="escpos">ESC/POS</option>
                                <option value="a4">Documento A4</option>
                                <option value="a5">Documento A5</option>
                            </select>
                        </div>
                        <div class="doc-channel">
                            <label for="doc-printer-select">Impresora</label>
                            <select id="doc-printer-select" class="select" x-model="selectedNativePrinter" @change="applyDetectedEscposWidth(selectedNativePrinter, true); persistPrintPreferences()">
                                <option value="">Seleccionar impresora</option>
                                <template x-for="printer in nativePrinters" :key="printer">
                                    <option :value="printer" x-text="printer"></option>
                                </template>
                            </select>
                        </div>
                        <div class="doc-channel" x-show="selectedPrintMode === 'escpos' || selectedPrintMode === 'auto'">
                            <label for="doc-escpos-width">Ancho ticket</label>
                            <select id="doc-escpos-width" class="select" x-model="selectedEscposWidth" @change="persistPrintPreferences()">
                                <option value="32">58mm</option>
                                <option value="48">80mm</option>
                            </select>
                        </div>
                        <div x-show="nativePrintStatus" class="doc-status" :class="nativePrintStatusType" x-text="nativePrintStatus"></div>
                    </div>

                    <div class="doc-help">
                        Si elegis `ESC/POS`, se imprime siempre por Sistemax Agent en la impresora seleccionada. `Auto detectar` usa heuristica por nombre; `Documento A4/A5` abre impresion web en ese formato. Email envia el PDF adjunto desde el sistema y WhatsApp abre `wa.me` con el mensaje listo.
                    </div>
                    <div x-show="sendingDocumentEmail || documentEmailStatus" class="doc-help">
                        <span x-show="sendingDocumentEmail">Enviando email con PDF adjunto...</span>
                        <span x-show="documentEmailStatus" class="doc-status" :class="documentEmailStatusType" x-text="documentEmailStatus"></span>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function documentosListApp() {
    return {
        config: window.__DOC_LIST_CONFIG__ || {},
        q: '',
        estado: 'activo',
        periodo: 'mes',
        fechaDesde: '',
        fechaHasta: '',
        rows: [],
        gridApi: null,
        showDocumentActionsModal: false,
        documentActionRow: null,
        documentActionEmail: '',
        documentActionPhone: '',
        documentActionCountryCode: '595',
        sendingDocumentEmail: false,
        documentEmailStatus: '',
        documentEmailStatusType: '',
        smxPrinter: null,
        nativePrintConnected: false,
        nativePrintProvider: 'N/A',
        nativePrinters: [],
        nativePrinterDetails: {},
        selectedNativePrinter: '',
        selectedPrintMode: 'auto',
        selectedEscposWidth: '48',
        nativePrintStatus: '',
        nativePrintStatusType: '',
        countries: [
            { code: '595', name: 'Paraguay', flag: '🇵🇾', iso: 'PY' },
            { code: '54', name: 'Argentina', flag: '🇦🇷', iso: 'AR' },
            { code: '55', name: 'Brasil', flag: '🇧🇷', iso: 'BR' },
            { code: '56', name: 'Chile', flag: '🇨🇱', iso: 'CL' },
            { code: '57', name: 'Colombia', flag: '🇨🇴', iso: 'CO' },
            { code: '593', name: 'Ecuador', flag: '🇪🇨', iso: 'EC' },
            { code: '51', name: 'Peru', flag: '🇵🇪', iso: 'PE' },
            { code: '598', name: 'Uruguay', flag: '🇺🇾', iso: 'UY' },
            { code: '58', name: 'Venezuela', flag: '🇻🇪', iso: 'VE' },
            { code: '591', name: 'Bolivia', flag: '🇧🇴', iso: 'BO' },
            { code: '52', name: 'Mexico', flag: '🇲🇽', iso: 'MX' },
            { code: '34', name: 'Espana', flag: '🇪🇸', iso: 'ES' },
            { code: '1', name: 'Estados Unidos', flag: '🇺🇸', iso: 'US' }
        ],
        async init() {
            this.restorePrintPreferences();
            await this.initAgGrid();
            await this.initNativePrinter();
            this.handlePeriodoChange(false);
        },
        getPrintPreferencesKey() {
            const companyId = '<?= (int)Session::get('id_empresa') ?>';
            return `smx_doc_print_prefs:${companyId}`;
        },
        getPrinterPreferencesKey() {
            const companyId = '<?= (int)Session::get('id_empresa') ?>';
            return `smx_doc_printer_pref:${companyId}`;
        },
        restorePrintPreferences() {
            try {
                const raw = localStorage.getItem(this.getPrintPreferencesKey());
                const prefs = raw ? JSON.parse(raw) : {};
                const mode = String(prefs?.mode || '').toLowerCase();
                const escposWidth = String(prefs?.escpos_width || '').trim();
                if (['auto', 'escpos', 'a4', 'a5'].includes(mode)) {
                    this.selectedPrintMode = mode;
                }
                if (escposWidth === '32' || escposWidth === '48') {
                    this.selectedEscposWidth = escposWidth;
                }
            } catch (_) {}
            try {
                const rawPrinter = localStorage.getItem(this.getPrinterPreferencesKey());
                if (!rawPrinter) return;
                const prefsPrinter = JSON.parse(rawPrinter);
                const printer = String(prefsPrinter?.printer || '').trim();
                const printerNormalized = String(prefsPrinter?.printer_normalized || '').trim();
                if (printer) {
                    this.selectedNativePrinter = printer;
                } else if (printerNormalized) {
                    this.selectedNativePrinter = printerNormalized;
                }
            } catch (_) {}
        },
        persistPrintPreferences() {
            try {
                localStorage.setItem(this.getPrintPreferencesKey(), JSON.stringify({
                    mode: String(this.selectedPrintMode || 'auto').toLowerCase(),
                    escpos_width: String(this.selectedEscposWidth || '48') === '32' ? '32' : '48'
                }));
            } catch (_) {}
            this.persistPrinterPreference();
        },
        persistPrinterPreference() {
            try {
                const printer = String(this.selectedNativePrinter || '').trim();
                localStorage.setItem(this.getPrinterPreferencesKey(), JSON.stringify({
                    printer,
                    printer_normalized: this.normalizePrinterName(printer)
                }));
            } catch (_) {}
        },
        normalizePrinterName(name) {
            return String(name || '').trim().toLowerCase().replace(/\s+/g, ' ');
        },
        findStoredPrinterMatch(printerName, printerList = null) {
            const wanted = this.normalizePrinterName(printerName);
            const list = Array.isArray(printerList) ? printerList : this.nativePrinters;
            if (!wanted || !Array.isArray(list) || !list.length) return '';

            const exact = list.find(item => this.normalizePrinterName(item) === wanted);
            if (exact) return String(exact);

            const contains = list.find(item => {
                const current = this.normalizePrinterName(item);
                return current.includes(wanted) || wanted.includes(current);
            });
            return contains ? String(contains) : '';
        },
        applyDetectedEscposWidth(printerName, force = false) {
            const key = String(printerName || '').trim();
            if (!key) return;
            const detail = this.nativePrinterDetails[key] || null;
            const detected = String(detail?.escpos_width || '').trim();
            if (detected !== '32' && detected !== '48') return;
            if (!force && this.hasStoredEscposWidth()) return;
            this.selectedEscposWidth = detected;
            this.persistPrintPreferences();
        },
        inferEscposWidthByPrinterName(printerName) {
            const normalized = this.normalizePrinterName(printerName);
            if (normalized.includes('58')) return '32';
            if (normalized.includes('80')) return '48';
            return String(this.selectedEscposWidth || '48') === '32' ? '32' : '48';
        },
        hasStoredEscposWidth() {
            try {
                const raw = localStorage.getItem(this.getPrintPreferencesKey());
                const prefs = raw ? JSON.parse(raw) : {};
                return prefs?.escpos_width === '32' || prefs?.escpos_width === '48';
            } catch (_) {
                return false;
            }
        },
        async initNativePrinter() {
            try {
                if (typeof window.SmxPrinter !== 'function') return;
                this.smxPrinter = new window.SmxPrinter({ strategy: 'agent-only' });
                await this.smxPrinter.connect();
                this.nativePrintConnected = this.smxPrinter.isActive();
                this.nativePrintProvider = this.smxPrinter.getProviderLabel();
                await this.refreshNativePrinters();
            } catch (e) {
                this.nativePrintConnected = false;
                this.nativePrintProvider = 'N/A';
                this.nativePrinters = [];
            }
        },
        async refreshNativePrinters() {
            this.nativePrintStatus = '';
            this.nativePrintStatusType = '';
            try {
                if (!this.smxPrinter) {
                    await this.initNativePrinter();
                }
                if (!this.smxPrinter) throw new Error('Agent no disponible');
                if (!this.smxPrinter.isActive()) {
                    await this.smxPrinter.connect();
                }
                this.nativePrintConnected = this.smxPrinter.isActive();
                this.nativePrintProvider = this.smxPrinter.getProviderLabel();
                this.nativePrinters = await this.smxPrinter.findPrinters();
                this.nativePrinterDetails = {};
                try {
                    const details = await this.smxPrinter.getPrinterDetails();
                    if (Array.isArray(details)) {
                        details.forEach(detail => {
                            const name = String(detail?.name || '').trim();
                            if (!name) return;
                            const escposWidth = Number(detail?.escpos_width || 0);
                            this.nativePrinterDetails[name] = {
                                name,
                                paper_width_mm: Number(detail?.paper_width_mm || 0),
                                escpos_width: escposWidth === 32 ? '32' : (escposWidth === 48 ? '48' : ''),
                                detected_by: String(detail?.detected_by || '').trim()
                            };
                        });
                    }
                } catch (_) {}
                const matchedPrinter = this.findStoredPrinterMatch(this.selectedNativePrinter, this.nativePrinters);
                if (matchedPrinter) {
                    this.selectedNativePrinter = matchedPrinter;
                    if (!this.nativePrinterDetails[matchedPrinter]?.escpos_width && !this.hasStoredEscposWidth()) {
                        this.selectedEscposWidth = this.inferEscposWidthByPrinterName(matchedPrinter);
                    }
                    this.applyDetectedEscposWidth(matchedPrinter);
                    this.persistPrintPreferences();
                    this.persistPrinterPreference();
                } else if (!this.selectedNativePrinter && this.nativePrinters.length) {
                    this.selectedNativePrinter = this.nativePrinters[0];
                    if (!this.nativePrinterDetails[this.nativePrinters[0]]?.escpos_width && !this.hasStoredEscposWidth()) {
                        this.selectedEscposWidth = this.inferEscposWidthByPrinterName(this.nativePrinters[0]);
                    }
                    this.applyDetectedEscposWidth(this.nativePrinters[0]);
                    this.persistPrintPreferences();
                    this.persistPrinterPreference();
                }
            } catch (e) {
                this.nativePrintConnected = false;
                this.nativePrinters = [];
                this.nativePrinterDetails = {};
                this.nativePrintStatus = e?.message || 'No se pudieron cargar impresoras';
                this.nativePrintStatusType = 'error';
            }
        },
        getAgGridLocaleText() {
            return window.SmxAgGridLocale?.getLocaleText?.() || {};
        },
        async initAgGrid() {
            await window.__agGridReady;
            const self = this;
            this.gridApi = agGrid.createGrid(this.$refs.grid, {
                defaultColDef: {
                    sortable: true,
                    filter: true,
                    resizable: true,
                    minWidth: 120,
                    flex: 1
                },
                rowData: [],
                animateRows: true,
                rowHeight: 64,
                localeText: this.getAgGridLocaleText(),
                suppressCellFocus: true,
                getContextMenuItems: params => self.getContextMenuItems(params),
                overlayNoRowsTemplate: `<span style="padding:12px;color:#94a3b8;">No se encontraron ${self.config.document_label?.toLowerCase?.() || 'documentos'}</span>`,
                columnDefs: this.getColumnDefs()
            });
            await this.loadRows();
        },
        getColumnDefs() {
            const self = this;
            return [
                { headerName: 'ID', field: 'id_factura', maxWidth: 110, type: 'numericColumn' },
                {
                    headerName: 'Número',
                    field: 'nro_factura',
                    minWidth: 180,
                    cellRenderer(params) {
                        const data = params.data || {};
                        return `<div class="doc-cell-stack"><span class="main">${self.escapeHtml(data.nro_factura || 'S/N')}</span><span class="meta">ID ${self.escapeHtml(data.id_factura || '-')}</span></div>`;
                    }
                },
                {
                    headerName: 'Fecha',
                    field: 'fecha',
                    minWidth: 170,
                    sort: 'desc',
                    comparator: (a, b) => new Date(a || 0).getTime() - new Date(b || 0).getTime(),
                    cellRenderer(params) {
                        return `<div class="doc-cell-stack"><span class="main">${self.formatDate(params.value)}</span><span class="meta">${self.formatTime(params.value)}</span></div>`;
                    }
                },
                {
                    headerName: self.config.document_label === 'Pedido' ? 'Proveedor' : 'Cliente',
                    field: 'cliente_nombre',
                    minWidth: 260,
                    cellRenderer(params) {
                        const data = params.data || {};
                        const badge = Number(data.estado || 1) === 1
                            ? '<span class="badge badge-ok">Activo</span>'
                            : '<span class="badge badge-off">Anulado</span>';
                        return `<div class="doc-cell-stack"><span class="main">${self.escapeHtml(data.cliente_nombre || '-')}</span><span class="meta">${badge}</span></div>`;
                    }
                },
                { headerName: 'RUC', field: 'cliente_ruc', minWidth: 140 },
                {
                    headerName: 'Total',
                    field: 'total',
                    minWidth: 150,
                    type: 'numericColumn',
                    valueFormatter: params => `${self.formatMoney(params.value)} Gs`,
                    cellStyle: { textAlign: 'right', fontWeight: '700' }
                }
            ];
        },
        getContextMenuItems(params) {
            const data = params?.node?.data;
            if (!data) return ['copy', 'copyWithHeaders', 'export'];
            return [
                {
                    name: 'Editar',
                    icon: this.buildMenuIcon('fas fa-edit', '#60a5fa'),
                    action: () => this.openEditor(data)
                },
                {
                    name: 'Imprimir',
                    icon: this.buildMenuIcon('fas fa-print', '#34d399'),
                    action: () => this.openDocumentActionsModal(data)
                },
                {
                    name: 'Anular',
                    icon: this.buildMenuIcon('fas fa-ban', '#fb7185'),
                    action: () => this.anularRow(data)
                },
                'separator',
                'copy',
                'copyWithHeaders',
                'export'
            ];
        },
        buildMenuIcon(iconClass, color) {
            const wrapper = document.createElement('span');
            wrapper.style.display = 'inline-flex';
            wrapper.style.alignItems = 'center';
            wrapper.style.justifyContent = 'center';
            wrapper.style.width = '16px';
            wrapper.style.color = color;

            const icon = document.createElement('i');
            String(iconClass || '').split(/\s+/).filter(Boolean).forEach(cls => icon.classList.add(cls));
            icon.setAttribute('aria-hidden', 'true');

            wrapper.appendChild(icon);
            return wrapper;
        },
        async loadRows() {
            const params = new URLSearchParams({
                action: 'list',
                q: this.q || '',
                estado: this.estado,
                periodo: this.periodo,
                fecha_desde: this.fechaDesde || '',
                fecha_hasta: this.fechaHasta || ''
            });
            const res = await fetch(`${this.config.api_endpoint}?${params.toString()}`, { cache: 'no-store' });
            const data = await res.json();
            if (!data?.success) {
                alert(data?.message || 'No se pudo cargar la lista');
                return;
            }
            this.rows = Array.isArray(data.rows) ? data.rows : [];
            this.gridApi.setGridOption('rowData', this.rows);
            if (!this.rows.length) {
                this.gridApi.showNoRowsOverlay();
            } else {
                this.gridApi.hideOverlay();
            }
        },
        handlePeriodoChange(reload = true) {
            const now = new Date();
            const fmt = d => d.toISOString().slice(0, 10);
            if (this.periodo === 'hoy') {
                this.fechaDesde = fmt(now);
                this.fechaHasta = fmt(now);
            } else if (this.periodo === 'semana') {
                const d = new Date(now);
                const day = d.getDay() || 7;
                d.setDate(d.getDate() - day + 1);
                this.fechaDesde = fmt(d);
                this.fechaHasta = fmt(now);
            } else if (this.periodo === 'mes') {
                this.fechaDesde = fmt(new Date(now.getFullYear(), now.getMonth(), 1));
                this.fechaHasta = fmt(now);
            } else if (this.periodo === 'anio') {
                this.fechaDesde = fmt(new Date(now.getFullYear(), 0, 1));
                this.fechaHasta = fmt(now);
            } else if (this.periodo === 'todo') {
                this.fechaDesde = '';
                this.fechaHasta = '';
            }
            if (reload) this.loadRows();
        },
        openNew() {
            window.open(this.config.editor_path, '_blank');
        },
        openEditor(row) {
            window.open(`${this.config.editor_path}?edit_id=${encodeURIComponent(row.id_factura)}`, '_blank');
        },
        async openDocumentActionsModal(row) {
            this.documentActionRow = row || null;
            this.documentActionEmail = '';
            this.documentActionPhone = '';
            this.documentActionCountryCode = '595';
            this.documentEmailStatus = '';
            this.documentEmailStatusType = '';
            this.nativePrintStatus = '';
            this.nativePrintStatusType = '';
            this.restorePrintPreferences();
            this.showDocumentActionsModal = true;
            await this.refreshNativePrinters();
            this.applyDetectedEscposWidth(this.selectedNativePrinter);
            await this.hydrateDocumentContact(row);
        },
        closeDocumentActionsModal() {
            this.showDocumentActionsModal = false;
            this.documentActionRow = null;
            this.documentActionEmail = '';
            this.documentActionPhone = '';
            this.documentActionCountryCode = '595';
            this.sendingDocumentEmail = false;
            this.documentEmailStatus = '';
            this.documentEmailStatusType = '';
            this.nativePrintStatus = '';
            this.nativePrintStatusType = '';
        },
        async hydrateDocumentContact(row) {
            const id = Number(row?.id_factura || 0);
            if (!id) return;
            try {
                const res = await fetch(`${this.config.api_endpoint}?action=load&id=${encodeURIComponent(id)}`, { cache: 'no-store' });
                const data = await res.json();
                const cliente = data?.cliente || {};
                this.documentActionEmail = String(cliente?.email || '').trim();
                this.applyPhoneValue(String(cliente?.telefono || '').trim());
            } catch (e) {
                console.warn('No se pudo cargar contacto del documento:', e);
            }
        },
        applyPhoneValue(rawPhone) {
            const digits = String(rawPhone || '').replace(/\D/g, '');
            if (!digits) {
                this.documentActionCountryCode = '595';
                this.documentActionPhone = '';
                return;
            }

            const countriesSorted = [...this.countries].sort((a, b) => String(b.code).length - String(a.code).length);
            const matched = countriesSorted.find(country => digits.startsWith(String(country.code)));
            if (matched) {
                this.documentActionCountryCode = String(matched.code);
                this.documentActionPhone = digits.slice(String(matched.code).length);
                return;
            }

            this.documentActionCountryCode = '595';
            this.documentActionPhone = digits;
        },
        async printRow(row) {
            if (!row) return;
            this.nativePrintStatus = '';
            this.nativePrintStatusType = '';
            try {
                let printer = String(this.selectedNativePrinter || '').trim();
                if (!printer && (!this.smxPrinter || !this.smxPrinter.isActive())) {
                    await this.initNativePrinter();
                }
                if (!printer && this.smxPrinter && this.smxPrinter.isActive()) {
                    const list = await this.smxPrinter.findPrinters();
                    if (!Array.isArray(list) || !list.length) {
                        throw new Error('No hay impresoras disponibles en el sistema');
                    }
                    printer = String(list[0] || '').trim();
                    this.selectedNativePrinter = printer;
                    this.persistPrinterPreference();
                }
                const profile = this.resolvePrintProfile(printer);
                if (profile.mode === 'document') {
                    const url = this.buildDocumentPrintUrl(row, profile.paper, true);
                    this.nativePrintStatus = `Impresora de hoja detectada. Se abrira el documento en ${profile.paper.toUpperCase()} para imprimir.`;
                    this.nativePrintStatusType = 'ok';
                    window.open(url, '_blank', 'noopener');
                    return;
                }
                if (!this.smxPrinter) {
                    await this.initNativePrinter();
                }
                if (!this.smxPrinter || !this.smxPrinter.isActive()) {
                    throw new Error('Sistemax Agent no esta conectado');
                }
                const escposWidth = String(this.selectedEscposWidth || '48') === '32' ? '32' : '48';
                const ticketUrl = `${this.config.print_path}?id=${encodeURIComponent(row.id_factura)}&id_empresa=<?= (int)Session::get('id_empresa') ?>&width=${encodeURIComponent(escposWidth)}`;
                const res = await fetch(ticketUrl, { cache: 'no-store' });
                const data = await res.json();
                if (!data?.success || !data?.data) {
                    throw new Error(data?.message || 'No se pudo generar ticket ESC/POS');
                }
                const found = await this.smxPrinter.findPrinters(printer);
                if (!Array.isArray(found) || !found.length) {
                    throw new Error(`Impresora "${printer}" no encontrada`);
                }
                this.persistPrinterPreference();
                await this.smxPrinter.printRaw(found[0], data.data);
                this.nativePrintConnected = this.smxPrinter.isActive();
                this.nativePrintProvider = this.smxPrinter.getProviderLabel();
                this.nativePrintStatus = `Impresion termica enviada a ${found[0]}`;
                this.nativePrintStatusType = 'ok';
            } catch (e) {
                this.nativePrintConnected = this.smxPrinter ? this.smxPrinter.isActive() : false;
                this.nativePrintProvider = this.nativePrintConnected && this.smxPrinter ? this.smxPrinter.getProviderLabel() : 'N/A';
                this.nativePrintStatus = e?.message || 'No se pudo imprimir de forma nativa';
                this.nativePrintStatusType = 'error';
                window.open(this.buildDocumentPrintUrl(row, 'a4', false), '_blank', 'noopener');
            }
        },
        buildDocumentPrintUrl(row, paper = 'a4', autoPrint = false) {
            const safePaper = String(paper || '').toLowerCase() === 'a5' ? 'a5' : 'a4';
            const auto = autoPrint ? '&autoprint=1' : '';
            return `${this.config.print_path}?id=${encodeURIComponent(row.id_factura)}&id_empresa=<?= (int)Session::get('id_empresa') ?>&pdf=1&paper=${safePaper}${auto}`;
        },
        buildDocumentPdfUrl(row) {
            return `${window.location.origin}${this.config.print_path}?id=${encodeURIComponent(row.id_factura)}&id_empresa=<?= (int)Session::get('id_empresa') ?>&pdf=1`;
        },
        inferPrinterProfile(printerName) {
            const raw = String(printerName || '').trim();
            const normalized = raw.toLowerCase();
            const thermalHints = ['tm-', 'epson tm', 'ticket', 'receipt', 'thermal', 'termica', '58mm', '80mm', 'xprinter', 'bixolon', 'sunmi', 'zjiang', 'pos-', 'pos ', 'ec-line', 'xp-'];
            const documentHints = ['a4', 'a5', 'laser', 'laserjet', 'deskjet', 'officejet', 'ink', 'canon', 'brother', 'xerox', 'ricoh', 'kyocera', 'pantum', 'samsung', 'ecotank', 'epson l', 'epson wf', 'pdf'];
            const isThermal = thermalHints.some(hint => normalized.includes(hint));
            const isDocument = documentHints.some(hint => normalized.includes(hint));
            const paper = normalized.includes('a5') ? 'a5' : 'a4';
            if (isThermal && !isDocument) {
                return { mode: 'thermal', paper: 'a4' };
            }
            return { mode: 'document', paper };
        },
        resolvePrintProfile(printerName) {
            const mode = String(this.selectedPrintMode || 'auto').toLowerCase();
            if (mode === 'escpos') {
                return { mode: 'thermal', paper: 'a4' };
            }
            if (mode === 'a5') {
                return { mode: 'document', paper: 'a5' };
            }
            if (mode === 'a4') {
                return { mode: 'document', paper: 'a4' };
            }
            return this.inferPrinterProfile(printerName);
        },
        buildDocumentShareSubject(row) {
            const label = this.config.document_label || 'Documento';
            const nro = row?.nro_factura || ('#' + (row?.id_factura || ''));
            return `${label} ${nro}`;
        },
        buildDocumentShareMessage(row) {
            const label = this.config.document_label || 'Documento';
            const nro = row?.nro_factura || ('#' + (row?.id_factura || ''));
            const cliente = row?.cliente_nombre || '-';
            const total = `${this.formatMoney(row?.total || 0)} Gs`;
            const fecha = this.formatDate(row?.fecha || '');
            const pdfUrl = this.buildDocumentPdfUrl(row);
            return `${label}: ${nro}\nCliente: ${cliente}\nFecha: ${fecha}\nTotal: ${total}\nPDF: ${pdfUrl}`;
        },
        async sendDocumentByEmail() {
            const row = this.documentActionRow;
            if (!row || this.sendingDocumentEmail) return;
            const email = String(this.documentActionEmail || '').trim();
            if (!email || !email.includes('@')) {
                alert('Ingrese un email valido');
                return;
            }
            this.sendingDocumentEmail = true;
            this.documentEmailStatus = '';
            this.documentEmailStatusType = '';
            try {
                const res = await fetch('/public/pos/api/documentos_send_email.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        id_factura: Number(row.id_factura || 0),
                        email,
                        print_path: this.config.print_path,
                        document_label: this.config.document_label || 'Documento',
                        document_number: row.nro_factura || '',
                        cliente: row.cliente_nombre || '',
                        fecha: this.formatDate(row.fecha || ''),
                        total: `${this.formatMoney(row.total || 0)} Gs`
                    })
                });
                const raw = await res.text();
                let data = null;
                try {
                    data = raw ? JSON.parse(raw) : null;
                } catch (e) {
                    throw new Error('Respuesta invalida del servidor');
                }
                if (!data?.success) {
                    throw new Error(data?.error || 'No se pudo enviar el email');
                }
                this.documentEmailStatus = data.message || 'Email enviado correctamente';
                this.documentEmailStatusType = 'ok';
            } catch (e) {
                this.documentEmailStatus = e?.message || 'No se pudo enviar el email';
                this.documentEmailStatusType = 'error';
            } finally {
                this.sendingDocumentEmail = false;
            }
        },
        sendDocumentByWhatsApp() {
            const row = this.documentActionRow;
            if (!row) return;
            const rawPhone = String(this.documentActionPhone || '').trim();
            if (!rawPhone) {
                alert('Ingrese un numero para WhatsApp');
                return;
            }
            const phoneLocal = rawPhone.replace(/\D/g, '');
            const countryCode = String(this.documentActionCountryCode || '').replace(/\D/g, '');
            const phone = `${countryCode}${phoneLocal}`;
            if (!phone) {
                alert('Ingrese un numero valido para WhatsApp');
                return;
            }
            const message = this.buildDocumentShareMessage(row);
            window.open(`https://wa.me/${phone}?text=${encodeURIComponent(message)}`, '_blank', 'noopener');
        },
        async anularRow(row) {
            if (Number(row?.estado || 1) !== 1) {
                alert(`${this.config.document_label} ya anulado`);
                return;
            }
            if (!window.confirm(`¿Anular ${this.config.document_label.toLowerCase()} ${row.nro_factura || ('#' + row.id_factura)}?`)) return;
            const res = await fetch(`${this.config.api_endpoint}?action=anular`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_factura: Number(row.id_factura || 0) })
            });
            const data = await res.json();
            if (!data?.success) {
                alert(data?.message || `No se pudo anular ${this.config.document_label.toLowerCase()}`);
                return;
            }
            await this.loadRows();
        },
        formatDate(value) {
            if (!value) return '';
            const d = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) return this.escapeHtml(String(value));
            return d.toLocaleDateString();
        },
        formatTime(value) {
            if (!value) return '';
            const d = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) return '';
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },
        formatMoney(value) {
            const n = Number(value || 0);
            return new Intl.NumberFormat('es-PY').format(n);
        },
        escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    };
}
</script>
</body>
</html>
