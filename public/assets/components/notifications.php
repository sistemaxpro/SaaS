<?php
/**
 * Componente de Notificaciones y Confirmación
 * Sistema unificado Alpine.js + Tailwind
 * 
 * INCLUIR EN TU PÁGINA:
 * <?php include __DIR__ . '/assets/components/notifications.php'; ?>
 * 
 * O si estás en otra carpeta:
 * <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/components/notifications.php'; ?>
 * 
 * USAR EN JAVASCRIPT:
 * $notify.success('Guardado', 'Los cambios se guardaron correctamente');
 * $notify.error('Error', 'No se pudo completar la operación');
 * $notify.warning('Atención', 'Esta acción no se puede deshacer');
 * $notify.info('Info', 'Nuevo mensaje recibido');
 * 
 * const confirmed = await $confirm('¿Eliminar?', '¿Está seguro de eliminar este registro?');
 * if (confirmed) { ... }
 * 
 * // Con opciones
 * await $confirm('¿Eliminar?', 'Esta acción es irreversible', {
 *     dangerous: true,
 *     confirmText: 'Sí, eliminar',
 *     cancelText: 'No, cancelar'
 * });
 */
?>

<!-- Estilos para notificaciones -->
<style>
    /* Animaciones de notificaciones */
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    .notification-enter {
        animation: slideInRight 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .notification-leave {
        animation: slideOutRight 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    /* Animación modal */
    @keyframes modalFadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }
    
    @keyframes modalSlideIn {
        from {
            transform: scale(0.95) translateY(-10px);
            opacity: 0;
        }
        to {
            transform: scale(1) translateY(0);
            opacity: 1;
        }
    }
    
    .modal-overlay-enter {
        animation: modalFadeIn 0.2s ease-out;
    }
    
    .modal-content-enter {
        animation: modalSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
</style>

<!-- Contenedor de Notificaciones (Toast) -->
<div x-data="notificationSystem()"
     x-init="$nextTick(() => { if (window.Alpine && Alpine.store('notifications')) Alpine.store('notifications').setContainer($data) })"
     class="fixed top-4 right-4 z-[9999] flex flex-col gap-3 pointer-events-none max-w-sm w-full"
     style="padding-top: env(safe-area-inset-top, 0px); padding-right: env(safe-area-inset-right, 0px);">
    
    <template x-for="notification in notifications" :key="notification.id">
        <div x-show="!notification.hidden"
             :class="notification.closing ? 'notification-leave' : 'notification-enter'"
             class="pointer-events-auto bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-gray-200 dark:border-slate-700 overflow-hidden">
            
            <!-- Contenido -->
            <div class="p-4 flex items-start gap-3">
                <!-- Icono -->
                <div class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center"
                     :class="getBgClass(notification.type)">
                    <i :class="getIconClass(notification.type)" class="text-lg"></i>
                </div>
                
                <!-- Texto -->
                <div class="flex-1 min-w-0 pt-0.5">
                    <p class="font-semibold text-gray-900 dark:text-white text-sm" x-text="notification.title"></p>
                    <p class="text-gray-600 dark:text-gray-300 text-sm mt-0.5" x-text="notification.message"></p>
                </div>
                
                <!-- Botón cerrar -->
                <button @click="close(notification.id)" 
                        class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors p-1 -m-1">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>
            
            <!-- Barra de progreso -->
            <div class="h-1 bg-gray-100 dark:bg-slate-700">
                <div class="h-full transition-all duration-100 ease-linear"
                     :class="getProgressClass(notification.type)"
                     :style="'width: ' + notification.progress + '%'">
                </div>
            </div>
        </div>
    </template>
</div>

<!-- Modal de Confirmación -->
<div x-data="confirmModalSystem()" 
     x-init="$nextTick(() => { if (window.Alpine && Alpine.store('confirmModal')) Alpine.store('confirmModal').setModal($data) })"
     x-cloak>
    <div x-show="show"
         x-transition:enter="modal-overlay-enter"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[10000] flex items-center justify-center p-4"
         @click.self="cancel()">
        
        <div x-show="show"
             x-transition:enter="modal-content-enter"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-md w-full overflow-hidden"
             @keydown.escape.window="cancel()">
            
            <!-- Contenido -->
            <div class="p-6 text-center">
                <!-- Icono -->
                <div class="w-16 h-16 rounded-full mx-auto mb-4 flex items-center justify-center"
                     :class="iconBg">
                    <i :class="icon + ' text-3xl ' + iconColor"></i>
                </div>
                
                <!-- Título -->
                <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2" x-text="title"></h3>
                
                <!-- Mensaje -->
                <p class="text-gray-600 dark:text-gray-300 mb-6" x-text="message"></p>
                
                <!-- Botones -->
                <div class="flex gap-3">
                    <button @click="cancel()"
                            class="flex-1 px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-slate-600 text-gray-700 dark:text-gray-300 font-semibold hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
                        <span x-text="cancelText"></span>
                    </button>
                    <button @click="confirm()"
                            class="flex-1 px-4 py-3 rounded-xl text-white font-semibold shadow-lg hover:shadow-xl transition-all"
                            :class="confirmClass">
                        <span x-text="confirmText"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cargar JavaScript de notificaciones si no se ha cargado antes -->
<script>
if (typeof window.$notify === 'undefined') {
    var alreadyLoaded = !!document.querySelector('script[src$="/public/assets/js/notifications.js"],script[src$="assets/js/notifications.js"]');
    if (!alreadyLoaded) {
        var script = document.createElement('script');
        script.src = '/public/assets/js/notifications.js';
        document.head.appendChild(script);
    }
}
</script>
