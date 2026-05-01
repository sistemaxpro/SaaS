# Implementación de Timeout de Sesión por Inactividad

## Descripción General

Se ha implementado un sistema completo de timeout automático de sesión después de **20 minutos de inactividad**. El usuario será desconectado automáticamente si no realiza ninguna actividad durante este período.

## Componentes Implementados

### 1. **Métodos de Sesión** (`config/session.php`)

Se han añadido tres nuevos métodos a la clase `Session`:

#### `Session::checkInactivity($timeoutMinutes = 20): bool`
- Verifica si ha habido inactividad durante el tiempo especificado
- Si el tiempo se excede, destruye la sesión automáticamente
- Retorna `true` si la sesión sigue activa, `false` si fue destruida
- Se ejecuta automáticamente en cada request

**Ejemplo:**
```php
if (!Session::checkInactivity(20)) {
    // La sesión fue destruida por inactividad
    header('Location: /public/login.php?timeout=inactivity');
    exit;
}
```

#### `Session::updateActivityTime(): void`
- Actualiza manualmente el timestamp de última actividad
- Útil para operaciones que toman mucho tiempo

**Ejemplo:**
```php
// Después de una operación larga
Session::updateActivityTime();
```

#### `Session::getTimeRemainingBeforeTimeout($timeoutMinutes = 20): ?int`
- Obtiene el tiempo restante antes del timeout en segundos
- Retorna `null` si no hay sesión activa
- Útil para mostrar advertencias al usuario

**Ejemplo:**
```php
$secondsRemaining = Session::getTimeRemainingBeforeTimeout();
$minutesRemaining = ceil($secondsRemaining / 60);
echo "Tiempo restante: {$minutesRemaining} minutos";
```

### 2. **Middleware de Timeout** (`config/session-timeout-middleware.php`)

- Se ejecuta automáticamente en cada request
- Valida la inactividad antes de procesar la solicitud
- Redirige al login si la sesión ha expirado
- Responde con JSON para solicitudes AJAX/API

**Rutas excluidas del control:**
- `/public/login.php`
- `/public/forgot-password.php`
- `/api/auth/*` (endpoints de autenticación)
- `/public/assets/*`

### 3. **Endpoints de API**

#### `GET /api/auth/check-session`
Verifica el estado actual de la sesión y retorna el tiempo restante.

**Respuesta exitosa:**
```json
{
  "success": true,
  "isLoggedIn": true,
  "timeRemaining": 1043
}
```

**Respuesta con sesión expirada:**
```json
{
  "success": false,
  "isLoggedIn": false,
  "code": "SESSION_TIMEOUT",
  "timeRemaining": 0
}
```

#### `POST /api/auth/register-activity`
Actualiza el timestamp de última actividad del usuario.

**Respuesta:**
```json
{
  "success": true,
  "isLoggedIn": true,
  "timeRemaining": 1200
}
```

#### `POST /api/auth/refresh-activity`
Extiende la sesión reiniciando el contador de inactividad.

**Respuesta:**
```json
{
  "success": true,
  "isLoggedIn": true,
  "message": "Sesión extendida exitosamente",
  "timeRemaining": 1200
}
```

### 4. **Script de Advertencia Visual** (`public/assets/js/session-timeout-warning.js`)

- Monitorea la inactividad del usuario
- Muestra un modal cuando faltan **2 minutos** para que expire la sesión
- Permite extender la sesión o cerrar sesión manualmente
- Detecta actividad del usuario (clicks, keypresses, scroll, etc.)

**Características:**
- Modal personalizado con contador regresivo
- Botones para extender o cerrar sesión
- Detección automática de actividad
- Compatible con modo oscuro

## Instalación

La implementación está completamente integrada. Solo necesita:

### 1. Incluir el script en las páginas

En cualquier página PHP donde quiera mostrar la advertencia de timeout:

```html
<!-- En el footer o antes de cerrar </body> -->
<script src="/public/assets/js/session-timeout-warning.js"></script>
```

### 2. Marcar el contenedor como sesión activa (opcional pero recomendado)

```html
<body data-session-active="true">
    <!-- contenido -->
</body>
```

### Integración en el Layout Base

Si tiene un layout o header base, puede agregar:

```php
<!-- En el template base -->
<?php if (Session::isLoggedIn()): ?>
    <script src="/public/assets/js/session-timeout-warning.js"></script>
<?php endif; ?>
```

