<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
$empresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $idEmpresa));
$userName = (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tracking Móvil</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <style>
        [x-cloak] { display: none !important; }
        html, body { min-height: 100%; }
        #trackingMap { min-height: 420px; height: 100%; }
        .leaflet-container { background: #020617; }
        .smx-tracking-marker {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            line-height: 1;
            background: transparent;
            border: 0;
            box-shadow: none;
            position: relative;
        }
        .smx-tracking-marker-icon {
            width: 34px;
            height: 34px;
            position: relative;
            display: block;
            filter: drop-shadow(0 4px 10px rgba(2, 6, 23, 0.45));
        }
        .smx-tracking-marker-icon img,
        .smx-icon-preview img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }
        .smx-tracking-marker-text {
            max-width: 96px;
            font-size: 11px;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: 0.02em;
            color: #e2e8f0;
            text-shadow: 0 2px 8px rgba(2, 6, 23, 0.85);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .smx-tracking-marker-wrap {
            background: transparent;
            border: 0;
        }
        .smx-icon-preview {
            width: 72px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 0;
            box-shadow: none;
        }
        @media (max-width: 1279px) {
            #trackingMap { min-height: 72vh; }
        }
        @media (min-width: 1280px) {
            #trackingMap { min-height: 78vh; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="mobileTrackingApp()">
<div class="min-h-screen w-full px-3 py-3 md:px-4 md:py-4 xl:flex xl:flex-col">
    <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-2xl font-black tracking-tight">Tracking Móvil</h1>
            <p class="text-sm text-slate-400"><?= htmlspecialchars($empresaName, ENT_QUOTES, 'UTF-8') ?> · Usuario <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="loadPositions(true)" class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-semibold text-slate-100 hover:bg-slate-800">Actualizar</button>
            <button type="button" @click="fitAll()" class="rounded-xl border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm font-semibold text-cyan-200 hover:bg-cyan-500/20">Ver todos</button>
            <button type="button" onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';" class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-semibold text-slate-100 hover:bg-slate-800">Cerrar</button>
        </div>
    </div>

    <div class="mb-3 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-cyan-800/40 bg-slate-900 px-3 py-2.5">
            <div class="text-xs uppercase tracking-[0.22em] text-cyan-300">Dispositivos</div>
            <div class="mt-1 text-2xl font-black" x-text="stats.total">0</div>
        </div>
        <div class="rounded-xl border border-emerald-800/40 bg-slate-900 px-3 py-2.5">
            <div class="text-xs uppercase tracking-[0.22em] text-emerald-300">Online</div>
            <div class="mt-1 text-2xl font-black" x-text="stats.online">0</div>
        </div>
        <div class="rounded-xl border border-amber-800/40 bg-slate-900 px-3 py-2.5">
            <div class="text-xs uppercase tracking-[0.22em] text-amber-300">Tracking Activo</div>
            <div class="mt-1 text-2xl font-black" x-text="stats.tracking">0</div>
        </div>
        <div class="rounded-xl border border-indigo-800/40 bg-slate-900 px-3 py-2.5">
            <div class="text-xs uppercase tracking-[0.22em] text-indigo-300">Mi empresa</div>
            <div class="mt-1 text-2xl font-black" x-text="stats.companies">0</div>
        </div>
    </div>

    <div class="grid flex-1 gap-3 xl:grid-cols-[360px_minmax(0,1fr)]">
        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
            <div class="border-b border-slate-800 px-4 py-3">
                <input x-model="q" @input="filterItems()" type="text" placeholder="Buscar usuario, empresa o dispositivo..." class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
            </div>
            <div class="max-h-[78vh] overflow-y-auto">
                <template x-if="loading">
                    <div class="px-4 py-6 text-sm text-slate-400">Cargando dispositivos...</div>
                </template>
                <template x-if="!loading && filteredItems.length === 0">
                    <div class="px-4 py-6 text-sm text-slate-500">No hay posiciones disponibles.</div>
                </template>
                <template x-for="item in filteredItems" :key="item.id">
                    <div class="block w-full border-b border-slate-800 px-4 py-3 text-left">
                        <div @click="focusItem(item.id)" @keydown.enter.prevent="focusItem(item.id)" @keydown.space.prevent="focusItem(item.id)" tabindex="0" role="button" class="rounded-xl transition hover:bg-slate-800/70 focus:outline-none focus:ring-2 focus:ring-cyan-500/60">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-white" x-text="item.marker_label || item.device_name || 'Dispositivo'"></div>
                                <div class="truncate text-xs text-slate-400" x-text="item.user_name || 'Sin usuario'"></div>
                                <div class="truncate text-[11px] text-slate-500" x-text="item.company_name || ('Empresa #' + item.id_empresa)"></div>
                            </div>
                            <span class="mt-0.5 inline-flex rounded-full px-2 py-1 text-[11px] font-bold" :class="item.status === 'online' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-slate-500/15 text-slate-300'" x-text="item.status || 'offline'"></span>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                            <span x-text="item.platform + (item.app_version ? (' · v' + item.app_version) : '')"></span>
                            <span x-text="formatDate(item.last_fix_at)"></span>
                        </div>
                        </div>
                        <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                            <span class="text-slate-400">Batería: <span class="text-slate-200" x-text="item.battery_pct != null ? (item.battery_pct + '%') : 'N/D'"></span></span>
                            <div class="flex items-center gap-2">
                                <button type="button" @click.stop.prevent="refreshDevice(item.id)" class="rounded-lg bg-amber-500/15 px-2.5 py-1 text-[11px] font-semibold text-amber-200 hover:bg-amber-500/25 disabled:cursor-not-allowed disabled:opacity-60" :disabled="refreshingDeviceId === Number(item.id || 0)" x-text="refreshingDeviceId === Number(item.id || 0) ? 'Recargando...' : 'Recargar'"></button>
                                <button type="button" @click.stop.prevent="openEdit(item)" class="rounded-lg bg-cyan-500/15 px-2.5 py-1 text-[11px] font-semibold text-cyan-200 hover:bg-cyan-500/25">Modificar</button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
            <div class="border-b border-slate-800 px-4 py-3 text-sm text-slate-400">
                Mapa en vivo de dispositivos con última posición reportada.
            </div>
            <div id="trackingMap"></div>
        </section>
    </div>
</div>

<div x-show="editOpen" x-cloak class="fixed inset-0 z-[100000] flex items-center justify-center bg-black/75 p-4">
    <div class="w-full max-w-lg rounded-2xl border border-slate-800 bg-slate-900 p-5 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-black text-white">Editar dispositivo</h2>
                <p class="text-sm text-slate-400" x-text="editForm.device_name || 'Dispositivo'"></p>
            </div>
            <button @click="closeEdit()" class="rounded-lg border border-slate-700 px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800">Cerrar</button>
        </div>
        <div class="grid gap-5 md:grid-cols-[120px_minmax(0,1fr)]">
            <div class="flex items-center justify-center">
                <div class="smx-icon-preview" x-html="getIconImage(editForm.icon_type || 'auto', editForm.device_name || 'Dispositivo')"></div>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs uppercase tracking-[0.18em] text-slate-400">Alias</label>
                    <input x-model="editForm.marker_label" type="text" maxlength="24" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs uppercase tracking-[0.18em] text-slate-400">Tipo de ícono</label>
                        <select x-model="editForm.icon_type" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                            <template x-for="opt in iconTypes" :key="opt">
                                <option :value="opt" x-text="opt"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs uppercase tracking-[0.18em] text-slate-400">Color</label>
                        <select x-model="editForm.icon_color" class="h-[44px] w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                            <option value="">Sin color</option>
                            <option value="cyan">Cyan</option>
                            <option value="blue">Azul</option>
                            <option value="emerald">Verde</option>
                            <option value="amber">Amarillo</option>
                            <option value="red">Rojo</option>
                            <option value="violet">Violeta</option>
                            <option value="indigo">Índigo</option>
                            <option value="slate">Gris</option>
                            <option value="black">Negro</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button @click="closeEdit()" class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-slate-800">Cancelar</button>
            <button @click="saveEdit()" :disabled="savingEdit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-60">Guardar</button>
        </div>
    </div>
</div>

<script>
function mobileTrackingApp() {
    return {
        map: null,
        markers: new Map(),
        items: [],
        filteredItems: [],
        q: '',
        loading: false,
        refreshingDeviceId: 0,
        stats: { total: 0, online: 0, tracking: 0, companies: 0 },
        editOpen: false,
        savingEdit: false,
        editForm: { device_id: 0, device_name: '', marker_label: '', icon_type: 'auto', icon_color: '', tracking_enabled: true },
        iconTypes: ['auto', 'moto', 'auto_car', 'sedan', 'suv', 'pickup', 'furgon', 'camion'],

        init() {
            window.smxTrackingActions = {
                editDevice: (id) => this.openEditById(id),
                refreshDevice: (id) => this.refreshDevice(id),
            };
            this.initMap();
            this.loadPositions();
            setInterval(() => this.loadPositions(), 30000);
        },

        initMap() {
            this.map = L.map('trackingMap', {
                zoomControl: true,
                preferCanvas: true,
            }).setView([-25.2637, -57.5759], 6);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(this.map);
        },

        async loadPositions(force = false) {
            this.loading = true;
            try {
                const url = '/public/api/mobile_tracking.php?action=latest_positions&limit=200' + (force ? ('&_ts=' + Date.now()) : '');
                const res = await fetch(url, { credentials: 'same-origin' });
                const data = await res.json();
                if (!data.ok) {
                    throw new Error(data.error || 'No se pudo cargar tracking');
                }
                this.items = Array.isArray(data.data?.items) ? data.data.items : [];
                this.stats = {
                    total: this.items.length,
                    online: this.items.filter(item => String(item.status || '') === 'online').length,
                    tracking: this.items.filter(item => Number(item.tracking_enabled || 0) === 1).length,
                    companies: [...new Set(this.items.map(item => Number(item.id_empresa || 0)))].filter(Boolean).length,
                };
                this.filterItems();
                this.renderMarkers();
            } catch (error) {
                alert(error.message || 'No se pudo cargar tracking');
            } finally {
                this.loading = false;
            }
        },

        filterItems() {
            const query = String(this.q || '').trim().toLowerCase();
            if (!query) {
                this.filteredItems = this.items.slice();
                return;
            }
            this.filteredItems = this.items.filter(item => {
                const haystack = [
                    item.marker_label,
                    item.device_name,
                    item.user_name,
                    item.company_name,
                    item.platform,
                ].join(' ').toLowerCase();
                return haystack.includes(query);
            });
        },

        renderMarkers() {
            const aliveIds = new Set();
            this.items.forEach(item => {
                const lat = Number(item.last_lat);
                const lng = Number(item.last_lng);
                if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                    return;
                }
                aliveIds.add(String(item.id));
                const marker = this.upsertMarker(item, lat, lng);
                marker.setPopupContent(this.popupHtml(item));
            });

            [...this.markers.keys()].forEach(id => {
                if (!aliveIds.has(id)) {
                    this.map.removeLayer(this.markers.get(id));
                    this.markers.delete(id);
                }
            });
        },

        upsertMarker(item, lat, lng) {
            const markerId = String(item.id);
            const icon = L.divIcon({
                className: 'smx-tracking-marker-wrap',
                html: this.markerHtml(item),
                iconSize: [120, 34],
                iconAnchor: [17, 17],
                popupAnchor: [0, -18],
            });
            let marker = this.markers.get(markerId);
            if (!marker) {
                marker = L.marker([lat, lng], { icon }).addTo(this.map);
                this.markers.set(markerId, marker);
            } else {
                marker.setLatLng([lat, lng]);
                marker.setIcon(icon);
            }
            return marker;
        },

        markerHtml(item) {
            const label = this.escapeHtml(item.marker_label || item.device_name || 'GPS');
            const image = this.getIconImage(item.icon_type || 'auto', item.device_name || 'Vehiculo');
            return `
                <div class="smx-tracking-marker">
                    <span class="smx-tracking-marker-icon">${image}</span>
                    <span class="smx-tracking-marker-text">${label}</span>
                </div>
            `;
        },

        popupHtml(item) {
            const title = this.escapeHtml(item.marker_label || item.device_name || 'Dispositivo');
            const user = this.escapeHtml(item.user_name || 'Sin usuario');
            const company = this.escapeHtml(item.company_name || ('Empresa #' + item.id_empresa));
            const battery = item.battery_pct != null ? `${item.battery_pct}%` : 'N/D';
            const speed = item.last_speed_mps != null ? `${(Number(item.last_speed_mps) * 3.6).toFixed(1)} km/h` : 'N/D';
            const deviceId = Number(item.id || 0);
            return `
                <div class="min-w-[220px] text-slate-900">
                    <div class="font-bold">${title}</div>
                    <div class="text-xs text-slate-600">${user}</div>
                    <div class="text-xs text-slate-500">${company}</div>
                    <div class="mt-2 text-xs"><strong>Estado:</strong> ${this.escapeHtml(item.status || 'offline')}</div>
                    <div class="text-xs"><strong>Batería:</strong> ${this.escapeHtml(battery)}</div>
                    <div class="text-xs"><strong>Red:</strong> ${this.escapeHtml(item.network_type || 'N/D')}</div>
                    <div class="text-xs"><strong>Velocidad:</strong> ${this.escapeHtml(speed)}</div>
                    <div class="text-xs"><strong>Último fix:</strong> ${this.escapeHtml(this.formatDate(item.last_fix_at))}</div>
                    <div class="mt-3 flex gap-2">
                        <button type="button" onclick="window.smxTrackingActions && window.smxTrackingActions.refreshDevice(${deviceId})" class="rounded-lg bg-amber-100 px-2.5 py-1 text-[11px] font-semibold text-amber-800 hover:bg-amber-200">Recargar</button>
                        <button type="button" onclick="window.smxTrackingActions && window.smxTrackingActions.editDevice(${deviceId})" class="rounded-lg bg-cyan-100 px-2.5 py-1 text-[11px] font-semibold text-cyan-800 hover:bg-cyan-200">Modificar</button>
                    </div>
                </div>
            `;
        },

        focusItem(id) {
            const item = this.items.find(entry => String(entry.id) === String(id));
            if (!item) {
                return;
            }
            const marker = this.markers.get(String(id));
            if (marker) {
                this.map.flyTo(marker.getLatLng(), 16, { duration: 0.6 });
                marker.openPopup();
            }
        },

        fitAll() {
            const points = [...this.markers.values()].map(marker => marker.getLatLng());
            if (points.length === 0) {
                return;
            }
            this.map.fitBounds(L.latLngBounds(points), { padding: [40, 40] });
        },

        async refreshDevice(id) {
            const deviceId = Number(id || 0);
            if (deviceId <= 0 || this.refreshingDeviceId === deviceId) {
                return;
            }
            this.refreshingDeviceId = deviceId;
            try {
                await this.loadPositions(true);
                this.focusItem(deviceId);
            } finally {
                this.refreshingDeviceId = 0;
            }
        },

        openEdit(item) {
            this.editForm = {
                device_id: Number(item.id || 0),
                device_name: String(item.device_name || ''),
                marker_label: String(item.marker_label || ''),
                icon_type: String(item.icon_type || 'auto'),
                icon_color: String(item.icon_color || ''),
                tracking_enabled: Number(item.tracking_enabled || 0) === 1
            };
            this.editOpen = true;
        },

        openEditById(id) {
            const item = this.items.find(entry => String(entry.id) === String(id));
            if (!item) {
                return;
            }
            this.openEdit(item);
        },

        closeEdit() {
            this.editOpen = false;
            this.savingEdit = false;
        },

        async saveEdit() {
            this.savingEdit = true;
            try {
                const res = await fetch('/public/geolocalizacion-config/api/index.php?action=save', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        device_id: Number(this.editForm.device_id || 0),
                        marker_label: this.editForm.marker_label || '',
                        icon_type: this.editForm.icon_type || 'auto',
                        icon_color: String(this.editForm.icon_color || '').trim(),
                        tracking_enabled: !!this.editForm.tracking_enabled
                    })
                });
                const raw = await res.text();
                const data = raw ? JSON.parse(raw) : {};
                if (!data.ok) {
                    throw new Error(data.error || 'No se pudo guardar');
                }
                this.closeEdit();
                await this.loadPositions(true);
            } catch (error) {
                alert(error.message || 'No se pudo guardar');
            } finally {
                this.savingEdit = false;
            }
        },

        getIconPath(iconType) {
            const map = {
                moto: '/public/assets/images/tracking/motorcycle.svg',
                auto_car: '/public/assets/images/tracking/car-sedan.svg',
                sedan: '/public/assets/images/tracking/car-sedan.svg',
                suv: '/public/assets/images/tracking/suv.svg',
                pickup: '/public/assets/images/tracking/pickup.svg',
                furgon: '/public/assets/images/tracking/van.svg',
                camion: '/public/assets/images/tracking/truck.svg',
                auto: '/public/assets/images/tracking/car-sedan.svg'
            };
            return map[String(iconType || 'auto')] || '/public/assets/images/tracking/car-sedan.svg';
        },

        getIconImage(iconType, altText) {
            const src = this.escapeHtml(this.getIconPath(iconType));
            const alt = this.escapeHtml(altText || 'Vehiculo');
            return `<img src="${src}" alt="${alt}">`;
        },

        normalizeHexColor(value) {
            const color = String(value || '').trim().toLowerCase();
            return /^#[0-9a-f]{6}$/.test(color) ? color : '';
        },

        formatDate(value) {
            if (!value) {
                return 'Sin fecha';
            }
            const parsed = new Date(String(value).replace(' ', 'T') + 'Z');
            if (Number.isNaN(parsed.getTime())) {
                return String(value);
            }
            return parsed.toLocaleString('es-PY', {
                day: '2-digit',
                month: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    }
}
</script>
</body>
</html>
