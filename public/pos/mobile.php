<?php
/**
 * POS Mobile - Sistema de Punto de Venta Móvil
 * Diseño Native-Like para dispositivos móviles
 * PWA Ready - Tailwind CSS + Alpine.js
 */

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

// Forzar versión actual del archivo para evitar cache agresivo en WebView/app.
$mobileVersion = (string)(@filemtime(__FILE__) ?: time());
$requestedVersion = (string)($_GET['v'] ?? '');
if ($requestedVersion !== $mobileVersion) {
    $params = $_GET;
    $params['v'] = $mobileVersion;
    $target = strtok((string)($_SERVER['REQUEST_URI'] ?? '/public/pos/mobile.php'), '?');
    $qs = http_build_query($params);
    header('Location: ' . $target . ($qs !== '' ? '?' . $qs : ''), true, 302);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

$id_login = Session::getIdLogin();
$requestedEmpresa = (int)($_GET['id_empresa'] ?? 0);
$sessionEmpresa = (int)Session::getIdEmpresa();
$id_empresa = $requestedEmpresa > 0 ? $requestedEmpresa : $sessionEmpresa;
$usuario = Session::get('usuario', 'Usuario');

// Valores por defecto
$requestedCaja = (int)($_GET['id_caja'] ?? 0);
$sessionCaja = (int)(Session::get('id_caja_def', 0));
$id_caja = $requestedCaja > 0 ? $requestedCaja : $sessionCaja;
$nombreEmpresa = 'SistemaX';
$empresaRuc = '';
$empresaDv = '';
$isFacturaElectronica = 1;
$popularProductsSeed = [];
$rolUsuario = '';
$popularDebugEnabled = false;

try {
    $pdo_init = Database::getMasterConnection();

    // Obtener datos del usuario
    $stmt_user = $pdo_init->prepare("SELECT login, role, caja_def, id_empresa, cobro_df, ancho_papel, forma_pago_def FROM sec_users WHERE id_login = :id");
    $stmt_user->execute([':id' => $id_login]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    $cobro_df = 1;
    $ancho_papel = 400;
    $forma_pago_def = 1;

    if ($user_data) {
        $usuario = $user_data['login'];
        $rolUsuario = trim((string)($user_data['role'] ?? ''));
        if ($id_caja <= 0) {
            $id_caja = (int)$user_data['caja_def'];
        }
        if ($id_empresa <= 0) {
            $id_empresa = (int)$user_data['id_empresa'];
        }
        $cobro_df = (int)$user_data['cobro_df'];
        $ancho_papel = (int)$user_data['ancho_papel'];
        $forma_pago_def = (int)$user_data['forma_pago_def'];
    }

    if ($id_empresa <= 0) {
        $id_empresa = 169;
    }
    $popularDebugEnabled = ((int)$id_empresa === 169 && stripos($rolUsuario, 'admin') !== false);

    // Obtener datos de la empresa
    $stmt_init = $pdo_init->prepare("SELECT empresa, fe, ruc, dv FROM empresa WHERE id_empresa = :id");
    $stmt_init->execute([':id' => $id_empresa]);
    $emp_data = $stmt_init->fetch(PDO::FETCH_ASSOC);
    if ($emp_data) {
        $nombreEmpresa = $emp_data['empresa'];
        $isFacturaElectronica = (int)$emp_data['fe'];
        $empresaRuc = (string)($emp_data['ruc'] ?? '');
        $empresaDv = (string)($emp_data['dv'] ?? '');
    }

    // Obtener tipos de precio
    $stmt_db = $pdo_init->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmt_db->execute([':id' => $id_empresa]);
    $dbEmpresa = $stmt_db->fetchColumn();

    $tiposPrecio = [];
    if ($dbEmpresa) {
        $stmt_precios = $pdo_init->query("SELECT id, tipo FROM $dbEmpresa.tipo_precio ORDER BY id");
        $tiposPrecio = $stmt_precios->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Obtener ruta de imágenes
    $rutaImagenMercaderias = '';
    if ($dbEmpresa) {
        try {
            $stmt_ruta = $pdo_init->query("SELECT ruta_imagen_mercaderias FROM $dbEmpresa.control_empresa LIMIT 1");
            $ruta_result = $stmt_ruta->fetch(PDO::FETCH_ASSOC);
            if ($ruta_result && !empty($ruta_result['ruta_imagen_mercaderias'])) {
                $rutaImagenMercaderias = $ruta_result['ruta_imagen_mercaderias'];
            }
        } catch (Exception $e) {
            // Mantener vacío si no existe
        }
    }

    // Seed inicial para móvil: evita pantalla vacía cuando falla fetch de populares.
    try {
        $connSeed = getEmpresaConnection($id_empresa);
        $pdoSeed = $connSeed['pdo'] ?? null;
        if ($pdoSeed instanceof PDO) {
            $stmt_pop = $pdoSeed->query("
                SELECT
                    p.idproducto AS id,
                    p.cve_producto AS codigo,
                    p.referencia,
                    p.desproducto AS descripcion,
                    COALESCE(p.precio_venta, 0) AS precio,
                    COALESCE(p.saldo, 0) AS stock,
                    COALESCE(p.iva, 10) AS tasa_iva,
                    COALESCE(p.controla_stock, 0) AS controla_stock,
                    COALESCE(p.edita_precio, 0) AS edita_precio,
                    COALESCE(p.editable, 0) AS editable,
                    COALESCE(p.precio_compra, 0) AS precio_min,
                    COALESCE(p.vende_sin_stock, 0) AS vende_sin_stock
                FROM tblproductos p
                WHERE p.Estado = 1
                ORDER BY p.idproducto DESC
                LIMIT 24
            ");
            $popularProductsSeed = $stmt_pop ? ($stmt_pop->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

            // Fallback para empresas legacy (ej. 169) donde Estado puede no estar normalizado.
            if (empty($popularProductsSeed)) {
                $stmt_pop2 = $pdoSeed->query("
                    SELECT
                        p.idproducto AS id,
                        p.cve_producto AS codigo,
                        p.referencia,
                        p.desproducto AS descripcion,
                        COALESCE(p.precio_venta, 0) AS precio,
                        COALESCE(p.saldo, 0) AS stock,
                        COALESCE(p.iva, 10) AS tasa_iva,
                        COALESCE(p.controla_stock, 0) AS controla_stock,
                        COALESCE(p.edita_precio, 0) AS edita_precio,
                        COALESCE(p.editable, 0) AS editable,
                        COALESCE(p.precio_compra, 0) AS precio_min,
                        COALESCE(p.vende_sin_stock, 0) AS vende_sin_stock
                    FROM tblproductos p
                    ORDER BY p.idproducto DESC
                    LIMIT 24
                ");
                $popularProductsSeed = $stmt_pop2 ? ($stmt_pop2->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            }
        }
    } catch (Throwable $e) {
        $popularProductsSeed = [];
    }
} catch (Exception $e) {
    // Mantener defaults
}
?>
<!DOCTYPE html>
<html lang="es" x-data="posMobile()" x-init="init()" :class="isDarkMode ? 'dark' : ''">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#1e293b">
    
    <title>POS Mobile - <?php echo htmlspecialchars($nombreEmpresa); ?></title>
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="logo.png">
    
    <!-- Tailwind CSS -->
    <link rel="stylesheet" href="../assets/tailwind.css">
    
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <!-- Font Awesome para iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script>
        window.registerPosMobilePwa = async function registerPosMobilePwa() {
            if (!('serviceWorker' in navigator)) return false;
            try {
                await navigator.serviceWorker.register('/public/pos/sw.js?v=1', { scope: '/public/pos/' });
                return true;
            } catch (error) {
                console.warn('No se pudo registrar SW del POS móvil:', error);
                return false;
            }
        };
    </script>
    
    <!-- USB Printer Script - Ruta absoluta para asegurar carga -->
    <script src="/public/pos/js/usb-printer.js?v=<?= filemtime(__DIR__ . '/js/usb-printer.js') ?>"></script>
    <script src="/public/pos/js/smx-printer.js?v=<?= filemtime(__DIR__ . '/js/smx-printer.js') ?>"></script>
    <script>
        // Verificar que USBPrinter se cargó correctamente
        document.addEventListener('DOMContentLoaded', function() {
            if (window.USBPrinter) {
                console.log('✅ USBPrinter módulo cargado correctamente');
            } else {
                console.error('❌ USBPrinter NO se cargó - verifica la ruta del archivo');
            }
        });
    </script>
    
    <style>
        * {
            user-select: none;
        }
        
        input, textarea {
            -webkit-user-select: text;
            user-select: text;
        }
        
        html, body {
            height: 100%;
            width: 100%;
            position: fixed;
            inset: 0;
            overflow: hidden;
            overscroll-behavior: none;
            overscroll-behavior-y: none;
            touch-action: manipulation;
        }

        /* Refuerzo global para Android Chrome (pull-to-refresh) */
        html, body, #app, main, .overflow-y-auto, .hide-scrollbar {
            overscroll-behavior-y: none;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding-top: env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }
        
        [x-cloak] { display: none !important; }
        
        /* Animaciones nativas */
        .slide-up-enter {
            animation: slideUp 0.3s cubic-bezier(0.32, 0.72, 0, 1);
        }
        
        @keyframes slideUp {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .bounce-in {
            animation: bounceIn 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        
        @keyframes bounceIn {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        
        /* Scrollbar oculto pero funcional */
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Contenedores internos: permiten scroll vertical sin pull-to-refresh del navegador */
        .no-pull-refresh-scroll {
            overscroll-behavior-y: contain;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-y;
        }
        
        /* Efecto ripple táctil */
        .ripple {
            position: relative;
            overflow: hidden;
        }
        
        .ripple::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            background-image: radial-gradient(circle, #fff 10%, transparent 10.01%);
            background-repeat: no-repeat;
            background-position: 50%;
            transform: scale(10, 10);
            opacity: 0;
            transition: transform .3s, opacity .5s;
        }
        
        .ripple:active::after {
            transform: scale(0, 0);
            opacity: .2;
            transition: 0s;
        }
        
        /* Tab bar estilo iOS */
        .tab-bar {
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        
        /* Cards con sombra suave */
        .card-shadow {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
        }
        
        .dark .card-shadow {
            box-shadow: 0 2px 8px rgba(0,0,0,0.3), 0 1px 2px rgba(0,0,0,0.2);
        }
        
        /* Input estilo iOS */
        .input-ios {
            background: rgba(118, 118, 128, 0.12);
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 17px;
            border: none;
            outline: none;
            width: 100%;
            transition: background 0.2s;
        }
        
        .dark .input-ios {
            background: rgba(118, 118, 128, 0.24);
            color: white;
        }
        
        .input-ios:focus {
            background: rgba(118, 118, 128, 0.18);
        }

        /* Buscador compacto de productos (móvil) */
        .product-search-input {
            min-height: 42px;
            padding-top: 8px;
            padding-bottom: 8px;
            font-size: 14px;
            border-radius: 9px;
        }
        
        /* Botón primario estilo nativo */
        .btn-primary {
            background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%);
            border-radius: 14px;
            color: white;
            font-weight: 600;
            font-size: 17px;
            padding: 16px 24px;
            border: none;
            width: 100%;
            transition: transform 0.1s, opacity 0.2s;
        }
        
        .btn-primary:active {
            transform: scale(0.98);
            opacity: 0.9;
        }
        
        /* Badge de carrito */
        .cart-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            background: #ef4444;
            color: white;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
        }
        
        /* Swipe to delete */
        .swipe-container {
            position: relative;
            overflow: hidden;
        }
        
        .swipe-content {
            transition: transform 0.2s ease-out;
        }
        
        .swipe-action {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 80px;
            background: #ef4444;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        /* Pull to refresh indicator */
        .pull-indicator {
            position: absolute;
            top: -50px;
            left: 50%;
            transform: translateX(-50%);
            transition: all 0.3s;
        }
        
        /* Numpad */
        .numpad-btn {
            aspect-ratio: 1;
            border-radius: 50%;
            font-size: 24px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(118, 118, 128, 0.12);
            transition: background 0.1s;
        }
        
        .dark .numpad-btn {
            background: rgba(118, 118, 128, 0.24);
        }
        
        .numpad-btn:active {
            background: rgba(118, 118, 128, 0.24);
        }
        
        .numpad-btn.primary {
            background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
            color: white;
        }
        
        /* Modal sheet */
        .bottom-sheet {
            border-radius: 20px 20px 0 0;
            max-height: 90vh;
        }
        
        /* Modal teclado numérico para carrito */
        .numpad-modal-btn {
            aspect-ratio: 1;
            border-radius: 12px;
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(118, 118, 128, 0.12);
            transition: all 0.15s;
            user-select: none;
            -webkit-user-select: none;
        }
        
        .dark .numpad-modal-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .numpad-modal-btn:active {
            transform: scale(0.95);
            background: rgba(118, 118, 128, 0.25);
        }
        
        .numpad-modal-btn.enter {
            background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
            color: white;
        }
        
        .numpad-modal-btn.enter:active {
            background: linear-gradient(180deg, #16a34a 0%, #15803d 100%);
        }
        
        .numpad-modal-btn.delete {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .numpad-display {
            font-size: 32px;
            font-weight: 700;
            font-family: 'SF Mono', 'Menlo', monospace;
            letter-spacing: -0.5px;
        }
        
        /* Long press feedback */
        .long-press-target {
            transition: all 0.15s;
            cursor: pointer;
        }
        
        .long-press-target:active {
            transform: scale(0.97);
            opacity: 0.8;
        }

        /* Grid de productos responsive */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        /* 4 columnas en landscape */
        @media (orientation: landscape) {
            .products-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
            }
        }
        
        /* Producto card móvil - formato grid con imagen */
        .product-card-mobile {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px;
            background: white;
            border-radius: 16px;
            transition: transform 0.1s;
            min-height: 160px;
        }
        
        @media (orientation: landscape) {
            .product-card-mobile {
                min-height: 140px;
                padding: 8px;
            }
        }
        
        .dark .product-card-mobile {
            background: #1e293b;
        }
        
        .product-card-mobile:active {
            transform: scale(0.98);
        }
        
        /* Contenedor de imagen del producto */
        .product-image-container {
            position: relative;
            width: 100%;
            aspect-ratio: 1;
            max-height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border-radius: 12px;
            background: #f3f4f6;
            margin-bottom: 8px;
        }
        
        @media (orientation: landscape) {
            .product-image-container {
                max-height: 70px;
            }
        }
        
        .dark .product-image-container {
            background: #374151;
        }

        .cart-product-thumb {
            position: relative;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            overflow: hidden;
            background: #f3f4f6;
            flex-shrink: 0;
        }

        .dark .cart-product-thumb {
            background: #374151;
        }

        .cart-product-thumb img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cart-product-thumb .product-avatar {
            font-size: 14px;
            border-radius: 10px;
        }
        
        .product-image-container img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 12px;
        }

        .product-carousel {
            position: absolute;
            inset: 0;
            overflow: hidden;
            border-radius: 12px;
        }

        .product-carousel img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0;
        }

        @keyframes sxCarouselFade {
            0% { opacity: 0; }
            8% { opacity: 1; }
            30% { opacity: 1; }
            38% { opacity: 0; }
            100% { opacity: 0; }
        }
        
        /* Avatar del producto cuando no hay imagen */
        .product-avatar {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            color: white;
            border-radius: 12px;
        }
        
        @media (orientation: landscape) {
            .product-avatar {
                font-size: 26px;
            }
        }
        
        /* Info del producto en grid */
        .product-info-grid {
            width: 100%;
            text-align: center;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 50px;
        }
        
        .product-info-grid .product-name {
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 4px;
        }
        
        .product-info-grid .product-price {
            font-size: 13px;
            font-weight: 700;
            color: #3b82f6;
        }
        
        .dark .product-info-grid .product-price {
            color: #60a5fa;
        }
        
        /* Status bar safe area */
        .safe-top {
            padding-top: max(env(safe-area-inset-top), 12px);
        }
        
        .safe-bottom {
            padding-bottom: max(env(safe-area-inset-bottom), 12px);
        }
        
        /* Teclado Alfanumérico Personalizado */
        .custom-keyboard {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 100;
            background: linear-gradient(to bottom, #d1d5db, #9ca3af);
            padding: 8px;
            padding-bottom: max(8px, env(safe-area-inset-bottom));
            box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
            border-top: 1px solid #6b7280;
        }
        
        .dark .custom-keyboard {
            background: linear-gradient(to bottom, #374151, #1f2937);
            border-top-color: #4b5563;
        }
        
        .keyboard-row {
            display: flex;
            justify-content: center;
            gap: 4px;
            margin-bottom: 4px;
        }
        
        .keyboard-key {
            min-width: 32px;
            height: 42px;
            border-radius: 6px;
            background: white;
            border: none;
            font-size: 18px;
            font-weight: 500;
            color: #1f2937;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2), 0 2px 0 #a1a1aa;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.05s, box-shadow 0.05s;
            flex: 1;
            max-width: 38px;
        }
        
        .dark .keyboard-key {
            background: #4b5563;
            color: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.4), 0 2px 0 #374151;
        }
        
        .keyboard-key:active {
            transform: translateY(2px);
            box-shadow: 0 0 0 rgba(0,0,0,0.2);
        }
        
        .keyboard-key.special {
            background: #9ca3af;
            color: #1f2937;
            font-size: 12px;
            font-weight: 600;
            min-width: 50px;
            max-width: 60px;
        }
        
        .dark .keyboard-key.special {
            background: #6b7280;
            color: white;
        }
        
        .keyboard-key.space {
            flex: 4;
            max-width: none;
        }
        
        .keyboard-key.enter {
            background: #3b82f6;
            color: white;
            font-size: 13px;
            font-weight: 700;
            min-width: 70px;
            max-width: 80px;
        }
        
        .keyboard-key.enter:active {
            background: #2563eb;
        }
        
        .keyboard-key.backspace {
            font-size: 20px;
        }
        
        .keyboard-key.shift {
            font-size: 16px;
        }
        
        /* Números en la parte superior */
        .keyboard-key.number {
            background: #e5e7eb;
        }
        
        .dark .keyboard-key.number {
            background: #374151;
        }
        
        /* Ajuste para cuando el teclado está visible */
        .keyboard-visible main {
            margin-bottom: 280px;
        }
        
        .keyboard-visible .tab-bar {
            display: none;
        }

        .tablet-main-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(340px, 0.8fr);
            gap: 0;
        }

        .tablet-panel {
            min-height: 0;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.96);
        }

        .dark .tablet-panel {
            background: rgba(30, 41, 59, 0.96);
        }

        .tablet-products-panel {
            border-right: 1px solid rgba(226, 232, 240, 0.95);
        }

        .dark .tablet-products-panel {
            border-right-color: rgba(71, 85, 105, 0.9);
        }

        .tablet-sales-panel {
            grid-column: 1 / -1;
        }

        .tablet-panel .products-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .tablet-panel .product-card-mobile {
            min-height: 180px;
        }

        .tablet-mode .tab-bar {
            display: none;
        }

        @media (min-width: 1180px) {
            .tablet-panel .products-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
    </style>
</head>

<body id="app" class="bg-gray-100 dark:bg-slate-900 text-gray-900 dark:text-white" :class="(showKeyboard ? 'keyboard-visible ' : '') + (isTabletDevice ? 'tablet-mode' : '')">
    <script>
        window.__SISTEMAX_BUG_CONFIG__ = {
            id_empresa: <?php echo (int)$id_empresa; ?>,
            empresa: <?php echo json_encode($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa ' . (int)$id_empresa), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            usuario: <?php echo json_encode((string)$usuario, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
        };
    </script>
    <script defer src="/public/assets/js/sistemax-bug-reporter.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/sistemax-bug-reporter.js') ?: time(); ?>"></script>
    <div class="h-full flex flex-col" style="height: 100dvh; max-height: 100dvh; overflow: hidden;">
        
        <!-- Header Compacto -->
        <header class="safe-top bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-4 pb-3">
            <div class="flex items-center justify-between">
                <!-- Logo y Empresa -->
                <div class="flex items-center gap-3">
                    <img src="logo.png" alt="Logo" class="w-8 h-8 rounded-lg">
                    <div>
                        <h1 class="text-lg font-bold leading-tight">POS Mobile</h1>
                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($nombreEmpresa); ?></p>
                    </div>
                </div>
                
                <!-- Usuario y Opciones -->
                <div class="flex items-center gap-2">
                    <span class="text-xs bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400 px-2 py-1 rounded-full font-medium">
                        Caja #<?php echo str_pad($id_caja, 2, '0', STR_PAD_LEFT); ?>
                    </span>

                    <!-- Botón Limpiar Caché -->
                    <button
                        @click="clearPosCache()"
                        class="w-10 h-10 rounded-full bg-gray-100 dark:bg-slate-700 flex items-center justify-center transition-all active:scale-95"
                        title="Limpiar caché">
                        <i class="fas fa-broom text-base text-slate-500 dark:text-slate-300"></i>
                    </button>
                    
                </div>
            </div>

            <div x-show="isTabletDevice" x-cloak class="mt-3 flex items-center gap-2 overflow-x-auto">
                <button
                    @click="openTabletWorkspace()"
                    :class="tabletHeaderButtonClass('workspace')"
                    class="px-3 py-2 rounded-xl text-sm font-semibold whitespace-nowrap transition-colors">
                    POS Tablet
                </button>
                <button
                    @click="activeTab = 'sales'; loadVentasHoy()"
                    :class="tabletHeaderButtonClass('sales')"
                    class="px-3 py-2 rounded-xl text-sm font-semibold whitespace-nowrap transition-colors">
                    Ventas
                </button>
                <button
                    @click="window.location.href='/public/pos/caja_mobile.php?id_caja=<?php echo (int)$id_caja; ?>&id_empresa=<?php echo (int)$id_empresa; ?>&v=<?php echo @filemtime(__DIR__ . '/caja_mobile.php') ?: time(); ?>'"
                    class="px-3 py-2 rounded-xl text-sm font-semibold whitespace-nowrap transition-colors bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200">
                    Mi Caja
                </button>
            </div>
        </header>
        
        <!-- Contenido Principal con Tabs -->
        <main class="flex-1 overflow-hidden relative" :class="isTabletDevice ? 'tablet-main-layout' : ''">
            
            <!-- Tab: Productos -->
            <div x-show="showProductsPanel()" x-transition class="h-full flex flex-col" :class="isTabletDevice ? 'tablet-panel tablet-products-panel' : ''">
                <!-- Buscador -->
                <div class="p-4 bg-white dark:bg-slate-800">
                    <div class="relative">
                        <input 
                            type="text"
                            x-model="searchQuery"
                            @focus="openKeyboard()"
                            readonly
                            x-ref="searchInput"
                            placeholder="Buscar producto..."
                            class="input-ios product-search-input pl-3 pr-14 cursor-pointer w-full">

                        <button
                            @click.stop="showKeyboard = false; toggleVoiceSearch('products')"
                            type="button"
                            :class="voiceListening ? 'text-red-500 animate-pulse' : 'text-gray-400 hover:text-white'"
                            class="absolute right-10 top-1/2 -translate-y-1/2 z-10 flex h-5 w-5 items-center justify-center transition-colors"
                            title="Buscar por voz">
                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
                        </button>

                        <button
                            x-show="searchQuery"
                            @click.stop="searchQuery = ''; productos = []; popularProducts = []; loadPopular()"
                            type="button"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 z-10">
                            <i class="fas fa-times text-sm"></i>
                        </button>
                    </div>

                    <div class="mt-2 flex items-center gap-2">
                        <button
                            @click.stop="openGeneralProductSearch()"
                            type="button"
                            class="h-8 px-3 rounded-lg bg-slate-800 text-white flex items-center justify-center gap-1.5 text-xs font-medium border border-slate-600">
                            <i class="fas fa-search text-xs leading-none text-white"></i>
                            Buscar
                        </button>

                        <div class="flex items-center gap-1.5">
                            <label class="text-[10px] text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap">Tipo Precio:</label>
                            <select 
                                x-model="selectedPriceType"
                                @change="searchProducts()"
                                class="h-8 text-xs bg-blue-50 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400 rounded-lg px-2 border border-blue-300 dark:border-blue-700 font-medium">
                                <?php foreach ($tiposPrecio as $tp): ?>
                                    <option value="<?php echo (int)$tp['id']; ?>"><?php echo htmlspecialchars($tp['tipo']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Indicador de escucha por voz -->
                    <div x-show="voiceListening" x-transition class="mt-2 flex items-center gap-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl px-3 py-2">
                        <span class="relative flex h-3 w-3">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                        </span>
                        <span class="text-sm text-red-600 dark:text-red-300 font-medium">Escuchando — Diga el producto a buscar</span>
                    </div>
                </div>
                
                <!-- Lista de Productos -->
                <div class="flex-1 overflow-y-auto hide-scrollbar no-pull-refresh-scroll p-4 space-y-3">
                    <!-- Loading -->
                    <div x-show="searchLoading" class="flex items-center justify-center py-8">
                        <div class="animate-spin w-8 h-8 border-3 border-blue-500 border-t-transparent rounded-full"></div>
                    </div>
                    
                    <!-- Productos Populares (cuando no hay búsqueda) -->
                    <div x-show="!hasSearchQuery()">
                        <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 px-1">
                            <i class="fas fa-fire text-orange-500 mr-1"></i> Más Vendidos
                        </h3>
                        <div x-show="popularLoading" class="text-xs text-slate-500 px-1 mb-2">Cargando más vendidos...</div>
                        <div x-show="popularError" class="mb-2 px-2 py-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-[11px] text-amber-700 dark:text-amber-300 flex items-center justify-between gap-2">
                            <span x-text="popularError"></span>
                            <button @click="loadPopularProducts(true)" class="px-2 py-1 rounded bg-amber-600 text-white text-[11px]">Reintentar</button>
                        </div>
                        <div class="products-grid">
                            <template x-for="(producto, idx) in currentPopularProducts()" :key="'pop-' + idx">
                                <div 
                                    @click="handleProductTap(producto)"
                                    @touchstart="startImageSearch(producto, $event)"
                                    @touchend="cancelImageSearch()"
                                    @touchmove="cancelImageSearch()"
                                    @contextmenu.prevent="openProductCameraCapture(producto)"
                                    class="product-card-mobile card-shadow ripple">
                                    <!-- Imagen/Avatar del Producto -->
                                    <div class="product-image-container" x-html="getProductImageHTML(producto)"></div>
                                    <!-- Info -->
                                    <div class="product-info-grid">
                                        <p class="product-name" x-text="producto.descripcion"></p>
                                        <p class="product-price" x-text="formatMoney(producto.precio) + ' Gs'"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    
                    <!-- Resultados de Búsqueda -->
                    <div x-show="hasSearchQuery() && !searchLoading && productos.length > 0">
                        <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 px-1">
                            <i class="fas fa-search text-blue-500 mr-1"></i> 
                            <span x-text="productos.length + ' resultados'"></span>
                        </h3>
                        <div class="products-grid">
                            <template x-for="(producto, index) in productos" :key="producto.id">
                                <div 
                                    @click="handleProductTap(producto)"
                                    @touchstart="startImageSearch(producto, $event)"
                                    @touchend="cancelImageSearch()"
                                    @touchmove="cancelImageSearch()"
                                    @contextmenu.prevent="openProductCameraCapture(producto)"
                                    :class="selectedIndex === index ? 'ring-2 ring-blue-500' : ''"
                                    class="product-card-mobile card-shadow ripple">
                                    <!-- Imagen/Avatar del Producto -->
                                    <div class="product-image-container" x-html="getProductImageHTML(producto)"></div>
                                    <!-- Info -->
                                    <div class="product-info-grid">
                                        <p class="product-name" x-html="highlightMatch(producto.descripcion, searchQuery)"></p>
                                        <p class="product-price" x-text="formatMoney(producto.precio) + ' Gs'"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    
                    <!-- Sin resultados -->
                    <div x-show="hasSearchQuery() && !searchLoading && productos.length === 0" class="text-center py-12">
                        <div class="text-6xl mb-4">🔍</div>
                        <p class="text-gray-500">No se encontraron productos</p>
                    </div>
                </div>

            </div>
            
            <!-- Tab: Carrito -->
            <div x-show="showCartPanel()" x-transition class="h-full flex flex-col" :class="isTabletDevice ? 'tablet-panel tablet-cart-panel' : ''">
                <!-- Header del Carrito -->
                <div class="p-3 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold">
                            <i class="fas fa-shopping-cart text-blue-500 mr-1.5"></i>
                            Carrito (<span x-text="cart.length"></span>)
                        </h2>
                        <button 
                            x-show="cart.length > 0"
                            @click="confirmClearCart()"
                            class="text-red-500 text-xs font-medium px-2 py-1 rounded-lg bg-red-50 dark:bg-red-900/20">
                            <i class="fas fa-trash mr-1"></i>Vaciar
                        </button>
                    </div>
                    
                    <!-- Cliente - Compacto -->
                    <div class="mt-2 relative">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 relative">
                                <i class="fas fa-user absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                <input 
                                    type="text"
                                    x-model="clienteSearch"
                                    @input.debounce.300ms="searchClientes()"
                                    @focus="showClienteDropdown = true"
                                    placeholder="Cliente..."
                                    class="w-full bg-gray-100 dark:bg-slate-700 rounded-lg pl-10 pr-9 py-2 text-sm border-0 focus:ring-1 focus:ring-blue-500">
                                <button
                                    @click.stop="toggleVoiceSearch('clientes')"
                                    :class="voiceListeningCliente ? 'text-red-500 animate-pulse' : 'text-gray-400 hover:text-blue-500'"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 transition-colors z-10">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
                                </button>
                            </div>
                            <!-- Cliente seleccionado badge -->
                            <div x-show="selectedCliente" class="flex items-center gap-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 px-2 py-1 rounded-lg text-xs max-w-[120px]">
                                <i class="fas fa-check-circle"></i>
                                <span class="truncate" x-text="selectedCliente?.nombre?.split(' ')[0]"></span>
                                <button @click="selectedCliente = null; clienteSearch = ''" class="ml-1 text-green-600 hover:text-red-500">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Indicador de escucha por voz cliente -->
                        <div x-show="voiceListeningCliente" x-transition class="mt-1 flex items-center gap-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl px-3 py-2">
                            <span class="relative flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                            </span>
                            <span class="text-sm text-red-600 dark:text-red-300 font-medium">Escuchando — Diga el cliente a buscar</span>
                        </div>

                        <!-- Dropdown clientes -->
                        <div 
                            x-show="showClienteDropdown && clientesResults.length > 0"
                            @click.away="showClienteDropdown = false"
                            class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-slate-800 rounded-xl shadow-xl z-20 max-h-40 overflow-y-auto border border-gray-200 dark:border-slate-700">
                            <template x-for="cli in clientesResults" :key="cli.id">
                                <button 
                                    @click="selectCliente(cli)"
                                    class="w-full p-2 text-left hover:bg-gray-50 dark:hover:bg-slate-700 border-b border-gray-100 dark:border-slate-700 last:border-0">
                                    <p class="font-medium text-sm truncate" x-text="cli.nombre"></p>
                                    <p class="text-xs text-gray-500" x-text="cli.ruc || cli.cedula || ''"></p>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
                
                <!-- Items del Carrito - COMPACTO -->
                <div class="flex-1 overflow-y-auto hide-scrollbar no-pull-refresh-scroll px-2 py-2 space-y-1.5">
                    <!-- Carrito vacío -->
                    <div x-show="cart.length === 0" class="text-center py-12">
                        <div class="text-6xl mb-3 opacity-40">🛒</div>
                        <p class="text-base font-medium text-gray-400">Carrito vacío</p>
                        <p class="text-xs text-gray-400">Busca productos para agregar</p>
                    </div>
                    
                    <!-- Items Compactos -->
                    <template x-for="(item, index) in cart" :key="index">
                        <div class="bg-white dark:bg-slate-800 rounded-xl p-2.5 shadow-sm border border-gray-100 dark:border-slate-700">
                            <!-- Fila Principal: Producto -->
                            <div class="flex items-center gap-2">
                                <!-- Imagen del producto -->
                                <div class="cart-product-thumb" x-html="getCartImageHTML(item)"></div>
                                <!-- Nombre del producto -->
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-sm leading-tight truncate" x-text="item.descripcion"></p>
                                </div>
                                <!-- Botón Eliminar -->
                                <button 
                                    @click="removeFromCart(index)"
                                    class="w-7 h-7 rounded-full bg-red-50 dark:bg-red-900/20 text-red-500 flex items-center justify-center text-xs flex-shrink-0">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                            
                            <!-- Fila de valores editables con long press -->
                            <div class="flex items-center justify-between mt-2 pt-2 border-t border-gray-100 dark:border-slate-700 gap-2">
                                <!-- Cantidad (long press) -->
                                <div 
                                    @touchstart="startLongPress('cantidad', index, item)"
                                    @touchend="cancelLongPress()"
                                    @touchmove="cancelLongPress()"
                                    @mousedown="startLongPress('cantidad', index, item)"
                                    @mouseup="cancelLongPress()"
                                    @mouseleave="cancelLongPress()"
                                    class="long-press-target flex-1 bg-gray-100 dark:bg-slate-700 rounded-lg px-2 py-1.5 text-center cursor-pointer">
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Cant.</p>
                                    <p class="font-bold text-sm text-gray-800 dark:text-white" x-text="item.cantidad"></p>
                                </div>
                                
                                <!-- Precio (long press) - solo si edita_precio -->
                                <div 
                                    @touchstart="item.edita_precio ? startLongPress('precio', index, item) : null"
                                    @touchend="cancelLongPress()"
                                    @touchmove="cancelLongPress()"
                                    @mousedown="item.edita_precio ? startLongPress('precio', index, item) : null"
                                    @mouseup="cancelLongPress()"
                                    @mouseleave="cancelLongPress()"
                                    :class="item.edita_precio ? 'long-press-target cursor-pointer' : 'opacity-60'"
                                    class="flex-1 bg-gray-100 dark:bg-slate-700 rounded-lg px-2 py-1.5 text-center">
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Precio</p>
                                    <p class="font-bold text-sm text-gray-800 dark:text-white" x-text="formatMoney(item.precio)"></p>
                                </div>
                                
                                <!-- Importe (long press) -->
                                <div 
                                    @touchstart="startLongPress('importe', index, item)"
                                    @touchend="cancelLongPress()"
                                    @touchmove="cancelLongPress()"
                                    @mousedown="startLongPress('importe', index, item)"
                                    @mouseup="cancelLongPress()"
                                    @mouseleave="cancelLongPress()"
                                    class="long-press-target flex-1 bg-blue-100 dark:bg-blue-900/30 rounded-lg px-2 py-1.5 text-center cursor-pointer">
                                    <p class="text-[10px] text-blue-600 dark:text-blue-400 uppercase">Total</p>
                                    <p class="font-bold text-sm text-blue-600 dark:text-blue-400" x-text="formatMoney(item.precio * item.cantidad)"></p>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                
                <!-- Footer del Carrito - Compacto -->
                <div x-show="cart.length > 0" class="bg-white dark:bg-slate-800 border-t border-gray-200 dark:border-slate-700 p-3 safe-bottom">
                    <!-- Resumen Compacto -->
                    <div class="flex justify-between items-center mb-3">
                        <div>
                            <p class="text-xs text-gray-500"><span x-text="cart.length"></span> producto(s)</p>
                            <p class="text-xl font-black text-blue-600 dark:text-blue-400 leading-tight" x-text="formatMoney(total) + ' Gs'"></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] text-gray-400">IVA incl.</p>
                            <p class="text-xs text-gray-500" x-text="formatMoney(Math.round(total / 11)) + ' Gs'"></p>
                        </div>
                    </div>
                    
                    <!-- Botón Cobrar -->
                    <button 
                        @click="openPayment()"
                        class="btn-primary flex items-center justify-center gap-2 py-3">
                        <i class="fas fa-cash-register"></i>
                        <span class="font-bold">COBRAR</span>
                        <span class="bg-white/20 px-2 py-0.5 rounded-lg text-sm" x-text="formatMoney(total)"></span>
                    </button>
                </div>
            </div>
            
            <!-- Tab: Pagos/Ventas -->
            <div x-show="showSalesPanel()" x-transition class="h-full flex flex-col" :class="isTabletDevice ? 'tablet-panel tablet-sales-panel' : ''">
                <div class="p-4 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700">
                    <h2 class="text-lg font-bold">
                        <i class="fas fa-receipt text-green-500 mr-2"></i>
                        Mis Ventas Hoy
                    </h2>
                    <div class="mt-3 space-y-2 bg-gray-50 dark:bg-slate-900/40 rounded-xl p-2 border border-gray-200 dark:border-slate-700">
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                            <input
                                type="text"
                                x-model="salesSearchQuery"
                                @input.debounce.300ms="loadVentasHoy()"
                                placeholder="Buscar por nro, cliente..."
                                class="w-full bg-white dark:bg-slate-700 rounded-lg pl-8 pr-3 py-2 text-sm border border-gray-200 dark:border-slate-600 text-gray-800 dark:text-slate-100 placeholder-gray-400 dark:placeholder-slate-400 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div class="flex items-center gap-1 overflow-x-auto hide-scrollbar">
                            <template x-for="p in salesPeriodOptions" :key="p.value">
                                <button
                                    @click="setSalesPeriod(p.value)"
                                    :class="salesPeriod === p.value ? 'bg-blue-600 text-white shadow' : 'bg-white dark:bg-slate-700 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-slate-600'"
                                    class="px-2.5 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition-colors">
                                    <span x-text="p.label"></span>
                                </button>
                            </template>
                        </div>

                        <div x-show="salesPeriod === 'custom'" class="grid grid-cols-2 gap-2">
                            <input type="date" x-model="salesDateFrom" @change="applySalesCustomRange()" class="w-full bg-white dark:bg-slate-700 rounded-lg px-2 py-2 text-xs border border-gray-200 dark:border-slate-600 text-gray-700 dark:text-slate-200">
                            <input type="date" x-model="salesDateTo" @change="applySalesCustomRange()" class="w-full bg-white dark:bg-slate-700 rounded-lg px-2 py-2 text-xs border border-gray-200 dark:border-slate-600 text-gray-700 dark:text-slate-200">
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <select x-model="salesStatusFilter" @change="loadVentasHoy()"
                                    class="col-span-2 w-full bg-white dark:bg-slate-700 rounded-lg px-2 py-2 text-xs border border-gray-200 dark:border-slate-600 font-semibold text-gray-700 dark:text-slate-200">
                                <option value="activos">Activos</option>
                                <option value="anulados">Anulados</option>
                                <option value="todos">Todos</option>
                            </select>
                            <button @click="clearSalesFilters()"
                                    class="w-full py-2 rounded-lg bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-gray-600 dark:text-gray-300 text-xs font-bold hover:bg-gray-100 dark:hover:bg-slate-600">
                                Limpiar
                            </button>
                        </div>

                        <button
                            @click="exportVentasExcel()"
                            class="w-full py-2 rounded-lg bg-emerald-600 text-white text-xs font-bold flex items-center justify-center gap-2">
                            <i class="fas fa-file-excel"></i> Exportar Excel
                        </button>
                    </div>
                </div>
                
                <div class="flex-1 overflow-y-auto hide-scrollbar no-pull-refresh-scroll p-3">
                    <!-- Loading -->
                    <div x-show="loadingSales" class="flex items-center justify-center py-8">
                        <div class="animate-spin w-8 h-8 border-3 border-blue-500 border-t-transparent rounded-full"></div>
                    </div>
                    
                    <!-- Grid de ventas 2 columnas -->
                    <div class="grid grid-cols-2 gap-2">
                        <template x-for="venta in ventasHoy" :key="venta.id_factura">
                            <div 
                                @click="showVentaDetail(venta)"
                                class="bg-white dark:bg-slate-800 rounded-xl p-3 card-shadow ripple">
                                <!-- Número y Estado -->
                                <div class="flex items-center justify-between mb-1">
                                    <p class="font-bold text-sm" x-text="'#' + venta.nro_factura"></p>
                                    <div class="flex items-center gap-1">
                                        <span 
                                            :class="venta.estado === 'PAGADO' ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400'"
                                            class="text-[10px] px-1.5 py-0.5 rounded-full font-medium"
                                            x-text="venta.estado === 'PAGADO' ? '✓' : '⏳'">
                                        </span>
                                        <div class="relative" x-data="{ open: false }">
                                            <button @click.stop="open = !open"
                                                    class="w-6 h-6 rounded-md bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300 flex items-center justify-center">
                                                <i class="fas fa-ellipsis-v text-[10px]"></i>
                                            </button>
                                            <div x-show="open"
                                                 @click.away="open = false"
                                                 x-transition
                                                 class="absolute right-0 top-full mt-1 w-44 bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-gray-200 dark:border-slate-700 py-1 z-50">
                                                <button @click.stop="showVentaDetail(venta); open = false"
                                                        class="w-full px-3 py-2 text-left text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center gap-2">
                                                    <i class="fas fa-eye w-3"></i>
                                                    <span x-text="isVentaElectronica(venta) ? 'Ver KUDE' : 'Ver'"></span>
                                                </button>
                                                <button @click.stop="reprintVentaFromList(venta); open = false"
                                                        class="w-full px-3 py-2 text-left text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center gap-2">
                                                    <i class="fas fa-print w-3"></i> Reimprimir
                                                </button>

                                                <button x-show="isVentaElectronica(venta) && getSifenStatusKey(venta.estado_sifen) === 'pendiente'"
                                                        @click.stop="consultarSifenVenta(venta); open = false"
                                                        class="w-full px-3 py-2 text-left text-xs text-blue-700 dark:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/20 flex items-center gap-2">
                                                    <i class="fas fa-magnifying-glass w-3"></i> Consultar Sifen
                                                </button>

                                                <button x-show="isVentaElectronica(venta) && getSifenStatusKey(venta.estado_sifen) === 'rechazado'"
                                                        @click.stop="reenviarSifenVenta(venta); open = false"
                                                        class="w-full px-3 py-2 text-left text-xs text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-900/20 flex items-center gap-2">
                                                    <i class="fas fa-paper-plane w-3"></i> Reenviar a Sifen
                                                </button>

                                                <button x-show="isDocNotaControlVenta(venta)"
                                                        @click.stop="editVentaFromList(venta); open = false"
                                                        class="w-full px-3 py-2 text-left text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center gap-2">
                                                    <i class="fas fa-edit w-3"></i> Editar
                                                </button>

                                                <hr class="my-1 border-gray-200 dark:border-slate-700">

                                                <button x-show="isVentaElectronica(venta) && getSifenStatusKey(venta.estado_sifen) === 'aprobado'"
                                                        @click.stop="anularEnSifenVenta(venta); open = false"
                                                        class="w-full px-3 py-2 text-left text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2">
                                                    <i class="fas fa-ban w-3"></i> Anular en Sifen
                                                </button>

                                                <button x-show="(isVentaElectronica(venta) && getSifenStatusKey(venta.estado_sifen) !== 'aprobado') || isDocNotaControlVenta(venta) || isDocAutoimpresaVenta(venta)"
                                                        @click.stop="anularLocalVenta(venta); open = false"
                                                        class="w-full px-3 py-2 text-left text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2">
                                                    <i class="fas fa-ban w-3"></i> Anular Local
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Total -->
                                <p class="font-black text-base text-green-600 dark:text-green-400 leading-tight" x-text="formatMoney(venta.total)"></p>
                                <p class="text-[10px] text-gray-400">Gs</p>
                                <!-- Hora y Cliente -->
                                <div class="mt-2 pt-2 border-t border-gray-100 dark:border-slate-700">
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400" x-text="venta.hora"></p>
                                    <p class="text-xs text-gray-600 dark:text-gray-300 truncate" x-text="venta.cliente || 'S/Cliente'"></p>
                                </div>
                            </div>
                        </template>
                    </div>
                    
                    <!-- Sin ventas -->
                    <div x-show="!loadingSales && ventasHoy.length === 0" class="text-center py-16">
                        <div class="text-6xl mb-4 opacity-50">📋</div>
                        <p class="text-gray-500">No hay ventas hoy</p>
                    </div>
                </div>
            </div>
        </main>

        <!-- Modal carrusel de imagenes de producto (long press) -->
        <div
            x-show="showProductImageModal"
            x-cloak
            x-transition.opacity
            class="fixed inset-0 z-[120] bg-black/90 flex items-center justify-center p-2"
            @click.self="closeProductImageModal()"
        >
            <div class="relative w-[98vw] h-[98vh] rounded-2xl overflow-hidden bg-black border border-slate-700">
                <button
                    @click="closeProductImageModal()"
                    class="absolute top-3 right-3 z-20 bg-white/90 text-slate-900 px-3 py-1.5 rounded-lg font-bold text-sm"
                >
                    Close
                </button>

                <template x-if="productImageModal.images.length > 0">
                    <img
                        :src="productImageModal.images[productImageModal.index]"
                        :alt="productImageModal.product?.descripcion || 'Imagen producto'"
                        class="w-full h-full object-contain"
                    >
                </template>

                <div x-show="productImageModal.images.length > 1" class="absolute inset-y-0 left-0 right-0 flex items-center justify-between px-2 pointer-events-none">
                    <button
                        @click="prevProductImage()"
                        class="pointer-events-auto w-11 h-11 rounded-full bg-black/55 text-white text-lg"
                    >‹</button>
                    <button
                        @click="nextProductImage()"
                        class="pointer-events-auto w-11 h-11 rounded-full bg-black/55 text-white text-lg"
                    >›</button>
                </div>

                <div x-show="productImageModal.images.length > 1" class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-1.5 bg-black/45 px-2 py-1 rounded-full">
                    <template x-for="(img, i) in productImageModal.images" :key="'modal-dot-' + i">
                        <button
                            @click="setProductImageIndex(i)"
                            :class="productImageModal.index === i ? 'bg-white' : 'bg-white/40'"
                            class="w-2 h-2 rounded-full"
                        ></button>
                    </template>
                </div>
            </div>
        </div>

        <!-- Teclado Alfanumérico Propio -->
        <div x-show="showKeyboard"
             x-cloak
             class="custom-keyboard"
             @click.outside="closeKeyboard()">
            <div class="keyboard-row">
                <template x-for="k in ['1','2','3','4','5','6','7','8','9','0']" :key="'n-' + k">
                    <button class="keyboard-key number" @click="keyboardInput(k)" x-text="k"></button>
                </template>
            </div>
            <div class="keyboard-row">
                <template x-for="k in ['q','w','e','r','t','y','u','i','o','p']" :key="'r1-' + k">
                    <button class="keyboard-key" @click="keyboardInput(k)" x-text="keyboardShift ? k.toUpperCase() : k"></button>
                </template>
            </div>
            <div class="keyboard-row">
                <template x-for="k in ['a','s','d','f','g','h','j','k','l']" :key="'r2-' + k">
                    <button class="keyboard-key" @click="keyboardInput(k)" x-text="keyboardShift ? k.toUpperCase() : k"></button>
                </template>
            </div>
            <div class="keyboard-row">
                <button class="keyboard-key special shift" @click="keyboardShift = !keyboardShift">Shift</button>
                <template x-for="k in ['z','x','c','v','b','n','m']" :key="'r3-' + k">
                    <button class="keyboard-key" @click="keyboardInput(k)" x-text="keyboardShift ? k.toUpperCase() : k"></button>
                </template>
                <button class="keyboard-key special backspace" @click="keyboardBackspace()">⌫</button>
            </div>
            <div class="keyboard-row">
                <button class="keyboard-key special" @click="keyboardClear()">Limpiar</button>
                <button class="keyboard-key space" @click="keyboardInput(' ')">Espacio</button>
                <button class="keyboard-key enter" @click="closeKeyboard()">Listo</button>
            </div>
        </div>
        
        <!-- Tab Bar Inferior (estilo iOS) -->
        <nav x-show="!isTabletDevice" class="tab-bar bg-white/90 dark:bg-slate-800/90 border-t border-gray-200 dark:border-slate-700 safe-bottom">
            <div :class="tabMenuContainerClass()">
                <button 
                    @click="activeTab = 'products'; ensurePopularProducts()"
                    :class="(activeTab === 'products' ? 'text-blue-600' : 'text-gray-400') + ' ' + tabMenuButtonClass(false)"
                    class="flex flex-col items-center transition-colors">
                    <i class="fas fa-box-open" :class="tabMenuIconClass()"></i>
                    <span :class="tabMenuLabelClass()">Productos</span>
                </button>
                
                <button 
                    @click="activeTab = 'cart'"
                    :class="(activeTab === 'cart' ? 'text-blue-600' : 'text-gray-400') + ' ' + tabMenuButtonClass(false)"
                    class="flex flex-col items-center transition-colors relative">
                    <i class="fas fa-shopping-cart" :class="tabMenuIconClass()"></i>
                    <span :class="tabMenuLabelClass()">Carrito</span>
                    <!-- Badge -->
                    <span 
                        x-show="cart.length > 0" 
                        x-text="cart.length"
                        class="cart-badge">
                    </span>
                </button>
                
                <button 
                    @click="activeTab = 'sales'; loadVentasHoy()"
                    :class="(activeTab === 'sales' ? 'text-blue-600' : 'text-gray-400') + ' ' + tabMenuButtonClass(true)"
                    class="flex flex-col items-center transition-colors">
                    <i class="fas fa-receipt" :class="tabMenuIconClass()"></i>
                    <span :class="tabMenuLabelClass()">Ventas</span>
                </button>

                <button
                    @click="window.location.href='/public/pos/caja_mobile.php?id_caja=<?php echo (int)$id_caja; ?>&id_empresa=<?php echo (int)$id_empresa; ?>&v=<?php echo @filemtime(__DIR__ . '/caja_mobile.php') ?: time(); ?>'"
                    :class="tabMenuButtonClass(true)"
                    class="flex flex-col items-center transition-colors text-gray-400 hover:text-blue-600">
                    <i class="fas fa-cash-register" :class="tabMenuIconClass()"></i>
                    <span :class="tabMenuLabelClass()">Mi Caja</span>
                </button>
            </div>
        </nav>

        <!-- Modal Lector de Código de Barras -->
        <div x-show="showBarcodeScanner" x-cloak class="fixed inset-0 z-[130] bg-black/80 flex items-center justify-center p-3">
            <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-800 overflow-hidden border border-slate-200 dark:border-slate-700">
                <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                    <h3 class="font-semibold text-slate-800 dark:text-slate-100">Escanear Código de Barras</h3>
                    <button @click="closeBarcodeScanner()" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700">
                        <i class="fas fa-times text-slate-500"></i>
                    </button>
                </div>
                <div class="p-3 space-y-2">
                    <div class="relative rounded-xl overflow-hidden bg-black aspect-video">
                        <video x-ref="barcodeVideo" autoplay playsinline muted class="w-full h-full object-cover"></video>
                        <div class="absolute inset-x-8 top-1/2 -translate-y-1/2 border-2 border-emerald-400/90 rounded-lg h-14"></div>
                    </div>
                    <p class="text-[11px] text-slate-600 dark:text-slate-300">Enfoca el código dentro del recuadro.</p>
                    <p x-show="barcodeScannerError" class="text-[11px] text-red-600 dark:text-red-400" x-text="barcodeScannerError"></p>
                </div>
            </div>
        </div>
        
        <!-- Modal Teclado Numérico para Carrito -->
        <div 
            x-show="numpadModal.show"
            x-cloak
            class="fixed inset-0 z-50">
            <!-- Backdrop -->
            <div 
                x-show="numpadModal.show"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="closeNumpadModal()"
                class="absolute inset-0 bg-black/60 backdrop-blur-sm">
            </div>
            
            <!-- Modal Content -->
            <div 
                x-show="numpadModal.show"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="translate-y-full"
                x-transition:enter-end="translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="translate-y-0"
                x-transition:leave-end="translate-y-full"
                class="absolute bottom-0 left-0 right-0 bg-white dark:bg-slate-800 rounded-t-3xl shadow-2xl safe-bottom">
                
                <!-- Handle -->
                <div class="flex justify-center pt-3 pb-2">
                    <div class="w-10 h-1 bg-gray-300 dark:bg-slate-600 rounded-full"></div>
                </div>
                
                <!-- Header -->
                <div class="px-4 pb-3 border-b border-gray-100 dark:border-slate-700">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase" x-text="numpadModal.label"></p>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate max-w-[200px]" x-text="numpadModal.productName"></p>
                        </div>
                        <button @click="closeNumpadModal()" class="w-8 h-8 rounded-full bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                            <i class="fas fa-times text-gray-500"></i>
                        </button>
                    </div>
                    
                    <!-- Display del valor -->
                    <div class="mt-3 bg-gray-100 dark:bg-slate-700 rounded-xl p-3 text-center">
                        <p class="numpad-display text-gray-800 dark:text-white" x-text="numpadModal.display || '0'"></p>
                        <!-- Mostrar precio mínimo si aplica -->
                        <p x-show="numpadModal.type === 'precio' && numpadModal.minPrice > 0" 
                           class="text-xs text-orange-500 mt-1">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            Mín: <span x-text="formatMoney(numpadModal.minPrice)"></span> Gs
                        </p>
                    </div>
                </div>
                
                <!-- Teclado Numérico -->
                <div class="p-4">
                    <div class="grid grid-cols-3 gap-2 max-w-xs mx-auto">
                        <template x-for="key in ['1','2','3','4','5','6','7','8','9','.','0','⌫']">
                            <button 
                                @click="numpadInput(key)"
                                :class="key === '⌫' ? 'numpad-modal-btn delete' : 'numpad-modal-btn'"
                                class="h-14">
                                <span x-text="key"></span>
                            </button>
                        </template>
                    </div>
                    
                    <!-- Botón Confirmar -->
                    <button 
                        @click="confirmNumpadValue()"
                        class="w-full mt-3 h-14 numpad-modal-btn enter text-lg font-semibold">
                        <i class="fas fa-check mr-2"></i> Confirmar
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Modal de Pago (Estilo Desktop) -->
        <div 
            x-show="showPaymentModal"
            x-cloak
            class="fixed inset-0 z-[120] bg-slate-950 p-3 md:p-6 flex items-center justify-center"
            @click.self="showPaymentModal = false">
            
            <!-- Modal -->
            <div 
                x-show="showPaymentModal"
                x-transition:enter="transition ease-out duration-250"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative w-full max-w-2xl rounded-3xl border border-slate-700 bg-slate-900 shadow-2xl overflow-hidden">

                <div class="relative p-4 md:p-5 space-y-4 max-h-[90vh] overflow-y-auto">
                    <div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-2xl px-4 py-4 text-center">
                        <div class="text-[11px] font-bold uppercase tracking-widest text-blue-100">Total a pagar</div>
                        <div class="text-4xl font-black text-white mt-1" x-text="formatMoney(total) + ' Gs'"></div>
                    </div>

                    <div x-show="!userConfig.isVendedor">
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Tipo Documento</label>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button"
                                @touchstart.passive="startLPFix('doc','comun')" @touchend="endLPFix()" @touchcancel="endLPFix()"
                                @mousedown="startLPFix('doc','comun')" @mouseup="endLPFix()" @mouseleave="endLPFix()"
                                @click="if(!wasLPFix()) selectedDocType = 'comun'" @contextmenu.prevent
                                class="relative rounded-xl border-2 py-3 px-3 pr-8 text-sm font-bold transition-all text-center"
                                :class="selectedDocType === 'comun' ? 'border-slate-300 bg-slate-800 text-slate-100 ring-2 ring-slate-300 shadow-lg shadow-slate-700 scale-[1.01]' : 'border-slate-600 bg-slate-900 text-slate-400 hover:border-slate-500'">
                                <div x-show="defaultDocType === 'comun'" class="absolute top-0 right-0 z-10">
                                    <span class="text-[8px] bg-amber-500 text-white px-1 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important">⭐ FIJADO</span>
                                </div>
                                <span class="inline-flex items-center gap-2 justify-center">
                                    <span class="text-base">📋</span>
                                    <span>Nota de Control</span>
                                </span>
                            </button>
                            <button type="button"
                                x-show="isFacturaElectronica == 1"
                                @touchstart.passive="startLPFix('doc','electro')" @touchend="endLPFix()" @touchcancel="endLPFix()"
                                @mousedown="startLPFix('doc','electro')" @mouseup="endLPFix()" @mouseleave="endLPFix()"
                                @click="if(!wasLPFix()) selectedDocType = 'electro'" @contextmenu.prevent
                                class="relative rounded-xl border-2 py-3 px-3 pr-8 text-sm font-bold transition-all text-center"
                                :class="selectedDocType === 'electro' ? 'border-blue-400 bg-blue-700 text-blue-100 ring-2 ring-blue-300 shadow-lg shadow-blue-900 scale-[1.01]' : 'border-slate-600 bg-slate-900 text-slate-400 hover:border-slate-500'">
                                <div x-show="defaultDocType === 'electro'" class="absolute top-0 right-0 z-10">
                                    <span class="text-[8px] bg-amber-500 text-white px-1 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important">⭐ FIJADO</span>
                                </div>
                                <span class="inline-flex items-center gap-2 justify-center">
                                    <span class="text-base">⚡</span>
                                    <span>Factura Electrónica</span>
                                </span>
                            </button>
                            <button type="button"
                                x-show="isFacturaElectronica != 1"
                                @touchstart.passive="startLPFix('doc','auto')" @touchend="endLPFix()" @touchcancel="endLPFix()"
                                @mousedown="startLPFix('doc','auto')" @mouseup="endLPFix()" @mouseleave="endLPFix()"
                                @click="if(!wasLPFix()) selectedDocType = 'auto'" @contextmenu.prevent
                                class="relative rounded-xl border-2 py-3 px-3 pr-8 text-sm font-bold transition-all text-center"
                                :class="selectedDocType === 'auto' ? 'border-amber-400 bg-amber-700 text-amber-100 ring-2 ring-amber-300 shadow-lg shadow-amber-900 scale-[1.01]' : 'border-slate-600 bg-slate-900 text-slate-400 hover:border-slate-500'">
                                <div x-show="defaultDocType === 'auto'" class="absolute top-0 right-0 z-10">
                                    <span class="text-[8px] bg-amber-500 text-white px-1 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important">⭐ FIJADO</span>
                                </div>
                                <span class="inline-flex items-center gap-2 justify-center">
                                    <span class="text-base">🧾</span>
                                    <span>Factura Autoimpresa</span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div x-show="!userConfig.isVendedor">
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Forma de Pago</label>
                        <div class="grid grid-cols-3 gap-1.5">
                            <button type="button"
                                @touchstart.passive="startLPFix('payment','pendiente')" @touchend="endLPFix()" @touchcancel="endLPFix()"
                                @mousedown="startLPFix('payment','pendiente')" @mouseup="endLPFix()" @mouseleave="endLPFix()"
                                @click="if(!wasLPFix()) selectPaymentMethod('pendiente')" @contextmenu.prevent
                                class="relative rounded-xl border py-3 px-2 text-[10px] font-black transition-all flex flex-col items-center justify-center gap-1"
                                style="background:rgba(251,191,36,.22); border-color:rgba(251,191,36,.65); color:#fef9c3;"
                                :class="paymentMethod === 'pendiente' ? 'ring-2 ring-amber-300/80 shadow-lg shadow-amber-500/30 scale-[1.02]' : ''">
                                <div x-show="defaultPaymentMethod === 'pendiente'" class="absolute top-0 right-0 z-10">
                                    <span class="text-[7px] bg-amber-500 text-white px-1 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important">⭐</span>
                                </div>
                                <span class="text-2xl">⏳</span>
                                <span>Pendiente</span>
                            </button>
                            <button type="button"
                                @touchstart.passive="startLPFix('payment','efectivo')" @touchend="endLPFix()" @touchcancel="endLPFix()"
                                @mousedown="startLPFix('payment','efectivo')" @mouseup="endLPFix()" @mouseleave="endLPFix()"
                                @click="if(!wasLPFix()) selectPaymentMethod('efectivo')" @contextmenu.prevent
                                class="relative rounded-xl border py-3 px-2 text-[10px] font-black transition-all flex flex-col items-center justify-center gap-1"
                                style="background:rgba(16,185,129,.22); border-color:rgba(16,185,129,.65); color:#d1fae5;"
                                :class="paymentMethod === 'efectivo' ? 'ring-2 ring-emerald-300/80 shadow-lg shadow-emerald-500/30 scale-[1.02]' : ''">
                                <div x-show="defaultPaymentMethod === 'efectivo'" class="absolute top-0 right-0 z-10">
                                    <span class="text-[7px] bg-amber-500 text-white px-1 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important">⭐</span>
                                </div>
                                <span class="text-2xl">💵</span>
                                <span>Efectivo</span>
                            </button>
                            <button type="button"
                                @touchstart.passive="startLPFix('payment','tarjeta')" @touchend="endLPFix()" @touchcancel="endLPFix()"
                                @mousedown="startLPFix('payment','tarjeta')" @mouseup="endLPFix()" @mouseleave="endLPFix()"
                                @click="if(!wasLPFix()) selectPaymentMethod('tarjeta')" @contextmenu.prevent
                                class="relative rounded-xl border py-3 px-2 text-[10px] font-black transition-all flex flex-col items-center justify-center gap-1"
                                style="background:rgba(14,165,233,.22); border-color:rgba(14,165,233,.65); color:#e0f2fe;"
                                :class="paymentMethod === 'tarjeta' ? 'ring-2 ring-sky-300/80 shadow-lg shadow-sky-500/30 scale-[1.02]' : ''">
                                <div x-show="defaultPaymentMethod === 'tarjeta'" class="absolute top-0 right-0 z-10">
                                    <span class="text-[7px] bg-amber-500 text-white px-1 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important">⭐</span>
                                </div>
                                <span class="text-2xl">💳</span>
                                <span>Tarjeta</span>
                            </button>
                            <button type="button"
                                @touchstart.passive="startLPFix('payment','transferencia')" @touchend="endLPFix()" @touchcancel="endLPFix()"
                                @mousedown="startLPFix('payment','transferencia')" @mouseup="endLPFix()" @mouseleave="endLPFix()"
                                @click="if(!wasLPFix()) selectPaymentMethod('transferencia')" @contextmenu.prevent
                                class="relative rounded-xl border py-3 px-2 text-[10px] font-black transition-all flex flex-col items-center justify-center gap-1"
                                style="background:rgba(139,92,246,.22); border-color:rgba(139,92,246,.65); color:#ede9fe;"
                                :class="paymentMethod === 'transferencia' ? 'ring-2 ring-violet-300/80 shadow-lg shadow-violet-500/30 scale-[1.02]' : ''">
                                <div x-show="defaultPaymentMethod === 'transferencia'" class="absolute top-0 right-0 z-10">
                                    <span class="text-[7px] bg-amber-500 text-white px-1 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important">⭐</span>
                                </div>
                                <span class="text-2xl">🏦</span>
                                <span>Transfer.</span>
                            </button>
                            <button type="button"
                                @touchstart.passive="startLPFix('payment','pix')" @touchend="endLPFix()" @touchcancel="endLPFix()"
                                @mousedown="startLPFix('payment','pix')" @mouseup="endLPFix()" @mouseleave="endLPFix()"
                                @click="if(!wasLPFix()) selectPaymentMethod('pix')" @contextmenu.prevent
                                class="relative rounded-xl border py-3 px-2 text-[10px] font-black transition-all flex flex-col items-center justify-center gap-1"
                                style="background:rgba(217,70,239,.22); border-color:rgba(217,70,239,.65); color:#fae8ff;"
                                :class="paymentMethod === 'pix' ? 'ring-2 ring-fuchsia-300/80 shadow-lg shadow-fuchsia-500/30 scale-[1.02]' : ''">
                                <div x-show="defaultPaymentMethod === 'pix'" class="absolute top-0 right-0 z-10">
                                    <span class="text-[7px] bg-amber-500 text-white px-1 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important">⭐</span>
                                </div>
                                <span class="text-2xl">📱</span>
                                <span>Pix</span>
                            </button>
                            <button type="button"
                                @touchstart.passive="startLPFix('payment','credito')" @touchend="endLPFix()" @touchcancel="endLPFix()"
                                @mousedown="startLPFix('payment','credito')" @mouseup="endLPFix()" @mouseleave="endLPFix()"
                                @click="if(!wasLPFix()) selectPaymentMethod('credito')" @contextmenu.prevent
                                class="relative rounded-xl border py-3 px-2 text-[10px] font-black transition-all flex flex-col items-center justify-center gap-1"
                                style="background:rgba(245,158,11,.22); border-color:rgba(245,158,11,.65); color:#fef3c7;"
                                :class="paymentMethod === 'credito' ? 'ring-2 ring-amber-300/80 shadow-lg shadow-amber-500/30 scale-[1.02]' : ''">
                                <div x-show="defaultPaymentMethod === 'credito'" class="absolute top-0 right-0 z-10">
                                    <span class="text-[7px] bg-amber-500 text-white px-1 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important">⭐</span>
                                </div>
                                <span class="text-2xl">📅</span>
                                <span>Crédito</span>
                            </button>
                        </div>
                    </div>

                    <div x-show="!paymentMethod" class="rounded-xl border border-slate-700 bg-slate-900 py-5 text-center">
                        <p class="text-sm font-semibold text-slate-300">Seleccione una forma de pago para continuar</p>
                    </div>

                    <div x-show="paymentMethod === 'efectivo'" class="space-y-2">
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">Monto recibido</label>
                        <input
                            type="tel"
                            x-model="cashReceived"
                            x-ref="cashReceivedInput"
                            @input="cashReceived = $event.target.value.replace(/\D/g, '')"
                            @focus="$event.target.select()"
                            inputmode="numeric"
                            class="w-full bg-slate-900 border-2 border-slate-600 rounded-xl px-4 py-3 text-2xl font-black text-yellow-300 text-right focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="0">
                        <div class="grid grid-cols-4 gap-2">
                            <template x-for="amount in [10000, 20000, 50000, 100000]" :key="amount">
                                <button @click="cashReceived = (parseInt(cashReceived || 0) + amount)"
                                    class="py-2 rounded-lg border border-emerald-300 bg-emerald-700 text-emerald-100 font-bold text-xs">
                                    +<span x-text="(amount/1000) + 'k'"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div x-show="paymentMethod === 'tarjeta'" class="space-y-2">
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">Nro. Boucher</label>
                        <input
                            type="text"
                            x-model="voucherNumber"
                            x-ref="voucherInput"
                            class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white"
                            placeholder="Ingrese nro. boucher">
                    </div>

                    <div x-show="paymentMethod === 'transferencia'" class="space-y-2">
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">REF Transferencia</label>
                        <input
                            type="text"
                            x-model="transferReference"
                            class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white"
                            placeholder="Ingrese referencia">
                    </div>

                    <div x-show="paymentMethod === 'pix'" class="space-y-2">
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">Referencia PIX</label>
                        <input
                            type="text"
                            x-model="pixReference"
                            class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white"
                            placeholder="Ingrese referencia">
                    </div>

                    <div x-show="paymentMethod === 'credito'" class="space-y-2">
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">Cuotas</label>
                        <select
                            x-model="creditInstallments"
                            class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white">
                            <template x-for="n in [1,2,3,4,5,6,9,12]" :key="'cred-' + n">
                                <option :value="n" x-text="n + ' cuota(s)'"></option>
                            </template>
                        </select>
                    </div>

                    <div x-show="paymentMethod === 'pendiente'" class="space-y-2">
                        <div class="rounded-xl border border-amber-500 bg-amber-900/40 p-3">
                            <div class="flex items-center gap-2 text-amber-300">
                                <span class="text-lg">⚠️</span>
                                <span class="text-xs font-bold">Esta venta quedará con pago pendiente por caja</span>
                            </div>
                        </div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">Observación (opcional)</label>
                        <input
                            type="text"
                            x-model="pendienteNotes"
                            class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white"
                            placeholder="Motivo, referencia, etc.">
                    </div>

                    <div x-show="paymentMethod !== 'efectivo' && paymentMethod !== 'tarjeta'" class="rounded-xl p-3.5 text-center border border-emerald-500 bg-emerald-900">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-emerald-300 mb-0.5">Cobertura</div>
                        <div class="text-2xl font-black text-emerald-300" x-text="formatMoney(total) + ' Gs'"></div>
                    </div>

                    <div x-show="paymentMethod === 'efectivo'" class="rounded-xl p-3.5 text-center transition-colors duration-300"
                        :class="(parseInt(cashReceived || 0)) >= total ? 'bg-emerald-900 border border-emerald-500' : 'bg-red-900 border border-red-500'">
                        <div class="text-[10px] font-bold uppercase tracking-wider mb-0.5"
                            :class="(parseInt(cashReceived || 0)) >= total ? 'text-emerald-400' : 'text-red-400'"
                            x-text="(parseInt(cashReceived || 0)) >= total ? 'Su Vuelto' : 'Faltante'"></div>
                        <div class="text-3xl font-black"
                            :class="(parseInt(cashReceived || 0)) >= total ? 'text-emerald-400' : 'text-red-400'"
                            x-text="formatMoney(Math.abs((parseInt(cashReceived || 0)) - total)) + ' Gs'"></div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <button
                            @click="showPaymentModal = false"
                            class="py-3 rounded-xl font-semibold text-base text-red-100 border border-red-500 bg-red-700 hover:bg-red-600 transition-all">
                            Cancelar
                        </button>
                        <button
                            @click="confirmSale()"
                            :disabled="processingSale || !paymentMethod || (paymentMethod === 'efectivo' && Number(cashReceived || 0) < Number(total || 0)) || (paymentMethod === 'tarjeta' && !String(voucherNumber || '').trim()) || (paymentMethod === 'transferencia' && !String(transferReference || '').trim()) || (paymentMethod === 'pix' && !String(pixReference || '').trim()) || (paymentMethod === 'credito' && Number(creditInstallments || 0) < 1)"
                            class="py-3 rounded-xl font-bold text-base text-white bg-blue-600 hover:bg-blue-500 transition-all disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                            <template x-if="!processingSale">
                                <span class="flex items-center gap-2"><i class="fas fa-save"></i> Guardar</span>
                            </template>
                            <template x-if="processingSale">
                                <span class="flex items-center gap-2"><i class="fas fa-spinner fa-spin"></i> Procesando...</span>
                            </template>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Modal de Proceso de Venta (Grabado + Impresión) -->
        <div
            x-show="saleProcessModal.show"
            x-cloak
            class="fixed inset-0 z-[90] flex items-center justify-center p-6">
            <div class="absolute inset-0 bg-black/70"></div>

            <div
                x-show="saleProcessModal.show"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                class="relative bg-white dark:bg-slate-800 rounded-3xl p-6 max-w-sm w-full text-center shadow-2xl">

                <div class="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-receipt text-2xl text-blue-600 dark:text-blue-400"></i>
                </div>

                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-1">Procesando venta</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4" x-text="saleProcessModal.message"></p>
                <p class="text-xs text-amber-600 dark:text-amber-400 mb-4 whitespace-pre-line" x-show="saleProcessModal.detail" x-text="saleProcessModal.detail"></p>

                <div class="space-y-2 text-left">
                    <div class="flex items-center justify-between rounded-xl px-3 py-2 bg-slate-100 dark:bg-slate-700/50">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200">Grabando venta</span>
                        <span class="text-xs font-semibold"
                              :class="saleProcessModal.saving === 'done' ? 'text-emerald-600 dark:text-emerald-400' : (saleProcessModal.saving === 'loading' ? 'text-blue-600 dark:text-blue-400' : 'text-slate-400')"
                              x-text="saleProcessModal.saving === 'done' ? 'Completado' : (saleProcessModal.saving === 'loading' ? 'Procesando...' : 'Pendiente')"></span>
                    </div>
                    <div class="flex items-center justify-between rounded-xl px-3 py-2 bg-slate-100 dark:bg-slate-700/50">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-200">Imprimiendo</span>
                        <span class="text-xs font-semibold"
                              :class="saleProcessModal.printing === 'done' ? 'text-emerald-600 dark:text-emerald-400' : (saleProcessModal.printing === 'loading' ? 'text-blue-600 dark:text-blue-400' : (saleProcessModal.printing === 'error' ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400'))"
                              x-text="saleProcessModal.printing === 'done' ? 'Completado' : (saleProcessModal.printing === 'loading' ? 'Procesando...' : (saleProcessModal.printing === 'error' ? 'Fallido' : 'Pendiente'))"></span>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-center gap-2 text-blue-600 dark:text-blue-400" x-show="saleProcessModal.saving === 'loading' || saleProcessModal.printing === 'loading'">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span class="text-xs font-medium">No cierres esta ventana</span>
                </div>

                <button
                    x-show="saleProcessModal.saving !== 'loading' && saleProcessModal.printing !== 'loading'"
                    @click="saleProcessModal.show = false; if (lastVenta) { showSuccessModal = true; }"
                    class="mt-4 w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl transition-all active:scale-95">
                    Entendido
                </button>
            </div>
        </div>

        <!-- Modal de Éxito - AUTO-IMPRESIÓN -->
        <div 
            x-show="showSuccessModal"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-6">
            <div class="absolute inset-0 bg-black/60" @click="showSuccessModal = false"></div>
            
            <div 
                x-show="showSuccessModal"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100"
                class="relative bg-white dark:bg-slate-800 rounded-3xl p-6 max-w-sm w-full text-center bounce-in">
                
                <div class="w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-check text-3xl text-green-500"></i>
                </div>
                
                <h2 class="text-xl font-bold mb-2">¡Venta Exitosa!</h2>
                <p class="text-gray-500 text-sm mb-1" x-text="getVentaDocTitle(lastVenta)"></p>
                <p class="text-amber-600 text-xs mb-2" x-show="lastVenta?.queued_offline">Pendiente de sincronización</p>
                <p class="text-green-600 text-xs mb-3" x-show="autoPrintDone">✓ Enviado a impresora</p>
                
                <div class="bg-gray-50 dark:bg-slate-900 rounded-xl p-3 mb-4">
                    <p class="text-xs text-gray-500" x-text="getVentaDocNumberLabel(lastVenta)"></p>
                    <p class="text-lg font-bold" x-text="lastVenta?.nro_factura"></p>
                    <p class="text-xl font-black text-blue-600 mt-1" x-text="formatMoney(lastVenta?.total) + ' Gs'"></p>
                </div>
                
                <!-- Botón reimprimir ticket 32col -->
                <button 
                    @click="printSteward()"
                    x-show="lastVenta?.id_factura"
                    :disabled="printingSteward"
                    class="w-full py-3 px-4 bg-orange-500 hover:bg-orange-600 text-white rounded-xl font-semibold flex items-center justify-center gap-2 transition-all active:scale-95 mb-3">
                    <i class="fas" :class="printingSteward ? 'fa-spinner fa-spin' : 'fa-print'"></i>
                    <span x-text="printingSteward ? 'Enviando...' : 'Reimprimir Ticket'"></span>
                </button>
                
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <button 
                        @click="printTicketDirect()"
                        x-show="lastVenta?.id_factura"
                        :disabled="printingTicket"
                        class="py-2.5 px-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-medium flex items-center justify-center gap-2 text-sm">
                        <i class="fas" :class="printingTicket ? 'fa-spinner fa-spin' : 'fa-file-pdf'"></i>
                        <span x-text="printingTicket ? '...' : 'Ver PDF'"></span>
                    </button>
                    <button 
                        @click="shareWhatsApp()"
                        class="py-2.5 px-3 bg-green-500 text-white rounded-xl font-medium flex items-center justify-center gap-2 text-sm">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </button>
                </div>
                
                <button 
                    @click="newSale()"
                    class="w-full py-2.5 text-blue-600 font-semibold text-sm">
                    Nueva Venta
                </button>
            </div>
        </div>
        
        <!-- Modal de Alerta/Error -->
        <div 
            x-show="showAlertModal"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-6">
            <div class="absolute inset-0 bg-black/70" @click="closeAlert()"></div>
            
            <div 
                x-show="showAlertModal"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100"
                class="relative bg-white dark:bg-slate-800 rounded-3xl p-6 max-w-sm w-full text-center shadow-2xl">
                
                <div class="w-16 h-16 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="text-4xl" x-text="alertIcon"></span>
                </div>
                
                <h2 class="text-xl font-bold dark:text-white mb-2" x-text="alertTitle"></h2>
                <p class="text-slate-500 dark:text-slate-400 mb-6 text-sm" x-text="alertMessage"></p>
                
                <button 
                    @click="closeAlert()"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition-all active:scale-95">
                    Aceptar
                </button>
            </div>
        </div>
        
        <!-- Toast Container -->
        <div class="fixed top-4 right-4 z-50 flex flex-col gap-2 pointer-events-none w-[min(92vw,420px)] items-end">
            <template x-for="toast in toasts" :key="toast.id">
                <div 
                    x-show="toast.show"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-4"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-200"
                    :class="{
                        'bg-emerald-600': toast.type === 'success',
                        'bg-red-600': toast.type === 'error',
                        'bg-yellow-500 text-slate-950': toast.type === 'warning',
                        'bg-blue-600': toast.type === 'info'
                    }"
                    class="w-full px-4 py-3 rounded-xl shadow-xl text-white border border-black/20 flex items-center gap-3 pointer-events-auto">
                    <i :class="{
                        'fas fa-check-circle': toast.type === 'success',
                        'fas fa-times-circle': toast.type === 'error',
                        'fas fa-exclamation-triangle': toast.type === 'warning',
                        'fas fa-info-circle': toast.type === 'info'
                    }"></i>
                    <span class="flex-1 font-medium" x-text="toast.message"></span>
                    <button
                        @click="copyToastMessage(toast.message)"
                        class="text-[11px] px-2 py-1 rounded bg-slate-900 hover:bg-slate-800 text-white">
                        Copiar
                    </button>
                    <button
                        @click="removeToastById(toast.id)"
                        class="text-base leading-none opacity-80 hover:opacity-100">
                        &times;
                    </button>
                </div>
            </template>
        </div>
    </div>
    
    <script>
        // ===== SISTEMA DE IMÁGENES DE PRODUCTOS =====
        const RUTA_IMAGEN_MERCADERIAS = '<?php echo addslashes($rutaImagenMercaderias ?? ''); ?>';
        const ID_EMPRESA = <?php echo $id_empresa; ?>;
        const POPULAR_PRODUCTS_SEED = <?php echo json_encode($popularProductsSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        
        const AVATAR_COLORS = [
            '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8',
            '#F7DC6F', '#BB8FCE', '#85C1E2', '#F8B88B', '#AAD8A7',
            '#FF8C94', '#A8E6CF', '#FDCB6E', '#6C5CE7', '#00B894'
        ];
        
        function getAvatarColor(text) {
            let hash = 0;
            for (let i = 0; i < (text || '').length; i++) {
                hash = text.charCodeAt(i) + ((hash << 5) - hash);
            }
            return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
        }
        
        function generateProductAvatar(name) {
            const initial = (name || 'P').charAt(0).toUpperCase();
            const color = getAvatarColor(name);
            return `<div class="product-avatar" style="background: ${color}; position: absolute; top: 0; left: 0; width: 100%; height: 100%;">${initial}</div>`;
        }

        function sanitizeMalformedVariantUrl(url) {
            return String(url || '').replace(/\.webp_(thumb|small|medium|large)\.webp/gi, '.webp');
        }

        function buildVariantFallbackChain(producto) {
            const p = producto || {};
            const candidates = [
                p.imagen_thumb,
                p.foto_thumb_url,
                p.imagen_small,
                p.foto_small_url,
                p.imagen_medium,
                p.foto_medium_url,
                p.imagen_large,
                p.foto_large_url,
                p.imagen,
                p.foto_url
            ]
                .map((url) => sanitizeMalformedVariantUrl(url))
                .filter(Boolean);
            return Array.from(new Set(candidates));
        }

        function handleProductImageError(img) {
            if (!img) return;
            const chainRaw = img.dataset.fallbackChain
                ? (() => {
                    try { return JSON.parse(img.dataset.fallbackChain || '[]'); } catch (_) { return []; }
                })()
                : [];
            const chain = Array.isArray(chainRaw) ? chainRaw.filter(Boolean) : [];
            const current = String(img.getAttribute('src') || '');
            const idx = Math.max(0, Number(img.dataset.fallbackIndex || 0));

            if (idx < (chain.length - 1)) {
                const nextUrl = String(chain[idx + 1] || '');
                if (nextUrl && nextUrl !== current) {
                    img.dataset.fallbackIndex = String(idx + 1);
                    img.src = nextUrl;
                    return;
                }
            }

            img.style.display = 'none';
            const avatar = img.previousElementSibling;
            if (avatar && avatar.style) {
                avatar.style.display = 'flex';
            }
        }

        function handleProductImageLoad(img) {
            if (!img) return;
            img.style.display = 'block';
            const avatar = img.previousElementSibling;
            if (avatar && avatar.style) {
                avatar.style.display = 'none';
            }
        }
        
        function getProductImageHTML(producto) {
            if (!producto) return generateProductAvatar('P');
            
            const avatar = generateProductAvatar(producto.descripcion || 'Producto');
            const productDesc = (producto.descripcion || '').replace(/"/g, '&quot;');

            const fallbackChain = buildVariantFallbackChain(producto);
            const imageCandidate = fallbackChain[0] || '';

            if (imageCandidate) {
                const imagenUrl = imageCandidate.startsWith('/_lib') ? '/public' + imageCandidate : imageCandidate;
                const fallbackChainAttr = JSON.stringify(
                    fallbackChain.map((url) => (String(url || '').startsWith('/_lib') ? '/public' + url : url))
                ).replace(/"/g, '&quot;');
                return `
                    ${avatar}
                    <img src="${imagenUrl}" 
                         alt="${productDesc}"
                         loading="lazy" decoding="async"
                         style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;"
                         data-fallback-chain="${fallbackChainAttr}"
                         data-fallback-index="0"
                         onerror="handleProductImageError(this)"
                         onload="handleProductImageLoad(this)" />
                `;
            }

            const proxyUrl = `/public/pos/api/imagen_proxy.php?id=${producto.id || producto.idproducto}&q=${encodeURIComponent(producto.descripcion || 'producto')}`;
            return `
                ${avatar}
                <img src="${proxyUrl}" 
                     alt="${productDesc}"
                     loading="lazy" decoding="async"
                     style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;"
                     onerror="handleProductImageError(this)"
                     onload="handleProductImageLoad(this)" />
            `;
        }

        function getCartImageHTML(producto) {
            return getProductImageHTML(producto);
        }
        
        // Audio System
        const POSAudio = {
            ctx: null,
            muted: localStorage.getItem('pos_sound_muted') === 'true',
            sounds: {
                success: { freq: 880, duration: 0.1, type: 'sine', vol: 0.15 },
                error: { freq: 220, duration: 0.2, type: 'square', vol: 0.1 },
                warning: { freq: 440, duration: 0.15, type: 'triangle', vol: 0.12 },
                click: { freq: 1200, duration: 0.05, type: 'sine', vol: 0.08 }
            },
            init() {
                if (this.ctx) return;
                try {
                    this.ctx = new (window.AudioContext || window.webkitAudioContext)();
                } catch (e) {}
            },
            play(type) {
                if (this.muted) return;
                if (!this.ctx) this.init();
                try {
                    const sound = this.sounds[type] || this.sounds.click;
                    const osc = this.ctx.createOscillator();
                    const gain = this.ctx.createGain();
                    osc.connect(gain);
                    gain.connect(this.ctx.destination);
                    osc.type = sound.type;
                    osc.frequency.value = sound.freq;
                    gain.gain.setValueAtTime(sound.vol, this.ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, this.ctx.currentTime + sound.duration);
                    osc.start();
                    osc.stop(this.ctx.currentTime + sound.duration);
                } catch (e) {}
            }
        };
        
        function posMobile() {
            return {
                // Config
                idEmpresa: <?php echo $id_empresa; ?>,
                idUsuario: <?php echo $id_login; ?>,
                idCaja: <?php echo $id_caja; ?>,
                emisorNombre: <?php echo json_encode($nombreEmpresa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                emisorRuc: <?php echo json_encode(trim($empresaRuc) . (trim($empresaDv) !== '' ? '-' . trim($empresaDv) : ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                isFacturaElectronica: <?php echo $isFacturaElectronica; ?>,
                selectedDocType: <?php echo $isFacturaElectronica ? "'electro'" : "'auto'"; ?>,
                defaultDocType: localStorage.getItem('pos_default_doc') || <?php echo $isFacturaElectronica ? "'electro'" : "'auto'"; ?>,
                defaultPaymentMethod: localStorage.getItem('pos_default_pm') || 'efectivo',
                _lpTimer: null,
                _lpFired: false,
                userConfig: {
                    cobro_df: <?php echo $cobro_df; ?>,
                    ancho_papel: <?php echo $ancho_papel; ?>,
                    isVendedor: <?php echo (mb_strtolower($rolUsuario, 'UTF-8') === 'vendedor') ? 'true' : 'false'; ?>
                },
                popularDebugEnabled: <?php echo $popularDebugEnabled ? 'true' : 'false'; ?>,
                
                // UI State
                isDarkMode: localStorage.getItem('theme') === 'dark',
                soundMuted: localStorage.getItem('pos_sound_muted') === 'true',
                mobileOS: 'other',
                isTabletDevice: false,
                activeTab: 'products',
                showMenu: false,
                showPaymentModal: false,
                showSuccessModal: false,
                isOfflineMode: typeof navigator !== 'undefined' ? !navigator.onLine : false,
                pendingOfflineSales: [],
                processingOfflineSync: false,
                saleProcessModal: {
                    show: false,
                    message: 'Guardando comprobante...',
                    detail: '',
                    saving: 'idle', // idle | loading | done
                    printing: 'idle' // idle | loading | done | error
                },
                showAlertModal: false,
                alertTitle: '',
                alertMessage: '',
                alertIcon: '⚠️',
                showKeyboard: false,
                keyboardShift: false,
                
                // Search & Products
                searchQuery: '',
                selectedPriceType: 1,
                productos: [],
                popularProducts: Array.isArray(POPULAR_PRODUCTS_SEED) ? POPULAR_PRODUCTS_SEED : [],
                popularProductsSeed: Array.isArray(POPULAR_PRODUCTS_SEED) ? POPULAR_PRODUCTS_SEED : [],
                popularLoading: false,
                popularError: '',
                selectedIndex: 0,
                loading: false,
                searchLoading: false,
                
                // Cart
                cart: [],
                selectedCliente: null,
                clienteSearch: '',
                clientesResults: [],
                showClienteDropdown: false,
                voiceListening: false,
                voiceListeningCliente: false,
                _voiceRecognition: null,
                _voiceRecognitionCliente: null,
                
                // Payment
                paymentMethod: 'efectivo',
                cashReceived: 0,
                voucherNumber: '',
                transferReference: '',
                pixReference: '',
                creditInstallments: 1,
                pendienteNotes: '',
                processingSale: false,
                printingTicket: false,
                printingSteward: false,
                printingNota40: false,
                printingFactura40: false,
                printerApp: 'sistemaxagent', // Compatibilidad legacy; SistemaX Pro usa bridge nativo
                smxPrinter: null,
                lastPrintError: '',
                lastVenta: null,
                lastVentaCliente: null,
                autoPrintDone: false, // Flag para indicar que ya se imprimió automáticamente
                
                // Captura de fotos de producto (camara)
                pendingPhotoProduct: null,
                photoCaptureLabel: 'adelante',
                photoCaptureOptions: ['adelante', 'atras', 'lado_izquierdo', 'lado_derecho'],
                savingImage: false,
                longPressTimer: null,
                suppressNextProductTap: false,
                showProductImageModal: false,
                productImageModal: {
                    product: null,
                    images: [],
                    index: 0,
                    autoplayId: null
                },
                showBarcodeScanner: false,
                barcodeScannerError: '',
                barcodeStream: null,
                barcodeDetector: null,
                barcodeDetectActive: false,
                lastAutoBarcodeQuery: '',
                pullStartY: 0,
                pullStartX: 0,
                pullTracking: false,
                pullScrollable: null,
                pullLastY: 0,
                pullTouchStartHandler: null,
                pullTouchMoveHandler: null,
                pullTouchEndHandler: null,
                pullBlockMode: false,
                
                // Numpad Modal para editar campos del carrito
                numpadModal: {
                    show: false,
                    type: '', // 'cantidad', 'precio', 'importe'
                    index: null,
                    item: null,
                    display: '',
                    rawValue: '',
                    replaceOnInput: false,
                    label: '',
                    productName: '',
                    minPrice: 0
                },
                numpadLongPressTimer: null,
                
                // Sales
                ventasHoy: [],
                loadingSales: false,
                salesSearchQuery: '',
                salesStatusFilter: 'activos',
                salesPeriod: 'hoy',
                salesDateFrom: new Date().toISOString().split('T')[0],
                salesDateTo: new Date().toISOString().split('T')[0],
                salesPeriodOptions: [
                    { value: 'todo', label: 'Todo' },
                    { value: 'hoy', label: 'Hoy' },
                    { value: 'semana', label: 'Semana' },
                    { value: 'mes', label: 'Mes' },
                    { value: 'anio', label: 'Año' },
                    { value: 'custom', label: 'Personalizado' }
                ],
                
                // Toasts
                toasts: [],
                toastId: 0,
                
                // Computed
                get total() {
                    return this.cart.reduce((sum, item) => sum + Math.round(item.precio * item.cantidad), 0);
                },
                
                // Helper para imágenes de productos
                getProductImageHTML(producto) {
                    return getProductImageHTML(producto);
                },

                getCartImageHTML(producto) {
                    return getCartImageHTML(producto);
                },

                popularCacheKey() {
                    return `pos_mobile_popular_${this.idEmpresa}_${this.selectedPriceType}`;
                },

                loadPopularCache() {
                    try {
                        const raw = localStorage.getItem(this.popularCacheKey());
                        if (!raw) return [];
                        const data = JSON.parse(raw);
                        if (!Array.isArray(data)) return [];
                        // Evitar reutilizar URLs locales antiguas que suelen quedar rotas en cache.
                        return data.map((p) => {
                            const clone = { ...(p || {}) };
                            const clearIfLegacyLocal = (value) => {
                                const s = String(value || '');
                                if (!s) return '';
                                if (s.includes('/_lib/file/img/productos/') || s.includes('/public/_lib/file/img/productos/')) {
                                    return '';
                                }
                                return s;
                            };
                            clone.imagen = clearIfLegacyLocal(clone.imagen);
                            clone.imagen_thumb = clearIfLegacyLocal(clone.imagen_thumb);
                            clone.imagen_small = clearIfLegacyLocal(clone.imagen_small);
                            clone.imagen_medium = clearIfLegacyLocal(clone.imagen_medium);
                            clone.imagen_large = clearIfLegacyLocal(clone.imagen_large);
                            if (Array.isArray(clone.imagenes)) {
                                clone.imagenes = clone.imagenes
                                    .map(clearIfLegacyLocal)
                                    .filter(Boolean);
                            }
                            return clone;
                        });
                    } catch (_) {
                        return [];
                    }
                },

                savePopularCache(list) {
                    try {
                        if (!Array.isArray(list) || list.length === 0) return;
                        localStorage.setItem(this.popularCacheKey(), JSON.stringify(list.slice(0, 60)));
                    } catch (_) {}
                },

                normalizeProductImageFields(raw = {}) {
                    const sanitize = (u) => String(u || '').replace(/\.webp_(thumb|small|medium|large)\.webp/gi, '.webp');
                    const imgThumb = sanitize(raw.imagen_thumb || raw.foto_thumb_url || '');
                    const imgSmall = sanitize(raw.imagen_small || raw.foto_small_url || raw.imagen || raw.foto_url || '');
                    const imgMedium = sanitize(raw.imagen_medium || raw.foto_medium_url || raw.imagen || raw.foto_url || '');
                    const imgLarge = sanitize(raw.imagen_large || raw.foto_large_url || raw.imagen || raw.foto_url || '');
                    const imageList = Array.isArray(raw.imagenes)
                        ? Array.from(new Set(raw.imagenes.map(sanitize).filter(Boolean)))
                        : [];
                    if (imageList.length === 0 && imgSmall) {
                        imageList.push(imgSmall);
                    }
                    return {
                        ...raw,
                        imagen_thumb: imgThumb || imgSmall || imgMedium || imgLarge || '',
                        imagen_small: imgSmall || imgMedium || imgLarge || imgThumb || '',
                        imagen_medium: imgMedium || imgLarge || imgSmall || imgThumb || '',
                        imagen_large: imgLarge || imgMedium || imgSmall || imgThumb || '',
                        imagen: imgSmall || imgMedium || imgLarge || imgThumb || sanitize(raw.imagen || ''),
                        imagenes: imageList
                    };
                },

                sanitizePopularList(list) {
                    if (!Array.isArray(list)) return [];
                    const seen = new Set();
                    const out = [];
                    for (const raw of list) {
                        if (!raw || typeof raw !== 'object') continue;
                        const p = this.normalizeProductImageFields({
                            ...raw,
                            id: raw.id ?? raw.idproducto ?? null,
                            descripcion: raw.descripcion ?? raw.desproducto ?? raw.producto_nombre ?? 'Producto',
                            precio: Number(raw.precio ?? raw.precio_venta ?? 0)
                        });
                        const k = String(p.id ?? '') + '|' + String(p.descripcion || '');
                        if (seen.has(k)) continue;
                        seen.add(k);
                        out.push(p);
                    }
                    return out;
                },

                hasSearchQuery() {
                    const q = String(this.searchQuery || '').replace(/[\u200B-\u200D\uFEFF]/g, '').trim();
                    return q.length > 0;
                },

                normalizeSearchQuery(value) {
                    return String(value || '').replace(/[\u200B-\u200D\uFEFF]/g, '').trim();
                },

                isBarcodeLikeQuery(value) {
                    const raw = this.normalizeSearchQuery(value);
                    const q = raw.replace(/\s+/g, '');
                    if (!q) return false;
                    if (/^\d{8,18}$/.test(q)) return true; // EAN/UPC/ITF comunes
                    if (/^[A-Za-z0-9\-]{10,30}$/.test(q) && /\d/.test(q)) return true;
                    return false;
                },

                isNumericOnlyQuery(value) {
                    return /^\d+$/.test(this.normalizeSearchQuery(value));
                },

                async lookupBarcodeProduct(code, { autoAdd = false, silentNotFound = true } = {}) {
                    const q = this.normalizeSearchQuery(code).replace(/\s+/g, '');
                    if (!q) return false;
                    try {
                        const params = new URLSearchParams({
                            action: 'barcode',
                            cod: q,
                            id_empresa: this.idEmpresa,
                            tipo_precio: this.selectedPriceType
                        });
                        const res = await fetch(`api/productos.php?${params.toString()}`, { cache: 'no-store' });
                        const data = await res.json();
                        if (data?.producto && data.producto.id) {
                            const normalizedProduct = this.normalizeProductImageFields(data.producto);
                            if (autoAdd) {
                                this.addToCart(normalizedProduct, { source: 'barcode', silentToast: true });
                                this.searchQuery = '';
                                this.productos = [];
                            } else {
                                this.productos = [normalizedProduct];
                            }
                            return true;
                        }
                        this.productos = [];
                        if (!silentNotFound) this.toast(`Código no encontrado: ${q}`, 'warning');
                        return false;
                    } catch (_) {
                        if (!silentNotFound) this.toast('Error al procesar el código', 'error');
                        this.productos = [];
                        return false;
                    }
                },

                currentPopularProducts() {
                    if (Array.isArray(this.popularProducts) && this.popularProducts.length > 0) {
                        return this.sanitizePopularList(this.popularProducts);
                    }
                    const cached = this.loadPopularCache();
                    if (Array.isArray(cached) && cached.length > 0) {
                        return this.sanitizePopularList(cached);
                    }
                    if (Array.isArray(this.popularProductsSeed) && this.popularProductsSeed.length > 0) {
                        return this.sanitizePopularList(this.popularProductsSeed);
                    }
                    return [];
                },

                offlineSalesStorageKey() {
                    return `pos_mobile_offline_${this.idEmpresa}_${this.idCaja || 0}`;
                },

                loadPendingOfflineSales() {
                    try {
                        const raw = localStorage.getItem(this.offlineSalesStorageKey());
                        const parsed = raw ? JSON.parse(raw) : [];
                        this.pendingOfflineSales = Array.isArray(parsed) ? parsed : [];
                    } catch (_) {
                        this.pendingOfflineSales = [];
                    }
                },

                savePendingOfflineSales() {
                    try {
                        localStorage.setItem(this.offlineSalesStorageKey(), JSON.stringify(this.pendingOfflineSales));
                    } catch (_) {}
                },

                createOfflineSyncId(prefix = 'venta') {
                    const rand = Math.random().toString(36).slice(2, 10);
                    return `${prefix}-${this.idEmpresa}-${this.idCaja || 0}-${Date.now()}-${rand}`;
                },

                isLikelyOfflineError(error) {
                    const msg = String(error?.message || error || '').toLowerCase();
                    return !navigator.onLine
                        || msg.includes('failed to fetch')
                        || msg.includes('networkerror')
                        || msg.includes('load failed')
                        || msg.includes('error de conexión')
                        || msg.includes('error de conexion')
                        || msg.includes('fetch');
                },

                canQueueOfflineVenta(payload = {}) {
                    const method = String(payload.payment_method || payload.forma_pago || '').toLowerCase();
                    return !['pix', 'qr', 'ueno'].includes(method);
                },

                queueVentaOffline(payload) {
                    const queueItem = {
                        queue_id: this.createOfflineSyncId('queue'),
                        created_at: new Date().toISOString(),
                        payload: { ...payload }
                    };
                    this.pendingOfflineSales.push(queueItem);
                    this.savePendingOfflineSales();
                    return queueItem;
                },

                buildOfflineVentaPreview(payload) {
                    const syncId = String(payload?.offline_sync_id || this.createOfflineSyncId('venta')).trim();
                    return {
                        id_factura: null,
                        offline_sync_id: syncId,
                        nro_factura: syncId.slice(-12).toUpperCase(),
                        total: Number(this.total || 0),
                        tipo_documento: this.selectedDocType === 'electro' ? 3 : (this.selectedDocType === 'auto' ? 1 : 0),
                        cdc: null,
                        fecha: new Date().toISOString(),
                        payment_method: this.paymentMethod,
                        queued_offline: true,
                        offline_pending: true
                    };
                },

                async submitVentaPayload(payload, options = {}) {
                    const outbound = { ...payload };
                    if (!outbound.offline_sync_id) {
                        outbound.offline_sync_id = this.createOfflineSyncId('venta');
                    }
                    try {
                        const response = await fetch('api/venta.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify(outbound)
                        });

                        const rawText = await response.text();
                        let result = null;
                        try {
                            result = rawText ? JSON.parse(rawText) : null;
                        } catch (parseErr) {
                            const looksHtml = /^\s*</.test(rawText || '');
                            if (looksHtml) {
                                throw new Error('La sesión expiró o la respuesta no es JSON. Volvé a iniciar sesión.');
                            }
                            throw new Error('Respuesta inválida del servidor al guardar la venta.');
                        }

                        if (!response.ok) {
                            throw new Error(result?.message || `Error HTTP ${response.status} al guardar venta`);
                        }

                        return { queued: false, payload: outbound, result };
                    } catch (error) {
                        if (options.allowQueue !== false && this.canQueueOfflineVenta(outbound) && this.isLikelyOfflineError(error)) {
                            const queueItem = this.queueVentaOffline(outbound);
                            this.toast(
                                'Sin conexión. La venta quedó guardada localmente y se sincronizará al volver internet.',
                                'warning'
                            );
                            return {
                                queued: true,
                                payload: outbound,
                                queueItem,
                                result: {
                                    success: true,
                                    queued_offline: true,
                                    data: {
                                        offline_sync_id: outbound.offline_sync_id
                                    }
                                }
                            };
                        }
                        throw error;
                    }
                },

                async syncPendingOfflineSales(showToast = false) {
                    if (this.processingOfflineSync || !navigator.onLine || !this.pendingOfflineSales.length) return;
                    this.processingOfflineSync = true;
                    let synced = 0;
                    const remaining = [];

                    for (const item of this.pendingOfflineSales) {
                        try {
                            const response = await fetch('api/venta.php', {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify(item.payload || {})
                            });
                            const result = await response.json();
                            if (response.ok && result?.success) {
                                synced += 1;
                                continue;
                            }
                            remaining.push({
                                ...item,
                                last_error: result?.message || `HTTP ${response.status}`,
                                last_attempt_at: new Date().toISOString()
                            });
                        } catch (error) {
                            remaining.push({
                                ...item,
                                last_error: error?.message || 'Error de sincronización',
                                last_attempt_at: new Date().toISOString()
                            });
                            break;
                        }
                    }

                    this.pendingOfflineSales = remaining;
                    this.savePendingOfflineSales();
                    this.processingOfflineSync = false;

                    if (synced > 0 || showToast) {
                        this.toast(
                            synced > 0
                                ? `Se sincronizaron ${synced} venta(s) pendientes.`
                                : 'No se pudieron sincronizar ventas pendientes todavía.',
                            synced > 0 ? 'success' : 'warning'
                        );
                    }
                },

                async setupOfflineSupport() {
                    this.isOfflineMode = typeof navigator !== 'undefined' ? !navigator.onLine : false;
                    this.loadPendingOfflineSales();
                    await window.registerPosMobilePwa?.();

                    this._offlineStatusListener = () => {
                        this.isOfflineMode = true;
                        this.toast('Modo sin internet activo. Se usarán datos ya cargados y la cola local.', 'warning');
                    };
                    this._onlineStatusListener = async () => {
                        this.isOfflineMode = false;
                        this.toast('Conexión restablecida. Sincronizando ventas pendientes...', 'info');
                        await this.syncPendingOfflineSales(true);
                    };

                    window.addEventListener('offline', this._offlineStatusListener);
                    window.addEventListener('online', this._onlineStatusListener);

                    if (navigator.onLine && this.pendingOfflineSales.length) {
                        await this.syncPendingOfflineSales(false);
                    }
                },

                detectMobileOS() {
                    try {
                        const ua = String(navigator.userAgent || '');
                        const isAndroid = /android/i.test(ua);
                        const isIOS = /iPad|iPhone|iPod/.test(ua)
                            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                        if (isAndroid) return 'android';
                        if (isIOS) return 'ios';
                    } catch (_) {}
                    return 'other';
                },

                detectTabletDevice() {
                    try {
                        const ua = String(navigator.userAgent || '');
                        const touchPoints = Number(navigator.maxTouchPoints || 0);
                        const minViewport = Math.min(window.innerWidth || 0, window.innerHeight || 0);
                        const isIPad = /iPad/i.test(ua) || (navigator.platform === 'MacIntel' && touchPoints > 1);
                        const isAndroidTablet = /Android/i.test(ua) && !/Mobile/i.test(ua);
                        const explicitTablet = /Tablet|Nexus 7|Nexus 10|SM-T|Lenovo TB|Tab/i.test(ua);
                        const largeTouchViewport = touchPoints > 0 && minViewport >= 768;
                        return !!(isIPad || isAndroidTablet || explicitTablet || largeTouchViewport);
                    } catch (_) {
                        return false;
                    }
                },

                updateDeviceLayout() {
                    this.isTabletDevice = this.detectTabletDevice();
                    if (this.isTabletDevice && this.activeTab === 'cart') {
                        this.activeTab = 'products';
                    }
                },

                showProductsPanel() {
                    return this.isTabletDevice ? this.activeTab !== 'sales' : this.activeTab === 'products';
                },

                showCartPanel() {
                    return this.isTabletDevice ? this.activeTab !== 'sales' : this.activeTab === 'cart';
                },

                showSalesPanel() {
                    return this.activeTab === 'sales';
                },

                openTabletWorkspace() {
                    this.activeTab = 'products';
                    this.ensurePopularProducts();
                },

                tabletHeaderButtonClass(target) {
                    const active = (target === 'sales')
                        ? this.activeTab === 'sales'
                        : this.activeTab !== 'sales';
                    return active
                        ? 'bg-blue-600 text-white shadow'
                        : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200';
                },

                tabMenuContainerClass() {
                    return 'flex justify-around py-1.5';
                },

                tabMenuButtonClass(compact = false) {
                    return compact ? 'py-0.5 px-3' : 'py-0.5 px-4';
                },

                tabMenuIconClass() {
                    return 'text-lg';
                },

                tabMenuLabelClass() {
                    return 'text-[10px] mt-0.5 font-normal tracking-tight';
                },

                findScrollableParent(el) {
                    let node = el instanceof Element ? el : null;
                    while (node && node !== document.body) {
                        const style = window.getComputedStyle(node);
                        const overflowY = String(style.overflowY || '');
                        const canScroll = (overflowY === 'auto' || overflowY === 'scroll') && node.scrollHeight > node.clientHeight;
                        if (canScroll) return node;
                        node = node.parentElement;
                    }
                    return document.scrollingElement || document.documentElement;
                },

                canAnyAncestorScrollUp(el) {
                    let node = el instanceof Element ? el : null;
                    while (node && node !== document.body) {
                        const style = window.getComputedStyle(node);
                        const overflowY = String(style.overflowY || '');
                        const canScroll = (overflowY === 'auto' || overflowY === 'scroll') && node.scrollHeight > node.clientHeight;
                        if (canScroll && Number(node.scrollTop || 0) > 0) return true;
                        node = node.parentElement;
                    }
                    const root = document.scrollingElement || document.documentElement;
                    return Number(root?.scrollTop || 0) > 0;
                },

                setupPullToRefreshGuard() {
                    if (this.pullTouchStartHandler || !('ontouchstart' in window)) return;
                    this.pullBlockMode = (() => {
                        try {
                            return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
                        } catch (_) {
                            return false;
                        }
                    })();

                    this.pullTouchStartHandler = (event) => {
                        if (!event.touches || event.touches.length !== 1) return;
                        const touch = event.touches[0];
                        this.pullStartY = touch.clientY;
                        this.pullLastY = touch.clientY;
                        this.pullStartX = touch.clientX;
                        this.pullTracking = true;
                        this.pullScrollable = this.findScrollableParent(event.target);
                    };

                    this.pullTouchMoveHandler = (event) => {
                        if (!this.pullTracking || !event.touches || event.touches.length !== 1) return;
                        const touch = event.touches[0];
                        const deltaY = touch.clientY - this.pullStartY;
                        const moveY = touch.clientY - this.pullLastY;
                        const deltaX = Math.abs(touch.clientX - this.pullStartX);
                        this.pullLastY = touch.clientY;

                        // Ignorar gestos horizontales.
                        if (deltaX > Math.abs(deltaY)) return;
                        const scrollable = this.findScrollableParent(event.target);
                        if (!scrollable) {
                            if (event.cancelable) event.preventDefault();
                            return;
                        }

                        const scrollTop = Number(scrollable.scrollTop || 0);
                        const maxScrollTop = Math.max(0, Number(scrollable.scrollHeight || 0) - Number(scrollable.clientHeight || 0));

                        // Si el dedo baja y ya está en tope -> bloquear bounce/refresh.
                        if (moveY > 0 && scrollTop <= 0) {
                            if (event.cancelable) event.preventDefault();
                            return;
                        }

                        // Si el dedo sube y ya está en fondo -> bloquear bounce.
                        if (moveY < 0 && scrollTop >= maxScrollTop) {
                            if (event.cancelable) event.preventDefault();
                            return;
                        }

                        // Guard extra en modo app: si empieza descendente desde tope, bloquea siempre refresh.
                        if (this.pullBlockMode && deltaY > 8 && scrollTop <= 0) {
                            if (event.cancelable) event.preventDefault();
                        }
                    };

                    this.pullTouchEndHandler = () => { this.pullTracking = false; };

                    document.addEventListener('touchstart', this.pullTouchStartHandler, { passive: true, capture: true });
                    document.addEventListener('touchmove', this.pullTouchMoveHandler, { passive: false, capture: true });
                    document.addEventListener('touchend', this.pullTouchEndHandler, { passive: true, capture: true });
                    document.addEventListener('touchcancel', this.pullTouchEndHandler, { passive: true, capture: true });

                    // Refuerzo para WebViews que no respetan solo document-level listeners.
                    window.addEventListener('touchmove', this.pullTouchMoveHandler, { passive: false, capture: true });
                    document.body?.addEventListener('touchmove', this.pullTouchMoveHandler, { passive: false, capture: true });
                },
                
                // Init - agregar inicialización de impresora
                init() {
                    POSAudio.init();
                    this.mobileOS = this.detectMobileOS();
                    this.updateDeviceLayout();
                    this.setupPullToRefreshGuard();
                    this.setupOfflineSupport();
                    this.searchQuery = '';
                    this.productos = [];
                    this.$nextTick(() => {
                        try {
                            if (this.$refs.searchInput) this.$refs.searchInput.value = '';
                        } catch (_) {}
                    });
                    const cachedPopular = this.loadPopularCache();
                    if (Array.isArray(cachedPopular) && cachedPopular.length > 0) {
                        this.popularProducts = this.sanitizePopularList(cachedPopular);
                    }
                    this.loadPopularProducts();
                    this.loadSavedCart();
                    this.selectedDocType = this.defaultDocType;
                    const savedPrinterApp = localStorage.getItem('pos_printer_app');
                    if (!savedPrinterApp || savedPrinterApp === 'rawbt') {
                        this.printerApp = 'sistemaxagent';
                        localStorage.setItem('pos_printer_app', 'sistemaxagent');
                    } else {
                        this.printerApp = savedPrinterApp;
                    }
                    // En Android forzamos Sistemax Agent para evitar quedar en PDF por config vieja.
                    try {
                        if (/Android/i.test(navigator.userAgent || '')) {
                            this.printerApp = 'sistemaxagent';
                            localStorage.setItem('pos_printer_app', 'sistemaxagent');
                        }
                    } catch (_) {}
                    
                    if (this.isDarkMode) {
                        document.documentElement.classList.add('dark');
                    }
                    
                    this.vibrate = (ms = 10) => {
                        if ('vibrate' in navigator) {
                            navigator.vibrate(ms);
                        }
                    };
                    
                    // Verificar soporte Web USB
                    this.checkUSBSupport();
                    
                    // Intentar reconectar impresora guardada
                    this.tryReconnectPrinter();
                    
                    this.setSalesPeriod('hoy', false);

                    const tab = new URLSearchParams(window.location.search).get('tab');
                    if (tab && ['products', 'cart', 'sales'].includes(tab)) {
                        this.activeTab = tab;
                        if (tab === 'sales') this.loadVentasHoy();
                    }

                    this.updateDeviceLayout();
                    window.addEventListener('resize', () => this.updateDeviceLayout());

                    window.addEventListener('beforeunload', () => {
                        this.stopBarcodeScanner();
                        this.stopProductImageAutoplay();
                    });
                },

                isAndroidNativePrintBridge() {
                    try {
                        return !!window.Android
                            && typeof window.Android.printerHealthJson === 'function'
                            && typeof window.Android.printerListJson === 'function'
                            && typeof window.Android.printRawJson === 'function';
                    } catch (_) {
                        return false;
                    }
                },

                getNativePrintUnavailableMessage() {
                    return 'Esta pantalla no está usando la versión nueva de SistemaX Pro. Cerrá y abrí la app actualizada, o reinstalá el APK más reciente.';
                },

                async ensureSmxPrinterReady() {
                    if (typeof window.SmxPrinter === 'undefined') {
                        throw new Error('Motor de impresion no cargado');
                    }
                    if (!this.smxPrinter) {
                        this.smxPrinter = new window.SmxPrinter({
                            strategy: 'agent-only',
                            agentBaseUrl: 'http://127.0.0.1:17890',
                            agentTimeoutMs: 1800
                        });
                    }
                    try {
                        await this.smxPrinter.connect();
                    } catch (error) {
                        const msg = String(error?.message || '').trim();
                        if (/bridge android no disponible/i.test(msg)) {
                            this.smxPrinter = null;
                            throw new Error(this.getNativePrintUnavailableMessage());
                        }
                        throw error;
                    }
                    return this.smxPrinter;
                },

                async resolveTargetPrinterName() {
                    const printer = await this.ensureSmxPrinterReady();

                    try {
                        const defaultPrinter = String(await printer.getDefaultPrinter() || '').trim();
                        if (defaultPrinter) return defaultPrinter;
                    } catch (_) {}

                    const printers = await printer.findPrinters('');
                    if (Array.isArray(printers) && printers.length > 0) {
                        return String(printers[0] || '').trim();
                    }

                    throw new Error('No hay impresoras Bluetooth emparejadas');
                },

                getTodayIso() {
                    return new Date().toISOString().split('T')[0];
                },

                setSalesPeriod(period, reload = true) {
                    this.salesPeriod = period;
                    const now = new Date();
                    const iso = (d) => d.toISOString().split('T')[0];
                    let from = new Date(now);
                    let to = new Date(now);

                    if (period === 'todo') {
                        from = new Date(now.getFullYear() - 3, 0, 1);
                    } else if (period === 'semana') {
                        const day = now.getDay();
                        const diff = day === 0 ? 6 : day - 1;
                        from.setDate(now.getDate() - diff);
                    } else if (period === 'mes') {
                        from = new Date(now.getFullYear(), now.getMonth(), 1);
                    } else if (period === 'anio') {
                        from = new Date(now.getFullYear(), 0, 1);
                    } else if (period === 'custom') {
                        from = new Date(this.salesDateFrom || this.getTodayIso());
                        to = new Date(this.salesDateTo || this.getTodayIso());
                    }

                    this.salesDateFrom = iso(from);
                    this.salesDateTo = iso(to);
                    if (reload) this.loadVentasHoy();
                },

                applySalesCustomRange() {
                    if (!this.salesDateFrom || !this.salesDateTo) return;
                    if (this.salesDateFrom > this.salesDateTo) {
                        const tmp = this.salesDateFrom;
                        this.salesDateFrom = this.salesDateTo;
                        this.salesDateTo = tmp;
                    }
                    this.setSalesPeriod('custom');
                },

                clearSalesFilters() {
                    this.salesSearchQuery = '';
                    this.salesStatusFilter = 'activos';
                    this.setSalesPeriod('hoy');
                },

                exportVentasExcel() {
                    const headers = ['ID', 'Nro Factura', 'Fecha', 'Hora', 'Cliente', 'RUC', 'Total', 'Estado', 'Tipo Documento'];
                    const rows = (this.ventasHoy || []).map(v => [
                        v.id_factura ?? '',
                        v.nro_factura ?? '',
                        (v.fecha || '').toString().slice(0, 10),
                        v.hora || '',
                        (v.cliente || v.cliente_nombre || '').replace(/\n/g, ' '),
                        v.cliente_ruc || '',
                        Number(v.total || 0),
                        v.estado || '',
                        v.tipo_documento || ''
                    ]);
                    const csv = [headers, ...rows]
                        .map(row => row.map(cell => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','))
                        .join('\n');
                    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `ventas_mobile_${this.salesDateFrom}_a_${this.salesDateTo}.csv`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                },
                
                // ===== FUNCIONES DE IMPRESORA USB =====
                
                checkUSBSupport() {
                    if (!window.USBPrinter || !window.USBPrinter.isSupported()) {
                        console.warn('⚠️ Web USB no soportado en este navegador');
                    } else {
                        console.log('✅ Web USB disponible');
                    }
                },
                
                async connectUSBPrinter() {
                    if (!window.USBPrinter) {
                        console.error('❌ window.USBPrinter no está definido. Verifica que js/usb-printer.js se cargó correctamente.');
                        this.toast('Recarga la página para activar la impresora', 'warning');
                        return;
                    }
                    
                    if (!window.USBPrinter.isSupported()) {
                        this.toast('Tu navegador no soporta Web USB. Usa Chrome o Edge.', 'error');
                        return;
                    }
                    
                    try {
                        this.toast('Selecciona tu impresora...', 'info');
                        const result = await window.USBPrinter.connect();
                        
                        this.usbPrinterConnected = true;
                        this.usbPrinterName = result.name;
                        
                        this.toast(`Impresora "${result.name}" conectada`, 'success');
                        POSAudio.play('success');
                        
                        // Guardar preferencia
                        localStorage.setItem('pos_usb_printer_connected', 'true');
                        
                    } catch (error) {
                        console.error('Error conectando impresora:', error);
                        if (error.name === 'NotFoundError') {
                            this.toast('No se seleccionó ninguna impresora', 'warning');
                        } else {
                            this.toast('Error: ' + error.message, 'error');
                        }
                        this.usbPrinterConnected = false;
                    }
                },
                
                async tryReconnectPrinter() {
                    // Web USB no permite reconexión automática sin interacción del usuario
                    // Solo marcamos que había una conexión previa
                    const wasConnected = localStorage.getItem('pos_usb_printer_connected') === 'true';
                    if (wasConnected) {
                        console.log('ℹ️ Había una impresora conectada anteriormente');
                    }
                },
                
                async printUSB() {
                    // Verificar primero que el módulo esté disponible
                    if (!window.USBPrinter) {
                        console.error('❌ Módulo USBPrinter no cargado');
                        this.toast('Recarga la página (Ctrl+Shift+R) para usar la impresora', 'warning');
                        return;
                    }
                    
                    if (!this.lastVenta?.id_factura) {
                        this.toast('No hay venta para imprimir', 'error');
                        return;
                    }
                    
                    // Si no hay impresora conectada, intentar conectar
                    if (!this.usbPrinterConnected || !window.USBPrinter?.isConnected) {
                        try {
                            await this.connectUSBPrinter();
                            if (!this.usbPrinterConnected) return;
                        } catch (e) {
                            return;
                        }
                    }
                    
                    this.printingUSB = true;
                    this.vibrate(10);
                    
                    try {
                        await window.USBPrinter.printTicket(
                            this.lastVenta.id_factura, 
                            this.idEmpresa
                        );
                        
                        this.toast('Ticket impreso ✓', 'success');
                        POSAudio.play('success');
                        this.vibrate(50);
                        
                    } catch (error) {
                        console.error('Error imprimiendo:', error);
                        this.toast('Error al imprimir: ' + error.message, 'error');
                        POSAudio.play('error');
                        
                        // Si falló, marcar como desconectada
                        this.usbPrinterConnected = false;
                    }
                    
                    this.printingUSB = false;
                },
                
                async printTestUSB() {
                    if (!this.usbPrinterConnected) {
                        await this.connectUSBPrinter();
                        if (!this.usbPrinterConnected) return;
                    }
                    
                    try {
                        await window.USBPrinter.printTest();
                        this.toast('Prueba de impresión enviada', 'success');
                    } catch (error) {
                        this.toast('Error: ' + error.message, 'error');
                    }
                },
                
                async openCashDrawer() {
                    if (!this.usbPrinterConnected) {
                        this.toast('Conecta una impresora primero', 'warning');
                        return;
                    }
                    
                    try {
                        await window.USBPrinter.openCashDrawer();
                        this.toast('Cajón abierto', 'success');
                        this.vibrate(30);
                    } catch (error) {
                        this.toast('Error abriendo cajón', 'error');
                    }
                },
                
                // Formatters
                formatMoney(amount) {
                    return Math.round(Number(amount || 0)).toLocaleString('es-PY');
                },

                openKeyboard() {
                    this.showKeyboard = true;
                },

                async closeKeyboard() {
                    const q = this.normalizeSearchQuery(this.searchQuery);
                    if (q && this.isNumericOnlyQuery(q)) {
                        this.searchLoading = true;
                        try {
                            await this.lookupBarcodeProduct(q, { autoAdd: true, silentNotFound: false });
                        } finally {
                            this.searchLoading = false;
                        }
                    }
                    this.showKeyboard = false;
                    this.keyboardShift = false;
                    this.$refs.searchInput?.blur();
                },

                keyboardInput(key) {
                    const char = this.keyboardShift ? key.toUpperCase() : key;
                    this.searchQuery = `${this.searchQuery || ''}${char}`;
                    if (this.keyboardShift) this.keyboardShift = false;
                    this.searchProducts();
                },

                keyboardBackspace() {
                    this.searchQuery = (this.searchQuery || '').slice(0, -1);
                    this.searchProducts();
                },

                keyboardClear() {
                    this.searchQuery = '';
                    this.searchProducts();
                },
                
                highlightMatch(text, query) {
                    if (!query || !text) return text;
                    const regex = new RegExp(`(${query})`, 'gi');
                    return text.replace(regex, '<mark class="bg-yellow-200 dark:bg-yellow-800 rounded px-0.5">$1</mark>');
                },

                debugPopular(eventName, extra = {}) {
                    if (!this.popularDebugEnabled) return;
                    try {
                        fetch('api/popular_debug.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'same-origin',
                            keepalive: true,
                            body: JSON.stringify({
                                event: eventName,
                                detail: extra,
                                page: location.href
                            })
                        }).catch(() => {});
                    } catch (_) {}
                },
                
                async loadPopularProducts(force = false) {
                    if (this.popularLoading) return;
                    const previousPopular = Array.isArray(this.popularProducts) ? this.popularProducts.slice(0) : [];
                    this.popularLoading = true;
                    if (force) this.popularError = '';
                    this.debugPopular('load_start', { force, id_empresa: this.idEmpresa, tipo_precio: this.selectedPriceType });
                    const normalizeList = (arr) => {
                        if (!Array.isArray(arr)) return [];
                        return arr
                            .filter(Boolean)
                            .map((p) => ({
                                ...p,
                                id: p.id ?? p.idproducto ?? null,
                                descripcion: p.descripcion ?? p.desproducto ?? p.producto_nombre ?? 'Producto',
                                precio: Number(p.precio ?? p.precio_venta ?? 0)
                            }))
                            .filter((p) => p.id !== null);
                    };
                    const safeFetchProductos = async (url) => {
                        try {
                            const res = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
                            const txt = await res.text();
                            if (!txt || !res.ok) return [];
                            try {
                                const data = JSON.parse(txt);
                                return normalizeList(data?.productos || []);
                            } catch (_) {
                                const i = txt.indexOf('{');
                                if (i >= 0) {
                                    try {
                                        const data2 = JSON.parse(txt.slice(i));
                                        return normalizeList(data2?.productos || []);
                                    } catch (_) {}
                                }
                            }
                        } catch (_) {}
                        return [];
                    };
                    try {
                        if (!force && Array.isArray(this.popularProducts) && this.popularProducts.length > 0) {
                            this.debugPopular('load_skip_cached_in_memory', { count: this.popularProducts.length });
                            this.popularLoading = false;
                            return;
                        }
                        const popularUrl = `api/productos.php?action=popular&id_empresa=${this.idEmpresa}&tipo_precio=${this.selectedPriceType}&_t=${Date.now()}`;
                        const popularList = await safeFetchProductos(popularUrl);
                        this.debugPopular('popular_response', { count: popularList.length, url: popularUrl });
                        if (popularList.length) {
                            this.popularProducts = popularList;
                            this.savePopularCache(popularList);
                            this.popularError = '';
                            this.debugPopular('load_success', { source: 'popular', count: popularList.length });
                            return;
                        }

                        const mobileUrl = `api/productos.php?action=popular_mobile&id_empresa=${this.idEmpresa}&tipo_precio=${this.selectedPriceType}&_t=${Date.now()}`;
                        const mobileList = await safeFetchProductos(mobileUrl);
                        this.debugPopular('popular_mobile_response', { count: mobileList.length, url: mobileUrl });
                        if (mobileList.length) {
                            this.popularProducts = mobileList;
                            this.savePopularCache(mobileList);
                            this.popularError = '';
                            this.debugPopular('load_success', { source: 'popular_mobile', count: mobileList.length });
                            return;
                        }

                        // Empresa 169: usar estrategia rápida y robusta para evitar vacíos en móvil.
                        if (Number(this.idEmpresa) === 169) {
                            const firstList = await safeFetchProductos(`api/productos.php?action=popular_simple&id_empresa=${this.idEmpresa}&tipo_precio=${this.selectedPriceType}&_t=${Date.now()}`);
                            this.debugPopular('popular_simple_response_169', { count: firstList.length });
                            if (firstList.length) {
                                this.popularProducts = firstList;
                                this.savePopularCache(firstList);
                                this.popularError = '';
                                this.debugPopular('load_success', { source: 'popular_simple_169', count: firstList.length });
                            }
                            // Intento secundario de popular completo (sin bloquear UI)
                            fetch(`api/productos.php?action=popular&id_empresa=${this.idEmpresa}&tipo_precio=${this.selectedPriceType}`, { cache: 'no-store' })
                                .then(r => r.json())
                                .then(d => {
                                    const enriched = normalizeList(d.productos);
                                    if (enriched.length) {
                                        this.popularProducts = enriched;
                                        this.savePopularCache(enriched);
                                        this.popularError = '';
                                        this.debugPopular('load_success_async', { source: 'popular_async_169', count: enriched.length });
                                    }
                                })
                                .catch(async () => {
                                    // Fallback final empresa 169: usar endpoint de búsqueda genérico.
                                    try {
                                        const params = new URLSearchParams({
                                            action: 'search',
                                            q: '',
                                            id_empresa: this.idEmpresa,
                                            tipo_precio: this.selectedPriceType,
                                            estado: '1'
                                        });
                                        const r3 = await fetch(`api/productos.php?${params.toString()}`, { cache: 'no-store' });
                                        const d3 = await r3.json();
                                        const list3 = normalizeList(d3.productos);
                                        if (list3.length) {
                                            this.popularProducts = list3.slice(0, 24);
                                            this.savePopularCache(this.popularProducts);
                                            this.popularError = '';
                                            this.debugPopular('load_success_async', { source: 'search_fallback_async_169', count: list3.length });
                                        }
                                    } catch (_) {}
                                });

                            if (!firstList.length) {
                                try {
                                    const params = new URLSearchParams({
                                        action: 'search',
                                        q: '',
                                        id_empresa: this.idEmpresa,
                                        tipo_precio: this.selectedPriceType,
                                        estado: '1'
                                    });
                                    const r3 = await fetch(`api/productos.php?${params.toString()}`, { cache: 'no-store' });
                                    const d3 = await r3.json();
                                    const list3 = normalizeList(d3.productos);
                                    if (list3.length) {
                                        this.popularProducts = list3.slice(0, 24);
                                        this.savePopularCache(this.popularProducts);
                                        this.popularError = '';
                                        this.debugPopular('load_success', { source: 'search_fallback_169', count: list3.length });
                                    }
                                } catch (_) {}
                            }
                            return;
                        }

                        const urlPopular = `api/productos.php?action=popular&id_empresa=${this.idEmpresa}&tipo_precio=${this.selectedPriceType}&_t=${Date.now()}`;
                        const fetched = await safeFetchProductos(urlPopular);
                        this.debugPopular('popular_response_universal', { count: fetched.length, url: urlPopular });
                        if (fetched.length) {
                            this.popularProducts = fetched;
                            this.savePopularCache(fetched);
                            this.popularError = '';
                            this.debugPopular('load_success', { source: 'popular_universal', count: fetched.length });
                        }

                        // Fallback móvil: si popular viene vacío, usar lista simple.
                        if (!fetched.length) {
                            const fallback = await safeFetchProductos(`api/productos.php?action=popular_simple&id_empresa=${this.idEmpresa}&tipo_precio=${this.selectedPriceType}&_t=${Date.now()}`);
                            this.debugPopular('popular_simple_response_universal', { count: fallback.length });
                            if (fallback.length) {
                                this.popularProducts = fallback;
                                this.savePopularCache(fallback);
                                this.popularError = '';
                                this.debugPopular('load_success', { source: 'popular_simple_universal', count: fallback.length });
                            }
                        }

                        // Fallback final universal: búsqueda general.
                        if (!Array.isArray(this.popularProducts) || this.popularProducts.length === 0) {
                            const params = new URLSearchParams({
                                action: 'search',
                                q: '',
                                id_empresa: this.idEmpresa,
                                tipo_precio: this.selectedPriceType,
                                estado: '1',
                                _t: String(Date.now())
                            });
                            const list3 = await safeFetchProductos(`api/productos.php?${params.toString()}`);
                            if (list3.length) {
                                this.popularProducts = list3.slice(0, 24);
                                this.savePopularCache(this.popularProducts);
                                this.popularError = '';
                                this.debugPopular('load_success', { source: 'search_fallback_universal', count: list3.length });
                            }
                        }
                        if (!Array.isArray(this.popularProducts) || this.popularProducts.length === 0) {
                            const cached = this.loadPopularCache();
                            if (cached.length) {
                                this.popularProducts = cached;
                                this.popularError = '';
                                this.debugPopular('load_success', { source: 'local_cache_recover', count: cached.length });
                                return;
                            }
                            this.popularError = 'No se pudieron cargar los más vendidos.';
                            this.debugPopular('load_empty', { message: this.popularError });
                        }
                    } catch (e) {
                        console.error('Error loading popular products:', e);
                        this.debugPopular('load_exception', { message: String(e && (e.message || e) || 'error') });
                        try {
                            const fallback = await safeFetchProductos(`api/productos.php?action=popular_simple&id_empresa=${this.idEmpresa}&tipo_precio=${this.selectedPriceType}&_t=${Date.now()}`);
                            if (fallback.length) {
                                this.popularProducts = fallback;
                                this.savePopularCache(fallback);
                                this.popularError = '';
                                this.debugPopular('load_success', { source: 'popular_simple_exception', count: fallback.length });
                            }
                        } catch (_) {
                            // Mantener seed existente para no dejar vacío.
                        }
                        if (!Array.isArray(this.popularProducts) || this.popularProducts.length === 0) {
                            const cached = this.loadPopularCache();
                            if (cached.length) {
                                this.popularProducts = cached;
                                this.popularError = '';
                                this.debugPopular('load_success', { source: 'local_cache_exception_recover', count: cached.length });
                                return;
                            }
                            this.popularError = 'No se pudieron cargar los más vendidos.';
                            this.debugPopular('load_failed', { message: this.popularError });
                        }
                    } finally {
                        if ((!Array.isArray(this.popularProducts) || this.popularProducts.length === 0) && previousPopular.length > 0) {
                            this.popularProducts = previousPopular;
                        }
                        this.popularLoading = false;
                        this.debugPopular('load_end', { count: Array.isArray(this.popularProducts) ? this.popularProducts.length : 0, error: this.popularError || '' });
                    }
                },

                ensurePopularProducts() {
                    if (this.popularError) return;
                    if ((!Array.isArray(this.popularProducts) || this.popularProducts.length === 0) && !this.popularLoading) {
                        this.loadPopularProducts(false);
                    }
                },
                
                async searchProducts() {
                    const q = this.normalizeSearchQuery(this.searchQuery);
                    if (!q) {
                        this.productos = [];
                        this.lastAutoBarcodeQuery = '';
                        this.searchLoading = false;
                        return;
                    }

                    // Si parece código de barra, usar búsqueda exacta por barcode y no búsqueda general.
                    if (this.isBarcodeLikeQuery(q)) {
                        if (this.lastAutoBarcodeQuery === q) {
                            this.searchLoading = false;
                            return;
                        }
                        this.searchLoading = true;
                        try {
                            const ok = await this.lookupBarcodeProduct(q, { autoAdd: true, silentNotFound: true });
                            if (ok) {
                                this.lastAutoBarcodeQuery = q;
                            }
                        } finally {
                            this.searchLoading = false;
                        }
                        return;
                    }

                    this.searchLoading = true;
                    try {
                        const params = new URLSearchParams({
                            action: 'search',
                            q: q,
                            id_empresa: this.idEmpresa,
                            tipo_precio: this.selectedPriceType
                        });
                        const res = await fetch(`api/productos.php?${params}`);
                        const data = await res.json();
                        this.productos = this.sanitizePopularList(data.productos || []);
                    } catch (e) {
                        console.error('Error searching:', e);
                        this.productos = [];
                    } finally {
                        this.searchLoading = false;
                    }
                },
                
                async handleSearchEnter() {
                    const q = this.normalizeSearchQuery(this.searchQuery);
                    if (q && this.isBarcodeLikeQuery(q)) {
                        await this.lookupBarcodeProduct(q, { autoAdd: true, silentNotFound: false });
                        return;
                    }
                    if (this.productos.length > 0) {
                        this.addToCart(this.productos[0]);
                        this.searchQuery = '';
                        this.productos = [];
                    }
                },

                async openBarcodeScanner() {
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        this.toast('Tu navegador no soporta cámara para escaneo', 'error');
                        return;
                    }
                    this.showBarcodeScanner = true;
                    this.barcodeScannerError = '';
                    await this.$nextTick();
                    await this.startBarcodeScanner();
                },

                async startBarcodeScanner() {
                    try {
                        let stream = null;
                        try {
                            stream = await navigator.mediaDevices.getUserMedia({
                                video: {
                                    facingMode: { ideal: 'environment' },
                                    width: { ideal: 1280 },
                                    height: { ideal: 720 }
                                },
                                audio: false
                            });
                        } catch (_) {
                            stream = await navigator.mediaDevices.getUserMedia({
                                video: true,
                                audio: false
                            });
                        }
                        this.barcodeStream = stream;
                        const video = this.$refs.barcodeVideo;
                        if (!video) throw new Error('Video no disponible');
                        video.srcObject = stream;
                        await video.play();

                        if ('BarcodeDetector' in window) {
                            this.barcodeDetector = new BarcodeDetector({
                                formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar']
                            });
                            this.barcodeDetectActive = true;
                            this.barcodeScanLoop();
                        } else {
                            this.barcodeScannerError = 'Este dispositivo no soporta detector nativo. Ingresa el código manualmente.';
                        }
                    } catch (err) {
                        const errorName = String(err?.name || '');
                        if (errorName === 'NotAllowedError' || errorName === 'PermissionDeniedError') {
                            this.barcodeScannerError = 'Permiso de cámara bloqueado. Habilítalo en Chrome > Configuración del sitio > Cámara.';
                        } else if (errorName === 'NotFoundError' || errorName === 'DevicesNotFoundError') {
                            this.barcodeScannerError = 'No se encontró una cámara disponible en este dispositivo.';
                        } else if (errorName === 'NotReadableError' || errorName === 'TrackStartError') {
                            this.barcodeScannerError = 'La cámara está en uso por otra app. Cierra esa app y reintenta.';
                        } else {
                            this.barcodeScannerError = 'No se pudo iniciar la cámara. Verifica permisos del navegador.';
                        }
                        console.error('Barcode scanner camera error:', err);
                    }
                },

                async barcodeScanLoop() {
                    if (!this.barcodeDetectActive || !this.showBarcodeScanner) return;
                    const video = this.$refs.barcodeVideo;
                    try {
                        if (video && video.readyState >= 2 && this.barcodeDetector) {
                            const found = await this.barcodeDetector.detect(video);
                            if (Array.isArray(found) && found.length > 0) {
                                const raw = String(found[0].rawValue || '').trim();
                                if (raw) {
                                    this.barcodeDetectActive = false;
                                    this.vibrate(30);
                                    POSAudio.play('success');
                                    this.closeBarcodeScanner();
                                    await this.handleScannedBarcode(raw);
                                    return;
                                }
                            }
                        }
                    } catch (_) {}
                    requestAnimationFrame(() => this.barcodeScanLoop());
                },

                stopBarcodeScanner() {
                    this.barcodeDetectActive = false;
                    this.barcodeDetector = null;
                    try {
                        if (this.barcodeStream) {
                            this.barcodeStream.getTracks().forEach(t => t.stop());
                        }
                    } catch (_) {}
                    this.barcodeStream = null;
                    try {
                        const video = this.$refs.barcodeVideo;
                        if (video) video.srcObject = null;
                    } catch (_) {}
                },

                closeBarcodeScanner() {
                    this.stopBarcodeScanner();
                    this.showBarcodeScanner = false;
                },

                async handleScannedBarcode(code) {
                    this.searchQuery = code;
                    const ok = await this.lookupBarcodeProduct(code, { autoAdd: true, silentNotFound: false });
                    if (!ok) await this.searchProducts();
                },
                
                // ===== PREVIEW DE IMAGENES DE PRODUCTO (LONG PRESS) =====
                handleProductTap(producto) {
                    if (this.suppressNextProductTap) {
                        this.suppressNextProductTap = false;
                        return;
                    }
                    this.addToCart(producto);
                },

                startImageSearch(producto, event) {
                    this.cancelImageSearch();
                    this.longPressTimer = setTimeout(() => {
                        if (event?.cancelable) event.preventDefault();
                        this.vibrate(25);
                        this.suppressNextProductTap = true;
                        this.openProductCameraCapture(producto);
                    }, 480);
                },
                
                cancelImageSearch() {
                    if (this.longPressTimer) {
                        clearTimeout(this.longPressTimer);
                        this.longPressTimer = null;
                    }
                },
                
                openProductCameraCapture(producto) {
                    if (!producto) return;
                    const base = this.normalizeProductImageFields(producto);
                    const list = Array.from(new Set([
                        ...(Array.isArray(base.imagenes) ? base.imagenes : []),
                        base.imagen_small || '',
                        base.imagen || '',
                        base.imagen_medium || '',
                        base.imagen_large || '',
                        base.imagen_thumb || ''
                    ].map((u) => String(u || '').trim()).filter(Boolean)))
                        .map((u) => u.startsWith('/_lib') ? '/public' + u : u);

                    if (!list.length) {
                        this.toast('Este producto no tiene imágenes', 'warning');
                        return;
                    }

                    this.closeProductImageModal();
                    this.productImageModal = {
                        product: base,
                        images: list,
                        index: 0,
                        autoplayId: null
                    };
                    this.showProductImageModal = true;
                    this.startProductImageAutoplay();
                },

                nextProductImage() {
                    const total = Array.isArray(this.productImageModal.images) ? this.productImageModal.images.length : 0;
                    if (total <= 1) return;
                    this.productImageModal.index = (this.productImageModal.index + 1) % total;
                },

                prevProductImage() {
                    const total = Array.isArray(this.productImageModal.images) ? this.productImageModal.images.length : 0;
                    if (total <= 1) return;
                    this.productImageModal.index = (this.productImageModal.index - 1 + total) % total;
                },

                setProductImageIndex(i) {
                    const total = Array.isArray(this.productImageModal.images) ? this.productImageModal.images.length : 0;
                    if (!total) return;
                    const idx = Number(i);
                    if (!Number.isFinite(idx) || idx < 0 || idx >= total) return;
                    this.productImageModal.index = idx;
                },

                startProductImageAutoplay() {
                    this.stopProductImageAutoplay();
                    const total = Array.isArray(this.productImageModal.images) ? this.productImageModal.images.length : 0;
                    if (total <= 1) return;
                    this.productImageModal.autoplayId = setInterval(() => {
                        this.nextProductImage();
                    }, 2400);
                },

                stopProductImageAutoplay() {
                    if (this.productImageModal.autoplayId) {
                        clearInterval(this.productImageModal.autoplayId);
                        this.productImageModal.autoplayId = null;
                    }
                },

                closeProductImageModal() {
                    this.stopProductImageAutoplay();
                    this.showProductImageModal = false;
                    this.productImageModal = {
                        product: null,
                        images: [],
                        index: 0,
                        autoplayId: null
                    };
                },

                applyImagePatchToProduct(productId, patch) {
                    const pid = Number(productId || 0);
                    if (!pid) return;
                    const basePatch = this.normalizeProductImageFields(patch || {});
                    this.popularProducts = this.popularProducts.map((p) => Number(p.id) === pid ? ({ ...p, ...basePatch }) : p);
                    this.productos = this.productos.map((p) => Number(p.id) === pid ? ({ ...p, ...basePatch }) : p);
                    this.cart = this.cart.map((c) => Number(c.id) === pid ? ({ ...c, ...basePatch }) : c);
                },

                async suppressProductImage(producto) {
                    void producto;
                    this.toast('Supresión de imágenes desde POS móvil deshabilitada', 'warning');
                    return;
                },
                async onProductPhotoCaptured(event) {
                    void event;
                    this.toast('Carga de fotos desde POS móvil deshabilitada', 'warning');
                    return;
                },
                
                // Cart
                addToCart(producto, options = {}) {
                    const source = String(options?.source || 'manual');
                    const silentToast = options?.silentToast === true;

                    if (source === 'barcode') {
                        this.vibrate(25);
                        POSAudio.play('click');
                    } else {
                        this.vibrate(10);
                        POSAudio.play('success');
                    }
                    
                    const existing = this.cart.find(item => item.id === producto.id);
                    if (existing) {
                        existing.cantidad++;
                    } else {
                        this.cart.push({
                            id: producto.id,
                            codigo: producto.codigo,
                            descripcion: producto.descripcion,
                            precio: parseFloat(producto.precio),
                            precio_min: parseFloat(producto.precio_min || 0),
                            edita_precio: producto.edita_precio == 1,
                            cantidad: 1,
                            tasa_iva: producto.tasa_iva || 10,
                            imagen: producto.imagen || null,
                            imagenes: Array.isArray(producto.imagenes) ? producto.imagenes : (producto.imagen ? [producto.imagen] : [])
                        });
                    }
                    
                    this.saveCart();
                    
                    // Limpiar búsqueda
                    this.searchQuery = '';
                    this.productos = [];
                },
                
                // ===== LONG PRESS Y NUMPAD MODAL =====
                startLongPress(type, index, item) {
                    this.numpadLongPressTimer = setTimeout(() => {
                        this.vibrate(30);
                        this.openNumpadModal(type, index, item);
                    }, 400); // 400ms para long press
                },
                
                cancelLongPress() {
                    if (this.numpadLongPressTimer) {
                        clearTimeout(this.numpadLongPressTimer);
                        this.numpadLongPressTimer = null;
                    }
                },
                
                openNumpadModal(type, index, item) {
                    const labels = {
                        'cantidad': 'Cantidad',
                        'precio': 'Precio Unitario',
                        'importe': 'Importe Total'
                    };
                    
                    let initialValue = '';
                    if (type === 'cantidad') {
                        initialValue = String(item.cantidad);
                    } else if (type === 'precio') {
                        initialValue = String(Math.round(item.precio));
                    } else if (type === 'importe') {
                        initialValue = String(Math.round(item.precio * item.cantidad));
                    }
                    
                    this.numpadModal = {
                        show: true,
                        type: type,
                        index: index,
                        item: item,
                        display: this.formatNumpadDisplay(initialValue),
                        rawValue: initialValue,
                        replaceOnInput: true,
                        label: labels[type],
                        productName: item.descripcion,
                        minPrice: type === 'precio' ? (item.precio_min || 0) : 0
                    };
                },
                
                closeNumpadModal() {
                    this.numpadModal.show = false;
                },
                
                numpadInput(key) {
                    this.vibrate(5);
                    let raw = String(this.numpadModal.rawValue || '');
                    const replace = this.numpadModal.replaceOnInput === true;

                    if (key === '⌫') {
                        if (replace) {
                            raw = '';
                            this.numpadModal.replaceOnInput = false;
                        } else {
                            raw = raw.slice(0, -1);
                        }
                    } else if (key === '.') {
                        if (replace) {
                            raw = '0.';
                        } else if (!raw.includes('.')) {
                            raw = raw === '' ? '0.' : raw + '.';
                        }
                        this.numpadModal.replaceOnInput = false;
                    } else {
                        if (replace) {
                            raw = key;
                        } else {
                            raw = raw === '0' ? key : raw + key;
                        }
                        this.numpadModal.replaceOnInput = false;
                    }

                    this.numpadModal.rawValue = raw;
                    this.numpadModal.display = this.formatNumpadDisplay(raw);
                },
                
                async confirmNumpadValue() {
                    const value = parseFloat(this.numpadModal.rawValue || '0') || 0;
                    const index = this.numpadModal.index;
                    const type = this.numpadModal.type;
                    const item = this.cart[index];
                    
                    if (!item) {
                        this.closeNumpadModal();
                        return;
                    }
                    
                    if (type === 'cantidad') {
                        if (value < 0.01) {
                            this.toast('La cantidad debe ser mayor a 0', 'warning');
                            return;
                        }
                        item.cantidad = value;
                    } else if (type === 'precio') {
                        // Validar precio mínimo (precio de compra)
                        const minPrice = item.precio_min || 0;
                        if (minPrice > 0 && value < minPrice) {
                            this.toast(`El precio no puede ser menor a ${this.formatMoney(minPrice)} Gs`, 'warning');
                            const auth = await this.requestPriceOverride(item, value, minPrice, 'NUMPAD_MOBILE');
                            if (auth?.approved) {
                                item.precio = value;
                            } else {
                                return;
                            }
                        }
                        if (value < 0) {
                            this.toast('El precio no puede ser negativo', 'warning');
                            return;
                        }
                        item.precio = value;
                    } else if (type === 'importe') {
                        // Calcular cantidad desde importe total usando el precio unitario actual
                        if (value < 0) {
                            this.toast('El importe no puede ser negativo', 'warning');
                            return;
                        }
                        if ((item.precio || 0) <= 0) {
                            this.toast('El precio debe ser mayor a 0 para calcular cantidad', 'warning');
                            return;
                        }
                        const newQty = value / item.precio;
                        if (newQty < 0.01) {
                            this.toast('La cantidad resultante debe ser mayor a 0', 'warning');
                            return;
                        }
                        item.cantidad = parseFloat(newQty.toFixed(3));
                    }
                    
                    this.vibrate(10);
                    POSAudio.play('success');
                    this.saveCart();
                    this.closeNumpadModal();
                    this.toast('Actualizado', 'success');
                },

                async requestPriceOverride(item, precioIntentado, precioMinimo, origen = 'POS_MOBILE') {
                    try {
                        const response = await fetch('api/venta.php?action=request_price_override', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                id_empresa: this.idEmpresa,
                                id_usuario: this.idUsuario,
                                id_caja: this.idCaja,
                                id_producto: Number(item?.id || 0),
                                descripcion: String(item?.descripcion || ''),
                                precio_intentado: Number(precioIntentado || 0),
                                precio_minimo: Number(precioMinimo || 0),
                                origen: origen
                            })
                        });
                        const data = await response.json();
                        if (data?.success) {
                            this.toast(data?.message || 'Solicitud enviada a administrador', 'warning');
                            return data;
                        }
                    } catch (e) {
                        // no bloquear edición por fallo de red en solicitud de autorización
                    }
                    return null;
                },

                async ensureCartPriceAuthorizations(origen = 'CHECKOUT_MOBILE') {
                    for (const item of (this.cart || [])) {
                        const minPrice = parseFloat(item?.precio_min || 0);
                        const currentPrice = parseFloat(item?.precio || 0);
                        if (minPrice > 0 && currentPrice < minPrice) {
                            this.toast(`El precio no puede ser menor a ${this.formatMoney(minPrice)} Gs`, 'warning');
                            const auth = await this.requestPriceOverride(item, currentPrice, minPrice, origen);
                            if (auth?.approved) {
                                this.toast(`Precio autorizado: ${this.formatMoney(currentPrice)} Gs`, 'success');
                                continue;
                            }
                            return false;
                        }
                    }
                    return true;
                },

                formatNumpadDisplay(raw) {
                    const val = String(raw || '');
                    if (val === '') return '';

                    const parts = val.split('.');
                    const intPart = (parts[0] || '0').replace(/\D/g, '');
                    const intFmt = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

                    if (parts.length > 1) {
                        const decPart = parts[1].replace(/\D/g, '');
                        return `${intFmt}.${decPart}`;
                    }
                    return intFmt;
                },
                
                updateQty(index, delta) {
                    this.vibrate(5);
                    const item = this.cart[index];
                    const newQty = item.cantidad + delta;
                    if (newQty < 1) return;
                    item.cantidad = newQty;
                    this.saveCart();
                },
                
                updateQtyManual(index, value) {
                    const newQty = parseFloat(value);
                    if (isNaN(newQty) || newQty < 1) {
                        this.cart[index].cantidad = 1;
                    } else {
                        this.cart[index].cantidad = newQty;
                    }
                    this.saveCart();
                },
                
                async removeFromCart(index) {
                    this.vibrate(20);
                    POSAudio.play('warning');
                    const item = this.cart[index];
                    if (!item) return;
                    const auth = await this.requestItemDeleteApproval(item, 'DELETE_MOBILE');
                    if (auth?.approved) {
                        this.cart.splice(index, 1);
                        this.saveCart();
                        this.toast('Ítem eliminado (autorizado)', 'success');
                    }
                },

                async requestItemDeleteApproval(item, origen = 'POS_MOBILE') {
                    try {
                        const response = await fetch('api/venta.php?action=request_item_delete', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                id_empresa: this.idEmpresa,
                                id_usuario: this.idUsuario,
                                id_caja: this.idCaja,
                                id_producto: Number(item?.id || 0),
                                descripcion: String(item?.descripcion || ''),
                                cantidad: Number(item?.cantidad || 0),
                                precio: Number(item?.precio || 0),
                                origen: origen
                            })
                        });
                        const data = await response.json();
                        if (data?.success) {
                            this.toast(data?.message || 'Solicitud enviada a administrador', 'warning');
                            return data;
                        } else {
                            this.toast(data?.message || data?.error || 'No se pudo enviar solicitud', 'error');
                        }
                    } catch (e) {
                        this.toast('Error de conexión al solicitar autorización', 'error');
                    }
                    return null;
                },
                
                async confirmClearCart() {
                    if (confirm('¿Vaciar el carrito?')) {
                        this.vibrate(30);
                        this.cart = [];
                        this.saveCart();
                        this.toast('Carrito vaciado', 'warning');
                    }
                },
                
                saveCart() {
                    localStorage.setItem('pos_mobile_cart_' + this.idEmpresa, JSON.stringify({
                        cart: this.cart,
                        cliente: this.selectedCliente
                    }));
                },
                
                loadSavedCart() {
                    try {
                        const saved = localStorage.getItem('pos_mobile_cart_' + this.idEmpresa);
                        if (saved) {
                            const data = JSON.parse(saved);
                            this.cart = data.cart || [];
                            this.selectedCliente = data.cliente || null;
                            if (this.selectedCliente) {
                                this.clienteSearch = this.selectedCliente.nombre;
                            }
                        }
                    } catch (e) {}
                },
                
                // Clients
                async searchClientes() {
                    if (this.clienteSearch.length < 2) {
                        this.clientesResults = [];
                        return;
                    }
                    
                    try {
                        const res = await fetch(`api/clientes.php?action=search&q=${encodeURIComponent(this.clienteSearch)}&id_empresa=${this.idEmpresa}`);
                        const data = await res.json();
                        this.clientesResults = data.clientes || [];
                    } catch (e) {
                        console.error('Error searching clients:', e);
                    }
                },
                toggleVoiceSearch(target = 'products') {
                    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                    if (!SpeechRecognition) {
                        alert('Tu navegador no soporta búsqueda por voz');
                        return;
                    }
                    const isCliente = target === 'clientes';
                    const listeningProp = isCliente ? 'voiceListeningCliente' : 'voiceListening';
                    const recProp = isCliente ? '_voiceRecognitionCliente' : '_voiceRecognition';

                    if (this[listeningProp]) {
                        this[recProp]?.abort();
                        this[listeningProp] = false;
                        return;
                    }

                    const recognition = new SpeechRecognition();
                    recognition.lang = 'es-PY';
                    recognition.interimResults = false;
                    recognition.maxAlternatives = 1;
                    recognition.continuous = false;
                    this[recProp] = recognition;
                    this[listeningProp] = true;

                    recognition.onresult = (event) => {
                        const transcript = event.results[0][0].transcript.trim();
                        if (transcript) {
                            if (isCliente) {
                                this.clienteSearch = transcript;
                                this.searchClientes();
                            } else {
                                this.searchQuery = transcript;
                                this.searchProducts();
                            }
                        }
                    };
                    recognition.onerror = () => {};
                    recognition.onend = () => {
                        this[listeningProp] = false;
                        this[recProp] = null;
                    };
                    recognition.start();
                },
                
                selectCliente(cliente) {
                    this.vibrate(10);
                    this.selectedCliente = cliente;
                    this.clienteSearch = cliente.nombre;
                    this.showClienteDropdown = false;
                    this.clientesResults = [];
                    this.saveCart();
                    this.toast('Cliente seleccionado', 'success');
                },
                
                // Payment
                openPayment() {
                    if (this.cart.length === 0) {
                        this.toast('El carrito está vacío', 'error');
                        return;
                    }
                    this.vibrate(10);
                    this.paymentMethod = '';
                    this.voucherNumber = '';
                    this.transferReference = '';
                    this.pixReference = '';
                    this.creditInstallments = 1;
                    this.showPaymentModal = true;
                    this.cashReceived = this.total;

                    // Vendedor: forzar nota de control + pendiente
                    if (this.userConfig.isVendedor) {
                        this.selectedDocType = 'comun';
                        this.$nextTick(() => this.selectPaymentMethod('pendiente'));
                    } else {
                        // Auto-seleccionar defaults guardados
                        this.selectedDocType = this.defaultDocType;
                        const defPM = this.defaultPaymentMethod;
                        if (defPM) {
                            this.$nextTick(() => this.selectPaymentMethod(defPM));
                        }
                    }
                },
                
                startLPFix(type, id) {
                    this._lpFired = false;
                    this._lpTimer = setTimeout(() => {
                        this._lpFired = true;
                        this.setDefault(type, id);
                        if (navigator.vibrate) navigator.vibrate(50);
                    }, 600);
                },
                endLPFix() {
                    clearTimeout(this._lpTimer);
                    this._lpTimer = null;
                },
                wasLPFix() {
                    if (this._lpFired) { this._lpFired = false; return true; }
                    return false;
                },
                setDefault(type, id) {
                    if (type === 'doc') {
                        this.defaultDocType = id;
                        localStorage.setItem('pos_default_doc', id);
                        this.toast('Tipo documento "' + id + '" fijado', 'success');
                    } else {
                        this.defaultPaymentMethod = id;
                        localStorage.setItem('pos_default_pm', id);
                        this.toast('Cobro "' + id + '" fijado', 'success');
                    }
                    POSAudio.play('success');
                },

                selectPaymentMethod(method) {
                    this.vibrate(5);
                    POSAudio.play('click');
                    this.paymentMethod = method;
                    if (method === 'efectivo') {
                        this.cashReceived = this.total;
                        this.$nextTick(() => {
                            const el = this.$refs.cashReceivedInput;
                            if (el) {
                                el.focus();
                                if (typeof el.select === 'function') el.select();
                            }
                        });
                    } else if (method === 'tarjeta') {
                        this.voucherNumber = '';
                        this.$nextTick(() => {
                            const el = this.$refs.voucherInput;
                            if (el) el.focus();
                        });
                    } else if (method === 'transferencia') {
                        this.transferReference = '';
                    } else if (method === 'pix') {
                        this.pixReference = '';
                    } else if (method === 'credito') {
                        this.creditInstallments = 1;
                    } else if (method === 'pendiente') {
                        this.pendienteNotes = '';
                    }
                },
                
                async confirmSale() {
                    if (this.processingSale) return;
                    const pricesOk = await this.ensureCartPriceAuthorizations('CHECKOUT_MOBILE');
                    if (!pricesOk) return;
                    if (!this.paymentMethod) {
                        this.toast('Debe seleccionar una forma de pago', 'error');
                        return;
                    }
                    if (this.paymentMethod === 'efectivo' && Number(this.cashReceived || 0) < Number(this.total || 0)) {
                        this.toast('El monto recibido es menor al total', 'error');
                        return;
                    }
                    if (this.paymentMethod === 'tarjeta' && !String(this.voucherNumber || '').trim()) {
                        this.toast('Debe capturar la tarjeta o ingresar referencia', 'error');
                        return;
                    }
                    if (this.paymentMethod === 'transferencia' && !String(this.transferReference || '').trim()) {
                        this.toast('Debe ingresar REF de transferencia', 'error');
                        return;
                    }
                    if (this.paymentMethod === 'pix' && !String(this.pixReference || '').trim()) {
                        this.toast('Debe ingresar referencia PIX', 'error');
                        return;
                    }
                    if (this.paymentMethod === 'credito' && Number(this.creditInstallments || 0) < 1) {
                        this.toast('Debe indicar la cantidad de cuotas', 'error');
                        return;
                    }
                    
                    this.processingSale = true;
                    this.saleProcessModal.show = true;
                    this.saleProcessModal.message = 'Guardando comprobante...';
                    this.saleProcessModal.detail = '';
                    this.saleProcessModal.saving = 'loading';
                    this.saleProcessModal.printing = 'idle';
                    this.vibrate(20);
                    
                    try {
                        const paymentObj = {
                            method: this.paymentMethod,
                            amount: this.total,
                            cash_received: this.paymentMethod === 'efectivo' ? this.cashReceived : null,
                            cash_change: this.paymentMethod === 'efectivo' ? Math.max(0, this.cashReceived - this.total) : 0,
                            voucher_number: this.paymentMethod === 'tarjeta' ? String(this.voucherNumber || '').trim() : null,
                            transfer_reference: this.paymentMethod === 'transferencia' ? String(this.transferReference || '').trim() : null,
                            qr_transaction_code: this.paymentMethod === 'pix' ? String(this.pixReference || '').trim() : null,
                            credit_installments: this.paymentMethod === 'credito' ? Math.max(parseInt(this.creditInstallments, 10) || 1, 1) : 1,
                            credit_due_date: '',
                            credit_notes: '',
                            card_terminal_reference: this.paymentMethod === 'tarjeta' ? String(this.voucherNumber || '').trim() : '',
                            card_auth_code: '',
                            card_nsu: '',
                            card_acquirer: '',
                            card_brand: '',
                            card_masked_pan: '',
                            card_rrn: '',
                            card_batch: '',
                            card_installments: 1,
                            card_financing_type: 'credito',
                            card_processor: 'bancard',
                            pendiente_notes: this.paymentMethod === 'pendiente' ? (this.pendienteNotes || '') : '',
                            is_pending: this.paymentMethod === 'pendiente'
                        };

                        const payload = {
                            id_empresa: this.idEmpresa,
                            id_caja: this.idCaja,
                            id_usuario: this.idUsuario,
                            items: this.cart.map(item => ({
                                id: item.id,
                                id_producto: item.id,
                                codigo: item.codigo || '',
                                descripcion: item.descripcion || '',
                                cantidad: item.cantidad,
                                precio: item.precio,
                                tasa_iva: item.tasa_iva
                            })),
                            id_cliente: this.selectedCliente?.id || null,
                            forma_pago: this.paymentMethod,
                            doc_type: this.selectedDocType,
                            tipo_documento: this.selectedDocType,
                            payment_method: this.paymentMethod,
                            cash_received: paymentObj.cash_received || 0,
                            cash_change: paymentObj.cash_change || 0,
                            voucher_number: paymentObj.voucher_number || '',
                            transfer_reference: paymentObj.transfer_reference || '',
                            qr_transaction_code: paymentObj.qr_transaction_code || '',
                            credit_installments: paymentObj.credit_installments || 1,
                            credit_due_date: paymentObj.credit_due_date || '',
                            credit_notes: paymentObj.credit_notes || '',
                            card_terminal_reference: paymentObj.card_terminal_reference || '',
                            card_auth_code: paymentObj.card_auth_code || '',
                            card_nsu: paymentObj.card_nsu || '',
                            card_acquirer: paymentObj.card_acquirer || '',
                            card_brand: paymentObj.card_brand || '',
                            card_masked_pan: paymentObj.card_masked_pan || '',
                            card_rrn: paymentObj.card_rrn || '',
                            card_batch: paymentObj.card_batch || '',
                            card_installments: paymentObj.card_installments || 1,
                            card_financing_type: paymentObj.card_financing_type || 'credito',
                            card_processor: paymentObj.card_processor || 'bancard',
                            payments: [paymentObj]
                        };
                        const { queued, payload: submittedPayload, result } = await this.submitVentaPayload(payload);
                        
                        if (result.success) {
                            POSAudio.play('success');
                            this.vibrate(50);
                            this.saleProcessModal.saving = 'done';
                            this.saleProcessModal.message = queued
                                ? 'Venta guardada offline.'
                                : 'Venta guardada. Preparando impresión...';
                            this.lastVentaCliente = this.selectedCliente ? { ...this.selectedCliente } : null;
                            this.lastVenta = queued ? this.buildOfflineVentaPreview(submittedPayload) : result.data;
                            if (this.lastVenta && !this.lastVenta.tipo_documento) {
                                this.lastVenta.tipo_documento = this.selectedDocType === 'electro' ? 3 : (this.selectedDocType === 'auto' ? 1 : 0);
                            }
                            if (this.lastVenta) {
                                this.lastVenta.payment_method = this.paymentMethod;
                            }
                            // Para pendiente: forzar monto = total (no requiere pago real)
                            if (this.paymentMethod === 'pendiente') {
                                this.cashReceived = this.total;
                            }
                            this.autoPrintDone = false;
                            this.showPaymentModal = false;
                            this.cart = [];
                            this.selectedCliente = null;
                            this.clienteSearch = '';
                            localStorage.removeItem('pos_mobile_cart_' + this.idEmpresa);

                            if (queued) {
                                this.autoPrintDone = false;
                                this.saleProcessModal.printing = 'done';
                                this.saleProcessModal.message = 'Venta guardada offline.';
                                this.saleProcessModal.detail = 'Se sincronizará automáticamente cuando vuelva internet.';
                                await new Promise(resolve => setTimeout(resolve, 350));
                                this.saleProcessModal.show = false;
                                this.showSuccessModal = true;
                            } else {
                                // Auto-imprimir según tipo de documento (FE o nota común)
                                this.saleProcessModal.printing = 'loading';
                                this.saleProcessModal.message = 'Imprimiendo comprobante...';
                                const printed = await this.autoPrint40col({ silent: true });
                                this.saleProcessModal.printing = printed ? 'done' : 'error';
                                this.saleProcessModal.message = printed
                                    ? 'Proceso completado'
                                    : 'Venta guardada. Impresión pendiente';
                                if (printed) {
                                    await new Promise(resolve => setTimeout(resolve, 400));
                                    this.saleProcessModal.show = false;
                                    this.showSuccessModal = true;
                                } else {
                                    const detail = String(this.lastPrintError || '').trim();
                                    this.saleProcessModal.detail = detail
                                        ? `No se pudo imprimir automáticamente.\n${detail}`
                                        : 'No se pudo imprimir automáticamente. Tocá Entendido para reintentar desde la pantalla de éxito.';
                                }
                            }
                        } else {
                            POSAudio.play('error');
                            this.saleProcessModal.show = false;
                            this.toast(result.message || 'Error al procesar venta', 'error');
                            this.showAlert('Error al procesar venta', result.message || 'No se pudo guardar la venta.', '❌');
                        }
                    } catch (e) {
                        POSAudio.play('error');
                        this.saleProcessModal.show = false;
                        const msg = e?.message || 'Error de conexión';
                        this.toast(msg, 'error');
                        this.showAlert('Error de conexión', msg, '❌');
                        console.error('Sale error:', e);
                    }
                    
                    this.processingSale = false;
                },
                
                printTicket() {
                    if (this.lastVenta?.id_factura) {
                        this.openWebTicketForVenta(this.lastVenta);
                    } else if (this.lastVenta?.queued_offline) {
                        this.toast('Esta venta sigue offline. La impresión estará disponible al sincronizar.', 'warning');
                    }
                },
                
                // Imprimir directamente - abre el PDF y activa diálogo de impresión
                async printTicketDirect() {
                    if (!this.lastVenta?.id_factura) {
                        this.toast(this.lastVenta?.queued_offline ? 'La venta está pendiente de sincronización.' : 'No hay venta para imprimir', 'error');
                        return;
                    }
                    
                    this.printingTicket = true;
                    this.vibrate(10);
                    
                    try {
                        const url = this.getWebTicketUrlByVenta(this.lastVenta);
                        
                        // Abrir en nueva ventana con opción de imprimir
                        const printWindow = window.open(url, '_blank');
                        
                        // Intentar activar el diálogo de impresión cuando cargue
                        if (printWindow) {
                            printWindow.onload = function() {
                                setTimeout(() => {
                                    printWindow.print();
                                }, 500);
                            };
                        }
                        
                        this.toast('Ticket abierto - usa el menú para imprimir', 'success');
                        POSAudio.play('success');
                        
                    } catch (error) {
                        console.error('Error abriendo ticket:', error);
                        this.toast('Error: ' + error.message, 'error');
                        this.showAlert('Error de impresión', error.message || 'No se pudo abrir el ticket.', '❌');
                    }
                    
                    this.printingTicket = false;
                },
                
                async sendToMobilePrinterApp(base64Data, options = {}) {
                    this.lastPrintError = '';
                    let printerApp = localStorage.getItem('pos_printer_app') || 'sistemaxagent';
                    if (printerApp === 'pdf') {
                        printerApp = 'sistemaxagent';
                        localStorage.setItem('pos_printer_app', printerApp);
                        this.printerApp = printerApp;
                    }

                    if (this.isAndroidNativePrintBridge()) {
                        const printerName = await this.resolveTargetPrinterName();
                        await this.smxPrinter.printRaw(printerName, base64Data);
                        return true;
                    }

                    const normalized = (printerApp === 'rawbt') ? 'sistemaxagent' : printerApp;
                    if (normalized !== 'sistemaxagent') {
                        this.lastPrintError = 'La configuración actual de impresión no es compatible con el flujo móvil.';
                        return false;
                    }

                    const payload = encodeURIComponent(base64Data);
                    const deepLink = `sistemaxagent://print?data=${payload}`;
                    const interactive = !!options.interactive;

                    if (interactive) {
                        try {
                            window.location.href = deepLink;
                            return true;
                        } catch (_) {
                            // continue with hidden launch
                        }
                    }

                    const launchByIframe = (url) => {
                        try {
                            const fr = document.createElement('iframe');
                            fr.style.display = 'none';
                            fr.setAttribute('aria-hidden', 'true');
                            fr.src = url;
                            document.body.appendChild(fr);
                            setTimeout(() => {
                                try { fr.remove(); } catch (_) {}
                            }, 1200);
                        } catch (_) {}
                    };
                    const didOpen = await new Promise((resolve) => {
                        let settled = false;
                        const finish = (ok) => {
                            if (settled) return;
                            settled = true;
                            document.removeEventListener('visibilitychange', onVis, true);
                            resolve(ok);
                        };
                        const onVis = () => {
                            if (document.visibilityState === 'hidden' || document.hidden) {
                                finish(true);
                            }
                        };

                        document.addEventListener('visibilitychange', onVis, true);
                        // Solo deep-link por iframe oculto (evita mostrar intent://... en pantalla).
                        launchByIframe(deepLink);
                        setTimeout(() => {
                            if (!settled) launchByIframe(deepLink);
                        }, 350);

                        setTimeout(() => finish(false), 1800);
                    });

                    if (!didOpen) {
                        this.lastPrintError = this.getNativePrintUnavailableMessage();
                    }
                    return didOpen;
                },

                // Imprimir Ticket - Sistemax Agent Android o PDF
                async printSteward() {
                    if (!this.lastVenta?.id_factura) {
                        this.toast('No hay venta para imprimir', 'error');
                        return;
                    }
                    
                    const printerApp = localStorage.getItem('pos_printer_app') || 'sistemaxagent';
                    
                    // Si es PDF, abrir el ticket
                    if (printerApp === 'pdf') {
                        this.printTicketDirect();
                        return;
                    }
                    
                    // Sistemax Agent Android - Impresión directa térmica
                    this.printingSteward = true;
                    this.vibrate(10);
                    
                    try {
                        const data = await this.fetchEscposForVenta(this.lastVenta, 32);
                        
                        if (!data.success) {
                            throw new Error(data.message || 'Error generando ticket');
                        }
                        
                        const sent = await this.sendToMobilePrinterApp(data.data, { interactive: true });
                        if (!sent) throw new Error('No se pudo enviar a la impresora. Revisá permisos Bluetooth y el emparejamiento en Android.');
                        
                        this.toast('Enviando a impresora...', 'success');
                        POSAudio.play('success');
                        
                    } catch (error) {
                        console.error('Error:', error);
                        this.toast('Error: ' + error.message, 'error');
                        this.showAlert('Error de impresión', error.message || 'No se pudo enviar a la impresora.', '❌');
                        this.printTicketDirect();
                        POSAudio.play('error');
                    }
                    
                    setTimeout(() => {
                        this.printingSteward = false;
                    }, 1000);
                },
                
                // Auto-impresión 40col según tipo de documento
                async autoPrint40col(options = {}) {
                    const silent = !!options.silent;
                    if (!this.lastVenta?.id_factura) return false;
                    this.lastPrintError = '';
                    const tipo = this.getEscposTipoByVenta(this.lastVenta);
                    console.log('🖨️ Auto-print:', tipo, 'para factura:', this.lastVenta.id_factura);
                    
                    try {
                        const data = await this.fetchEscposForVenta(this.lastVenta, 40);
                        
                        if (!data.success) {
                            throw new Error(data.message || 'Error generando impresión');
                        }
                        
                        const sent = await this.sendToMobilePrinterApp(data.data, { interactive: true });
                        if (!sent) throw new Error('No se pudo enviar a la impresora. Revisá permisos Bluetooth y el emparejamiento en Android.');
                        
                        this.autoPrintDone = true;
                        console.log('✅ Enviado a Sistemax Agent:', tipo, '40col');
                        return true;
                        
                    } catch (error) {
                        console.error('❌ Error auto-print:', error);
                        this.lastPrintError = String(error?.message || '').trim() || 'Error desconocido de impresión';
                        if (!silent) {
                            this.toast(`No se pudo imprimir automáticamente: ${this.lastPrintError}`, 'warning');
                        }
                        return false;
                    }
                },
                
                // Imprimir Nota Común 40 columnas
                async printNota40() {
                    if (!this.lastVenta?.id_factura) {
                        this.toast('No hay venta para imprimir', 'error');
                        return;
                    }
                    
                    const printerApp = localStorage.getItem('pos_printer_app') || 'sistemaxagent';
                    
                    // Si es PDF, abrir el PDF de nota
                    if (printerApp === 'pdf') {
                        this.openWebTicketForVenta(this.lastVenta);
                        return;
                    }
                    
                    // Sistemax Agent Android - Impresión directa térmica
                    this.printingNota40 = true;
                    this.vibrate(10);
                    
                    try {
                        const data = await this.fetchEscposForVenta(this.lastVenta, 40, 'nota');
                        
                        if (!data.success) {
                            throw new Error(data.message || 'Error generando nota');
                        }
                        
                        const sent = await this.sendToMobilePrinterApp(data.data);
                        if (!sent) throw new Error('No se pudo enviar a la impresora. Revisá permisos Bluetooth y el emparejamiento en Android.');
                        
                        this.toast('📝 Enviando nota...', 'success');
                        POSAudio.play('success');
                        
                    } catch (error) {
                        console.error('Error:', error);
                        this.toast('Error: ' + error.message, 'error');
                        this.showAlert('Error de impresión', error.message || 'No se pudo enviar la nota.', '❌');
                        this.printTicketDirect();
                        POSAudio.play('error');
                    }
                    
                    setTimeout(() => {
                        this.printingNota40 = false;
                    }, 1000);
                },
                
                // Imprimir Factura Legal 40 columnas
                async printFactura40() {
                    if (!this.lastVenta?.id_factura) {
                        this.toast('No hay venta para imprimir', 'error');
                        return;
                    }
                    
                    const printerApp = localStorage.getItem('pos_printer_app') || 'sistemaxagent';
                    
                    // Si es PDF, abrir el PDF de factura
                    if (printerApp === 'pdf') {
                        this.openWebTicketForVenta(this.lastVenta);
                        return;
                    }
                    
                    // Sistemax Agent Android - Impresión directa térmica
                    this.printingFactura40 = true;
                    this.vibrate(10);
                    
                    try {
                        const data = await this.fetchEscposForVenta(this.lastVenta, 40, 'factura');
                        
                        if (!data.success) {
                            throw new Error(data.message || 'Error generando factura');
                        }
                        
                        const sent = await this.sendToMobilePrinterApp(data.data, { interactive: true });
                        if (!sent) throw new Error('No se pudo enviar a la impresora. Revisá permisos Bluetooth y el emparejamiento en Android.');
                        
                        this.toast('📄 Enviando factura...', 'success');
                        POSAudio.play('success');
                        
                    } catch (error) {
                        console.error('Error:', error);
                        this.toast('Error: ' + error.message, 'error');
                        this.showAlert('Error de impresión', error.message || 'No se pudo enviar la factura.', '❌');
                        this.printTicketDirect();
                        POSAudio.play('error');
                    }
                    
                    setTimeout(() => {
                        this.printingFactura40 = false;
                    }, 1000);
                },
                
                // Cambiar app de impresión
                selectPrinterApp(app) {
                    localStorage.setItem('pos_printer_app', app);
                    this.printerApp = app;
                    this.toast(`App de impresión: ${app.toUpperCase()}`, 'info');
                },
                
                shareWhatsApp() {
                    if (!this.lastVenta) return;

                    const venta = this.lastVenta || {};
                    const receptor = this.lastVentaCliente || {};
                    const phone = String(receptor.telefono || venta.cliente_telefono || '').trim();
                    const docNum = venta.nro_factura || venta.id_factura || '';
                    const tipoDoc = this.getVentaDocTitle(venta);
                    const fechaRaw = String(venta.fecha || new Date().toISOString());
                    const fechaFmt = this.formatDateTimeForWhatsApp(fechaRaw);
                    const rucRec = String(receptor.numero || receptor.ruc || receptor.documento || venta.cliente_ruc || '').trim();
                    const telRec = String(receptor.telefono || venta.cliente_telefono || '').trim();
                    const nombreRec = String(receptor.nombre || venta.cliente || 'CONSUMIDOR FINAL').trim();
                    const pago = String(venta.payment_method || this.paymentMethod || '').trim().toUpperCase();
                    const cantItems = Array.isArray(this.cart) && this.cart.length > 0
                        ? this.cart.length
                        : '';
                    const msg = [
                        '*COMPROBANTE DE VENTA*',
                        '',
                        '*Emisor:* ' + (this.emisorNombre || 'SistemaX'),
                        '*RUC Emisor:* ' + (this.emisorRuc || 'N/D'),
                        '*Fecha/Hora:* ' + fechaFmt,
                        '',
                        '*Receptor:* ' + (nombreRec || 'CONSUMIDOR FINAL'),
                        '*RUC/CI Receptor:* ' + (rucRec || 'N/D'),
                        '*Teléfono:* ' + (telRec || 'N/D'),
                        '',
                        '*Tipo:* ' + tipoDoc,
                        '*Nro:* ' + docNum,
                        '*Total:* ' + this.formatMoney(venta.total) + ' Gs',
                        '*Forma de Pago:* ' + (pago || 'N/D'),
                        cantItems ? ('*Items:* ' + cantItems) : ''
                    ].filter(Boolean).join('\n');

                    if (phone) {
                        window.open(`https://wa.me/${phone.replace(/\D/g, '')}?text=${encodeURIComponent(msg)}`, '_blank');
                    } else {
                        window.open(`https://wa.me/?text=${encodeURIComponent(msg)}`, '_blank');
                    }
                },

                formatDateTimeForWhatsApp(raw) {
                    try {
                        const d = new Date(raw);
                        if (isNaN(d.getTime())) return String(raw || '');
                        return d.toLocaleString('es-PY', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                    } catch (_) {
                        return String(raw || '');
                    }
                },
                
                newSale() {
                    this.showSuccessModal = false;
                    this.saleProcessModal.show = false;
                    this.saleProcessModal.message = 'Guardando comprobante...';
                    this.saleProcessModal.detail = '';
                    this.saleProcessModal.saving = 'idle';
                    this.saleProcessModal.printing = 'idle';
                    this.lastVenta = null;
                    this.lastVentaCliente = null;
                    this.autoPrintDone = false;
                    this.activeTab = 'products';
                    this.$refs.searchInput?.focus();
                },
                
                // Sales History
                async loadVentasHoy() {
                    this.loadingSales = true;
                    try {
                        const q = encodeURIComponent(this.salesSearchQuery || '');
                        const estado = encodeURIComponent(this.salesStatusFilter || 'activos');
                        const from = encodeURIComponent(this.salesDateFrom || this.getTodayIso());
                        const to = encodeURIComponent(this.salesDateTo || this.getTodayIso());
                        const res = await fetch(`api/venta_edit.php?action=search&q=${q}&id_empresa=${this.idEmpresa}&id_usuario=${this.idUsuario}&estado_filter=${estado}&fecha_desde=${from}&fecha_hasta=${to}&limit=200`);
                        const data = await res.json();
                        if (data.success) {
                            this.ventasHoy = (data.ventas || []).slice(0, 200);
                        }
                    } catch (e) {
                        console.error('Error loading sales:', e);
                    }
                    this.loadingSales = false;
                },

                async reprintVentaFromList(venta) {
                    if (!venta?.id_factura) {
                        this.toast('Venta no válida para reimpresión', 'error');
                        return;
                    }
                    this.lastVenta = {
                        ...venta,
                        id_factura: venta.id_factura,
                        tipo_documento: parseInt(venta.tipo_documento || 0),
                        cdc: venta.cdc || null
                    };
                    await this.printSteward();
                },

                async anularLocalVenta(venta) {
                    if (!venta?.id_factura) return;
                    if (!confirm('¿Está seguro de anular esta venta?')) return;
                    try {
                        const response = await fetch('/public/ventas/api/anular.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id_factura: venta.id_factura, id_empresa: this.idEmpresa })
                        });
                        const data = await response.json();
                        if (data.success) {
                            this.toast(data.message || 'Venta anulada', 'success');
                            this.loadVentasHoy();
                        } else {
                            this.toast(data.message || 'Error al anular', 'error');
                        }
                    } catch (error) {
                        this.toast('Error de conexión', 'error');
                    }
                },

                async anularEnSifenVenta(venta) {
                    if (!confirm('¿Confirmar anulación en SIFEN?')) return;
                    await this.anularLocalVenta(venta);
                },

                async consultarSifenVenta(venta) {
                    try {
                        const body = new URLSearchParams({
                            id_factura: String(venta.id_factura),
                            id_empresa: String(this.idEmpresa),
                            consult_only: '1'
                        });
                        const response = await fetch('/public/pos/api/sifen_retry.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: body.toString()
                        });
                        const data = await response.json();
                        this.toast(data.message || data.error || 'Consulta SIFEN ejecutada', data.success ? 'success' : 'info');
                        this.loadVentasHoy();
                    } catch (error) {
                        this.toast('Error consultando SIFEN', 'error');
                    }
                },

                async reenviarSifenVenta(venta) {
                    if (!confirm('¿Reenviar esta factura a SIFEN?')) return;
                    try {
                        const body = new URLSearchParams({
                            id_factura: String(venta.id_factura),
                            id_empresa: String(this.idEmpresa)
                        });
                        const response = await fetch('/public/pos/api/sifen_retry.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: body.toString()
                        });
                        const data = await response.json();
                        this.toast(data.message || data.error || 'Reenvío ejecutado', data.success ? 'success' : 'info');
                        this.loadVentasHoy();
                    } catch (error) {
                        this.toast('Error reenviando a SIFEN', 'error');
                    }
                },

                editVentaFromList(venta) {
                    if (!venta?.id_factura) return;
                    window.location.href = `/public/pos/index.php?edit=${venta.id_factura}`;
                },
                
                showVentaDetail(venta) {
                    this.vibrate(10);
                    this.openWebTicketForVenta(venta);
                },

                isVentaElectronica(venta) {
                    if (!venta) return false;
                    const tipo = parseInt(venta.tipo_documento || 0);
                    const hasCdc = String(venta.cdc || '').trim().length > 10;
                    return tipo === 3 || hasCdc;
                },

                isDocAutoimpresaVenta(venta) {
                    return parseInt(venta?.tipo_documento || 0) === 1;
                },

                isDocNotaControlVenta(venta) {
                    const t = parseInt(venta?.tipo_documento || 0);
                    return t !== 1 && !this.isVentaElectronica(venta);
                },

                getSifenStatusKey(estado) {
                    const val = String(estado || '').trim().toLowerCase();
                    if (val === 'aprobado') return 'aprobado';
                    if (val === 'rechazado') return 'rechazado';
                    return 'pendiente';
                },

                getEscposTipoByVenta(venta) {
                    if (this.isVentaElectronica(venta)) return 'factura';
                    const tipo = parseInt(venta?.tipo_documento || 0);
                    return tipo === 1 ? 'factura' : 'nota';
                },

                getWebTicketUrlByVenta(venta) {
                    const isFe = this.isVentaElectronica(venta);
                    const script = isFe ? 'kude.php' : 'ticket.php';
                    const idFactura = venta?.id_factura || this.lastVenta?.id_factura;
                    const nro = encodeURIComponent(String(venta?.nro_factura || this.lastVenta?.nro_factura || '').trim());
                    return `/public/pos/${script}?id=${idFactura}&id_empresa=${this.idEmpresa}&width=48&nro=${nro}&logo=0`;
                },

                openWebTicketForVenta(venta) {
                    const url = this.getWebTicketUrlByVenta(venta || this.lastVenta || {});
                    window.open(url, '_blank');
                },

                getEscposEndpointByVenta(venta, forceTipo = '') {
                    const force = String(forceTipo || '').toLowerCase();
                    // Pago pendiente: siempre usar comprobante simplificado
                    if (force === 'pendiente' || (venta?.payment_method === 'pendiente' && force === '')) {
                        return '/public/pos/ticket_comprobante_pendiente.php';
                    }
                    if (force === 'nota') return '/public/pos/ticket_nota_comun.php';
                    if (force === 'factura') {
                        return this.isVentaElectronica(venta)
                            ? '/public/pos/ticket_factura_electronica.php'
                            : '/public/pos/ticket_factura_autoimpresa.php';
                    }
                    if (this.isVentaElectronica(venta)) return '/public/pos/ticket_factura_electronica.php';
                    const tipo = parseInt(venta?.tipo_documento || 0);
                    return tipo === 1 ? '/public/pos/ticket_factura_autoimpresa.php' : '/public/pos/ticket_nota_comun.php';
                },

                getEscposQrMode() {
                    const forced = String(localStorage.getItem('pos_qr_mode') || '').trim().toLowerCase();
                    if (['text', 'native', 'raster'].includes(forced)) return forced;
                    return 'raster';
                },

                async fetchEscposForVenta(venta, width = 40, forceTipo = '') {
                    const endpoint = this.getEscposEndpointByVenta(venta, forceTipo);
                    const idFactura = venta?.id_factura || this.lastVenta?.id_factura;
                    const nro = encodeURIComponent(String(venta?.nro_factura || this.lastVenta?.nro_factura || '').trim());
                    const qrMode = this.getEscposQrMode();
                    const response = await fetch(`${endpoint}?id=${idFactura}&id_empresa=${this.idEmpresa}&width=${width}&nro=${nro}&qr_mode=${encodeURIComponent(qrMode)}&safe_end=1`);
                    const data = await response.json();
                    if (!data.success) {
                        throw new Error(data.message || 'Error generando ticket');
                    }
                    return data;
                },

                getVentaDocTitle(venta) {
                    if (venta?.queued_offline) return 'Venta guardada offline';
                    if (this.isVentaElectronica(venta)) return 'Factura Electrónica enviada';
                    return parseInt(venta?.tipo_documento || 0) === 1 ? 'Factura Autoimpresa generada' : 'Nota de control generada';
                },

                getVentaDocNumberLabel(venta) {
                    if (venta?.queued_offline) return 'Referencia local';
                    if (this.isVentaElectronica(venta) || parseInt(venta?.tipo_documento || 0) === 1) return 'Nro. Factura';
                    return 'Nro. Nota';
                },
                
                // Settings
                toggleDarkMode() {
                    this.vibrate(10);
                    this.isDarkMode = true;
                    localStorage.setItem('theme', 'dark');
                    document.documentElement.classList.add('dark');
                },
                
                toggleSound() {
                    this.vibrate(10);
                    this.soundMuted = !this.soundMuted;
                    POSAudio.muted = this.soundMuted;
                    localStorage.setItem('pos_sound_muted', this.soundMuted.toString());
                    if (!this.soundMuted) POSAudio.play('success');
                },

                async clearPosCache() {
                    this.vibrate(10);
                    try {
                        if ('caches' in window) {
                            const keys = await caches.keys();
                            await Promise.all(keys.map((k) => caches.delete(k)));
                        }
                        if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {
                            const regs = await navigator.serviceWorker.getRegistrations();
                            await Promise.all(regs.map((r) => r.unregister()));
                        }
                        try {
                            localStorage.removeItem(this.popularCacheKey());
                            localStorage.removeItem('pos_mobile_cart_' + this.idEmpresa);
                            localStorage.removeItem('pos_usb_printer_connected');
                        } catch (_) {}
                        this.showAlert('Caché limpiado', 'Se recargará la app POS móvil.', '🧹');
                        setTimeout(() => {
                            const u = new URL(window.location.href);
                            u.searchParams.set('v', String(Date.now()));
                            window.location.replace(u.toString());
                        }, 450);
                    } catch (e) {
                        console.error('Error limpiando caché POS:', e);
                        this.showAlert('Error', 'No se pudo limpiar caché', '⚠️');
                    }
                },
                
                salir() {
                    // Guardar carrito antes de salir
                    this.saveCart();
                    // Intentar cerrar usando la función del padre (iframe del menú)
                    try {
                        if (typeof parent.cerrarApp === 'function') {
                            parent.cerrarApp();
                            return;
                        }
                        if (typeof parent.cerrarAppMobile === 'function') {
                            parent.cerrarAppMobile();
                            return;
                        }
                    } catch (e) {}
                    // Fallback: navegar al menú directamente
                    window.location.href = '../menu/menu.php';
                },
                
                showAlert(title, message, icon = '⚠️') {
                    this.alertTitle = title || 'Atención';
                    this.alertMessage = message || '';
                    this.alertIcon = icon || '⚠️';
                    this.showAlertModal = true;
                },

                closeAlert() {
                    this.showAlertModal = false;
                },

                // Toast
                toast(message, type = 'info', durationMs = 3000) {
                    const id = ++this.toastId;
                    this.toasts.push({ id, message, type, show: true });
                    const timeout = durationMs ?? 3000;
                    setTimeout(() => {
                        this.removeToastById(id);
                    }, timeout);
                },

                removeToastById(id) {
                    const idx = this.toasts.findIndex(t => t.id === id);
                    if (idx > -1) {
                        this.toasts[idx].show = false;
                        setTimeout(() => {
                            this.toasts = this.toasts.filter(t => t.id !== id);
                        }, 300);
                    }
                },

                async copyToastMessage(message) {
                    const txt = String(message || '').trim();
                    if (!txt) return;
                    try {
                        await navigator.clipboard.writeText(txt);
                        this.toast('Mensaje copiado', 'success', 1800);
                    } catch (e) {
                        this.toast('No se pudo copiar automáticamente', 'warning', 2800);
                    }
                }
            };
        }
    </script>
</body>
</html>
                openGeneralProductSearch() {
                    const query = String(this.searchQuery || '').trim();
                    const params = new URLSearchParams({
                        desktop: '1',
                        no_redirect: '1'
                    });
                    if (query) {
                        params.set('search', query);
                    }
                    const targetUrl = `/public/productos/search_dark.php?${params.toString()}`;
                    this.showKeyboard = false;
                    const popup = window.open(targetUrl, '_blank', 'noopener');
                    if (!popup) {
                        window.location.href = targetUrl;
                    }
                },