## Configuración

### Cambiar el Timeout

**En PHP:** Editar `config/session-timeout-middleware.php`
```php
define('SESSION_TIMEOUT_MINUTES', 20); // Cambiar a otro valor
```

**En JavaScript:** Editar `public/assets/js/session-timeout-warning.js`
```javascript
const CONFIG = {
    timeoutMinutes: 20,        // Timeout total
    warningTimeMinutes: 2,     // Cuándo mostrar la advertencia
    checkIntervalSeconds: 10,  // Intervalo de verificación
};
```

## Comportamiento del Sistema

### Flujo de Inactividad

1. **Usuario inicia sesión** → Timestamp de actividad se registra
2. **Usuario realiza acciones** → Timestamp se actualiza con cada request
3. **20 minutos sin actividad** → 
   - En PHP: Sesión se destruye automáticamente
   - En JavaScript: Se muestra modal de advertencia a los 18 minutos
4. **Usuario hace clic en "Continuar sesión"** → Se envía `POST /api/auth/refresh-activity`
5. **Sesión se extiende** → Contador reinicia a 20 minutos

### Eventos Que Registran Actividad (en el navegador)

- Clics del mouse (`mousedown`)
- Pulsaciones de teclado (`keydown`)
- Desplazamiento (`scroll`)
- Toque en dispositivos (`touchstart`)
- Movimiento del mouse (`mousemove`)

## Casos de Uso Especiales

### Operaciones Largas

Si una operación en servidor toma más de 20 minutos:

```php
// En el script que realiza la operación larga
while ($procesando) {
    // ... proceso ...
    
    // Actualizar actividad cada cierto tiempo
    if ($tiempoTranscurrido % 300 == 0) { // Cada 5 minutos
        Session::updateActivityTime();
    }
}
```

### Solicitudes AJAX

El sistema detecta automáticamente solicitudes AJAX/API y responde con JSON:

```javascript
fetch('/api/auth/check-session')
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            // Sesión expirada
            window.location.href = '/public/login.php?timeout=inactivity';
        }
    });
```

### Páginas sin JavaScript

Si una página no incluye el script de advertencia, el usuario será redirigido automáticamente al login cuando haga su próxima acción.

## Manejo de Errores

### Sesión Expirada en Solicitud Normal
→ Redirige a `/public/login.php?timeout=inactivity`

### Sesión Expirada en Solicitud AJAX
→ Responde con JSON `{success: false, code: 'SESSION_TIMEOUT'}`

### En el navegador
El JavaScript captura esto y redirige al login

## Debugging

### Verificar Estado de Sesión

En cualquier página:

```php
<?php
$timeRemaining = Session::getTimeRemainingBeforeTimeout();
echo "Tiempo restante: " . ($timeRemaining / 60) . " minutos";
?>
```

### Ver en Consola del Navegador

```javascript
fetch('/api/auth/check-session')
    .then(r => r.json())
    .then(d => console.log(d));
```

### Logs

- PHP: Ver `logs/php_errors.log`
- Browser: Ver Console (F12)

## Consideraciones de Seguridad

✅ **Implementado:**
- Validación en server-side (no confíar solo en cliente)
- CSRF protection en endpoints de API
- Validación de X-Requested-With header
- Sesiones HTTPOnly secure cookies
- Timeout seguro sin exposición de datos

✅ **Recomendaciones:**
- Usar HTTPS en producción
- Configurar session.cookie_secure = on
- Configurar session.cookie_httponly = on
- Configurar session.cookie_samesite = 'Lax'

## FAQ

**P: ¿El user debe hacer clic en "Continuar sesión"?**
R: Sí, para seguridad. El usuario debe confirmar que sigue en la computadora.

**P: ¿Se puede desactivar el timeout?**
R: Cambiar `SESSION_TIMEOUT_MINUTES` a un valor muy alto (ej: 99999).

**P: ¿Qué pasa si el navegador se cierra?**
R: La sesión se destruye (por defecto en PHP, lifetime = 0).

**P: ¿Afecta a APIs que usan tokens?**
R: No, los tokens JWT no usan sesiones. Este sistema solo afecta a sesiones PHP.

## Soporte

Para problemas o mejoras, revisar:
- Logs: `logs/php_errors.log`
- Browser Console: F12 → Console
- Network tab: Verificar requests a `/api/auth/*`
