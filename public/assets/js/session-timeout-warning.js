/**
 * Sistema de Advertencia de Timeout de Sesión
 * Muestra un modal cuando faltan 2 minutos para que expire la sesión
 */

(function() {
    'use strict';

    // Configuración
    const CONFIG = {
        timeoutMinutes: 20,
        warningTimeMinutes: 2,
        checkIntervalSeconds: 10,
        sessionCheckEndpoint: '/api/auth/check-session' // Endpoint para verificar estado de sesión
    };

    // Variables globales
    let warningShown = false;
    let timeoutHandle = null;
    let checkIntervalHandle = null;

    /**
     * Crear el modal de advertencia
     */
    function createWarningModal() {
        // Verificar si ya existe
        if (document.getElementById('session-timeout-modal')) {
            return;
        }

        const html = `
            <div id="session-timeout-modal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999] hidden">
                <div class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl max-w-sm mx-4 animate-slide-in">
                    <div class="bg-amber-500 dark:bg-amber-600 px-6 py-4">
                        <h2 class="text-white font-semibold flex items-center gap-2">
                            <i class="fas fa-exclamation-triangle"></i>
                            Sesión por expirar
                        </h2>
                    </div>
                    <div class="p-6">
                        <p class="text-gray-700 dark:text-gray-300 mb-2">
                            Su sesión está por expirar por inactividad.
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
                            Tiempo restante: <span id="timeout-countdown" class="font-semibold text-amber-600 dark:text-amber-400">2:00</span>
                        </p>
                        <div class="flex gap-3">
                            <button id="session-extend-btn" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition-colors">
                                Continuar sesión
                            </button>
                            <button id="session-logout-btn" class="flex-1 bg-gray-300 dark:bg-gray-600 hover:bg-gray-400 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 font-semibold py-2 px-4 rounded-lg transition-colors">
                                Cerrar sesión
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                @keyframes slideIn {
                    from {
                        opacity: 0;
                        transform: translateY(-20px) scale(0.95);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0) scale(1);
                    }
                }
                .animate-slide-in {
                    animation: slideIn 0.3s ease-out;
                }
            </style>
        `;

        document.body.insertAdjacentHTML('beforeend', html);

        // Agregar eventos a los botones
        document.getElementById('session-extend-btn').addEventListener('click', extendSession);
        document.getElementById('session-logout-btn').addEventListener('click', logoutSession);
    }

    /**
     * Mostrar el modal de advertencia
     */
    function showWarningModal() {
        if (warningShown) return;

        const modal = document.getElementById('session-timeout-modal');
        if (modal) {
            modal.classList.remove('hidden');
            warningShown = true;
            startCountdown();
        }
    }

    /**
     * Ocultar el modal de advertencia
     */
    function hideWarningModal() {
        const modal = document.getElementById('session-timeout-modal');
        if (modal) {
            modal.classList.add('hidden');
            warningShown = false;
        }
    }

    /**
     * Iniciar el contador regresivo
     */
    function startCountdown() {
        const warningTime = CONFIG.warningTimeMinutes * 60;
        let secondsRemaining = warningTime;

        const updateCountdown = () => {
            const minutes = Math.floor(secondsRemaining / 60);
            const seconds = secondsRemaining % 60;
            const display = `${minutes}:${seconds.toString().padStart(2, '0')}`;

            const countdownElement = document.getElementById('timeout-countdown');
            if (countdownElement) {
                countdownElement.textContent = display;
            }

            if (secondsRemaining <= 0) {
                // Sesión expirada
                logoutSession();
            } else {
                secondsRemaining--;
                timeoutHandle = setTimeout(updateCountdown, 1000);
            }
        };

        updateCountdown();
    }

    /**
     * Extender la sesión (reactivar actividad)
     */
    function extendSession() {
        // Hacer request al servidor para actualizar la actividad
        fetch('/api/auth/refresh-activity', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                hideWarningModal();
                resetMonitoring();
                showNotification('Sesión renovada', 'success');
            }
        })
        .catch(error => {
            console.error('Error al renovar sesión:', error);
            showNotification('Error al renovar sesión', 'error');
        });
    }

    /**
     * Cerrar sesión
     */
    function logoutSession() {
        // Limpiar intervalos
        clearTimeout(timeoutHandle);
        clearInterval(checkIntervalHandle);

        // Redirigir a logout
        window.location.href = '/public/login.php?logout=true';
    }

    /**
     * Mostrar notificación
     */
    function showNotification(message, type = 'info') {
        // Implementar si existe un sistema de notificaciones en la app
        console.log(`[${type.toUpperCase()}] ${message}`);
    }

    /**
     * Resetear el monitoreo después de extender la sesión
     */
    function resetMonitoring() {
        warningShown = false;
        clearTimeout(timeoutHandle);
        startMonitoring();
    }

    /**
     * Registrar actividad del usuario (clicks, keypresses, etc.)
     */
    function registerActivity() {
        if (warningShown) {
            // Si el modal está mostrado, no registrar como actividad
            return;
        }

        fetch('/api/auth/register-activity', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        }).catch(() => {
            // Silenciar errores
        });
    }

    /**
     * Iniciar monitoreo de inactividad
     */
    function startMonitoring() {
        // Eventos para detectar actividad
        const activityEvents = ['mousedown', 'keydown', 'scroll', 'touchstart', 'mousemove'];

        activityEvents.forEach(event => {
            document.addEventListener(event, registerActivity, { passive: true });
        });

        // Chequeo periódico del estado de la sesión
        checkIntervalHandle = setInterval(checkSessionTimeout, CONFIG.checkIntervalSeconds * 1000);
    }

    /**
     * Verificar el timeout de sesión
     */
    function checkSessionTimeout() {
        // Solo continuar si la sesión aún está activa
        if (!document.body.dataset.sessionActive) {
            return;
        }

        // Obtener información de timeout desde el servidor
        fetch('/api/auth/check-session', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success === false) {
                // Sesión expirada
                if (data.code === 'SESSION_TIMEOUT') {
                    logoutSession();
                }
            } else if (data.timeRemaining !== undefined) {
                const warningThreshold = CONFIG.warningTimeMinutes * 60;

                // Mostrar advertencia si quedan menos de 2 minutos
                if (data.timeRemaining <= warningThreshold && !warningShown) {
                    showWarningModal();
                } else if (data.timeRemaining > warningThreshold && warningShown) {
                    hideWarningModal();
                }
            }
        })
        .catch(() => {
            // Silenciar errores de conexión
        });
    }

    /**
     * Inicializar cuando el DOM esté listo
     */
    function init() {
        // Crear el modal
        createWarningModal();

        // Iniciar monitoreo
        startMonitoring();

        console.log('[Session Timeout Warning] Sistema de advertencia de timeout iniciado');
    }

    // Inicializar cuando el documento esté listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
