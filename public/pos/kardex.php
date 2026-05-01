<?php
require_once __DIR__ . '/../../config/bootstrap.php';

if (!Session::isLoggedIn()) {
    header('Location: /');
    exit;
}

$idProducto = (int)($_GET['id'] ?? 0);
$idSucursal = (int)($_GET['id_sucursal'] ?? 0);
$idEmpresa = (int)($_GET['id_empresa'] ?? Session::getIdEmpresa());
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kardex de Producto</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #0b1220; color: #e5e7eb; }
        .wrap { width: 100%; max-width: none; margin: 0; padding: 12px; box-sizing: border-box; }
        .card { background: #111a2c; border: 1px solid #24324d; border-radius: 14px; overflow: hidden; }
        .head { padding: 14px 16px; background: linear-gradient(120deg, #1d4ed8, #0ea5e9); }
        .title { font-size: 18px; font-weight: 700; color: #fff; }
        .sub { font-size: 13px; color: #dbeafe; margin-top: 2px; }
        .head-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .stock-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 6px 10px;
            background: rgba(15, 23, 42, 0.35);
            border: 1px solid rgba(219, 234, 254, 0.35);
            color: #dbeafe;
            font-size: 12px;
            font-weight: 700;
        }
        .badge-group { display: inline-flex; gap: 8px; flex-wrap: wrap; }
        .toolbar { padding: 10px 16px; border-bottom: 1px solid #24324d; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .search-box { flex: 1 1 280px; min-width: 240px; position: relative; }
        .search-input {
            width: 100%;
            background: #334155;
            border: 1px solid #475569;
            color: #e2e8f0;
            border-radius: 8px;
            height: 38px;
            padding: 0 12px;
            font-size: 14px;
            outline: none;
        }
        .search-input:focus { border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2); }
        .search-suggestions {
            position: absolute;
            top: 42px;
            left: 0;
            right: 0;
            z-index: 40;
            background: #0f1a30;
            border: 1px solid #2c3b5a;
            border-radius: 10px;
            box-shadow: 0 16px 30px rgba(0, 0, 0, 0.45);
            overflow: hidden;
            display: none;
        }
        .search-suggestions.show { display: block; }
        .s-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            border: 0;
            border-bottom: 1px solid #1d2b47;
            background: transparent;
            color: #e2e8f0;
            text-align: left;
            padding: 9px 10px;
            cursor: pointer;
            font-size: 13px;
        }
        .s-item:last-child { border-bottom: 0; }
        .s-item:hover, .s-item.active { background: #1a2a49; }
        .s-label { font-weight: 600; }
        .s-meta {
            font-size: 11px;
            color: #93c5fd;
            background: #1d3a66;
            border: 1px solid #335d94;
            border-radius: 999px;
            padding: 2px 7px;
            white-space: nowrap;
        }
        .range-group {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #263247;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 3px;
            flex-wrap: wrap;
        }
        .range-btn {
            border: 0;
            background: transparent;
            color: #94a3b8;
            font-weight: 700;
            font-size: 13px;
            border-radius: 6px;
            padding: 7px 10px;
            cursor: pointer;
        }
        .range-btn.active { background: #335d94; color: #bfdbfe; }
        .toolbar-select {
            background: #334155;
            border: 1px solid #475569;
            color: #e2e8f0;
            border-radius: 8px;
            height: 38px;
            padding: 0 10px;
            font-size: 14px;
            min-width: 140px;
        }
        .toolbar-actions { margin-left: auto; display: inline-flex; gap: 8px; }
        .custom-range { display: none; align-items: center; gap: 6px; }
        .custom-range.show { display: inline-flex; }
        .date-input {
            background: #334155;
            border: 1px solid #475569;
            color: #e2e8f0;
            border-radius: 8px;
            height: 38px;
            padding: 0 10px;
            font-size: 13px;
        }
        .btn { border: 0; border-radius: 8px; padding: 8px 12px; cursor: pointer; font-weight: 600; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-muted { background: #334155; color: #e2e8f0; }
        .table-wrap { overflow: auto; max-height: calc(100vh - 220px); }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #1f2a44; white-space: nowrap; }
        th { position: sticky; top: 0; background: #18243b; text-align: left; color: #93c5fd; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable .sort-ind { margin-left: 6px; color: #64748b; font-size: 11px; }
        th.sortable.active .sort-ind { color: #93c5fd; }
        .right { text-align: right; }
        .tag { padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .in { background: #064e3b; color: #6ee7b7; }
        .out { background: #7f1d1d; color: #fca5a5; }
        .adj { background: #374151; color: #cbd5e1; }
        tr.row-in td { background: rgba(16, 185, 129, 0.12); }
        tr.row-out td { background: rgba(239, 68, 68, 0.12); }
        .muted { color: #94a3b8; }
        .center { text-align: center; padding: 28px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="head">
            <div class="head-row">
                <div class="title">Kardex de Movimientos</div>
                <div class="badge-group">
                    <div id="stockArranqueBadge" class="stock-badge">Stock arranque: 0</div>
                    <div id="stockActualBadge" class="stock-badge">Stock actual sucursal: 0</div>
                </div>
            </div>
            <div id="subtitle" class="sub">Cargando...</div>
        </div>
        <div class="toolbar">
            <div class="search-box">
                <input id="txtBuscar" class="search-input" type="text" placeholder="Buscar por nro, cliente, usuario...">
                <div id="searchSuggestions" class="search-suggestions"></div>
            </div>
            <div class="range-group">
                <button type="button" class="range-btn active" data-range="todo">Todo</button>
                <button type="button" class="range-btn" data-range="hoy">Hoy</button>
                <button type="button" class="range-btn" data-range="semana">Semana</button>
                <button type="button" class="range-btn" data-range="mes">Mes</button>
                <button type="button" class="range-btn" data-range="anio">Año</button>
                <button type="button" class="range-btn" data-range="custom">Personalizado</button>
            </div>
            <div id="customRange" class="custom-range">
                <input id="desdeFecha" class="date-input" type="date">
                <input id="hastaFecha" class="date-input" type="date">
            </div>
            <select id="selTipo" class="toolbar-select">
                <option value="todos">Todos</option>
                <option value="entrada">Entradas</option>
                <option value="salida">Salidas</option>
                <option value="ajuste">Ajustes</option>
            </select>
            <div class="toolbar-actions">
                <button class="btn btn-primary" onclick="loadKardex()">Actualizar</button>
                <button class="btn btn-muted" onclick="window.close()">Cerrar</button>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="sortable" data-sort="fecha">Fecha <span class="sort-ind">⇅</span></th>
                    <th class="sortable right" data-sort="entrada">Entrada <span class="sort-ind">⇅</span></th>
                    <th class="sortable right" data-sort="salida">Salida <span class="sort-ind">⇅</span></th>
                    <th class="sortable right" data-sort="stock_prorrateo">Stock Prorrateo <span class="sort-ind">⇅</span></th>
                    <th class="sortable right" data-sort="precio">Precio <span class="sort-ind">⇅</span></th>
                    <th class="sortable" data-sort="idfactura">ID Factura <span class="sort-ind">⇅</span></th>
                    <th class="sortable" data-sort="factura_cliente">Cliente <span class="sort-ind">⇅</span></th>
                    <th class="sortable" data-sort="usuario_nombre">Usuario <span class="sort-ind">⇅</span></th>
                </tr>
                </thead>
                <tbody id="rows">
                <tr><td class="center" colspan="8">Cargando kardex...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const ID_PRODUCTO = <?= $idProducto ?>;
const ID_SUCURSAL = <?= $idSucursal ?>;
const ID_EMPRESA = <?= $idEmpresa ?>;
let ALL_ITEMS = [];
let activeRange = 'todo';
let suggestionIndex = -1;
let currentSuggestions = [];
let sortKey = 'fecha';
let sortDir = 'desc';
let STOCK_ACTUAL = 0;
let STOCK_ARRANQUE = 0;

function fmt(n) {
    return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(Number(n || 0));
}

function fmt4(n) {
    return Number(n || 0).toFixed(4);
}

function fmtQty(n) {
    const val = Number(n || 0);
    if (!isFinite(val)) return '0';
    if (Math.abs(val % 1) < 0.0000001) {
        return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(val);
    }
    return new Intl.NumberFormat('es-PY', { minimumFractionDigits: 1, maximumFractionDigits: 4 }).format(val);
}

async function loadKardex() {
    const rows = document.getElementById('rows');
    rows.innerHTML = '<tr><td class="center" colspan="8">Cargando kardex...</td></tr>';
    try {
        const params = new URLSearchParams({
            id: String(ID_PRODUCTO),
            id_sucursal: String(ID_SUCURSAL),
            id_empresa: String(ID_EMPRESA)
        });
        const res = await fetch(`/public/pos/api/producto_kardex.php?${params.toString()}`);
        const data = await res.json();
        if (!data.success) {
            rows.innerHTML = `<tr><td class="center" colspan="8">${data.error || 'No se pudo cargar kardex'}</td></tr>`;
            return;
        }

        const p = data.producto || {};
        const s = data.sucursal || {};
        document.getElementById('subtitle').textContent = `${p.descripcion || ''} · ${s.nombre || ''}`;

        STOCK_ACTUAL = Number(data.stock_actual || 0);
        document.getElementById('stockActualBadge').textContent = `Stock actual sucursal: ${fmtQty(STOCK_ACTUAL)}`;
        ALL_ITEMS = Array.isArray(data.movimientos) ? data.movimientos : [];
        attachProrratedStock(ALL_ITEMS, STOCK_ACTUAL);
        if (ALL_ITEMS.length === 0) {
            rows.innerHTML = '<tr><td class="center" colspan="8">Sin movimientos para este producto/sucursal.</td></tr>';
            return;
        }
        applyFilters();
    } catch (e) {
        rows.innerHTML = '<tr><td class="center" colspan="8">Error de conexión cargando kardex.</td></tr>';
    }
}

function normalizeText(s) {
    return String(s || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();
}

function parseDateValue(dateText) {
    if (!dateText) return null;
    const d = new Date(dateText);
    if (!isNaN(d.getTime())) return d;
    const m = String(dateText).match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
    if (m) {
        const day = parseInt(m[1], 10);
        const month = parseInt(m[2], 10) - 1;
        const year = parseInt(m[3], 10);
        return new Date(year, month, day);
    }
    return null;
}

function startOfDay(d) {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}

function inDateRange(itemDate) {
    if (activeRange === 'todo') return true;
    const date = parseDateValue(itemDate);
    if (!date) return false;
    const now = new Date();
    const today = startOfDay(now);
    const itemDay = startOfDay(date);

    if (activeRange === 'hoy') {
        return itemDay.getTime() === today.getTime();
    }
    if (activeRange === 'semana') {
        const weekStart = new Date(today);
        weekStart.setDate(today.getDate() - 6);
        return itemDay >= weekStart && itemDay <= today;
    }
    if (activeRange === 'mes') {
        return itemDay.getMonth() === today.getMonth() && itemDay.getFullYear() === today.getFullYear();
    }
    if (activeRange === 'anio') {
        return itemDay.getFullYear() === today.getFullYear();
    }
    if (activeRange === 'custom') {
        const desde = document.getElementById('desdeFecha').value;
        const hasta = document.getElementById('hastaFecha').value;
        if (!desde && !hasta) return true;
        const dDesde = desde ? startOfDay(new Date(`${desde}T00:00:00`)) : null;
        const dHasta = hasta ? startOfDay(new Date(`${hasta}T00:00:00`)) : null;
        if (dDesde && itemDay < dDesde) return false;
        if (dHasta && itemDay > dHasta) return false;
        return true;
    }
    return true;
}

function renderRows(items) {
    const rows = document.getElementById('rows');
    if (items.length === 0) {
        rows.innerHTML = '<tr><td class="center" colspan="8">Sin resultados para el filtro aplicado.</td></tr>';
        return;
    }
    rows.innerHTML = items.map(m => {
        const qtyIn = Number(m.entrada || 0);
        const qtyOut = Number(m.salida || 0);
        const rowCls = qtyIn > 0 ? 'row-in' : (qtyOut > 0 ? 'row-out' : '');
        return `
            <tr class="${rowCls}">
                <td>${m.fecha || '-'}</td>
                <td class="right">${qtyIn > 0 ? fmtQty(qtyIn) : ''}</td>
                <td class="right">${qtyOut > 0 ? fmtQty(qtyOut) : ''}</td>
                <td class="right">${fmtQty(m.stock_prorrateo)}</td>
                <td class="right">${fmt(m.precio)}</td>
                <td>${Number(m.idfactura || 0) > 0 ? Number(m.idfactura || 0) : '-'}</td>
                <td>${m.factura_cliente || '-'}</td>
                <td>${m.usuario_nombre || '-'}</td>
            </tr>
        `;
    }).join('');
}

function getSuggestions(queryText) {
    const q = normalizeText(queryText);
    if (!q || q.length < 2) return [];
    const seen = new Set();
    const pool = [];
    for (const m of ALL_ITEMS) {
        const entries = [
            { type: 'Factura', value: Number(m.idfactura || 0) > 0 ? String(m.idfactura) : '' },
            { type: 'Cliente', value: m.factura_cliente || '' },
            { type: 'Usuario', value: m.usuario_nombre || '' }
        ];
        for (const e of entries) {
            const raw = String(e.value || '').trim();
            if (!raw) continue;
            const key = `${e.type}|${raw.toLowerCase()}`;
            if (seen.has(key)) continue;
            seen.add(key);
            pool.push({ type: e.type, value: raw, norm: normalizeText(raw) });
        }
    }

    return pool
        .map(item => {
            let score = 0;
            if (item.norm.startsWith(q)) score += 100;
            if (item.norm.includes(q)) score += 50;
            for (const token of q.split(/\s+/)) {
                if (!token) continue;
                if (item.norm.startsWith(token)) score += 15;
                else if (item.norm.includes(token)) score += 8;
            }
            return { ...item, score };
        })
        .filter(item => item.score > 0)
        .sort((a, b) => b.score - a.score || a.value.localeCompare(b.value))
        .slice(0, 8);
}

function hideSuggestions() {
    const box = document.getElementById('searchSuggestions');
    box.classList.remove('show');
    box.innerHTML = '';
    currentSuggestions = [];
    suggestionIndex = -1;
}

function renderSuggestions() {
    const box = document.getElementById('searchSuggestions');
    if (!currentSuggestions.length) {
        hideSuggestions();
        return;
    }
    box.innerHTML = currentSuggestions.map((s, i) => `
        <button type="button" class="s-item ${i === suggestionIndex ? 'active' : ''}" data-index="${i}">
            <span class="s-label">${s.value}</span>
            <span class="s-meta">${s.type}</span>
        </button>
    `).join('');
    box.classList.add('show');
    box.querySelectorAll('.s-item').forEach(btn => {
        btn.addEventListener('mousedown', (e) => {
            e.preventDefault();
            const idx = Number(btn.dataset.index || -1);
            if (idx >= 0 && currentSuggestions[idx]) {
                const input = document.getElementById('txtBuscar');
                input.value = currentSuggestions[idx].value;
                hideSuggestions();
                applyFilters();
            }
        });
    });
}

function refreshSuggestions() {
    const input = document.getElementById('txtBuscar');
    currentSuggestions = getSuggestions(input.value);
    suggestionIndex = -1;
    renderSuggestions();
}

function attachProrratedStock(items, stockActual = 0) {
    if (!Array.isArray(items) || items.length === 0) return;
    items.forEach((m, idx) => {
        m.__idx = idx;
    });

    const chrono = [...items].sort((a, b) => {
        const da = parseDateValue(a.fecha);
        const db = parseDateValue(b.fecha);
        const ta = da ? da.getTime() : 0;
        const tb = db ? db.getTime() : 0;
        if (ta !== tb) return ta - tb;
        return Number(a.id_mov || 0) - Number(b.id_mov || 0);
    });

    const deltaLoaded = chrono.reduce((acc, m) => {
        const entrada = Number(m.entrada || 0);
        const salida = Number(m.salida || 0);
        return acc + entrada - salida;
    }, 0);
    STOCK_ARRANQUE = Number(stockActual || 0) - deltaLoaded;
    document.getElementById('stockArranqueBadge').textContent = `Stock arranque: ${fmtQty(STOCK_ARRANQUE)}`;
    let running = STOCK_ARRANQUE;
    for (const m of chrono) {
        const entrada = Number(m.entrada || 0);
        const salida = Number(m.salida || 0);
        running += entrada;
        running -= salida;
        m.stock_prorrateo = running;
    }
}

function getSortValue(item, key) {
    if (key === 'fecha') {
        const d = parseDateValue(item.fecha);
        return d ? d.getTime() : 0;
    }
    if (key === 'entrada' || key === 'salida' || key === 'stock_prorrateo' || key === 'precio' || key === 'idfactura') {
        return Number(item[key] || 0);
    }
    return normalizeText(item[key] || '');
}

function sortItems(items) {
    const dir = sortDir === 'asc' ? 1 : -1;
    return [...items].sort((a, b) => {
        const va = getSortValue(a, sortKey);
        const vb = getSortValue(b, sortKey);
        if (va < vb) return -1 * dir;
        if (va > vb) return 1 * dir;
        return Number(a.__idx || 0) - Number(b.__idx || 0);
    });
}

function updateSortHeaders() {
    document.querySelectorAll('th.sortable').forEach(th => {
        const isActive = th.dataset.sort === sortKey;
        th.classList.toggle('active', isActive);
        const ind = th.querySelector('.sort-ind');
        if (!ind) return;
        if (!isActive) {
            ind.textContent = '⇅';
        } else {
            ind.textContent = sortDir === 'asc' ? '↑' : '↓';
        }
    });
}

function applyFilters() {
    const txt = normalizeText(document.getElementById('txtBuscar').value);
    const tipo = document.getElementById('selTipo').value;
    const filtered = ALL_ITEMS.filter(m => {
        const t = normalizeText(m.tipo_mov || 'Ajuste');
        if (tipo === 'entrada' && t !== 'entrada') return false;
        if (tipo === 'salida' && t !== 'salida') return false;
        if (tipo === 'ajuste' && t !== 'ajuste') return false;
        if (!inDateRange(m.fecha)) return false;
        if (!txt) return true;
        const hay = normalizeText([
            m.fecha, m.tipo_mov, m.factura_text, m.idfactura, m.factura_cliente, m.usuario_nombre
        ].join(' '));
        return hay.includes(txt);
    });
    renderRows(sortItems(filtered));
    updateSortHeaders();
}

function setActiveRange(range) {
    activeRange = range;
    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.range === range);
    });
    const customBox = document.getElementById('customRange');
    customBox.classList.toggle('show', range === 'custom');
    applyFilters();
}

function bindToolbar() {
    const input = document.getElementById('txtBuscar');
    input.addEventListener('input', () => {
        applyFilters();
        refreshSuggestions();
    });
    input.addEventListener('keydown', (e) => {
        if (!currentSuggestions.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            suggestionIndex = Math.min(suggestionIndex + 1, currentSuggestions.length - 1);
            renderSuggestions();
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            suggestionIndex = Math.max(suggestionIndex - 1, 0);
            renderSuggestions();
            return;
        }
        if (e.key === 'Enter' && suggestionIndex >= 0 && currentSuggestions[suggestionIndex]) {
            e.preventDefault();
            input.value = currentSuggestions[suggestionIndex].value;
            hideSuggestions();
            applyFilters();
        }
        if (e.key === 'Escape') {
            hideSuggestions();
        }
    });
    document.getElementById('selTipo').addEventListener('change', applyFilters);
    document.getElementById('desdeFecha').addEventListener('change', applyFilters);
    document.getElementById('hastaFecha').addEventListener('change', applyFilters);
    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.addEventListener('click', () => setActiveRange(btn.dataset.range));
    });
    document.querySelectorAll('th.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const key = th.dataset.sort || '';
            if (!key) return;
            if (sortKey === key) {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                sortKey = key;
                sortDir = (key === 'fecha') ? 'desc' : 'asc';
            }
            applyFilters();
        });
    });
    document.addEventListener('click', (e) => {
        const box = document.querySelector('.search-box');
        if (box && !box.contains(e.target)) {
            hideSuggestions();
        }
    });
}

bindToolbar();
loadKardex();
</script>
</body>
</html>
