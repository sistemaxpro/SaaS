<?php
require_once __DIR__ . '/../../config/bootstrap.php';

if (!Session::isLoggedIn()) {
    header('Location: /');
    exit;
}

$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Afiliacion Tarjeta - Empresa</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto p-4 md:p-6">
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                    class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-400 hover:bg-red-600/20 active:scale-95 transition-all cursor-pointer"
                    title="Salir">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                </svg>
            </button>
            <div>
            <h1 class="text-2xl md:text-3xl font-black">Afiliacion Cobro con Tarjeta</h1>
            <p class="text-sm text-slate-400">Empresa #<?= $idEmpresa ?> | Usuario #<?= $idLogin ?></p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="xl:col-span-2 bg-slate-900 border border-slate-800 rounded-xl p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-bold">Configuraciones Cargadas</h2>
                <button id="btnReload" class="px-3 py-2 rounded-lg bg-sky-700 hover:bg-sky-600 text-xs font-bold">Actualizar</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="text-left py-2">Caja</th>
                        <th class="text-left py-2">Proveedor</th>
                        <th class="text-left py-2">Procesador</th>
                        <th class="text-left py-2">Ambiente</th>
                        <th class="text-left py-2">Estado</th>
                        <th class="text-left py-2">Acciones</th>
                    </tr>
                    </thead>
                    <tbody id="configsBody"></tbody>
                </table>
            </div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-bold">Formulario Afiliacion</h2>
                <button id="btnNew" class="px-3 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-600 text-xs font-bold">Nuevo</button>
            </div>

            <form id="cfgForm" class="space-y-3">
                <input type="hidden" id="id" value="">

                <div>
                    <label class="text-xs text-slate-400">Caja</label>
                    <select id="id_caja" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm"></select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-slate-400">Proveedor</label>
                        <select id="provider" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm">
                            <option value="bancard">Bancard</option>
                            <option value="bepsa">Bepsa</option>
                            <option value="dinelco">Dinelco</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Procesador</label>
                        <input id="processor" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm" value="bancard">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-slate-400">Tipo Terminal</label>
                        <select id="terminal_type" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm">
                            <option value="pinpad">PinPad</option>
                            <option value="smartpos">SmartPOS</option>
                            <option value="tef">TEF</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Ambiente</label>
                        <select id="environment" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm">
                            <option value="sandbox">Sandbox</option>
                            <option value="production">Produccion</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-xs text-slate-400">Bridge Sale URL</label>
                    <input id="bridge_sale_url" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm" placeholder="http://127.0.0.1:8099/sale">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-slate-400">Bridge Status URL</label>
                        <input id="bridge_status_url" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm" placeholder="http://127.0.0.1:8099/status">
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Bridge Reversal URL</label>
                        <input id="bridge_reversal_url" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm" placeholder="http://127.0.0.1:8099/reversal">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-slate-400">Merchant ID</label>
                        <input id="merchant_id" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Terminal ID</label>
                        <input id="terminal_code" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="text-xs text-slate-400">Tarjetas habilitadas</label>
                        <input id="allowed_card_types" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm" value="credito,debito">
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Cuotas max</label>
                        <input id="installments_max" type="number" min="1" max="36" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm" value="12">
                    </div>
                    <div>
                        <label class="text-xs text-slate-400">Timeout (s)</label>
                        <input id="timeout_sec" type="number" min="10" max="120" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm" value="45">
                    </div>
                </div>

                <div>
                    <label class="text-xs text-slate-400">Headers JSON (opcional)</label>
                    <textarea id="bridge_headers_json" rows="2" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm" placeholder='{"Authorization":"Bearer ..."}'></textarea>
                </div>

                <div>
                    <label class="text-xs text-slate-400">Notas</label>
                    <textarea id="notes" rows="2" class="w-full mt-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm"></textarea>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input id="enabled" type="checkbox" checked class="accent-emerald-500">
                    <span>Habilitado</span>
                </label>

                <div class="grid grid-cols-3 gap-2 pt-1">
                    <button type="button" id="btnTest" class="py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-xs font-bold">Probar</button>
                    <button type="button" id="btnDelete" class="py-2 rounded-lg bg-rose-700 hover:bg-rose-600 text-xs font-bold">Eliminar</button>
                    <button type="submit" class="py-2 rounded-lg bg-emerald-700 hover:bg-emerald-600 text-xs font-bold">Guardar</button>
                </div>
                <p id="msg" class="text-xs text-slate-300"></p>
            </form>
        </div>
    </div>
