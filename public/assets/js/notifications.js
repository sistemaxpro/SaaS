/**
 * Sistema de Notificaciones Unificado - SistemaX
 * Usa Alpine.js + Tailwind CSS
 * 
 * USO:
 * 1. Incluir este archivo en la página
 * 2. Agregar el componente HTML con: <?php include 'assets/components/notifications.php'; ?>
 * 3. Usar las funciones globales:
 *    - window.$notify.success('Título', 'Mensaje')
 *    - window.$notify.error('Título', 'Mensaje')
 *    - window.$notify.warning('Título', 'Mensaje')
 *    - window.$notify.info('Título', 'Mensaje')
 *    - window.$confirm('Título', 'Mensaje').then(result => { ... })
 */

(function() {
    'use strict';

    // ==========================================
    // Sistema de Notificaciones (Toast)
    // ==========================================
    window.notificationSystem = function() {
        return {
            notifications: [],
            nextId: 1,

            /**
             * Mostrar notificación
             * @param {string} title - Título de la notificación
             * @param {string} message - Mensaje
             * @param {string} type - Tipo: 'success', 'error', 'warning', 'info'
             * @param {number} duration - Duración en ms (0 = permanente)
             */
            show(title, message, type = 'info', duration = 5000) {
                const id = this.nextId++;
                const notification = {
                    id,
                    title,
                    message,
                    type,
                    closing: false,
                    hidden: false,
                    progress: 100
                };

                this.notifications.push(notification);

                // Animar barra de progreso
                if (duration > 0) {
                    const startTime = Date.now();
                    const progressInterval = setInterval(() => {
                        const elapsed = Date.now() - startTime;
                        const remaining = Math.max(0, 100 - (elapsed / duration) * 100);
                        const notif = this.notifications.find(n => n.id === id);
                        if (notif) {
                            notif.progress = remaining;
                        }
                        if (remaining <= 0) {
                            clearInterval(progressInterval);
                        }
                    }, 50);

                    setTimeout(() => {
                        clearInterval(progressInterval);
                        this.close(id);
                    }, duration);
                }

                return id;
            },

            close(id) {
                const notification = this.notifications.find(n => n.id === id);
                if (notification && !notification.closing) {
                    notification.closing = true;
                    setTimeout(() => {
                        notification.hidden = true;
                        this.notifications = this.notifications.filter(n => n.id !== id);
                    }, 300);
                }
            },

            // Métodos de conveniencia
            success(title, message, duration = 5000) {
                return this.show(title, message, 'success', duration);
            },
            error(title, message, duration = 8000) {
                return this.show(title, message, 'error', duration);
            },
            warning(title, message, duration = 6000) {
                return this.show(title, message, 'warning', duration);
            },
            info(title, message, duration = 5000) {
                return this.show(title, message, 'info', duration);
            },

            // Helpers para colores
            getIconClass(type) {
                const icons = {
                    success: 'fas fa-check-circle text-green-500',
                    error: 'fas fa-times-circle text-red-500',
                    warning: 'fas fa-exclamation-triangle text-amber-500',
                    info: 'fas fa-info-circle text-blue-500'
                };
                return icons[type] || icons.info;
            },
            getBgClass(type) {
                const bgs = {
                    success: 'bg-green-50 dark:bg-green-900/20',
                    error: 'bg-red-50 dark:bg-red-900/20',
                    warning: 'bg-amber-50 dark:bg-amber-900/20',
                    info: 'bg-blue-50 dark:bg-blue-900/20'
                };
                return bgs[type] || bgs.info;
            },
            getProgressClass(type) {
                const colors = {
                    success: 'bg-green-500',
                    error: 'bg-red-500',
                    warning: 'bg-amber-500',
                    info: 'bg-blue-500'
                };
                return colors[type] || colors.info;
            }
        };
    };

    // ==========================================
    // Sistema de Confirmación (Modal)
    // ==========================================
    window.confirmModalSystem = function() {
        return {
            show: false,
            title: '',
            message: '',
            icon: 'fas fa-question-circle',
            iconColor: 'text-amber-500',
            iconBg: 'bg-amber-100 dark:bg-amber-900/30',
            confirmText: 'Confirmar',
            cancelText: 'Cancelar',
            confirmClass: 'bg-blue-600 hover:bg-blue-700',
            dangerous: false,
            resolvePromise: null,

            /**
             * Abrir modal de confirmación
             * @param {Object} options - Opciones del modal
             */
            open(options = {}) {
                this.title = options.title || '¿Confirmar acción?';
                this.message = options.message || '¿Está seguro que desea continuar?';
                this.icon = options.icon || 'fas fa-question-circle';
                this.confirmText = options.confirmText || 'Confirmar';
                this.cancelText = options.cancelText || 'Cancelar';
                this.dangerous = options.dangerous || false;

                if (this.dangerous) {
                    this.iconColor = 'text-red-500';
                    this.iconBg = 'bg-red-100 dark:bg-red-900/30';
                    this.confirmClass = 'bg-red-600 hover:bg-red-700';
                } else {
                    this.iconColor = options.iconColor || 'text-amber-500';
                    this.iconBg = options.iconBg || 'bg-amber-100 dark:bg-amber-900/30';
                    this.confirmClass = options.confirmClass || 'bg-blue-600 hover:bg-blue-700';
                }

                this.show = true;

                return new Promise((resolve) => {
                    this.resolvePromise = resolve;
                });
            },

            confirm() {
                this.show = false;
                if (this.resolvePromise) {
                    this.resolvePromise(true);
                    this.resolvePromise = null;
                }
            },

            cancel() {
                this.show = false;
                if (this.resolvePromise) {
                    this.resolvePromise(false);
                    this.resolvePromise = null;
                }
            }
        };
    };

    // ==========================================
    // API Global - $notify y $confirm
    // ==========================================
    
    // Función para registrar stores de Alpine
    function registerAlpineStores() {
        if (typeof Alpine === 'undefined') return;
        
        // Verificar si ya están registrados
        if (Alpine.store('notifications')) return;
        
        Alpine.store('notifications', {
            container: null,
            setContainer(container) {
                this.container = container;
            }
        });

        Alpine.store('confirmModal', {
            modal: null,
            setModal(modal) {
                this.modal = modal;
            }
        });
    }
    
    // Intentar registrar ahora si Alpine ya está disponible
    if (typeof Alpine !== 'undefined') {
        registerAlpineStores();
    }
    
    // También escuchar por si Alpine se inicializa después
    document.addEventListener('alpine:init', registerAlpineStores);

    // API Global para notificaciones
    window.$notify = {
        _getContainer() {
            // Intentar obtener el contenedor de Alpine
            const el = document.querySelector('[x-data*="notificationSystem"]');
            if (el && el._x_dataStack && el._x_dataStack[0]) {
                return el._x_dataStack[0];
            }
            // Fallback: buscar en Alpine stores
            if (typeof Alpine !== 'undefined' && Alpine.store('notifications')?.container) {
                return Alpine.store('notifications').container;
            }
            return null;
        },
        _queue: [],
        _processQueue() {
            const container = this._getContainer();
            if (container && this._queue.length > 0) {
                this._queue.forEach(fn => fn(container));
                this._queue = [];
            }
        },
        _call(method, args) {
            const container = this._getContainer();
            if (container) {
                if (typeof container[method] === 'function') {
                    return container[method](...args);
                }
                // Fallback defensivo: algunos contenedores legacy solo exponen show(...)
                if (typeof container.show === 'function') {
                    const type = String(method || 'info');
                    const [title, message, duration] = args || [];
                    return container.show(title, message, type, duration);
                }
                console.warn(`Notificaciones: método no disponible (${method})`);
            }
            // Si no está listo, encolar y reintentar
            console.warn('Notificaciones: esperando inicialización...');
            return new Promise((resolve) => {
                this._queue.push((c) => {
                    if (typeof c?.[method] === 'function') {
                        resolve(c[method](...args));
                        return;
                    }
                    if (typeof c?.show === 'function') {
                        const [title, message, duration] = args || [];
                        resolve(c.show(title, message, String(method || 'info'), duration));
                        return;
                    }
                    resolve(null);
                });
                setTimeout(() => this._processQueue(), 100);
            });
        },
        success(title, message, duration) {
            return this._call('success', [title, message, duration]);
        },
        error(title, message, duration) {
            return this._call('error', [title, message, duration]);
        },
        warning(title, message, duration) {
            return this._call('warning', [title, message, duration]);
        },
        info(title, message, duration) {
            return this._call('info', [title, message, duration]);
        },
        close(id) {
            const container = this._getContainer();
            if (container) container.close(id);
        }
    };

    // API Global para confirmaciones
    window.$confirm = function(title, message, options = {}) {
        return new Promise((resolve) => {
            const getModalApi = () => {
                // 1) Preferir store registrado
                if (typeof Alpine !== 'undefined' && Alpine.store('confirmModal')?.modal) {
                    return Alpine.store('confirmModal').modal;
                }
                // 2) Fallback a componente en DOM
                const el = document.querySelector('[x-data*="confirmModalSystem"]');
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    return el._x_dataStack[0];
                }
                return null;
            };

            const openOrFallback = (modalApi) => {
                if (modalApi && typeof modalApi.open === 'function') {
                    modalApi.open({
                        title,
                        message,
                        ...options
                    }).then(resolve);
                    return;
                }
                console.warn('Sistema de confirmación no inicializado, usando confirm nativo');
                resolve(confirm(message || title));
            };

            const tryOpen = () => {
                const modalApi = getModalApi();
                if (modalApi && typeof modalApi.open === 'function') return openOrFallback(modalApi);
                // Reintentar una vez, luego fallback
                setTimeout(() => openOrFallback(getModalApi()), 120);
            };
            tryOpen();
        });
    };

    // Alias para compatibilidad
    window.notify = window.$notify;
    window.confirmDialog = window.$confirm;

})();
