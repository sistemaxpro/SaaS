<?php
require_once __DIR__ . '/_module.php';
smxAlqRenderHead('Imágenes');
smxAlqRenderTopbar('imagenes', 'Imágenes de Propiedades', 'Galería y carga local');
?>

<div x-data="alqImagenes()" x-init="init()" class="grid gap-5 xl:grid-cols-[320px_1fr]">
    <section class="alq-card-light p-5 dark:alq-card">
        <h2 class="text-[15px] text-slate-900 dark:text-white">Propiedad</h2>
        <div class="mt-4 space-y-3">
            <div>
                <label class="text-[11px] text-slate-500 dark:text-slate-400">Seleccionar</label>
                <select x-model="form.id_propiedad" @change="onChangeProperty()" class="alq-select mt-1">
                    <option value="">Seleccione</option>
                    <template x-for="p in propiedades" :key="p.id_propiedad">
                        <option :value="p.id_propiedad" x-text="p.codigo + ' · ' + p.nombre"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="text-[11px] text-slate-500 dark:text-slate-400">Pegar URL de imagen</label>
                <input x-model="manualUrl" class="alq-input mt-1" placeholder="https://...">
                <input x-model="manualTitle" class="alq-input mt-2" placeholder="Título opcional">
                <button @click="attachManualUrl()" class="alq-btn mt-2 w-full border border-teal-500 bg-teal-600 text-white">Agregar URL</button>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-[11px] text-slate-600 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-300">
                <div class="font-medium text-slate-900 dark:text-white">Uso rápido</div>
                <p class="mt-1">Subí imágenes locales o pegá una URL directa. La imagen principal queda marcada con prioridad y el resto se ordena como tira.</p>
            </div>
            <div>
                <label class="text-[11px] text-slate-500 dark:text-slate-400">Subir local</label>
                <input type="file" accept="image/*" multiple class="alq-input mt-1" @change="uploadLocal($event)">
            </div>
            <button @click="reloadGallery()" class="alq-btn border border-teal-500 bg-teal-600 text-white">Recargar galería</button>
        </div>
    </section>

    <section class="space-y-5">
        <div class="alq-card-light p-5 dark:alq-card">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-[15px] text-slate-900 dark:text-white">Galería actual</h2>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400" x-text="galleryHint()"></p>
                </div>
                <div class="text-[11px] text-slate-500 dark:text-slate-400" x-text="images.length + ' imagen(es)'"></div>
            </div>
            <div class="mt-4">
                <template x-if="mainImage()">
                    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/50">
                        <div class="aspect-[16/9] bg-slate-100 dark:bg-slate-950">
                            <img :src="mainImage().image_url || mainImage().url_preview || mainImage().url_original" class="h-full w-full object-cover" alt="">
                        </div>
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">Imagen principal</div>
                                <div class="text-slate-900 dark:text-white" x-text="mainImage().titulo || 'Sin título'"></div>
                            </div>
                            <span class="alq-badge bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">Principal</span>
                        </div>
                    </div>
                </template>
            </div>
            <div class="mt-4 flex gap-3 overflow-x-auto pb-2">
                <template x-for="img in images" :key="img.id_imagen">
                    <div
                        class="w-[180px] shrink-0 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950/60"
                        :class="draggedId === Number(img.id_imagen) ? 'ring-2 ring-teal-500 ring-offset-2 ring-offset-transparent' : ''"
                        draggable="true"
                        @dragstart="beginDrag(img.id_imagen)"
                        @dragend="dragEnd()"
                        @dragover.prevent
                        @drop.prevent="dropOn(img.id_imagen)"
                    >
                        <div class="relative aspect-[4/3] bg-slate-100 dark:bg-slate-900">
                            <img :src="img.image_url || img.url_preview || img.url_original" class="h-full w-full object-cover" alt="">
                            <span x-show="Number(img.principal) === 1" class="absolute left-2 top-2 rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-semibold text-white">Principal</span>
                            <span class="absolute right-2 top-2 rounded-full bg-slate-900/70 px-2 py-0.5 text-[10px] font-semibold text-white">Arrastrar</span>
                        </div>
                        <div class="space-y-2 p-3">
                            <div class="truncate text-[11px] text-slate-700 dark:text-slate-200" x-text="img.titulo || img.fuente || 'Imagen'"></div>
                            <div class="flex flex-wrap gap-1">
                                <button @click="setPrimary(img.id_imagen)" class="alq-btn border border-emerald-500 text-emerald-700 dark:text-emerald-200">Principal</button>
                                <button @click="moveImage(img.id_imagen, 'up')" class="alq-btn border border-slate-300 text-slate-700 dark:border-slate-700 dark:text-slate-200">↑</button>
                                <button @click="moveImage(img.id_imagen, 'down')" class="alq-btn border border-slate-300 text-slate-700 dark:border-slate-700 dark:text-slate-200">↓</button>
                                <button @click="deleteImage(img.id_imagen)" class="alq-btn border border-rose-300 text-rose-600 dark:border-rose-900/40 dark:text-rose-300">Eliminar</button>
                            </div>
                        </div>
                    </div>
                </template>
                <div x-show="!images.length" class="flex min-h-[220px] min-w-[260px] items-center justify-center rounded-2xl border border-dashed border-slate-300 px-8 text-center text-slate-400 dark:border-slate-700">
                    No hay imágenes cargadas para esta propiedad.
                </div>
            </div>
        </div>
    </section>