</div>

<script>
const API = '/public/pos/api/card_provider_config.php';
const ID_EMPRESA = <?= $idEmpresa ?>;
let state = { configs: [], cajas: [] };

const $ = (id) => document.getElementById(id);

function setMsg(text, ok = true) {
    const el = $('msg');
    el.textContent = text || '';
    el.className = 'text-xs ' + (ok ? 'text-emerald-300' : 'text-rose-300');
}

function formData() {
    return {
        id: $('id').value ? parseInt($('id').value, 10) : 0,
        id_caja: $('id_caja').value === '' ? null : parseInt($('id_caja').value, 10),
        provider: $('provider').value.trim(),
        processor: $('processor').value.trim(),
        terminal_type: $('terminal_type').value.trim(),
        bridge_sale_url: $('bridge_sale_url').value.trim(),
        bridge_status_url: $('bridge_status_url').value.trim(),
        bridge_reversal_url: $('bridge_reversal_url').value.trim(),
        merchant_id: $('merchant_id').value.trim(),
        terminal_code: $('terminal_code').value.trim(),
        environment: $('environment').value.trim(),
        allowed_card_types: $('allowed_card_types').value.trim(),
        installments_max: parseInt($('installments_max').value || '12', 10),
        timeout_sec: parseInt($('timeout_sec').value || '45', 10),
        bridge_headers_json: $('bridge_headers_json').value.trim(),
        notes: $('notes').value.trim(),
        enabled: $('enabled').checked ? 1 : 0
    };
}

function clearForm() {
    $('cfgForm').reset();
    $('id').value = '';
    $('provider').value = 'bancard';
    $('processor').value = 'bancard';
    $('terminal_type').value = 'pinpad';
    $('environment').value = 'sandbox';
    $('installments_max').value = '12';
    $('timeout_sec').value = '45';
    $('allowed_card_types').value = 'credito,debito';
    $('enabled').checked = true;
    setMsg('');
}

function fillForm(row) {
    $('id').value = row.id || '';
    $('id_caja').value = row.id_caja ?? '';
    $('provider').value = row.provider || 'bancard';
    $('processor').value = row.processor || 'bancard';
    $('terminal_type').value = row.terminal_type || 'pinpad';
    $('bridge_sale_url').value = row.bridge_sale_url || '';
    $('bridge_status_url').value = row.bridge_status_url || '';
    $('bridge_reversal_url').value = row.bridge_reversal_url || '';
    $('merchant_id').value = row.merchant_id || '';
    $('terminal_code').value = row.terminal_code || '';
    $('environment').value = row.environment || 'sandbox';
    $('allowed_card_types').value = row.allowed_card_types || 'credito,debito';
    $('installments_max').value = row.installments_max || 12;
    $('timeout_sec').value = row.timeout_sec || 45;
    $('bridge_headers_json').value = row.bridge_headers_json || '';
    $('notes').value = row.notes || '';
    $('enabled').checked = Number(row.enabled || 0) === 1;
    setMsg('Editando configuracion #' + row.id);
}

function renderCajas() {
    const select = $('id_caja');
    select.innerHTML = '';
    const optGlobal = document.createElement('option');
    optGlobal.value = '';
    optGlobal.textContent = 'General (todas las cajas)';
    select.appendChild(optGlobal);
    state.cajas.forEach(c => {
        const o = document.createElement('option');
        o.value = c.id_caja;
        o.textContent = `${c.id_caja} - ${c.nombre || ('Caja #' + c.id_caja)}`;
        select.appendChild(o);
    });
}

