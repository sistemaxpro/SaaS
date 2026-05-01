<?php

/**
 * Bootstrap - Inicializador del Sistema v1
 */

// Definir constante de aplicación
if (!defined('SISTEMAX_V1')) {
    define('SISTEMAX_V1', true);
}
define('SISTEMAX_PROJECT_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));

// Detectar entorno: permite override por variable y por ruta del proyecto.
$envOverride = strtolower(trim((string)(getenv('SISTEMAX_ENV') ?: '')));
if (in_array($envOverride, ['dev', 'development'], true)) {
    $detectedEnv = 'dev';
} elseif (in_array($envOverride, ['prod', 'production'], true)) {
    $detectedEnv = 'prod';
} else {
    $detectedEnv = preg_match('~(?:^|[\\\\/])(desarrollo|sistemaxpro-dev)(?:[\\\\/]|$)~i', SISTEMAX_PROJECT_ROOT) === 1
        ? 'dev'
        : 'prod';
}
define('SISTEMAX_ENV', $detectedEnv);
define('SISTEMAX_IS_DEV', SISTEMAX_ENV === 'dev');

// Nombre fijo de la Master DB
define('MASTER_DB', 'serproc1');
define('SISTEMAX_SUPPORT_COMPANIES', [168, 169]);

// Configuración de errores (desarrollo)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Configuración de zona horaria
date_default_timezone_set('America/Asuncion');

// Configuración de charset
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// Cargar archivos de configuración
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../public/shared/schema_module_compat.php';
require_once __DIR__ . '/../public/shared/i18n.php';

// Cargar clases Core
require_once __DIR__ . '/../src/Core/Security.php';
require_once __DIR__ . '/../src/Core/Auth.php';
require_once __DIR__ . '/../src/Core/Permission.php';
require_once __DIR__ . '/../src/Core/MultiTenant.php';
require_once __DIR__ . '/../src/Core/Response.php';

if (PHP_SAPI !== 'cli') {
    // Iniciar sesión
    Session::start();

    // Auto-verificación y autocreación de esquema para módulos que usan el helper compartido.
    sxEnsureBootstrapModuleSchemasCompat();

    // Cargar middleware de timeout de sesión por inactividad
    require_once __DIR__ . '/session-timeout-middleware.php';

    SmxI18n::bootstrap();

    // Headers de seguridad
    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');

        // DEV: Desactivar cache del navegador para páginas PHP
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    // CORS para APIs (ajustar según necesidad)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        http_response_code(200);
        exit;
    }
}