</div>

<script>
function alqImagenes() {
    return {
        propiedades: [],
        images: [],
        manualUrl: '',
        manualTitle: '',
        draggedId: 0,
        form: { id_propiedad: '' },
        async init() {
            await this.loadPropiedades();
            const params = new URLSearchParams(window.location.search);
            const id = params.get('id_propiedad') || params.get('id');
            if (id) {
                this.form.id_propiedad = String(id);
            }
            if (this.form.id_propiedad) await this.reloadGallery();
        },
        async loadPropiedades() {
            const res = await fetch('/public/alquileres/api/propiedades.php?action=list', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            this.propiedades = data.rows || [];
        },
        selectedProperty() {
            return this.propiedades.find((item) => String(item.id_propiedad) === String(this.form.id_propiedad || ''));
        },
        selectedPropertyLabel() {
            const p = this.selectedProperty();
            return p ? `${p.codigo} · ${p.nombre}` : '';
        },
        propertySeed() {
            const p = this.selectedProperty();
            if (!p) return '';
            return [p.codigo, p.nombre, p.direccion, p.ciudad].filter(Boolean).join(' ');
        },
        galleryHint() {
            const p = this.selectedProperty();
            return p ? `${p.codigo} · ${p.nombre}` : 'Seleccione una propiedad para gestionar su galería';
        },
        async onChangeProperty() {
            await this.reloadGallery();
        },
        async reloadGallery() {
            if (!this.form.id_propiedad) {
                this.images = [];
                return;
            }
            const res = await fetch(`/public/alquileres/api/imagenes.php?action=list&id_propiedad=${encodeURIComponent(this.form.id_propiedad)}`, { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            this.images = data.images || [];
        },
        mainImage() {
            return this.images.find((img) => Number(img.principal || 0) === 1) || this.images[0] || null;
        },
        async attachManualUrl() {
            if (!this.form.id_propiedad) {
                alert('Seleccione una propiedad');
                return;
            }
            if (!this.manualUrl.trim()) {
                alert('Pegue una URL válida');
                return;
            }
            const res = await fetch('/public/alquileres/api/imagenes.php?action=attach', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id_propiedad: this.form.id_propiedad,
                    url_original: this.manualUrl.trim(),
                    url_preview: this.manualUrl.trim(),
                    origen: 'web',
                    fuente: 'url_manual',
                    titulo: this.manualTitle.trim() || 'Imagen de propiedad'
                })
            });
            const data = await res.json();
            if (!data.ok) {
                alert(data.error || 'No se pudo agregar la URL');
                return;
            }
            this.manualUrl = '';
            this.manualTitle = '';
            await this.reloadGallery();
        },
        async uploadLocal(ev) {
            const files = ev.target.files;
            if (!files || !files.length) return;
            if (!this.form.id_propiedad) {
                alert('Seleccione una propiedad');
                ev.target.value = '';
                return;
            }
            const fd = new FormData();
            fd.append('id_propiedad', this.form.id_propiedad);
            Array.from(files).forEach((file) => fd.append('images[]', file));
            const res = await fetch('/public/alquileres/api/imagenes.php?action=upload', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
            const data = await res.json();
            if (!data.ok) {
                alert(data.error || 'No se pudieron subir las imágenes');
                ev.target.value = '';
                return;
            }
            ev.target.value = '';
            await this.reloadGallery();
        },
        async setPrimary(idImagen) {
            const res = await fetch('/public/alquileres/api/imagenes.php?action=set_primary', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_imagen: idImagen })
            });
            const data = await res.json();
            if (!data.ok) {
                alert(data.error || 'No se pudo marcar como principal');
                return;
            }
            await this.reloadGallery();
        },
        async moveImage(idImagen, direction) {
            const res = await fetch('/public/alquileres/api/imagenes.php?action=move', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_imagen: idImagen, direction })
            });
            const data = await res.json();
            if (!data.ok) {
                alert(data.error || 'No se pudo mover la imagen');
                return;
            }
            await this.reloadGallery();
        },
        async deleteImage(idImagen) {
            if (!confirm('¿Eliminar esta imagen?')) return;
            const res = await fetch('/public/alquileres/api/imagenes.php?action=delete', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_imagen: idImagen })
            });
            const data = await res.json();
            if (!data.ok) {
                alert(data.error || 'No se pudo eliminar');
                return;
            }
            await this.reloadGallery();
        },
        beginDrag(idImagen) {
            this.draggedId = Number(idImagen || 0);
        },
        dragEnd() {
            this.draggedId = 0;
        },
        async dropOn(targetId) {
            const draggedId = Number(this.draggedId || 0);
            const target = Number(targetId || 0);
            if (!draggedId || !target || draggedId === target) {
                this.dragEnd();
                return;
            }
            const res = await fetch('/public/alquileres/api/imagenes.php?action=reorder', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_imagen: draggedId, target_id: target })
            });
            const data = await res.json();
            this.dragEnd();
            if (!data.ok) {
                alert(data.error || 'No se pudo reordenar');
                return;
            }
            await this.reloadGallery();
        }
    };
}
</script>

<?php smxAlqRenderFoot(); ?>