function renderConfigs() {
    const body = $('configsBody');
    body.innerHTML = '';
    if (!state.configs.length) {
        body.innerHTML = '<tr><td colspan="6" class="py-3 text-slate-500">Sin configuraciones cargadas.</td></tr>';
        return;
    }
    state.configs.forEach(cfg => {
        const tr = document.createElement('tr');
        tr.className = 'border-b border-slate-900';
        const cajaTxt = (cfg.id_caja === null || cfg.id_caja === '' || Number(cfg.id_caja) === 0) ? 'General' : ('Caja #' + cfg.id_caja);
        tr.innerHTML = `
            <td class="py-2">${cajaTxt}</td>
            <td class="py-2">${cfg.provider || '-'}</td>
            <td class="py-2">${cfg.processor || '-'}</td>
            <td class="py-2">${cfg.environment || '-'}</td>
            <td class="py-2">${Number(cfg.enabled||0) ? '<span class="text-emerald-300">Activo</span>' : '<span class="text-rose-300">Inactivo</span>'}</td>
            <td class="py-2">
                <button class="px-2 py-1 rounded bg-slate-800 hover:bg-slate-700 text-xs font-bold" data-edit="${cfg.id}">Editar</button>
            </td>
        `;
        body.appendChild(tr);
    });

    body.querySelectorAll('[data-edit]').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = Number(btn.getAttribute('data-edit'));
            const row = state.configs.find(x => Number(x.id) === id);
            if (row) fillForm(row);
        });
    });
}

async function loadAll() {
    setMsg('Cargando...');
    const res = await fetch(`${API}?action=bootstrap&id_empresa=${ID_EMPRESA}`);
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error cargando datos');
    state.configs = json.data.configs || [];
    state.cajas = json.data.cajas || [];
    renderCajas();
    renderConfigs();
    setMsg('Datos cargados');
}

async function saveConfig(e) {
    e.preventDefault();
    setMsg('Guardando...');
    const res = await fetch(`${API}?action=save&id_empresa=${ID_EMPRESA}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData())
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error guardando');
    setMsg(json.message || 'Guardado');
    await loadAll();
    if (json.data && json.data.id) {
        const row = state.configs.find(x => Number(x.id) === Number(json.data.id));
        if (row) fillForm(row);
    }
}

async function testConfig() {
    setMsg('Probando conexion...');
    const res = await fetch(`${API}?action=test&id_empresa=${ID_EMPRESA}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData())
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error en prueba');
    const d = json.data || {};
    setMsg(`Test OK | Sale HTTP: ${d.sale?.http ?? '-'} | Status HTTP: ${d.status?.http ?? '-'} | Reversal HTTP: ${d.reversal?.http ?? '-'}`);
}

async function deleteConfig() {
    const id = Number($('id').value || 0);
    if (!id) {
        setMsg('Seleccione una configuracion para eliminar', false);
        return;
    }
    if (!confirm('¿Eliminar configuracion #' + id + '?')) return;
    setMsg('Eliminando...');
    const res = await fetch(`${API}?action=delete&id_empresa=${ID_EMPRESA}&id=${id}`, { method: 'POST' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error eliminando');
    clearForm();
    await loadAll();
    setMsg('Configuracion eliminada');
}

document.addEventListener('DOMContentLoaded', async () => {
    $('cfgForm').addEventListener('submit', async (e) => {
        try { await saveConfig(e); } catch (err) { setMsg(err.message, false); }
    });
    $('btnReload').addEventListener('click', async () => {
        try { await loadAll(); } catch (err) { setMsg(err.message, false); }
    });
    $('btnNew').addEventListener('click', clearForm);
    $('btnTest').addEventListener('click', async () => {
        try { await testConfig(); } catch (err) { setMsg(err.message, false); }
    });
    $('btnDelete').addEventListener('click', async () => {
        try { await deleteConfig(); } catch (err) { setMsg(err.message, false); }
    });

    try {
        clearForm();
        await loadAll();
    } catch (err) {
        setMsg(err.message, false);
    }
});
</script>
</body>
</html>