/**
 * Inyección global de capturador de bugs en respuestas HTML.
 * No aplica en CLI, llamadas API/AJAX o respuestas no-HTML.
 */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $isApiLike = (stripos($requestUri, '/api/') !== false)
        || (stripos($requestUri, '/public/pos/api/') !== false)
        || (stripos($requestUri, '/public/ventas/api/') !== false)
        || (stripos($requestUri, '/public/misventas/api/') !== false)
        || (stripos($requestUri, '/public/cajas/api/') !== false)
        || (stripos($requestUri, '/public/contactos/api/') !== false)
        || (stripos($requestUri, '/public/menu/api/') !== false)
        || (stripos($requestUri, '/public/productos/api/') !== false)
        || (stripos($requestUri, '/public/balanzas/api/') !== false)
        || (stripos($requestUri, '/public/db_migrador/api/') !== false);
    $isLoginPage = (stripos($requestUri, '/public/login.php') !== false);
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $expectsJson = (stripos($accept, 'application/json') !== false) || (strcasecmp($xrw, 'XMLHttpRequest') === 0);

    if (!$isApiLike && !$expectsJson && !$isLoginPage) {
        ob_start(function ($buffer) {
            if (!is_string($buffer) || $buffer === '') {
                return $buffer;
            }
            if (stripos($buffer, '<html') === false || stripos($buffer, '</body>') === false) {
                return $buffer;
            }

            $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
            $empresa = $_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa ' . $idEmpresa);
            $user = $_SESSION['login'] ?? $_SESSION['usuario'] ?? '';

            $cfg = [
                'id_empresa' => $idEmpresa,
                'empresa' => $empresa,
                'usuario' => $user,
                'whatsapp' => '5445991283116',
            ];
            $offlineCfg = [
                'precacheUrls' => [
                    '/public/menu/menu.php',
                    '/public/panel/index.php',
                    '/public/pos/index.php',
                    '/public/pos/mobile.php',
                    '/public/productos/index.php',
                    '/public/productos/mobile.php',
                    '/public/inventario/index.php',
                    '/public/inventario/mobile.php',
                    '/public/compras/index.php',
                    '/public/compras/mobile.php',
                    '/public/gastos/index.php',
                    '/public/gastos/mobile.php',
                    '/public/contactos/index.php',
                    '/public/contactos/mobile.php',
                    '/public/cajas/index.php',
                    '/public/cajas/mobile.php',
                    '/public/ventas/index.php',
                    '/public/usuarios/index.php',
                    '/public/usuarios/mobile.php',
                    '/public/taller/index.php',
                    '/public/taller/mobile.php',
                    '/public/misventas/index.php',
                    '/public/sucursales/index.php',
                    '/public/soporte/index.php',
                    '/public/suscripciones.php',
                ],
            ];
            $localeCfg = SmxI18n::config();
            $supportShadow = $_SESSION['support_shadow'] ?? null;

            $inject = "\n" .
                '<script>window.__SISTEMAX_LOCALE__=' .
                json_encode($localeCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
                ';</script>' . "\n" .
                '<script src="/public/assets/js/i18n.js?v=1"></script>' . "\n" .
                '<script src="/public/assets/js/sistemax-dark-only.js?v=1"></script>' . "\n" .
                '<script>window.__SISTEMAX_OFFLINE_CONFIG__=' .
                json_encode($offlineCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
                ';</script>' . "\n" .
                '<script src="/public/assets/js/sistemax-offline-db.js?v=1"></script>' . "\n" .
                '<script src="/public/assets/js/sistemax-offline-shell.js?v=1" defer></script>' . "\n" .
                '<script>window.__SISTEMAX_BUG_CONFIG__=' .
                json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
                ';</script>' . "\n" .
                '<script src="/public/assets/js/sistemax-bug-reporter.js?v=1" defer></script>' . "\n";

            $requestPath = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
            $presenceDisabled = strpos($requestPath, '/public/apps-moviles.php') !== false
                && trim((string)($_GET['external'] ?? '')) !== '';

            if ($idEmpresa > 0 && !empty($_SESSION['id_login']) && !$presenceDisabled) {
                $inject .= '<script>(function(){var pingUrl="/public/menu/api/presencia.php";var path=(window.location&&window.location.pathname)||"";var body=JSON.stringify({action:"ping",path:path});var headers={"Content-Type":"application/json"};var send=function(){try{fetch(pingUrl,{method:"POST",credentials:"same-origin",headers:headers,body:body,keepalive:true}).catch(function(){});}catch(e){}};send();var t=setInterval(send,45000);window.addEventListener("beforeunload",function(){try{var payload=JSON.stringify({action:"offline",path:path});if(navigator.sendBeacon){navigator.sendBeacon(pingUrl,new Blob([payload],{type:"application/json"}));}else{fetch(pingUrl,{method:"POST",credentials:"same-origin",headers:headers,body:payload,keepalive:true}).catch(function(){});}}catch(e){}});window.addEventListener("pagehide",function(){try{if(t){clearInterval(t);}}catch(e){}});})();</script>' . "\n";
            }

            if (is_array($supportShadow) && !empty($supportShadow['active'])) {
                $shadowTitle = 'MODO SOPORTE AUDITADO';
                $shadowDesc = 'Actuando como ' . (($supportShadow['target_name'] ?? 'Usuario') . ' (@' . ($supportShadow['target_login'] ?? '') . ') · ' . ($supportShadow['target_company_name'] ?? ('Empresa #' . ($supportShadow['target_company_id'] ?? 0))));
                $inject .= '<div id="smx-support-shadow-banner" style="position:fixed;left:12px;right:12px;bottom:12px;z-index:99998;background:rgba(120,53,15,.96);color:#fff;border:1px solid rgba(251,191,36,.35);border-radius:14px;box-shadow:0 14px 40px rgba(0,0,0,.35);padding:12px 14px;font-family:Inter,system-ui,sans-serif;display:flex;gap:12px;align-items:center;justify-content:space-between;">'
                    . '<div style="min-width:0;">'
                    . '<div style="font-size:11px;font-weight:800;letter-spacing:.08em;color:#fde68a;">' . htmlspecialchars($shadowTitle, ENT_QUOTES, 'UTF-8') . '</div>'
                    . '<div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . htmlspecialchars($shadowDesc, ENT_QUOTES, 'UTF-8') . '</div>'
                    . '<div style="font-size:11px;color:#fed7aa;">Iniciado por ' . htmlspecialchars((string)($supportShadow['support_name'] ?? 'Soporte'), ENT_QUOTES, 'UTF-8') . ' · Cada acción queda auditada</div>'
                    . '</div>'
                    . '<button id="smx-support-shadow-restore" style="flex-shrink:0;background:#ef4444;color:#fff;border:none;border-radius:10px;padding:10px 12px;font-size:12px;font-weight:700;cursor:pointer;">Restaurar sesión</button>'
                    . '</div>';
                $inject .= '<script>(function(){var btn=document.getElementById("smx-support-shadow-restore");if(!btn)return;btn.addEventListener("click",async function(){btn.disabled=true;btn.textContent="Restaurando...";try{var r=await fetch("/public/soporte_shadow/api.php",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:"stop"})});var d=await r.json();if(!d||!d.ok)throw new Error((d&&d.error)||"No se pudo restaurar");window.location.href=(d&&d.redirect)||"/public/soporte_shadow/index.php";}catch(e){alert(e&&e.message?e.message:"No se pudo restaurar la sesión");btn.disabled=false;btn.textContent="Restaurar sesión";}});})();</script>' . "\n";
            }

            // Badge visual DEV para identificar entorno
            if (defined('SISTEMAX_IS_DEV') && SISTEMAX_IS_DEV) {
                $inject .= '<div style="position:fixed;top:4px;right:4px;z-index:99999;background:#ef4444;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;pointer-events:none;opacity:0.85;font-family:monospace;">DEV</div>' . "\n";
                $inject .= '<script>window.__SISTEMAX_ENV__="dev";</script>' . "\n";
            }

            return preg_replace('/<\/body>/i', $inject . '</body>', $buffer, 1);
        });
    }
}
