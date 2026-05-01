<?php

/**
 * POS Desktop - Sistema de Punto de Venta
 * Tailwind CSS + Alpine.js + PHP
 * Adaptado a arquitectura v1
 */

// Detección de móvil y redirección automática (opcional)
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
$forceDesktop = isset($_GET['desktop']) || isset($_COOKIE['pos_desktop']);

// Si es móvil y no forzó desktop, redirigir a versión móvil
if ($isMobile && !$forceDesktop && !isset($_GET['no_redirect'])) {
    $vMobile = @filemtime(__DIR__ . '/mobile.php') ?: time();
    header('Location: /public/pos/mobile.php?v=' . $vMobile);
    exit;
}

// Si forzó desktop, guardar cookie
if (isset($_GET['desktop'])) {
    setcookie('pos_desktop', '1', time() + 86400 * 30, '/');
}

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$id_login = Session::getIdLogin();
$id_empresa = Session::getIdEmpresa();
$id_sucursal = (int)(Session::get('id_sucursal', 0));
$usuario = Session::get('usuario', 'Usuario');
$rol_usuario = '';
$permisosProductos = Permission::getAppPermissions('app_grid_mercaderias');

// Valores por defecto
$id_caja = (int)(Session::get('id_caja_def', 0));
$nombreEmpresa = 'SistemaX';
$isFacturaElectronica = 1;
$logoEmpresaUrl = '/public/_lib/file/img/empresa/logo_sistemax.png'; // Fallback por defecto
$initialSearchPreview = null;
$posContext = array_merge([
    'mode' => 'venta',
    'page_title' => 'POS Desktop Dropdown',
    'search_placeholder' => 'Buscar mercadería por descripción, código o código de barra',
    'cart_items_label' => 'Items en factura',
    'counterpart_label' => 'Cliente',
    'counterpart_empty_name' => 'Consumidor Final',
    'counterpart_empty_doc' => '4444440-1',
    'success_title' => '¡Venta Exitosa!',
    'success_subtitle' => 'Factura generada correctamente',
    'checkout_title' => 'Finalizar Venta',
    'confirm_button_text' => 'CONFIRMAR E IMPRIMIR',
    'storage_key' => 'pos_tickets',
    'mode_badge' => 'VENTA',
    'header_highlight' => '',
    'hide_caja' => false,
    'show_back_button' => false,
    'back_path' => '/public/menu/menu.php',
], $GLOBALS['SMX_POS_CONTEXT'] ?? []);

try {
    $pdo_init = Database::getMasterConnection();

    // 1. Obtener datos extendidos del usuario desde sec_users (Fuente de Verdad)
    $stmt_user = $pdo_init->prepare("SELECT login, role, caja_def, id_empresa, cobro_df, ancho_papel, forma_pago_def FROM sec_users WHERE id_login = :id");
    $stmt_user->execute([':id' => $id_login]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    $cobro_df = 1; // Default
    $ancho_papel = 400; // Default
    $forma_pago_def = 1; // Default

    if ($user_data) {
        $usuario = $user_data['login'];
        $rol_usuario = trim((string)($user_data['role'] ?? ''));
        $id_caja = (int)$user_data['caja_def'];
        // Respetar empresa activa de sesión; sec_users solo como fallback.
        if ((int)$id_empresa <= 0) {
            $id_empresa = (int)$user_data['id_empresa'];
        }
        $cobro_df = (int)$user_data['cobro_df'];
        $ancho_papel = (int)$user_data['ancho_papel'];
        $forma_pago_def = (int)$user_data['forma_pago_def'];
    }

    // 2. Obtener datos de la empresa
    $stmt_init = $pdo_init->prepare("SELECT empresa, fe, logos FROM empresa WHERE id_empresa = :id");
    $stmt_init->execute([':id' => $id_empresa]);
    $emp_data = $stmt_init->fetch(PDO::FETCH_ASSOC);
    $logoEmpresa = '';
    if ($emp_data) {
        $nombreEmpresa = $emp_data['empresa'];
        $isFacturaElectronica = (int)$emp_data['fe'];
        $logoEmpresa = $emp_data['logos'] ?? '';
    }
    
    // Construir URL del logo - usar ruta absoluta desde raíz web
    $rutaImagenEmpresas = '/public/_lib/file/img/empresa/';
    if (!empty($logoEmpresa) && (str_starts_with($logoEmpresa, 'http') || str_starts_with($logoEmpresa, '/'))) {
        $logoEmpresaUrl = $logoEmpresa;
    } elseif (!empty($logoEmpresa)) {
        $logoEmpresaUrl = $rutaImagenEmpresas . ltrim($logoEmpresa, '/');
    } else {
        $logoEmpresaUrl = $rutaImagenEmpresas . 'logo_sistemax.png'; // Fallback
    }

    // 3. Obtener base de datos de la empresa para tipos de precio
    $stmt_db = $pdo_init->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmt_db->execute([':id' => $id_empresa]);
    $dbEmpresa = $stmt_db->fetchColumn();

    // 3.1 Crear tabla de FE si no existe
    if ($dbEmpresa) {
        try {
            $createFeTableSql = "CREATE TABLE IF NOT EXISTS `$dbEmpresa`.`fe` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_factura INT NOT NULL,
                numero_timbrado VARCHAR(20) NOT NULL,
                establecimiento VARCHAR(10) NOT NULL,
                punto_expedicion VARCHAR(10) NOT NULL,
                numero_documento VARCHAR(20) NOT NULL,
                fecha_inicio_timbrado DATE NOT NULL,
                fecha_fin_timbrado DATE NOT NULL,
                ruc_emisor VARCHAR(20) NOT NULL,
                dv_emisor VARCHAR(5) NOT NULL,
                razon_social_emisor VARCHAR(255) NOT NULL,
                direccion_emisor VARCHAR(255) NOT NULL,
                numero_casa_emisor VARCHAR(10),
                complemento_direccion1 VARCHAR(255),
                complemento_direccion2 VARCHAR(255),
                telefono_emisor VARCHAR(50),
                email_emisor VARCHAR(255),
                denominacion_sucursal VARCHAR(255),
                codigo_actividad_economica VARCHAR(50),
                naturaleza_receptor VARCHAR(50),
                tipo_operacion VARCHAR(50),
                pais_receptor VARCHAR(50),
                cliente VARCHAR(255) NOT NULL,
                ruc_cliente VARCHAR(20) NOT NULL,
                direccion_cliente VARCHAR(255) NOT NULL,
                numero_casa_cliente VARCHAR(10),
                complemento_direccion1_cliente VARCHAR(255),
                complemento_direccion2_cliente VARCHAR(255),
                telefono_cliente VARCHAR(50),
                email_cliente VARCHAR(255),
                nro_factura VARCHAR(20),
                fecha_emision DATETIME,
                fecha_limite_envio DATETIME,
                xml_generado TEXT,
                xml_firmado TEXT,
                estado_transmision VARCHAR(30),
                mensaje_error TEXT,
                fecha_transmision DATETIME,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                iva DECIMAL(12,2) NOT NULL DEFAULT 0,
                intentos_transmision INT NOT NULL DEFAULT 0,
                fecha_limite_transmision DATE,
                estado_electronico VARCHAR(50),
                mensaje_set TEXT,
                acuse_sifen TEXT,
                KEY idx_fe_id_factura (id_factura)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            $pdo_init->exec($createFeTableSql);

            // Intentar agregar FK si aplica
            try {
                $pdo_init->exec("ALTER TABLE `$dbEmpresa`.`fe`
                    ADD CONSTRAINT fk_fe_factura_ventas FOREIGN KEY (id_factura)
                    REFERENCES `$dbEmpresa`.`factura_ventas`(id_factura)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT");
            } catch (Exception $e) {
                // Si falla la FK, dejar la tabla creada sin bloquear
                error_log("POS FE FK error: " . $e->getMessage());
            }
        } catch (Exception $e) {
            // Evitar bloquear el POS si no se puede crear la tabla
            error_log("POS FE table create error: " . $e->getMessage());
        }
    }

    // 4. Cargar tipos de precio
    $tiposPrecio = [];
    $rutaImagenMercaderias = isset($_SESSION['ruta_imagen_mercaderias']) ? $_SESSION['ruta_imagen_mercaderias'] : '';

    // Si no está en sesión, intentar obtener de la base de datos
    if (empty($rutaImagenMercaderias) && $dbEmpresa) {
        try {
            $stmt_ruta = $pdo_init->query("SELECT ruta_imagen_mercaderias FROM $dbEmpresa.control_empresa LIMIT 1");
            $ruta_result = $stmt_ruta->fetch(PDO::FETCH_ASSOC);
            if ($ruta_result && !empty($ruta_result['ruta_imagen_mercaderias'])) {
                $rutaImagenMercaderias = $ruta_result['ruta_imagen_mercaderias'];
                $_SESSION['ruta_imagen_mercaderias'] = $rutaImagenMercaderias; // Guardar en sesión
            }
        } catch (Exception $e) {
            // Mantener vacío si no existe
        }
    }

    if ($dbEmpresa) {
        $stmt_precios = $pdo_init->query("SELECT id, tipo FROM $dbEmpresa.tipo_precio ORDER BY id");
        $tiposPrecio = $stmt_precios->fetchAll(PDO::FETCH_ASSOC);

        try {
            $stmt_preview = $pdo_init->prepare("
                SELECT
                    p.idproducto AS id,
                    p.cve_producto AS codigo,
                    p.desproducto AS descripcion,
                    p.referencia,
                    COALESCE(mp.precio, p.precio_venta) AS precio,
                    COALESCE(p.saldo, 0) AS stock,
                    p.iva AS tasa_iva,
                    p.controla_stock,
                    COALESCE(p.vende_sin_stock, 0) AS vende_sin_stock,
                    p.edita_precio,
                    p.editable,
                    COALESCE(p.usaserial, 0) AS usaserial,
                    COALESCE(g.grupo, '') AS categoria
                FROM {$dbEmpresa}.tblproductos p
                LEFT JOIN {$dbEmpresa}.mercaderia_grupo g ON g.id = p.grupo
                LEFT JOIN (
                    SELECT codigo, precio
                    FROM {$dbEmpresa}.mercaderia_precio
                    WHERE tipo = :tipo_precio
                    GROUP BY codigo
                ) mp ON mp.codigo = p.idproducto
                WHERE p.Estado = 1
                ORDER BY p.idproducto DESC
                LIMIT 1
            ");
            $stmt_preview->execute([':tipo_precio' => $forma_pago_def > 0 ? $forma_pago_def : 1]);
            $previewRow = $stmt_preview->fetch(PDO::FETCH_ASSOC);
            if ($previewRow) {
                $initialSearchPreview = [
                    'success' => true,
                    'scope' => 'placeholder',
                    'heavy_loaded' => false,
                    'producto' => [
                        'idproducto' => (int)($previewRow['id'] ?? 0),
                        'codigo' => (string)($previewRow['codigo'] ?? ''),
                        'descripcion' => (string)($previewRow['descripcion'] ?? 'Producto'),
                        'Estado' => 1,
                        'descontinuado' => 0,
                        'referencia' => (string)($previewRow['referencia'] ?? ''),
                        'costo' => (float)($previewRow['precio'] ?? 0),
                        'precio_venta' => (float)($previewRow['precio'] ?? 0),
                        'stock_global' => (float)($previewRow['stock'] ?? 0),
                        'controla_stock' => (int)($previewRow['controla_stock'] ?? 1),
                        'vende_sin_stock' => (int)($previewRow['vende_sin_stock'] ?? 0),
                        'edita_precio' => (int)($previewRow['edita_precio'] ?? 0),
                        'editable' => (int)($previewRow['editable'] ?? 0),
                        'usaserial' => (int)($previewRow['usaserial'] ?? 0),
                        'tasa_iva' => (int)($previewRow['tasa_iva'] ?? 10),
                        'stock_minimo' => 0,
                        'stock_maximo' => 0,
                        'categoria' => (string)($previewRow['categoria'] ?? ''),
                        'marca_nombre' => '',
                        'modelo_nombre' => '',
                        'imagen_url' => '',
                        'imagen' => '',
                        'imagen_updated' => '',
                        'imagenes' => [],
                        'detalle_cargado' => 1
                    ],
                    'codigos_barra' => [],
                    'precios' => ((float)($previewRow['precio'] ?? 0) > 0) ? [[
                        'tipo' => 1,
                        'nombre_tipo' => 'Precio actual',
                        'precio' => (float)($previewRow['precio'] ?? 0),
                    ]] : [],
                    'stock_por_sucursal' => [],
                    'equivalentes' => [],
                    'clientes_compraron' => [],
                    'ventas_registradas' => 0,
                ];
            }
        } catch (Exception $e) {
            error_log('POS initial preview bootstrap error: ' . $e->getMessage());
        }
    }

    // 5. Obtener impresora configurada de la caja
    $impresora_caja = '';
    $nombre_sucursal = '';
    $id_sucursal_caja = 0;
    $metodos_cobro_ids = ['efectivo', 'tarjeta', 'transferencia', 'pix', 'credito'];
    if ($id_caja > 0 && $dbEmpresa) {
        try {
            $hasMetodoCobroPermitido = false;
            $hasCajaSucursal = false;
            try {
                $stmt_col = $pdo_init->query("SHOW COLUMNS FROM $dbEmpresa.cajas LIKE 'metodo_cobro_permitido'");
                $hasMetodoCobroPermitido = (bool)$stmt_col->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $eCol) {
                $hasMetodoCobroPermitido = false;
            }
            try {
                $stmt_col_suc = $pdo_init->query("SHOW COLUMNS FROM $dbEmpresa.cajas LIKE 'id_sucursal'");
                $hasCajaSucursal = (bool)$stmt_col_suc->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $eCol) {
                $hasCajaSucursal = false;
            }

            $selectCols = ['impresora'];
            if ($hasMetodoCobroPermitido) {
                $selectCols[] = 'metodo_cobro_permitido';
            }
            if ($hasCajaSucursal) {
                $selectCols[] = 'id_sucursal';
            }
            $selectColsSql = implode(', ', $selectCols);
            $stmt_imp = $pdo_init->prepare("SELECT $selectColsSql FROM $dbEmpresa.cajas WHERE id_caja = :id AND id_empresa = :emp");
            $stmt_imp->execute([':id' => $id_caja, ':emp' => $id_empresa]);
            $caja_cfg = $stmt_imp->fetch(PDO::FETCH_ASSOC) ?: [];
            if (empty(trim((string)($caja_cfg['impresora'] ?? '')))) {
                $stmt_imp2 = $pdo_init->prepare("SELECT $selectColsSql FROM $dbEmpresa.cajas WHERE id_caja = :id LIMIT 1");
                $stmt_imp2->execute([':id' => $id_caja]);
                $caja_cfg2 = $stmt_imp2->fetch(PDO::FETCH_ASSOC) ?: [];
                if (!empty($caja_cfg2)) {
                    $caja_cfg = $caja_cfg2;
                }
            }
            $impresora_caja = $caja_cfg['impresora'] ?? '';
            $id_sucursal_caja = (int)($caja_cfg['id_sucursal'] ?? 0);

            $rawMetodos = strtoupper(trim((string)($caja_cfg['metodo_cobro_permitido'] ?? '')));
            if ($rawMetodos !== '') {
                $mapMetodos = [
                    'EFECTIVO' => 'efectivo',
                    'TARJETA' => 'tarjeta',
                    'TRANSFERENCIA' => 'transferencia',
                    'TRANSFER' => 'transferencia',
                    'QR' => 'pix',
                    'PIX' => 'pix',
                    'CREDITO' => 'credito'
                ];
                $parsed = [];
                foreach (explode(',', $rawMetodos) as $part) {
                    $k = trim($part);
                    if ($k === '' || !isset($mapMetodos[$k])) continue;
                    $v = $mapMetodos[$k];
                    if (!in_array($v, $parsed, true)) $parsed[] = $v;
                }
                if (!empty($parsed)) {
                    $metodos_cobro_ids = $parsed;
                }
            }
        } catch (Exception $e) { /* ignorar */ }
    }
    if ($id_sucursal_caja > 0) {
        $id_sucursal = $id_sucursal_caja;
    }
    if ($id_sucursal > 0 && $dbEmpresa) {
        try {
            $sucCols = $pdo_init->query("SHOW COLUMNS FROM $dbEmpresa.sucursales")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!empty($sucCols)) {
                $idSucursalCol = in_array('id_sucursal', $sucCols, true) ? 'id_sucursal' : (in_array('id', $sucCols, true) ? 'id' : '');
                $nombreSucursalCol = in_array('sucursal', $sucCols, true) ? 'sucursal' : (in_array('nombre', $sucCols, true) ? 'nombre' : '');
                if ($idSucursalCol !== '' && $nombreSucursalCol !== '') {
                    $stmt_sucursal = $pdo_init->prepare("SELECT $nombreSucursalCol FROM $dbEmpresa.sucursales WHERE $idSucursalCol = :id LIMIT 1");
                    $stmt_sucursal->execute([':id' => $id_sucursal]);
                    $nombre_sucursal = trim((string)$stmt_sucursal->fetchColumn());
                }
            }
        } catch (Exception $e) {
            $nombre_sucursal = '';
        }
    }
    if ($nombre_sucursal === '' && $id_sucursal > 0) {
        $nombre_sucursal = 'Suc. #' . $id_sucursal;
    }
} catch (Exception $e) {
    // Mantener defaults en caso de error
    $impresora_caja = $impresora_caja ?? '';
    if (empty($nombre_sucursal) && !empty($id_sucursal)) {
        $nombre_sucursal = 'Suc. #' . (int)$id_sucursal;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(SmxI18n::getLocale(), ENT_QUOTES, 'UTF-8') ?>" x-data="posApp()" x-init="init()" class="dark" style="background:#020617 !important;">
<script>
    // Detectar tema heredado del menú (ejecución temprana - antes del head)
    (function() {
        const isDark = true;
        document.documentElement.classList.add('dark');
        console.log('[POS] Tema oscuro sólido activo');
    })();
</script>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f172a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?= htmlspecialchars($posContext['page_title'], ENT_QUOTES, 'UTF-8') ?> - SistemaX</title>
    <link rel="manifest" href="/public/pos/manifest_desktop.json">
    <link rel="apple-touch-icon" href="/public/pos/logo.png">

    <!-- Sistemax Agent bridge (nativo) -->
    <script src="/public/pos/js/smx-printer.js?v=3"></script>
    <script>window.__PERMISOS_PRODUCTOS__ = <?= json_encode($permisosProductos) ?>;</script>
    <script>window.__POS_CONTEXT__ = <?= json_encode($posContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
    <script>window.__INITIAL_SEARCH_PREVIEW__ = <?= json_encode($initialSearchPreview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>

    <!-- Tailwind CSS (compilado) -->
    <link rel="stylesheet" href="../assets/tailwind.css">
    <script>
        // Solo configurar si se usa el CDN JS de Tailwind (no aplica con CSS compilado)
        if (typeof tailwind !== 'undefined') {
            tailwind.config = {
                darkMode: 'class',
                theme: {
                    extend: {
                        colors: {
                            primary: '#3b82f6',
                            success: '#22c55e',
                            danger: '#ef4444',
                            warning: '#f59e0b'
                        }
                    }
                }
            }
        }
    </script>

    <!-- Alpine.js -->
    <script defer src="/public/assets/vendor/alpine.min.js"></script>
    <script>
        window.registerPosDesktopPwa = async function registerPosDesktopPwa() {
            if (!('serviceWorker' in navigator)) return false;
            try {
                await navigator.serviceWorker.register('/public/pos/sw.js?v=1', { scope: '/public/pos/' });
                return true;
            } catch (error) {
                console.warn('No se pudo registrar SW del POS desktop:', error);
                return false;
            }
        };
    </script>

    <style>
        #bootSplash {
            position: fixed;
            inset: 0;
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #020617;
            color: #e2e8f0;
            opacity: 1;
            transition: opacity 0.18s ease-out, visibility 0.18s ease-out;
            visibility: visible;
        }

        #bootSplash.is-hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .boot-splash-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
            padding: 28px 32px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(2, 6, 23, 0.98));
            box-shadow: 0 18px 60px rgba(0, 0, 0, 0.38);
        }

        .boot-splash-logo {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            object-fit: contain;
            background: rgba(255, 255, 255, 0.04);
            padding: 8px;
        }

        .boot-splash-title {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: #67e8f9;
        }

        .boot-splash-subtitle {
            font-size: 13px;
            color: #cbd5e1;
            text-align: center;
        }

        .boot-splash-spinner {
            width: 26px;
            height: 26px;
            border-radius: 999px;
            border: 2px solid rgba(103, 232, 249, 0.22);
            border-top-color: #67e8f9;
            animation: bootSplashSpin 0.75s linear infinite;
        }

        @keyframes bootSplashSpin {
            to { transform: rotate(360deg); }
        }

        /* Video Wallpaper - Pixabay */
        #video-background {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            object-fit: cover !important;
            z-index: -2 !important;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
            pointer-events: none;
        }
        
        #video-background.loaded {
            opacity: 1;
        }
        
        /* Overlay sobre el video */
        #video-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            z-index: -1 !important;
            background: linear-gradient(135deg, 
                rgba(248, 250, 252, 0.88) 0%, 
                rgba(241, 245, 249, 0.80) 50%,
                rgba(248, 250, 252, 0.88) 100%);
            pointer-events: none;
        }
        
        html.dark #video-overlay {
            background: linear-gradient(135deg, 
                rgba(2, 6, 23, 0.88) 0%, 
                rgba(15, 23, 42, 0.80) 50%,
                rgba(2, 6, 23, 0.88) 100%);
        }

        /* === HEREDAR WALLPAPER DEL MENU === */
        html, body {
            background-size: cover !important;
            background-position: center !important;
            background-attachment: fixed !important;
            background-repeat: no-repeat !important;
        }
        .bg-gray-100, .bg-slate-900, .dark\:bg-slate-900 {
            background: transparent !important;
        }
        .pos-container {
            background: transparent !important;
        }
        header {
            background: rgba(255,255,255,0.85) !important;
            backdrop-filter: blur(20px) !important;
            -webkit-backdrop-filter: blur(20px) !important;
        }
        .dark header {
            background: rgba(30,41,59,0.85) !important;
        }
        main {
            background: rgba(255,255,255,0.7) !important;
            backdrop-filter: blur(16px) !important;
            -webkit-backdrop-filter: blur(16px) !important;
        }
        .dark main {
            background: rgba(15,23,42,0.7) !important;
        }
        /* === FIN WALLPAPER === */

        [x-cloak] {
            display: none !important;
        }

        /* ===== GRID 6 COLUMNAS FORZADO ===== */
        .grid-cols-6 {
            display: grid !important;
            grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
        }
        
        .gap-2 {
            gap: 0.5rem !important;
        }

        /* ===== PRODUCTO CARD - Ajuste dinámico para Desktop ===== */
        .producto-card {
            transition: all 0.15s ease;
            padding: 0.375rem;
        }

        .producto-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .producto-card:active {
            transform: scale(0.98);
        }
        
        /* Imágenes cuadradas adaptativas */
        .producto-card .long-press-container {
            width: 100% !important;
            aspect-ratio: 1 / 1 !important;
            height: auto !important;
        }
        
        /* Highlight en texto de búsqueda */
        mark {
            background-color: #fcd34d;
            color: #000;
            font-weight: 600;
            padding: 0 2px;
            border-radius: 2px;
        }
        
        /* Ajuste por tamaño de pantalla desktop */
        /* HD (1366x768) */
        @media (min-width: 1280px) and (max-width: 1439px) {
            .producto-card { padding: 0.25rem; }
            .producto-card h3 { font-size: 10px !important; }
            .producto-card .text-xs { font-size: 10px !important; }
            .producto-card .text-\[8px\] { font-size: 7px !important; }
        }
        
        /* Full HD (1920x1080) */
        @media (min-width: 1440px) and (max-width: 1919px) {
            .producto-card { padding: 0.375rem; }
            .producto-card h3 { font-size: 11px !important; }
            .producto-card .text-xs { font-size: 11px !important; }
        }
        
        /* QHD (2560x1440) */
        @media (min-width: 1920px) and (max-width: 2559px) {
            .producto-card { padding: 0.5rem; }
            .producto-card h3 { font-size: 12px !important; }
            .producto-card .text-xs { font-size: 12px !important; }
        }
        
        /* 4K (3840x2160) */
        @media (min-width: 2560px) {
            .producto-card { padding: 0.625rem; }
            .producto-card h3 { font-size: 14px !important; }
            .producto-card .text-xs { font-size: 14px !important; }
            .producto-card .text-\[8px\] { font-size: 10px !important; }
        }

        /* Custom scrollbar - Dark */
        .dark .custom-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .dark .custom-scroll::-webkit-scrollbar-track {
            background: #1e293b;
        }

        .dark .custom-scroll::-webkit-scrollbar-thumb {
            background: #475569;
            border-radius: 3px;
        }

        /* Custom scrollbar - Light */
        :not(.dark) .custom-scroll::-webkit-scrollbar {
            width: 6px;
        }

        :not(.dark) .custom-scroll::-webkit-scrollbar-track {
            background: #e2e8f0;
        }

        :not(.dark) .custom-scroll::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 3px;
        }

        /* Animación entrada */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes bounce-in {
            0% { transform: translateX(-50%) scale(0.8) translateY(20px); opacity: 0; }
            50% { transform: translateX(-50%) scale(1.05) translateY(-5px); }
            100% { transform: translateX(-50%) scale(1) translateY(0); opacity: 1; }
        }
        
        .animate-bounce-in {
            animation: bounce-in 0.4s ease-out forwards;
        }
        
        @keyframes slide-in {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .animate-slide-in {
            animation: slide-in 0.3s ease-out forwards;
        }

        .toast-no-glass,
        .toast-no-glass * {
            -webkit-backdrop-filter: none !important;
            backdrop-filter: none !important;
        }

        body.ios-absolute-glass .toast-no-glass {
            border: 1px solid rgba(15, 23, 42, 0.18) !important;
            box-shadow: 0 14px 26px rgba(2, 6, 23, 0.28) !important;
            background-image: none !important;
        }

        body.ios-absolute-glass .toast-no-glass.bg-green-600 {
            background: #16a34a !important;
            color: #fff !important;
        }

        body.ios-absolute-glass .toast-no-glass.bg-red-600 {
            background: #dc2626 !important;
            color: #fff !important;
        }

        body.ios-absolute-glass .toast-no-glass.bg-yellow-600 {
            background: #ca8a04 !important;
            color: #111827 !important;
        }

        body.ios-absolute-glass .toast-no-glass.bg-blue-600 {
            background: #2563eb !important;
            color: #fff !important;
        }

        .cart-item {
            animation: slideIn 0.2s ease;
        }
        
        /* Filas alternadas del carrito */
        .cart-item:nth-child(odd) {
            background-color: rgba(241, 245, 249, 0.5); /* slate-100 */
        }
        .cart-item:nth-child(even) {
            background-color: rgba(226, 232, 240, 0.3); /* slate-200 */
        }
        .dark .cart-item:nth-child(odd) {
            background-color: rgba(51, 65, 85, 0.3); /* slate-700 */
        }
        .dark .cart-item:nth-child(even) {
            background-color: rgba(30, 41, 59, 0.4); /* slate-800 */
        }

        /* Full viewport cover */
        html {
            height: 100%;
            background-color: transparent !important;
        }

        html,
        body {
            /* Styles replaced by specific body styling below */
            width: 100%;
            margin: 0px !important;
        }

        /* Modern Transparent UI */
        body {
            font-family: Poppins, sans-serif;
            display: flex;
            flex-direction: column;
            overflow: hidden !important;
            background-color: transparent !important;

            /* Card Effect */
            margin: 0px !important;
            height: calc(100% - 0px) !important;
            border-radius: 10px;

            /* Force Clipping */
            overflow: hidden !important;
            position: relative;
            /* Ensures z-index container */

            border: 0px;
        }
        
        /* Contenedor principal sobre el video */
        .pos-container {
            position: relative;
            z-index: 1;
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border-radius: 10px;
        }

        .dark body {
            background-color: transparent !important;
            /* 100% transparente */
        }

        /* Tema Claro - Transparent */
        html:not(.dark) {
            background-color: transparent !important;
        }

        html:not(.dark) body {
            background-color: transparent !important;
            /* 100% transparente */
            color: #0f172a;
        }

        .pos-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border-radius: 10px;
        }

        html:not(.dark) .pos-container {
            background-color: transparent !important;
        }

        .dark .pos-container {
            background-color: transparent !important;
        }

        /* Contenedores principales y tarjetas en blanco puro */
        html:not(.dark) .producto-card,
        html:not(.dark) .bg-white,
        html:not(.dark) header {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
        }

        /* Mapeo de negros/grises oscuros a grises claros para mantener jerarquía */
        html:not(.dark) .bg-slate-800,
        html:not(.dark) .bg-gray-900,
        html:not(.dark) .bg-slate-900 {
            background-color: #ffffff !important;
            color: #0f172a !important;
            border: 1px solid #e2e8f0;
            /* Agrega borde sutil para definir forma */
        }

        /* Inputs y elementos secundarios en gris muy claro */
        html:not(.dark) input,
        html:not(.dark) select,
        html:not(.dark) .bg-slate-100 {
            background-color: #f8fafc !important;
            /* Slate 50 */
            border-color: #cbd5e1 !important;
            /* Slate 300 */
            color: #334155 !important;
            /* Slate 700 */
        }

        /* Hover states en light mode */
        html:not(.dark) .hover\:bg-slate-100:hover {
            background-color: #e2e8f0 !important;
            /* Slate 200 */
        }

        /* Azul Universal - Enforcement */
        /* Asegura que los elementos verdes se vuelvan azules en ambos modos si quedan clases residuales */
        .text-green-400,
        .text-green-500,
        .text-green-600 {
            color: #3b82f6 !important;
        }

        /* Blue 500 */
        .bg-green-500,
        .bg-green-600 {
            background-color: #2563eb !important;
        }

        /* Blue 600 */
        .bg-green-600:hover {
            background-color: #1d4ed8 !important;
        }

        /* Dark Mode específico para textos azules para legibilidad */
        .dark .text-blue-600 {
            color: #60a5fa !important;
        }

        /* Blue 400 en dark mode */

        /* Long Press para editar imagen de producto */
        .long-press-container {
            position: relative;
            -webkit-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }
        
        .long-press-indicator {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0%;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            border-radius: 0 0 8px 8px;
            transition: width 0.05s linear;
            pointer-events: none;
            z-index: 30;
        }
        
        .long-press-container.pressing .long-press-indicator {
            animation: longPressProgress 0.6s linear forwards;
        }
        
        @keyframes longPressProgress {
            0% { width: 0%; }
            100% { width: 100%; }
        }
        
        .long-press-container::after {
            content: '🔍';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
            font-size: 24px;
            opacity: 0;
            transition: all 0.2s ease;
            pointer-events: none;
            z-index: 31;
        }
        
        .long-press-container.pressing::after {
            transform: translate(-50%, -50%) scale(1);
            opacity: 0.8;
        }

        .product-card-carousel {
            position: absolute;
            inset: 0;
            border-radius: inherit;
            overflow: hidden;
        }

        .product-card-carousel img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0;
        }

        .card-carousel-controls {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 6px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.15s ease;
            z-index: 32;
        }

        .long-press-container:hover .card-carousel-controls {
            opacity: 1;
            pointer-events: auto;
        }

        .card-carousel-btn {
            width: 26px;
            height: 26px;
            border: 0;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.6);
            color: #fff;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
        }

        @keyframes posCardCarouselFade {
            0% { opacity: 0; }
            8% { opacity: 1; }
            30% { opacity: 1; }
            38% { opacity: 0; }
            100% { opacity: 0; }
        }

        .pos-main-split {
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }

        .pos-cart-panel,
        .pos-detail-panel {
            width: 100%;
            min-height: 0;
        }

        @media (min-width: 768px) {
            .pos-main-split {
                flex-direction: row;
            }

            .pos-cart-panel {
                flex: 0 0 35%;
                max-width: 35%;
                border-right-width: 1px;
                border-bottom-width: 0 !important;
            }

            .pos-detail-panel {
                flex: 0 0 65%;
                max-width: 65%;
            }
        }

        .preview-gallery {
            display: flex;
            gap: 0;
            align-items: center;
            justify-content: center;
            min-height: 0;
            position: relative;
            padding-left: 72px;
        }

        .preview-gallery-strip {
            position: absolute;
            left: 0;
            top: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 61px;
            flex-shrink: 0;
        }

        .preview-gallery-thumb {
            width: 61px;
            height: 61px;
            border-radius: 16px;
            border: 2px solid rgba(148, 163, 184, 0.28);
            background: rgba(15, 23, 42, 0.65);
            overflow: hidden;
            cursor: pointer;
            transition: border-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
        }

        .preview-gallery-thumb:hover,
        .preview-gallery-thumb.is-active {
            border-color: rgba(96, 165, 250, 0.95);
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.24);
        }

        .preview-gallery-main {
            position: relative;
            flex: 0 0 auto;
            min-height: 0;
            border-radius: 20px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 20px 35px rgba(15, 23, 42, 0.24);
            max-width: 300px;
        }

        .preview-gallery-stage {
            position: relative;
            aspect-ratio: 1 / 1;
            overflow: hidden;
            cursor: zoom-in;
            background:
                radial-gradient(circle at top left, rgba(255,255,255,0.35), transparent 38%),
                linear-gradient(135deg, rgba(248,250,252,1), rgba(255,255,255,0.94));
        }

        .preview-gallery-lens {
            position: absolute;
            width: 44%;
            aspect-ratio: 1 / 1;
            border: 1px solid rgba(148, 163, 184, 0.45);
            background: rgba(15, 23, 42, 0.18);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.18);
            pointer-events: none;
            display: none;
        }

        .preview-gallery-stage img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transition: transform 0.12s ease-out;
            will-change: transform;
            transform-origin: center center;
        }

        .preview-gallery-edit-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 8;
            width: 38px;
            height: 38px;
            border-radius: 999px;
            border: 1px solid rgba(191, 219, 254, 0.42);
            background: rgba(15, 23, 42, 0.78);
            color: #f8fafc;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.28);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease;
        }

        .preview-gallery-edit-btn:hover {
            transform: translateY(-1px) scale(1.03);
            background: rgba(37, 99, 235, 0.88);
            border-color: rgba(147, 197, 253, 0.72);
        }

        .preview-gallery-zoom-pane {
            position: absolute;
            left: calc(100% + 20px);
            top: 0;
            width: min(42vw, 780px);
            height: min(42vw, 520px);
            display: none;
            border-radius: 24px;
            overflow: hidden;
            background: #fff;
            border: 1px solid rgba(226, 232, 240, 0.85);
            box-shadow: 0 20px 35px rgba(15, 23, 42, 0.16);
            z-index: 20;
        }

        .preview-gallery-zoom-pane.is-visible {
            display: block;
        }

        .preview-gallery-zoom-surface {
            width: 100%;
            height: 100%;
            background-repeat: no-repeat;
            background-color: #fff;
        }

        .preview-gallery-zoom-hint {
            position: absolute;
            right: 14px;
            bottom: 14px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.72);
            color: #e2e8f0;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            pointer-events: none;
        }

        @media (max-width: 767px) {
            .preview-gallery {
                flex-direction: column-reverse;
                padding-left: 0;
            }

            .preview-gallery-strip {
                position: static;
                flex-direction: row;
                width: 100%;
                overflow-x: auto;
                padding-bottom: 4px;
            }

            .preview-gallery-main {
                max-width: none;
            }

            .preview-gallery-edit-btn {
                width: 34px;
                height: 34px;
                top: 8px;
                right: 8px;
            }

            .preview-gallery-zoom-pane {
                display: none !important;
            }

            .preview-gallery-lens {
                display: none !important;
            }
        }

        .preview-ml-font {
            font-family: "Proxima Nova", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            letter-spacing: 0;
        }

        .preview-top-layout {
            display: grid;
            grid-template-columns: minmax(280px, 1fr) minmax(260px, 320px) minmax(320px, 1fr);
            gap: 24px;
            align-items: start;
        }

        .preview-side-card {
            min-height: 100%;
            border-radius: 24px;
            background: rgba(15, 23, 42, 0.58);
            border: 1px solid rgba(51, 65, 85, 0.8);
            padding: 24px 28px;
        }

        .preview-side-title {
            color: #cbd5e1;
            font-size: 16px;
            line-height: 1.25;
            font-weight: 700;
            margin-bottom: 22px;
        }

        .preview-info-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 9px 0;
            font-size: 15px;
            line-height: 1.35;
        }

        .preview-info-label {
            color: #94a3b8;
            font-weight: 400;
        }

        .preview-info-value {
            color: #60a5fa;
            font-weight: 500;
            text-align: right;
        }

        .preview-price-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 20px;
            padding: 14px 0;
        }

        .preview-price-label {
            color: #f8fafc;
            font-size: 15px;
            line-height: 1.35;
            font-weight: 700;
            text-transform: uppercase;
        }

        .preview-price-value {
            color: #60a5fa;
            font-size: 17px;
            line-height: 1.25;
            font-weight: 700;
            text-align: right;
            white-space: nowrap;
        }

        .preview-stock-card {
            margin-top: 24px;
            border-radius: 24px;
            background: rgba(15, 23, 42, 0.58);
            border: 1px solid rgba(51, 65, 85, 0.8);
            padding: 24px 28px;
        }

        .preview-stock-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 26px;
        }

        .preview-stock-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding-top: 6px;
        }

        @media (max-width: 1279px) {
            .preview-top-layout {
                grid-template-columns: 1fr;
            }
        }

        .preview-ecom-shell {
            background: linear-gradient(180deg, #0f172a 0%, #020617 100%);
            border-radius: 28px;
            padding: 28px 30px 26px;
            color: #e5e7eb;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
            border: 1px solid rgba(51, 65, 85, 0.9);
        }

        .preview-ecom-layout {
            display: grid;
            grid-template-columns: minmax(360px, 46%) minmax(0, 54%);
            gap: 28px;
            align-items: start;
        }

        .preview-ecom-text {
            font-family: "Proxima Nova", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            color: #e5e7eb;
        }

        .preview-ecom-copy {
            min-width: 0;
        }

        .preview-ecom-link {
            color: #3483fa;
            display: block;
            max-width: 100%;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.3;
            overflow-wrap: anywhere;
            word-break: break-word;
            text-wrap: pretty;
        }

        .preview-ecom-meta {
            color: #94a3b8;
            max-width: 100%;
            font-size: 13px;
            line-height: 1.35;
            font-weight: 400;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .preview-ecom-title {
            color: #f8fafc;
            max-width: 100%;
            font-size: clamp(24px, 2.2vw, 34px);
            line-height: 1.08;
            font-weight: 500;
            margin-top: 12px;
            overflow-wrap: anywhere;
            word-break: break-word;
            text-wrap: balance;
        }

        .preview-ecom-rating {
            color: #94a3b8;
            max-width: 100%;
            font-size: 16px;
            line-height: 1.3;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px 10px;
            margin-top: 10px;
        }

        .preview-ecom-stars {
            color: #3483fa;
            letter-spacing: 1px;
            font-size: 18px;
        }

        .preview-ecom-old-price {
            color: #94a3b8;
            font-size: 24px;
            line-height: 1.25;
            text-decoration: line-through;
            margin-top: 22px;
        }

        .preview-ecom-balance {
            margin-top: 2px;
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
        }

        .preview-ecom-balance-label {
            color: #93c5fd;
            font-size: 14px;
            line-height: 1;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .preview-ecom-price-value {
            color: #f8fafc;
            font-size: 54px;
            line-height: 0.95;
            font-weight: 300;
        }

        .preview-ecom-discount {
            color: #00a650;
            background: #e7f6ec;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 18px;
            line-height: 1;
            font-weight: 500;
        }

        .preview-ecom-subprice {
            color: #e5e7eb;
            max-width: 100%;
            font-size: 16px;
            line-height: 1.35;
            font-weight: 400;
            margin-top: 10px;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .preview-ecom-stock-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .preview-ecom-stock-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            max-width: 100%;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(59, 130, 246, 0.25);
            background: rgba(15, 23, 42, 0.45);
            color: #cbd5e1;
            font-size: 12px;
            line-height: 1.2;
        }

        .preview-ecom-stock-pill.is-compact {
            padding: 5px 8px;
            gap: 5px;
            font-size: 11px;
            opacity: 0.92;
        }

        .preview-ecom-stock-pill.is-current {
            border-color: rgba(34, 197, 94, 0.55);
            background: rgba(21, 128, 61, 0.26);
            color: #dcfce7;
            box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.16) inset;
            padding: 7px 12px;
            font-size: 12px;
            order: -1;
        }

        .preview-ecom-stock-pill strong {
            color: #f8fafc;
            font-weight: 600;
        }

        .preview-ecom-section-title {
            color: #f8fafc;
            font-size: 18px;
            line-height: 1.35;
            font-weight: 600;
            margin-bottom: 14px;
        }

        .preview-ecom-bullets {
            color: #e5e7eb;
            font-size: 18px;
            line-height: 1.65;
            padding-left: 24px;
        }

        .preview-ecom-actions {
            margin-top: 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
            width: 100%;
            min-width: 0;
        }

        .preview-ecom-actions .preview-ecom-link {
            width: 100%;
        }

        @media (max-width: 1600px) {
            .preview-ecom-shell {
                padding: 22px 22px 20px;
            }

            .preview-ecom-layout {
                grid-template-columns: minmax(280px, 40%) minmax(0, 60%);
                gap: 18px;
            }

            .preview-gallery {
                padding-left: 54px;
            }

            .preview-gallery-strip {
                width: 48px;
                gap: 8px;
            }

            .preview-gallery-thumb {
                width: 48px;
                height: 48px;
                border-radius: 12px;
            }

            .preview-gallery-main {
                max-width: 225px;
                border-radius: 16px;
            }

            .preview-ecom-link {
                font-size: 13px;
            }

            .preview-ecom-meta {
                font-size: 12px;
            }

            .preview-ecom-title {
                font-size: clamp(20px, 1.8vw, 28px);
                line-height: 1.06;
                margin-top: 8px;
            }

            .preview-ecom-rating {
                font-size: 14px;
                gap: 4px 8px;
                margin-top: 8px;
            }

            .preview-ecom-stars {
                font-size: 16px;
            }

            .preview-ecom-old-price {
                font-size: 18px;
                margin-top: 14px;
            }

            .preview-ecom-price {
                gap: 8px;
            }

            .preview-ecom-price-value {
                font-size: clamp(34px, 3.2vw, 44px);
                line-height: 0.96;
            }

            .preview-ecom-discount {
                font-size: 14px;
                padding: 3px 7px;
            }

            .preview-ecom-subprice {
                font-size: 14px;
                margin-top: 8px;
            }

            .preview-ecom-stock-pills {
                gap: 6px;
                margin-top: 8px;
            }

            .preview-ecom-stock-pill {
                padding: 5px 9px;
                font-size: 11px;
            }

            .preview-ecom-stock-pill.is-current {
                padding: 6px 10px;
                font-size: 11px;
            }

            .preview-ecom-section-title {
                font-size: 16px;
                margin-bottom: 10px;
            }

            .preview-ecom-bullets {
                font-size: 15px;
                line-height: 1.45;
                padding-left: 18px;
            }

            .preview-ecom-actions {
                margin-top: 14px;
                gap: 8px;
            }
        }

        @media (max-width: 1279px) {
            .preview-ecom-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Precios en la tabla */
        html:not(.dark) .text-slate-600 dark:text-slate-400 {
            color: #1e293b !important;
        }

        /* Resaltado de búsqueda */
        mark {
            background-color: rgba(234, 179, 8, 0.4);
            border-radius: 2px;
            padding: 0 2px;
            color: inherit;
        }

        .dark mark {
            color: #fff;
        }

        html:not(.dark) mark {
            color: #000;
            font-weight: bold;
        }

        /* ==========================================================
           iOS Absolute Glass Theme (global override)
           ========================================================== */
        body.ios-absolute-glass {
            --ios-glass-bg-light: rgba(255, 255, 255, 0.20);
            --ios-glass-bg-dark: rgba(12, 18, 32, 0.30);
            --ios-glass-border-light: rgba(255, 255, 255, 0.52);
            --ios-glass-border-dark: rgba(255, 255, 255, 0.24);
            --ios-glass-shadow-light: 0 18px 45px rgba(15, 23, 42, 0.24);
            --ios-glass-shadow-dark: 0 20px 50px rgba(2, 6, 23, 0.55);
            --ios-glass-blur: blur(34px) saturate(200%);
            position: relative;
            overflow: hidden;
        }

        body.ios-absolute-glass::before,
        body.ios-absolute-glass::after {
            content: '';
            position: fixed;
            width: 42vw;
            height: 42vw;
            min-width: 320px;
            min-height: 320px;
            border-radius: 999px;
            filter: blur(56px);
            pointer-events: none;
            z-index: -1;
            opacity: 0.9;
        }

        body.ios-absolute-glass::before {
            top: -14vh;
            left: -10vw;
            background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.62), rgba(147, 197, 253, 0.22) 42%, rgba(56, 189, 248, 0.12) 70%, transparent 100%);
        }

        body.ios-absolute-glass::after {
            right: -12vw;
            bottom: -20vh;
            background: radial-gradient(circle at 65% 40%, rgba(255, 255, 255, 0.56), rgba(125, 211, 252, 0.20) 40%, rgba(20, 184, 166, 0.14) 72%, transparent 100%);
        }

        html.dark body.ios-absolute-glass::before {
            background: radial-gradient(circle at 35% 35%, rgba(56, 189, 248, 0.20), rgba(59, 130, 246, 0.16) 45%, rgba(2, 6, 23, 0.12) 80%, transparent 100%);
            opacity: 0.8;
        }

        html.dark body.ios-absolute-glass::after {
            background: radial-gradient(circle at 65% 40%, rgba(45, 212, 191, 0.18), rgba(14, 165, 233, 0.14) 45%, rgba(2, 6, 23, 0.16) 80%, transparent 100%);
            opacity: 0.78;
        }

        body.ios-absolute-glass #video-overlay {
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.12) 0%,
                rgba(255, 255, 255, 0.05) 50%,
                rgba(255, 255, 255, 0.10) 100%
            ) !important;
        }

        html.dark body.ios-absolute-glass #video-overlay {
            background: linear-gradient(
                135deg,
                rgba(15, 23, 42, 0.22) 0%,
                rgba(2, 6, 23, 0.16) 50%,
                rgba(15, 23, 42, 0.20) 100%
            ) !important;
        }

        body.ios-absolute-glass .pos-container,
        body.ios-absolute-glass header,
        body.ios-absolute-glass main {
            background: transparent !important;
        }

        body.ios-absolute-glass header,
        body.ios-absolute-glass header[class*="bg-"] {
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.34) 0%,
                rgba(219, 234, 254, 0.26) 45%,
                rgba(186, 230, 253, 0.24) 100%
            ) !important;
            border-color: rgba(255, 255, 255, 0.58) !important;
            -webkit-backdrop-filter: blur(36px) saturate(205%) !important;
            backdrop-filter: blur(36px) saturate(205%) !important;
            box-shadow: 0 18px 42px rgba(30, 41, 59, 0.26) !important;
        }

        html.dark body.ios-absolute-glass header,
        html.dark body.ios-absolute-glass header[class*="bg-"] {
            background: linear-gradient(
                135deg,
                rgba(15, 23, 42, 0.40) 0%,
                rgba(30, 41, 59, 0.32) 48%,
                rgba(15, 23, 42, 0.36) 100%
            ) !important;
            border-color: rgba(191, 219, 254, 0.32) !important;
            box-shadow: 0 20px 48px rgba(2, 6, 23, 0.56) !important;
        }

        body.ios-absolute-glass .ios-desk-shell {
            border: 1px solid rgba(255, 255, 255, 0.42);
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), rgba(226, 232, 240, 0.10));
            -webkit-backdrop-filter: blur(30px) saturate(190%);
            backdrop-filter: blur(30px) saturate(190%);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.24);
            margin: 10px;
            overflow: hidden;
            position: relative;
        }

        html.dark body.ios-absolute-glass .ios-desk-shell {
            border-color: rgba(191, 219, 254, 0.24);
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.20), rgba(15, 23, 42, 0.18));
            box-shadow: 0 24px 56px rgba(2, 6, 23, 0.60);
        }

        body.ios-absolute-glass .ios-desk-pane,
        body.ios-absolute-glass .ios-desk-footer,
        body.ios-absolute-glass .ios-desk-chip {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.20), rgba(255, 255, 255, 0.12)) !important;
            border-color: rgba(255, 255, 255, 0.50) !important;
            -webkit-backdrop-filter: blur(28px) saturate(185%) !important;
            backdrop-filter: blur(28px) saturate(185%) !important;
            box-shadow: 0 14px 36px rgba(15, 23, 42, 0.18) !important;
            position: relative;
        }

        html.dark body.ios-absolute-glass .ios-desk-pane,
        html.dark body.ios-absolute-glass .ios-desk-footer,
        html.dark body.ios-absolute-glass .ios-desk-chip {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.26), rgba(15, 23, 42, 0.22)) !important;
            border-color: rgba(191, 219, 254, 0.30) !important;
            box-shadow: 0 16px 40px rgba(2, 6, 23, 0.48) !important;
        }

        body.ios-absolute-glass .producto-card,
        body.ios-absolute-glass .cart-item {
            border-color: rgba(255, 255, 255, 0.46) !important;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.14);
        }

        html.dark body.ios-absolute-glass .producto-card,
        html.dark body.ios-absolute-glass .cart-item {
            border-color: rgba(191, 219, 254, 0.28) !important;
            box-shadow: 0 10px 24px rgba(2, 6, 23, 0.40);
        }

        body.ios-absolute-glass [class*="bg-white"],
        body.ios-absolute-glass [class*="bg-slate-"],
        body.ios-absolute-glass [class*="bg-gray-"],
        body.ios-absolute-glass [class*="dark:bg-slate-"],
        body.ios-absolute-glass [class*="dark:bg-gray-"] {
            background: var(--ios-glass-bg-light) !important;
            border-color: var(--ios-glass-border-light) !important;
            -webkit-backdrop-filter: var(--ios-glass-blur) !important;
            backdrop-filter: var(--ios-glass-blur) !important;
            box-shadow: var(--ios-glass-shadow-light) !important;
        }

        html.dark body.ios-absolute-glass [class*="bg-white"],
        html.dark body.ios-absolute-glass [class*="bg-slate-"],
        html.dark body.ios-absolute-glass [class*="bg-gray-"],
        html.dark body.ios-absolute-glass [class*="dark:bg-slate-"],
        html.dark body.ios-absolute-glass [class*="dark:bg-gray-"] {
            background: var(--ios-glass-bg-dark) !important;
            border-color: var(--ios-glass-border-dark) !important;
            box-shadow: var(--ios-glass-shadow-dark) !important;
        }

        body.ios-absolute-glass input,
        body.ios-absolute-glass select,
        body.ios-absolute-glass textarea,
        body.ios-absolute-glass button {
            -webkit-backdrop-filter: var(--ios-glass-blur) !important;
            backdrop-filter: var(--ios-glass-blur) !important;
        }

        /* Ajuste específico: input de cliente en POS desktop */
        @media (min-width: 1024px) {
            .pos-cliente-input {
                padding-left: 0.9rem !important;
            }
        }

        body.ios-absolute-glass [class*="bg-blue-"] {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.34), rgba(37, 99, 235, 0.24)) !important;
            border-color: rgba(147, 197, 253, 0.50) !important;
            color: #ffffff !important;
        }

        body.ios-absolute-glass [class*="bg-green-"] {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.34), rgba(5, 150, 105, 0.24)) !important;
            border-color: rgba(110, 231, 183, 0.45) !important;
            color: #ffffff !important;
        }

        body.ios-absolute-glass [class*="bg-red-"],
        body.ios-absolute-glass [class*="bg-rose-"] {
            background: linear-gradient(135deg, rgba(248, 113, 113, 0.30), rgba(225, 29, 72, 0.22)) !important;
            border-color: rgba(252, 165, 165, 0.45) !important;
            color: #ffffff !important;
        }

        body.ios-absolute-glass [class*="bg-amber-"],
        body.ios-absolute-glass [class*="bg-yellow-"] {
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.32), rgba(245, 158, 11, 0.22)) !important;
            border-color: rgba(253, 224, 71, 0.45) !important;
            color: #111827 !important;
        }

        /* Cabecera carrito: azul degradé + glass absoluto */
        .cart-header-glass {
            background: linear-gradient(
                135deg,
                rgba(37, 99, 235, 0.34) 0%,
                rgba(59, 130, 246, 0.26) 45%,
                rgba(14, 116, 144, 0.24) 100%
            ) !important;
            border: 1px solid rgba(147, 197, 253, 0.42) !important;
            border-radius: 12px;
            -webkit-backdrop-filter: blur(24px) saturate(180%) !important;
            backdrop-filter: blur(24px) saturate(180%) !important;
            box-shadow: 0 10px 24px rgba(30, 64, 175, 0.24);
        }

        .cart-header-glass .cart-header-title {
            color: rgba(239, 246, 255, 0.96) !important;
            text-shadow: 0 1px 10px rgba(191, 219, 254, 0.35);
        }

        .cart-header-glass .cart-header-count {
            background: rgba(255, 255, 255, 0.22) !important;
            border: 1px solid rgba(255, 255, 255, 0.38) !important;
            color: rgba(239, 246, 255, 0.98) !important;
            text-shadow: 0 1px 8px rgba(191, 219, 254, 0.35);
        }

        html.dark .cart-header-glass {
            background: linear-gradient(
                135deg,
                rgba(29, 78, 216, 0.38) 0%,
                rgba(30, 64, 175, 0.28) 45%,
                rgba(12, 74, 110, 0.30) 100%
            ) !important;
            border-color: rgba(96, 165, 250, 0.40) !important;
            box-shadow: 0 12px 28px rgba(2, 6, 23, 0.45);
        }

        /* Buscador principal: blanco hielo + glass absoluto */
        .search-ice-glass {
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.56) 0%,
                rgba(239, 246, 255, 0.46) 55%,
                rgba(219, 234, 254, 0.40) 100%
            ) !important;
            border: 1px solid rgba(191, 219, 254, 0.56) !important;
            color: rgba(15, 23, 42, 0.96) !important;
            -webkit-backdrop-filter: blur(22px) saturate(175%) !important;
            backdrop-filter: blur(22px) saturate(175%) !important;
            box-shadow: 0 10px 24px rgba(148, 163, 184, 0.24);
        }

        .search-ice-glass::placeholder {
            color: rgba(71, 85, 105, 0.78) !important;
        }

        html.dark .search-ice-glass {
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.20) 0%,
                rgba(191, 219, 254, 0.18) 55%,
                rgba(125, 211, 252, 0.16) 100%
            ) !important;
            border-color: rgba(191, 219, 254, 0.42) !important;
            color: rgba(239, 246, 255, 0.98) !important;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.45);
        }

        html.dark .search-ice-glass::placeholder {
            color: rgba(226, 232, 240, 0.72) !important;
        }

        /* Icono carrito vacío: blanco hielo + glass absoluto */
        .empty-cart-icon-glass {
            width: 7rem;
            height: 7rem;
            margin: 0 auto;
            border-radius: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.40) 0%,
                rgba(239, 246, 255, 0.32) 55%,
                rgba(219, 234, 254, 0.28) 100%
            ) !important;
            border: 1px solid rgba(226, 232, 240, 0.65) !important;
            -webkit-backdrop-filter: blur(20px) saturate(170%) !important;
            backdrop-filter: blur(20px) saturate(170%) !important;
            box-shadow: 0 12px 26px rgba(148, 163, 184, 0.24);
        }

        .empty-cart-icon-glass svg {
            color: rgba(248, 250, 252, 0.96) !important;
            filter: drop-shadow(0 2px 10px rgba(255, 255, 255, 0.35));
        }

        html.dark .empty-cart-icon-glass {
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.18) 0%,
                rgba(191, 219, 254, 0.15) 55%,
                rgba(186, 230, 253, 0.12) 100%
            ) !important;
            border-color: rgba(226, 232, 240, 0.32) !important;
            box-shadow: 0 12px 30px rgba(2, 6, 23, 0.45);
        }

        .empty-cart-caption {
            color: rgba(248, 250, 252, 0.94) !important;
            text-shadow: 0 1px 10px rgba(255, 255, 255, 0.30);
        }

        .empty-cart-subcaption {
            color: rgba(226, 232, 240, 0.86) !important;
            text-shadow: 0 1px 8px rgba(255, 255, 255, 0.24);
        }

        /* POS dark sólido: depreca glass/transparencias y fuerza superficies opacas */
        html,
        body,
        body.pos-dark-solid {
            background: #020617 !important;
            background-image: none !important;
            color: #e2e8f0 !important;
        }

        body.pos-dark-solid::before,
        body.pos-dark-solid::after,
        body.pos-dark-solid #video-background,
        body.pos-dark-solid #video-overlay {
            display: none !important;
        }

        body.pos-dark-solid,
        body.pos-dark-solid .pos-container,
        body.pos-dark-solid .ios-desk-shell,
        body.pos-dark-solid .ios-desk-pane,
        body.pos-dark-solid .ios-desk-footer,
        body.pos-dark-solid .ios-desk-chip,
        body.pos-dark-solid header,
        body.pos-dark-solid main,
        body.pos-dark-solid section {
            background: #020617 !important;
            -webkit-backdrop-filter: none !important;
            backdrop-filter: none !important;
            box-shadow: none !important;
        }

        body.pos-dark-solid .ios-desk-shell,
        body.pos-dark-solid .ios-desk-pane,
        body.pos-dark-solid .ios-desk-footer,
        body.pos-dark-solid .ios-desk-chip,
        body.pos-dark-solid header,
        body.pos-dark-solid main,
        body.pos-dark-solid section,
        body.pos-dark-solid .producto-card,
        body.pos-dark-solid .cart-item,
        body.pos-dark-solid [class*="bg-white"],
        body.pos-dark-solid [class*="bg-slate-"],
        body.pos-dark-solid [class*="bg-gray-"],
        body.pos-dark-solid [class*="dark:bg-slate-"],
        body.pos-dark-solid [class*="dark:bg-gray-"] {
            border-color: #334155 !important;
        }

        body.pos-dark-solid header,
        body.pos-dark-solid .ios-desk-chip,
        body.pos-dark-solid .cart-header-glass {
            background: #0f172a !important;
        }

        body.pos-dark-solid .ios-desk-pane,
        body.pos-dark-solid .ios-desk-footer,
        body.pos-dark-solid .producto-card,
        body.pos-dark-solid .cart-item,
        body.pos-dark-solid .search-ice-glass,
        body.pos-dark-solid input,
        body.pos-dark-solid select,
        body.pos-dark-solid textarea {
            background: #111827 !important;
            color: #e5e7eb !important;
        }

        body.pos-dark-solid .search-ice-glass::placeholder,
        body.pos-dark-solid input::placeholder,
        body.pos-dark-solid textarea::placeholder {
            color: #94a3b8 !important;
        }

        body.pos-dark-solid .empty-cart-icon-glass {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        body.pos-dark-solid .empty-cart-caption,
        body.pos-dark-solid .empty-cart-subcaption {
            color: #cbd5e1 !important;
            text-shadow: none !important;
        }
    </style>
</head>

<body class="pos-dark-solid text-gray-900 dark:text-white transition-colors" style="background:#020617 !important;">
    <div id="bootSplash" aria-hidden="true">
        <div class="boot-splash-card">
            <img src="<?php echo htmlspecialchars($logoEmpresaUrl ?: '/public/_lib/file/img/empresa/logo_sistemax.png'); ?>" alt="Logo" class="boot-splash-logo" onerror="this.src='/public/_lib/file/img/empresa/logo_sistemax.png'">
            <div class="boot-splash-title"><?= htmlspecialchars($posContext['mode_badge'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="boot-splash-subtitle">Cargando mercaderías y detalle inicial...</div>
            <div class="boot-splash-spinner"></div>
        </div>
    </div>
    <!-- Video Background -->
    <video id="video-background" autoplay muted loop playsinline>
        <source src="" type="video/mp4">
    </video>
    <div id="video-overlay"></div>
    
    <div class="pos-container ios-desk-shell">
        <!-- Header -->
        <header class="bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 px-4 py-2 flex items-center justify-between gap-4 rounded-t-[19px]">
            <!-- Izquierda: Título y Empresa -->
            <div class="flex items-center gap-3 flex-shrink-0 min-w-0">
                <?php
                    // Logo de empresa - construir ruta absoluta directa
                    $logoSrc = '/public/_lib/file/img/empresa/logo_sistemax.png'; // fallback
                    if (!empty($logoEmpresaUrl)) {
                        $logoSrc = $logoEmpresaUrl;
                    }
                ?>
                <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Logo" class="w-8 h-8 object-contain rounded" onerror="this.src='/public/_lib/file/img/empresa/logo_sistemax.png'">
                <div class="w-px h-6 bg-slate-100 dark:bg-slate-700"></div>
                <div class="flex min-w-0 items-center gap-2">
                    <?php if (!empty($posContext['show_back_button'])): ?>
                    <button
                        @click="goBackToParent()"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-cyan-400/40 bg-cyan-500/12 px-3 py-1.5 text-[11px] font-black uppercase tracking-[0.14em] text-cyan-100 shadow-[0_0_18px_rgba(34,211,238,.08)] transition hover:border-cyan-300 hover:bg-cyan-500/20 hover:text-white"
                        title="Volver">
                        <span aria-hidden="true" class="text-cyan-200">←</span>
                        <span class="text-cyan-50">Volver</span>
                    </button>
                    <?php endif; ?>
                    <span class="text-white/90 font-bold text-[11px] uppercase tracking-widest truncate max-w-[170px] xl:max-w-[220px]" style="text-shadow: 0 1px 10px rgba(255,255,255,.35);"><?php echo htmlspecialchars($nombreEmpresa); ?></span>
                    <?php if (empty($posContext['header_highlight']) || empty($posContext['hide_caja'])): ?>
                    <span class="inline-flex items-center rounded-full border border-cyan-500/30 bg-cyan-500/10 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.18em] text-cyan-200">
                        <?= htmlspecialchars($posContext['mode_badge'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($posContext['header_highlight'])): ?>
                    <span class="mr-2 inline-flex max-w-[220px] xl:max-w-[320px] items-center rounded-full border border-amber-400/30 bg-gradient-to-r from-amber-500/18 via-orange-500/10 to-cyan-500/10 pl-3 pr-5 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-white shadow-[0_0_24px_rgba(251,191,36,.08)] truncate">
                        <?= htmlspecialchars($posContext['header_highlight'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($posContext['header_highlight']) && empty($posContext['hide_caja'])): ?>
            <div class="hidden 2xl:flex min-w-0 flex-1 justify-center px-4">
                <div class="inline-flex items-center gap-3 rounded-2xl border border-amber-400/30 bg-gradient-to-r from-amber-500/18 via-orange-500/10 to-cyan-500/10 px-5 py-2 shadow-[0_0_30px_rgba(251,191,36,.08)]">
                    <span class="text-[11px] font-black uppercase tracking-[0.28em] text-amber-300"><?= htmlspecialchars($posContext['mode_badge'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="h-5 w-px bg-white/10"></span>
                    <span class="text-base font-black uppercase tracking-[0.16em] text-white"><?= htmlspecialchars($posContext['header_highlight'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
            <?php else: ?>
            <div class="hidden 2xl:block flex-1"></div>
            <?php endif; ?>

            <!-- Centro: Tickets -->
            <div class="flex items-center gap-1 flex-1 justify-center overflow-x-auto max-w-2xl">
                <template x-for="(ticket, index) in tickets" :key="ticket.id">
                    <div
                        @click="switchTicket(index)"
                        :class="activeTicket === index 
                        ? 'bg-blue-600 text-white' 
                        : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-600'"
                        :title="(ticket.selectedCliente?.nombre ? '👤 ' + ticket.selectedCliente.nombre + (ticket.selectedCliente?.ruc ? ' (' + ticket.selectedCliente.ruc + ')' : '') : 'Sin cliente') + '\n🛒 ' + ticket.cart.length + ' producto(s)'"
                        class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg cursor-pointer transition-all text-sm min-w-fit max-w-52 group relative">
                        <span class=" font-medium whitespace-nowrap">🧾 #<span x-text="ticket.id"></span></span>
                        <!-- Nombre del cliente -->
                        <span
                            x-show="ticket.selectedCliente?.nombre"
                            class="text-xs truncate max-w-24 opacity-80 border-l border-white/20 pl-1.5"
                            x-text="ticket.selectedCliente?.nombre?.split(',')[0] || ticket.selectedCliente?.nombre?.split(' ')[0] || ''"></span>
                        <button
                            x-show="index > 0"
                            @click.stop="removeTicket(index)"
                            class="text-xs opacity-50 hover:opacity-100 hover:text-red-400 flex-shrink-0 ml-1"
                            title="Cerrar ticket">✕</button>
                    </div>
                </template>
                <button
                    @click="addTicket()"
                    class="w-7 h-7 bg-slate-100 dark:bg-slate-700 hover:bg-green-600 text-slate-400 hover:text-slate-900 dark:text-white rounded-lg flex items-center justify-center transition-all flex-shrink-0"
                    title="Nuevo ticket (Ctrl+T)">+</button>
            </div>

            <!-- Derecha: Hora y Usuario -->
            <div class="flex items-center gap-4 flex-shrink-0">
                <span class="text-white/90 text-sm hidden sm:inline" style="text-shadow: 0 1px 10px rgba(255,255,255,.35);" x-text="currentTime"></span>

                <button
                    @click="openPresupuestoImportModal()"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold p-2 rounded-lg transition-all flex items-center justify-center text-sm gap-1.5 shadow-sm"
                    title="Importar desde presupuestos">
                    <span class="text-xs">Importar Presup.</span>
                </button>

                <!-- Caja y Usuario -->
                <div class="flex items-center gap-3 bg-white dark:bg-slate-800/50 px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700/50 ios-desk-chip">
                    <?php if (empty($posContext['hide_caja'])): ?>
                    <div class="flex items-stretch gap-3">
                        <div class="flex min-w-[54px] flex-col justify-center leading-tight text-right">
                            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">Caja</span>
                            <span class="mt-0.5 text-sm font-semibold text-slate-100">#<?php echo str_pad($id_caja, 2, '0', STR_PAD_LEFT); ?></span>
                        </div>
                        <?php if ($nombre_sucursal !== ''): ?>
                        <div class="hidden h-8 w-px bg-slate-200 dark:bg-slate-700 md:block"></div>
                        <div class="hidden min-w-[108px] flex-col justify-center leading-tight text-right md:flex">
                            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">Sucursal</span>
                            <span class="mt-0.5 truncate text-sm font-semibold text-slate-100" title="<?php echo htmlspecialchars($nombre_sucursal, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($nombre_sucursal, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="w-px h-6 bg-slate-100 dark:bg-slate-700"></div>
                    <?php endif; ?>
                    <div class="flex items-center gap-2">
                        <div class="hidden md:flex flex-col items-end leading-none">
                            <span class="text-sm text-slate-500 font-bold tracking-widest"><?= empty($posContext['hide_caja']) ? 'Usuario' : 'Operador' ?></span>
                            <span class="text-xs"><?php echo htmlspecialchars($usuario); ?></span>
                        </div>
                        <button
                            @click="toggleSound()"
                            class="w-8 h-8 rounded-lg flex items-center justify-center border transition-all cursor-pointer"
                            :class="soundMuted ? 'bg-red-500/20 border-red-500/30' : 'bg-green-500/20 border-green-500/30'"
                            :title="soundMuted ? 'Sonido desactivado - Click para activar' : 'Sonido activado - Click para desactivar'">
                            <span x-show="!soundMuted" class="text-sm">🔊</span>
                            <span x-show="soundMuted" class="text-sm">🔇</span>
                        </button>
                    </div>
                </div>

            </div>
        </header>

        <!-- Main Content -->
        <main class="pos-main-split flex-1">

            <!-- Panel Izquierdo: Carrito -->
            <section class="pos-cart-panel order-1 w-full min-h-0 border-b md:border-b-0 md:border-r border-slate-200 dark:border-slate-700 flex flex-col bg-slate-850 ios-desk-pane">

                <!-- Búsqueda con Autocompletado -->
                <div class="shrink-0 p-4 border-b border-slate-200 dark:border-slate-700 relative overflow-visible">
                    <div class="flex items-center gap-2">
                    <div class="relative flex-1" @click.away="closeSearchDropdown()">
                        <button
                            type="button"
                            @click="openGeneralProductSearch()"
                            class="absolute left-3 top-1/2 z-10 -translate-y-1/2 text-white hover:text-cyan-300 transition-colors"
                            title="Abrir buscador general">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </button>
                        <input
                            type="text"
                            x-model="searchQuery"
                            @input="searchProducts()"
                            @keydown.enter.prevent="handleSearchEnter()"
                            @keydown.arrow-down.prevent="navigateSearchDropdown(1)"
                            @keydown.arrow-up.prevent="navigateSearchDropdown(-1)"
                            @keydown.escape="searchQuery = ''; productos = []; searchSuggestion = null; closeSearchDropdown(); searchPreviewDetail = null"
                            @focus="selectedIndex = 0; updateSearchDropdownMaxHeight()"
                            x-ref="searchInput"
                            placeholder="<?= htmlspecialchars($posContext['search_placeholder'], ENT_QUOTES, 'UTF-8') ?>"
                            class="search-ice-glass w-full rounded-lg pl-10 pr-16 py-3 text-base focus:outline-none focus:ring-2 focus:ring-blue-500 rounded-xl">
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                            <button
                                @click="toggleVoiceSearch('products')"
                                :class="voiceListening ? 'text-red-500 animate-pulse' : 'text-slate-400 hover:text-blue-500'"
                                class="transition-colors"
                                title="Buscar por voz">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
                            </button>

                            <button
                                x-show="searchQuery"
                                @click="searchQuery = ''; productos = []; searchSuggestion = null; closeSearchDropdown(); searchPreviewDetail = null"
                                class="text-slate-400 hover:text-slate-900 dark:text-white">✕</button>
                        </div>

                        <div
                            x-cloak
                            x-show="showSearchDropdown && (loading || productos.length > 0 || searchSuggestion)"
                            x-ref="searchDropdownPanel"
                            @resize.window="updateSearchDropdownMaxHeight()"
                            @scroll.window.capture="updateSearchDropdownMaxHeight()"
                            class="absolute left-0 right-0 top-[calc(100%+8px)] z-40 overflow-hidden rounded-2xl border border-slate-700 bg-slate-950 shadow-2xl">
                            <!-- Header con contador -->
                            <div class="sticky top-0 bg-slate-900 border-b border-slate-700 px-4 py-2 space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-slate-400 font-semibold tracking-widest">RESULTADOS</span>
                                    <span class="text-lg font-bold text-blue-400 bg-slate-800 px-2.5 py-1 rounded" x-text="productos.length"></span>
                                </div>
                            </div>
                            <div 
                                x-ref="searchDropdownScroll"
                                class="overflow-y-auto py-2"
                                :style="`max-height: ${searchDropdownMaxHeight}px`"
                                @show="setupDropdownScrollListener()"
                                @scroll.throttle="loadMoreProducts()">
                                <div x-show="loading" class="mx-2 mb-2 flex items-center gap-3 rounded-xl border border-blue-500/30 bg-blue-500/10 px-4 py-3 text-blue-200">
                                    <div class="h-4 w-4 animate-spin rounded-full border-2 border-blue-300 border-t-transparent"></div>
                                    <span class="text-sm font-medium">Buscando productos...</span>
                                </div>
                                <button
                                    type="button"
                                    x-show="searchSuggestion?.text"
                                    @click="applySearchSuggestion()"
                                    class="mx-2 mb-2 block w-[calc(100%-1rem)] rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-left transition hover:bg-amber-500/15">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-amber-300">Quizás quiso decir</div>
                                    <div class="mt-1 text-sm font-semibold text-white" x-text="searchSuggestion?.text || ''"></div>
                                </button>
                                <template x-for="(producto, index) in getVisibleDropdownProducts()" :key="`${producto.id}-${index}`">
                                    <button
                                        type="button"
                                        @mouseenter="focusDropdownProduct(index)"
                                        @click="selectDropdownProduct(producto)"
                                        class="flex w-full items-start px-4 py-2 text-left transition-colors"
                                        :class="selectedIndex === index ? 'bg-blue-600/20 border-l-2 border-blue-400' : 'hover:bg-slate-800/80 border-l-2 border-transparent'">
                                        <div class="min-w-0 flex-1">
                                            <span class="text-sm font-normal text-slate-100 break-words whitespace-normal" x-html="highlightSearchText(producto.descripcion, searchQuery)"></span>
                                            <div x-show="selectedIndex === index" class="mt-1 flex flex-wrap items-center gap-2 text-[11px]">
                                                <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 font-semibold text-emerald-300"
                                                      x-text="'Precio: ' + formatMoney(getDropdownInlinePrice(producto))"></span>
                                                <template x-for="suc in getDropdownInlineStocks(producto)" :key="'dropdown-inline-stock-' + producto.id + '-' + suc.id_sucursal">
                                                    <span class="rounded-full px-2 py-0.5 font-medium"
                                                          :class="isCurrentPreviewSucursal(suc) ? 'bg-cyan-500/15 text-cyan-200' : 'bg-slate-800 text-slate-300'">
                                                        <span x-text="suc.nombre_sucursal"></span>
                                                        <span class="mx-1 text-slate-500">:</span>
                                                        <span x-text="formatNum(suc.stock, 0, 3)"></span>
                                                    </span>
                                                </template>
                                                <span x-show="getDropdownInlineStocksOverflow(producto) > 0"
                                                      class="rounded-full bg-slate-800 px-2 py-0.5 font-medium text-slate-400"
                                                      x-text="'+' + getDropdownInlineStocksOverflow(producto) + ' suc.'"></span>
                                            </div>
                                        </div>
                                    </button>
                                </template>
                                <div
                                    x-show="hasMoreDropdownResults()"
                                    class="px-4 pb-2 pt-3 text-right">
                                    <button
                                        type="button"
                                        @click="openGeneralProductSearch()"
                                        class="inline-flex items-center gap-2 text-xs font-medium text-cyan-300/80 underline decoration-cyan-400/40 underline-offset-4 transition hover:text-cyan-200 hover:decoration-cyan-300">
                                        <span>Ver todo</span>
                                        <span class="text-[11px] text-slate-400 no-underline" x-text="'(' + getHiddenDropdownCount() + ' más)'"></span>
                                    </button>
                                </div>
                                <div
                                    x-show="!productos.length && searchSuggestion?.text"
                                    class="px-4 pb-3 pt-1 text-xs text-slate-400">
                                    No hubo coincidencias directas. Pulse la sugerencia para rehacer la búsqueda.
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>

                    <div x-show="voiceListening" x-transition class="mt-2 flex items-center gap-2 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 rounded-xl px-3 py-2">
                        <span class="relative flex h-3 w-3">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                        </span>
                        <span class="text-sm text-red-600 dark:text-red-300 font-medium">Escuchando — Diga el producto a buscar</span>
                    </div>

                    <div
                        x-show="balanzaData"
                        x-transition
                        class="mt-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/40 dark:bg-amber-900/30 dark:text-amber-100 px-4 py-3 flex items-start gap-3 shadow-sm">
                        <div class="text-2xl">⚖️</div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <p class="font-bold text-sm">Peso detectado en balanza</p>
                                <span class="text-[10px] font-mono bg-white/60 dark:bg-slate-800/60 px-2 py-0.5 rounded-full" x-text="balanzaData?.balanza || 'Balanza'"></span>
                            </div>
                            <div class="text-[12px] grid grid-cols-2 gap-2 text-slate-700 dark:text-slate-200">
                                <div>
                                    <span class="font-semibold">Producto:</span>
                                    <span x-text="(balanzaData?.descripcion || 'N/D') + (balanzaData?.codigo ? ' (' + balanzaData.codigo + ')' : '')"></span>
                                </div>
                                <div><span class="font-semibold">ID:</span> <span x-text="balanzaData?.idproducto || ''"></span></div>
                                <div><span class="font-semibold">Modo:</span> <span x-text="balanzaData?.modo || ''"></span></div>
                                <div><span class="font-semibold">Valor leído:</span> <span x-text="balanzaData?.valor || ''"></span></div>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <button @click="confirmarBalanza()" class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-bold hover:bg-blue-700 transition-colors">Usar en venta</button>
                                <button @click="cancelarBalanza()" class="px-3 py-1.5 rounded-lg bg-white text-slate-700 border border-slate-300 text-xs font-bold hover:bg-slate-100 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-600 dark:hover:bg-slate-700">Cancelar</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Items del Carrito -->
                <div class="flex-1 min-h-0 overflow-y-auto custom-scroll max-h-[50vh] md:max-h-none px-2 py-2">
                    <div class="cart-header-glass flex items-center justify-between mb-2 px-3 py-2">
                        <span class="cart-header-title text-[10px] font-black uppercase tracking-widest"><?= htmlspecialchars($posContext['cart_items_label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="cart-header-count text-[10px] font-bold px-2 py-0.5 rounded-full" x-text="cart.length + ' items'"></span>
                    </div>
                    
                    <div class="space-y-1.5">
                        <template x-for="(item, index) in cart" :key="item.uid || (index + '-' + (item.id ?? ''))">
                            <div class="cart-item group relative rounded-lg border transition-all"
                                 @mouseenter="previewCartItem(item)"
                                 @click="openCartItemDetail(item)"
                                 :class="index % 2 === 0 ? 'bg-white dark:bg-slate-800/70 border-slate-200 dark:border-slate-700/50' : 'bg-slate-50 dark:bg-slate-800/40 border-slate-100 dark:border-slate-700/30'">
                                <div class="absolute left-0 top-0 bottom-0 w-1 rounded-l-lg" :class="index % 2 === 0 ? 'bg-blue-500' : 'bg-emerald-500'"></div>
                                
                                <div class="pl-3 pr-2 py-1.5 flex gap-2">
                                    <div class="flex-shrink-0 w-10 h-10 rounded overflow-hidden bg-slate-100 dark:bg-slate-700">
                                        <template x-if="item.imagen">
                                            <img :src="(item.imagen.startsWith('/_lib') ? '/public' + item.imagen : item.imagen) + (item.imagen_updated ? '?v=' + item.imagen_updated : '')" 
                                                 class="w-full h-full object-cover" 
                                                 :alt="item.descripcion">
                                        </template>
                                        <template x-if="!item.imagen">
                                            <div class="w-full h-full flex items-center justify-center text-slate-400">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                                </svg>
                                            </div>
                                        </template>
                                    </div>
                                    
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-start gap-2">
                                            <div class="flex-1 min-w-0">
                                                <template x-if="item.editableDescripcion">
                                                    <input type="text" class="w-full text-[11px] font-semibold bg-transparent border-b border-blue-400 focus:outline-none py-0.5" :value="item.descripcion" @click.stop @input="updateDescripcion(index, $event.target.value)">
                                                </template>
                                                <template x-if="!item.editableDescripcion">
                                                    <p class="text-[11px] font-semibold text-slate-800 dark:text-white leading-tight line-clamp-1" x-text="item.descripcion"></p>
                                                </template>
                                                <template x-if="item.serial_code">
                                                    <p class="mt-0.5 text-[9px] font-mono text-cyan-600 dark:text-cyan-300">
                                                        <span x-text="item.serial_type || 'SERIAL'"></span>: <span x-text="item.serial_code"></span>
                                                    </p>
                                                </template>
                                            </div>
                                            <button @click.stop="POSAudio.play('warning'); removeFromCart(index)" 
                                                    class="opacity-40 group-hover:opacity-100 text-red-500 hover:bg-red-100 dark:hover:bg-red-900/30 p-0.5 rounded transition-all">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        </div>
                                        
                                        <div class="flex items-center gap-1.5 mt-1">
                                            <span class="text-[8px] bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-400 px-1 rounded font-mono" x-text="item.codigo"></span>
                                            
                                            <div class="flex-1 flex items-center justify-end gap-1">
                                                <input type="text" inputmode="decimal"
                                                       :value="formatNum(item.cantidad, 0, 3)"
                                                       @click.stop
                                                       @change="POSAudio.play('info'); updateQtyManual(index, parseNum($event.target.value))"
                                                       :readonly="item.uses_serial"
                                                       class="w-10 bg-slate-100 dark:bg-slate-900 border-0 rounded text-center text-[10px] font-bold py-0.5 focus:ring-1 focus:ring-blue-500 outline-none">
                                                
                                                <span class="text-[10px] text-slate-400">×</span>
                                                
                                                <template x-if="item.editablePrecio">
                                                    <input type="text" inputmode="numeric"
                                                           class="w-14 bg-slate-100 dark:bg-slate-900 border-0 rounded text-center text-[10px] font-bold py-0.5 focus:ring-1 focus:ring-blue-500 outline-none"
                                                           :value="formatNum(item.precio, 0, 0)"
                                                           @click.stop
                                                           @change="updatePrice(index, parseNum($event.target.value))">
                                                </template>
                                                <template x-if="!item.editablePrecio">
                                                    <span class="text-[10px] font-medium text-slate-500 dark:text-slate-400 w-14 text-right" x-text="formatMoney(item.precio)"></span>
                                                </template>
                                                
                                                <span class="text-[10px] text-slate-400">=</span>
                                                
                                                <span class="text-[11px] font-black text-blue-600 dark:text-blue-400 min-w-[50px] text-right" x-text="formatMoney(item.precio * item.cantidad)"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div x-show="item.delete_authorized" class="pointer-events-none absolute bottom-2 right-2 z-10">
                                    <div class="rounded-full bg-emerald-500/15 px-2.5 py-1 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-500/30 dark:bg-emerald-400/15 dark:text-emerald-300 dark:ring-emerald-400/30">
                                        <span>Autorizado</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div x-show="cart.length === 0" class="text-center text-slate-500 py-20">
                        <div class="empty-cart-icon-glass mb-4">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-24 h-24 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <p class="empty-cart-caption text-lg">Factura vacía</p>
                        <p class="empty-cart-subcaption text-sm">Buscá productos para agregar</p>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 p-4 ios-desk-footer">

                    <!-- Cliente -->
                    <div class="mb-4">
                        <!-- <label class="text-sm text-slate-400 mb-1 block">Cliente</label> -->
                        <div class="relative flex items-center">
                            <input
                                type="text"
                                :value="currentTicket.clienteSearch"
                                @input="currentTicket.clienteSearch = $event.target.value; clienteSelectedIdx = 0; $nextTick(() => searchClientes(false))"
                                @focus="showClienteDropdown = true; clienteSelectedIdx = 0"
                                @blur="setTimeout(() => { const q = currentTicket.clienteSearch || ''; if(!selectedCliente && !sifenLookupInFlight && q && /^[0-9.-]+$/.test(q.trim()) && q.trim().length >= 5) { searchClientes(true); } showClienteDropdown = false; }, 200)"
                                @keydown.arrow-down.prevent="if(clientesResults.length) clienteSelectedIdx = (clienteSelectedIdx + 1) % clientesResults.length"
                                @keydown.arrow-up.prevent="if(clientesResults.length) clienteSelectedIdx = (clienteSelectedIdx - 1 + clientesResults.length) % clientesResults.length"
                                @keydown.enter.prevent="handleClienteEnter()"
                                @keydown.escape="showClienteDropdown = false"
                                placeholder="Cliente ocasional"
                                x-ref="clienteInput"
                                class="pos-cliente-input w-full bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded pl-3 pr-9 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500 rounded-xl">
                            <!-- Botón micrófono cliente -->
                            <button
                                @click="toggleVoiceSearch('clientes')"
                                :class="voiceListeningCliente ? 'text-red-500 animate-pulse' : 'text-slate-400 hover:text-blue-500'"
                                class="absolute right-2 top-1/2 -translate-y-1/2 transition-colors z-10"
                                title="Buscar cliente por voz">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
                            </button>

                            <!-- Indicador de escucha por voz cliente -->
                            <div x-show="voiceListeningCliente" x-transition class="absolute top-full left-0 z-30 w-full mt-1 flex items-center gap-2 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 rounded-xl px-3 py-2">
                                <span class="relative flex h-3 w-3">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                                </span>
                                <span class="text-sm text-red-600 dark:text-red-300 font-medium">Escuchando — Diga el cliente a buscar</span>
                            </div>

                            <!-- Dropdown clientes -->
                            <div
                                x-show="showClienteDropdown && clientesResults.length > 0"
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                @click.away="showClienteDropdown = false"
                                class="absolute top-full left-0 z-20 w-full mt-1 bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded-lg shadow-xl max-h-[300px] overflow-y-auto">

                                <template x-for="(cli, idx) in clientesResults" :key="cli.id">
                                    <div
                                        @click="POSAudio.play('success'); selectedCliente = cli; clienteSearch = cli.nombre; showClienteDropdown = false; clientesResults = [];"
                                        @mouseenter="clienteSelectedIdx = idx"
                                        :class="clienteSelectedIdx === idx ? 'bg-blue-600/50' : 'hover:bg-slate-600'"
                                        class="px-3 py-2 cursor-pointer text-sm transition-colors border-b border-slate-600/50 last:border-none">
                                        <div class="flex justify-between items-start gap-2">
                                            <span class="font-medium flex-1" x-text="cli.nombre"></span>
                                            <span class="text-xs text-blue-400 font-mono" x-text="cli.ruc"></span>
                                            <span
                                                :class="cli.source === 'sifen' ? 'bg-green-500/20 text-green-400 border-green-500/50' : 'bg-slate-500/20 text-slate-400 border-slate-500/50'"
                                                class="text-[10px] px-1.5 py-0.5 rounded border font-bold uppercase tracking-wider">
                                                <span x-text="cli.source === 'sifen' ? '🌐 SET' : '💾 DB'"></span>
                                            </span>
                                        </div>
                                        <div class="text-xs text-slate-400 flex gap-3 mt-0.5">
                                            <span x-show="cli.telefono" x-text="'📞 ' + cli.telefono"></span>
                                            <span x-show="cli.email" x-text="'✉️ ' + cli.email"></span>
                                            <span x-show="cli.direccion" class="truncate max-w-[200px]" x-text="'📍 ' + cli.direccion"></span>
                                        </div>
                                    </div>
                                </template>
                                <div class="px-3 py-1.5 bg-white dark:bg-slate-800/50 text-xs text-slate-500 flex justify-between">
                                    <span>↑↓ navegar • Enter seleccionar</span>
                                    <span x-text="clientesResults.length + ' resultados'"></span>
                                </div>
                            </div>

                            <div
                                x-show="showClienteDropdown && clientesResults.length === 0 && clienteSearch.trim().length >= 2 && !loadingClientes"
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                @click.away="showClienteDropdown = false"
                                class="absolute top-full left-0 z-20 w-full mt-1 bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded-lg shadow-xl overflow-hidden">

                                <div class="p-3 text-center">
                                    <p class="text-slate-300 text-sm font-medium mb-1">Sin resultados para "<span class="text-blue-400" x-text="clienteSearch"></span>"</p>
                                    <p class="text-slate-400 text-xs">
                                        Ingrese el <strong class="text-blue-400">RUC o CI</strong> y presione <kbd class="bg-slate-600 px-1 rounded text-[10px]">Enter</kbd> para buscar en <strong class="text-green-400">SET</strong>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div x-show="editingVenta" class="mb-2 bg-amber-500/20 border border-amber-500 rounded-xl p-3 flex items-center justify-between animate-pulse">
                        <div class="flex items-center gap-2">
                            <span class="text-xl">✏️</span>
                            <div>
                                <div class="text-amber-500 font-black text-sm">MODO EDICIÓN</div>
                                <div class="text-amber-400 text-xs" x-text="'Editando: ' + editingVenta?.nro_factura"></div>
                            </div>
                        </div>
                        <button @click="cancelEditMode()" class="text-amber-500 hover:text-red-500 text-xs font-bold px-2 py-1 rounded bg-amber-500/20 hover:bg-red-500/20 transition-all">
                            ✕ Cancelar
                        </button>
                    </div>

                    <div x-show="!editingVenta && importedPresupuesto" class="mb-2 bg-indigo-500/20 border border-indigo-500 rounded-xl p-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-xl">🧾</span>
                            <div>
                                <div class="text-indigo-300 font-black text-sm">PRESUPUESTO IMPORTADO</div>
                                <div class="text-indigo-200 text-xs" x-text="'Origen: ' + (importedPresupuesto?.nro_factura || ('#' + importedPresupuesto?.id_factura))"></div>
                            </div>
                        </div>
                        <button @click="importedPresupuesto = null; toast('Origen de presupuesto quitado', 'warning')" class="text-indigo-200 hover:text-white text-xs font-bold px-2 py-1 rounded bg-indigo-500/20 hover:bg-indigo-500/30 transition-all">
                            Quitar
                        </button>
                    </div>

                    <div class="flex gap-2">
                        <button
                            @click="handleFinishSale()"
                            @keydown.enter.prevent="handleFinishSale()"
                            x-ref="finishSaleButton"
                            :disabled="processingSale"
                            :class="editingVenta ? 'bg-amber-600 hover:bg-amber-700' : 'bg-blue-600 hover:bg-blue-700'"
                            class="w-full text-white font-bold py-4 rounded-xl transition-all flex items-center justify-center gap-3 text-2xl shadow-lg hover:shadow-green-500/20 active:scale-[0.98]">
                            <span x-show="!processingSale" class="flex items-center gap-3">
                                <span x-text="editingVenta ? 'GUARDAR' : 'TERMINAR'"></span>
                                <span class="bg-white dark:bg-slate-800/20 px-3 py-1 rounded-lg text-xl text-red-600 font-black" x-text="formatMoney(total) + ' Gs'"></span>
                            </span>
                            <span x-show="processingSale" class="animate-spin text-3xl">⏳</span>
                        </button>
                    </div>
                </div>
            </section>

            <!-- Panel Derecho: Preview y detalle -->
            <section class="pos-detail-panel order-2 w-full min-h-0 flex flex-col overflow-hidden ios-desk-pane">

                <!-- Preview de mercadería enfocada -->
                <div class="flex-1 min-h-0 overflow-hidden p-4">
                    <div class="flex h-full min-h-0 flex-col">
                    <div class="cart-header-glass mb-3 shrink-0 flex items-center justify-between px-3 py-2">
                        <h2 class="cart-header-title text-xs font-black uppercase tracking-widest flex items-center gap-2">
                            <span class="text-cyan-300">⌄</span> Preview de mercadería
                        </h2>
                        <span class="cart-header-count text-[9px] font-bold px-2 py-0.5 rounded-full" x-text="productos.length > 0 ? (selectedIndex + 1) + ' / ' + productos.length : 'Sin selección'"></span>
                    </div>

                    <div x-show="(loading || loadingSearchPreview) && !searchPreviewDetail" class="flex flex-1 min-h-0 items-center justify-center rounded-3xl border border-slate-800 bg-slate-950 text-slate-400">
                        <div class="text-center">
                            <div class="animate-spin text-2xl">⏳</div>
                            <p class="mt-3 text-sm">Buscando mercaderías...</p>
                        </div>
                    </div>

                    <div x-show="!loading && !searchPreviewDetail" class="flex flex-1 min-h-0 items-center justify-center rounded-3xl border border-dashed border-slate-700 bg-slate-950/70 p-6 text-center text-slate-400">
                        <div>
                            <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-slate-900 text-4xl">📦</div>
                            <p class="mt-4 text-base font-semibold text-slate-200">Sin panel de frecuentes ni grilla de búsqueda</p>
                            <p class="mt-2 text-sm text-slate-400">Escriba en el buscador y navegue el dropdown para ver aquí el detalle de la mercadería enfocada.</p>
                        </div>
                    </div>

                    <div x-show="searchPreviewDetail && searchPreviewDetail.producto" class="flex-1 min-h-0 overflow-hidden rounded-3xl border border-slate-800 bg-slate-950 shadow-2xl">
                            <div class="h-full overflow-y-auto p-5">
                                <div class="preview-ecom-shell preview-ecom-text">
                                    <div class="preview-ecom-layout">
                                        <div class="flex items-start justify-center">
                                            <div class="preview-gallery w-full">
                                                <div class="preview-gallery-strip">
                                                    <template x-for="image in getSearchPreviewImages()" :key="image.url">
                                                        <button
                                                            type="button"
                                                            @click="setSearchPreviewImage(image.url)"
                                                            class="preview-gallery-thumb"
                                                            :class="{ 'is-active': getActiveSearchPreviewImage() === image.url }">
                                                            <img :src="image.thumb || image.url" alt="Miniatura"
                                                                class="h-full w-full object-cover">
                                                        </button>
                                                    </template>
                                                </div>

                                                <div class="preview-gallery-main">
                                                    <div class="preview-gallery-stage"
                                                        @mouseenter="activateSearchPreviewZoom()"
                                                        @mouseleave="clearSearchPreviewZoom()"
                                                        @mousemove="updateSearchPreviewZoom($event)">
                                                        <button
                                                            type="button"
                                                            @click.stop="openImageSearchModal(getSearchPreviewCardProduct())"
                                                            class="preview-gallery-edit-btn"
                                                            title="Gestionar imagenes del producto">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                                <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25Zm17.71-10.04a1.003 1.003 0 0 0 0-1.42l-2.5-2.5a1.003 1.003 0 0 0-1.42 0l-1.96 1.96 3.75 3.75 2.13-1.79Z"/>
                                                            </svg>
                                                        </button>
                                                        <img
                                                            x-show="getActiveSearchPreviewImage()"
                                                            :src="getActiveSearchPreviewImage()"
                                                            :alt="searchPreviewDetail?.producto?.descripcion || 'Producto'"
                                                            style="transform: none;">
                                                        <div x-show="!getActiveSearchPreviewImage()" x-html="getProductImageHTML(getSearchPreviewCardProduct(), 'full', 'h-full w-full object-cover', { enableCarousel: false, suppressImage: false })"></div>
                                                        <div
                                                            x-show="searchPreviewZoomActive && getActiveSearchPreviewImage()"
                                                            class="preview-gallery-lens"
                                                            :style="getSearchPreviewLensStyle()"></div>
                                                    </div>
                                                </div>

                                                <div class="preview-gallery-zoom-pane" :class="{ 'is-visible': searchPreviewZoomActive && getActiveSearchPreviewImage() }">
                                                    <div class="preview-gallery-zoom-surface" :style="getSearchPreviewZoomPaneStyle()"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="preview-ecom-copy">
                                            <a href="#" @click.prevent="openKardexModal()" class="preview-ecom-link">Acceda a la ficha del producto</a>
                                            <div class="preview-ecom-meta mt-6">
                                                <span x-text="(searchPreviewDetail?.producto?.Estado == 1 ? 'Nuevo' : 'Inactivo')"></span>
                                                <span class="mx-2">|</span>
                                                <span x-text="formatNum(searchPreviewDetail?.ventas_registradas || 0, 0, 0) + ' ventas registradas'"></span>
                                            </div>
                                            <div class="preview-ecom-meta mt-3">
                                                Código de barra:
                                                <span class="font-mono text-blue-400" x-text="(searchPreviewDetail?.codigos_barra || [])[0] || 'Sin código'"></span>
                                            </div>
                                            <h3 class="preview-ecom-title" x-text="searchPreviewDetail?.producto?.descripcion"></h3>
                                            <div class="preview-ecom-rating">
                                                <span>4.9</span>
                                                <span class="preview-ecom-stars">★★★★★</span>
                                                <span x-text="'(' + formatNum(searchPreviewDetail?.ventas_registradas || 0, 0, 0) + ')'"></span>
                                            </div>

                                            <div x-show="getPreviewOriginalPrice() > getPreviewPrimaryPrice()" class="preview-ecom-old-price" x-text="formatMoney(getPreviewOriginalPrice()) + ' Gs'"></div>
                                            <button type="button" class="preview-ecom-price" @click="showPreviewPricesModal = true">
                                                <span class="preview-ecom-price-value" x-text="formatMoney(getPreviewPrimaryPrice()) + ' Gs'"></span>
                                                <span x-show="getPreviewDiscountPct() > 0" class="preview-ecom-discount" x-text="getPreviewDiscountPct() + '% OFF'"></span>
                                            </button>
                                            <div class="preview-ecom-stock-pills" x-show="getOrderedPreviewStocks().length > 0">
                                                <template x-for="suc in getOrderedPreviewStocks()" :key="'preview-stock-pill-' + suc.id_sucursal">
                                                    <div class="preview-ecom-stock-pill" :class="{ 'is-current': isCurrentPreviewSucursal(suc), 'is-compact': !isCurrentPreviewSucursal(suc) }">
                                                        <span x-text="suc.nombre_sucursal"></span>
                                                        <strong x-text="formatMoney(suc.stock || 0)"></strong>
                                                    </div>
                                                </template>
                                            </div>
                                            <div class="preview-ecom-subprice">Haga click en el precio para ver los tipos de precio</div>
                                            <a href="#" @click.prevent="showPreviewPricesModal = true" class="preview-ecom-link mt-4 inline-block">Ver tipos de precio</a>

                                            <div class="mt-10">
                                                <div class="preview-ecom-section-title">Lo que necesitas saber de este producto</div>
                                                <ul class="preview-ecom-bullets">
                                                    <template x-for="(bullet, idx) in getPreviewFeatureBullets()" :key="'feat-' + idx">
                                                        <li x-text="bullet"></li>
                                                    </template>
                                                </ul>
                                            </div>

                                            <div class="preview-ecom-actions">
                                                <button
                                                    @click="addPreviewProductToCart()"
                                                    class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-500">
                                                    Agregar al carrito
                                                </button>
                                                <a href="#" @click.prevent="openPreviewEquivalentesEditor()" class="preview-ecom-link">Ver productos equivalentes</a>
                                                <a href="#" @click.prevent="showPreviewClientesModal = true" class="preview-ecom-link">Historico de Venta</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </section>

        </main>

        <div
            x-show="showPreviewPricesModal"
            x-cloak
            class="fixed inset-0 z-[120] flex items-center justify-center bg-black/75 backdrop-blur-sm"
            @keydown.escape.window="showPreviewPricesModal = false">
            <div
                x-show="showPreviewPricesModal"
                class="w-full max-w-3xl overflow-hidden rounded-3xl border border-slate-700 bg-slate-950 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-800 px-5 py-4">
                    <h3 class="text-xl font-bold text-slate-100">Tipos de Precio</h3>
                    <button @click="showPreviewPricesModal = false" class="text-2xl text-slate-400 hover:text-white">×</button>
                </div>
                <div class="max-h-[70vh] overflow-y-auto p-5">
                    <div class="space-y-3">
                        <template x-for="precio in getPreviewPriceList()" :key="'modal-price-' + precio.tipo">
                            <div class="flex items-center justify-between rounded-2xl bg-slate-900/80 px-5 py-4">
                                <span class="text-lg font-semibold uppercase text-slate-100" x-text="precio.nombre_tipo || ('Precio ' + precio.tipo)"></span>
                                <span class="text-2xl font-bold text-blue-400" x-text="formatMoney(precio.precio) + ' Gs'"></span>
                            </div>
                        </template>
                        <div x-show="!getPreviewPriceList().length" class="py-12 text-center text-slate-500">
                            Sin precios definidos
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div
            x-show="showPreviewStockModal"
            x-cloak
            class="fixed inset-0 z-[120] flex items-center justify-center bg-black/75 backdrop-blur-sm"
            @keydown.escape.window="showPreviewStockModal = false">
            <div
                x-show="showPreviewStockModal"
                class="w-full max-w-5xl overflow-hidden rounded-3xl border border-slate-700 bg-slate-950 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-800 px-5 py-4">
                    <h3 class="text-xl font-bold text-slate-100">Stock por Sucursal</h3>
                    <button @click="showPreviewStockModal = false" class="text-2xl text-slate-400 hover:text-white">×</button>
                </div>
                <div class="max-h-[70vh] overflow-y-auto p-5">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <template x-for="suc in searchPreviewDetail?.stock_por_sucursal || []" :key="'modal-stock-' + suc.id_sucursal">
                            <div class="rounded-2xl bg-slate-900/80 px-5 py-4">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <div class="text-xl font-semibold text-slate-100" x-text="suc.nombre_sucursal"></div>
                                        <div class="mt-1 text-sm text-slate-500" x-text="suc.ciudad"></div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <div :class="parseFloat(suc.stock) > 0 ? 'text-blue-400' : 'text-red-400'"
                                            class="text-2xl font-bold" x-text="parseFloat(suc.stock || 0).toFixed(0)">
                                        </div>
                                        <button @click.stop="openKardexModal(suc); showPreviewStockModal = false"
                                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-700/90 text-blue-300 transition-colors hover:bg-blue-900/40"
                                            title="Ver kardex de esta sucursal">
                                            <img src="/public/pos/assets/icons/numbered-list.svg" alt="Kardex"
                                                style="filter: invert(86%) sepia(15%) saturate(1026%) hue-rotate(183deg) brightness(98%) contrast(93%);"
                                                class="h-4 w-4 opacity-95">
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <div x-show="!searchPreviewDetail?.stock_por_sucursal?.length" class="col-span-full py-12 text-center text-slate-500">
                            Sin stock por sucursal
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div
            x-show="showPreviewEquivalentesModal"
            x-cloak
            class="fixed inset-0 z-[120] flex items-center justify-center bg-black/75 backdrop-blur-sm"
            @keydown.escape.window="showPreviewEquivalentesModal = false">
            <div
                x-show="showPreviewEquivalentesModal"
                class="w-[96vw] max-w-[1500px] overflow-hidden rounded-3xl border border-slate-700 bg-slate-950 shadow-2xl text-[13px] font-light text-slate-100">
                <div class="flex items-center justify-between border-b border-slate-800 px-5 py-4">
                    <div class="min-w-0">
                        <h3 class="truncate text-[18px] font-semibold text-slate-100">Productos Equivalentes</h3>
                        <p class="mt-1 truncate text-[13px] font-light text-cyan-300">
                            <span x-text="previewEquivEditor.form.cve_producto || 'Sin codigo'"></span>
                            <span class="mx-1">-</span>
                            <span x-text="previewEquivEditor.form.desproducto || 'Sin descripcion'"></span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="savePreviewEquivEditor()" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-[13px] font-medium text-white transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-60" :disabled="previewEquivEditor.loading || previewEquivEditor.saving || !previewEquivEditor.form.idproducto">
                            <i class="fas" :class="previewEquivEditor.saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                            <span x-text="previewEquivEditor.saving ? 'Guardando...' : 'Guardar'"></span>
                        </button>
                        <button @click="showPreviewEquivalentesModal = false" class="text-2xl text-slate-400 hover:text-white">×</button>
                    </div>
                </div>
                <div class="max-h-[78vh] overflow-y-auto p-5">
                    <div x-show="previewEquivEditor.loading" class="flex min-h-[320px] items-center justify-center text-[13px] font-light text-slate-400">
                        <div class="flex items-center gap-3">
                            <i class="fas fa-spinner fa-spin text-cyan-300"></i>
                            <span>Cargando editor de equivalencias...</span>
                        </div>
                    </div>
                    <div x-show="!previewEquivEditor.loading" class="space-y-4">
                        <div x-show="previewEquivEditor.error" class="rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-[13px] font-light text-red-200" x-text="previewEquivEditor.error"></div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button @click="previewEquivAgregarCodigoConversion()" type="button" class="inline-flex items-center gap-2 rounded-xl bg-violet-600 px-3 py-2 text-[13px] font-medium text-white transition hover:bg-violet-500">
                                <i class="fas fa-layer-group text-[11px]"></i>
                                <span>Agregar equivalente</span>
                            </button>
                            <button @click="previewEquivAgregarAplicacion()" type="button" class="inline-flex items-center gap-2 rounded-xl bg-cyan-600 px-3 py-2 text-[13px] font-medium text-white transition hover:bg-cyan-500">
                                <i class="fas fa-plus text-[11px]"></i>
                                <span>Agregar aplicacion</span>
                            </button>
                        </div>

                        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
                            <div class="overflow-hidden rounded-2xl border border-slate-700 bg-slate-950/95 shadow-[0_20px_60px_rgba(0,0,0,0.35)]">
                                <table class="w-full table-fixed border-collapse">
                                    <thead class="sticky top-0 z-10 bg-[#1d1d1d] text-white">
                                        <tr class="text-[11px] uppercase tracking-[0.24em]">
                                            <th class="px-4 py-3 text-left font-semibold">Equivalente</th>
                                        </tr>
                                    </thead>
                                </table>
                                <div class="max-h-[58vh] overflow-y-auto">
                                    <table class="w-full table-fixed border-collapse">
                                        <tbody class="bg-slate-950/95 text-slate-100">
                                            <tr x-show="previewEquivEquivalenciasAgrupadas().length === 0">
                                                <td class="px-6 py-10 text-center text-[13px] font-light text-slate-400">
                                                    <i class="fas fa-layer-group mb-2 block text-2xl"></i>
                                                    Sin equivalentes cargados
                                                </td>
                                            </tr>
                                            <template x-for="group in previewEquivEquivalenciasAgrupadas()" :key="'left-' + group.key">
                                                <tr class="align-top border-b border-slate-700/70">
                                                    <td class="px-3 py-3">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <input type="text"
                                                                   :value="group.marca_cod_conversion"
                                                                   list="preview-catalogo-marcas-cod-conversion"
                                                                   @input="previewEquivSetEquivalenciaGroupField(group, 'marca_cod_conversion', $event.target.value)"
                                                                   @blur="previewEquivEnsureCatalogValue('marcas_cod_conversion', $event.target.value)"
                                                                   class="w-full border-0 border-b border-slate-700 bg-transparent px-0 py-1 text-[13px] font-light uppercase leading-none text-[#7ae07d] focus:border-cyan-400 focus:ring-0">
                                                            <div class="flex items-center gap-1">
                                                                <button type="button" @click="savePreviewEquivEditor()" class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-600 text-emerald-300 hover:bg-slate-900" :disabled="previewEquivEditor.saving">
                                                                    <i class="fas" :class="previewEquivEditor.saving ? 'fa-spinner fa-spin text-[10px]' : 'fa-save text-[10px]'"></i>
                                                                </button>
                                                                <button type="button" @click="previewEquivEliminarEquivalenciaGrupo(group)" class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-600 text-red-300 hover:bg-slate-900">
                                                                    <i class="fas fa-minus text-[10px]"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="mt-2 space-y-1.5">
                                                            <template x-for="groupRow in group.conversionRows" :key="'left-conv-' + groupRow._idx">
                                                                <div class="flex items-center gap-1">
                                                                    <input type="text"
                                                                           x-model="previewEquivEditor.form.equivalentes[groupRow._idx].conversion"
                                                                           class="w-full border-0 border-b border-slate-700/70 bg-transparent px-0 py-1 text-[13px] font-light text-slate-100 focus:border-cyan-400 focus:ring-0">
                                                                    <button type="button" @click="previewEquivAgregarCodigoConversionEnGrupo(group)" class="flex h-6 w-6 items-center justify-center rounded-full border border-slate-600 text-cyan-300 hover:bg-slate-900">
                                                                        <i class="fas fa-plus text-[10px]"></i>
                                                                    </button>
                                                                    <button type="button" @click="previewEquivEliminarEquivalenciaFila(groupRow._idx)" class="flex h-6 w-6 items-center justify-center rounded-full border border-slate-600 text-red-300 hover:bg-slate-900">
                                                                        <i class="fas fa-minus text-[10px]"></i>
                                                                    </button>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 shadow-[0_20px_60px_rgba(0,0,0,0.35)]">
                                <div class="max-h-[58vh] overflow-auto">
                                    <div class="sticky top-0 z-10 grid grid-cols-[1.5fr_0.8fr_1.1fr_1fr_1fr_72px] bg-[#1d1d1d] text-[11px] uppercase tracking-[0.24em] text-white">
                                        <div class="border-r border-slate-700/80 px-4 py-3 font-semibold">Aplicacion</div>
                                        <div class="border-r border-slate-700/80 px-4 py-3 font-semibold">Ano</div>
                                        <div class="border-r border-slate-700/80 px-4 py-3 font-semibold">Modelo</div>
                                        <div class="border-r border-slate-700/80 px-4 py-3 font-semibold">Motor</div>
                                        <div class="border-r border-slate-700/80 px-4 py-3 font-semibold">Cod. motor</div>
                                        <div class="px-2 py-3 text-center font-semibold">Acc.</div>
                                    </div>
                                    <div class="bg-slate-900 text-slate-100">
                                        <div x-show="previewEquivAplicacionesAgrupadas().length === 0" class="px-6 py-10 text-center text-[13px] font-light text-slate-400">
                                            <i class="fas fa-car mb-2 block text-2xl"></i>
                                            Sin aplicaciones cargadas
                                        </div>
                                        <div class="divide-y divide-slate-700/70">
                                            <template x-for="group in previewEquivAplicacionesAgrupadas()" :key="group.key">
                                                <div x-show="group.showAppGroup" class="bg-slate-950/40">
                                                    <div class="grid grid-cols-[1.5fr_0.8fr_1.1fr_1fr_1fr_72px] items-start">
                                                        <div class="border-r border-slate-700/80 px-3 py-3">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <input type="text"
                                                                       :value="group.marca_aplicacion"
                                                                       list="preview-catalogo-marcas-aplicacion"
                                                                       @input="previewEquivSetAplicacionGroupField(group, 'marca_aplicacion', $event.target.value)"
                                                                       @blur="previewEquivEnsureCatalogValue('marcas_aplicacion', $event.target.value)"
                                                                       class="w-full border-0 border-b border-slate-700 bg-transparent px-0 py-1 text-[13px] font-light uppercase leading-none text-[#7ae07d] focus:border-cyan-400 focus:ring-0">
                                                                <div class="flex items-center justify-center gap-1">
                                                                    <button type="button" @click="savePreviewEquivEditor()" class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-600 text-emerald-300 hover:bg-slate-800" :disabled="previewEquivEditor.saving">
                                                                        <i class="fas" :class="previewEquivEditor.saving ? 'fa-spinner fa-spin text-[10px]' : 'fa-save text-[10px]'"></i>
                                                                    </button>
                                                                    <button type="button" @click="previewEquivAgregarAplicacionEnGrupo(group)" class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-600 text-cyan-300 hover:bg-slate-800">
                                                                        <i class="fas fa-plus text-[10px]"></i>
                                                                    </button>
                                                                    <button type="button" @click="previewEquivEliminarAplicacionGrupo(group)" class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-600 text-red-300 hover:bg-slate-800">
                                                                        <i class="fas fa-minus text-[10px]"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                            <div class="mt-2 text-[12px] font-light text-slate-400" x-text="group.detalle_resumen || 'Sin detalle'"></div>
                                                        </div>
                                                        <div class="col-span-5 divide-y divide-slate-800/70">
                                                            <template x-for="row in previewEquivGetAplicacionGroupRows(group)" :key="'app-row-' + row._idx">
                                                                <div class="grid grid-cols-[0.8fr_1.1fr_1fr_1fr_72px] items-center">
                                                                    <div class="border-r border-slate-800/70 px-3 py-2">
                                                                        <input type="text" x-model="previewEquivEditor.form.aplicaciones[row._idx].anio" list="preview-catalogo-anios-aplicacion" @blur="previewEquivEnsureCatalogValue('anios_aplicacion', $event.target.value)" class="w-full border-0 bg-transparent px-0 py-1 text-[13px] font-light text-slate-100 focus:ring-0">
                                                                    </div>
                                                                    <div class="border-r border-slate-800/70 px-3 py-2">
                                                                        <input type="text" x-model="previewEquivEditor.form.aplicaciones[row._idx].vehiculo_modelo" list="preview-catalogo-modelos-aplicacion" @blur="previewEquivEnsureCatalogValue('modelos_aplicacion', $event.target.value)" class="w-full border-0 bg-transparent px-0 py-1 text-[13px] font-light text-slate-100 focus:ring-0">
                                                                    </div>
                                                                    <div class="border-r border-slate-800/70 px-3 py-2">
                                                                        <input type="text" x-model="previewEquivEditor.form.aplicaciones[row._idx].motor" list="preview-catalogo-motores-aplicacion" @blur="previewEquivEnsureCatalogValue('motores_aplicacion', $event.target.value)" class="w-full border-0 bg-transparent px-0 py-1 text-[13px] font-light text-slate-100 focus:ring-0">
                                                                    </div>
                                                                    <div class="border-r border-slate-800/70 px-3 py-2">
                                                                        <input type="text" x-model="previewEquivEditor.form.aplicaciones[row._idx].codigo_motor" list="preview-catalogo-codigos-motor-aplicacion" @blur="previewEquivEnsureCatalogValue('codigos_motor_aplicacion', $event.target.value)" class="w-full border-0 bg-transparent px-0 py-1 text-[13px] font-light text-slate-100 focus:ring-0">
                                                                    </div>
                                                                    <div class="flex items-center justify-center px-2 py-2">
                                                                        <button type="button" @click="previewEquivEliminarAplicacionFila(row._idx)" class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-600 text-red-300 hover:bg-slate-800">
                                                                            <i class="fas fa-trash text-[10px]"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <datalist id="preview-catalogo-marcas-cod-conversion">
            <template x-for="item in previewEquivEditor.catMarcasCodConversion" :key="'eq-catalog-cod-' + item.id">
                <option :value="item.nombre"></option>
            </template>
        </datalist>
        <datalist id="preview-catalogo-marcas-aplicacion">
            <template x-for="item in previewEquivEditor.catMarcasAplicacion" :key="'eq-catalog-app-' + item.id">
                <option :value="item.nombre"></option>
            </template>
        </datalist>
        <datalist id="preview-catalogo-anios-aplicacion">
            <template x-for="item in previewEquivEditor.catAniosAplicacion" :key="'eq-catalog-anio-' + item.id">
                <option :value="item.nombre"></option>
            </template>
        </datalist>
        <datalist id="preview-catalogo-modelos-aplicacion">
            <template x-for="item in previewEquivEditor.catModelosAplicacion" :key="'eq-catalog-modelo-' + item.id">
                <option :value="item.nombre"></option>
            </template>
        </datalist>
        <datalist id="preview-catalogo-motores-aplicacion">
            <template x-for="item in previewEquivEditor.catMotoresAplicacion" :key="'eq-catalog-motor-' + item.id">
                <option :value="item.nombre"></option>
            </template>
        </datalist>
        <datalist id="preview-catalogo-codigos-motor-aplicacion">
            <template x-for="item in previewEquivEditor.catCodigosMotorAplicacion" :key="'eq-catalog-codmotor-' + item.id">
                <option :value="item.nombre"></option>
            </template>
        </datalist>

        <div
            x-show="showPreviewClientesModal"
            x-cloak
            class="fixed inset-0 z-[120] flex items-center justify-center bg-black/75 backdrop-blur-sm"
            @keydown.escape.window="showPreviewClientesModal = false">
            <div
                x-show="showPreviewClientesModal"
                class="w-full max-w-5xl overflow-hidden rounded-3xl border border-slate-700 bg-slate-950 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-800 px-5 py-4">
                    <h3 class="text-xl font-bold text-slate-100">Historico de Venta</h3>
                    <button @click="showPreviewClientesModal = false" class="text-2xl text-slate-400 hover:text-white">×</button>
                </div>
                <div class="max-h-[70vh] overflow-y-auto p-5">
                    <div class="overflow-hidden rounded-2xl bg-slate-900/70">
                        <table class="w-full text-sm">
                            <thead class="text-xs text-slate-400">
                                <tr>
                                    <th class="px-4 py-3 text-left">Cliente</th>
                                    <th class="px-4 py-3 text-right">Fecha</th>
                                    <th class="px-4 py-3 text-right">Cantidad</th>
                                    <th class="px-4 py-3 text-right">Precio</th>
                                    <th class="px-4 py-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="cli in searchPreviewDetail?.clientes_compraron || []" :key="'modal-cli-' + cli.id_cliente + '-' + cli.fecha">
                                    <tr class="border-t border-slate-800">
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-slate-100" x-text="cli.cliente_nombre"></div>
                                            <div class="text-xs text-slate-500" x-text="cli.cliente_ruc"></div>
                                            <div class="text-xs text-slate-500 truncate max-w-[260px]" x-text="cli.producto_descripcion || ''"></div>
                                        </td>
                                        <td class="px-4 py-3 text-right text-slate-400" x-text="formatDate(cli.fecha)"></td>
                                        <td class="px-4 py-3 text-right font-semibold text-slate-200" x-text="cli.cantidad"></td>
                                        <td class="px-4 py-3 text-right text-slate-300" x-text="formatMoney(cli.precio) + ' Gs'"></td>
                                        <td class="px-4 py-3 text-right font-bold text-blue-400" x-text="formatMoney(cli.importe) + ' Gs'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div x-show="!searchPreviewDetail?.clientes_compraron?.length" class="py-12 text-center text-slate-500">
                            No hay historial de ventas
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <template x-teleport="body">
        <div
            x-show="showGeneralSearchModal"
            x-cloak
            class="fixed inset-0 z-[140] flex items-center justify-center bg-black/88"
            @keydown.escape.window="showGeneralSearchModal = false">
            <div
                x-show="showGeneralSearchModal"
                class="flex h-[99dvh] max-h-[99dvh] w-[99dvw] max-w-[99dvw] flex-col overflow-hidden rounded-[24px] border border-slate-700 bg-slate-950 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-800 px-5 py-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-cyan-300"><?= htmlspecialchars(t('productos.title')) ?></div>
                        <div class="mt-1 text-lg font-bold text-white" x-text="searchQuery || <?= json_encode(t('common.search')) ?>"></div>
                    </div>
                    <button @click="showGeneralSearchModal = false" class="text-2xl text-slate-400 hover:text-white">×</button>
                </div>
                <div class="min-h-0 flex-1">
                    <iframe
                        x-show="showGeneralSearchModal"
                        :src="showGeneralSearchModal ? generalSearchModalUrl : 'about:blank'"
                        class="h-full w-full border-0"
                        loading="lazy"></iframe>
                </div>
            </div>
        </div>
        </template>

        <!-- Modal Pago Exitoso -->
        <div
            x-show="showSuccessModal"
            x-cloak
            class="fixed inset-0 bg-black/70 flex items-center justify-center z-50">
            <div class="bg-white dark:bg-slate-800 rounded-xl p-6 max-w-md w-full mx-4 text-center">
                <div class="text-6xl mb-4">✅</div>
                <h2 class="text-2xl font-bold mb-2"><?= htmlspecialchars($posContext['success_title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="text-slate-400 mb-4"><?= htmlspecialchars($posContext['success_subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
                <div class="bg-slate-50 dark:bg-slate-900 rounded-lg p-4 mb-4">
                    <div class="text-sm text-slate-400">Nro. Factura</div>
                    <div class="text-xl font-bold" x-text="lastVenta?.nro_factura"></div>
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-2" x-text="formatMoney(lastVenta?.total) + ' Gs'"></div>
                </div>
                <div class="flex gap-3">
                    <button
                        @click="printTicket()"
                        class="flex-1 bg-blue-600 hover:bg-blue-500 text-white font-bold py-2 rounded-lg">🖨️ Imprimir</button>
                    <button
                        @click="newSale()"
                        class="flex-1 bg-green-600 hover:bg-blue-600 text-white font-bold py-2 rounded-lg">➕ Nueva Venta</button>
                </div>
            </div>
        </div>

        <div
            x-show="showManagedDocumentActionsModal"
            x-cloak
            class="fixed inset-0 bg-black/80 flex items-center justify-center z-[105] backdrop-blur-sm p-4"
            @keydown.escape.window="closeManagedDocumentActionsModal()">
            <div class="w-full max-w-[560px] rounded-[28px] overflow-hidden border border-slate-600/40 bg-gradient-to-b from-slate-900/95 to-slate-800/95 shadow-2xl shadow-black/50">
                <div class="px-6 py-5 border-b border-slate-700/70 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-[28px] leading-none font-black tracking-wide uppercase text-white">Acciones de <span x-text="isLastDocumentPedido() ? 'Pedido' : 'Presupuesto'"></span></h2>
                        <p class="mt-2 text-sm text-slate-400">
                            <span x-text="lastVenta?.nro_factura || ('ID ' + (lastVenta?.id_factura || '-'))"></span>
                            ·
                            <span x-text="lastVenta?.cliente || lastVenta?.cliente_nombre || '-'"></span>
                        </p>
                    </div>
                    <button type="button" @click="closeManagedDocumentActionsModal()" class="w-11 h-11 rounded-full border border-slate-600 bg-slate-800/80 text-slate-200 hover:text-white hover:border-slate-500 transition-colors text-xl font-bold">×</button>
                </div>

                <div class="p-6 grid gap-5">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <button type="button" class="min-h-[112px] rounded-[18px] border border-blue-400/35 bg-gradient-to-b from-blue-600/20 to-blue-700/10 text-white font-black uppercase tracking-wide shadow-lg shadow-blue-500/15 hover:-translate-y-[1px] transition-all" @click="printManagedDocument()">Imprimir</button>
                        <button type="button" class="min-h-[112px] rounded-[18px] border border-emerald-400/35 bg-gradient-to-b from-emerald-600/18 to-emerald-700/10 text-white font-black uppercase tracking-wide shadow-lg shadow-emerald-500/15 hover:-translate-y-[1px] transition-all" @click="sendManagedDocumentByEmail()">Email</button>
                        <button type="button" class="min-h-[112px] rounded-[18px] border border-green-400/35 bg-gradient-to-b from-green-600/18 to-green-700/10 text-white font-black uppercase tracking-wide shadow-lg shadow-green-500/15 hover:-translate-y-[1px] transition-all" @click="sendManagedDocumentByWhatsApp()">WhatsApp</button>
                    </div>

                    <div class="grid gap-2">
                        <label class="text-xs font-black uppercase tracking-[0.16em] text-blue-300">Email</label>
                        <input type="email" x-model.trim="managedDocumentEmail" placeholder="cliente@correo.com" class="w-full rounded-[16px] border border-slate-600 bg-slate-800/70 px-4 py-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500/40">
                    </div>

                    <div class="grid gap-2">
                        <label class="text-xs font-black uppercase tracking-[0.16em] text-blue-300">WhatsApp</label>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <select x-model="managedDocumentCountryCode" class="w-full sm:w-[140px] rounded-[16px] border border-slate-600 bg-slate-800/70 px-4 py-4 text-white focus:outline-none focus:ring-2 focus:ring-blue-500/40">
                                <template x-for="country in countries" :key="'managed-country-' + country.code">
                                    <option :value="country.code" x-text="country.flag + ' +' + country.code"></option>
                                </template>
                            </select>
                            <input type="text" x-model.trim="managedDocumentPhone" placeholder="981123456" class="flex-1 rounded-[16px] border border-slate-600 bg-slate-800/70 px-4 py-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500/40">
                        </div>
                    </div>

                    <div class="rounded-[20px] border border-slate-700 bg-slate-900/45 p-4 grid gap-4">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-bold" :class="qzConnected ? 'text-emerald-400' : 'text-rose-400'" x-text="qzConnected ? ('Agent conectado (' + printProvider + ')') : 'Agent no conectado'"></span>
                            <button type="button" @click="refreshManagedDocumentPrinters()" class="ml-auto rounded-[14px] border border-slate-600 bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-700 transition-colors">Actualizar impresoras</button>
                        </div>
                        <div class="grid gap-2">
                            <label class="text-xs font-black uppercase tracking-[0.16em] text-blue-300">Formato</label>
                            <select x-model="managedDocumentPrintMode" @change="persistManagedDocumentPrintPreferences()" class="w-full rounded-[16px] border border-slate-600 bg-slate-800/70 px-4 py-3.5 text-white focus:outline-none focus:ring-2 focus:ring-blue-500/40">
                                <option value="auto">Auto detectar</option>
                                <option value="escpos">ESC/POS</option>
                                <option value="a4">Documento A4</option>
                                <option value="a5">Documento A5</option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <label class="text-xs font-black uppercase tracking-[0.16em] text-blue-300">Impresora</label>
                            <select x-model="managedDocumentPrinter" @change="persistManagedDocumentPrintPreferences()" class="w-full rounded-[16px] border border-slate-600 bg-slate-800/70 px-4 py-3.5 text-white focus:outline-none focus:ring-2 focus:ring-blue-500/40">
                                <option value="">Seleccionar impresora</option>
                                <template x-for="printer in _qzPrintersList" :key="'managed-printer-' + printer">
                                    <option :value="printer" x-text="printer"></option>
                                </template>
                            </select>
                        </div>
                        <div class="grid gap-2" x-show="managedDocumentPrintMode === 'escpos' || managedDocumentPrintMode === 'auto'">
                            <label class="text-xs font-black uppercase tracking-[0.16em] text-blue-300">Ancho ticket</label>
                            <select x-model="managedDocumentEscposWidth" @change="persistManagedDocumentPrintPreferences()" class="w-full rounded-[16px] border border-slate-600 bg-slate-800/70 px-4 py-3.5 text-white focus:outline-none focus:ring-2 focus:ring-blue-500/40">
                                <option value="32">58mm</option>
                                <option value="48">80mm</option>
                            </select>
                        </div>
                    </div>

                    <div class="text-sm leading-6 text-slate-400">
                        Si elegis `ESC/POS`, se imprime siempre por Sistemax Agent en la impresora seleccionada. `Auto detectar` usa heuristica por nombre; `Documento A4/A5` abre impresion web en ese formato. Email envia el PDF adjunto desde el sistema y WhatsApp abre `wa.me` con el mensaje listo.
                    </div>
                    <div x-show="managedDocumentStatus" class="text-sm font-bold" :class="managedDocumentStatusType === 'ok' ? 'text-emerald-400' : 'text-rose-400'" x-text="managedDocumentStatus"></div>
                </div>
            </div>
        </div>

        <!-- Modal de Ticket Impresión (Para modo Pendiente) -->
        <div
            x-show="showTicketModal"
            x-cloak
            class="fixed inset-0 bg-black/80 flex items-center justify-center z-[100] backdrop-blur-sm"
            @keydown.escape.window="closeTicketModal()">
            <div class="relative flex flex-col items-center gap-4 max-w-full">
                <div
                    class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl overflow-hidden flex flex-col"
                    :style="`width: ${getTicketModalWidthPx()}px; height: 85vh; max-width: 96vw;`">
                    <div class="bg-slate-100 dark:bg-slate-700 px-4 py-2 flex items-center justify-between gap-3 border-b border-slate-200 dark:border-slate-600">
                        <span class="text-xs font-black uppercase tracking-widest text-slate-500 flex items-center gap-2">
                            <span class="text-lg">📄</span>
                            <span x-text="webPrintModalMode ? 'Impresión Web' : 'Comprobante de Venta'"></span>
                        </span>
                        <div class="flex items-center gap-2 ml-auto">
                            <div x-show="webPrintModalMode && (isLastDocumentPresupuesto() || isLastDocumentPedido())" class="flex items-center gap-2">
                                <select
                                    x-show="qzConnected && _qzPrintersList.length"
                                    x-model="managedDocumentPrinter"
                                    @change="handleManagedDocumentPrinterChange()"
                                    class="bg-slate-800 text-white text-xs font-semibold border border-slate-600 rounded-xl px-3 py-2 max-w-[220px]">
                                    <option value="">Elegir impresora</option>
                                    <template x-for="printer in _qzPrintersList" :key="'managed-printer-' + printer">
                                        <option :value="printer" x-text="printer"></option>
                                    </template>
                                </select>
                                <button
                                    x-show="qzConnected"
                                    @click="refreshManagedDocumentPrinters()"
                                    class="bg-slate-700 hover:bg-slate-600 text-white font-bold py-2 px-3 rounded-xl text-xs transition-all shadow-lg shadow-slate-900/20 active:scale-[0.98]">
                                    Actualizar
                                </button>
                                <button
                                    @click="if(lastVenta) printManagedDocument()"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-3 rounded-xl text-xs transition-all shadow-lg shadow-blue-500/20 active:scale-[0.98]">
                                    Imprimir
                                </button>
                                <button
                                    @click="showEmailModal = true; emailInput = (lastVenta?.cliente_email || currentTicket?.selectedCliente?.email || '')"
                                    class="bg-slate-700 hover:bg-slate-600 text-white font-bold py-2 px-3 rounded-xl text-xs transition-all shadow-lg shadow-slate-900/20 active:scale-[0.98] flex items-center justify-center gap-2 border border-slate-600">
                                    <span>📧</span> Email
                                </button>
                                <button
                                    @click="prepareWhatsApp()"
                                    class="bg-[#25D366] hover:bg-[#128C7E] text-white font-bold py-2 px-3 rounded-xl text-xs transition-all shadow-lg shadow-green-500/20 active:scale-[0.98] flex items-center justify-center gap-2">
                                    <span>💬</span> WhatsApp
                                </button>
                            </div>
                            <button @click="closeTicketModal()" class="text-slate-400 hover:text-red-500 transition-colors text-xl font-bold p-1">✕</button>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto bg-white">
                        <iframe x-show="showTicketModal" id="webPrintFrame" :src="ticketUrl" class="w-full h-full min-h-[70vh] border-none"></iframe>
                    </div>
                </div>

                <!-- Botones fuera del bloque de comprobante -->
                <div x-show="!webPrintModalMode" class="flex gap-4 w-full justify-center flex-wrap">
                    <button
                        @click="printTicket()"
                        class="font-bold py-3 px-6 rounded-2xl text-sm transition-all flex items-center justify-center gap-2 border backdrop-blur-md active:scale-[0.98]"
                        :class="directPrintEnabled
                            ? 'bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border-emerald-500/30'
                            : 'bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border-amber-500/30'"
                        :title="directPrintEnabled
                            ? (qzConnected ? ('Impresión directa ESC/POS (' + printProvider + ') → ' + printerName) : 'Impresión local no conectada — click para configurar')
                            : 'Impresión directa desactivada. Se usará impresión web/PDF.'">
                        <svg xmlns="http://www.w3.org/2000/svg"
                             viewBox="0 0 24 24"
                             fill="none"
                             stroke="currentColor"
                             stroke-width="1.8"
                             class="w-5 h-5"
                             :class="qzConnected ? 'text-emerald-300' : 'text-slate-400'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 9V4.5h10.5V9m-10.5 6h10.5m-10.5 0v4.5h10.5V15m-12.75 0h15a1.5 1.5 0 0 0 1.5-1.5V10.5A1.5 1.5 0 0 0 19.5 9h-15A1.5 1.5 0 0 0 3 10.5v3A1.5 1.5 0 0 0 4.5 15Zm13.5-3h.008v.008H18V12Z"/>
                        </svg>
                        <span x-text="printerName || 'Imprimir'"></span>
                        <span x-show="directPrintEnabled && qzConnected" class="inline-flex shrink-0 items-center justify-center w-2.5 h-2.5 rounded-full bg-emerald-400 border border-white/30 shadow-[0_0_0_2px_rgba(16,185,129,.25)] animate-pulse" style="min-width:10px; min-height:10px;"></span>
                        <span x-show="directPrintEnabled && !qzConnected" class="inline-flex shrink-0 items-center justify-center w-2.5 h-2.5 rounded-full bg-slate-400 border border-white/30 shadow-[0_0_0_2px_rgba(148,163,184,.25)]" style="min-width:10px; min-height:10px;"></span>
                        <span x-show="!directPrintEnabled" class="inline-flex shrink-0 items-center justify-center w-2.5 h-2.5 rounded-full bg-amber-400 border border-white/30 shadow-[0_0_0_2px_rgba(251,191,36,.25)]" style="min-width:10px; min-height:10px;"></span>
                    </button>

                    <!-- Botón config impresora -->
                    <button
                        @click="showQzInstallModal = true"
                        class="bg-slate-500/20 hover:bg-slate-500/30 text-slate-300 font-bold py-3 px-4 rounded-2xl text-sm transition-all flex items-center justify-center gap-2 border border-slate-500/30 backdrop-blur-md active:scale-[0.98]"
                        title="Configurar impresión local">
                        <i class="fas fa-cog"></i>
                    </button>

                    <button
                        @click="prepareWhatsApp()"
                        class="bg-[#25D366] hover:bg-[#128C7E] text-white font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-green-500/20 active:scale-[0.98] flex items-center justify-center gap-2">
                        <span>💬</span> WhatsApp
                    </button>

                    <button
                        @click="showEmailModal = true; emailInput = (lastVenta?.cliente_email || '')"
                        class="bg-slate-700 hover:bg-slate-600 text-white font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-slate-900/20 active:scale-[0.98] flex items-center justify-center gap-2 border border-slate-600">
                        <span>📧</span> Email
                    </button>

                    <button
                        x-show="lastVenta && lastVenta.id_factura && userConfig.fe === 1 && isLastVentaEligibleForSifen()"
                        @click="convertirSifen()"
                        :disabled="loadingSifen"
                        class="bg-amber-500 hover:bg-amber-600 text-slate-900 font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-amber-500/20 active:scale-[0.98] flex items-center justify-center gap-2">
                        <span x-show="!loadingSifen" class="flex items-center gap-2">⚡ <span>Emitir FE</span></span>
                        <span x-show="loadingSifen" class="animate-spin text-lg">⏳</span>
                    </button>

                    <button
                        x-show="lastVenta && lastVenta.id_factura && userConfig.fe === 0"
                        @click="window.open(ticketUrl, '_blank')"
                        class="bg-slate-600 hover:bg-slate-700 text-white font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-slate-500/20 active:scale-[0.98] flex items-center justify-center gap-2">
                        <span>🖶️</span> Autoimpreso
                    </button>

                    <button
                        @click="closeTicketModal(); newSale()"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-blue-500/20 active:scale-[0.98]">
                        Nueva Venta
                    </button>
                    <button
                        @click="closeTicketModal()"
                        class="bg-slate-500/20 hover:bg-slate-500/30 text-slate-200 font-bold py-3 px-6 rounded-2xl text-sm transition-all border border-slate-500/30 backdrop-blur-md active:scale-[0.98]">
                        Salir
                    </button>
                </div>
                <div x-show="webPrintModalMode && !isLastDocumentPresupuesto() && !isLastDocumentPedido()" class="flex gap-3 w-full justify-center flex-wrap">
                    <button
                        @click="printWebTicketInModal()"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-blue-500/20 active:scale-[0.98]">
                        Imprimir
                    </button>
                    <button
                        @click="window.open(ticketUrl, '_blank')"
                        class="bg-slate-600 hover:bg-slate-700 text-white font-bold py-3 px-6 rounded-2xl text-sm transition-all shadow-xl shadow-slate-500/20 active:scale-[0.98]">
                        Abrir en pestaña
                    </button>
                    <button
                        @click="closeTicketModal()"
                        class="bg-slate-500/20 hover:bg-slate-500/30 text-slate-200 font-bold py-3 px-6 rounded-2xl text-sm transition-all border border-slate-500/30 backdrop-blur-md active:scale-[0.98]">
                        Salir
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal Preview de Venta (Antes de Confirmar) -->
        <div
            x-show="showPreviewModal"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-slate-950/90 backdrop-blur-md"
            @keydown.escape.window="showPreviewModal = false">
            <div
                @click.away="showPreviewModal = false"
                class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl border-2 border-green-500 w-full max-w-md max-h-[90vh] overflow-hidden flex flex-col">
                <!-- Header -->
                <div class="bg-gradient-to-r from-green-600 to-green-700 text-white p-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="text-3xl">📄</span>
                        <div>
                            <h2 class="text-xl font-black">PREVIEW DE VENTA</h2>
                            <p class="text-green-200 text-xs">Verifique antes de confirmar</p>
                        </div>
                    </div>
                    <button @click="showPreviewModal = false" class="text-white/70 hover:text-white text-2xl font-bold">&times;</button>
                </div>

                <!-- Content - Scrollable -->
                <div class="flex-1 overflow-y-auto p-4 space-y-4" style="font-family: 'Courier New', monospace; font-size: 11px;">

                    <!-- Empresa Header -->
                    <div class="text-center border-b border-dashed border-slate-300 dark:border-slate-600 pb-3">
                        <div class="font-black text-slate-900 dark:text-white text-sm uppercase"><?php echo htmlspecialchars($empresa['empresa'] ?? 'EMPRESA'); ?></div>
                        <div class="text-slate-500 text-[10px]">RUC: <?php echo htmlspecialchars($empresa['ruc'] ?? ''); ?>-<?php echo htmlspecialchars($empresa['dv'] ?? ''); ?></div>
                        <div class="inline-block bg-slate-900 dark:bg-white text-white dark:text-slate-900 text-[10px] font-black px-2 py-0.5 mt-1 rounded" x-text="selectedDocType === 'electro' ? 'FACTURA ELECTRÓNICA' : (selectedDocType === 'auto' ? 'FACTURA AUTOGRAFIADA' : 'NOTA DE CONTROL')"></div>
                        <div class="text-slate-400 text-[9px] mt-1" x-text="new Date().toLocaleString('es-PY')"></div>
                    </div>

                    <!-- Cliente -->
                    <div class="border-b border-slate-200 dark:border-slate-700 pb-2">
                        <div class="text-[9px] font-black text-slate-500 uppercase"><?= htmlspecialchars($posContext['counterpart_label'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="flex justify-between text-slate-900 dark:text-white">
                            <span x-text="currentTicket.selectedCliente?.nombre || <?= json_encode($posContext['counterpart_empty_name']) ?>"></span>
                            <span class="text-slate-500" x-text="currentTicket.selectedCliente?.ruc || <?= json_encode($posContext['counterpart_empty_doc']) ?>"></span>
                        </div>
                    </div>

                    <!-- Items -->
                    <div class="border-b border-slate-200 dark:border-slate-700 pb-2">
                        <div class="text-[9px] font-black text-slate-500 uppercase mb-1">Detalle</div>
                        <table class="w-full text-[10px]">
                            <thead>
                                <tr class="border-b border-slate-300 dark:border-slate-600">
                                    <th class="text-left py-0.5 text-slate-500">Cant</th>
                                    <th class="text-left py-0.5 text-slate-500">Descripción</th>
                                    <th class="text-right py-0.5 text-slate-500">P.Unit</th>
                                    <th class="text-right py-0.5 text-slate-500">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="item in cart" :key="item.id">
                                    <tr class="text-slate-900 dark:text-white">
                                        <td class="py-0.5" x-text="item.cantidad"></td>
                                        <td class="py-0.5 truncate max-w-[120px]" x-text="item.descripcion"></td>
                                        <td class="py-0.5 text-right" x-text="formatMoney(item.precio)"></td>
                                        <td class="py-0.5 text-right font-bold" x-text="formatMoney(item.precio * item.cantidad)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Totales -->
                    <div class="bg-slate-100 dark:bg-slate-900 rounded-lg p-3 space-y-1">
                        <div class="flex justify-between items-center text-lg font-black text-slate-900 dark:text-white border-b border-slate-300 dark:border-slate-700 pb-2">
                            <span>TOTAL:</span>
                            <span x-text="formatMoney(total) + ' Gs'"></span>
                        </div>

                        <!-- Detalle de Pagos -->
                        <template x-for="p in paymentsList" :key="p.method">
                            <div class="space-y-0.5">
                                <div class="flex justify-between text-sm text-slate-700 dark:text-slate-300">
                                    <span class="uppercase font-bold" x-text="p.name || p.method"></span>
                                    <span x-text="formatMoney(p.amount) + ' Gs'"></span>
                                </div>
                                <template x-if="p.method === 'efectivo' && p.cash_received > 0">
                                    <div class="text-[11px] text-slate-500 pl-2">
                                        <div class="flex justify-between">
                                            <span>Entregado:</span>
                                            <span class="font-bold" x-text="formatMoney(p.cash_received) + ' Gs'"></span>
                                        </div>
                                        <div class="flex justify-between text-green-600 font-bold" x-show="p.cash_change > 0">
                                            <span>VUELTO:</span>
                                            <span x-text="formatMoney(p.cash_change) + ' Gs'"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                </div>

                <!-- Footer Buttons -->
                <div class="p-4 bg-slate-50 dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 flex gap-3">
                    <button
                        @click="showPreviewModal = false"
                        class="flex-1 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-white font-bold py-3 rounded-xl transition-all">
                        ← VOLVER
                    </button>
                    <button
                        @click="showPreviewModal = false; handleFinishSale()"
                        :disabled="processingSale"
                        :class="editingVenta ? 'bg-amber-600 hover:bg-amber-700 shadow-amber-900/30' : 'bg-green-600 hover:bg-green-700 shadow-green-900/30'"
                        class="flex-[2] text-white font-black py-3 rounded-xl shadow-lg transition-all flex items-center justify-center gap-2 active:scale-[0.98]">
                        <span x-show="!processingSale" class="flex items-center gap-2">
                            <span class="text-xl" x-text="editingVenta ? '💾' : '✅'"></span>
                            <span x-text="editingVenta ? 'GUARDAR CAMBIOS' : 'CONFIRMAR VENTA'"></span>
                        </span>
                        <span x-show="processingSale" class="flex items-center gap-2">
                            <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Procesando...
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal Buscar Venta para Editar -->
        <div
            x-show="showEditSearchModal"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            class="fixed inset-0 z-[85] flex items-center justify-center p-4 bg-slate-950/90 backdrop-blur-md"
            @keydown.escape.window="showEditSearchModal = false">
            <div
                @click.away="showEditSearchModal = false"
                class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl border-2 border-amber-500 w-full max-w-5xl max-h-[85vh] overflow-hidden flex flex-col">
                <!-- Header -->
                <div class="bg-gradient-to-r from-amber-500 to-orange-600 text-white p-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="text-3xl">📋</span>
                        <div>
                            <h2 class="text-xl font-black">MIS VENTAS</h2>
                            <p class="text-amber-200 text-xs">Últimos 30 días - Solo notas editables</p>
                        </div>
                    </div>
                    <button @click="showEditSearchModal = false" class="text-white/70 hover:text-white text-2xl font-bold">&times;</button>
                </div>

                <!-- Search Input -->
                <div class="p-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
                        <input
                            type="text"
                            x-model="editSearchQuery"
                            @input.debounce.300ms="searchEditableVentas()"
                            placeholder="Buscar por nro. factura, cliente o fecha..."
                            class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl pl-10 pr-4 py-3 text-sm outline-none focus:border-amber-500 transition-all">
                    </div>
                    <div class="mt-2">
                        <select
                            x-model="editSifenFilter"
                            @change="searchEditableVentas()"
                            class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs outline-none focus:border-amber-500 transition-all">
                            <option value="todos">Todos</option>
                            <option value="comunes">Solo Comunes</option>
                            <option value="electronicas">Solo FE</option>
                            <option value="incompleto">SIFEN incompleto</option>
                        </select>
                    </div>
                </div>

                <!-- Results List -->
                <div class="flex-1 overflow-y-auto p-2">
                    <!-- Loading -->
                    <div x-show="loadingEditSearch" class="flex items-center justify-center py-8">
                        <svg class="animate-spin h-8 w-8 text-amber-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </div>

                    <!-- No Results -->
                    <div x-show="!loadingEditSearch && editSearchResults.length === 0" class="text-center py-8 text-slate-500">
                        <span class="text-4xl block mb-2">📋</span>
                        <p>No se encontraron notas de venta editables</p>
                        <p class="text-xs mt-1">Solo se pueden editar ventas de los últimos 30 días</p>
                    </div>

                    <!-- Results -->
                    <div x-show="!loadingEditSearch && editSearchResults.length > 0" class="space-y-2">
                        <template x-for="venta in editSearchResults" :key="venta.id_factura">
                            <div
                                class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3 text-left transition-all group">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-black text-slate-900 dark:text-white text-sm" x-text="venta.nro_factura"></div>
                                        <div class="text-[11px] text-slate-400 font-mono" x-text="'ID: ' + venta.id_factura"></div>
                                        <div class="text-xs text-slate-500" x-text="venta.cliente_nombre || 'Sin cliente'"></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-bold text-amber-600" x-text="formatMoney(venta.total) + ' Gs'"></div>
                                        <div class="text-[10px] text-slate-400" x-text="new Date(venta.fecha).toLocaleDateString('es-PY')"></div>
                                    </div>
                                </div>
                                <div class="mt-1 flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <span
                                            x-show="parseInt(venta.tipo_documento) !== 3"
                                            class="text-[9px] bg-amber-500/20 text-amber-200 px-2 py-1 rounded-md font-extrabold tracking-wide">NOTA DE VENTA</span>
                                        <span
                                            x-show="parseInt(venta.tipo_documento) === 3"
                                            class="text-[9px] bg-blue-500/20 text-blue-200 px-2 py-1 rounded-md font-extrabold tracking-wide">FACTURA ELECTRÓNICA</span>
                                        <span
                                            x-show="parseInt(venta.tipo_documento) === 3 && parseInt(venta.sifen_incompleto) === 1"
                                            class="text-[9px] bg-red-500/20 text-red-200 px-2 py-1 rounded-md font-extrabold tracking-wide">SIFEN INCOMPLETO</span>
                                        <span
                                            x-show="parseInt(venta.tipo_documento) === 3"
                                            :class="(
                                                String(venta.estado_sifen || '').toLowerCase() === 'aprobado'
                                                    ? 'bg-emerald-500/20 text-emerald-200'
                                                    : (String(venta.estado_sifen || '').toLowerCase() === 'rechazado'
                                                        ? 'bg-rose-500/20 text-rose-200'
                                                        : 'bg-cyan-500/20 text-cyan-200')
                                            )"
                                            class="text-[9px] px-2 py-1 rounded-md font-extrabold tracking-wide"
                                            x-text="'ESTADO: ' + (venta.estado_sifen || 'Pendiente')"></span>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button
                                            type="button"
                                            x-show="parseInt(venta.tipo_documento) === 3 && parseInt(venta.sifen_incompleto) === 1"
                                            @click="retrySifenFromList(venta)"
                                            class="px-3 py-1.5 rounded-lg text-[11px] font-black bg-red-600 text-white hover:bg-red-700 shadow-sm transition-all">
                                            ⚡ Reintentar SIFEN
                                        </button>
                                        <button
                                            type="button"
                                            x-show="parseInt(venta.tipo_documento) === 3"
                                            @click="consultSifenStatusFromList(venta)"
                                            class="px-3 py-1.5 rounded-lg text-[11px] font-black bg-cyan-600 text-white hover:bg-cyan-700 shadow-sm transition-all">
                                            🔎 Consultar estado
                                        </button>
                                        <button
                                            type="button"
                                            @click="reprintVentaFromList(venta)"
                                            class="px-3 py-1.5 rounded-lg text-[11px] font-black bg-blue-600 text-white hover:bg-blue-700 shadow-sm transition-all">
                                            🖨 Reimprimir
                                        </button>
                                        <button
                                            type="button"
                                            @click="loadVentaForEdit(venta.id_factura)"
                                            x-show="parseInt(venta.tipo_documento) === 0"
                                            class="px-3 py-1.5 rounded-lg text-[11px] font-black bg-amber-600 text-white hover:bg-amber-700 shadow-sm transition-all">
                                            ✏ Editar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Footer -->
                <div class="p-4 bg-slate-50 dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700">
                    <button
                        @click="showEditSearchModal = false"
                        class="w-full bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-white font-bold py-2 rounded-xl transition-all">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal Detalle de Producto -->
        <div
            x-show="showPresupuestoImportModal"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @keydown.escape.window="showPresupuestoImportModal = false"
            class="fixed inset-0 z-[90] flex items-start justify-center bg-slate-950/90 p-4 pt-8 backdrop-blur-md overflow-y-auto">
            <div
                x-show="showPresupuestoImportModal"
                @click.away="showPresupuestoImportModal = false"
                class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl border-2 border-indigo-500 w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col">
                <div class="bg-gradient-to-r from-indigo-600 to-indigo-700 text-white p-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="text-3xl">🧾</span>
                        <div>
                            <h2 class="text-xl font-black">IMPORTAR PRESUPUESTO</h2>
                            <p class="text-indigo-100 text-xs">Cargar cliente e items al ticket actual</p>
                        </div>
                    </div>
                    <button @click="showPresupuestoImportModal = false" class="text-white/70 hover:text-white text-2xl font-bold">&times;</button>
                </div>

                <div class="p-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
                        <input
                            type="text"
                            x-model="presupuestoSearchQuery"
                            @input.debounce.300ms="searchPresupuestos()"
                            placeholder="Buscar por nro. presupuesto, cliente o total..."
                            class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl pl-10 pr-4 py-3 text-sm outline-none focus:border-indigo-500 transition-all">
                    </div>
                    <div class="mt-2">
                        <select
                            x-model="presupuestoEstadoFilter"
                            @change="searchPresupuestos()"
                            class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs outline-none focus:border-indigo-500 transition-all">
                            <option value="activo">Solo activos</option>
                            <option value="anulado">Solo anulados</option>
                            <option value="todos">Todos</option>
                        </select>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto p-2">
                    <div x-show="loadingPresupuestoSearch" class="flex items-center justify-center py-8">
                        <svg class="animate-spin h-8 w-8 text-indigo-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </div>

                    <div x-show="!loadingPresupuestoSearch && presupuestoSearchResults.length === 0" class="text-center py-8 text-slate-500">
                        <span class="text-4xl block mb-2">🧾</span>
                        <p>No se encontraron presupuestos</p>
                    </div>

                    <div x-show="!loadingPresupuestoSearch && presupuestoSearchResults.length > 0" class="space-y-2">
                        <template x-for="presupuesto in presupuestoSearchResults" :key="'pres_' + presupuesto.id_factura">
                            <div class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-3 text-left transition-all group">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <div class="font-black text-slate-900 dark:text-white text-sm" x-text="presupuesto.nro_factura"></div>
                                        <div class="text-[11px] text-slate-400 font-mono" x-text="'ID: ' + presupuesto.id_factura"></div>
                                        <div class="text-xs text-slate-500" x-text="presupuesto.cliente_nombre || 'Cliente ocasional'"></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-bold text-indigo-600" x-text="formatMoney(presupuesto.total) + ' Gs'"></div>
                                        <div class="text-[10px] text-slate-400" x-text="new Date(presupuesto.fecha).toLocaleDateString('es-PY')"></div>
                                    </div>
                                </div>
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <span class="text-[9px] bg-indigo-500/20 text-indigo-200 px-2 py-1 rounded-md font-extrabold tracking-wide">PRESUPUESTO</span>
                                        <span
                                            :class="parseInt(presupuesto.estado || 1) === 1 ? 'bg-emerald-500/20 text-emerald-200' : 'bg-rose-500/20 text-rose-200'"
                                            class="text-[9px] px-2 py-1 rounded-md font-extrabold tracking-wide"
                                            x-text="parseInt(presupuesto.estado || 1) === 1 ? 'ACTIVO' : 'ANULADO'"></span>
                                    </div>
                                    <button
                                        type="button"
                                        @click="loadPresupuestoForImport(presupuesto.id_factura)"
                                        class="px-3 py-1.5 rounded-lg text-[11px] font-black bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm transition-all">
                                        📥 Importar
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="p-4 bg-slate-50 dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700">
                    <button
                        @click="showPresupuestoImportModal = false"
                        class="w-full bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-white font-bold py-2 rounded-xl transition-all">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal Detalle de Producto -->
        <div
            x-show="showProductDetailModal"
            x-cloak
            x-transition:enter="transition ease-out duration-75"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @keydown.escape.window="showProductDetailModal = false"
            class="fixed inset-0 bg-black/72 flex items-start justify-center z-50 p-4 pt-8 overflow-y-auto">
            <div
                x-show="showProductDetailModal"
                x-transition:enter="transition ease-out duration-75"
                x-transition:enter-start="opacity-0 translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0"
                @click.away="showProductDetailModal = false"
                class="bg-white dark:bg-slate-800 rounded-2xl w-full max-w-5xl max-h-[85vh] overflow-hidden flex flex-col shadow-xl ring-1 ring-white/10 my-auto">
                <!-- Header del Modal -->
                <div class="bg-gradient-to-r from-blue-600 to-purple-600 px-6 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-white dark:bg-slate-800/20 rounded-xl flex items-center justify-center text-2xl">📦</div>
                        <div>
                            <h2 class="text-xl font-bold" x-text="productDetail?.producto?.descripcion || 'Producto'"></h2>
                            <div class="flex items-center gap-3 text-sm text-slate-900 dark:text-white/80">
                                <span x-text="'Cód: ' + (productDetail?.producto?.codigo || '')"></span>
                                <span>•</span>
                                <span x-text="productDetail?.producto?.categoria || 'Sin categoría'"></span>
                            </div>
                        </div>
                    </div>
                    <button @click="showProductDetailModal = false" class="text-slate-900 dark:text-white/80 hover:text-slate-900 dark:text-white text-2xl">✕</button>
                </div>

                <!-- Contenido del Modal -->
                <div class="flex-1 overflow-y-auto p-6 custom-scroll">
                    <!-- Loading -->
                    <div x-show="loadingDetail" class="text-center py-10">
                        <div class="animate-spin text-4xl">⏳</div>
                        <p class="text-slate-400 mt-2">Cargando información...</p>
                    </div>

                    <div class="space-y-4" x-show="!loadingDetail && !detailContentReady">
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div class="rounded-xl bg-slate-50 dark:bg-slate-900 p-4 min-h-[280px] animate-pulse"></div>
                            <div class="rounded-xl bg-slate-50 dark:bg-slate-900 p-4 min-h-[280px] animate-pulse"></div>
                            <div class="rounded-xl bg-slate-50 dark:bg-slate-900 p-4 min-h-[280px] animate-pulse"></div>
                        </div>
                    </div>

                    <div class="space-y-6" x-show="!loadingDetail && detailContentReady && productDetail">
                        <!-- Fila Superior: Foto + Info Básica + Precios -->
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                            <!-- Foto del Producto -->
                            <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                                <div class="flex items-center justify-center">
                                    <div class="relative h-[220px] w-[220px] overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-800 shadow-md">
                                        <img
                                            x-show="getDetailPrimaryImageUrl()"
                                            :src="withImageVersion(getDetailPrimaryImageUrl(), productDetail?.producto?.imagen_updated || '')"
                                            :alt="productDetail?.producto?.descripcion || 'Imagen producto'"
                                            class="h-full w-full object-cover"
                                            loading="eager"
                                            decoding="async">
                                        <div x-show="!getDetailPrimaryImageUrl()" class="flex h-full w-full items-center justify-center text-6xl">
                                            📦
                                        </div>
                                    </div>
                                </div>
                                <div x-show="getDetailGalleryImages().length > 1" class="mt-4">
                                    <div class="flex gap-2 overflow-x-auto pb-1 pr-1 custom-scroll">
                                        <template x-for="(img, idx) in getDetailGalleryImages()" :key="(img.url || img.thumb_url || 'img') + '-' + idx">
                                            <button type="button"
                                                @click="selectDetailImage(img)"
                                                class="relative h-16 w-16 flex-shrink-0 overflow-hidden rounded-lg border transition-all bg-slate-100 dark:bg-slate-800/60"
                                                :class="isSelectedDetailImage(img) ? 'border-blue-500 ring-2 ring-blue-500/40' : 'border-slate-200 dark:border-slate-700 hover:border-slate-400 dark:hover:border-slate-500'">
                                                <span x-show="img.file_id"
                                                    @click.stop="deleteDetailImage(img)"
                                                    class="absolute top-1 right-1 z-10 inline-flex h-6 w-6 items-center justify-center rounded-full bg-red-600/90 text-white text-xs shadow hover:bg-red-500"
                                                    :class="deletingDetailImage ? 'opacity-50 pointer-events-none' : ''"
                                                    title="Suprimir imagen">✕</span>
                                                <img :src="withImageVersion(img.thumb_url || img.url || '', productDetail?.producto?.imagen_updated || '')"
                                                    :alt="productDetail?.producto?.descripcion || 'Imagen producto'"
                                                    class="w-full h-full object-cover"
                                                    loading="lazy"
                                                    decoding="async">
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <!-- Información Básica -->
                            <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                                <h3 class="text-sm font-semibold text-slate-400 mb-3 flex items-center gap-2">
                                    📋 Información
                                </h3>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-slate-400">Código:</span>
                                        <span class="font-mono text-blue-400" x-text="productDetail?.producto?.codigo"></span>
                                    </div>
                                    <div class="flex justify-between" x-show="productDetail?.producto?.marca_nombre">
                                        <span class="text-slate-400">Marca:</span>
                                        <span x-text="productDetail?.producto?.marca_nombre"></span>
                                    </div>
                                    <div class="flex justify-between" x-show="productDetail?.producto?.modelo_nombre">
                                        <span class="text-slate-400">Modelo:</span>
                                        <span x-text="productDetail?.producto?.modelo_nombre"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-400">IVA:</span>
                                        <span x-text="formatIvaLabel(productDetail?.producto?.tasa_iva)"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-400">Stock Mín:</span>
                                        <span x-text="productDetail?.producto?.stock_minimo || 0"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-slate-400">Stock Máx:</span>
                                        <span x-text="productDetail?.producto?.stock_maximo || 0"></span>
                                    </div>
                                    <div class="pt-2 mt-2 border-t border-slate-200 dark:border-slate-700">
                                        <label class="flex items-center justify-between gap-3 cursor-pointer">
                                            <span class="text-slate-400">Descontinuar:</span>
                                            <span class="inline-flex items-center gap-2">
                                                <input type="checkbox"
                                                    :checked="Number(productDetail?.producto?.descontinuado || 0) === 1"
                                                    :disabled="discontinuingProduct || Number(productDetail?.producto?.descontinuado || 0) === 1"
                                                    @change="toggleProductDiscontinuedFromDetail($event)"
                                                    class="rounded border-slate-500 text-amber-500 focus:ring-amber-500 bg-transparent disabled:opacity-50 disabled:cursor-not-allowed">
                                                <span class="text-xs font-semibold"
                                                    :class="Number(productDetail?.producto?.descontinuado || 0) === 1 ? 'text-amber-400' : 'text-slate-500'"
                                                    x-text="Number(productDetail?.producto?.descontinuado || 0) === 1 ? 'Descontinuado' : 'Activo'"></span>
                                            </span>
                                        </label>
                                    </div>
                                    <template x-if="productDetail?.codigos_barra?.length > 0">
                                        <div class="pt-2 border-t border-slate-200 dark:border-slate-700">
                                            <span class="text-slate-400 block mb-1">Códigos de barra:</span>
                                            <template x-for="cb in productDetail.codigos_barra" :key="cb">
                                                <span class="inline-block bg-slate-100 dark:bg-slate-700 px-2 py-1 rounded text-xs mr-1 mb-1 font-mono" x-text="cb"></span>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Precios Habilitados -->
                            <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                                <h3 class="text-sm font-semibold text-slate-400 mb-3 flex items-center gap-2">
                                    Precios Habilitados
                                </h3>
                                <div class="space-y-2">
                                    <template x-for="precio in productDetail?.precios || []" :key="precio.tipo">
                                        <div class="flex justify-between items-center bg-white dark:bg-slate-800 rounded-lg px-3 py-2">
                                            <span class="text-sm" x-text="precio.nombre_tipo || ('Precio ' + precio.tipo)"></span>
                                            <span class="font-bold text-blue-600 dark:text-blue-400" x-text="formatMoney(precio.precio) + ' Gs'"></span>
                                        </div>
                                    </template>
                                    <div x-show="!productDetail?.precios?.length" class="text-slate-500 text-sm text-center py-2">
                                        Sin precios definidos
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-6" x-show="productDetail?.heavy_loaded">
                            <!-- Fila: Stock por Sucursal -->
                            <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                                <h3 class="text-sm font-semibold text-slate-400 mb-3 flex items-center gap-2">
                                    🏢 Stock por Sucursal
                                </h3>
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                    <template x-for="suc in productDetail?.stock_por_sucursal || []" :key="suc.id_sucursal">
                                        <div class="bg-white dark:bg-slate-800 rounded-lg px-3 py-2 flex justify-between items-center">
                                            <div>
                                                <div class="font-medium text-sm" x-text="suc.nombre_sucursal"></div>
                                                <div class="text-xs text-slate-500" x-text="suc.ciudad"></div>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <div :class="parseFloat(suc.stock) > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-red-400'"
                                                    class="font-bold text-lg" x-text="parseFloat(suc.stock || 0).toFixed(0)">
                                                </div>
                                                <button @click.stop="openKardexModal(suc)"
                                                    :class="isDarkMode
                                                        ? 'bg-slate-700/90 hover:bg-blue-900/40 text-blue-300 border border-slate-600'
                                                        : 'bg-slate-100 hover:bg-blue-100 text-blue-700 border border-slate-300'"
                                                    class="w-7 h-7 rounded-md transition-colors flex items-center justify-center"
                                                    title="Ver kardex de esta sucursal">
                                                    <img src="/public/pos/assets/icons/numbered-list.svg" alt="Kardex"
                                                        :style="isDarkMode
                                                            ? 'filter: invert(86%) sepia(15%) saturate(1026%) hue-rotate(183deg) brightness(98%) contrast(93%);'
                                                            : 'filter: invert(33%) sepia(97%) saturate(1276%) hue-rotate(205deg) brightness(98%) contrast(97%);'"
                                                        :class="isDarkMode ? 'opacity-95' : 'opacity-90'"
                                                        class="w-3.5 h-3.5 transition-all duration-150">
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Fila: Equivalentes + Clientes -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                                <!-- Productos Equivalentes -->
                                <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                                    <h3 class="text-sm font-semibold text-slate-400 mb-3 flex items-center gap-2">
                                        🔄 Productos Equivalentes
                                        <span class="text-xs bg-blue-600/50 px-2 py-0.5 rounded-full" x-text="(productDetail?.equivalentes?.length || 0) + ' encontrados'"></span>
                                    </h3>
                                    <div class="max-h-48 overflow-y-auto custom-scroll space-y-2">
                                        <template x-for="eq in productDetail?.equivalentes || []" :key="eq.id">
                                            <div @click="addToCart(eq); showProductDetailModal = false"
                                                class="bg-white dark:bg-slate-800 hover:bg-slate-100 dark:bg-slate-700 rounded-lg px-3 py-2 cursor-pointer transition flex justify-between items-center">
                                                <div>
                                                    <div class="font-medium text-sm truncate" x-text="eq.descripcion"></div>
                                                    <div class="text-xs text-slate-500" x-text="'Cód: ' + eq.codigo"></div>
                                                </div>
                                                <div class="text-right">
                                                    <div class="text-blue-600 dark:text-blue-400 font-bold text-sm" x-text="formatMoney(eq.precio_venta) + ' Gs'"></div>
                                                    <div class="text-xs text-slate-500" x-text="'Stock: ' + (eq.stock || 0)"></div>
                                                </div>
                                            </div>
                                        </template>
                                        <div x-show="!productDetail?.equivalentes?.length" class="text-slate-500 text-sm text-center py-4">
                                            No hay productos equivalentes
                                        </div>
                                    </div>
                                </div>

                                <!-- Historico de Venta -->
                                <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                                    <h3 class="text-sm font-semibold text-slate-400 mb-3 flex items-center gap-2">
                                        👥 Historico de Venta
                                        <span class="text-xs bg-purple-600/50 px-2 py-0.5 rounded-full" x-text="formatNum(productDetail?.ventas_registradas || 0, 0, 0) + ' ventas'"></span>
                                    </h3>
                                    <div class="max-h-48 overflow-y-auto custom-scroll">
                                        <table class="w-full text-sm">
                                            <thead class="text-slate-400 text-xs">
                                                <tr>
                                                    <th class="text-left py-1">Cliente</th>
                                                    <th class="text-right py-1">Fecha</th>
                                                    <th class="text-right py-1">Cantidad</th>
                                                    <th class="text-right py-1">Precio</th>
                                                    <th class="text-right py-1">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="cli in productDetail?.clientes_compraron || []" :key="cli.id_cliente + '-' + cli.fecha">
                                                    <tr class="border-t border-slate-200 dark:border-slate-700/50">
                                                        <td class="py-2">
                                                            <div class="font-medium truncate max-w-[150px]" x-text="cli.cliente_nombre"></div>
                                                            <div class="text-xs text-slate-500" x-text="cli.cliente_ruc"></div>
                                                            <div class="text-xs text-slate-500 truncate max-w-[180px]" x-text="cli.producto_descripcion || ''"></div>
                                                        </td>
                                                        <td class="text-right text-xs text-slate-400" x-text="formatDate(cli.fecha)"></td>
                                                        <td class="text-right" x-text="cli.cantidad"></td>
                                                        <td class="text-right text-slate-500 dark:text-slate-300" x-text="formatMoney(cli.precio)"></td>
                                                        <td class="text-right text-blue-600 dark:text-blue-400" x-text="formatMoney(cli.importe)"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                        <div x-show="!productDetail?.clientes_compraron?.length" class="text-slate-500 text-sm text-center py-4">
                                            No hay historial de ventas
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-slate-50/80 dark:bg-slate-900/60 px-4 py-3 text-sm text-slate-500" x-show="!productDetail?.heavy_loaded">
                            Completando stock por sucursal, equivalentes e histórico...
                        </div>
                    </div>
                </div>

                <!-- Footer del Modal -->
                <div class="bg-slate-50 dark:bg-slate-900 px-6 py-4 flex justify-between items-center border-t border-slate-200 dark:border-slate-700">
                    <div>
                        <button
                            x-show="canOpenProductosModule()"
                            @click="openProductEditorFromDetail()"
                            class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-semibold rounded-lg transition flex items-center gap-2">
                            <i class="fas fa-edit"></i>
                            <span>Editar en Productos</span>
                        </button>
                    </div>
                    <div class="flex gap-3">
                        <button
                            @click="showProductDetailModal = false"
                            class="px-4 py-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-600 rounded-lg transition">Cerrar</button>
                        <button
                            @click="POSAudio.play('success'); addToCartFromDetail()"
                            class="w-full bg-green-600 hover:bg-blue-600 text-white font-bold py-4 rounded-xl shadow-lg transition-all transform active:scale-95 flex items-center justify-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-red-400 group-hover:text-red-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            <span>Agregar al Carrito</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Kardex por Sucursal -->
        <div
            x-show="showKardexModal"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @keydown.escape.window="showKardexModal = false"
            class="fixed inset-0 bg-black/75 backdrop-blur-sm flex items-center justify-center z-[90] p-4">
            <div
                x-show="showKardexModal"
                x-transition:enter="transition ease-out duration-200 delay-75"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                @click.away="showKardexModal = false"
                class="bg-white dark:bg-slate-800 rounded-2xl w-full max-w-6xl max-h-[85vh] overflow-hidden flex flex-col shadow-2xl ring-1 ring-white/10">

                <div class="bg-gradient-to-r from-blue-700 to-cyan-700 px-6 py-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">Kardex de Movimientos</h3>
                        <p class="text-sm text-white/80" x-text="(kardexProducto?.descripcion || '') + (kardexTab === 'global' ? ' • Global' : (kardexSucursal?.nombre ? (' • ' + kardexSucursal.nombre) : ''))"></p>
                    </div>
                    <button @click="showKardexModal = false" class="text-white/80 hover:text-white text-2xl leading-none">×</button>
                </div>

                <div class="flex-1 overflow-auto p-4">
                    <div class="mb-4 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            @click="switchKardexTab('sucursales')"
                            :class="kardexTab === 'sucursales' ? 'bg-blue-600 text-white border-blue-500' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 border-slate-300 dark:border-slate-600'"
                            class="rounded-lg border px-3 py-2 text-sm font-semibold transition-colors">
                            Sucursales
                        </button>
                        <button
                            type="button"
                            @click="switchKardexTab('global')"
                            :class="kardexTab === 'global' ? 'bg-blue-600 text-white border-blue-500' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 border-slate-300 dark:border-slate-600'"
                            class="rounded-lg border px-3 py-2 text-sm font-semibold transition-colors">
                            Kardex global
                        </button>
                        <div class="ml-auto text-xs text-slate-500 dark:text-slate-400" x-show="!loadingKardex">
                            Stock actual:
                            <span class="font-semibold text-slate-700 dark:text-slate-100" x-text="formatMoney(kardexStockActual || 0)"></span>
                        </div>
                    </div>

                    <div x-show="kardexTab === 'sucursales'" class="mb-4 flex flex-wrap gap-2">
                        <template x-for="suc in kardexSucursales" :key="'kardex-suc-' + suc.id_sucursal">
                            <button
                                type="button"
                                @click="selectKardexSucursal(suc)"
                                :class="Number(kardexSelectedSucursalId) === Number(suc.id_sucursal) ? 'bg-cyan-600 text-white border-cyan-500' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-300 dark:border-slate-600'"
                                class="rounded-lg border px-3 py-2 text-left transition-colors">
                                <div class="text-sm font-semibold" x-text="suc.nombre || suc.nombre_sucursal || ('Sucursal ' + suc.id_sucursal)"></div>
                                <div class="text-xs opacity-80" x-text="'Stock: ' + formatMoney(suc.stock || 0)"></div>
                            </button>
                        </template>
                    </div>

                    <div x-show="loadingKardex" class="text-center py-10">
                        <div class="animate-spin text-3xl">⏳</div>
                        <p class="text-slate-500 mt-2">Cargando kardex...</p>
                    </div>

                    <div x-show="!loadingKardex">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Fecha</th>
                                        <th x-show="kardexTab === 'global'" class="px-3 py-2 text-left">Sucursal</th>
                                        <th class="px-3 py-2 text-left">Tipo</th>
                                        <th class="px-3 py-2 text-right">Entrada</th>
                                        <th class="px-3 py-2 text-right">Salida</th>
                                        <th class="px-3 py-2 text-right">Precio</th>
                                        <th class="px-3 py-2 text-left">Factura</th>
                                        <th class="px-3 py-2 text-left">Usuario</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                    <template x-for="mov in kardexMovimientos" :key="(mov.id_mov || 0) + '_' + (mov.fecha || '')">
                                        <tr>
                                            <td class="px-3 py-2 text-slate-700 dark:text-slate-200" x-text="mov.fecha || '-'"></td>
                                            <td x-show="kardexTab === 'global'" class="px-3 py-2 text-slate-700 dark:text-slate-200" x-text="mov.sucursal_nombre || '-'"></td>
                                            <td class="px-3 py-2">
                                                <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                                    :class="mov.tipo_mov === 'Entrada' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : (mov.tipo_mov === 'Salida' ? 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300' : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-300')"
                                                    x-text="mov.tipo_mov || 'Ajuste'"></span>
                                            </td>
                                            <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-300" x-text="Number(mov.entrada || 0).toFixed(4)"></td>
                                            <td class="px-3 py-2 text-right text-rose-600 dark:text-rose-300" x-text="Number(mov.salida || 0).toFixed(4)"></td>
                                            <td class="px-3 py-2 text-right text-slate-700 dark:text-slate-200" x-text="formatMoney(mov.precio || 0)"></td>
                                            <td class="px-3 py-2 text-slate-700 dark:text-slate-200" x-text="mov.factura_text || '-'"></td>
                                            <td class="px-3 py-2 text-slate-500 dark:text-slate-300" x-text="mov.usuario_nombre || '-'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div x-show="kardexMovimientos.length === 0" class="text-center py-10 text-slate-500">
                            <span x-text="kardexTab === 'global' ? 'Sin movimientos para este producto en el kardex global.' : 'Sin movimientos para este producto en la sucursal seleccionada.'"></span>
                        </div>
                    </div>
                </div>

                <div class="px-4 py-3 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 flex justify-end">
                    <button @click="showKardexModal = false" class="px-4 py-2 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600">Cerrar</button>
                </div>
            </div>
        </div>

        <!-- Modal Buscar Imagen de Producto -->
        <div
            x-show="showImageSearchModal"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @keydown.escape.window="closeImageSearchModal()"
            class="fixed inset-0 bg-black/80 backdrop-blur-sm flex items-center justify-center z-[60] p-4">
            <div
                x-show="showImageSearchModal"
                x-transition:enter="transition ease-out duration-200 delay-75"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                @click.away="closeImageSearchModal()"
                class="bg-white dark:bg-slate-800 rounded-2xl w-full max-w-5xl max-h-[92vh] overflow-hidden shadow-2xl flex flex-col">
                
                <!-- Header -->
                <div class="bg-gradient-to-r from-blue-600 to-cyan-600 px-6 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
                            <i class="fas fa-camera text-white text-lg"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-white">Buscar Imagen</h2>
                            <p class="text-white/80 text-xs" x-text="imageSearchProduct?.descripcion || ''"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="closeImageSearchModal()"
                            class="rounded-xl bg-white/15 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/25">
                            Cancelar
                        </button>
                        <button
                            type="button"
                            @click="saveProductImage()"
                            :disabled="(!imageUrlInput && !imageBase64) || savingImage"
                            :class="((!imageUrlInput && !imageBase64) || savingImage) ? 'opacity-50 cursor-not-allowed' : ''"
                            class="rounded-xl bg-emerald-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-400 flex items-center justify-center gap-2">
                            <template x-if="savingImage">
                                <i class="fas fa-spinner fa-spin"></i>
                            </template>
                            <template x-if="!savingImage">
                                <i class="fas fa-save"></i>
                            </template>
                            <span x-text="savingImage ? 'Guardando...' : 'Guardar'"></span>
                        </button>
                        <button @click="closeImageSearchModal()" class="text-white/80 hover:text-white text-2xl leading-none ml-1">&times;</button>
                    </div>
                </div>

                <!-- Contenido -->
                <div class="flex-1 overflow-y-auto p-6 space-y-4 min-h-0">
                    <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-6 h-6 bg-amber-600 rounded-full flex items-center justify-center text-white text-xs font-bold">0</span>
                            <span class="font-semibold text-slate-700 dark:text-slate-300">Imágenes actuales</span>
                            <span class="text-xs bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 px-2 py-0.5 rounded-full" x-text="imageManagerImages.length + ' imagen(es)'"></span>
                        </div>

                        <div x-show="imageManagerLoading" class="py-6 text-center text-sm text-slate-500">
                            Cargando imágenes...
                        </div>

                        <div x-show="!imageManagerLoading && imageManagerImages.length > 0" class="grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-6">
                            <template x-for="img in imageManagerImages" :key="img.file_id || img.drive_file_id || img.url">
                                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                                    <div class="relative aspect-square bg-slate-100 dark:bg-slate-900">
                                        <img :src="img.thumb_url || img.small_url || img.url" class="h-full w-full object-cover">
                                        <span x-show="Number(img.principal || 0) === 1" class="absolute left-2 top-2 rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-bold text-white">Principal</span>
                                    </div>
                                    <div class="space-y-1.5 p-1.5">
                                        <button
                                            type="button"
                                            @click="setManagedImagePrincipal(img)"
                                            :disabled="Number(img.principal || 0) === 1 || imageManagerBusyFileId === (img.file_id || img.drive_file_id)"
                                            class="w-full rounded-lg bg-blue-600 px-1.5 py-1 text-[11px] font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                                            Principal
                                        </button>
                                        <button
                                            type="button"
                                            @click="deleteManagedImage(img)"
                                            :disabled="imageManagerBusyFileId === (img.file_id || img.drive_file_id)"
                                            class="w-full rounded-lg bg-rose-600 px-1.5 py-1 text-[11px] font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                                            Suprimir
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div x-show="!imageManagerLoading && imageManagerImages.length === 0" class="py-6 text-center text-sm text-slate-500">
                            Este producto todavía no tiene imágenes cargadas.
                        </div>
                    </div>

                    <!-- Alternativa: Buscar en sitios de terceros -->
                    <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold">1</span>
                            <span class="font-semibold text-slate-700 dark:text-slate-300">Buscar en sitios externos</span>
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <a :href="'https://www.google.com/search?tbm=isch&q=' + encodeURIComponent(imageSearchProduct?.descripcion || '')"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="w-full bg-gradient-to-r from-blue-500 to-cyan-500 hover:from-blue-600 hover:to-cyan-600 text-white font-semibold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all text-sm">
                                <i class="fab fa-google"></i>
                                Google Images
                                <i class="fas fa-external-link-alt text-xs"></i>
                            </a>
                            <a :href="'https://www.bing.com/images/search?q=' + encodeURIComponent(imageSearchProduct?.descripcion || '')"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="w-full bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-600 hover:to-teal-600 text-white font-semibold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all text-sm">
                                <i class="fas fa-image"></i>
                                Bing Images
                                <i class="fas fa-external-link-alt text-xs"></i>
                            </a>
                            <a :href="'https://duckduckgo.com/?iax=images&ia=images&q=' + encodeURIComponent(imageSearchProduct?.descripcion || '')"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="w-full bg-gradient-to-r from-orange-500 to-amber-500 hover:from-orange-600 hover:to-amber-600 text-white font-semibold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all text-sm">
                                <i class="fas fa-globe"></i>
                                DuckDuckGo
                                <i class="fas fa-external-link-alt text-xs"></i>
                            </a>
                        </div>
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-6 h-6 bg-indigo-600 rounded-full flex items-center justify-center text-white text-xs font-bold">2</span>
                            <span class="font-semibold text-slate-700 dark:text-slate-300">Buscar en URL de proveedor</span>
                        </div>
                        <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">
                            Pegue el dominio o URL del proveedor. Si la URL contiene `q=` o `search=` se usará directamente; si no, se abrirá una búsqueda del producto restringida a ese sitio.
                        </p>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <input
                                type="text"
                                x-model="supplierSearchUrl"
                                placeholder="Ej: proveedor.com o https://proveedor.com/buscar?q={q}"
                                class="flex-1 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">
                            <button
                                type="button"
                                @click="openSupplierSearch()"
                                class="rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">
                                Buscar proveedor
                            </button>
                        </div>
                    </div>

                    <!-- Pegar imagen o URL -->
                    <div class="bg-slate-50 dark:bg-slate-900 rounded-xl p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-6 h-6 bg-green-600 rounded-full flex items-center justify-center text-white text-xs font-bold">3</span>
                            <span class="font-semibold text-slate-700 dark:text-slate-300">Pegar imagen o URL</span>
                        </div>

                        <button
                            type="button"
                            @click="pasteImageFromClipboard()"
                            class="mb-3 w-full rounded-xl bg-green-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-green-700 flex items-center justify-center gap-2">
                            <i class="fas fa-paste"></i>
                            Pegar imagen
                        </button>
                        
                        <!-- Área para pegar imagen con Ctrl+V -->
                        <div 
                            x-ref="pasteArea"
                            @paste.window="handleImagePaste($event)"
                            @click="$refs.urlInput.focus()"
                            class="w-full min-h-[80px] px-4 py-3 bg-white dark:bg-slate-800 border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-xl text-sm cursor-pointer hover:border-green-400 dark:hover:border-green-500 transition-colors flex flex-col items-center justify-center gap-2"
                            :class="imageBase64 ? 'border-green-500 bg-green-50 dark:bg-green-900/20' : ''">
                            
                            <template x-if="!imageBase64 && !imageUrlInput">
                                <div class="text-center text-slate-500">
                                    <i class="fas fa-paste text-xl mb-1"></i>
                                    <p class="text-xs font-medium">Ctrl+V para pegar imagen</p>
                                </div>
                            </template>
                            
                            <template x-if="imageBase64">
                                <div class="text-center">
                                    <img :src="imageBase64" class="max-h-16 rounded-lg shadow mx-auto mb-1">
                                    <p class="text-xs text-green-600 dark:text-green-400 font-medium">
                                        <i class="fas fa-check-circle"></i> Imagen pegada
                                    </p>
                                </div>
                            </template>
                        </div>
                        
                        <div class="flex items-center gap-2 my-2">
                            <div class="flex-1 border-t border-slate-300 dark:border-slate-600"></div>
                            <span class="text-xs text-slate-400">o URL</span>
                            <div class="flex-1 border-t border-slate-300 dark:border-slate-600"></div>
                        </div>
                        
                        <input
                            x-ref="urlInput"
                            type="url"
                            x-model="imageUrlInput"
                            @input="imageBase64 = ''"
                            placeholder="https://ejemplo.com/imagen.jpg"
                            class="w-full px-3 py-2 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl text-sm focus:ring-2 focus:ring-green-500 focus:border-transparent">
                        
                        <!-- Preview de URL -->
                        <div x-show="imageUrlInput && !imageBase64" class="mt-2">
                            <div class="relative w-20 h-20 mx-auto bg-slate-200 dark:bg-slate-700 rounded-lg overflow-hidden">
                                <img x-ref="previewImg" 
                                     :src="imageUrlInput" 
                                     class="w-full h-full object-cover"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'"
                                     onload="this.style.display='block'; this.nextElementSibling.style.display='none'">
                                <div class="absolute inset-0 flex items-center justify-center text-slate-400" style="display:none">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Toast Notifications -->
        <div class="fixed bottom-4 right-4 z-[999] flex flex-col gap-2 pointer-events-none">
            <template x-for="(toast, index) in toasts" :key="toast.id">
                <div
                    x-show="toast.show"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-x-8"
                    x-transition:enter-end="opacity-100 translate-x-0"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-x-0"
                    x-transition:leave-end="opacity-0 translate-x-8"
                    :class="{
                    'bg-green-600': toast.type === 'success',
                    'bg-red-600': toast.type === 'error',
                    'bg-yellow-600': toast.type === 'warning',
                    'bg-blue-600': toast.type === 'info'
                }"
                    class="toast-no-glass px-4 py-3 rounded-lg shadow-xl text-slate-900 dark:text-white flex items-center gap-3 min-w-72 max-w-sm pointer-events-auto">
                    <span x-text="toast.type === 'success' ? '✅' : toast.type === 'error' ? '❌' : toast.type === 'warning' ? '⚠️' : 'ℹ️'" class="text-lg"></span>
                    <span class="flex-1" x-text="toast.message"></span>
                    <button
                        @click="copyToast(toast.message)"
                        class="text-xs px-2 py-1 rounded bg-slate-900/20 hover:bg-slate-900/30 dark:bg-white/20 dark:hover:bg-white/30">
                        Copiar
                    </button>
                    <button @click="removeToast(toast.id)" class="opacity-60 hover:opacity-100 text-lg">&times;</button>
                </div>
            </template>
        </div>

        <!-- Modal de Confirmación -->
        <div
            x-show="confirmModal.show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 backdrop-blur-sm"
            @click.self="cancelConfirm()">
            <div
                x-show="confirmModal.show"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100"
                class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl max-w-md w-full mx-4 overflow-hidden border border-slate-200 dark:border-slate-700">
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <span class="text-3xl" x-text="confirmModal.icon || '❓'"></span>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-2" x-text="confirmModal.title || 'Confirmar'"></h3>
                            <p class="text-slate-600 dark:text-slate-400 whitespace-pre-line" x-text="confirmModal.message"></p>
                        </div>
                    </div>
                </div>
                <div class="bg-slate-50 dark:bg-slate-900/50 px-6 py-4 flex justify-end gap-3">
                    <button
                        x-show="confirmModal.showCancel !== false"
                        @click="cancelConfirm()"
                        class="px-4 py-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-600 text-slate-900 dark:text-white rounded-lg transition-colors">Cancelar</button>
                    <button
                        @click="acceptConfirm()"
                        :class="confirmModal.type === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'"
                        class="px-4 py-2 text-slate-900 dark:text-white rounded-lg transition-colors font-medium"
                        x-text="confirmModal.confirmText || 'Aceptar'"></button>
                </div>
            </div>
        </div>

        <!-- Modal Informativo (sin botones) -->
        <div
            x-show="infoModal.show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div
                x-show="infoModal.show"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100"
                class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl max-w-md w-full mx-4 overflow-hidden border border-slate-200 dark:border-slate-700">
                <div class="p-6">
                    <div class="flex items-center gap-4">
                        <div x-show="infoModal.loading" class="animate-spin rounded-full h-8 w-8 border-4 border-blue-600 border-t-transparent"></div>
                        <span x-show="!infoModal.loading" class="text-3xl" x-text="infoModal.icon"></span>
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1" x-text="infoModal.title"></h3>
                            <p class="text-slate-600 dark:text-slate-400 whitespace-pre-line text-sm" x-html="infoModal.message"></p>
                        </div>
                    </div>
                </div>
                <div x-show="!infoModal.loading && infoModal.type !== 'loading'" class="bg-slate-50 dark:bg-slate-900/50 px-6 py-3 flex justify-end">
                    <button
                        @click="closeInfoModal()"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors font-medium text-sm">Aceptar</button>
                </div>
            </div>
        </div>

        <!-- Modal de Cobro (Checkout) -->
        <div
            x-show="showCheckoutModal"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm"
            @keydown.escape.window="if(showCheckoutModal) { if(selectedPaymentMethod) { selectedPaymentMethod = null; } else { showCheckoutModal = false; } }"
            @keydown.arrow-down.window.prevent="if(showCheckoutModal && !selectedPaymentMethod) navigateCheckout('ArrowDown')"
            @keydown.arrow-up.window.prevent="if(showCheckoutModal && !selectedPaymentMethod) navigateCheckout('ArrowUp')"
            @keydown.arrow-left.window.prevent="if(showCheckoutModal && !selectedPaymentMethod) navigateCheckout('ArrowLeft')"
            @keydown.arrow-right.window.prevent="if(showCheckoutModal && !selectedPaymentMethod) navigateCheckout('ArrowRight')"
            @keydown.enter.window.prevent="if(showCheckoutModal) { if(selectedPaymentMethod) { confirmUnifiedPayment(); } else { selectFocusedItem(); } }">
            <div
                @click.away="showCheckoutModal = false"
                class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
                <!-- Header Modal -->
                <div class="bg-slate-100 dark:bg-slate-700/30 p-4 border-b border-slate-200 dark:border-slate-600 flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <div class="bg-blue-600/20 p-2 rounded-lg text-blue-600 dark:text-blue-400 text-xl font-black">$</div>
                        <div>
                            <h2 class="text-lg font-bold text-slate-900 dark:text-white leading-tight"><?= htmlspecialchars($posContext['checkout_title'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p class="text-[10px] text-slate-400 uppercase tracking-tighter">Click derecho para fijar</p>
                        </div>
                    </div>
                    <button @click="showCheckoutModal = false" class="text-slate-400 hover:text-slate-900 dark:text-white transition-colors text-2xl px-2">×</button>
                </div>

                <div class="p-4 overflow-y-auto custom-scroll flex-1">
                    <div class="space-y-6 max-w-xl mx-auto">


                        <div x-show="isPresupuestoMode()" class="bg-slate-50 dark:bg-slate-900/50 rounded-2xl border-2 border-dashed border-slate-300 dark:border-slate-700 p-4 space-y-4">
                            <div class="flex justify-between items-center bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700">
                                <div>
                                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest block">Total Presupuesto</span>
                                    <span class="text-3xl font-black text-slate-900 dark:text-white" x-text="formatMoney(total)"></span>
                                </div>
                                <div class="text-right text-xs text-slate-500 dark:text-slate-400 max-w-[180px]">
                                    Se guardará el presupuesto sin cobro ni impresión automática.
                                </div>
                            </div>
                            <button
                                @click="editingVenta ? updateVenta() : confirmSale()"
                                :disabled="processingSale"
                                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-4 rounded-xl shadow-lg shadow-blue-900/20 transition-all flex items-center justify-center gap-3 transform active:scale-95">
                                <span x-show="!processingSale" class="text-lg" x-text="editingVenta ? 'GUARDAR CAMBIOS' : 'GUARDAR PRESUPUESTO'"></span>
                                <span x-show="processingSale" class="flex items-center gap-2">
                                    <svg class="animate-spin h-6 w-6" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    Guardando...
                                </span>
                            </button>
                        </div>

                        <!-- Resumen de Pagos e Interfaz Multi-Medio -->
                        <div x-show="!isPresupuestoMode()" class="bg-slate-50 dark:bg-slate-900/50 rounded-2xl border-2 border-dashed border-slate-300 dark:border-slate-700 p-4 space-y-4">
                            <div class="flex justify-between items-center bg-white dark:bg-slate-800 p-3 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700">
                                <div>
                                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest block">Total Venta</span>
                                    <span class="text-2xl font-black text-slate-900 dark:text-white" x-text="formatMoney(total)"></span>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] font-black uppercase tracking-widest block"
                                        :class="(remainingAmount - (selectedPaymentMethod ? cashAmountReceived : 0)) < 0 ? 'text-green-500' : 'text-slate-500'"
                                        x-text="(remainingAmount - (selectedPaymentMethod ? cashAmountReceived : 0)) < 0 ? 'Vuelto' : 'Faltante'"></span>
                                    <span class="text-2xl font-black"
                                        :class="(remainingAmount - (selectedPaymentMethod ? cashAmountReceived : 0)) > 0 ? 'text-red-500' : 'text-green-500'"
                                        x-text="formatMoney(Math.abs(remainingAmount - (selectedPaymentMethod ? cashAmountReceived : 0)))"></span>
                                </div>
                            </div>

                            <!-- Lista de Pagos Agregados -->
                            <div x-show="paymentsList.length > 0" class="space-y-2">
                                <template x-for="(p, pidx) in paymentsList" :key="pidx">
                                    <div class="bg-blue-600/10 border border-blue-500/30 p-2 rounded-lg group animate-in fade-in slide-in-from-left-2">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <div class="bg-blue-600 text-white text-[10px] font-black px-1.5 py-0.5 rounded capitalize" x-text="p.name || p.method"></div>
                                                <span class="text-sm font-bold text-slate-900 dark:text-white" x-text="formatMoney(p.amount)"></span>
                                                <template x-if="p.voucher_number || p.transfer_reference || p.qr_transaction_code">
                                                    <span class="text-[10px] text-slate-500 italic" x-text="'Ref: ' + (p.voucher_number || p.transfer_reference || p.qr_transaction_code)"></span>
                                                </template>
                                            </div>
                                            <button @click="paymentsList.splice(pidx, 1); POSAudio.play('warning')" class="text-slate-500 hover:text-red-500 transition-colors p-1">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                        <!-- Detalle de Efectivo: Entregado y Vuelto -->
                                        <template x-if="p.method === 'efectivo' && p.cash_received > 0">
                                            <div class="mt-1 pt-1 border-t border-blue-500/20 grid grid-cols-2 gap-2 text-[11px]">
                                                <div class="flex justify-between">
                                                    <span class="text-slate-400">Entregado:</span>
                                                    <span class="font-bold text-slate-200" x-text="formatMoney(p.cash_received) + ' Gs'"></span>
                                                </div>
                                                <div class="flex justify-between" x-show="p.cash_change > 0">
                                                    <span class="text-green-400 font-bold">Vuelto:</span>
                                                    <span x-text="formatMoney(p.cash_change) + ' Gs'"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            <!-- Boton Finalizar Venta -->
                            <div x-show="remainingAmount <= 0 && paymentsList.length > 0">
                                <button
                                    @click="editingVenta ? updateVenta() : confirmSale()"
                                    :disabled="processingSale"
                                    :class="editingVenta ? 'bg-amber-600 hover:bg-amber-700' : 'bg-green-600 hover:bg-green-700'"
                                    class="w-full text-white font-black py-4 rounded-xl shadow-lg shadow-green-900/20 transition-all flex items-center justify-center gap-3 transform active:scale-95">
                                    <span x-show="!processingSale" class="text-lg" x-text="editingVenta ? 'GUARDAR CAMBIOS' : <?= json_encode($posContext['confirm_button_text']) ?>"></span>
                                    <span x-show="processingSale" class="flex items-center gap-2">
                                        <svg class="animate-spin h-6 w-6" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                        Procesando...
                                    </span>
                                </button>
                            </div>
                        </div>

                        <!-- Panel de Entrada de Pago (Unificado) -->
                        <div x-show="selectedPaymentMethod && !isPresupuestoMode()" class="bg-blue-600/5 dark:bg-blue-600/10 rounded-2xl border border-blue-500/30 p-4 space-y-4 animate-in zoom-in-95 duration-200">
                            <div class="flex items-center gap-3 mb-2">
                                <h3 class="text-lg font-bold text-slate-900 dark:text-white uppercase tracking-tight" x-text="'Detalle de ' + (paymentMethods.find(m => m.id === selectedPaymentMethod)?.name || '')"></h3>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Monto -->
                                <div>
                                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Monto a Pagar</label>
                                    <div class="relative group">
                                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-sm">Gs.</span>
                                        <input
                                            type="text"
                                            inputmode="numeric"
                                            :value="formatNum(cashAmountReceived, 0, 0)"
                                            @input="cashAmountReceived = parseNum($event.target.value)"
                                            @keydown.enter.prevent="confirmUnifiedPayment()"
                                            x-ref="unifiedAmountInput"
                                            class="w-full bg-white dark:bg-slate-900 border border-slate-700 focus:border-blue-500 rounded-xl px-10 py-3 text-2xl font-black text-slate-900 dark:text-white outline-none transition-all shadow-inner">
                                    </div>
                                </div>

                                <!-- Campos Específicos -->
                                <div class="space-y-3">
                                    <!-- Tarjeta -->
                                    <div x-show="selectedPaymentMethod === 'tarjeta'">
                                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Nro. de Boucher / Lote</label>
                                        <input type="text" x-model="voucherNumber" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none" placeholder="000XXX">
                                    </div>

                                    <!-- Transferencia -->
                                    <div x-show="selectedPaymentMethod === 'transferencia'">
                                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Referencia / Observación</label>
                                        <input type="text" x-model="transferReference" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none" placeholder="Nro de Transacción">
                                    </div>

                                    <!-- PIX -->
                                    <div x-show="selectedPaymentMethod === 'pix'">
                                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Código PIX / Confirmación</label>
                                        <input type="text" x-model="qrTransactionCode" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none" placeholder="Transacción PIX">
                                    </div>

                                    <!-- Crédito -->
                                    <div x-show="selectedPaymentMethod === 'credito'" class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Cuotas</label>
                                            <input type="number" min="1" x-model="creditInstallments" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                                        </div>
                                        <div>
                                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Vto. 1ra Cuota</label>
                                            <input type="date" x-model="creditDueDate" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-2 text-xs font-bold text-slate-900 dark:text-white outline-none">
                                        </div>
                                        <div>
                                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Interes Normal % Mes</label>
                                            <input type="number" min="0" step="0.01" x-model="creditInterestPct" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                                        </div>
                                        <div>
                                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Interes Moratorio % Mes</label>
                                            <input type="number" min="0" step="0.01" x-model="creditMoraPct" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                                        </div>
                                        <div>
                                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Dias de Gracia</label>
                                            <input type="number" min="0" step="1" x-model="creditGraceDays" class="w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                                        </div>
                                    </div>

                                </div>
                            </div>

                            <div class="flex gap-2">
                                <button
                                    @click="confirmUnifiedPayment()"
                                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-black py-3 rounded-xl shadow-lg transition-all transform active:scale-95">AGREGAR PAGO</button>
                                <button
                                    @click="selectedPaymentMethod = null"
                                    class="px-6 bg-slate-200 hover:bg-slate-300 text-slate-700 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 font-bold py-3 rounded-xl transition-all">CANCELAR</button>
                            </div>
                        </div>

                        <!-- Botones de Medio de Pago -->
                        <div x-show="remainingAmount > 0 && !selectedPaymentMethod && !userConfig.isVendedor && !isPresupuestoMode()" class="animate-in fade-in slide-in-from-top-4 duration-300">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 block">Seleccionar Medio de Pago</label>
                            <div class="grid grid-cols-3 gap-2">
                                <template x-for="(pm, index) in paymentMethodsAvailable" :key="pm.id">
                                    <button
                                        @mousedown="startLongPress('payment', pm.id)"
                                        @mouseup="endLongPress()"
                                        @mouseleave="endLongPress()"
                                        @touchstart.passive="startLongPress('payment', pm.id)"
                                        @touchend="endLongPress()"
                                        @touchcancel="endLongPress()"
                                        @click="if(!wasLongPress()) selectUnifiedMethod(pm)"
                                        @contextmenu.prevent
                                        :class="{
                                        'bg-blue-600 border-blue-400 ring-2 ring-white/50 scale-[1.01]': focusedCheckoutSection === 'paymentMethods' && focusedCheckoutIdx === index,
                                        'bg-slate-100 dark:bg-slate-700 border-slate-600 opacity-90 hover:opacity-100': !(focusedCheckoutSection === 'paymentMethods' && focusedCheckoutIdx === index)
                                    }"
                                        class="flex flex-col items-center justify-center p-3 rounded-xl border-2 transition-all gap-1.5 h-24 text-center relative overflow-hidden group">
                                        <span class="text-[11px] font-black text-slate-900 dark:text-white uppercase leading-tight px-1" x-text="pm.name"></span>

                                        <div x-show="defaultPaymentMethod === pm.id" class="absolute top-0 right-0 z-10">
                                            <span class="text-[9px] bg-amber-500 text-white px-1.5 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important;">⭐ FIJADO</span>
                                        </div>

                                        <div class="absolute inset-0 bg-blue-600 opacity-0 group-hover:opacity-10 transition-opacity pointer-events-none"></div>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <!-- Feedback de Procesamiento -->
                        <div x-show="processingSale" class="flex flex-col items-center justify-center py-4 bg-slate-50 dark:bg-slate-900/50 rounded-xl border border-blue-500/30 gap-2 animate-pulse">
                            <div class="animate-spin h-8 w-8 border-4 border-blue-400 border-t-transparent rounded-full"></div>
                            <span class="text-blue-400 font-black text-[10px] uppercase tracking-widest" x-text="isPresupuestoMode() ? 'Guardando Presupuesto...' : 'Procesando Venta...'"></span>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Modal de Espera UENO -->
            <div
                x-show="showUenoModal"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                class="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-blue-900/60 backdrop-blur-md">
                <div class="bg-white dark:bg-slate-800 rounded-3xl p-8 max-w-sm w-full text-center shadow-2xl border border-slate-700">
                    <div class="mb-6 relative">
                        <div class="w-20 h-20 bg-blue-600 rounded-full flex items-center justify-center mx-auto animate-bounce shadow-lg">
                            <span class="text-4xl text-white font-black">UENO</span>
                        </div>
                        <!-- Spinner alrededor -->
                        <div class="absolute inset-0 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin w-24 h-24 -top-2 -left-2 mx-auto"></div>
                    </div>

                    <h3 class="text-2xl font-black text-slate-800 dark:text-white mb-2 italic">¡Esperando Pago!</h3>
                    <p class="text-slate-500 dark:text-slate-400 text-sm mb-6 leading-relaxed">
                        Hemos abierto la pasarela de pagos de <b>ueno</b> en una ventana nueva.
                        Por favor, complete la transacción allí.
                    </p>

                    <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-2xl mb-6 border border-blue-200 dark:border-blue-700">
                        <span class="text-[10px] font-black uppercase tracking-widest text-blue-500 block mb-1">Estado en tiempo real</span>
                        <span class="text-sm font-bold text-blue-700 dark:text-blue-300">Sincronizando con Webhook...</span>
                    </div>

                    <button
                        @click="showUenoModal = false; clearCart();"
                        class="w-full bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-600 dark:text-white font-bold py-3 rounded-xl transition-all">
                        Cerrar Ventana y Nueva Venta
                    </button>
                    <p class="text-[9px] text-slate-400 mt-4 uppercase tracking-tighter">La factura se generará automáticamente al confirmar</p>
                </div>
            </div>

            <!-- Modal de Contraseña Admin -->
            <div
                x-show="passwordModal.show"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
                @click.self="cancelPassword()">
                <div
                    x-show="passwordModal.show"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-90"
                    x-transition:enter-end="opacity-100 scale-100"
                    class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl max-w-sm w-full mx-4 overflow-hidden border border-slate-200 dark:border-slate-700">
                    <div class="p-6">
                        <div class="text-center mb-4">
                            <span class="text-4xl">🔐</span>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mt-2" x-text="passwordModal.title || 'Autorización Requerida'"></h3>
                            <p class="text-slate-400 text-sm mt-1" x-text="passwordModal.message || 'Ingrese la contraseña del administrador'"></p>
                        </div>
                        <div class="mb-4">
                            <label class="text-sm text-slate-400 mb-1 block">Contraseña del Administrador</label>
                            <input
                                type="password"
                                x-model="passwordModal.password"
                                @keydown.enter="verifyAdminPassword()"
                                @focus="$el.removeAttribute('readonly')"
                                x-ref="adminPasswordInput"
                                class="w-full bg-slate-100 dark:bg-slate-700 border border-slate-600 rounded-lg px-4 py-3 text-center text-lg tracking-widest focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="••••••"
                                autocomplete="off"
                                autocorrect="off"
                                autocapitalize="off"
                                spellcheck="false"
                                data-lpignore="true"
                                data-form-type="other"
                                readonly>
                        </div>
                        <p x-show="passwordModal.error" class="text-red-400 text-sm text-center mb-3" x-text="passwordModal.error"></p>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-900/50 px-6 py-4 flex justify-end gap-3">
                        <button
                            @click="cancelPassword()"
                            class="px-4 py-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-600 text-slate-900 dark:text-white rounded-lg transition-colors">Cancelar</button>
                        <button
                            @click="verifyAdminPassword()"
                            :disabled="passwordModal.verifying"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white dark:text-white rounded-lg transition-colors font-medium disabled:opacity-50">
                            <span x-show="!passwordModal.verifying">Autorizar</span>
                            <span x-show="passwordModal.verifying">Verificando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <script>
            // Global Audio System (Outside Alpine to bypass reactivity overhead)
            const POSAudio = {
                ctx: null,
                muted: localStorage.getItem('pos_sound_muted') === 'true',
                sounds: {
                    success: {
                        freq: 880,
                        duration: 0.1,
                        type: 'sine',
                        vol: 0.15
                    },
                    error: {
                        freq: 220,
                        duration: 0.2,
                        type: 'square',
                        vol: 0.1
                    },
                    warning: {
                        freq: 440,
                        duration: 0.15,
                        type: 'triangle',
                        vol: 0.12
                    },
                    info: {
                        freq: 660,
                        duration: 0.08,
                        type: 'sine',
                        vol: 0.1
                    }
                },
                init() {
                    if (this.ctx) return;
                    try {
                        this.ctx = new(window.AudioContext || window.webkitAudioContext)();
                        const resume = () => {
                            if (this.ctx && this.ctx.state === 'suspended') this.ctx.resume();
                        };
                        ['click', 'keydown', 'touchstart'].forEach(e => document.addEventListener(e, resume, {
                            once: true
                        }));
                        console.log('🔈 Audio System initialized (muted: ' + this.muted + ')');
                    } catch (e) {
                        console.warn('AudioContext not available');
                    }
                },
                toggleMute() {
                    this.muted = !this.muted;
                    localStorage.setItem('pos_sound_muted', this.muted.toString());
                    console.log('🔊 Sound ' + (this.muted ? 'muted' : 'unmuted'));
                    return this.muted;
                },
                play(type) {
                    // Si está muteado, no reproducir
                    if (this.muted) return;

                    const startTime = performance.now();
                    if (!this.ctx) this.init();

                    try {
                        if (this.ctx && this.ctx.state === 'suspended') {
                            this.ctx.resume();
                        }

                        const sound = this.sounds[type] || this.sounds.info;
                        const ctx = this.ctx;
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();

                        osc.connect(gain);
                        gain.connect(ctx.destination);

                        osc.type = sound.type;
                        osc.frequency.setValueAtTime(sound.freq, ctx.currentTime);

                        gain.gain.setValueAtTime(sound.vol, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + sound.duration);

                        osc.start(ctx.currentTime);
                        osc.stop(ctx.currentTime + sound.duration);

                        const endTime = performance.now();
                        console.log(`🎵 Sound [${type}] triggered in ${(endTime - startTime).toFixed(2)}ms`);
                    } catch (e) {
                        console.error('❌ Audio Error:', e);
                    }
                }
            };

            // ===== SISTEMA DE IMÁGENES DE PRODUCTOS =====
            const RUTA_IMAGEN_MERCADERIAS = '<?php echo addslashes($rutaImagenMercaderias ?? ''); ?>';
            console.log('📸 Ruta de imágenes configurada:', RUTA_IMAGEN_MERCADERIAS);

            // ===== SISTEMA LONG PRESS PARA BUSCAR IMÁGENES EN GOOGLE =====
            let longPressTimer = null;
            let longPressProductId = null;
            let longPressProductDesc = null;
            let longPressElement = null;
            const LONG_PRESS_DURATION = 600; // 600ms para activar

            function startLongPress(event, productId, productDesc) {
                event.preventDefault();
                event.stopPropagation();
                
                // Guardar referencia al producto
                longPressProductId = productId;
                longPressProductDesc = productDesc;
                longPressElement = event.currentTarget;
                
                // Agregar clase de animación
                if (longPressElement) {
                    longPressElement.classList.add('pressing');
                }
                
                // Iniciar timer
                longPressTimer = setTimeout(() => {
                    // Long press completado - abrir Google Images
                    openGoogleImagesSearch(productId, productDesc);
                    cancelLongPress();
                }, LONG_PRESS_DURATION);
            }

            function cancelLongPress() {
                if (longPressTimer) {
                    clearTimeout(longPressTimer);
                    longPressTimer = null;
                }
                if (longPressElement) {
                    longPressElement.classList.remove('pressing');
                    longPressElement = null;
                }
                longPressProductId = null;
                longPressProductDesc = null;
            }

            function openGoogleImagesSearch(productId, productDesc) {
                // Guardar en sessionStorage para saber qué producto estamos editando
                sessionStorage.setItem('pendingImageProduct', JSON.stringify({
                    id: productId,
                    descripcion: productDesc,
                    timestamp: Date.now()
                }));
                
                // Mostrar notificación
                showImagePasteNotification(productDesc);
                
                // Abrir Google Images en nueva pestaña
                const searchQuery = encodeURIComponent(productDesc);
                const googleUrl = `https://www.google.com/search?tbm=isch&q=${searchQuery}`;
                window.open(googleUrl, '_blank');
            }

            function showImagePasteNotification(productDesc) {
                // Crear notificación flotante
                const notification = document.createElement('div');
                notification.id = 'paste-image-notification';
                notification.innerHTML = `
                    <div class="fixed bottom-20 left-1/2 transform -translate-x-1/2 bg-gradient-to-r from-blue-600 to-purple-600 text-white px-6 py-4 rounded-2xl shadow-2xl z-[9999] max-w-md animate-bounce-in">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center text-2xl">📋</div>
                            <div class="flex-1">
                                <div class="font-bold">Copia una imagen de Google</div>
                                <div class="text-sm text-white/80">Luego vuelve aquí y presiona <kbd class="bg-white/20 px-2 py-0.5 rounded">Ctrl+V</kbd></div>
                                <div class="text-xs text-white/60 mt-1 truncate">Producto: ${productDesc}</div>
                            </div>
                            <button onclick="this.parentElement.parentElement.parentElement.remove()" class="text-white/60 hover:text-white text-xl">&times;</button>
                        </div>
                    </div>
                `;
                
                // Remover notificación anterior si existe
                const existing = document.getElementById('paste-image-notification');
                if (existing) existing.remove();
                
                document.body.appendChild(notification);
                
                // Auto-remover después de 15 segundos
                setTimeout(() => {
                    if (notification.parentElement) notification.remove();
                }, 15000);
            }

            // Listener global para pegar imágenes
            document.addEventListener('paste', async function(event) {
                const pendingProduct = JSON.parse(sessionStorage.getItem('pendingImageProduct') || 'null');
                
                // Solo procesar si hay un producto pendiente y no pasaron más de 5 minutos
                if (!pendingProduct || (Date.now() - pendingProduct.timestamp > 300000)) {
                    return;
                }
                
                const items = event.clipboardData?.items;
                if (!items) return;
                
                for (let item of items) {
                    if (item.type.startsWith('image/')) {
                        event.preventDefault();
                        
                        const blob = item.getAsFile();
                        if (!blob) continue;
                        
                        console.log('📷 Imagen detectada en clipboard para producto:', pendingProduct.id);
                        
                        // Convertir a base64
                        const reader = new FileReader();
                        reader.onload = async function(e) {
                            const base64 = e.target.result;
                            
                            // Subir imagen
                            await uploadProductImage(pendingProduct.id, base64);
                            
                            // Limpiar producto pendiente
                            sessionStorage.removeItem('pendingImageProduct');
                            
                            // Remover notificación
                            const notification = document.getElementById('paste-image-notification');
                            if (notification) notification.remove();
                        };
                        reader.readAsDataURL(blob);
                        break;
                    }
                }
            });

            async function uploadProductImage(productId, base64Image) {
                try {
                    const loadingToast = showToast('⏳ Subiendo imagen...', 'info', 0);

                    const alpineEl = document.querySelector('[x-data]');
                    const alpineData = alpineEl ? Alpine.$data(alpineEl) : null;
                    const idEmpresa = Number(alpineData?.idEmpresa || localStorage.getItem('id_empresa') || 0);

                    const blob = await (await fetch(base64Image)).blob();
                    const form = new FormData();
                    form.append('action', 'upload');
                    form.append('idproducto', String(productId));
                    form.append('id_empresa', String(idEmpresa));
                    form.append('imagen', blob, `paste_${productId}_${Date.now()}.png`);

                    const response = await fetch('/public/productos/api/imagen.php', {
                        method: 'POST',
                        body: form
                    });
                    const result = await response.json();
                    console.log('📷 Respuesta upload R2:', result);
                    
                    // Remover loading toast
                    if (loadingToast && loadingToast.remove) loadingToast.remove();
                    
                    if (result.success) {
                        await syncProductImagesFromServer(productId, idEmpresa, alpineData);
                        showToast('✅ Imagen agregada correctamente', 'success');
                    } else {
                        showToast('❌ Error: ' + (result.error || 'No se pudo guardar'), 'error');
                    }
                } catch (error) {
                    console.error('Error subiendo imagen:', error);
                    showToast('❌ Error de conexión', 'error');
                }
            }

            async function syncProductImagesFromServer(productId, idEmpresa, alpineData = null) {
                if (!idEmpresa) return;
                const res = await fetch(`/public/productos/api/imagen.php?action=list&idproducto=${encodeURIComponent(productId)}&id_empresa=${encodeURIComponent(idEmpresa)}`, { cache: 'no-store' });
                const listData = await res.json();
                const list = Array.isArray(listData?.data) ? listData.data : [];
                const principal = list.find((img) => Number(img?.principal) === 1) || list[0] || null;
                const patch = {
                    imagen: sanitizeMalformedVariantUrl(principal?.small_url || principal?.url || ''),
                    imagen_thumb: sanitizeMalformedVariantUrl(principal?.thumb_url || principal?.url || ''),
                    imagen_small: sanitizeMalformedVariantUrl(principal?.small_url || principal?.url || ''),
                    imagen_medium: sanitizeMalformedVariantUrl(principal?.medium_url || principal?.url || ''),
                    imagen_large: sanitizeMalformedVariantUrl(principal?.large_url || principal?.url || ''),
                    imagen_updated: Date.now(),
                    imagenes: Array.from(new Set(
                        list.map((img) => sanitizeMalformedVariantUrl(img?.small_url || img?.url || '')).filter(Boolean)
                    ))
                };

                const data = alpineData || (document.querySelector('[x-data]') ? Alpine.$data(document.querySelector('[x-data]')) : null);
                if (!data) return;
                const applyPatch = (arr) => Array.isArray(arr)
                    ? arr.map((p) => String(p?.id) === String(productId) ? ({ ...p, ...patch }) : p)
                    : arr;

                data.productos = applyPatch(data.productos);
                data.popularProducts = applyPatch(data.popularProducts);
                data.cart = applyPatch(data.cart);

                // Forzar re-render de tarjetas HTML generadas con x-html.
                if (Array.isArray(data.productos)) data.productos = [...data.productos];
                if (Array.isArray(data.popularProducts)) data.popularProducts = [...data.popularProducts];
            }

            function syncProductImageFromCache(productId, cacheUrl, alpineData = null) {
                const raw = String(cacheUrl || '').trim();
                if (!raw) return;
                const normalized = raw.startsWith('/_lib') ? '/public' + raw : raw;
                const versioned = normalized + (normalized.includes('?') ? '&' : '?') + 't=' + Date.now();
                const patch = {
                    imagen: versioned,
                    imagen_thumb: versioned,
                    imagen_small: versioned,
                    imagen_medium: versioned,
                    imagen_large: versioned,
                    imagen_updated: Date.now(),
                    imagenes: [versioned]
                };

                const data = alpineData || (document.querySelector('[x-data]') ? Alpine.$data(document.querySelector('[x-data]')) : null);
                if (!data) return;
                const applyPatch = (arr) => Array.isArray(arr)
                    ? arr.map((p) => String(p?.id) === String(productId) ? ({ ...p, ...patch }) : p)
                    : arr;

                data.productos = applyPatch(data.productos);
                data.popularProducts = applyPatch(data.popularProducts);
                data.cart = applyPatch(data.cart);

                if (Array.isArray(data.productos)) data.productos = [...data.productos];
                if (Array.isArray(data.popularProducts)) data.popularProducts = [...data.popularProducts];
                if (Array.isArray(data.cart)) data.cart = [...data.cart];
            }

            function showToast(message, type = 'info', duration = 3000) {
                const colors = {
                    success: 'bg-green-600',
                    error: 'bg-red-600',
                    info: 'bg-blue-600',
                    warning: 'bg-yellow-600'
                };
                
                const toast = document.createElement('div');
                toast.className = `toast-no-glass fixed bottom-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-xl shadow-2xl z-[9999] animate-slide-in`;
                if (type === 'warning') {
                    toast.classList.remove('text-white');
                    toast.classList.add('text-slate-900');
                }
                toast.innerHTML = message;
                document.body.appendChild(toast);
                
                if (duration > 0) {
                    setTimeout(() => toast.remove(), duration);
                }
                
                return toast;
            }

            const AVATAR_COLORS = [
                '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8',
                '#F7DC6F', '#BB8FCE', '#85C1E2', '#F8B88B', '#AAD8A7',
                '#FF8C94', '#A8E6CF', '#FDCB6E', '#6C5CE7', '#00B894'
            ];

            // Generar color basado en texto (hash)
            function getAvatarColor(text) {
                let hash = 0;
                for (let i = 0; i < text.length; i++) {
                    hash = text.charCodeAt(i) + ((hash << 5) - hash);
                }
                return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
            }

            // Generar avatar SVG con inicial
            function generateProductAvatar(name, size = 80) {
                const initial = (name || 'P').charAt(0).toUpperCase();
                const color = getAvatarColor(name);
                const fontSize = Math.round(size * 0.45);
                return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg">
                    <rect width="${size}" height="${size}" rx="8" fill="${color}"/>
                    <text x="50%" y="50%" text-anchor="middle" dy="0.35em" fill="white" font-family="system-ui" font-size="${fontSize}" font-weight="700">${initial}</text>
                </svg>`;
            }

            function sanitizeMalformedVariantUrl(url) {
                return String(url || '').replace(/\.webp_(thumb|small|medium|large)\.webp/gi, '.webp');
            }

            function buildVariantFallbackChain(url) {
                const base = sanitizeMalformedVariantUrl(url);
                if (!base) return [];
                const candidates = [];
                const push = (u) => { if (u && !candidates.includes(u)) candidates.push(u); };
                push(base);
                push(base.replace(/_small\.webp(\?.*)?$/i, '_medium.webp$1'));
                push(base.replace(/_small\.webp(\?.*)?$/i, '_large.webp$1'));
                push(base.replace(/_medium\.webp(\?.*)?$/i, '_large.webp$1'));
                return candidates.filter(Boolean);
            }

            // Seleccionar mejor variante de imagen según tamaño de display
            // thumb=120px, small=320px, medium=640px, large=1280px
            function getBestProductImageUrl(producto, numericSize) {
                const p = producto || {};
                if (numericSize <= 80) {
                    return sanitizeMalformedVariantUrl(p.imagen_thumb || p.imagen_small || p.imagen || p.foto_small_url || p.foto_url || '');
                }
                if (numericSize <= 240) {
                    return sanitizeMalformedVariantUrl(p.imagen_small || p.imagen || p.imagen_medium || p.foto_small_url || p.foto_url || '');
                }
                return sanitizeMalformedVariantUrl(p.imagen_medium || p.imagen_small || p.imagen || p.foto_small_url || p.foto_url || '');
            }

            // Obtener imágenes distintas del producto (para carrusel)
            function getDistinctProductImages(producto) {
                const p = producto || {};
                const list = Array.isArray(p.imagenes) ? p.imagenes : [];
                return list.map(u => sanitizeMalformedVariantUrl(u)).filter(Boolean);
            }

            // Función helper para obtener HTML de imagen de producto
            // size puede ser número (px) o 'full' para 100% ancho
            // Long press abre Google Images con búsqueda automática
            function getProductImageHTML(producto, size = 80, cssClass = '', options = {}) {
                if (!producto || !producto.id) return '';
                const enableCarousel = options?.enableCarousel !== false;
                const suppressImage = options?.suppressImage === true;

                const isFullWidth = size === 'full';
                const sizeValue = isFullWidth ? 100 : size;
                const numericSize = isFullWidth ? 320 : (typeof size === 'number' ? size : 80);
                const avatar = generateProductAvatar(producto.descripcion || 'Producto', sizeValue);
                const avatarDataUri = `data:image/svg+xml;base64,${btoa(avatar)}`;
                const productDesc = (producto.descripcion || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

                if (suppressImage) {
                    return `<div class="relative ${cssClass} long-press-container"
                                 style="${isFullWidth ? 'width: 100%; aspect-ratio: 1/1;' : `width: ${size}px; height: ${size}px; flex-shrink: 0;`}">
                        <img src="${avatarDataUri}" 
                             alt="${productDesc}"
                             class="w-full h-full object-cover rounded-lg"
                             style="position: absolute; top: 0; left: 0;"
                             data-avatar="true" />
                    </div>`;
                }

                // Seleccionar variante óptima según tamaño de display
                const bestUrl = getBestProductImageUrl(producto, numericSize);
                const distinctImages = getDistinctProductImages(producto);

                const addVersion = (u) => {
                    const base = u.startsWith('/_lib') ? '/public' + u : u;
                    const sep = base.includes('?') ? '&' : '?';
                    return `${base}${sep}v=${encodeURIComponent(producto.imagen_updated || Date.now())}`;
                };

                // Estilos según el modo
                const containerStyle = isFullWidth ?
                    'width: 100%; aspect-ratio: 1/1;' :
                    `width: ${size}px; height: ${size}px; flex-shrink: 0;`;

                // Atributos para long press (sin botón visible)
                const longPressAttrs = `
                    onmousedown="startLongPress(event, ${producto.id}, '${productDesc}')"
                    onmouseup="cancelLongPress()"
                    onmouseleave="cancelLongPress()"
                    ontouchstart="startLongPress(event, ${producto.id}, '${productDesc}')"
                    ontouchend="cancelLongPress()"
                    ontouchcancel="cancelLongPress()"
                    style="cursor: pointer; ${containerStyle}"
                    title="Mantén presionado para buscar imagen en Google"
                `;

                // Carrusel solo si hay múltiples imágenes distintas y el display es grande
                if (enableCarousel && distinctImages.length > 1 && (isFullWidth || numericSize > 80)) {
                    const carouselUrls = distinctImages.map(addVersion);
                    const duration = Math.max(6, carouselUrls.length * 2.2).toFixed(1);
                    const slides = carouselUrls.map((imgUrl, idx) => {
                        const delay = (idx * 2.2).toFixed(1);
                        const anim = `posCardCarouselFade ${duration}s linear infinite ${delay}s`;
                        const fallbackChain = JSON.stringify(buildVariantFallbackChain(imgUrl)).replace(/"/g, '&quot;');
                        return `<img src="${imgUrl}" alt="${productDesc}"
                                    loading="lazy" decoding="async"
                                    style="animation: ${anim};"
                                    data-anim="${anim}"
                                    data-fallback-chain="${fallbackChain}"
                                    data-fallback-index="0"
                                    data-slide="1"
                                    onerror="handleProductImageError(this)" data-product-id="${producto.id}" />`;
                    }).join('');

                    return `<div class="relative ${cssClass} long-press-container" ${longPressAttrs}
                                 onmouseenter="toggleProductCardCarouselHover(this, true)"
                                 onmouseleave="toggleProductCardCarouselHover(this, false)">
                        <img src="${avatarDataUri}" 
                             alt="${productDesc}"
                             class="w-full h-full object-cover rounded-lg"
                             style="position: absolute; top: 0; left: 0;"
                             data-avatar="true" />
                        <div class="product-card-carousel">${slides}</div>
                        <div class="card-carousel-controls">
                            <button class="card-carousel-btn"
                                    onclick="stepProductCardCarousel(event, -1)"
                                    onmousedown="event.stopPropagation()"
                                    ontouchstart="event.stopPropagation()">‹</button>
                            <button class="card-carousel-btn"
                                    onclick="stepProductCardCarousel(event, 1)"
                                    onmousedown="event.stopPropagation()"
                                    ontouchstart="event.stopPropagation()">›</button>
                        </div>
                        <div class="long-press-indicator"></div>
                    </div>`;
                }

                // Si hay imagen, mostrarla con la variante óptima
                if (bestUrl) {
                    const imagenUrl = addVersion(bestUrl);
                    return `<div class="relative ${cssClass} long-press-container" ${longPressAttrs}>
                        <img src="${avatarDataUri}" 
                             alt="${productDesc}"
                             class="w-full h-full object-cover rounded-lg"
                             style="position: absolute; top: 0; left: 0;"
                             data-avatar="true" />
                        <img src="${imagenUrl}" 
                             alt="${productDesc}"
                             loading="lazy" decoding="async"
                             class="w-full h-full object-cover rounded-lg"
                             style="position: absolute; top: 0; left: 0;"
                             data-fallback-chain="${JSON.stringify(buildVariantFallbackChain(imagenUrl)).replace(/"/g, '&quot;')}"
                             data-fallback-index="0"
                             onerror="handleProductImageError(this)"
                             onload="handleProductImageLoad(this)" 
                             data-product-id="${producto.id}" />
                        <div class="long-press-indicator"></div>
                    </div>`;
                }

                // Sin imagen: para displays pequeños (lista), solo mostrar avatar
                if (typeof size === 'number' && size <= 60) {
                    return `<div class="relative ${cssClass} long-press-container" ${longPressAttrs}>
                        <img src="${avatarDataUri}" 
                             alt="${productDesc}"
                             class="w-full h-full object-cover rounded-lg"
                             style="position: absolute; top: 0; left: 0;"
                             data-avatar="true" />
                        <div class="long-press-indicator"></div>
                    </div>`;
                }

                const proxyUrl = `/public/pos/api/imagen_proxy.php?id=${producto.id}&q=${encodeURIComponent(producto.descripcion || 'producto')}`;

                return `<div class="relative ${cssClass} long-press-container" ${longPressAttrs}>
                    <img src="${avatarDataUri}" 
                         alt="${productDesc}"
                         class="w-full h-full object-cover rounded-lg"
                         style="position: absolute; top: 0; left: 0;"
                         data-avatar="true" />
                    <img src="${proxyUrl}" 
                         alt="${productDesc}"
                         loading="lazy" decoding="async"
                         class="w-full h-full object-cover rounded-lg"
                         style="position: absolute; top: 0; left: 0; display: none;"
                         onerror="handleProductImageError(this)"
                         onload="handleProductImageLoad(this)" 
                         data-product-id="${producto.id}" />
                    <div class="long-press-indicator"></div>
                </div>`;
            }

            // Manejar error de carga de imagen
            function handleProductImageError(img) {
                if (!img) return;
                const current = String(img.getAttribute('src') || '');
                const chainRaw = img.dataset.fallbackChain
                    ? (() => {
                        try { return JSON.parse(img.dataset.fallbackChain || '[]'); } catch (_) { return []; }
                    })()
                    : buildVariantFallbackChain(current);
                const chain = Array.isArray(chainRaw) ? chainRaw : [];
                const idx = Math.max(0, Number(img.dataset.fallbackIndex || 0));

                if (idx < (chain.length - 1)) {
                    img.dataset.fallbackIndex = String(idx + 1);
                    img.src = chain[idx + 1];
                    return;
                }

                // Si fallan todas las variantes, ocultar imagen y dejar avatar.
                img.style.display = 'none';
            }

            // Manejar carga exitosa de imagen
            function handleProductImageLoad(img) {
                if (!img) return;
                img.style.display = 'block';
                // Ocultar el avatar
                const container = img.parentElement;
                if (container) {
                    const avatar = container.querySelector('[data-avatar="true"]');
                    if (avatar) avatar.style.display = 'none';
                }
            }

            function toggleProductCardCarouselHover(cardEl, isHover) {
                if (!cardEl) return;
                const carousel = cardEl.querySelector('.product-card-carousel');
                if (!carousel) return;
                const slides = carousel.querySelectorAll('img[data-slide="1"]');
                if (!slides.length) return;

                if (isHover) {
                    slides.forEach((img) => {
                        if (!carousel.dataset.manualIndex) {
                            img.style.animationPlayState = 'paused';
                        }
                    });
                    return;
                }

                delete carousel.dataset.manualIndex;
                slides.forEach((img) => {
                    img.style.animation = img.dataset.anim || '';
                    img.style.opacity = '';
                    img.style.animationPlayState = 'running';
                });
            }

            function stepProductCardCarousel(event, dir) {
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                const btn = event?.currentTarget;
                const card = btn ? btn.closest('.long-press-container') : null;
                if (!card) return;
                const carousel = card.querySelector('.product-card-carousel');
                if (!carousel) return;
                const slides = Array.from(carousel.querySelectorAll('img[data-slide="1"]'));
                if (slides.length <= 1) return;

                let idx = parseInt(carousel.dataset.manualIndex || '0', 10);
                if (!Number.isFinite(idx)) idx = 0;
                idx = (idx + (dir > 0 ? 1 : -1) + slides.length) % slides.length;
                carousel.dataset.manualIndex = String(idx);

                slides.forEach((img, i) => {
                    img.style.animation = 'none';
                    img.style.animationPlayState = 'paused';
                    img.style.opacity = i === idx ? '1' : '0';
                });
            }

            function posApp() {
                const initialSearchPreview = window.__INITIAL_SEARCH_PREVIEW__ || null;
                return {
                    // Estado
                    isDarkMode: true,
                    soundMuted: localStorage.getItem('pos_sound_muted') === 'true',
                    searchQuery: '',
                    isOfflineMode: typeof navigator !== 'undefined' ? !navigator.onLine : false,
                    pendingOfflineSales: [],
                    processingOfflineSync: false,
                    showSearchDropdown: false,
                    searchSuggestion: null,
                    searchDropdownMaxHeight: 340,
                    searchPreviewDetail: initialSearchPreview,
                    lastFocusedPreviewDetail: initialSearchPreview,
                    selectedSearchPreviewImage: '',
                    searchPreviewZoomActive: false,
                    searchPreviewZoomX: 50,
                    searchPreviewZoomY: 50,
                    showPreviewEquivalentesModal: false,
                    previewEquivEditor: {
                        loading: false,
                        saving: false,
                        error: '',
                        loadedProductId: 0,
                        form: {
                            idproducto: null,
                            cve_producto: '',
                            desproducto: '',
                            iva: '1',
                            codigo_barra: '',
                            precios: [],
                            codigos_barra_extra: [],
                            equivalentes: [],
                            aplicaciones: [],
                        },
                        catMarcasCodConversion: [],
                        catMarcasAplicacion: [],
                        catAniosAplicacion: [],
                        catModelosAplicacion: [],
                        catMotoresAplicacion: [],
                        catCodigosMotorAplicacion: [],
                    },
                    showPreviewClientesModal: false,
                    showPreviewPricesModal: false,
                    showPreviewStockModal: false,
                    showGeneralSearchModal: false,
                    generalSearchModalUrl: '',
                    loadingSearchPreview: false,
                    filterCategory: null,
                    productos: [],
                    categories: [],
                    loading: false,
                    processing: false,
                    // Infinite scroll
                    productSearchOffset: 0,
                    productSearchQuery: '',
                    loadingMoreProducts: false,
                    // Balanza
                    balanzaData: null,
                    balanzaLoading: false,

                    // ===== SISTEMA DE TICKETS MÚLTIPLES =====
                    tickets: [{
                        id: 1,
                        cart: [],
                        selectedCliente: null,
                        clienteSearch: ''
                    }],
                    activeTicket: 0,
                    nextTicketId: 2,

                    // Getter ticket activo
                    get currentTicket() {
                        return this.tickets[this.activeTicket] || this.tickets[0];
                    },
                    get cart() {
                        return this.currentTicket?.cart || [];
                    },
                    set cart(value) {
                        if (this.currentTicket) this.currentTicket.cart = value;
                    },
                    get selectedCliente() {
                        return this.currentTicket?.selectedCliente || null;
                    },
                    set selectedCliente(value) {
                        if (this.currentTicket) this.currentTicket.selectedCliente = value;
                    },
                    get clienteSearch() {
                        return this.currentTicket?.clienteSearch || '';
                    },
                    set clienteSearch(value) {
                        if (this.currentTicket) this.currentTicket.clienteSearch = value;
                    },

                    // Autocompletado y Vistas
                    // showSuggestions: false, // Deprecado
                    viewMode: (['grid','list'].includes(localStorage.getItem('pos_view_mode') || '') ? (localStorage.getItem('pos_view_mode') || 'grid') : 'grid'), // 'grid' | 'list'
                    selectedIndex: 0,

                    // Voice search
                    voiceListening: false,
                    voiceListeningCliente: false,
                    _voiceRecognition: null,
                    _voiceRecognitionCliente: null,

                    // Cliente search results (compartido)
                    clientesResults: [],
                    showClienteDropdown: false,
                    clienteSelectedIdx: 0,
                    loadingClientes: false,
                    sifenLookupInFlight: false,
                    lastSifenLookupQuery: '',

                    // UI
                    currentTime: '',
                    showSuccessModal: false,
                    lastVenta: null,
                    showSerialPickerModal: false,
                    serialPickerProduct: null,
                    serialPickerSeries: [],
                    serialPickerLoading: false,
                    serialPickerError: '',

                    // Simple Change Modal
                    showSimpleChangeModal: false,
                    simpleCashReceived: 0,
                    simplePaymentMethod: 'efectivo',
                    simpleReference: '',
                    simpleCreditInstallments: 1,
                    simpleCreditDueDate: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
                    simpleCreditNotes: '',
                    simpleCreditInterestPct: 0,
                    simpleCreditMoraPct: 0,
                    simpleCreditGraceDays: 0,
                    simpleCardCaptureLoading: false,
                    simpleCardCaptureError: '',
                    simpleCardCaptureData: null,
                    simpleCardInstallments: 1,
                    simpleCardFinancingType: 'credito',
                    simpleCardProcessor: 'bancard',
                    simpleCardMockDecline: false,
                    simplePixLoading: false,
                    simplePixStatusLoading: false,
                    simplePixError: '',
                    simplePixData: null,
                    pendingCobroFacturaId: 0,
                    pendingCobroFacturaNro: '',
                    _deleteApprovalPollBusy: false,
                    _deleteApprovalPollTimer: null,
                    simpleCardFinancingTypeOptions: [
                        { value: 'credito', label: 'Crédito' },
                        { value: 'debito', label: 'Débito' }
                    ],
                    simpleCardProcessorOptions: [
                        { value: 'bancard', label: 'Bancard' },
                        { value: 'bepsa', label: 'Bepsa' },
                        { value: 'dinelco', label: 'Dinelco' },
                        { value: 'otro', label: 'Otro' }
                    ],

                    // ===== SISTEMA DE NOTIFICACIONES =====
                    toasts: [],
                    toastId: 0,
                    confirmModal: {
                        show: false,
                        title: '',
                        message: '',
                        icon: '❓',
                        type: 'info',
                        confirmText: 'Aceptar',
                        onConfirm: null,
                        onCancel: null
                    },

                    // Modal informativo (sin botones de confirmación)
                    infoModal: {
                        show: false,
                        title: '',
                        message: '',
                        icon: '🔍',
                        type: 'info',
                        loading: false,
                        autoClose: 0
                    },

                    // Mostrar modal informativo
                    showInfoModal(options = {}) {
                        this.infoModal = {
                            show: true,
                            title: options.title || 'Información',
                            message: options.message || '',
                            icon: options.icon || 'ℹ️',
                            type: options.type || 'info',
                            loading: options.loading || false,
                            autoClose: options.autoClose || 0
                        };

                        // Auto cerrar si se especifica
                        if (options.autoClose > 0) {
                            setTimeout(() => {
                                this.closeInfoModal();
                            }, options.autoClose);
                        }
                    },

                    closeInfoModal() {
                        this.infoModal.show = false;
                    },

                    // Mostrar toast
                    toast(message, type = 'info', duration = 3000) {
                        const normalizedMessage = String(message || '').trim().toLowerCase();
                        const suppressConnectionToast = type === 'error'
                            && normalizedMessage === 'error de conexión'
                            && Number(this.suppressConnectionErrorToastUntil || 0) > Date.now();
                        if (suppressConnectionToast) {
                            message = 'Abriendo impresión web...';
                            type = 'warning';
                            duration = 3500;
                        }

                        const id = ++this.toastId;
                        const effectiveDuration = (duration === 3000 || duration == null)
                            ? (type === 'error' ? 15000 : (type === 'warning' ? 7000 : 3000))
                            : duration;
                        const toast = {
                            id,
                            message,
                            type,
                            show: true
                        };
                        this.toasts.push(toast);
                        this.playSound(type); // Reproducir sonido
                        if (effectiveDuration > 0) {
                            setTimeout(() => this.removeToast(id), effectiveDuration);
                        }
                        return id;
                    },

                    beginPrintFallbackGuard(duration = 6000) {
                        this.suppressConnectionErrorToastUntil = Date.now() + Math.max(1000, Number(duration) || 6000);
                    },

                    async copyToast(message) {
                        const txt = String(message || '').trim();
                        if (!txt) return;
                        try {
                            await navigator.clipboard.writeText(txt);
                            this.toast('Mensaje copiado', 'success', 1800);
                        } catch (e) {
                            this.toast('No se pudo copiar automáticamente', 'warning', 3000);
                        }
                    },

                    removeToast(id) {
                        const index = this.toasts.findIndex(t => t.id === id);
                        if (index > -1) {
                            this.toasts[index].show = false;
                            setTimeout(() => {
                                this.toasts = this.toasts.filter(t => t.id !== id);
                            }, 300);
                        }
                    },

                    // Toggle sonido del POS
                    toggleSound() {
                        this.soundMuted = POSAudio.toggleMute();
                        // Mostrar feedback visual (sin sonido si está muteado)
                        this.toast(this.soundMuted ? 'Sonido desactivado' : 'Sonido activado', 'info');
                    },

                    // Mostrar confirm modal (retorna Promise)
                    showConfirm(options) {
                        return new Promise((resolve) => {
                            this.confirmModal = {
                                show: true,
                                title: options.title || 'Confirmar',
                                message: options.message || '¿Está seguro?',
                                icon: options.icon || '❓',
                                type: options.type || 'info',
                                confirmText: options.confirmText || 'Aceptar',
                                showCancel: options.showCancel !== false,
                                onConfirm: () => resolve(true),
                                onCancel: () => resolve(false)
                            };
                            // Sonido al mostrar confirmación
                            this.playSound('warning');
                        });
                    },

                    // Mostrar alerta simple (solo botón Aceptar)
                    showAlert(title, message, icon = '⚠️', type = 'info') {
                        return this.showConfirm({
                            title,
                            message,
                            icon,
                            type,
                            confirmText: 'Aceptar',
                            showCancel: false
                        });
                    },

                    acceptConfirm() {
                        this.confirmModal.show = false;
                        this.playSound('success');
                        if (this.confirmModal.onConfirm) this.confirmModal.onConfirm();
                    },

                    cancelConfirm() {
                        this.confirmModal.show = false;
                        if (this.confirmModal.onCancel) this.confirmModal.onCancel();
                    },

                    // ===== MODAL DE CONTRASEÑA ADMIN =====
                    passwordModal: {
                        show: false,
                        title: 'Autorización Requerida',
                        message: '',
                        password: '',
                        error: '',
                        verifying: false,
                        onSuccess: null,
                        onCancel: null,
                        actionData: null
                    },

                    showPasswordPrompt(options) {
                        return new Promise((resolve) => {
                            this.passwordModal = {
                                show: true,
                                title: options.title || 'Autorización Requerida',
                                message: options.message || 'Ingrese la contraseña del administrador',
                                password: '',
                                error: '',
                                verifying: false,
                                onSuccess: () => resolve(true),
                                onCancel: () => resolve(false),
                                actionData: options.data || null
                            };
                            this.playSound('warning');
                            this.$nextTick(() => {
                                this.$refs.adminPasswordInput?.focus();
                            });
                        });
                    },

                    async verifyAdminPassword() {
                        if (!this.passwordModal.password) {
                            this.passwordModal.error = 'Ingrese la contraseña';
                            this.playSound('error');
                            return;
                        }

                        this.passwordModal.verifying = true;
                        this.passwordModal.error = '';

                        try {
                            const res = await fetch('api/verificar_admin.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    password: this.passwordModal.password,
                                    id_empresa: this.idEmpresa
                                })
                            });

                            const data = await res.json();

                            if (data.success) {
                                this.passwordModal.show = false;
                                this.playSound('success');
                                if (this.passwordModal.onSuccess) this.passwordModal.onSuccess();
                            } else {
                                this.passwordModal.error = data.message || 'Contraseña incorrecta';
                                this.passwordModal.password = '';
                                this.playSound('error');
                                this.$refs.adminPasswordInput?.focus();
                            }
                        } catch (e) {
                            this.passwordModal.error = 'Error de conexión';
                            this.playSound('error');
                        }

                        this.passwordModal.verifying = false;
                    },

                    cancelPassword() {
                        this.passwordModal.show = false;
                        if (this.passwordModal.onCancel) this.passwordModal.onCancel();
                    },

                    // ===== SISTEMA DE SONIDOS =====
                    initAudio() {
                        POSAudio.init();
                    },

                    playSound(type = 'info') {
                        POSAudio.play(type);
                    },

                    focusPrimaryInput() {
                        this.$nextTick(() => {
                            this.$refs.searchInput?.focus();
                        });
                    },

                    // Modal Detalle Producto
                    showProductDetailModal: false,
                    productDetail: null,
                    loadingDetail: false,
                    loadingHeavyDetail: false,
                    detailContentReady: false,
                    currentProductId: null,
                    detailImageError: false,
                    selectedDetailImage: null,
                    deletingDetailImage: false,
                    discontinuingProduct: false,
                    autoPixabayPersisting: {},
                    showKardexModal: false,
                    loadingKardex: false,
                    kardexTab: 'sucursales',
                    kardexMovimientos: [],
                    kardexSucursales: [],
                    kardexSelectedSucursalId: 0,
                    kardexStockActual: 0,
                    kardexSucursal: null,
                    kardexProducto: null,

                    // Modal Buscar Imagen
                    showImageSearchModal: false,
                    imageSearchProduct: null,
                    imageManagerImages: [],
                    imageManagerLoading: false,
                    imageManagerBusyFileId: '',
                    supplierSearchUrl: '',
                    imageUrlInput: '',
                    imageBase64: '',
                    savingImage: false,
                    // Búsqueda de imágenes en Pixabay
                    searchingImages: false,
                    searchResults: [],
                    searchTranslated: '',
                    searchTotal: 0,
                    searchPage: 1,

                    // Config - PHP tiene prioridad sobre localStorage
                    idEmpresa: <?php echo $id_empresa; ?>,
                    idUsuario: <?php echo $id_login; ?>,
                    idSucursal: <?php echo $id_sucursal; ?>,
                    idCaja: <?php echo $id_caja; ?>,
                    printerName: '<?php echo addslashes($impresora_caja); ?>',
                    allowedPaymentMethods: <?php echo json_encode(array_values($metodos_cobro_ids)); ?>,
                    qzConnected: false,
                    directPrintEnabled: true,
                    suppressConnectionErrorToastUntil: 0,
                    showQzInstallModal: false,
                    qzCheckingConnection: false,
                    _qzConnectPromise: null,
                    _qzLastError: '',
                    _qzPrintersList: [],
                    _qzMonitorTimer: null,
                    _qzFastRetryTimer: null,
                    _qzMonitorBusy: false,
                    _onQzVisibilityChange: null,
                    _onQzWindowFocus: null,
                    _onQzOnline: null,
                    printProvider: 'N/A',
                    smxPrinter: null,
                    nativeAgentInstalled: false,
                    nativeAgentVersion: '',
                    nativeAgentChecking: false,
                    nativeAgentOs: '',
                    nativeAgentDownloadReady: false,
                    androidApkAvailable: false,
                    androidApkUrl: '/public/pos/downloads/sistemax-agent-android.apk',
                    installingAgent: false,
                    launchingAgent: false,
                    installTerminalLogs: [],
                    userConfig: {
                        cobro_df: <?php echo $cobro_df; ?>,
                        ancho_papel: <?php echo $ancho_papel; ?>,
                        forma_pago_def: <?php echo $forma_pago_def; ?>,
                        fe: <?php echo $isFacturaElectronica; ?>,
                        isVendedor: <?php echo (mb_strtolower($rol_usuario, 'UTF-8') === 'vendedor') ? 'true' : 'false'; ?>
                    },
                    permisosProductos: window.__PERMISOS_PRODUCTOS__ || {},
                    posContext: window.__POS_CONTEXT__ || {},
                    searchEstado: 1, // 1: Activos, 0: Descontinuados
                    selectedPriceType: 1, // Tipo de precio seleccionado (por defecto 1)
                    ultraFastPosMode: true,

                    // Modales de Venta
                    showTicketModal: false,
                    webPrintModalMode: false,
                    ticketUrl: '',
                    loadingTicket: false,
                    loadingSifen: false,
                    showManagedDocumentActionsModal: false,
                    managedDocumentPrinter: '',
                    managedDocumentPrintMode: 'auto',
                    managedDocumentEscposWidth: '48',
                    managedDocumentEmail: '',
                    managedDocumentPhone: '',
                    managedDocumentCountryCode: '595',
                    managedDocumentStatus: '',
                    managedDocumentStatusType: 'ok',
                    showEmailModal: false,
                    showWhatsAppModal: false,
                    emailInput: '',
                    phoneInput: '',
                    selectedCountryCode: '595',
                    sendingEmail: false,

                    // Lista de países para WhatsApp
                    countries: [{
                            code: '595',
                            name: 'Paraguay',
                            flag: '🇵🇾',
                            iso: 'PY'
                        },
                        {
                            code: '54',
                            name: 'Argentina',
                            flag: '🇦🇷',
                            iso: 'AR'
                        },
                        {
                            code: '55',
                            name: 'Brasil',
                            flag: '🇧🇷',
                            iso: 'BR'
                        },
                        {
                            code: '56',
                            name: 'Chile',
                            flag: '🇨🇱',
                            iso: 'CL'
                        },
                        {
                            code: '57',
                            name: 'Colombia',
                            flag: '🇨🇴',
                            iso: 'CO'
                        },
                        {
                            code: '593',
                            name: 'Ecuador',
                            flag: '🇪🇨',
                            iso: 'EC'
                        },
                        {
                            code: '51',
                            name: 'Perú',
                            flag: '🇵🇪',
                            iso: 'PE'
                        },
                        {
                            code: '598',
                            name: 'Uruguay',
                            flag: '🇺🇾',
                            iso: 'UY'
                        },
                        {
                            code: '58',
                            name: 'Venezuela',
                            flag: '🇻🇪',
                            iso: 'VE'
                        },
                        {
                            code: '591',
                            name: 'Bolivia',
                            flag: '🇧🇴',
                            iso: 'BO'
                        },
                        {
                            code: '52',
                            name: 'México',
                            flag: '🇲🇽',
                            iso: 'MX'
                        },
                        {
                            code: '34',
                            name: 'España',
                            flag: '🇪🇸',
                            iso: 'ES'
                        },
                        {
                            code: '1',
                            name: 'Estados Unidos',
                            flag: '🇺🇸',
                            iso: 'US'
                        },
                    ],
                    paymentsList: [],
                    showPreviewModal: false,

                    // Modo Edición de Venta
                    showEditSearchModal: false,
                    editSearchQuery: '',
                    editSifenFilter: 'todos',
                    editSearchResults: [],
                    loadingEditSearch: false,
                    _editSearchAbortController: null,
                    _editSearchReqId: 0,
                    showPresupuestoImportModal: false,
                    presupuestoSearchQuery: '',
                    presupuestoEstadoFilter: 'activo',
                    presupuestoSearchResults: [],
                    loadingPresupuestoSearch: false,
                    _presupuestoSearchAbortController: null,
                    _presupuestoSearchReqId: 0,
                    importedPresupuesto: null,
                    editingVenta: null, // { id_factura, nro_factura, ... } cuando estamos editando


                    // Productos Populares
                    popularProducts: [],
                    loadingPopular: false,
                    popularDebug: { source: 'none', count: 0, error: '' },
                    popularProductsCacheTtlMs: 0, // DEV: cache desactivada (prod: 5 * 60 * 1000)
                    searchProductsCacheTtlMs: 45 * 1000,
                    searchImagesReady: true,
                    _searchRequestId: 0,
                    _searchPreviewReqId: 0,
                    _searchDebounceTimer: null,
                    _searchAbortController: null,
                    _searchPreviewAbortController: null,
                    _searchPreviewHoverTimer: null,
                    _searchImagesDelayTimer: null,
                    _viewModePressTimer: null,
                    _pendingCartHydrations: {},
                    _pendingHeavyDetailLoads: {},
                    _searchPreviewDetailCache: {},
                    _productDetailCache: {},

                    // Computed
                    get subtotal() {
                        return this.cart.reduce((sum, item) => sum + Math.round(item.precio * item.cantidad), 0);
                    },
                    get iva() {
                        return Math.round(this.subtotal / 11);
                    },
                    get total() {
                        return this.subtotal;
                    },
                    get totalPaid() {
                        return this.paymentsList.reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
                    },
                    get remainingAmount() {
                        return this.total - this.totalPaid;
                    },

                    // ===== FUNCIONES DE TICKETS =====
                    addTicket() {
                        if (this.tickets.length >= 8) {
                            this.toast('Máximo 8 tickets simultáneos', 'warning');
                            return;
                        }
                        const newTicket = {
                            id: this.nextTicketId++,
                            cart: [],
                            selectedCliente: null,
                            clienteSearch: ''
                        };
                        this.tickets.push(newTicket);
                        this.activeTicket = this.tickets.length - 1;
                        this.focusPrimaryInput();
                    },

                    async removeTicket(index) {
                        if (this.tickets.length === 1) {
                            // No eliminar el último, solo limpiar
                            this.tickets[0] = {
                                id: this.nextTicketId++,
                                cart: [],
                                selectedCliente: null,
                                clienteSearch: ''
                            };
                            this.activeTicket = 0;
                            return;
                        }

                        const ticket = this.tickets[index];
                        if (ticket.cart.length > 0) {
                            const confirmed = await this.showConfirm({
                                title: 'Cerrar Ticket',
                                message: `¿Cerrar Ticket #${ticket.id}?\nTiene ${ticket.cart.length} producto(s) en el carrito.`,
                                icon: '🎫',
                                type: 'danger',
                                confirmText: 'Cerrar'
                            });
                            if (!confirmed) return;
                        }

                        this.tickets.splice(index, 1);
                        if (this.activeTicket >= this.tickets.length) {
                            this.activeTicket = this.tickets.length - 1;
                        }
                    },

                    switchTicket(index) {
                        if (index >= 0 && index < this.tickets.length) {
                            this.activeTicket = index;
                            this.saveTickets();
                            this.focusPrimaryInput();
                        }
                    },

                    // ===== PERSISTENCIA EN LOCALSTORAGE =====
                    saveTickets() {
                        const start = performance.now();
                        try {
                            const data = {
                                tickets: this.tickets,
                                activeTicket: this.activeTicket,
                                nextTicketId: this.nextTicketId,
                                timestamp: Date.now()
                            };
                            const json = JSON.stringify(data);
                            localStorage.setItem(this.getTicketsStorageKey(), json);
                            const end = performance.now();
                            if (end - start > 100) {
                                console.warn(`⚠️ saveTickets() was slow: ${(end - start).toFixed(2)}ms`);
                            }
                        } catch (e) {
                            console.warn('Error saving tickets to localStorage:', e);
                        }
                    },

                    loadTickets() {
                        try {
                            const saved = localStorage.getItem(this.getTicketsStorageKey());
                            if (saved) {
                                const data = JSON.parse(saved);
                                // Solo cargar si los datos son recientes (menos de 24 horas)
                                if (data.timestamp && (Date.now() - data.timestamp) < 86400000) {
                                    if (data.tickets && data.tickets.length > 0) {
                                        this.tickets = data.tickets.map(t => ({
                                            id: t?.id || this.nextTicketId++,
                                            cart: Array.isArray(t?.cart) ? t.cart : [],
                                            selectedCliente: t?.selectedCliente || null,
                                            clienteSearch: t?.clienteSearch || ''
                                        }));
                                        this.activeTicket = data.activeTicket || 0;
                                        this.nextTicketId = data.nextTicketId || (this.tickets.length + 1);
                                        console.log('✅ Tickets recuperados:', this.tickets.length);
                                        return true;
                                    }
                                }
                            }
                        } catch (e) {
                            console.warn('Error loading tickets from localStorage:', e);
                        }
                        return false;
                    },

                    clearSavedTickets() {
                        localStorage.removeItem(this.getTicketsStorageKey());
                    },

                    getTicketsStorageKey() {
                        const base = String(this.posContext?.storage_key || 'pos_tickets').trim() || 'pos_tickets';
                        return `${base}_${this.idEmpresa}`;
                    },

                    offlineSalesStorageKey() {
                        const base = String(this.posContext?.storage_key || 'pos_tickets').trim() || 'pos_tickets';
                        return `${base}_offline_${this.idEmpresa}`;
                    },

                    loadPendingOfflineSales() {
                        try {
                            const raw = localStorage.getItem(this.offlineSalesStorageKey());
                            const parsed = raw ? JSON.parse(raw) : [];
                            this.pendingOfflineSales = Array.isArray(parsed) ? parsed : [];
                        } catch (e) {
                            console.warn('Error loading offline sales queue:', e);
                            this.pendingOfflineSales = [];
                        }
                    },

                    savePendingOfflineSales() {
                        try {
                            localStorage.setItem(this.offlineSalesStorageKey(), JSON.stringify(this.pendingOfflineSales));
                        } catch (e) {
                            console.warn('Error saving offline sales queue:', e);
                        }
                    },

                    createOfflineSyncId(prefix = 'venta') {
                        const rand = Math.random().toString(36).slice(2, 10);
                        return `${prefix}-${this.idEmpresa}-${this.idCaja}-${Date.now()}-${rand}`;
                    },

                    isPresupuestoMode() {
                        return String(this.posContext?.mode || '').toLowerCase() === 'presupuesto';
                    },

                    isPedidoProveedorMode() {
                        return String(this.posContext?.mode || '').toLowerCase() === 'pedido_proveedor';
                    },

                    skipStockAndDeleteGuards() {
                        return this.isPresupuestoMode() || this.isPedidoProveedorMode();
                    },

                    getSubmitApiEndpoint() {
                        if (this.isPresupuestoMode()) return 'api/presupuesto.php';
                        if (this.isPedidoProveedorMode()) return 'api/pedidos.php';
                        return 'api/venta.php';
                    },

                    getEditApiEndpoint() {
                        if (this.isPresupuestoMode()) return 'api/presupuestos_manage.php';
                        if (this.isPedidoProveedorMode()) return 'api/pedidos_manage.php';
                        return 'api/venta_edit.php';
                    },

                    getManagedDocumentLabel() {
                        if (this.isPresupuestoMode()) return 'Presupuesto';
                        if (this.isPedidoProveedorMode()) return 'Pedido';
                        return 'Venta';
                    },

                    goBackToParent() {
                        const backPath = String(this.posContext?.back_path || '/public/menu.php').trim() || '/public/menu.php';

                        if (window.opener && !window.opener.closed) {
                            try {
                                window.opener.focus();
                            } catch (_) {}
                            try {
                                window.close();
                                return;
                            } catch (_) {}
                        }

                        if (window.history.length > 1) {
                            window.history.back();
                            return;
                        }

                        window.location.href = backPath;
                    },

                    isLikelyOfflineError(error) {
                        const msg = String(error?.message || error || '').toLowerCase();
                        return !navigator.onLine
                            || msg.includes('failed to fetch')
                            || msg.includes('networkerror')
                            || msg.includes('load failed')
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

                    async submitVentaPayload(payload, options = {}) {
                        const outbound = { ...payload };
                        if (!outbound.offline_sync_id) {
                            outbound.offline_sync_id = this.createOfflineSyncId(
                                this.isPresupuestoMode() ? 'presupuesto' : (this.isPedidoProveedorMode() ? 'pedido' : 'venta')
                            );
                        }
                        outbound._document_kind = this.isPresupuestoMode() ? 'presupuesto' : (this.isPedidoProveedorMode() ? 'pedido' : 'venta');
                        const endpoint = options.endpoint || this.getSubmitApiEndpoint();

                        try {
                            const response = await fetch(endpoint, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(outbound)
                            });
                            const result = await response.json();
                            if (!response.ok || !result?.success) {
                                throw new Error(result?.message || `HTTP ${response.status}`);
                            }
                            return { queued: false, payload: outbound, result };
                        } catch (error) {
                            if (options.allowQueue !== false && this.canQueueOfflineVenta(outbound) && this.isLikelyOfflineError(error)) {
                                const queueItem = this.queueVentaOffline(outbound);
                                const defaultQueueMessage = this.isPresupuestoMode()
                                    ? 'Sin conexión. El presupuesto quedó guardado localmente y se sincronizará al volver internet.'
                                    : (this.isPedidoProveedorMode()
                                        ? 'Sin conexión. El pedido quedó guardado localmente y se sincronizará al volver internet.'
                                        : 'Sin conexión. La venta quedó guardada localmente y se sincronizará al volver internet.');
                                this.toast(
                                    options.queueMessage || defaultQueueMessage,
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
                                const payload = item.payload || {};
                                const kind = String(payload._document_kind || '').toLowerCase();
                                const endpoint = kind === 'presupuesto'
                                    ? 'api/presupuesto.php'
                                    : (kind === 'pedido' ? 'api/pedidos.php' : 'api/venta.php');
                                const response = await fetch(endpoint, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify(payload)
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
                        await window.registerPosDesktopPwa?.();

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

                    // Detectar país del navegador
                    detectUserCountry() {
                        try {
                            // Intentar obtener el país desde el idioma del navegador
                            const locale = navigator.language || navigator.userLanguage || 'es-PY';
                            const countryCode = locale.split('-')[1] || 'PY';

                            // Buscar el país en la lista
                            const country = this.countries.find(c => c.iso === countryCode.toUpperCase());

                            if (country) {
                                this.selectedCountryCode = country.code;
                                console.log(`🌍 País detectado: ${country.flag} ${country.name} (+${country.code})`);
                            } else {
                                // Fallback a Paraguay
                                this.selectedCountryCode = '595';
                                console.log('🌍 País no detectado, usando Paraguay por defecto');
                            }
                        } catch (e) {
                            console.warn('Error detectando país:', e);
                            this.selectedCountryCode = '595'; // Fallback a Paraguay
                        }
                    },

                    deferNonCritical(task, timeout = 0) {
                        const run = () => {
                            try {
                                const result = task();
                                if (result && typeof result.catch === 'function') {
                                    result.catch((error) => console.warn('Deferred POS task failed:', error));
                                }
                            } catch (error) {
                                console.warn('Deferred POS task failed:', error);
                            }
                        };
                        if (typeof window !== 'undefined' && typeof window.requestIdleCallback === 'function') {
                            window.requestIdleCallback(run, { timeout: Math.max(250, timeout || 250) });
                            return;
                        }
                        setTimeout(run, timeout);
                    },

                    // Init
                    _initDone: false,
                    init() {
                        // Guard: evitar doble init por Alpine re-mount
                        if (this._initDone) {
                            console.log('⚠️ init() ya ejecutado, ignorando re-mount');
                            return;
                        }
                        this._initDone = true;

                        const hideBootSplash = () => {
                            const splash = document.getElementById('bootSplash');
                            if (!splash) return;
                            splash.classList.add('is-hidden');
                            setTimeout(() => {
                                if (splash && splash.parentNode) {
                                    splash.parentNode.removeChild(splash);
                                }
                            }, 220);
                        };
                        requestAnimationFrame(() => requestAnimationFrame(hideBootSplash));

                        // Alpine suele preservar el contexto, pero algunos callbacks externos
                        // (timers/listeners/referencias diferidas) pueden invocar métodos sin bind.
                        // Esto evita errores del tipo "this.toast is not a function".
                        Object.keys(this).forEach((key) => {
                            if (key === 'init') return;
                            if (typeof this[key] === 'function') {
                                this[key] = this[key].bind(this);
                            }
                        });

                        if (!['grid', 'list'].includes(this.viewMode)) this.viewMode = 'grid';

                        // DEBUG: Mostrar empresa actual
                        console.log('🏢 POS Init - Empresa ID:', this.idEmpresa, 'Usuario ID:', this.idUsuario);

                        // Cargar IDs en localStorage para persistencia en APIs
                        localStorage.setItem('id_empresa', this.idEmpresa);
                        localStorage.setItem('id_login', this.idUsuario);
                        this.nativeAgentDownloadReady = localStorage.getItem('native_agent_download_ready') === '1';
                        this.directPrintEnabled = localStorage.getItem('pos_direct_print_enabled') !== '0';
                        if (!this._generalSearchMessageHandler) {
                            this._generalSearchMessageHandler = (event) => this.handleGeneralSearchMessage(event);
                            window.addEventListener('message', this._generalSearchMessageHandler);
                        }

                        // Cargar productos populares
                        this.loadPopularProducts();
                        if (this.searchPreviewDetail?.producto) {
                            const initialProductId = String(this.searchPreviewDetail.producto.idproducto || this.searchPreviewDetail.producto.id || '');
                            if (initialProductId) {
                                this._searchPreviewDetailCache[initialProductId] = this.searchPreviewDetail;
                                this._productDetailCache[initialProductId] = this.searchPreviewDetail;
                                this.syncSearchPreviewGallery();
                                this.ensureFullProductDetailInBackground(initialProductId, 'preview');
                            }
                        } else {
                            this.loadInitialPreviewFallback();
                        }

                        this.loadTickets();
                        this.updateTime();
                        setInterval(() => this.updateTime(), 1000);
                        this.focusPrimaryInput();

                        // Pre-inicializar AudioContext para sonidos inmediatos
                        this.initAudio();

                        // Diferir tareas no críticas para priorizar el primer render útil.
                        this.deferNonCritical(() => this.loadPrintConfig(), 150);
                        this.deferNonCritical(() => this.detectUserCountry(), 250);
                        this.deferNonCritical(() => this.loadCategories(), 350);
                        this.deferNonCritical(() => this.setupOfflineSupport(), 450);

                        // Auto-guardar tickets de forma eficiente (cada 5 segundos si hay cambios)
                        setInterval(() => this.saveTickets(), 5000);
                        this._deleteApprovalPollTimer = setInterval(() => this.pollDeleteApprovalStatuses(), 3000);

                        // Guardar antes de cerrar la página
                        window.addEventListener('beforeunload', () => this.saveTickets());

                        // Verificar parámetros de URL para edición directa
                        const urlParams = new URLSearchParams(window.location.search);
                        const editId = urlParams.get('edit_id') || urlParams.get('edit');
                        const viewId = urlParams.get('id_venta');
                        const cobroFacturaId = Number(urlParams.get('cobro_factura_id') || 0);

                        if (cobroFacturaId > 0) {
                            this.$nextTick(() => {
                                setTimeout(() => this.startCobroFacturaPendiente(cobroFacturaId), 300);
                            });
                        } else if (editId) {
                            console.log('🔗 Modo edición activado por URL:', editId);
                            this.$nextTick(() => {
                                // Pequeño delay para asegurar que los componentes estén listos
                                setTimeout(() => this.loadVentaForEdit(editId), 500);
                            });
                        } else if (viewId) {
                            console.log('🔗 Modo visualización activado por URL:', viewId);
                            this.$nextTick(() => {
                                // Cargar venta y mostrar modal de impresión
                                setTimeout(() => this.loadVentaForView(viewId), 500);
                            });
                        }

                        // Atajos de teclado
                        document.addEventListener('keydown', (e) => {
                            if (e.key === 'F2') {
                                e.preventDefault();
                                this.$refs.searchInput.focus();
                            }
                            if (e.key === 'F5' && this.cart.length > 0) {
                                e.preventDefault();
                                this.procesarVenta();
                            }
                            if (e.key === 'Escape') {
                                this.showSuccessModal = false;
                            }

                            // Atajos para tickets múltiples
                            if (e.ctrlKey && e.key === 't') {
                                e.preventDefault();
                                this.addTicket();
                            }
                            if (e.ctrlKey && e.key >= '1' && e.key <= '8') {
                                e.preventDefault();
                                const idx = parseInt(e.key) - 1;
                                if (idx < this.tickets.length) {
                                    this.switchTicket(idx);
                                }
                            }
                            if (e.ctrlKey && e.key === 'ArrowLeft') {
                                e.preventDefault();
                                if (this.activeTicket > 0) this.switchTicket(this.activeTicket - 1);
                            }
                            if (e.ctrlKey && e.key === 'ArrowRight') {
                                e.preventDefault();
                                if (this.activeTicket < this.tickets.length - 1) this.switchTicket(this.activeTicket + 1);
                            }
                        });

                        // Auto-conectar impresión local al iniciar el POS (silencioso)
                        this.initQzTray();
                        this.startQzAutoReconnect();
                    },

                    async probeLocalAgentHealth() {
                        if (!this.canUseLocalAgent()) return false;
                        const bases = [];
                        if (this.smxPrinter && this.smxPrinter._activeBaseUrl) {
                            bases.push(String(this.smxPrinter._activeBaseUrl).replace(/\/+$/, ''));
                        }
                        bases.push('http://127.0.0.1:17890', 'http://localhost:17890');

                        const uniq = [];
                        for (const b of bases) {
                            if (!b || uniq.includes(b)) continue;
                            uniq.push(b);
                        }

                        for (const base of uniq) {
                            const controller = new AbortController();
                            const timeout = setTimeout(() => controller.abort(), 1200);
                            try {
                                const res = await fetch(`${base}/health`, {
                                    method: 'GET',
                                    cache: 'no-store',
                                    signal: controller.signal
                                });
                                if (!res.ok) continue;
                                const data = await res.json();
                                if (data && data.ok === true) return true;
                            } catch (_) {
                                // seguir probando otras URLs locales
                            } finally {
                                clearTimeout(timeout);
                            }
                        }
                        return false;
                    },

                    getLocalAgentErrorMessage(err) {
                        const raw = String(err?.message || err || '').trim();
                        const likelyNetworkBlock = /failed to fetch|networkerror|load failed|private.network|err_blocked|cors/i.test(raw.toLowerCase());
                        if (location.protocol === 'https:' && likelyNetworkBlock) {
                            return 'Chrome bloqueó la conexión local del Agent. Permití "Contenido no seguro" para este sitio (candado en la barra de direcciones -> Configuración del sitio) y recargá el POS.';
                        }
                        return raw || 'Impresión directa no disponible en este equipo.';
                    },

                    async ensureQzConnected() {
                        if (!this.smxPrinter) {
                            throw new Error('Bridge de impresión no disponible');
                        }

                        if (this._qzConnectPromise) {
                            try {
                                await this._qzConnectPromise;
                            } catch(e) {
                            }
                        }

                        if (this.smxPrinter.isActive()) {
                            const alive = await this.probeLocalAgentHealth();
                            if (!alive) {
                                this.qzConnected = false;
                                this.printProvider = 'N/A';
                            } else {
                                this.qzConnected = true;
                                this.printProvider = this.smxPrinter.getProviderLabel();
                                return;
                            }
                        }

                        const doConnect = async () => {
                            try {
                                await this.smxPrinter.connect();
                                this.qzConnected = true;
                                this.printProvider = this.smxPrinter.getProviderLabel();
                                this.nativeAgentInstalled = true;
                                this.nativeAgentDownloadReady = true;
                                localStorage.setItem('native_agent_download_ready', '1');
                                this._qzLastError = '';
                            } catch(err) {
                                this.qzConnected = false;
                                this.printProvider = 'N/A';
                                this._qzLastError = this.getLocalAgentErrorMessage(err);
                                throw new Error('Impresión directa no disponible en este equipo.');
                            } finally {
                                this._qzConnectPromise = null;
                            }
                        };

                        this._qzConnectPromise = doConnect();
                        await this._qzConnectPromise;
                    },

                    async silentQzReconnect() {
                        if (this._qzMonitorBusy || this.qzCheckingConnection || this._qzInitRunning) return false;
                        if (!this.canUseLocalAgent()) return false;

                        this._qzMonitorBusy = true;
                        try {
                            if (!this.smxPrinter) {
                                await this.initQzTray();
                                return this.qzConnected;
                            }

                            const alive = await this.probeLocalAgentHealth();
                            if (alive && this.smxPrinter.isActive()) {
                                this.qzConnected = true;
                                this.printProvider = this.smxPrinter.getProviderLabel();
                                this.nativeAgentInstalled = true;
                                this.nativeAgentDownloadReady = true;
                                localStorage.setItem('native_agent_download_ready', '1');
                                this._qzLastError = '';
                                return true;
                            }

                            if (alive && !this.smxPrinter.isActive()) {
                                try {
                                    await this.smxPrinter.connect();
                                } catch (_) {}
                                this.qzConnected = true;
                                this.printProvider = this.smxPrinter.getProviderLabel();
                                this.nativeAgentInstalled = true;
                                this.nativeAgentDownloadReady = true;
                                localStorage.setItem('native_agent_download_ready', '1');
                                this._qzLastError = '';
                                return true;
                            }

                            try {
                                await this.smxPrinter.disconnect();
                            } catch (_) {}
                            this._qzConnectPromise = null;
                            this.qzConnected = false;
                            this.printProvider = 'N/A';

                            try {
                                await this.ensureQzConnected();
                                await this.refreshPrintersList();
                                return this.qzConnected;
                            } catch (e) {
                                this.qzConnected = false;
                                this.printProvider = 'N/A';
                                this._qzLastError = this.getLocalAgentErrorMessage(e);
                                return false;
                            }
                        } finally {
                            this._qzMonitorBusy = false;
                        }
                    },

                    startQzAutoReconnect() {
                        if (this._qzMonitorTimer) clearInterval(this._qzMonitorTimer);
                        if (this._qzFastRetryTimer) clearInterval(this._qzFastRetryTimer);

                        const kick = () => this.silentQzReconnect();
                        this._qzMonitorTimer = setInterval(kick, 5000);
                        this._qzFastRetryTimer = setInterval(() => {
                            if (!this.qzConnected) kick();
                        }, 1500);
                        setTimeout(() => {
                            if (this._qzFastRetryTimer) {
                                clearInterval(this._qzFastRetryTimer);
                                this._qzFastRetryTimer = null;
                            }
                        }, 25000);

                        this._onQzVisibilityChange = () => {
                            if (!document.hidden) kick();
                        };
                        this._onQzWindowFocus = () => kick();
                        this._onQzOnline = () => kick();

                        window.addEventListener('visibilitychange', this._onQzVisibilityChange);
                        window.addEventListener('focus', this._onQzWindowFocus);
                        window.addEventListener('online', this._onQzOnline);

                        setTimeout(kick, 1200);
                        window.addEventListener('beforeunload', () => this.stopQzAutoReconnect());
                    },

                    stopQzAutoReconnect() {
                        if (this._qzFastRetryTimer) {
                            clearInterval(this._qzFastRetryTimer);
                            this._qzFastRetryTimer = null;
                        }
                        if (this._qzMonitorTimer) {
                            clearInterval(this._qzMonitorTimer);
                            this._qzMonitorTimer = null;
                        }
                        if (this._onQzVisibilityChange) {
                            window.removeEventListener('visibilitychange', this._onQzVisibilityChange);
                            this._onQzVisibilityChange = null;
                        }
                        if (this._onQzWindowFocus) {
                            window.removeEventListener('focus', this._onQzWindowFocus);
                            this._onQzWindowFocus = null;
                        }
                        if (this._onQzOnline) {
                            window.removeEventListener('online', this._onQzOnline);
                            this._onQzOnline = null;
                        }
                    },

                    _qzInitRunning: false,
                    _qzConnectFromPrint: false,
                    async initQzTray() {
                        if (this._qzInitRunning) return;
                        this._qzInitRunning = true;

                        try {
                            if (!this.canUseLocalAgent()) {
                                this.qzConnected = false;
                                this.printProvider = 'N/A';
                                this._qzLastError = 'Safari en modo app no permite conexión local con Sistemax Agent. Se usará impresión web.';
                                return;
                            }
                            if (typeof SmxPrinter === 'undefined') {
                                throw new Error('SmxPrinter no cargado');
                            }
                            this.smxPrinter = new SmxPrinter({
                                strategy: 'agent-only',
                                agentBaseUrl: 'http://127.0.0.1:17890'
                            });

                            this._qzConnectFromPrint = false;
                            await this.ensureQzConnected();

                            try {
                                this._qzPrintersList = await this.smxPrinter.findPrinters();
                            } catch(e) {}
                        } catch (err) {
                            this.qzConnected = false;
                            this.printProvider = 'N/A';
                        } finally {
                            this._qzInitRunning = false;
                        }
                    },

                    async refreshPrintersList() {
                        if (!this.smxPrinter || !this.qzConnected) {
                            this._qzPrintersList = [];
                            return;
                        }
                        try {
                            this._qzPrintersList = await this.smxPrinter.findPrinters();
                        } catch (e) {
                            this._qzPrintersList = [];
                        }
                    },

                    getManagedDocumentPrintPrefsKey() {
                        return `smx_managed_doc_print_prefs:${this.idEmpresa}`;
                    },

                    restoreManagedDocumentPrintPreferences() {
                        try {
                            const raw = localStorage.getItem(this.getManagedDocumentPrintPrefsKey());
                            const prefs = raw ? JSON.parse(raw) : {};
                            const mode = String(prefs?.mode || '').toLowerCase();
                            const printer = String(prefs?.printer || '').trim();
                            const width = String(prefs?.escpos_width || '').trim();
                            if (['auto', 'escpos', 'a4', 'a5'].includes(mode)) {
                                this.managedDocumentPrintMode = mode;
                            }
                            if (width === '32' || width === '48') {
                                this.managedDocumentEscposWidth = width;
                            }
                            if (printer) {
                                this.managedDocumentPrinter = printer;
                            }
                        } catch (_) {}
                    },

                    persistManagedDocumentPrintPreferences() {
                        try {
                            localStorage.setItem(this.getManagedDocumentPrintPrefsKey(), JSON.stringify({
                                mode: String(this.managedDocumentPrintMode || 'auto').toLowerCase(),
                                printer: String(this.managedDocumentPrinter || '').trim(),
                                escpos_width: String(this.managedDocumentEscposWidth || '48') === '32' ? '32' : '48'
                            }));
                        } catch (_) {}
                    },

                    applyManagedDocumentPhoneValue(rawPhone) {
                        const digits = String(rawPhone || '').replace(/\D/g, '');
                        if (!digits) {
                            this.managedDocumentCountryCode = '595';
                            this.managedDocumentPhone = '';
                            return;
                        }
                        const countriesSorted = [...this.countries].sort((a, b) => String(b.code).length - String(a.code).length);
                        const matched = countriesSorted.find(country => digits.startsWith(String(country.code)));
                        if (matched) {
                            this.managedDocumentCountryCode = String(matched.code);
                            this.managedDocumentPhone = digits.slice(String(matched.code).length);
                            return;
                        }
                        this.managedDocumentCountryCode = '595';
                        this.managedDocumentPhone = digits;
                    },

                    async refreshManagedDocumentPrinters() {
                        this.managedDocumentStatus = '';
                        this.managedDocumentStatusType = 'ok';
                        await this.refreshPrintersList();
                        if (!this._qzPrintersList.length) return;
                        const preferred = String(this.managedDocumentPrinter || this.printerName || '').trim();
                        if (preferred && this._qzPrintersList.includes(preferred)) {
                            this.managedDocumentPrinter = preferred;
                        } else if (preferred) {
                            const match = this._qzPrintersList.find(item => String(item || '').trim().toLowerCase() === preferred.toLowerCase());
                            if (match) {
                                this.managedDocumentPrinter = String(match || '').trim();
                            }
                        }
                        if (!this.managedDocumentPrinter) {
                            this.managedDocumentPrinter = String(this._qzPrintersList[0] || '').trim();
                        }
                        this.persistManagedDocumentPrintPreferences();
                    },

                    resolveManagedDocumentProfile() {
                        const mode = String(this.managedDocumentPrintMode || 'auto').toLowerCase();
                        if (mode === 'escpos') return { mode: 'escpos', paper: 'a4' };
                        if (mode === 'a5') return { mode: 'document', paper: 'a5' };
                        if (mode === 'a4') return { mode: 'document', paper: 'a4' };
                        const printer = String(this.managedDocumentPrinter || this.printerName || '').toLowerCase();
                        if (/(a5)/.test(printer)) return { mode: 'document', paper: 'a5' };
                        if (/(laser|deskjet|officejet|brother|canon|xerox|ricoh|kyocera|pantum|epson l|ecotank|pdf|a4)/.test(printer)) {
                            return { mode: 'document', paper: 'a4' };
                        }
                        return { mode: 'escpos', paper: 'a4' };
                    },

                    openManagedDocumentWebPrint(paper = 'a4', autoPrint = false) {
                        if (!this.lastVenta?.id_factura) return;
                        const endpoint = this.isLastDocumentPedido() ? 'ticket_pedido.php' : 'ticket_presupuesto.php';
                        const nroParam = encodeURIComponent((this.lastVenta?.nro_factura || '').trim());
                        const safePaper = String(paper || '').toLowerCase() === 'a5' ? 'a5' : 'a4';
                        const auto = autoPrint ? '&autoprint=1' : '';
                        const url = `${endpoint}?id=${this.lastVenta.id_factura}&id_empresa=${this.idEmpresa}&nro=${nroParam}&pdf=1&paper=${safePaper}${auto}`;
                        window.open(url, '_blank', 'noopener');
                    },

                    async sendManagedDocumentByEmail() {
                        if (!this.lastVenta?.id_factura) return;
                        const email = String(this.managedDocumentEmail || '').trim();
                        if (!email || !email.includes('@')) {
                            this.managedDocumentStatus = 'Ingrese un email valido';
                            this.managedDocumentStatusType = 'error';
                            return;
                        }
                        this.managedDocumentStatus = '';
                        this.managedDocumentStatusType = 'ok';
                        try {
                            const printPath = this.isLastDocumentPedido() ? 'ticket_pedido.php' : 'ticket_presupuesto.php';
                            const res = await fetch('/public/pos/api/documentos_send_email.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                credentials: 'same-origin',
                                body: JSON.stringify({
                                    id_factura: Number(this.lastVenta.id_factura || 0),
                                    email,
                                    print_path: printPath,
                                    document_label: this.isLastDocumentPedido() ? 'Pedido' : 'Presupuesto',
                                    document_number: this.lastVenta.nro_factura || '',
                                    cliente: this.lastVenta.cliente || this.lastVenta.cliente_nombre || '',
                                    fecha: this.lastVenta.fecha || '',
                                    total: `${this.formatMoney(this.lastVenta.total || 0)} Gs`
                                })
                            });
                            const data = await res.json();
                            if (!data?.success) {
                                throw new Error(data?.error || 'No se pudo enviar el email');
                            }
                            this.managedDocumentStatus = data.message || 'Email enviado correctamente';
                            this.managedDocumentStatusType = 'ok';
                        } catch (e) {
                            this.managedDocumentStatus = e?.message || 'No se pudo enviar el email';
                            this.managedDocumentStatusType = 'error';
                        }
                    },

                    sendManagedDocumentByWhatsApp() {
                        if (!this.lastVenta?.id_factura) return;
                        const phone = `${String(this.managedDocumentCountryCode || '').replace(/\D/g, '')}${String(this.managedDocumentPhone || '').replace(/\D/g, '')}`;
                        if (!phone) {
                            this.managedDocumentStatus = 'Ingrese un numero valido para WhatsApp';
                            this.managedDocumentStatusType = 'error';
                            return;
                        }
                        const message = this.isLastDocumentPedido()
                            ? this.buildPedidoWhatsAppMessage()
                            : this.buildPresupuestoWhatsAppMessage();
                        this.managedDocumentStatus = '';
                        window.open(`https://wa.me/${phone}?text=${encodeURIComponent(message)}`, '_blank', 'noopener');
                    },

                    closeManagedDocumentActionsModal() {
                        this.showManagedDocumentActionsModal = false;
                        this.managedDocumentStatus = '';
                        this.managedDocumentStatusType = 'ok';
                    },

                    async handleAgentStatusClick() {
                        this.showQzInstallModal = true;
                        if (this.qzCheckingConnection) return;
                        if (this.qzConnected) {
                            await this.refreshPrintersList();
                            return;
                        }
                        await this.retryQzConnect();
                    },

                    toggleDirectPrint() {
                        this.directPrintEnabled = !this.directPrintEnabled;
                        localStorage.setItem('pos_direct_print_enabled', this.directPrintEnabled ? '1' : '0');
                        this.toast(
                            this.directPrintEnabled
                                ? 'Impresión directa activada'
                                : 'Impresión directa desactivada. Se usará impresión web/PDF',
                            this.directPrintEnabled ? 'success' : 'warning'
                        );
                    },

                    async retryQzConnect() {
                        if (!this.canUseLocalAgent()) {
                            this.qzConnected = false;
                            this.printProvider = 'N/A';
                            this._qzLastError = 'Safari en modo app no permite conexión local con Sistemax Agent.';
                            await this.showAlert(
                                'Impresión Web',
                                'Estás usando SistemaX como app de Safari.\nEn este modo, Apple bloquea la conexión local al Agent.\n\nSe usará impresión web automáticamente.',
                                '🖨️',
                                'warning'
                            );
                            return false;
                        }
                        this.qzCheckingConnection = true;
                        this._qzLastError = '';
                        try {
                            if (!this.smxPrinter) {
                                throw new Error('Bridge de impresión no disponible');
                            }
                            await this.smxPrinter.disconnect();
                            this._qzConnectPromise = null;
                            this.qzConnected = false;
                            this.printProvider = 'N/A';

                            this._qzConnectFromPrint = true;
                            await this.ensureQzConnected();
                            this._qzConnectFromPrint = false;

                            await this.refreshPrintersList();

                            this.playSound('success');
                            await this.showAlert('Conectado', 'Sistemax Agent conectado.\nImpresión directa activada.' + (this._qzPrintersList.length ? '\n\nImpresoras: ' + this._qzPrintersList.join(', ') : ''), '✅', 'success');
                            this.showQzInstallModal = false;
                            return true;
                        } catch (e) {
                            this.qzConnected = false;
                            this.printProvider = 'N/A';
                            this._qzLastError = this.getLocalAgentErrorMessage(e);
                            this.playSound('error');
                            console.error('❌ Sistemax Agent:', e);
                            await this.showAlert('Conexión Fallida', e.message + '\n\nSe usará impresión web como respaldo.', '❌', 'danger');
                            return false;
                        } finally {
                            this.qzCheckingConnection = false;
                        }
                    },

                    async loadPrintConfig() {
                        if (!this.idCaja) return '';
                        try {
                            const res = await fetch(`api/caja.php?action=print_config&id_caja=${this.idCaja}`, {
                                cache: 'no-store',
                                credentials: 'same-origin'
                            });
                            const data = await res.json();
                            if (data && data.success) {
                                const imp = String(data.impresora || '').trim();
                                if (imp) this.printerName = imp;
                                return imp;
                            }
                        } catch (e) {
                            console.warn('No se pudo refrescar config de impresión:', e);
                        }
                        return String(this.printerName || '').trim();
                    },

                    async resolveDirectPrinter() {
                        if (!this.smxPrinter) {
                            throw new Error('Bridge de impresión no inicializado');
                        }
                        if (typeof this.smxPrinter.getDefaultPrinter === 'function') {
                            const p = String(await this.smxPrinter.getDefaultPrinter() || '').trim();
                            if (p) return p;
                        }
                        const list = await this.smxPrinter.findPrinters();
                        if (!Array.isArray(list) || list.length === 0) {
                            throw new Error('No hay impresoras disponibles en el sistema');
                        }
                        return String(list[0] || '').trim();
                    },

                    getEscposQrMode(printerName = '') {
                        const forced = String(localStorage.getItem('pos_qr_mode') || '').trim().toLowerCase();
                        if (['text', 'native', 'raster'].includes(forced)) return forced;
                        return 'native';
                    },

                    async printCalibration() {
                        try {
                            if (!this.qzConnected) {
                                await this.showAlert('Sin conexión', 'Conectá Sistemax Agent primero', '⚠️', 'warning');
                                return;
                            }
                            let printer = String(this.printerName || '').trim();
                            if (!printer) {
                                printer = await this.loadPrintConfig();
                            }
                            if (!printer) {
                                await this.showAlert('Sin Impresora', 'Configurá una impresora en Cajas primero.', '🖨️', 'warning');
                                return;
                            }
                            const found = await this.smxPrinter.findPrinters(printer);
                            if (!found || found.length === 0) {
                                throw new Error('Impresora "' + printer + '" no encontrada');
                            }
                            const res = await fetch('ticket_calibrar.php?format=json', { cache: 'no-store' });
                            const data = await res.json();
                            if (!data.success || !data.data) throw new Error(data.message || 'No se pudo generar calibración');
                            await this.smxPrinter.printRaw(found[0], data.data);
                            await this.showAlert('Calibración Enviada', 'Revisá el ticket impreso.\nLa línea de ==== que llene TODO el ancho del papel es tu width correcto.', '📏', 'success');
                        } catch (e) {
                            await this.showAlert('Error', e.message, '❌', 'danger');
                        }
                    },

                    getTicketEndpoint(docType, paymentMethod = '') {
                        const dtRaw = String(docType ?? '').trim().toLowerCase();
                        if (dtRaw === 'presupuesto') return 'ticket_presupuesto.php';
                        if (dtRaw === 'pedido') return 'ticket_pedido.php';
                        const pm = String(paymentMethod || '').trim().toLowerCase();
                        if (pm === 'pendiente') return 'ticket_comprobante_pendiente.php';
                        const raw = docType ?? this.selectedDocType ?? 'comun';
                        const dt = String(raw).toLowerCase();
                        if (dt === '3' || dt === 'electro') return 'ticket_factura_electronica.php';
                        if (dt === '1' || dt === 'auto') return 'ticket_factura_autoimpresa.php';
                        return 'ticket_nota_comun.php';
                    },

                    getWebTicketEndpoint(docType, paymentMethod = '') {
                        const endpoint = this.getTicketEndpoint(docType, paymentMethod);
                        return (endpoint === 'ticket_presupuesto.php' || endpoint === 'ticket_pedido.php') ? endpoint : 'ticket.php';
                    },

                    isNotaComunDocType(docType) {
                        const dt = String(docType ?? this.selectedDocType ?? 'comun').toLowerCase();
                        return !(dt === '3' || dt === 'electro' || dt === '1' || dt === 'auto' || dt === 'presupuesto' || dt === 'pedido');
                    },

                    openPedidoPdf(idPedido, nroPedido = '') {
                        const nroParam = encodeURIComponent((nroPedido || '').trim());
                        const url = `ticket_pedido.php?id=${idPedido}&id_empresa=${this.idEmpresa}&nro=${nroParam}&pdf=1&autoprint=1`;
                        window.open(url, '_blank', 'noopener');
                    },

                    isLastDocumentPresupuesto() {
                        return String(this.lastVenta?.tipo_documento || '').toLowerCase() === 'presupuesto';
                    },

                    isLastDocumentPedido() {
                        return String(this.lastVenta?.tipo_documento || '').toLowerCase() === 'pedido';
                    },

                    openPresupuestoPdf(idFactura, nroFactura = '') {
                        const nroParam = encodeURIComponent((nroFactura || '').trim());
                        const url = `ticket_presupuesto.php?id=${idFactura}&id_empresa=${this.idEmpresa}&nro=${nroParam}&pdf=1&autoprint=1`;
                        window.open(url, '_blank', 'noopener');
                    },

                    showManagedDocumentActions() {
                        if (!this.lastVenta?.id_factura) return;
                        this.closeTicketModal();
                        this.restoreManagedDocumentPrintPreferences();
                        this.managedDocumentEmail = String(this.lastVenta?.cliente_email || this.currentTicket?.selectedCliente?.email || '').trim();
                        this.applyManagedDocumentPhoneValue(String(this.lastVenta?.cliente_telefono || this.currentTicket?.selectedCliente?.telefono || '').trim());
                        this.managedDocumentStatus = '';
                        this.managedDocumentStatusType = 'ok';
                        this.showManagedDocumentActionsModal = true;
                        this.$nextTick(() => {
                            this.refreshManagedDocumentPrinters();
                        });
                    },

                    showPresupuestoActions() {
                        this.showManagedDocumentActions();
                    },

                    async printManagedDocument() {
                        if (!this.lastVenta?.id_factura) return;
                        const profile = this.resolveManagedDocumentProfile();
                        const docType = this.isLastDocumentPedido() ? 'pedido' : 'presupuesto';
                        const printer = String(this.managedDocumentPrinter || this.printerName || '').trim();
                        this.managedDocumentStatus = '';
                        this.managedDocumentStatusType = 'ok';
                        if (profile.mode === 'escpos' && this.directPrintEnabled && this.qzConnected && this.canUseLocalAgent()) {
                            this.printerName = printer || this.printerName;
                            await this.directPrint(
                                this.lastVenta.id_factura,
                                docType,
                                this.lastVenta?.nro_factura || '',
                                {
                                    printer,
                                    reprintOnly: true,
                                    escposWidth: String(this.managedDocumentEscposWidth || '48') === '32' ? '32' : '48'
                                }
                            );
                            this.persistManagedDocumentPrintPreferences();
                            return;
                        }
                        this.openManagedDocumentWebPrint(profile.paper, true);
                        this.persistManagedDocumentPrintPreferences();
                        this.closeManagedDocumentActionsModal();
                    },

                    buildPresupuestoWhatsAppMessage() {
                        const presupuesto = this.lastVenta || {};
                        const cliente = presupuesto.cliente || presupuesto.cliente_nombre || 'Cliente';
                        const fecha = presupuesto.fecha ? this.formatDateTimeForWhatsApp(presupuesto.fecha) : '';
                        const items = Array.isArray(presupuesto.items) ? presupuesto.items : [];
                        const itemsText = items.length
                            ? items.map((item, idx) => {
                                const qty = Number(item.cantidad || 0);
                                const price = Number(item.precio || 0);
                                const total = Number(item.precio * item.cantidad || 0);
                                return `${idx + 1}. ${item.descripcion || 'Producto'}\n   Cod: ${item.codigo || '-'} | Cant: ${qty} | Precio: ${this.formatMoney(price)} | Importe: ${this.formatMoney(total)}`;
                            }).join('\n')
                            : 'Sin detalle de items';
                        const pdfUrl = `${window.location.origin}/public/pos/ticket_presupuesto.php?id=${presupuesto.id_factura}&id_empresa=${this.idEmpresa}&pdf=1`;
                        return `*PRESUPUESTO*\n\nNro: ${presupuesto.nro_factura || ('PRES-' + (presupuesto.id_factura || ''))}\nFecha: ${fecha}\nCliente: ${cliente}\nRUC/CI: ${presupuesto.cliente_ruc || '-'}\nTotal: *${this.formatMoney(presupuesto.total || 0)} Gs*\n\n*Items*\n${itemsText}\n\nPDF: ${pdfUrl}\n\n_SistemaX_`;
                    },

                    buildPedidoWhatsAppMessage() {
                        const pedido = this.lastVenta || {};
                        const proveedor = pedido.cliente || pedido.cliente_nombre || 'Proveedor';
                        const fecha = pedido.fecha ? this.formatDateTimeForWhatsApp(pedido.fecha) : '';
                        const items = Array.isArray(pedido.items) ? pedido.items : [];
                        const itemsText = items.length
                            ? items.map((item, idx) => {
                                const qty = Number(item.cantidad || 0);
                                const price = Number(item.precio || 0);
                                const total = Number(item.importe || (qty * price) || 0);
                                return `${idx + 1}. ${item.descripcion || 'Producto'}\n   Cod: ${item.codigo || '-'} | Cant: ${qty} | Precio: ${this.formatMoney(price)} | Importe: ${this.formatMoney(total)}`;
                            }).join('\n')
                            : 'Sin detalle de items';
                        const pdfUrl = `${window.location.origin}/public/pos/ticket_pedido.php?id=${pedido.id_factura}&id_empresa=${this.idEmpresa}&pdf=1`;
                        return `*PEDIDO A PROVEEDOR*\n\nNro: ${pedido.nro_factura || ('PED-' + (pedido.id_factura || ''))}\nFecha: ${fecha}\nProveedor: ${proveedor}\nRUC/CI: ${pedido.cliente_ruc || '-'}\nTotal: *${this.formatMoney(pedido.total || 0)} Gs*\n\n*Items*\n${itemsText}\n\nPDF: ${pdfUrl}\n\n_SistemaX_`;
                    },

                    formatDateTimeForWhatsApp(raw) {
                        const value = String(raw || '').trim();
                        if (!value) return '';
                        const normalized = value.includes('T') ? value : value.replace(' ', 'T');
                        const date = new Date(normalized);
                        if (Number.isNaN(date.getTime())) return value;
                        const dd = String(date.getDate()).padStart(2, '0');
                        const mm = String(date.getMonth() + 1).padStart(2, '0');
                        const yyyy = String(date.getFullYear());
                        const hh = String(date.getHours()).padStart(2, '0');
                        const mi = String(date.getMinutes()).padStart(2, '0');
                        return `${dd}/${mm}/${yyyy} ${hh}:${mi}`;
                    },

                    getTicketModalWidthPx() {
                        if (this.webPrintModalMode && (this.isLastDocumentPresupuesto() || this.isLastDocumentPedido())) {
                            const maxViewport = Math.max(640, Math.floor((window.innerWidth || 1280) * 0.96));
                            return Math.min(980, maxViewport);
                        }
                        const configured = parseInt(this.userConfig?.ancho_papel || 0, 10);
                        const base = configured > 0 ? Math.min(configured, 1000) : 420;
                        const maxViewport = Math.max(320, Math.floor((window.innerWidth || 1280) * 0.96));
                        return Math.min(base, maxViewport);
                    },

                    closeTicketModal() {
                        this.showTicketModal = false;
                        this.webPrintModalMode = false;
                    },

                    printWebTicketInModal() {
                        try {
                            const frame = document.getElementById('webPrintFrame');
                            if (frame && frame.contentWindow) {
                                frame.contentWindow.focus();
                                frame.contentWindow.print();
                                return;
                            }
                        } catch (e) {
                            console.warn('No se pudo imprimir dentro del modal:', e);
                        }
                        window.open(this.ticketUrl, '_blank');
                    },

                    openWebPrintTicket(idFactura, docType, nroFactura = '', paymentMethod = '') {
                        this.beginPrintFallbackGuard(6000);
                        const endpoint = this.getWebTicketEndpoint(docType, paymentMethod);
                        const nroParam = encodeURIComponent((nroFactura || '').trim());
                        const lowerEndpoint = String(endpoint || '').toLowerCase();
                        const isManagedDocument = lowerEndpoint === 'ticket_presupuesto.php' || lowerEndpoint === 'ticket_pedido.php';
                        const paperParam = isManagedDocument ? '&paper=a4' : '';
                        const url = `${endpoint}?id=${idFactura}&id_empresa=${this.idEmpresa}&width=48&nro=${nroParam}&logo=0&pdf=1&in_modal=1${paperParam}`;
                        this.ticketUrl = url;
                        this.webPrintModalMode = true;
                        this.showTicketModal = true;
                    },

                    async directPrint(idFactura, docType, nroFactura = '', options = {}) {
                        this.beginPrintFallbackGuard(6000);
                        let printer = '';
                        const tipoDoc = docType ?? this.lastVenta?.tipo_documento ?? this.selectedDocType ?? 'comun';
                        const paymentMethodTicket = String(
                            (options && options.paymentMethod)
                            || this.lastVenta?.medio_cobro
                            || this.lastVenta?.payment_method
                            || (this.paymentsList && this.paymentsList[0] ? this.paymentsList[0].method : '')
                            || this.simplePaymentMethod
                            || ''
                        );
                        const isElectro = (String(tipoDoc) === '3' || String(tipoDoc).toLowerCase() === 'electro');
                        const reprintOnly = !!(options && options.reprintOnly);
                        const allowPendingFE = !!(options && options.allowPendingFE);

                        console.log('🖨️ directPrint:', { idFactura, tipoDoc, printer });

                        if (!this.directPrintEnabled) {
                            this.openWebPrintTicket(idFactura, tipoDoc, nroFactura, paymentMethodTicket);
                            return;
                        }

                        if (!this.canUseLocalAgent()) {
                            this.openWebPrintTicket(idFactura, tipoDoc, nroFactura, paymentMethodTicket);
                            await this.showAlert(
                                'Impresión Web',
                                'Safari en modo app no permite impresión directa local.\nSe abrió impresión web automáticamente.',
                                '🖨️',
                                'warning'
                            );
                            return;
                        }

                        try {
                            this._qzConnectFromPrint = true;
                            await this.ensureQzConnected();
                            this._qzConnectFromPrint = false;

                            printer = String((options && options.printer) || '').trim();
                            if (!printer) {
                                printer = await this.resolveDirectPrinter();
                            }
                            this.printerName = printer;
                            const found = await this.smxPrinter.findPrinters(printer);
                            if (!found || found.length === 0) {
                                throw new Error('Impresora "' + printer + '" no encontrada en el sistema');
                            }
                            console.log('🖨️ Impresora encontrada:', found[0]);

                            if (isElectro) {
                                let needsEmit = true;
                                try {
                                    const metaRes = await fetch(`api/get_venta.php?id=${idFactura}&id_empresa=${this.idEmpresa}`);
                                    const metaData = await metaRes.json();
                                    const f = metaData?.factura || {};
                                    const cdcVal = String(f.cdc || this.lastVenta?.cdc || '').trim();
                                    const protVal = String(f.prot_cons_lote_sifen || '').trim();
                                    const xmlVal = String(f.xml_respuesta || '').trim();
                                    const estadoSifen = String(f.estado_sifen || '').trim().toLowerCase();
                                    const isAprobado = (estadoSifen === 'aprobado');
                                    needsEmit = !(isAprobado && cdcVal.length > 10 && (protVal.length > 0 || xmlVal.length > 0));
                                } catch (metaErr) {
                                    console.warn('No se pudo validar estado FE antes de imprimir:', metaErr);
                                    needsEmit = true;
                                }

                                if (needsEmit && !allowPendingFE && !reprintOnly) {
                                    throw new Error('Esta FE aún no está aprobada por SIFEN. Reintentá cuando figure como Aprobada.');
                                }
                            }

                            const escposWidth = String((options && options.escposWidth) || '48') === '32' ? '32' : '48';
                            const nroParam = encodeURIComponent((nroFactura || '').trim());
                            const endpoint = this.getTicketEndpoint(tipoDoc, paymentMethodTicket);
                            const qrMode = this.getEscposQrMode(found[0] || printer);
                            const res = await fetch(`${endpoint}?id=${idFactura}&id_empresa=${this.idEmpresa}&width=${encodeURIComponent(escposWidth)}&nro=${nroParam}&logo=0&qr_mode=${encodeURIComponent(qrMode)}`);
                            const ticketData = await res.json();

                            if (!ticketData.success || !ticketData.data) {
                                throw new Error(ticketData.message || 'Error generando ticket ESC/POS');
                            }

                            await this.smxPrinter.printRaw(found[0], ticketData.data);

                            const infoExtra = ticketData.items_count !== undefined
                                ? ` | Items: ${ticketData.items_count}`
                                : '';
                            console.log('✅ Impresión ESC/POS enviada a', printer, '| tipo:', ticketData.tipo, infoExtra);
                            this.closeTicketModal();
                            this.closeManagedDocumentActionsModal();
                            this.showCheckoutModal = false;
                            this.showSimpleChangeModal = false;
                            this.focusPrimaryInput();
                        } catch (printErr) {
                            this.qzConnected = this.smxPrinter ? this.smxPrinter.isActive() : false;
                            this.printProvider = this.qzConnected ? this.smxPrinter.getProviderLabel() : 'N/A';
                            console.error('❌ Error de impresión:', printErr.message);
                            this._pendingPrintId = idFactura;
                            this.openWebPrintTicket(idFactura, tipoDoc, nroFactura, paymentMethodTicket);
                            await this.showAlert('Impresión Web', 'No se pudo usar impresión directa. Se abrió impresión web automáticamente.', '🖨️', 'warning');
                        }
                    },

                    detectClientOs() {
                        const ua = navigator.userAgent || '';
                        if (/Android/i.test(ua)) return 'android';
                        if (/Macintosh|Mac OS X/i.test(ua)) return 'macos';
                        if (/Windows/i.test(ua)) return 'windows';
                        if (/Linux/i.test(ua)) return 'linux';
                        return 'otro';
                    },

                    isSafariStandalonePwa() {
                        try {
                            const ua = navigator.userAgent || '';
                            const isSafari = /Safari/i.test(ua) && !/Chrome|CriOS|Edg|OPR|Firefox|FxiOS/i.test(ua);
                            const isStandalone = !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
                                || (typeof navigator.standalone !== 'undefined' && !!navigator.standalone);
                            return isSafari && isStandalone;
                        } catch (e) {
                            return false;
                        }
                    },

                    canUseLocalAgent() {
                        return !this.isSafariStandalonePwa();
                    },

                    getNativeAgentInstallerUrl() {
                        // Ajustar rutas cuando se publique el instalador en producción
                        if (this.nativeAgentOs === 'windows') return '/public/pos/downloads/Sistemax-Agent-Setup-0.1.4.exe';
                        if (this.nativeAgentOs === 'macos') return '/public/pos/downloads/sistemax-agent-macos-0.1.4.tar.gz';
                        if (this.nativeAgentOs === 'linux') return '/public/pos/downloads/sistemax-agent-linux-0.1.2.tar.gz';
                        if (this.nativeAgentOs === 'android') {
                            if (this.androidApkAvailable) return this.getNativeAgentAndroidApkUrl();
                            return '/public/pos/downloads/sistemax-agent-android-source-0.1.0.tar.gz';
                        }
                        return '';
                    },

                    getNativeAgentAndroidApkUrl() {
                        return this.androidApkUrl || '/public/pos/downloads/sistemax-print-android.apk';
                    },

                    getNativeAgentAndroidApkCandidates() {
                        return [
                            '/public/pos/downloads/sistemax-print-android.apk',
                            '/public/pos/downloads/sistemax-print-android-0.3.1.apk',
                            '/public/pos/downloads/sistemax-agent-android.apk',
                            '/public/pos/downloads/sistemax-agent-android-0.2.3.apk',
                            '/public/pos/downloads/sistemax-agent-android-0.2.2.apk',
                            '/public/pos/downloads/sistemax-agent-android-0.2.1.apk',
                            '/public/pos/downloads/sistemax-agent-android-0.2.0.apk',
                            '/public/pos/downloads/sistemax-agent-android-0.1.1.apk',
                            '/public/pos/downloads/sistemax-agent-android-0.1.0.apk'
                        ];
                    },

                    getNativeAgentOsLabel() {
                        if (this.nativeAgentOs === 'windows') return 'Windows';
                        if (this.nativeAgentOs === 'macos') return 'macOS';
                        if (this.nativeAgentOs === 'linux') return 'Linux';
                        if (this.nativeAgentOs === 'android') return 'Android';
                        return 'tu sistema';
                    },

                    getNativeAgentInstallLabel() {
                        if (this.nativeAgentOs === 'android') {
                            return this.androidApkAvailable ? 'Instalar ahora (Android)' : 'Descargar fuente Android';
                        }
                        return 'Instalar en ' + this.getNativeAgentOsLabel();
                    },

                    getManualAgentSteps(installerUrl = '') {
                        if (this.nativeAgentOs === 'windows') {
                            return [
                                'Windows (fácil):',
                                '1) Se abrió una pestaña para descargar el instalador.',
                                '2) Esperá que termine la descarga.',
                                '3) Abrí la carpeta Descargas.',
                                '4) Hacé doble clic en: Sistemax-Agent-Setup-0.1.4.exe',
                                '5) Tocá siempre \"Siguiente\" hasta finalizar.',
                                '6) Abrí la app \"Sistemax Agent\".',
                                '7) Volvé al POS y tocá \"Reintentar conexión directa\".'
                            ].join('\n');
                        }
                        if (this.nativeAgentOs === 'macos') {
                            return [
                                'Mac (paso a paso):',
                                '1) Se abrió una pestaña para descargar el archivo.',
                                '2) Esperá que termine la descarga.',
                                '3) Abrí Descargas y hacé doble clic al archivo descargado.',
                                '4) Si aparece una carpeta, abrila.',
                                '5) Si no te deja abrir, mirá el botón \"Video tutorial\".',
                                '6) Cuando la app Sistemax Agent esté abierta, volvé al POS.',
                                '7) Tocá \"Reintentar conexión directa\".'
                            ].join('\n');
                        }
                        if (this.nativeAgentOs === 'linux') {
                            return [
                                'Linux (fácil):',
                                '1) Descargá el archivo que se abrió en la pestaña.',
                                '2) Abrí la carpeta Descargas.',
                                '3) Descomprimí el archivo descargado.',
                                '4) Abrí la carpeta resultante.',
                                '5) Ejecutá el instalador del Agent.',
                                '6) Volvé al POS y tocá \"Reintentar conexión directa\".'
                            ].join('\n');
                        }
                        return [
                            'Inicio manual requerido:',
                            '1) Instalá y abrí Sistemax Agent.',
                            '2) Volvé al POS.',
                            '3) Tocá \"Reintentar conexión directa\".'
                        ].join('\n');
                    },

                    buildAgentWakePayload() {
                        // Ticket mínimo válido para despertar app Android vía deeplink.
                        const txt = "SISTEMAX AGENT WAKE\\n\\n\\n";
                        try {
                            return btoa(unescape(encodeURIComponent(txt)));
                        } catch (e) {
                            return btoa("SISTEMAX AGENT WAKE\\n\\n\\n");
                        }
                    },

                    getNativeAgentDeepLinks() {
                        const payload = this.buildAgentWakePayload();
                        return [
                            `sistemaxagent://print?data=${encodeURIComponent(payload)}`,
                            `intent://print?data=${encodeURIComponent(payload)}#Intent;scheme=sistemaxagent;package=pro.sistemax.agent;end`,
                            `rawbt:base64,${payload}`
                        ];
                    },

                    async launchNativeAgentFromWeb() {
                        if (this.launchingAgent) return;
                        this.launchingAgent = true;
                        try {
                            // En desktop web, el navegador no puede iniciar procesos nativos por seguridad.
                            // Se intenta reconectar primero y, si no responde, se guía a instalación/arranque manual.
                            if (this.nativeAgentOs !== 'android') {
                                const ok = await this.retryQzConnect();
                                if (ok) return;

                                const installerUrl = this.getNativeAgentInstallerUrl();
                                if (installerUrl) {
                                    try { window.open(installerUrl, '_blank', 'noopener'); } catch (_) {}
                                }
                                const pasos = this.getManualAgentSteps(installerUrl);
                                await this.showAlert(
                                    'Inicio manual requerido',
                                    'El navegador no puede iniciar el Agent automáticamente en este sistema.\n\n' + pasos + (installerUrl ? '\n\nSe abrió el instalador en otra pestaña.' : ''),
                                    '🛠️',
                                    'warning'
                                );
                                return;
                            }

                            const links = this.getNativeAgentDeepLinks();
                            // Android: esquema correcto soportado por la app (host print + data base64)
                            try {
                                window.location.href = links[0];
                            } catch (_) {}

                            // Fallback por iframes ocultos
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
                            setTimeout(() => launchByIframe(links[1]), 120);
                            setTimeout(() => launchByIframe(links[2]), 280);

                            // Reintentar conexión al bridge local luego del arranque.
                            setTimeout(() => this.retryQzConnect(), 1300);
                        } finally {
                            setTimeout(() => { this.launchingAgent = false; }, 1800);
                        }
                    },

                    async checkNativeAgentAtStartup() {
                        this.nativeAgentChecking = true;
                        this.nativeAgentOs = this.detectClientOs();
                        if (this.nativeAgentOs === 'android') {
                            this.checkAndroidApkAvailability();
                        }
                        this.nativeAgentInstalled = false;
                        this.nativeAgentVersion = '';

                        try {
                            const res = await fetch('http://127.0.0.1:17890/health', {
                                method: 'GET',
                                cache: 'no-store'
                            });
                            if (res.ok) {
                                const data = await res.json();
                                if (data && data.ok) {
                                    this.nativeAgentInstalled = true;
                                    this.nativeAgentVersion = data.version || '';
                                    this.nativeAgentDownloadReady = true;
                                    localStorage.setItem('native_agent_download_ready', '1');
                                }
                            }
                        } catch (e) {
                            // Si falla, asumimos que no está instalado/activo
                        } finally {
                            this.nativeAgentChecking = false;
                        }

                        if (!this.nativeAgentInstalled) {
                            this._qzLastError = 'Sistemax Agent no detectado en este equipo.';
                            this.showQzInstallModal = true;
                        }
                    },

                    async openNativeAgentInstaller() {
                        await this.installNativeAgent();
                    },

                    async checkAndroidApkAvailability() {
                        this.androidApkAvailable = false;
                        for (const url of this.getNativeAgentAndroidApkCandidates()) {
                            try {
                                const res = await fetch(url, {
                                    method: 'HEAD',
                                    cache: 'no-store'
                                });
                                if (res.ok) {
                                    this.androidApkAvailable = true;
                                    this.androidApkUrl = url;
                                    break;
                                }
                            } catch (_) {
                                // continuar candidato
                            }
                        }
                    },

                    addInstallLog(message) {
                        const ts = new Date().toLocaleTimeString('es-PY', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                        this.installTerminalLogs.push(`[${ts}] ${message}`);
                    },

                    async installNativeAgent() {
                        if (this.installingAgent) return;
                        this.installingAgent = true;
                        this.installTerminalLogs = [];
                        this.addInstallLog(`Detectando sistema operativo: ${this.getNativeAgentOsLabel()}`);

                        const url = this.getNativeAgentInstallerUrl();
                        if (!url) {
                            this.addInstallLog('ERROR: instalador no disponible para este sistema.');
                            await this.showAlert(
                                'Instalador no disponible',
                                'No hay instalador configurado para este sistema operativo. Contactá a soporte para recibir el paquete.',
                                '⚠️',
                                'warning'
                            );
                            this.installingAgent = false;
                            return;
                        }
                        this.addInstallLog(`Descargando paquete: ${url}`);

                        try {
                            if (this.nativeAgentOs === 'android' && this.androidApkAvailable) {
                                this.nativeAgentDownloadReady = true;
                                localStorage.setItem('native_agent_download_ready', '1');
                                this.addInstallLog('Abriendo instalador APK de Android...');
                                window.location.href = url;
                                return;
                            }

                            const res = await fetch(url, { cache: 'no-store' });
                            if (!res.ok) throw new Error(`HTTP ${res.status}`);
                            const blob = await res.blob();
                            const filename = url.split('/').pop() || 'sistemax-agent.tar.gz';
                            const dlUrl = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = dlUrl;
                            a.download = filename;
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            URL.revokeObjectURL(dlUrl);

                            this.nativeAgentDownloadReady = true;
                            localStorage.setItem('native_agent_download_ready', '1');
                            this.addInstallLog(`Descarga completada: ${filename}`);

                            if (this.nativeAgentOs === 'windows') {
                                this.addInstallLog('Paso manual: extraé el paquete y ejecutá windows/build-windows.ps1');
                                this.addInstallLog('Luego ejecutá el instalador .exe generado y volvé para conectar.');
                            } else if (this.nativeAgentOs === 'macos') {
                                this.addInstallLog('Paso manual: extraé el paquete y ejecutá ./install-macos.sh');
                            } else if (this.nativeAgentOs === 'linux') {
                                this.addInstallLog('Paso manual: extraé el paquete y ejecutá ./install-linux.sh');
                            } else if (this.nativeAgentOs === 'android') {
                                if (this.androidApkAvailable) {
                                    this.addInstallLog('APK descargado: abrí Descargas, tocá el archivo y elegí Instalar.');
                                    this.addInstallLog('Si Android bloquea: habilitá "Instalar apps desconocidas" para tu navegador.');
                                } else {
                                    this.addInstallLog('Paso manual Android: extraé el paquete y abrí agent/android en Android Studio.');
                                    this.addInstallLog('Compilá APK (Build > Build APKs), instalá en el móvil y volvé a imprimir.');
                                }
                            }

                            this.addInstallLog('Intentando conectar automáticamente...');
                            const connected = await this.retryQzConnect();
                            if (connected && this.qzConnected) {
                                this.addInstallLog('Conexión verificada.');
                            } else {
                                this.addInstallLog('Conexión no disponible. Ejecutá la instalación y luego tocá "Ya instalé, conectar".');
                            }
                        } catch (e) {
                            this.addInstallLog(`ERROR: ${e.message || 'falló la descarga/instalación'}`);
                        } finally {
                            this.installingAgent = false;
                        }
                    },

                    markNativeAgentDownloaded() {
                        this.nativeAgentDownloadReady = true;
                        localStorage.setItem('native_agent_download_ready', '1');
                    },

                    updateTime() {
                        this.currentTime = new Date().toLocaleString('es-PY', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                    },

                    formatMoney(amount) {
                        return Math.round(Number(amount || 0)).toLocaleString('es-PY');
                    },

                    formatNum(n, minDec = 0, maxDec = 2) {
                        if (n === null || n === undefined || isNaN(n)) return '';
                        return Number(n).toLocaleString('es-PY', {
                            minimumFractionDigits: minDec,
                            maximumFractionDigits: maxDec
                        });
                    },

                    parseNum(s) {
                        if (s === null || s === undefined || s === '') return 0;
                        // Eliminar separadores de miles (puntos) y cambiar coma decimal por punto
                        let clean = s.toString().replace(/\./g, '').replace(',', '.');
                        return parseFloat(clean) || 0;
                    },

                    normalizeIvaRate(raw) {
                        const v = parseInt(raw, 10);
                        if (v === 1 || v === 0) return 0;   // Exenta
                        if (v === 2 || v === 5) return 5;   // IVA 5%
                        if (v === 3 || v === 10) return 10; // IVA 10%
                        return 10;
                    },

                    formatIvaLabel(raw) {
                        const iva = this.normalizeIvaRate(raw);
                        return iva === 0 ? 'Exenta' : `${iva}%`;
                    },

                    // Productos
                    async loadCategories() {
                        try {
                            const res = await fetch(`api/productos.php?action=categories&id_empresa=${this.idEmpresa}`);
                            const data = await res.json();
                            this.categories = data.categories || [];
                        } catch (e) {
                            console.error('Error loading categories:', e);
                        }
                    },

                    async loadPopularProducts() {
                        this.popularProducts = [];
                        this.popularDebug = { source: 'deprecated', count: 0, error: '' };
                        this.loadingPopular = false;
                        return;

                        const cacheKey = this.getPopularProductsCacheKey();
                        const cached = this.readPopularProductsCache(cacheKey);
                        if (cached.length > 0) {
                            this.popularProducts = this.sortProductsForDisplay(cached);
                            this.popularDebug = { source: 'cache', count: cached.length, error: '' };
                        }
                        this.loadingPopular = (cached.length === 0);
                        try {
                            const primaryAction = this.ultraFastPosMode ? 'popular_simple' : 'popular';
                            const primaryParams = new URLSearchParams({
                                action: primaryAction,
                                id_empresa: this.idEmpresa,
                                tipo_precio: this.selectedPriceType
                            });
                            if (this.ultraFastPosMode) primaryParams.append('fast', '1');
                            const res = await fetch(`api/productos.php?${primaryParams.toString()}`);
                            const data = await res.json();
                            let productos = data.productos || [];
                            let source = primaryAction;

                            // Fallback 1 (rápido y seguro): lista simple de productos activos.
                            if (!Array.isArray(productos) || productos.length === 0) {
                                const simpleParams = new URLSearchParams({
                                    action: 'popular_simple',
                                    id_empresa: this.idEmpresa,
                                    tipo_precio: this.selectedPriceType
                                });
                                if (this.ultraFastPosMode) simpleParams.append('fast', '1');
                                const resSimple = await fetch(`api/productos.php?${simpleParams.toString()}`);
                                const dataSimple = await resSimple.json();
                                productos = Array.isArray(dataSimple?.productos) ? dataSimple.productos : [];
                                source = 'popular_simple';
                            }

                            // Fallback 2: búsqueda base.
                            if (!Array.isArray(productos) || productos.length === 0) {
                                const fallbackParams = new URLSearchParams({
                                    action: 'search',
                                    q: '',
                                    id_empresa: this.idEmpresa,
                                    tipo_precio: this.selectedPriceType
                                });
                                if (this.ultraFastPosMode) fallbackParams.append('fast', '1');
                                const resFallback = await fetch(`api/productos.php?${fallbackParams.toString()}`);
                                const dataFallback = await resFallback.json();
                                productos = Array.isArray(dataFallback?.productos) ? dataFallback.productos.slice(0, 24) : [];
                                source = 'search';
                            }

                            this.popularProducts = this.sortProductsForDisplay(Array.isArray(productos) ? productos : []);
                            this.popularDebug = { source, count: this.popularProducts.length, error: '' };
                            if (!this.ultraFastPosMode && this.popularProducts.length > 0) {
                                this.writePopularProductsCache(cacheKey, this.popularProducts);
                            }
                            if (this.ultraFastPosMode && this.popularProducts.length > 0) {
                                this.loadPopularProductsDetails(cacheKey);
                            }
                        } catch (e) {
                            console.error('Error loading popular products:', e);
                            try {
                                const simpleParams = new URLSearchParams({
                                    action: 'popular_simple',
                                    id_empresa: this.idEmpresa,
                                    tipo_precio: this.selectedPriceType
                                });
                                if (this.ultraFastPosMode) simpleParams.append('fast', '1');
                                const resSimple = await fetch(`api/productos.php?${simpleParams.toString()}`);
                                const dataSimple = await resSimple.json();
                                this.popularProducts = this.sortProductsForDisplay(Array.isArray(dataSimple?.productos) ? dataSimple.productos : []);
                                this.popularDebug = { source: 'popular_simple(catch)', count: this.popularProducts.length, error: '' };
                            } catch (e2) {
                                console.error('Error loading popular_simple fallback:', e2);
                                this.popularDebug = { source: 'error', count: 0, error: String(e2?.message || e?.message || 'unknown') };
                            }
                        } finally {
                            this.loadingPopular = false;
                        }
                    },

                    async loadPopularProductsDetails(cacheKey = '') {
                        try {
                            const params = new URLSearchParams({
                                action: 'popular',
                                id_empresa: this.idEmpresa,
                                tipo_precio: this.selectedPriceType
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            const productos = Array.isArray(data?.productos) ? data.productos : [];
                            if (productos.length === 0) return;

                            this.popularProducts = this.sortProductsForDisplay(productos);
                            this.popularDebug = { source: 'popular(full)', count: this.popularProducts.length, error: '' };
                            if (cacheKey && this.popularProducts.length > 0) {
                                this.writePopularProductsCache(cacheKey, this.popularProducts);
                            }
                        } catch (e) {
                            console.error('Error loading popular full details:', e);
                        }
                    },

                    getPopularProductsCacheKey() {
                        return `pos_popular_products_${this.idEmpresa}_${this.selectedPriceType}_${this.ultraFastPosMode ? 'fast' : 'full'}`;
                    },

                    readPopularProductsCache(cacheKey) {
                        try {
                            const raw = localStorage.getItem(cacheKey);
                            if (!raw) return [];
                            const parsed = JSON.parse(raw);
                            const ts = Number(parsed?.ts || 0);
                            const items = Array.isArray(parsed?.items) ? parsed.items : [];
                            if (!ts || items.length === 0) return [];
                            if ((Date.now() - ts) > this.popularProductsCacheTtlMs) return [];
                            return items;
                        } catch (e) {
                            return [];
                        }
                    },

                    writePopularProductsCache(cacheKey, items) {
                        try {
                            localStorage.setItem(cacheKey, JSON.stringify({
                                ts: Date.now(),
                                items: Array.isArray(items) ? items : []
                            }));
                        } catch (e) {
                            // Ignorar errores de cuota de storage.
                        }
                    },

                    getSearchProductsCacheKey(payload = {}) {
                        const mode = String(payload.mode || (this.ultraFastPosMode ? 'fast' : 'full'));
                        const normalized = {
                            q: String(payload.q || '').trim().toLowerCase(),
                            estado: String(payload.estado || ''),
                            tipo_precio: String(payload.tipo_precio || ''),
                            price_op: String(payload.price_op || ''),
                            price_val: String(payload.price_val || ''),
                            empresa: String(this.idEmpresa || ''),
                            mode
                        };
                        return `pos_search_products_${btoa(unescape(encodeURIComponent(JSON.stringify(normalized))))}`;
                    },

                    readSearchProductsCache(cacheKey) {
                        try {
                            const raw = localStorage.getItem(cacheKey);
                            if (!raw) return [];
                            const parsed = JSON.parse(raw);
                            const ts = Number(parsed?.ts || 0);
                            const items = Array.isArray(parsed?.items) ? parsed.items : [];
                            if (!ts) return [];
                            if ((Date.now() - ts) > this.searchProductsCacheTtlMs) return [];
                            return items;
                        } catch (e) {
                            return [];
                        }
                    },

                    writeSearchProductsCache(cacheKey, items) {
                        try {
                            localStorage.setItem(cacheKey, JSON.stringify({
                                ts: Date.now(),
                                items: Array.isArray(items) ? items : []
                            }));
                        } catch (e) {
                            // Ignorar errores de cuota de storage.
                        }
                    },

                    // Cuando cambia el tipo de precio
                    async onPriceTypeChange() {
                        // Actualizar productos populares con el nuevo precio
                        this.loadPopularProducts();
                        // Si hay resultados de búsqueda, actualizarlos también
                        if (this.productos.length > 0) {
                            this.searchProducts();
                        }
                        // Actualizar precios del carrito
                        await this.updateCartPrices();
                    },

                    // Actualizar precios del carrito según tipo de precio seleccionado
                    async updateCartPrices() {
                        if (this.cart.length === 0) return;

                        const ids = this.cart.map(item => item.id);
                        try {
                            const res = await fetch(`api/productos.php?action=get_prices&ids=${ids.join(',')}&tipo_precio=${this.selectedPriceType}&id_empresa=${this.idEmpresa}`);
                            const data = await res.json();

                            if (data.precios) {
                                this.cart.forEach(item => {
                                    const nuevoPrecio = data.precios[item.id];
                                    if (nuevoPrecio !== undefined && nuevoPrecio > 0) {
                                        item.precio = parseFloat(nuevoPrecio);
                                    }
                                });
                                this.recalculate();
                                this.toast('Precios actualizados', 'success');
                            }
                        } catch (e) {
                            console.error('Error actualizando precios del carrito:', e);
                        }
                    },

                    // ===== BALANZA ELECTRÓNICA =====
                    async detectarBalanza(codigo) {
                        // Solo intentar si parece un código numérico de balanza
                        if (!codigo || !/^[0-9]{6,}$/.test(codigo)) {
                            return false;
                        }
                        this.balanzaLoading = true;
                        try {
                            // Usar el nuevo endpoint directo
                            const url = `../interpretar_codigo/check_balanza.php?codigo=${encodeURIComponent(codigo)}&id_empresa=${this.idEmpresa}`;
                            const res = await fetch(url);
                            const data = await res.json();
                            console.log('Respuesta balanza:', data);
                            if (data && data.es_balanza === true) {
                                await this.mostrarBalanzaUI(data);
                                return true;
                            }
                        } catch (e) {
                            console.error('Error detectando balanza', e);
                        } finally {
                            this.balanzaLoading = false;
                        }
                        return false;
                    },

                    async mostrarBalanzaUI(datos) {
                        let enriched = {
                            ...(datos || {})
                        };
                        const prod = await this.resolverProductoBalanza(enriched);
                        if (prod) {
                            enriched.descripcion = prod.descripcion || enriched.descripcion;
                            enriched.codigo = prod.codigo || enriched.codigo || prod.barcode;
                            enriched.idproducto = prod.id || enriched.idproducto;
                        }
                        if (!enriched.descripcion) {
                            enriched.descripcion = enriched.codigo || enriched.raw_producto || 'N/D';
                        }

                        // Procesar directamente sin confirmación
                        await this.procesarBalanza(enriched);
                        this.searchQuery = '';
                        this.$nextTick(() => this.$refs.searchInput?.focus());
                    },

                    cancelarBalanza() {
                        this.balanzaData = null;
                        this.$refs.searchInput?.focus();
                    },

                    async confirmarBalanza() {
                        if (!this.balanzaData) return;
                        await this.procesarBalanza(this.balanzaData);
                        this.balanzaData = null;
                        this.searchQuery = '';
                        this.$nextTick(() => this.$refs.searchInput?.focus());
                    },

                    async obtenerProductoPorId(idProd) {
                        try {
                            const params = new URLSearchParams({
                                action: 'by_id',
                                id: idProd,
                                id_empresa: this.idEmpresa
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            return data.producto || null;
                        } catch (e) {
                            console.error('Error obteniendo producto por ID', e);
                            return null;
                        }
                    },

                    async obtenerProductoPorCodigo(code) {
                        try {
                            const params = new URLSearchParams({
                                action: 'search',
                                q: code,
                                id_empresa: this.idEmpresa,
                                estado: this.searchEstado
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            const lista = data.productos || [];
                            if (!lista.length) return null;
                            // Coincidencia exacta por código
                            const exact = lista.find(p => String(p.codigo) === String(code));
                            return exact || lista[0];
                        } catch (e) {
                            console.error('Error obteniendo producto por código', e);
                            return null;
                        }
                    },

                    async obtenerProductoPorBarcode(code) {
                        try {
                            const params = new URLSearchParams({
                                action: 'barcode',
                                cod: code,
                                id_empresa: this.idEmpresa
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            return data.producto || null;
                        } catch (e) {
                            console.error('Error obteniendo producto por barcode', e);
                            return null;
                        }
                    },

                    async obtenerProductoPorBarcode(code) {
                        try {
                            const params = new URLSearchParams({
                                action: 'barcode',
                                cod: code,
                                id_empresa: this.idEmpresa
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            return data.producto || null;
                        } catch (e) {
                            console.error('Error obteniendo producto por barcode', e);
                            return null;
                        }
                    },

                    extraerArticuloDeCodigoBalanza(code) {
                        // Formato típico: PP AAAAA VVVVV C (prefijo, 5 dig producto, 5 dig valor, check)
                        const clean = String(code || '').replace(/\D+/g, '');
                        if (clean.length >= 7) {
                            // Tomar 5 dígitos después de los 2 de prefijo
                            const articulo = clean.slice(2, 7);
                            return articulo.replace(/^0+/, '') || articulo;
                        }
                        return null;
                    },

                    async resolverProductoBalanza(enriched) {
                        // 1) Si backend ya resolvió por codigo_barra, respetar ese idproducto primero.
                        if (enriched?.resuelto_por_codigo_barra && enriched?.idproducto) {
                            const prodByResolvedId = await this.obtenerProductoPorId(enriched.idproducto);
                            if (prodByResolvedId) return prodByResolvedId;
                        }

                        // 2) Por idproducto explícito
                        if (enriched?.idproducto) {
                            const prodById = await this.obtenerProductoPorId(enriched.idproducto);
                            if (prodById) return prodById;
                        }

                        // 3) Extraer artículo desde el código de balanza (cve_producto interno)
                        const articulo = this.extraerArticuloDeCodigoBalanza(enriched.codigo || enriched.raw_producto);
                        if (articulo) {
                            const prodArticulo = await this.obtenerProductoPorCodigo(articulo);
                            if (prodArticulo) return prodArticulo;
                            // probar como id numérico
                            const prodArticuloId = await this.obtenerProductoPorId(articulo);
                            if (prodArticuloId) return prodArticuloId;
                        }
                        // 4) Por raw_producto (podría venir ya como código interno)
                        if (enriched.raw_producto) {
                            const prodCode = await this.obtenerProductoPorCodigo(enriched.raw_producto);
                            if (prodCode) return prodCode;
                            // También intentar con raw_producto sin ceros
                            const trimmed = String(enriched.raw_producto).replace(/^0+/, '');
                            if (trimmed && trimmed !== enriched.raw_producto) {
                                const prodCode2 = await this.obtenerProductoPorCodigo(trimmed);
                                if (prodCode2) return prodCode2;
                            }
                        }
                        // 5) Por barcode completo
                        if (enriched.codigo) {
                            const prodBarcode = await this.obtenerProductoPorBarcode(enriched.codigo);
                            if (prodBarcode) return prodBarcode;
                        }
                        return null;
                    },

                    async procesarBalanza(datos) {
                        const idProd = datos.idproducto;
                        const modo = datos.modo;
                        const valor = parseFloat(datos.valor);

                        console.log('🔍 Datos de balanza recibidos:', {
                            valor_original: datos.valor,
                            valor_parseado: valor,
                            modo: modo,
                            datos_completos: datos
                        });

                        if (!idProd || !modo || Number.isNaN(valor)) {
                            this.toast('Lectura de balanza incompleta', 'error');
                            return;
                        }

                        const producto = await this.obtenerProductoPorId(idProd);
                        if (!producto) {
                            this.toast('Producto de balanza no encontrado', 'error');
                            return;
                        }

                        // Agregar como línea independiente (no fusionar con otros registros del mismo producto)
                        const nuevoItem = {
                            id: producto.id,
                            codigo: producto.codigo,
                            descripcion: producto.descripcion,
                            precio: modo === 'PRECIO' ? Math.max(valor, 0) : parseFloat(producto.precio || 0),
                            cantidad: modo === 'PESO' ? Math.max(valor, 0.001) : 1,
                            tasa_iva: this.normalizeIvaRate(producto.tasa_iva),
                            editablePrecio: parseInt(producto.edita_precio) === 1,
                            editableDescripcion: parseInt(producto.editable) === 1,
                            esBalanza: true // Marcar como producto de balanza
                        };

                        console.log('✅ Item de balanza creado:', nuevoItem);

                        this.cart = [...this.cart, nuevoItem];
                        this.toast('Producto agregado desde balanza', 'success');
                    },

                    // Parsear búsqueda inteligente (ej: "zap rojo <50000")
                    parseSmartSearch(query) {
                        let priceFilter = null;
                        let priceOp = null;
                        let searchTerms = query;

                        // Detectar filtros de precio: <50000, >10000, =40000
                        const priceMatch = query.match(/([<>=])\s*(\d+)/);
                        if (priceMatch) {
                            priceOp = priceMatch[1];
                            priceFilter = parseInt(priceMatch[2]);
                            searchTerms = query.replace(/[<>=]\s*\d+/, '').trim();
                        }

                        return {
                            searchTerms,
                            priceOp,
                            priceFilter
                        };
                    },

                    isSearchActive() {
                        const query = String(this.searchQuery || '').trim();
                        if (!query) return false;
                        const firstChar = query.charAt(0);
                        if (['.', '+', '*'].includes(firstChar)) return false;
                        return true;
                    },

                    searchProducts() {
                        clearTimeout(this._searchDebounceTimer);
                        if (this._searchPreviewHoverTimer) {
                            clearTimeout(this._searchPreviewHoverTimer);
                            this._searchPreviewHoverTimer = null;
                        }
                        const firstChar = String(this.searchQuery || '').charAt(0);
                        const blockedStart = ['.', '+', '*'].includes(firstChar);
                        if (blockedStart) {
                            if (this._searchAbortController) {
                                try { this._searchAbortController.abort(); } catch (e) { /* ignore */ }
                                this._searchAbortController = null;
                            }
                            this.resetSearchImagesDelay(true);
                            this.productos = [];
                            this.searchSuggestion = null;
                            this.closeSearchDropdown();
                            this.loading = false;
                            return;
                        }

                        if (!this.searchQuery) {
                            if (this._searchAbortController) {
                                try { this._searchAbortController.abort(); } catch (e) { /* ignore */ }
                                this._searchAbortController = null;
                            }
                            this.resetSearchImagesDelay(true);
                            this.productos = [];
                            this.searchSuggestion = null;
                            this.closeSearchDropdown();
                            this.loading = false;
                            return;
                        }

                        this.showSearchDropdown = true;
                        this._searchDebounceTimer = setTimeout(() => {
                            this.performSearchProducts();
                        }, 80);
                    },

                    async performSearchProducts() {
                        const query = String(this.searchQuery || '').trim();
                        if (!query) {
                            this.loading = false;
                            this.productos = [];
                            this.searchSuggestion = null;
                            this.closeSearchDropdown();
                            return;
                        }

                        const requestId = ++this._searchRequestId;
                        const cacheKey = this.getSearchProductsCacheKey({
                            q: query,
                            estado: this.searchEstado,
                            tipo_precio: this.selectedPriceType
                        });
                        const cached = this.readSearchProductsCache(cacheKey);
                        if (cached.length > 0) {
                            this.productos = cached;
                            this.searchSuggestion = null;
                            this.showSearchDropdown = true;
                            this.loading = false;
                            this.selectedIndex = 0;
                            this.$nextTick(() => this.updateSearchDropdownMaxHeight());
                            this.schedulePreviewForProduct(this.productos[0] || null, requestId, 90);
                            return;
                        }

                        if (this._searchAbortController) {
                            try { this._searchAbortController.abort(); } catch (e) { /* ignore */ }
                        }
                        const controller = new AbortController();
                        this._searchAbortController = controller;
                        this.loading = true;
                        this.selectedIndex = 0;
                        this.resetSearchImagesDelay(true);
                        // Reset infinite scroll
                        this.productSearchOffset = 0;
                        this.productSearchQuery = query;

                        try {
                            const params = new URLSearchParams({
                                action: 'search_dropdown',
                                fast: '1',
                                q: query,
                                id_empresa: this.idEmpresa,
                                estado: this.searchEstado,
                                offset: 0
                            });

                            const res = await fetch(`api/productos.php?${params.toString()}`, {
                                cache: 'no-store',
                                signal: controller.signal
                            });
                            const data = await res.json();
                            if (requestId !== this._searchRequestId) {
                                return;
                            }
                            this.productos = Array.isArray(data.productos) ? data.productos : [];
                            this.writeSearchProductsCache(cacheKey, this.productos);
                            this.searchSuggestion = data?.suggestion?.text ? data.suggestion : null;
                            this.showSearchDropdown = true;
                            this.$nextTick(() => this.updateSearchDropdownMaxHeight());
                            this.schedulePreviewForProduct(this.productos[0] || null, requestId, 120);
                        } catch (e) {
                            if (requestId !== this._searchRequestId) {
                                return;
                            }
                            if (e?.name === 'AbortError') {
                                return;
                            }
                            console.error('Error searching products:', e);
                            this.productos = [];
                            this.searchSuggestion = null;
                            this.closeSearchDropdown();
                        }
                        if (requestId === this._searchRequestId) {
                            if (this._searchAbortController === controller) {
                                this._searchAbortController = null;
                            }
                            this.loading = false;
                        }
                    },

                    closeSearchDropdown() {
                        this.showSearchDropdown = false;
                    },

                    updateSearchDropdownMaxHeight() {
                        this.$nextTick(() => {
                            const scrollEl = this.$refs.searchDropdownScroll;
                            if (!scrollEl || !this.showSearchDropdown) {
                                return;
                            }

                            const scrollRect = scrollEl.getBoundingClientRect();
                            const sectionEl = this.$refs.searchInput?.closest('.pos-cart-panel');
                            const sectionRect = sectionEl?.getBoundingClientRect?.() || null;
                            const viewportBottom = window.innerHeight || document.documentElement.clientHeight || 800;
                            const containerBottom = sectionRect ? Math.min(sectionRect.bottom, viewportBottom) : viewportBottom;
                            const available = Math.floor(containerBottom - scrollRect.top - 12);

                            this.searchDropdownMaxHeight = Math.max(140, available);
                        });
                    },

                    applySearchSuggestion() {
                        const suggested = String(this.searchSuggestion?.text || '').trim();
                        if (!suggested) return;
                        this.searchQuery = suggested;
                        this.selectedIndex = 0;
                        this.searchSuggestion = null;
                        this.searchProducts();
                    },

                    setupDropdownScrollListener() {
                        // Method is just a placeholder - scroll detection via @scroll.throttle in HTML
                    },

                    async loadMoreProducts() {
                        const scrollContainer = event.target;
                        if (!scrollContainer) return;

                        const { scrollTop, scrollHeight, clientHeight } = scrollContainer;
                        const isNearBottom = (scrollHeight - scrollTop - clientHeight) < 100;
                        
                        if (!isNearBottom || this.loadingMoreProducts || this.searchQuery !== this.productSearchQuery) {
                            return;
                        }

                        this.loadingMoreProducts = true;
                        this.productSearchOffset += 50;

                        try {
                            const params = new URLSearchParams({
                                action: 'search_dropdown',
                                fast: '1',
                                q: String(this.searchQuery || '').trim(),
                                id_empresa: this.idEmpresa,
                                estado: this.searchEstado,
                                offset: this.productSearchOffset
                            });

                            const res = await fetch(`api/productos.php?${params.toString()}`, {
                                cache: 'no-store'
                            });
                            const data = await res.json();
                            const moreProducts = Array.isArray(data.productos) ? data.productos : [];
                            
                            if (moreProducts.length > 0) {
                                // Append to existing productos (avoid duplicates by ID)
                                const existingIds = new Set(this.productos.map(p => p.id));
                                const newProducts = moreProducts.filter(p => !existingIds.has(p.id));
                                this.productos = [...this.productos, ...newProducts];
                            }
                        } catch (e) {
                            console.error('Error loading more products:', e);
                        }
                        
                        this.loadingMoreProducts = false;
                    },

                    getSelectedDropdownProduct() {
                        if (!Array.isArray(this.productos) || this.productos.length === 0) return null;
                        return this.productos[this.selectedIndex] || this.productos[0] || null;
                    },

                    highlightSearchText(text, searchQuery) {
                        if (!searchQuery || !text) return text;
                        const words = searchQuery.toLowerCase().trim().split(/\s+/).filter(w => w);
                        let highlighted = text;
                        for (const word of words) {
                            const regex = new RegExp(`(${word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
                            highlighted = highlighted.replace(regex, '<mark style="background-color: #fcd34d; color: #000; font-weight: 700; padding: 2px 4px; border-radius: 3px; box-shadow: 0 0 0 2px #eab308;">$1</mark>');
                        }
                        return highlighted;
                    },

                    async focusDropdownProduct(index) {
                        if (!Array.isArray(this.productos) || index < 0 || index >= this.productos.length) return;
                        this.showSearchDropdown = true;
                        this.selectedIndex = index;
                        this.schedulePreviewForProduct(this.productos[index], this._searchRequestId, 140);
                    },

                    async navigateSearchDropdown(delta) {
                        if (!this.showSearchDropdown || !Array.isArray(this.productos) || this.productos.length === 0) return;
                        this.selectedIndex = (this.selectedIndex + delta + this.productos.length) % this.productos.length;
                        this.schedulePreviewForProduct(this.productos[this.selectedIndex], this._searchRequestId, 0);
                    },

                    async selectDropdownProduct(producto) {
                        if (!producto) return;
                        await this.addToCartFast(producto);
                        this.playSound('success');
                        this.searchQuery = '';
                        this.productos = [];
                        this.searchSuggestion = null;
                        this.closeSearchDropdown();
                        this.focusPrimaryInput();
                    },

                    buildProductDetailUrl(productId, scope = 'full') {
                        const params = new URLSearchParams({
                            id: String(productId),
                            id_empresa: String(this.idEmpresa),
                            scope: scope === 'lite' ? 'lite' : 'full'
                        });
                        return `api/producto_detalle.php?${params.toString()}`;
                    },

                    async fetchProductDetail(productId, scope = 'full', options = {}) {
                        const res = await fetch(this.buildProductDetailUrl(productId, scope), options);
                        return res.json();
                    },

                    mergeProductDetail(existing, incoming) {
                        if (!incoming?.success) return existing || null;
                        if (!existing?.success) return incoming;
                        return {
                            ...existing,
                            ...incoming,
                            producto: incoming.producto || existing.producto || null,
                            codigos_barra: Array.isArray(incoming.codigos_barra) ? incoming.codigos_barra : (existing.codigos_barra || []),
                            precios: Array.isArray(incoming.precios) ? incoming.precios : (existing.precios || []),
                            stock_por_sucursal: Array.isArray(incoming.stock_por_sucursal) ? incoming.stock_por_sucursal : (existing.stock_por_sucursal || []),
                            equivalentes: Array.isArray(incoming.equivalentes) ? incoming.equivalentes : (existing.equivalentes || []),
                            clientes_compraron: Array.isArray(incoming.clientes_compraron) ? incoming.clientes_compraron : (existing.clientes_compraron || []),
                            ventas_registradas: typeof incoming.ventas_registradas !== 'undefined' ? incoming.ventas_registradas : (existing.ventas_registradas || 0)
                        };
                    },

                    buildPlaceholderProductDetail(producto) {
                        if (!producto?.id) return null;
                        const id = Number(producto.id || producto.idproducto || 0);
                        const normalizedIva = this.normalizeIvaRate(producto.tasa_iva || producto.iva || 0);
                        const precio = Number(producto.precio || producto.precio_venta || 0);
                        const stock = Number(producto.stock || producto.stock_global || producto.saldo || 0);
                        const imagen = String(producto.imagen || producto.imagen_url || '');
                        const imagenUpdated = String(producto.imagen_updated || '');
                        return {
                            success: true,
                            scope: 'placeholder',
                            heavy_loaded: false,
                            producto: {
                                idproducto: id,
                                codigo: String(producto.codigo || producto.cve_producto || ''),
                                descripcion: String(producto.descripcion || producto.desproducto || 'Producto'),
                                Estado: Number(producto.Estado ?? 1),
                                descontinuado: Number(producto.descontinuado ?? 0),
                                referencia: String(producto.referencia || ''),
                                costo: Number(producto.costo || producto.precio_min || 0),
                                precio_venta: precio,
                                stock_global: stock,
                                controla_stock: Number(producto.controla_stock ?? 1),
                                vende_sin_stock: Number(producto.vende_sin_stock ?? 0),
                                edita_precio: Number(producto.edita_precio ?? 0),
                                editable: Number(producto.editable ?? 0),
                                usaserial: Number(producto.usaserial ?? producto.uses_serial ?? 0),
                                tasa_iva: normalizedIva,
                                stock_minimo: Number(producto.stock_minimo || 0),
                                stock_maximo: Number(producto.stock_maximo || 0),
                                categoria: String(producto.categoria || ''),
                                marca_nombre: String(producto.marca_nombre || ''),
                                modelo_nombre: String(producto.modelo_nombre || ''),
                                imagen_url: imagen,
                                imagen: imagen,
                                imagen_updated: imagenUpdated,
                                imagenes: imagen ? [{ url: imagen, thumb_url: imagen, principal: 1 }] : [],
                                detalle_cargado: 1
                            },
                            codigos_barra: [],
                            precios: precio > 0 ? [{
                                tipo: Number(this.selectedPriceType || 1),
                                nombre_tipo: 'Precio actual',
                                precio
                            }] : [],
                            stock_por_sucursal: [],
                            equivalentes: [],
                            clientes_compraron: [],
                            ventas_registradas: 0
                        };
                    },

                    async ensureFullProductDetailInBackground(productId, applyTo = 'preview') {
                        const key = String(productId || '');
                        if (!key || this._pendingHeavyDetailLoads[key]) return;
                        const cached = this._productDetailCache[key] || this._searchPreviewDetailCache[key] || null;
                        if (cached?.heavy_loaded === true) return;

                        this._pendingHeavyDetailLoads[key] = true;
                        try {
                            const data = await this.fetchProductDetail(productId, 'full', { cache: 'no-store' });
                            if (!data?.success) return;
                            const merged = this.mergeProductDetail(cached, data);
                            this._productDetailCache[key] = merged;
                            this._searchPreviewDetailCache[key] = merged;
                            if (applyTo === 'preview' && String(this.searchPreviewDetail?.producto?.idproducto || this.searchPreviewDetail?.producto?.id || '') === key) {
                                this.searchPreviewDetail = merged;
                                this.lastFocusedPreviewDetail = merged;
                                this.syncSearchPreviewGallery();
                            }
                            if (String(this.productDetail?.producto?.idproducto || this.productDetail?.producto?.id || '') === key) {
                                this.productDetail = merged;
                                this.selectedDetailImage = this.getDetailGalleryImages()[0] || null;
                            }
                        } catch (e) {
                            console.error('Error loading heavy product detail in background:', e);
                        } finally {
                            delete this._pendingHeavyDetailLoads[key];
                        }
                    },

                    schedulePreviewForProduct(producto, sourceRequestId = null, delay = 120) {
                        if (this._searchPreviewHoverTimer) {
                            clearTimeout(this._searchPreviewHoverTimer);
                            this._searchPreviewHoverTimer = null;
                        }
                        if (!producto?.id) {
                            return;
                        }
                        this._searchPreviewHoverTimer = setTimeout(() => {
                            this._searchPreviewHoverTimer = null;
                            this.ensureSearchPreviewDetail(producto, sourceRequestId);
                        }, Math.max(0, Number(delay) || 0));
                    },

                    async ensureSearchPreviewDetail(producto, sourceRequestId = null) {
                        if (!producto?.id) {
                            return;
                        }

                        const productId = String(producto.id);
                        if (String(this.searchPreviewDetail?.producto?.idproducto || this.searchPreviewDetail?.producto?.id || '') === productId) {
                            return;
                        }

                        if (this._searchPreviewDetailCache[productId]) {
                            this.searchPreviewDetail = this._searchPreviewDetailCache[productId];
                            this.lastFocusedPreviewDetail = this.searchPreviewDetail;
                            this.loadingSearchPreview = false;
                            this.syncSearchPreviewGallery();
                            return;
                        }

                        if (this._searchPreviewAbortController) {
                            try { this._searchPreviewAbortController.abort(); } catch (e) { /* ignore */ }
                        }
                        const controller = new AbortController();
                        this._searchPreviewAbortController = controller;
                        const requestId = ++this._searchPreviewReqId;
                        const placeholderDetail = this.buildPlaceholderProductDetail(producto);
                        if (placeholderDetail) {
                            this.searchPreviewDetail = placeholderDetail;
                            this.lastFocusedPreviewDetail = placeholderDetail;
                            this._searchPreviewDetailCache[productId] = placeholderDetail;
                            this.syncSearchPreviewGallery();
                        }
                        this.loadingSearchPreview = true;
                        try {
                            const data = await this.fetchProductDetail(producto.id, 'lite', {
                                signal: controller.signal
                            });
                            if ((sourceRequestId !== null && sourceRequestId !== this._searchRequestId) || requestId !== this._searchPreviewReqId) {
                                return;
                            }
                            this.searchPreviewDetail = data.success ? data : { producto };
                            this._searchPreviewDetailCache[productId] = this.searchPreviewDetail;
                            if (data?.success) {
                                this._productDetailCache[productId] = data;
                                if (data.heavy_loaded !== true) {
                                    this.ensureFullProductDetailInBackground(producto.id, 'preview');
                                }
                            }
                            this.lastFocusedPreviewDetail = this.searchPreviewDetail;
                            this.syncSearchPreviewGallery();
                        } catch (e) {
                            if (requestId !== this._searchPreviewReqId) return;
                            if (e?.name === 'AbortError') return;
                            console.error('Error loading search preview detail:', e);
                            this.searchPreviewDetail = { producto };
                            this._searchPreviewDetailCache[productId] = this.searchPreviewDetail;
                            this.lastFocusedPreviewDetail = this.searchPreviewDetail;
                            this.syncSearchPreviewGallery();
                        } finally {
                            if (requestId === this._searchPreviewReqId) {
                                if (this._searchPreviewAbortController === controller) {
                                    this._searchPreviewAbortController = null;
                                }
                                this.loadingSearchPreview = false;
                            }
                        }
                    },

                    async loadInitialPreviewFallback() {
                        if (this.lastFocusedPreviewDetail?.producto || this.searchPreviewDetail?.producto) return;
                        this.loadingSearchPreview = true;
                        try {
                            let productos = [];

                            const popularParams = new URLSearchParams({
                                action: 'popular_simple',
                                id_empresa: this.idEmpresa,
                                tipo_precio: this.selectedPriceType
                            });
                            const popularRes = await fetch(`api/productos.php?${popularParams.toString()}`);
                            const popularData = await popularRes.json();
                            productos = Array.isArray(popularData?.productos) ? popularData.productos : [];

                            if (productos.length === 0) {
                                const searchParams = new URLSearchParams({
                                    action: 'search',
                                    q: '',
                                    id_empresa: this.idEmpresa,
                                    estado: this.searchEstado,
                                    tipo_precio: this.selectedPriceType
                                });
                                const searchRes = await fetch(`api/productos.php?${searchParams.toString()}`);
                                const searchData = await searchRes.json();
                                productos = Array.isArray(searchData?.productos) ? searchData.productos : [];
                            }

                            if (productos.length === 0) return;

                            const producto = productos[0];
                            await this.ensureSearchPreviewDetail(producto);
                        } catch (e) {
                            console.error('Error loading initial preview fallback:', e);
                        } finally {
                            this.loadingSearchPreview = false;
                        }
                    },

                    getSearchPreviewCardProduct() {
                        const producto = this.searchPreviewDetail?.producto || this.lastFocusedPreviewDetail?.producto || this.getSelectedDropdownProduct() || {};
                        return {
                            id: producto.idproducto || producto.id || 0,
                            descripcion: producto.descripcion || 'producto',
                            imagen: producto.imagen || producto.imagen_url || '',
                            imagen_updated: producto.imagen_updated || '',
                            imagenes: Array.isArray(producto.imagenes) ? producto.imagenes.map((img) => img?.url || '').filter(Boolean) : []
                        };
                    },

                    getSearchPreviewActionProduct() {
                        const producto = this.searchPreviewDetail?.producto || this.lastFocusedPreviewDetail?.producto || this.getSelectedDropdownProduct() || null;
                        if (!producto) return null;
                        return {
                            ...producto,
                            id: producto.idproducto || producto.id || 0,
                            codigo: producto.codigo || producto.cve_producto || '',
                            precio: parseFloat(producto.precio ?? producto.precio_venta ?? 0)
                        };
                    },

                    async addPreviewProductToCart() {
                        const producto = this.getSearchPreviewActionProduct();
                        if (!producto?.id) {
                            this.toast('No hay un producto seleccionado en el preview', 'warning');
                            return;
                        }
                        await this.addToCartFast(producto);
                        this.playSound('success');
                        this.focusPrimaryInput();
                    },

                    normalizePreviewImageUrl(url) {
                        const raw = sanitizeMalformedVariantUrl(String(url || '').trim());
                        if (!raw) return '';
                        return raw.startsWith('/_lib') ? '/public' + raw : raw;
                    },

                    shouldRenderDropdownThumb(index) {
                        return Number(index) < 8;
                    },

                    getVisibleDropdownProducts() {
                        return Array.isArray(this.productos) ? this.productos.slice(0, 50) : [];
                    },

                    hasMoreDropdownResults() {
                        return Array.isArray(this.productos) && this.productos.length > 50;
                    },

                    getHiddenDropdownCount() {
                        return this.hasMoreDropdownResults() ? this.productos.length - 50 : 0;
                    },

                    getDropdownThumbUrl(producto, index = 0) {
                        if (!this.shouldRenderDropdownThumb(index)) return '';
                        const p = producto || {};
                        const candidates = [];
                        const push = (url) => {
                            const normalized = this.normalizePreviewImageUrl(url);
                            if (normalized && !candidates.includes(normalized)) {
                                candidates.push(normalized);
                            }
                        };

                        if (Array.isArray(p.imagenes)) {
                            p.imagenes.forEach((img) => {
                                if (typeof img === 'string') {
                                    push(img);
                                    return;
                                }
                                push(img?.thumb_url || img?.small_url || img?.url || img?.medium_url);
                            });
                        }

                        push(p.imagen_thumb || p.imagen_small || p.imagen || p.imagen_url || p.foto_small_url || p.foto_url);
                        const best = candidates[0] || '';
                        if (best) {
                            return this.withImageVersion(best, p.imagen_updated || '');
                        }

                        const productId = parseInt(p.id || p.idproducto || 0, 10);
                        const productDesc = String(p.descripcion || p.desproducto || 'producto').trim();
                        if (productId > 0 && productDesc) {
                            return `/public/pos/api/imagen_proxy.php?id=${productId}&q=${encodeURIComponent(productDesc)}&prefer=bing_catalog`;
                        }

                        return '';
                    },

                    openGeneralProductSearch() {
                        const query = String(this.searchQuery || '').trim();
                        const params = new URLSearchParams({ desktop: '1', modal: '1' });
                        if (query) {
                            params.set('search', query);
                        }
                        const targetUrl = `/public/productos/search_dark.php?${params.toString()}`;
                        this.closeSearchDropdown();
                        this.productos = [];
                        this.searchSuggestion = null;
                        this.generalSearchModalUrl = targetUrl;
                        this.showGeneralSearchModal = false;
                        const popup = window.open(targetUrl, '_blank');
                        if (popup && typeof popup.focus === 'function') {
                            popup.focus();
                        }
                    },

                    async handleGeneralSearchMessage(event) {
                        const originOk = event?.origin === window.location.origin || event?.origin === 'null';
                        if (!originOk) return;
                        const data = event?.data || {};
                        if (data?.type !== 'smx:add-product-to-cart') return;
                        const payload = data?.product || {};
                        const id = Number(payload?.id || 0);
                        if (!id) return;

                        const producto = {
                            id,
                            codigo: String(payload?.codigo || ''),
                            descripcion: String(payload?.descripcion || 'Producto'),
                            precio: Number(payload?.precio || 0),
                            stock: Number(payload?.stock || 0),
                            tasa_iva: Number(payload?.tasa_iva || 3),
                            controla_stock: Number(payload?.controla_stock ?? 1),
                            vende_sin_stock: Number(payload?.vende_sin_stock ?? 0),
                            precio_min: Number(payload?.precio_min || 0),
                            edita_precio: Number(payload?.edita_precio || 0),
                            editable: Number(payload?.editable || 0),
                            usaserial: payload?.usaserial ?? 0,
                            imagen: String(payload?.imagen || ''),
                            imagen_updated: String(payload?.imagen_updated || ''),
                            detalle_cargado: 0
                        };

                        this.showGeneralSearchModal = false;
                        await this.addToCartFast(producto);
                        this.playSound('success');
                        try { window.focus(); } catch (e) { /* ignore */ }
                        this.focusPrimaryInput();
                        this.toast(`Agregado: ${producto.descripcion}`, 'success');
                    },

                    getSearchPreviewImages() {
                        const producto = this.searchPreviewDetail?.producto || this.lastFocusedPreviewDetail?.producto || {};
                        const images = [];
                        const pushImage = (url, thumb = '') => {
                            const full = this.normalizePreviewImageUrl(url);
                            const mini = this.normalizePreviewImageUrl(thumb || url);
                            if (!full) return;
                            if (images.some((img) => img.url === full)) return;
                            images.push({ url: full, thumb: mini || full });
                        };

                        if (Array.isArray(producto.imagenes)) {
                            producto.imagenes.forEach((img) => {
                                if (typeof img === 'string') {
                                    pushImage(img, img);
                                    return;
                                }
                                pushImage(img?.url || img?.large_url || img?.medium_url || img?.thumb_url, img?.thumb_url || img?.small_url || img?.url);
                            });
                        }

                        pushImage(producto.imagen_url || producto.imagen || producto.foto_url, producto.imagen_thumb || producto.imagen_small || producto.imagen_url || producto.imagen);
                        return images;
                    },

                    syncSearchPreviewGallery() {
                        const images = this.getSearchPreviewImages();
                        const hasCurrent = images.some((img) => img.url === this.selectedSearchPreviewImage);
                        this.selectedSearchPreviewImage = hasCurrent ? this.selectedSearchPreviewImage : (images[0]?.url || '');
                        this.clearSearchPreviewZoom();
                    },

                    getActiveSearchPreviewImage() {
                        const images = this.getSearchPreviewImages();
                        if (images.length === 0) return '';
                        return this.selectedSearchPreviewImage || images[0].url;
                    },

                    setSearchPreviewImage(url) {
                        this.selectedSearchPreviewImage = this.normalizePreviewImageUrl(url);
                        this.clearSearchPreviewZoom();
                    },

                    activateSearchPreviewZoom() {
                        if (!this.getActiveSearchPreviewImage()) return;
                        this.searchPreviewZoomActive = true;
                    },

                    clearSearchPreviewZoom() {
                        this.searchPreviewZoomActive = false;
                        this.searchPreviewZoomX = 50;
                        this.searchPreviewZoomY = 50;
                    },

                    updateSearchPreviewZoom(event) {
                        const el = event?.currentTarget;
                        if (!el) return;
                        const rect = el.getBoundingClientRect();
                        if (!rect.width || !rect.height) return;
                        const x = ((event.clientX - rect.left) / rect.width) * 100;
                        const y = ((event.clientY - rect.top) / rect.height) * 100;
                        this.searchPreviewZoomX = Math.max(0, Math.min(100, x));
                        this.searchPreviewZoomY = Math.max(0, Math.min(100, y));
                    },

                    getSearchPreviewLensStyle() {
                        const lensSize = 44;
                        const left = Math.max(0, Math.min(100 - lensSize, this.searchPreviewZoomX - (lensSize / 2)));
                        const top = Math.max(0, Math.min(100 - lensSize, this.searchPreviewZoomY - (lensSize / 2)));
                        return `left:${left}%; top:${top}%;`;
                    },

                    getSearchPreviewZoomPaneStyle() {
                        const active = this.getActiveSearchPreviewImage();
                        if (!active) return '';
                        return [
                            `background-image:url('${active.replace(/'/g, "%27")}')`,
                            'background-size: 240%',
                            `background-position: ${this.searchPreviewZoomX}% ${this.searchPreviewZoomY}%`
                        ].join('; ');
                    },

                    getPreviewPriceList() {
                        const list = Array.isArray(this.searchPreviewDetail?.precios) ? this.searchPreviewDetail.precios : [];
                        if (list.length > 0) return list;
                        const producto = this.searchPreviewDetail?.producto || {};
                        const base = Number(producto.precio_venta || producto.precio || 0);
                        return base > 0 ? [{ tipo: 0, nombre_tipo: 'Precio Base', precio: base }] : [];
                    },

                    getPreviewPrimaryPrice() {
                        const list = this.getPreviewPriceList();
                        if (list.length === 0) return 0;
                        return Number(list[0]?.precio || 0);
                    },

                    isCurrentPreviewSucursal(sucursal) {
                        return Number(sucursal?.id_sucursal || 0) === Number(this.idSucursal || 0);
                    },

                    getOrderedPreviewStocks() {
                        const stocks = Array.isArray(this.searchPreviewDetail?.stock_por_sucursal)
                            ? [...this.searchPreviewDetail.stock_por_sucursal]
                            : [];
                        return stocks.sort((a, b) => {
                            const aCurrent = this.isCurrentPreviewSucursal(a) ? 1 : 0;
                            const bCurrent = this.isCurrentPreviewSucursal(b) ? 1 : 0;
                            if (aCurrent !== bCurrent) {
                                return bCurrent - aCurrent;
                            }
                            return String(a?.nombre_sucursal || '').localeCompare(String(b?.nombre_sucursal || ''));
                        });
                    },

                    getDropdownInlinePrice(producto) {
                        const currentPreviewId = String(this.searchPreviewDetail?.producto?.idproducto || this.searchPreviewDetail?.producto?.id || '');
                        const productId = String(producto?.id || '');
                        if (currentPreviewId && currentPreviewId === productId) {
                            return Number(this.getPreviewPrimaryPrice() || producto?.precio || 0);
                        }
                        return Number(producto?.precio || 0);
                    },

                    getDropdownInlineStocks(producto, limit = 3) {
                        const currentPreviewId = String(this.searchPreviewDetail?.producto?.idproducto || this.searchPreviewDetail?.producto?.id || '');
                        const productId = String(producto?.id || '');
                        if (!currentPreviewId || currentPreviewId !== productId) {
                            return [];
                        }
                        const ordered = this.getOrderedPreviewStocks().filter((suc) => Number(suc?.stock || 0) > 0);
                        return ordered.slice(0, Math.max(0, Number(limit) || 0));
                    },

                    getDropdownInlineStocksOverflow(producto, limit = 3) {
                        const currentPreviewId = String(this.searchPreviewDetail?.producto?.idproducto || this.searchPreviewDetail?.producto?.id || '');
                        const productId = String(producto?.id || '');
                        if (!currentPreviewId || currentPreviewId !== productId) {
                            return 0;
                        }
                        const ordered = this.getOrderedPreviewStocks().filter((suc) => Number(suc?.stock || 0) > 0);
                        return Math.max(0, ordered.length - Math.max(0, Number(limit) || 0));
                    },

                    getPreviewOriginalPrice() {
                        const values = this.getPreviewPriceList().map((p) => Number(p?.precio || 0)).filter((n) => n > 0);
                        if (values.length === 0) return 0;
                        return Math.max(...values);
                    },

                    getPreviewDiscountPct() {
                        const current = this.getPreviewPrimaryPrice();
                        const original = this.getPreviewOriginalPrice();
                        if (!(original > current && current > 0)) return 0;
                        return Math.round(((original - current) / original) * 100);
                    },

                    getPreviewFeatureBullets() {
                        const p = this.searchPreviewDetail?.producto || {};
                        const bullets = [];
                        if (p.categoria) bullets.push(`Categoria: ${p.categoria}`);
                        if (p.marca_nombre) bullets.push(`Marca: ${p.marca_nombre}`);
                        if (p.modelo_nombre) bullets.push(`Modelo: ${p.modelo_nombre}`);
                        if (p.referencia) bullets.push(`Referencia: ${p.referencia}`);
                        bullets.push(`IVA: ${this.formatIvaLabel(p.tasa_iva)}`);
                        return bullets.slice(0, 5);
                    },

                    resetSearchImagesDelay(markReady = false) {
                        if (this._searchImagesDelayTimer) {
                            clearTimeout(this._searchImagesDelayTimer);
                            this._searchImagesDelayTimer = null;
                        }
                        this.searchImagesReady = !!markReady;
                    },

                    scheduleSearchImagesReveal() {
                        this.resetSearchImagesDelay(false);
                        this._searchImagesDelayTimer = setTimeout(() => {
                            this.searchImagesReady = true;
                            if (Array.isArray(this.productos)) {
                                this.productos = [...this.productos];
                            }
                            this._searchImagesDelayTimer = null;
                        }, 3000);
                    },

                    scheduleSearchResultHydration(requestId, payload = {}) {
                        this.resetSearchImagesDelay(false);
                        this._searchImagesDelayTimer = setTimeout(async () => {
                            if (requestId !== this._searchRequestId) {
                                this._searchImagesDelayTimer = null;
                                return;
                            }

                            const visibleIds = Array.isArray(this.productos)
                                ? this.productos.map((item) => parseInt(item?.id || 0, 10)).filter((id) => id > 0)
                                : [];
                            if (visibleIds.length === 0) {
                                this.searchImagesReady = true;
                                this._searchImagesDelayTimer = null;
                                return;
                            }

                            const fullCacheKey = this.getSearchProductsCacheKey({
                                ...payload,
                                mode: 'full'
                            });
                            const cachedFull = this.readSearchProductsCache(fullCacheKey);
                            if (cachedFull.length > 0) {
                                this.productos = cachedFull;
                                this.searchImagesReady = true;
                                this._searchImagesDelayTimer = null;
                                return;
                            }

                            try {
                                const params = new URLSearchParams({
                                    action: 'hydrate',
                                    ids: visibleIds.join(','),
                                    category: payload.category || '',
                                    id_empresa: this.idEmpresa,
                                    estado: payload.estado ?? this.searchEstado,
                                    tipo_precio: payload.tipo_precio ?? this.selectedPriceType
                                });

                                const res = await fetch(`api/productos.php?${params.toString()}`);
                                const data = await res.json();
                                if (requestId !== this._searchRequestId) {
                                    return;
                                }

                                const hydrated = Array.isArray(data?.productos) ? data.productos : [];
                                this.productos = hydrated;
                                this.writeSearchProductsCache(fullCacheKey, hydrated);
                            } catch (e) {
                                if (requestId === this._searchRequestId) {
                                    console.error('Error hidratando resultados de búsqueda:', e);
                                }
                            } finally {
                                if (requestId === this._searchRequestId) {
                                    this.searchImagesReady = true;
                                }
                                this._searchImagesDelayTimer = null;
                            }
                        }, 3000);
                    },

                    focusClienteInput() {
                        this.$nextTick(() => {
                            this.$refs.clienteInput?.focus();
                            this.$refs.clienteInput?.select?.();
                        });
                    },

                    focusFinishSaleButton() {
                        this.$nextTick(() => {
                            this.$refs.finishSaleButton?.focus?.();
                            this.$refs.finishSaleButton?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
                        });
                    },

                    canOpenProductosModule() {
                        const p = this.permisosProductos || {};
                        return String(p.priv_access || 'N') === 'Y';
                    },

                    openProductEditorFromDetail() {
                        const id = Number(this.productDetail?.producto?.idproducto || this.productDetail?.producto?.id || 0);
                        if (id <= 0 || !this.canOpenProductosModule()) return;
                        window.open(`/public/productos/index.php?edit_id=${encodeURIComponent(id)}&desktop=1`, '_blank', 'noopener');
                    },

                    async handleClienteEnter() {
                        if (this.clientesResults.length > 0 && this.showClienteDropdown) {
                            POSAudio.play('success');
                            this.selectedCliente = this.clientesResults[this.clienteSelectedIdx];
                            this.clienteSearch = this.clientesResults[this.clienteSelectedIdx].nombre;
                            this.showClienteDropdown = false;
                            this.clientesResults = [];
                            this.focusFinishSaleButton();
                            return;
                        }

                        const query = String(this.currentTicket?.clienteSearch || '').trim();
                        if (query === '' || this.selectedCliente) {
                            this.focusFinishSaleButton();
                            return;
                        }

                        await this.searchClientes(true);
                    },

                    async handleSearchEnter() {
                        let query = this.searchQuery?.trim();
                        if (!query) {
                            if (this.cart.length > 0) {
                                this.focusClienteInput();
                            }
                            return;
                        }

                        // Atajo de cambio de precio rápido (ej: .50000)
                        if (query.startsWith('.') && query.length > 1) {
                            const newPriceStr = query.substring(1);
                            const newPrice = this.parseNum(newPriceStr);
                            if (!isNaN(newPrice)) {
                                if (this.cart.length === 0) {
                                    this.toast('No hay productos en el carrito', 'warning');
                                    this.searchQuery = '';
                                    return;
                                }
                                const lastItem = this.cart[this.cart.length - 1];
                                const minPrice = parseFloat(lastItem.precio_min || 0);

                                if (newPrice >= minPrice) {
                                    lastItem.precio = newPrice;
                                    this.searchQuery = '';
                                    this.toast(`Precio de "${lastItem.descripcion}" actualizado a ${this.formatMoney(newPrice)}`, 'success');
                                    this.playSound('success');
                                    this.recalculate();
                                    return;
                                } else {
                                    this.toast(`Monto no autorizado. El precio mínimo es ${this.formatMoney(minPrice)}`, 'error');
                                    const auth = await this.requestPriceOverride(lastItem, newPrice, minPrice, 'ATAJO_DOT');
                                    if (auth?.approved) {
                                        lastItem.precio = newPrice;
                                        this.searchQuery = '';
                                        this.toast(`Precio autorizado y actualizado: ${this.formatMoney(newPrice)}`, 'success');
                                        this.playSound('success');
                                        this.recalculate();
                                        return;
                                    }
                                    this.playSound('error');
                                    return;
                                }
                            }
                        }

                        // Atajo de cambio de cantidad (ej: +5)
                        if (query.startsWith('+') && query.length > 1) {
                            const newQtyStr = query.substring(1);
                            const newQty = this.parseNum(newQtyStr);
                            if (!isNaN(newQty)) {
                                if (this.cart.length === 0) {
                                    this.toast('No hay productos en el carrito', 'warning');
                                    this.searchQuery = '';
                                    return;
                                }
                                const lastItem = this.cart[this.cart.length - 1];

                                // Validación de stock: si controla_stock != -1 y vende_sin_stock == 0
                                if (!this.skipStockAndDeleteGuards() && parseInt(lastItem.controla_stock) !== -1 && parseInt(lastItem.vende_sin_stock) === 0) {
                                    const available = parseFloat(lastItem.stock_inicial || 0);
                                    if (newQty > available) {
                                        this.toast(`Stock insuficiente. Disponible: ${available}`, 'error');
                                        this.playSound('error');
                                        return;
                                    }
                                }

                                lastItem.cantidad = newQty;
                                this.searchQuery = '';
                                this.toast(`Cantidad de "${lastItem.descripcion}" actualizada a ${newQty}`, 'success');
                                this.playSound('success');
                                this.recalculate();
                                return;
                            }
                        }

                        // Atajo de cambio de subtotal rápido (ej: *40000) - AJUSTANDO CANTIDAD
                        if (query.startsWith('*') && query.length > 1) {
                            const targetTotalStr = query.substring(1);
                            const targetTotal = this.parseNum(targetTotalStr);
                            if (!isNaN(targetTotal)) {
                                if (this.cart.length === 0) {
                                    this.toast('No hay productos en el carrito', 'warning');
                                    this.searchQuery = '';
                                    return;
                                }
                                const lastItem = this.cart[this.cart.length - 1];
                                const unitPrice = parseFloat(lastItem.precio || 0);

                                if (unitPrice <= 0) {
                                    this.toast('El precio unitario debe ser mayor a cero para calcular cantidad', 'error');
                                    this.playSound('error');
                                    return;
                                }

                                const newQty = targetTotal / unitPrice;

                                // Validación de stock: si controla_stock != -1 y vende_sin_stock == 0
                                if (!this.skipStockAndDeleteGuards() && parseInt(lastItem.controla_stock) !== -1 && parseInt(lastItem.vende_sin_stock) === 0) {
                                    const available = parseFloat(lastItem.stock_inicial || 0);
                                    if (newQty > available) {
                                        this.toast(`Stock insuficiente para alcanzar ese total. Disponible: ${available}`, 'error');
                                        this.playSound('error');
                                        return;
                                    }
                                }

                                lastItem.cantidad = newQty;
                                this.searchQuery = '';
                                this.toast(`Cantidad de "${lastItem.descripcion}" ajustada a ${newQty.toFixed(3)} para totalizar ${this.formatMoney(targetTotal)}`, 'success');
                                this.playSound('success');
                                this.recalculate();
                                return;
                            }
                        }

                        // Si hay lectura de balanza pendiente, usarla directamente con Enter
                        if (this.balanzaData) {
                            await this.confirmarBalanza();
                            return;
                        }
                        const queryBalanza = this.searchQuery?.trim();
                        if (queryBalanza) {
                            const fueBalanza = await this.detectarBalanza(queryBalanza);
                            if (fueBalanza) return;
                        }

                        const focusedProduct = this.getSelectedDropdownProduct();
                        if (focusedProduct) {
                            await this.selectDropdownProduct(focusedProduct);
                            return;
                        }

                        query = this.searchQuery?.trim();
                        if (!query) return;

                        // 2. Si no hay nada en sugerencias, forzar búsqueda rápida en servidor
                        this.loading = true;
                        try {
                            const params = new URLSearchParams({
                                action: 'search_dropdown',
                                fast: '1',
                                q: query,
                                id_empresa: this.idEmpresa,
                                estado: this.searchEstado
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            const results = data.productos || [];
                            this.searchSuggestion = data?.suggestion?.text ? data.suggestion : null;

                            const exact = results.find(p => p.codigo === query);
                            if (exact) {
                                await this.selectDropdownProduct(exact);
                            } else if (results.length === 1) {
                                await this.selectDropdownProduct(results[0]);
                            } else if (!results.length && this.searchSuggestion?.text) {
                                this.applySearchSuggestion();
                            } else {
                                this.showInfoModal({
                                    title: 'Codigo no encontrado',
                                    message: 'El codigo ingresado no existe en descripcion, referencia ni codigo de barras.',
                                    icon: '⌕',
                                    type: 'info'
                                });
                                this.playSound('error');
                            }
                        } catch (e) {
                            this.toast('Error al buscar producto', 'error');
                            this.playSound('error');
                        } finally {
                            this.loading = false;
                        }
                    },

                    // Navegación de sugerencias
                    navigateSuggestion(delta) {
                        if (this.productos.length === 0) return;
                        this.selectedIndex = (this.selectedIndex + delta + this.productos.length) % this.productos.length;
                    },

                    // Navegación en Grid/List
                    navigateGrid(direction) {
                        if (this.productos.length === 0) return;

                        const cols = this.viewMode === 'grid' ? 3 : 1;

                        if (direction === 1) { // Abajo
                            this.selectedIndex = Math.min(this.selectedIndex + cols, this.productos.length - 1);
                        } else if (direction === -1) { // Arriba
                            this.selectedIndex = Math.max(this.selectedIndex - cols, 0);
                        } else if (direction === 'right') {
                            this.selectedIndex = Math.min(this.selectedIndex + 1, this.productos.length - 1);
                        } else if (direction === 'left') {
                            this.selectedIndex = Math.max(this.selectedIndex - 1, 0);
                        }

                        this.scrollToSelected();
                    },

                    scrollToSelected() {
                        const container = document.querySelector('.custom-scroll');
                        const selectedEl = document.querySelectorAll('.producto-card, .group')[this.selectedIndex];

                        if (container && selectedEl) {
                            const containerRect = container.getBoundingClientRect();
                            const elRect = selectedEl.getBoundingClientRect();

                            if (elRect.bottom > containerRect.bottom) {
                                selectedEl.scrollIntoView({
                                    block: 'nearest',
                                    behavior: 'smooth'
                                });
                            } else if (elRect.top < containerRect.top) {
                                selectedEl.scrollIntoView({
                                    block: 'nearest',
                                    behavior: 'smooth'
                                });
                            }
                        }
                    },

                    setViewMode(mode) {
                        if (!['grid', 'list'].includes(mode)) return;
                        this.cancelViewModePress();
                        this.viewMode = mode;
                    },

                    startViewModePress(mode) {
                        if (!['grid', 'list'].includes(mode)) return;
                        this.cancelViewModePress();
                        this._viewModePressTimer = setTimeout(() => {
                            this.setViewModeDefault(mode);
                            this._viewModePressTimer = null;
                        }, 650);
                    },

                    cancelViewModePress() {
                        if (this._viewModePressTimer) {
                            clearTimeout(this._viewModePressTimer);
                            this._viewModePressTimer = null;
                        }
                    },

                    setViewModeDefault(mode) {
                        this.viewMode = mode;
                        localStorage.setItem('pos_view_mode', mode);
                        this.playSound('success');
                        this.toast(`Vista ${mode === 'grid' ? 'Cuadrícula' : 'Lista'} fijada por defecto`, 'success');
                    },

                    // Deprecado: Navegación sugerencias
                    async selectSuggestion(index) {
                        if (this.productos.length > 0 && index >= 0 && index < this.productos.length) {
                            await this.selectDropdownProduct(this.productos[index]);
                        }
                    },

                    // Checkout Modal
                    showCheckoutModal: false,
                    isFacturaElectronica: <?php echo $isFacturaElectronica; ?>,
                    selectedDocType: '<?php echo $isFacturaElectronica ? "electro" : "auto"; ?>',
                    selectedPaymentMethod: 'efectivo',
                    voucherNumber: '',
                    showCardModal: false,
                    showTransferModal: false,
                    transferReference: '',
                    showQrModal: false,
                    qrTransactionCode: '',
                    showCreditModal: false,
                    creditInstallments: 1,
                    creditDueDate: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0], // 30 días
                    creditNotes: '',
                    creditInterestPct: 0,
                    creditMoraPct: 0,
                    creditGraceDays: 0,
                    showUenoModal: false,
                    processingSale: false,
                    processingUeno: false,

                    // Predeterminados
                    defaultDocType: localStorage.getItem('pos_default_doc') || 'comun',
                    defaultPaymentMethod: localStorage.getItem('pos_default_pm') || 'efectivo',
                    _lpTimer: null,
                    _lpFired: false,

                    startLongPress(type, id) {
                        this._lpFired = false;
                        this._lpTimer = setTimeout(() => {
                            this._lpFired = true;
                            this.setDefault(type, id);
                            // Vibrar en dispositivos que lo soporten
                            if (navigator.vibrate) navigator.vibrate(50);
                        }, 600);
                    },
                    endLongPress() {
                        clearTimeout(this._lpTimer);
                        this._lpTimer = null;
                    },
                    wasLongPress() {
                        if (this._lpFired) { this._lpFired = false; return true; }
                        return false;
                    },

                    setDefault(type, id) {
                        if (type === 'doc') {
                            this.defaultDocType = id;
                            localStorage.setItem('pos_default_doc', id);
                            this.toast(`Documento "${id}" fijado como predeterminado`, 'success');
                        } else {
                            this.defaultPaymentMethod = id;
                            localStorage.setItem('pos_default_pm', id);
                            this.toast(`Medio de pago "${id}" fijado como predeterminado`, 'success');
                        }
                        this.playSound('success');
                    },

                    get docTypes() {
                        const types = [];
                        // Nota de Control siempre primero (predeterminada)
                        types.push({
                            id: 'comun',
                            name: 'Nota de Control',
                            icon: '📋'
                        });
                        if (this.isFacturaElectronica) {
                            types.push({
                                id: 'electro',
                                name: 'Factura Electrónica',
                                icon: '⚡'
                            });
                        } else {
                            types.push({
                                id: 'auto',
                                name: 'Factura Autografiada',
                                icon: '📝'
                            });
                        }
                        return types;
                    },

                    focusedCheckoutSection: 'paymentMethods',
                    focusedCheckoutIdx: 0,

                    navigateCheckout(key) {
                        const pms = this.paymentMethodsAvailable;
                        const dts = this.docTypes;
                        if (!pms.length) return;

                        if (this.focusedCheckoutSection === 'paymentMethods') {
                            const cols = window.innerWidth >= 768 ? 3 : 2;
                            if (key === 'ArrowLeft' && this.focusedCheckoutIdx > 0) this.focusedCheckoutIdx--;
                            if (key === 'ArrowRight' && this.focusedCheckoutIdx < pms.length - 1) this.focusedCheckoutIdx++;
                            if (key === 'ArrowUp') {
                                if (this.focusedCheckoutIdx < cols) {
                                    this.focusedCheckoutSection = 'docTypes';
                                    this.focusedCheckoutIdx = dts.length - 1;
                                } else {
                                    this.focusedCheckoutIdx -= cols;
                                }
                            }
                            if (key === 'ArrowDown') {
                                if (this.focusedCheckoutIdx + cols < pms.length) {
                                    this.focusedCheckoutIdx += cols;
                                }
                            }
                        } else {
                            // DocTypes Section
                            if (key === 'ArrowDown') {
                                if (this.focusedCheckoutIdx < dts.length - 1) {
                                    this.focusedCheckoutIdx++;
                                } else {
                                    this.focusedCheckoutSection = 'paymentMethods';
                                    this.focusedCheckoutIdx = 0;
                                }
                            }
                            if (key === 'ArrowUp') {
                                if (this.focusedCheckoutIdx > 0) {
                                    this.focusedCheckoutIdx--;
                                }
                            }
                            if (key === 'ArrowRight' || key === 'ArrowLeft') {
                                this.focusedCheckoutSection = 'paymentMethods';
                                this.focusedCheckoutIdx = 0;
                            }
                        }
                    },

                    selectFocusedItem() {
                        if (this.focusedCheckoutSection === 'docTypes') {
                            const dt = this.docTypes[this.focusedCheckoutIdx];
                            this.selectedDocType = dt.id;
                            this.playSound('info');
                        } else {
                            const pm = this.paymentMethodsAvailable[this.focusedCheckoutIdx];
                            if (!pm) return;
                            this.selectUnifiedMethod(pm);
                        }
                    },

                    focusedCashIdx: -1, // -1 means input is focused

                    navigateCash(key) {
                        // Indices: 0-6 (bills), 7 (Limpiar), 8 (Exacto), 9 (Cerrar), 10 (Confirmar)
                        if (this.focusedCashIdx === -1) {
                            if (key === 'ArrowDown') this.focusedCashIdx = 0;
                            return;
                        }

                        if (this.focusedCashIdx >= 0 && this.focusedCashIdx <= 7) {
                            // Bills grid (4 columns)
                            if (key === 'ArrowLeft' && this.focusedCashIdx % 4 > 0) this.focusedCashIdx--;
                            if (key === 'ArrowRight' && this.focusedCashIdx % 4 < 3) this.focusedCashIdx++;
                            if (key === 'ArrowUp') {
                                if (this.focusedCashIdx < 4) {
                                    this.focusedCashIdx = -1;
                                    this.$nextTick(() => {
                                        this.$refs.cashInput.focus();
                                        this.$refs.cashInput.select();
                                    });
                                } else {
                                    this.focusedCashIdx -= 4;
                                }
                            }
                            if (key === 'ArrowDown') {
                                if (this.focusedCashIdx >= 4) {
                                    this.focusedCashIdx = 8;
                                } else {
                                    this.focusedCashIdx += 4;
                                }
                            }
                        } else if (this.focusedCashIdx === 8) {
                            // Monto Exacto
                            if (key === 'ArrowUp') this.focusedCashIdx = 4;
                            if (key === 'ArrowDown') this.focusedCashIdx = 10;
                        } else if (this.focusedCashIdx >= 9) {
                            // Footer
                            if (key === 'ArrowLeft') this.focusedCashIdx = 9;
                            if (key === 'ArrowRight') this.focusedCashIdx = 10;
                            if (key === 'ArrowUp') this.focusedCashIdx = 8;
                        }
                    },

                    selectFocusedCashItem() {
                        if (this.focusedCashIdx === -1) {
                            this.confirmCashAmount();
                            return;
                        }
                        if (this.focusedCashIdx <= 6) {
                            const vals = [2000, 5000, 10000, 20000, 50000, 100000, 200000];
                            this.cashAmountReceived = vals[this.focusedCashIdx];
                            this.playSound('success');
                        } else if (this.focusedCashIdx === 7) {
                            this.cashAmountReceived = 0;
                            this.playSound('warning');
                            this.focusedCashIdx = -1;
                            this.$nextTick(() => this.$refs.cashInput.focus());
                        } else if (this.focusedCashIdx === 8) {
                            this.cashAmountReceived = this.total;
                            this.playSound('success');
                        } else if (this.focusedCashIdx === 9) {
                            this.showCashModal = false;
                        } else if (this.focusedCashIdx === 10) {
                            this.confirmCashAmount();
                        }
                    },

                    paymentMethods: [{
                            id: 'efectivo',
                            name: 'Efectivo',
                            icon: '💵'
                        },
                        {
                            id: 'tarjeta',
                            name: 'Tarjeta',
                            icon: '💳'
                        },
                        {
                            id: 'transferencia',
                            name: 'Transfer.',
                            icon: '🏦'
                        },
                        {
                            id: 'pix',
                            name: 'Pix',
                            icon: '📱'
                        },
                        {
                            id: 'credito',
                            name: 'Crédito',
                            icon: '📅'
                        }
                    ],

                    get paymentMethodsAvailable() {
                        const allowed = Array.isArray(this.allowedPaymentMethods) ? this.allowedPaymentMethods : [];
                        if (allowed.length === 0) return this.paymentMethods;
                        return this.paymentMethods.filter(pm => allowed.includes(pm.id));
                    },

                    get paymentMethodsCobroModal() {
                        return this.paymentMethodsAvailable;
                    },

                    isPaymentMethodAllowed(methodId) {
                        const allowed = Array.isArray(this.allowedPaymentMethods) ? this.allowedPaymentMethods : [];
                        if (allowed.length === 0) return true;
                        return allowed.includes(String(methodId || '').toLowerCase());
                    },

                    cashAmountReceived: 0,
                    showCashModal: false,
                    get cashChange() {
                        const change = this.cashAmountReceived - this.total;
                        return change > 0 ? change : 0;
                    },

                    openCashModal() {
                        this.showCashModal = true;
                        this.cashAmountReceived = this.remainingAmount; // Sugerir monto faltante
                        this.focusedCashIdx = -1; // Reset to input focus
                        this.playSound('info');
                        this.$nextTick(() => {
                            this.$refs.cashInput.focus();
                            this.$refs.cashInput.select();
                        });
                    },

                    confirmCashAmount() {
                        if (this.cashAmountReceived < this.remainingAmount) {
                            // Permitir pagos parciales en efectivo
                            const amtToAdd = this.cashAmountReceived;
                            this.paymentsList.push({
                                method: 'efectivo',
                                amount: amtToAdd,
                                name: 'Efectivo',
                                cash_received: amtToAdd,
                                cash_change: 0
                            });
                            this.showCashModal = false;
                            this.playSound('success');
                            return;
                        }

                        // Si el monto recibido es mayor o igual al faltante
                        const finalAmount = this.remainingAmount;
                        const change = this.cashAmountReceived - finalAmount;

                        this.paymentsList.push({
                            method: 'efectivo',
                            amount: finalAmount,
                            name: 'Efectivo',
                            cash_received: this.cashAmountReceived,
                            cash_change: change > 0 ? change : 0
                        });

                        this.showCashModal = false;
                        this.playSound('success');
                    },

                    openCardModal() {
                        console.log('Abriendo modal de tarjeta...');
                        this.toast('Cargando Boucher...', 'info');
                        this.showCardModal = true;
                        this.voucherNumber = '';
                        this.playSound('info');
                        this.$nextTick(() => {
                            this.$refs.voucherInput?.focus();
                        });
                    },

                    confirmCardPayment() {
                        const amt = this.remainingAmount;
                        this.paymentsList.push({
                            method: 'tarjeta',
                            amount: amt,
                            name: 'Tarjeta',
                            voucher_number: this.voucherNumber
                        });
                        this.showCardModal = false;
                        this.playSound('success');
                    },

                    openTransferModal() {
                        console.log('Abriendo modal de transferencia...');
                        this.toast('Pago por Transferencia...', 'info');
                        this.showTransferModal = true;
                        this.transferReference = '';
                        this.playSound('info');
                        this.$nextTick(() => {
                            this.$refs.transferInput?.focus();
                        });
                    },

                    selectUnifiedMethod(pm) {
                        if (!pm || !this.isPaymentMethodAllowed(pm.id)) {
                            this.toast('Método de pago no permitido para esta caja', 'warning');
                            return;
                        }
                        if (pm.id === 'ueno') {
                            this.pagarConUeno();
                            return;
                        }
                        this.selectedPaymentMethod = pm.id;
                        // Auto-llenar con el monto faltante
                        this.cashAmountReceived = this.remainingAmount > 0 ? this.remainingAmount : 0;

                        // Reset fields
                        if (pm.id === 'tarjeta') this.voucherNumber = '';
                        if (pm.id === 'transferencia') this.transferReference = '';
                        if (pm.id === 'pix') this.qrTransactionCode = '';
                        if (pm.id === 'credito') {
                            this.creditInstallments = 1;
                            this.creditDueDate = new Date().toISOString().split('T')[0];
                            this.creditNotes = '';
                            this.creditInterestPct = 0;
                            this.creditMoraPct = 0;
                            this.creditGraceDays = 0;
                            if (!this.currentTicket.selectedCliente?.id) {
                                this.toast('Atención: Venta a CRÉDITO sin cliente seleccionado', 'warning');
                            }
                        }
                        this.playSound('info');
                        this.$nextTick(() => {
                            const input = this.$refs.unifiedAmountInput;
                            if (input) {
                                input.focus();
                                input.select(); // Seleccionar todo el texto para escribir encima
                            }
                        });
                    },

                    confirmUnifiedPayment() {
                        if (this.cashAmountReceived <= 0 && this.remainingAmount > 0) {
                            this.cashAmountReceived = this.remainingAmount;
                        }

                        const methodId = this.selectedPaymentMethod;
                        if (!this.isPaymentMethodAllowed(methodId)) {
                            this.toast('Método de pago no permitido para esta caja', 'warning');
                            return;
                        }
                        const pm = this.paymentMethods.find(m => m.id === methodId);

                        const paymentObj = {
                            method: methodId,
                            amount: 0,
                            name: pm ? pm.name : methodId
                        };

                        if (methodId === 'efectivo') {
                            const finalAmount = Math.min(this.cashAmountReceived, this.remainingAmount);
                            const change = this.cashAmountReceived - finalAmount;
                            paymentObj.amount = finalAmount;
                            paymentObj.cash_received = this.cashAmountReceived;
                            paymentObj.cash_change = change > 0 ? change : 0;
                        } else if (methodId === 'tarjeta') {
                            paymentObj.amount = this.cashAmountReceived;
                            paymentObj.voucher_number = this.voucherNumber;
                        } else if (methodId === 'transferencia') {
                            paymentObj.amount = this.cashAmountReceived;
                            paymentObj.transfer_reference = this.transferReference;
                        } else if (methodId === 'pix') {
                            paymentObj.amount = this.cashAmountReceived;
                            paymentObj.qr_transaction_code = this.qrTransactionCode;
                        } else if (methodId === 'credito') {
                            paymentObj.amount = this.cashAmountReceived;
                            paymentObj.credit_installments = this.creditInstallments;
                            paymentObj.credit_due_date = this.creditDueDate;
                            paymentObj.credit_notes = this.creditNotes;
                            paymentObj.credit_interest_pct = this.creditInterestPct;
                            paymentObj.credit_mora_pct = this.creditMoraPct;
                            paymentObj.credit_grace_days = this.creditGraceDays;
                        } else {
                            paymentObj.amount = this.cashAmountReceived;
                        }

                        this.paymentsList.push(paymentObj);
                        this.selectedPaymentMethod = null;
                        this.playSound('success');
                    },

                    confirmCreditPayment() {
                        // Mantenido por compatibilidad si se llama desde otro lado, 
                        // pero ahora se usa el flujo unificado.
                    },

                    async pagarConUeno() {
                        // Primero debemos guardar la venta como pendiente para tener un ID real
                        // Aunque venta.php ya guarda y procesa, para Ueno necesitamos el ID antes del webhook.
                        // En este sistema, confirmSale ya lo hace. Así que intentaremos un flujo asíncrono.

                        this.playSound('info');
                        this.toast('Iniciando pago con UENO...', 'info');
                        this.processingUeno = true;

                        try {
                            // 1. Crear la venta preliminar (o usar la actual si ya tiene ID)
                            // Para este flujo usaremos un endpoint que 'congelará' el carrito y devolverá el ID
                            const resVenta = await fetch('api/venta.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    items: this.cart,
                                    total: this.total,
                                    id_cliente: this.currentTicket.selectedCliente?.id || 0,
                                    doc_type: this.selectedDocType,
                                    payment_method: 'ueno',
                                    id_empresa: this.idEmpresa,
                                    id_usuario: this.idUsuario,
                                    id_caja: this.idCaja,
                                    status: 'PENDIENTE' // Nuevo flag para venta.php
                                })
                            });

                            const ventaData = await resVenta.json();
                            if (!ventaData.success) throw new Exception(ventaData.message);

                            const idFactura = ventaData.data.id_factura;

                            // 2. Llamar a create-payment de Ueno
                            const resUeno = await fetch('api/ueno_create_payment.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    id_factura: idFactura,
                                    id_empresa: this.idEmpresa
                                })
                            });

                            const uenoData = await resUeno.json();
                            if (uenoData.success) {
                                this.toast('Redirigiendo a UENO...', 'success');
                                // Abrir Checkout en nueva ventana
                                const win = window.open(uenoData.checkout_url, '_blank');

                                // Mostrar modal de espera
                                this.showUenoModal = true;

                                // Opcional: Polling para detectar cuando se pague
                            } else {
                                this.toast(uenoData.message, 'error');
                            }

                        } catch (e) {
                            console.error('Ueno Error:', e);
                            this.toast('Error al procesar con UENO', 'error');
                        } finally {
                            this.processingUeno = false;
                        }
                    },

                    async openCheckout() {
                        if (this.cart.length === 0) {
                            this.toast('El carrito está vacío', 'error');
                            this.playSound('error');
                            return;
                        }
                        if (!this.currentTicket.selectedCliente) {
                            const confirmed = await this.showConfirm({
                                title: 'Cliente no seleccionado',
                                message: '¿Desea continuar la venta con un cliente "SIN NOMBRE"?',
                                icon: '👤',
                                type: 'warning',
                                confirmText: 'Sí, Sin Nombre',
                                cancelText: 'Cancelar'
                            });

                            if (confirmed) {
                                this.currentTicket.selectedCliente = {
                                    id: 0,
                                    nombre: 'SIN NOMBRE',
                                    numero: '4444440-1'
                                };
                                this.currentTicket.clienteSearch = 'SIN NOMBRE';
                                // Continuar después de asignar cliente
                            } else {
                                this.$nextTick(() => {
                                    this.$refs.clienteInput.focus();
                                });
                                return;
                            }
                        }

                        // Regla de negocio: rol vendedor finaliza siempre como pendiente por caja.
                        if (this.userConfig.isVendedor) {
                            await this.finalizePendingSaleForVendedor();
                            return;
                        }
                        if ((this.isPresupuestoMode() || this.isPedidoProveedorMode()) && !this.editingVenta) {
                            await this.confirmSale();
                            return;
                        }

                        // SIEMPRE abrir modal de pago
                        this.showCheckoutModal = true;
                        this.cashAmountReceived = 0; // Reset cash received
                        this.paymentsList = []; // Limpiar lista de pagos

                        // Si el monto es 0, agregar pago en efectivo automático
                        if (this.total === 0) {
                            this.paymentsList.push({
                                method: 'efectivo',
                                amount: 0,
                                name: 'Efectivo'
                            });
                        }

                        // Aplicar predeterminados
                        if (this.userConfig.isVendedor) {
                            this.selectedDocType = 'comun';
                            this.selectedPaymentMethod = null;
                        } else {
                            this.selectedDocType = this.defaultDocType;
                            this.selectedPaymentMethod = null; // Abrir opciones del medio de pago
                        }

                        // Validar que el tipo de documento seleccionado sea válido para esta empresa
                        if (!this.docTypes.find(d => d.id === this.selectedDocType)) {
                            this.selectedDocType = this.docTypes[0].id;
                        }

                        if (!this.paymentMethodsAvailable.length) {
                            this.showCheckoutModal = false;
                            this.toast('Esta caja no tiene métodos de cobro habilitados', 'error');
                            return;
                        }

                        this.focusedCheckoutSection = 'paymentMethods';
                        this.focusedCheckoutIdx = this.paymentMethodsAvailable.findIndex(pm => pm.id === this.selectedPaymentMethod);
                        if (this.focusedCheckoutIdx === -1) this.focusedCheckoutIdx = 0;

                        this.voucherNumber = ''; // Reset voucher
                        this.playSound('info');
                    },

                    async finalizePendingSaleForVendedor() {
                        if (this.processingSale) return;
                        this.processingSale = true;
                        this.selectedDocType = 'comun';
                        this.showCheckoutModal = false;
                        this.showSimpleChangeModal = false;
                        this.playSound('info');

                        try {
                            const paymentObj = {
                                method: 'pendiente',
                                amount: this.total,
                                name: 'Pendiente',
                                is_pending: true,
                                status: 'PENDIENTE',
                                pendiente_notes: ''
                            };

                            const payload = {
                                items: this.cart,
                                total: this.total,
                                id_cliente: this.currentTicket.selectedCliente?.id || 0,
                                doc_type: 'comun',
                                tipo_documento: 'comun',
                                payments: [paymentObj],
                                payment_method: 'pendiente',
                                forma_pago: 'pendiente',
                                pendiente_notes: '',
                                is_pending: true,
                                status: 'PENDIENTE',
                                cash_received: 0,
                                cash_change: 0,
                                id_empresa: this.idEmpresa,
                                id_usuario: this.idUsuario,
                                id_caja: this.idCaja
                            };
                            const { queued, result } = await this.submitVentaPayload(payload, {
                                allowQueue: true,
                                queueMessage: 'Sin conexión. La venta pendiente quedó guardada localmente y se sincronizará al volver internet.'
                            });
                            if (queued) {
                                this.clearCart();
                                return;
                            }
                            if (!result?.success) {
                                throw new Error(result?.message || 'No se pudo guardar la venta pendiente');
                            }

                            this.lastVenta = result.data || {};
                            const idFactura = Number(result?.data?.id_factura || 0);
                            if (!idFactura) {
                                throw new Error('No se recibió ID de factura');
                            }

                            this.directPrint(
                                idFactura,
                                'comun',
                                this.lastVenta?.nro_factura || result?.data?.nro_factura || '',
                                { paymentMethod: 'pendiente' }
                            );

                            this.clearCart();
                            this.toast('Venta pendiente guardada e impresa', 'success');
                        } catch (e) {
                            console.error('Error finalizePendingSaleForVendedor:', e);
                            this.toast(e?.message || 'Error al guardar venta pendiente', 'error');
                            this.playSound('error');
                        } finally {
                            this.processingSale = false;
                        }
                    },

                    // Mostrar preview del documento antes de confirmar
                    showSalePreview() {
                        this.showPreviewModal = true;
                        this.playSound('info');
                    },

                    openSimplePaymentModal() {
                        // Blindaje adicional por si algún flujo llama este método directamente.
                        if (this.userConfig.isVendedor && !this.editingVenta && Number(this.pendingCobroFacturaId || 0) <= 0) {
                            this.finalizePendingSaleForVendedor();
                            return;
                        }
                        if ((this.isPresupuestoMode() || this.isPedidoProveedorMode()) && !this.editingVenta) {
                            this.confirmSale();
                            return;
                        }
                        const allowed = this.paymentMethodsCobroModal.map(pm => pm.id);
                        if (this.userConfig.isVendedor) {
                            this.selectedDocType = 'comun';
                            const fallback = allowed.includes('efectivo') ? 'efectivo' : (allowed[0] || null);
                            if (fallback) this.selectSimplePaymentMethod(fallback);
                            else this.simplePaymentMethod = null;
                        } else {
                            this.selectedDocType = this.defaultDocType;
                            if (!this.docTypes.find(d => d.id === this.selectedDocType)) {
                                this.selectedDocType = this.docTypes[0]?.id || 'comun';
                            }

                            // Pre-seleccionar método predeterminado si está permitido, sino efectivo
                            const defPM = this.defaultPaymentMethod;
                            if (defPM && allowed.includes(defPM)) {
                                this.selectSimplePaymentMethod(defPM);
                            } else {
                                const fallback = allowed.includes('efectivo') ? 'efectivo' : (allowed[0] || null);
                                this.simplePaymentMethod = fallback;
                                this.simpleCashReceived = this.total;
                            }
                        }
                        this.simpleReference = '';
                        this.simpleCreditInstallments = 1;
                        this.simpleCreditDueDate = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                        this.simpleCreditNotes = '';
                        this.showSimpleChangeModal = true;
                        this.playSound('info');

                        this.$nextTick(() => {
                            setTimeout(() => {
                                if (this.$refs.simpleCashInput) {
                                    this.$refs.simpleCashInput.focus();
                                    this.$refs.simpleCashInput.select();
                                }
                            }, 100);
                        });
                    },

                    validateCreditForAmount(amount) {
                        const cliente = this.currentTicket.selectedCliente;
                        if (!cliente || !cliente.id) {
                            this.toast('Debe seleccionar un cliente para venta a Crédito', 'error');
                            this.playSound('error');
                            return false;
                        }

                        const saldoActual = this.parseCreditNumber(cliente.saldo_guaranies);
                        const lineaCredito = this.parseCreditNumber(cliente.linea_credito);
                        let importeValidar = this.parseCreditNumber(amount);

                        if (this.editingVenta && parseInt(this.editingVenta.id_factura || 0) > 0) {
                            const mismoCliente = parseInt(this.editingVenta.id_cliente || 0) === parseInt(cliente.id || 0);
                            const formaPagoAnterior = String(this.editingVenta.forma_pago || '').trim().toLowerCase();
                            const eraCredito = formaPagoAnterior === '5' || formaPagoAnterior === 'credito' || formaPagoAnterior === 'crédito';
                            const totalAnterior = parseFloat(this.editingVenta.total || 0) || 0;

                            if (mismoCliente && eraCredito) {
                                importeValidar = Math.max(0, importeValidar - totalAnterior);
                            }
                        }

                        const nuevoSaldo = saldoActual + importeValidar;

                        if (nuevoSaldo > lineaCredito) {
                            this.toast(`Límite de crédito excedido. Dif: ${this.formatMoney(nuevoSaldo - lineaCredito)}`, 'error');
                            this.toast(`Saldo: ${this.formatMoney(saldoActual)} | Límite: ${this.formatMoney(lineaCredito)}`, 'info');
                            this.playSound('error');
                            return false;
                        }

                        return true;
                    },

                    parseCreditNumber(value) {
                        if (typeof value === 'number') {
                            return Number.isFinite(value) ? value : 0;
                        }
                        let raw = String(value ?? '').trim();
                        if (!raw) return 0;
                        raw = raw.replace(/\s+/g, '');
                        if (raw.includes(',') && raw.includes('.')) {
                            const lastComma = raw.lastIndexOf(',');
                            const lastDot = raw.lastIndexOf('.');
                            if (lastComma > lastDot) {
                                raw = raw.replace(/\./g, '').replace(',', '.');
                            } else {
                                raw = raw.replace(/,/g, '');
                            }
                        } else if (raw.includes(',')) {
                            raw = raw.replace(/\./g, '').replace(',', '.');
                        } else {
                            raw = raw.replace(/,/g, '');
                        }
                        const parsed = Number(raw);
                        return Number.isFinite(parsed) ? parsed : 0;
                    },

                    async handleFinishSale() {
                        // Vendedor en venta nueva: guardar pendiente e imprimir directo (sin modal de cobro).
                        if (this.userConfig.isVendedor && !this.editingVenta && Number(this.pendingCobroFacturaId || 0) <= 0) {
                            await this.finalizePendingSaleForVendedor();
                            return;
                        }
                        if ((this.isPresupuestoMode() || this.isPedidoProveedorMode()) && !this.editingVenta) {
                            await this.confirmSale();
                            return;
                        }
                        // En otros casos (edición o cobro puntual): usar modal de cobro visual.
                        this.openSimplePaymentModal();
                    },

                    async startCobroFacturaPendiente(idFactura) {
                        try {
                            const res = await fetch(`api/caja.php?action=invoice_detail&id=${idFactura}&id_empresa=${this.idEmpresa}`, { cache: 'no-store' });
                            const data = await res.json();
                            if (!data?.success || !data?.factura) {
                                throw new Error(data?.error || 'No se pudo cargar factura pendiente');
                            }

                            const factura = data.factura || {};
                            const items = Array.isArray(data.items) ? data.items : [];

                            this.clearCart();
                            for (const it of items) {
                                this.cart.push({
                                    id: Number(it.idproducto || 0),
                                    codigo: '',
                                    descripcion: String(it.producto || 'Producto'),
                                    precio: Number(it.precio || 0),
                                    cantidad: Number(it.cantidad || 0) || 1,
                                    iva: 10,
                                    stock: 999999,
                                    stock_inicial: 999999,
                                    controla_stock: -1,
                                    vende_sin_stock: 1
                                });
                            }
                            if (this.cart.length === 0) {
                                this.cart.push({
                                    id: 0,
                                    codigo: '',
                                    descripcion: `Cobro Factura ${String(factura.nro_factura || idFactura)}`,
                                    precio: Number(factura.total || 0),
                                    cantidad: 1,
                                    iva: 10,
                                    stock: 999999,
                                    stock_inicial: 999999,
                                    controla_stock: -1,
                                    vende_sin_stock: 1
                                });
                            }

                            this.selectedCliente = {
                                id: Number(factura.id_cliente || 0),
                                nombre: String(factura.cliente || 'SIN NOMBRE'),
                                numero: String(factura.ruc || '')
                            };
                            this.clienteSearch = String(factura.cliente || 'SIN NOMBRE');
                            this.pendingCobroFacturaId = Number(factura.id_factura || idFactura);
                            this.pendingCobroFacturaNro = String(factura.nro_factura || '');

                            this.openSimplePaymentModal();
                            this.toast(`Cobro pendiente: ${this.pendingCobroFacturaNro || ('#' + this.pendingCobroFacturaId)}`, 'info');
                        } catch (e) {
                            this.toast(e?.message || 'No se pudo abrir modal de cobro pendiente', 'error');
                        }
                    },

                    selectSimplePaymentMethod(method) {
                        if (!this.isPaymentMethodAllowed(method)) {
                            this.toast('Método de pago no permitido para esta caja', 'warning');
                            return;
                        }
                        this.simplePaymentMethod = method;
                        this.simpleReference = '';
                        this.simpleCashReceived = this.total;
                        this.simpleCardCaptureError = '';
                        this.simpleCardCaptureData = null;
                        this.simpleCardInstallments = 1;
                        this.simpleCardFinancingType = 'credito';
                        this.simpleCardProcessor = 'bancard';
                        this.simpleCardMockDecline = false;
                        this.normalizeSimpleCardFields();
                        this.simplePixLoading = false;
                        this.simplePixStatusLoading = false;
                        this.simplePixError = '';
                        this.simplePixData = null;

                        if (method === 'credito') {
                            this.simpleCreditInstallments = 1;
                            this.simpleCreditDueDate = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                            this.simpleCreditNotes = '';
                            this.simpleCreditInterestPct = 0;
                            this.simpleCreditMoraPct = 0;
                            this.simpleCreditGraceDays = 0;
                        }
                        this.$nextTick(() => {
                            if (this.$refs.simpleCashInput) {
                                this.$refs.simpleCashInput.focus();
                                this.$refs.simpleCashInput.select();
                            }
                        });
                    },

                    closeSimpleChangeModal() {
                        this.showSimpleChangeModal = false;
                        this.simplePaymentMethod = '';
                        this.simpleCashReceived = 0;
                        this.simpleReference = '';
                        this.simpleCreditInstallments = 1;
                        this.simpleCreditDueDate = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                        this.simpleCreditNotes = '';
                        this.simpleCreditInterestPct = 0;
                        this.simpleCreditMoraPct = 0;
                        this.simpleCreditGraceDays = 0;
                        this.simpleCardCaptureLoading = false;
                        this.simpleCardCaptureError = '';
                        this.simpleCardCaptureData = null;
                        this.simpleCardInstallments = 1;
                        this.simpleCardFinancingType = 'credito';
                        this.simpleCardProcessor = 'bancard';
                        this.simpleCardMockDecline = false;
                        this.simplePixLoading = false;
                        this.simplePixStatusLoading = false;
                        this.simplePixError = '';
                        this.simplePixData = null;
                        this.pendingCobroFacturaId = 0;
                        this.pendingCobroFacturaNro = '';
                    },

                    getSimpleCardInstallmentOptions() {
                        // En débito no corresponde financiar en cuotas.
                        if (this.simpleCardFinancingType === 'debito') return [1];
                        return Array.from({ length: 12 }, (_, i) => i + 1);
                    },

                    isSimpleCashEmpty() {
                        return this.simpleCashReceived === null || this.simpleCashReceived === undefined || String(this.simpleCashReceived).trim() === '' || Number(this.simpleCashReceived) === 0;
                    },

                    getEffectiveSimpleCashReceived() {
                        if ((this.simplePaymentMethod || '') === 'efectivo' && this.isSimpleCashEmpty() && Number(this.total || 0) > 0) {
                            return Number(this.total || 0);
                        }
                        return Number(this.simpleCashReceived || 0);
                    },

                    normalizeSimpleCardFields() {
                        const validFinancing = this.simpleCardFinancingTypeOptions.map(o => o.value);
                        if (!validFinancing.includes(this.simpleCardFinancingType)) {
                            this.simpleCardFinancingType = 'credito';
                        }

                        const validProcessors = this.simpleCardProcessorOptions.map(o => o.value);
                        if (!validProcessors.includes(this.simpleCardProcessor)) {
                            this.simpleCardProcessor = 'bancard';
                        }

                        const validInstallments = this.getSimpleCardInstallmentOptions();
                        const currentInstallments = parseInt(this.simpleCardInstallments, 10) || 1;
                        this.simpleCardInstallments = validInstallments.includes(currentInstallments) ? currentInstallments : validInstallments[0];
                    },

                    async captureCardFromTerminal() {
                        if (this.simpleCardCaptureLoading) return;
                        if (this.total <= 0) {
                            this.toast('Monto inválido para cobro con tarjeta', 'error');
                            return;
                        }

                        this.simpleCardCaptureLoading = true;
                        this.simpleCardCaptureError = '';
                        this.simpleCardCaptureData = null;
                        this.normalizeSimpleCardFields();
                        this.playSound('info');

                        try {
                            const response = await fetch('api/card_terminal.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action: 'sale',
                                    amount: this.total,
                                    country: 'PY',
                                    integration: 'pos_py',
                                    installments: parseInt(this.simpleCardInstallments, 10) || 1,
                                    financing_type: this.simpleCardFinancingType || 'credito',
                                    processor: this.simpleCardProcessor || 'bancard',
                                    mock_decline: !!this.simpleCardMockDecline,
                                    id_empresa: this.idEmpresa,
                                    id_caja: this.idCaja,
                                    id_usuario: this.idUsuario
                                })
                            });

                            const result = await response.json();
                            if (!result.success) {
                                throw new Error(result.message || 'No se pudo capturar la tarjeta');
                            }
                            if (result.data && result.data.approved === false) {
                                throw new Error(result.data.status ? `Transacción rechazada: ${result.data.status}` : 'Transacción rechazada por terminal');
                            }

                            const txn = result.data || {};
                            this.simpleCardCaptureData = txn;
                            this.simpleReference = String(txn.reference || txn.nsu || txn.auth_code || txn.transaction_id || '').trim();

                            this.toast('Tarjeta capturada correctamente', 'success');
                            this.playSound('success');
                        } catch (e) {
                            console.error('Card terminal error:', e);
                            this.simpleCardCaptureError = e?.message || 'Error de conexión con terminal';
                            this.toast(this.simpleCardCaptureError, 'error');
                            this.playSound('error');
                        } finally {
                            this.simpleCardCaptureLoading = false;
                        }
                    },

                    async createPixCharge() {
                        if (this.simplePixLoading) return;
                        if (this.total <= 0) {
                            this.toast('Monto inválido para cobro PIX', 'error');
                            return;
                        }

                        this.simplePixLoading = true;
                        this.simplePixError = '';
                        this.simplePixData = null;
                        this.simpleReference = '';

                        try {
                            const response = await fetch('api/pix_payment.php?action=create', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    amount: this.total,
                                    id_empresa: this.idEmpresa,
                                    id_caja: this.idCaja,
                                    id_usuario: this.idUsuario
                                })
                            });
                            const result = await response.json();
                            if (!result.success) throw new Error(result.message || 'No se pudo generar PIX');

                            this.simplePixData = result.data || {};
                            this.simpleReference = String(this.simplePixData.reference || '').trim();
                            this.toast('PIX generado. Esperando pago...', 'info');
                        } catch (e) {
                            this.simplePixError = e?.message || 'Error generando PIX';
                            this.toast(this.simplePixError, 'error');
                        } finally {
                            this.simplePixLoading = false;
                        }
                    },

                    async refreshPixStatus() {
                        if (this.simplePixStatusLoading) return;
                        const reference = String(this.simpleReference || this.simplePixData?.reference || '').trim();
                        if (!reference) {
                            this.toast('Primero genere un PIX', 'error');
                            return;
                        }

                        this.simplePixStatusLoading = true;
                        this.simplePixError = '';
                        try {
                            const response = await fetch('api/pix_payment.php?action=status', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ reference })
                            });
                            const result = await response.json();
                            if (!result.success) throw new Error(result.message || 'No se pudo consultar PIX');

                            const prevPaid = !!(this.simplePixData && this.simplePixData.paid);
                            this.simplePixData = result.data || {};
                            this.simpleReference = String(this.simplePixData.reference || reference).trim();
                            if (this.simplePixData.paid && !prevPaid) {
                                this.toast('PIX confirmado como PAGADO', 'success');
                                this.playSound('success');
                            } else if (!this.simplePixData.paid) {
                                this.toast('PIX aún pendiente', 'info');
                            }
                        } catch (e) {
                            this.simplePixError = e?.message || 'Error consultando PIX';
                            this.toast(this.simplePixError, 'error');
                        } finally {
                            this.simplePixStatusLoading = false;
                        }
                    },

                    async markPixPaidQa() {
                        const reference = String(this.simpleReference || this.simplePixData?.reference || '').trim();
                        if (!reference) {
                            this.toast('Primero genere un PIX', 'error');
                            return;
                        }
                        try {
                            const response = await fetch('api/pix_payment.php?action=mark_paid', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ reference })
                            });
                            const result = await response.json();
                            if (!result.success) throw new Error(result.message || 'No se pudo confirmar PIX QA');
                            await this.refreshPixStatus();
                        } catch (e) {
                            this.simplePixError = e?.message || 'Error confirmando PIX QA';
                            this.toast(this.simplePixError, 'error');
                        }
                    },

                    async confirmSimpleSale() {
                        const pricesOk = await this.ensureCartPriceAuthorizations('CHECKOUT_SIMPLE_WEB');
                        if (!pricesOk) {
                            this.playSound('error');
                            return;
                        }
                        if (!this.simplePaymentMethod) {
                            this.toast('Debe seleccionar una forma de pago', 'error');
                            this.playSound('error');
                            return;
                        }

                        let entrega = this.getEffectiveSimpleCashReceived();
                        const metodo = this.simplePaymentMethod || 'efectivo';
                        if (!this.isPaymentMethodAllowed(metodo)) {
                            this.toast('Método de pago no permitido para esta caja', 'error');
                            return;
                        }
                        const isCredito = metodo === 'credito';
                        const requiereReferencia = metodo === 'tarjeta' || metodo === 'transferencia' || metodo === 'pix';

                        if (entrega <= 0 && this.total > 0) {
                            // Flujo solicitado: si viene vacío/0, completar en automático con el total.
                            this.simpleCashReceived = this.total;
                            entrega = this.total;
                        }

                        if (metodo === 'efectivo' && entrega < this.total) {
                            this.toast('El monto recibido es menor al total', 'error');
                            this.playSound('error');
                            return;
                        }

                        if (metodo !== 'efectivo' && entrega < this.total) {
                            this.toast('El monto debe cubrir el total de la venta', 'error');
                            this.playSound('error');
                            return;
                        }

                        if (requiereReferencia && !String(this.simpleReference || '').trim()) {
                            this.toast('Debe ingresar el número de referencia', 'error');
                            this.playSound('error');
                            return;
                        }

                        if (metodo === 'pix' && !(this.simplePixData && this.simplePixData.paid)) {
                            this.toast('PIX pendiente. Confirme el pago antes de cobrar.', 'error');
                            this.playSound('error');
                            return;
                        }

                        if (isCredito && !this.validateCreditForAmount(this.total)) {
                            return;
                        }

                        if (this.pendingCobroFacturaId > 0) {
                            const metodoApi = ({
                                efectivo: 'EFECTIVO',
                                tarjeta: 'TARJETA',
                                transferencia: 'TRANSFERENCIA',
                                pix: 'QR',
                                credito: 'CREDITO'
                            })[metodo] || 'EFECTIVO';

                            try {
                                const payload = {
                                    id_factura: this.pendingCobroFacturaId,
                                    monto: Number(this.total || 0),
                                    payment_method: metodoApi,
                                    payment_ref: String(this.simpleReference || '').trim(),
                                    tipo_documento: 'FACTURA',
                                    tipo_cobro: 'TOTAL'
                                };
                                if (metodo === 'credito') {
                                    payload.credit_installments = Math.max(parseInt(this.simpleCreditInstallments, 10) || 1, 1);
                                    payload.credit_due_date = this.simpleCreditDueDate || '';
                                    payload.credit_notes = String(this.simpleCreditNotes || '').trim();
                                    payload.credit_interest_pct = parseFloat(this.simpleCreditInterestPct || 0) || 0;
                                    payload.credit_mora_pct = parseFloat(this.simpleCreditMoraPct || 0) || 0;
                                    payload.credit_grace_days = Math.max(parseInt(this.simpleCreditGraceDays, 10) || 0, 0);
                                }

                                const resCobro = await fetch('api/caja.php?action=cobro_factura', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify(payload)
                                });
                                const dataCobro = await resCobro.json();
                                if (!dataCobro?.success) {
                                    throw new Error(dataCobro?.error || dataCobro?.message || 'No se pudo registrar cobro pendiente');
                                }

                                this.showSimpleChangeModal = false;
                                this.pendingCobroFacturaId = 0;
                                this.pendingCobroFacturaNro = '';
                                this.clearCart();
                                this.toast(dataCobro.message || 'Cobro registrado correctamente', 'success');
                                return;
                            } catch (e) {
                                this.toast(e?.message || 'Error registrando cobro pendiente', 'error');
                                return;
                            }
                        }

                        // En edición: usar este mismo modal de cobro y actualizar venta (no crear una nueva)
                        if (this.editingVenta) {
                            const paymentObj = {
                                method: metodo,
                                amount: this.total,
                                name: ({
                                    efectivo: 'Efectivo',
                                    tarjeta: 'Tarjeta',
                                    transferencia: 'Transferencia',
                                    pix: 'Pix',
                                    credito: 'Crédito'
                                })[metodo] || metodo
                            };

                            if (metodo === 'efectivo') {
                                paymentObj.cash_received = entrega;
                                paymentObj.cash_change = Math.max(entrega - this.total, 0);
                            } else if (metodo === 'tarjeta') {
                                paymentObj.voucher_number = String(this.simpleReference || '').trim();
                                paymentObj.card_terminal_reference = this.simpleCardCaptureData?.reference || '';
                                paymentObj.card_auth_code = this.simpleCardCaptureData?.auth_code || '';
                                paymentObj.card_nsu = this.simpleCardCaptureData?.nsu || '';
                                paymentObj.card_acquirer = this.simpleCardCaptureData?.acquirer || '';
                                paymentObj.card_brand = this.simpleCardCaptureData?.brand || '';
                                paymentObj.card_masked_pan = this.simpleCardCaptureData?.masked_pan || '';
                                paymentObj.card_rrn = this.simpleCardCaptureData?.rrn || '';
                                paymentObj.card_batch = this.simpleCardCaptureData?.batch || '';
                                paymentObj.card_installments = parseInt(this.simpleCardCaptureData?.installments || this.simpleCardInstallments, 10) || 1;
                                paymentObj.card_financing_type = this.simpleCardCaptureData?.financing_type || this.simpleCardFinancingType;
                                paymentObj.card_processor = this.simpleCardCaptureData?.processor || this.simpleCardProcessor;
                            } else if (metodo === 'transferencia') {
                                paymentObj.transfer_reference = String(this.simpleReference || '').trim();
                            } else if (metodo === 'pix') {
                                paymentObj.qr_transaction_code = String(this.simpleReference || '').trim();
                            } else if (metodo === 'credito') {
                                paymentObj.credit_installments = Math.max(parseInt(this.simpleCreditInstallments, 10) || 1, 1);
                                paymentObj.credit_due_date = this.simpleCreditDueDate || '';
                                paymentObj.credit_notes = String(this.simpleCreditNotes || '').trim();
                                paymentObj.credit_interest_pct = parseFloat(this.simpleCreditInterestPct || 0) || 0;
                                paymentObj.credit_mora_pct = parseFloat(this.simpleCreditMoraPct || 0) || 0;
                                paymentObj.credit_grace_days = Math.max(parseInt(this.simpleCreditGraceDays, 10) || 0, 0);
                            }

                            this.paymentsList = [paymentObj];
                            this.showSimpleChangeModal = false;
                            this.closeTicketModal();
                            this.showCheckoutModal = false;
                            await this.updateVenta();
                            return;
                        }

                        if (this.processingSale) return;
                        this.processingSale = true;
                        this.playSound('success');

                        // Cerrar TODOS los modales
                        this.showSimpleChangeModal = false;
                        this.closeTicketModal();
                        this.showCheckoutModal = false;

                        try {
                            const names = {
                                efectivo: 'Efectivo',
                                tarjeta: 'Tarjeta',
                                transferencia: 'Transferencia',
                                pix: 'Pix',
                                credito: 'Crédito'
                            };

                            const paymentObj = {
                                method: metodo,
                                amount: this.total,
                                name: names[metodo] || metodo
                            };

                            if (metodo === 'efectivo') {
                                paymentObj.cash_received = entrega;
                                paymentObj.cash_change = Math.max(entrega - this.total, 0);
                            } else if (metodo === 'tarjeta') {
                                paymentObj.voucher_number = String(this.simpleReference || '').trim();
                                paymentObj.card_terminal_reference = this.simpleCardCaptureData?.reference || '';
                                paymentObj.card_auth_code = this.simpleCardCaptureData?.auth_code || '';
                                paymentObj.card_nsu = this.simpleCardCaptureData?.nsu || '';
                                paymentObj.card_acquirer = this.simpleCardCaptureData?.acquirer || '';
                                paymentObj.card_brand = this.simpleCardCaptureData?.brand || '';
                                paymentObj.card_masked_pan = this.simpleCardCaptureData?.masked_pan || '';
                                paymentObj.card_rrn = this.simpleCardCaptureData?.rrn || '';
                                paymentObj.card_batch = this.simpleCardCaptureData?.batch || '';
                                paymentObj.card_installments = parseInt(this.simpleCardCaptureData?.installments || this.simpleCardInstallments, 10) || 1;
                                paymentObj.card_financing_type = this.simpleCardCaptureData?.financing_type || this.simpleCardFinancingType;
                                paymentObj.card_processor = this.simpleCardCaptureData?.processor || this.simpleCardProcessor;
                            } else if (metodo === 'transferencia') {
                                paymentObj.transfer_reference = String(this.simpleReference || '').trim();
                            } else if (metodo === 'pix') {
                                paymentObj.qr_transaction_code = String(this.simpleReference || '').trim();
                            } else if (metodo === 'credito') {
                                paymentObj.credit_installments = Math.max(parseInt(this.simpleCreditInstallments, 10) || 1, 1);
                                paymentObj.credit_due_date = this.simpleCreditDueDate || '';
                                paymentObj.credit_notes = String(this.simpleCreditNotes || '').trim();
                                paymentObj.credit_interest_pct = parseFloat(this.simpleCreditInterestPct || 0) || 0;
                                paymentObj.credit_mora_pct = parseFloat(this.simpleCreditMoraPct || 0) || 0;
                                paymentObj.credit_grace_days = Math.max(parseInt(this.simpleCreditGraceDays, 10) || 0, 0);
                            }

                            const paymentsList = [paymentObj];

                            const payload = {
                                items: this.cart,
                                total: this.total,
                                id_cliente: this.currentTicket.selectedCliente?.id || 0,
                                cliente_nombre: this.currentTicket.selectedCliente?.nombre || '',
                                cliente_ruc: this.currentTicket.selectedCliente?.ruc || '',
                                doc_type: this.selectedDocType,
                                payments: paymentsList,
                                payment_method: metodo,
                                voucher_number: paymentObj.voucher_number || '',
                                transfer_reference: paymentObj.transfer_reference || '',
                                qr_transaction_code: paymentObj.qr_transaction_code || '',
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
                                credit_installments: paymentObj.credit_installments || 1,
                                credit_due_date: paymentObj.credit_due_date || '',
                                credit_notes: paymentObj.credit_notes || '',
                                credit_interest_pct: paymentObj.credit_interest_pct || 0,
                                credit_mora_pct: paymentObj.credit_mora_pct || 0,
                                credit_grace_days: paymentObj.credit_grace_days || 0,
                                pendiente_notes: paymentObj.pendiente_notes || '',
                                is_pending: paymentObj.is_pending || false,
                                status: paymentObj.status || '',
                                cash_received: paymentObj.cash_received || 0,
                                cash_change: paymentObj.cash_change || 0,
                                id_empresa: this.idEmpresa,
                                id_usuario: this.idUsuario,
                                id_caja: this.idCaja
                            };
                            const { queued, result } = await this.submitVentaPayload(payload, {
                                allowQueue: true
                            });
                            if (queued) {
                                this.clearCart();
                                return;
                            }
                            console.log('📦 RESPUESTA VENTA (simple):', result);

                            if (result.success) {
                                this.lastVenta = result.data;
                                if (this.isPresupuestoMode()) {
                                    this.showCheckoutModal = false;
                                    this.toast('Presupuesto guardado con éxito', 'success');
                                    this.showPresupuestoActions();
                                    this.clearCart();
                                    return;
                                }
                                if (this.isPedidoProveedorMode()) {
                                    this.showCheckoutModal = false;
                                    this.toast('Pedido guardado con éxito', 'success');
                                    this.showManagedDocumentActions();
                                    this.clearCart();
                                    return;
                                }
                                const idFactura = result.data.id_factura;
                                let printDocType = this.selectedDocType;

                                // FE asíncrona: encolar y continuar caja sin bloqueo.
                                if (this.selectedDocType === 'electro') {
                                    try {
                                        const feRes = await this.emitirSifenYEsperar(idFactura, { fastPrint: true });
                                        if (feRes?.cdc && this.lastVenta && this.lastVenta.id_factura == idFactura) {
                                            this.lastVenta.cdc = feRes.cdc;
                                            this.lastVenta.tipo_documento = 3;
                                        }
                                        await this.enqueueSifenEmision(idFactura, 'consultar');
                                        this.toast('⚡ FE generada y firmada. Impresión inmediata; confirmación SIFEN en segundo plano.', 'info');
                                        printDocType = 'electro';
                                    } catch (sifenErr) {
                                        console.warn('No se pudo emitir FE en modo rápido:', sifenErr?.message || sifenErr);
                                        try {
                                            await this.enqueueSifenEmision(idFactura, 'emitir');
                                            this.toast('Venta guardada. FE encolada para envío/reintento.', 'warning');
                                        } catch (queueErr) {
                                            console.error('No se pudo encolar FE:', queueErr);
                                            this.toast('FE no pudo encolarse automáticamente. Revisar cola SIFEN.', 'error');
                                        }
                                        printDocType = 'electro';
                                    }
                                }

                                // 🖨️ Imprimir con el tipo correcto
                                this.directPrint(
                                    idFactura,
                                    printDocType,
                                    this.lastVenta?.nro_factura || result.data?.nro_factura || '',
                                    { allowPendingFE: this.selectedDocType === 'electro' }
                                );

                                // Limpiar carrito y volver al POS directo
                                this.clearCart();
                                this.toast('✅ Venta #' + idFactura + ' cobrada e impresa', 'success');
                            } else {
                                this.toast(result.message || 'Error al procesar venta', 'error');
                                this.playSound('error');
                            }
                        } catch (e) {
                            console.error('Error in confirmSimpleSale:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.processingSale = false;
                        }
                    },

                    async confirmSale() {
                        if (this.processingSale) return;
                        const pricesOk = await this.ensureCartPriceAuthorizations('CHECKOUT_WEB');
                        if (!pricesOk) return;
                        this.processingSale = true;
                        this.playSound('success');

                        try {
                            const payload = {
                                items: this.cart,
                                total: this.total,
                                id_cliente: this.currentTicket.selectedCliente?.id || 0,
                                cliente_nombre: this.currentTicket.selectedCliente?.nombre || '',
                                cliente_ruc: this.currentTicket.selectedCliente?.ruc || '',
                                doc_type: this.selectedDocType,
                                payments: this.paymentsList,
                                payment_method: this.paymentsList.length > 0 ? this.paymentsList[0].method : 'efectivo',
                                voucher_number: this.paymentsList.find(p => p.method === 'tarjeta')?.voucher_number || '',
                                transfer_reference: this.paymentsList.find(p => p.method === 'transferencia')?.transfer_reference || '',
                                qr_transaction_code: this.paymentsList.find(p => p.method === 'pix')?.qr_transaction_code || this.paymentsList.find(p => p.method === 'qr')?.qr_transaction_code || '',
                                credit_installments: this.paymentsList.find(p => p.method === 'credito')?.credit_installments || 1,
                                credit_due_date: this.paymentsList.find(p => p.method === 'credito')?.credit_due_date || '',
                                credit_notes: this.paymentsList.find(p => p.method === 'credito')?.credit_notes || '',
                                credit_interest_pct: this.paymentsList.find(p => p.method === 'credito')?.credit_interest_pct || 0,
                                credit_mora_pct: this.paymentsList.find(p => p.method === 'credito')?.credit_mora_pct || 0,
                                credit_grace_days: this.paymentsList.find(p => p.method === 'credito')?.credit_grace_days || 0,
                                cash_received: this.paymentsList.reduce((sum, p) => sum + (p.cash_received || 0), 0),
                                cash_change: this.paymentsList.reduce((sum, p) => sum + (p.cash_change || 0), 0),
                                id_empresa: this.idEmpresa,
                                id_usuario: this.idUsuario,
                                id_caja: this.idCaja
                            };
                            const { queued, result } = await this.submitVentaPayload(payload, {
                                allowQueue: true
                            });
                            if (queued) {
                                this.closeTicketModal();
                                this.showCheckoutModal = false;
                                this.clearCart();
                                return;
                            }

                            // Log completo de la respuesta
                            console.log('📦 RESPUESTA COMPLETA VENTA:', result);

                            // Log de debug específico
                            if (result.debug) {
                                console.log('🔍 DEBUG VENTA:', result.debug);
                            }

                            if (result.success) {
                                this.lastVenta = result.data;
                                if (this.isPresupuestoMode()) {
                                    this.closeTicketModal();
                                    this.showCheckoutModal = false;
                                    this.toast('Presupuesto guardado con éxito', 'success');
                                    this.showPresupuestoActions();
                                    this.clearCart();
                                    return;
                                }
                                if (this.isPedidoProveedorMode()) {
                                    this.closeTicketModal();
                                    this.showCheckoutModal = false;
                                    this.toast('Pedido guardado con éxito', 'success');
                                    this.showManagedDocumentActions();
                                    this.clearCart();
                                    return;
                                }
                                this.toast('Venta realizada con éxito', 'success');

                                const idFactura = result.data.id_factura;
                                const isElectro = this.selectedDocType === 'electro';
                                let printDocType = this.selectedDocType;

                                // FE asíncrona: encolar y continuar caja sin bloqueo.
                                if (isElectro) {
                                    try {
                                        const feRes = await this.emitirSifenYEsperar(idFactura, { fastPrint: true });
                                        if (feRes?.cdc && this.lastVenta && this.lastVenta.id_factura == idFactura) {
                                            this.lastVenta.cdc = feRes.cdc;
                                            this.lastVenta.tipo_documento = 3;
                                        }
                                        await this.enqueueSifenEmision(idFactura, 'consultar');
                                        this.toast('⚡ FE generada y firmada. Impresión inmediata; confirmación SIFEN en segundo plano.', 'info');
                                        printDocType = 'electro';
                                    } catch (sifenErr) {
                                        console.warn('No se pudo emitir FE en modo rápido:', sifenErr?.message || sifenErr);
                                        try {
                                            await this.enqueueSifenEmision(idFactura, 'emitir');
                                            this.toast('Venta guardada. FE encolada para envío/reintento.', 'warning');
                                        } catch (queueErr) {
                                            console.error('No se pudo encolar FE:', queueErr);
                                            this.toast('FE no pudo encolarse automáticamente. Revisar cola SIFEN.', 'error');
                                        }
                                        printDocType = 'electro';
                                    }
                                }

                                // Redirección directa a impresión según tipo
                                const hasCDC = !!(this.lastVenta.cdc && this.lastVenta.cdc.length > 10);
                                let script = hasCDC ? 'kude.php' : 'ticket.php';
                                this.ticketUrl = script + '?id=' + idFactura + '&id_empresa=' + this.idEmpresa;
                                console.log('🎫 Ticket URL generada:', this.ticketUrl);
                                this.closeTicketModal();
                                this.showCheckoutModal = false;

                                // 🖨️ Impresión directa automática a impresora de caja
                                this.directPrint(
                                    idFactura,
                                    printDocType,
                                    this.lastVenta?.nro_factura || result.data?.nro_factura || '',
                                    { allowPendingFE: isElectro }
                                );

                                this.clearCart();
                                this.toast('Venta finalizada con éxito', 'success');
                            } else {
                                this.toast(result.message || 'Error al procesar venta', 'error');
                            }
                        } catch (e) {
                            console.error('Error in confirmSale:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.processingSale = false;
                        }
                    },

                    clearCart() {
                        this.cart = [];
                        this.currentTicket.selectedCliente = null;
                        this.currentTicket.clienteSearch = '';
                        this.importedPresupuesto = null;
                        this.editingVenta = null; // Limpiar modo edición
                        this.saveTickets();
                        this.focusPrimaryInput();
                    },

                    // ===== EDICIÓN DE VENTAS =====
                    openEditSearchModal() {
                        this.showEditSearchModal = true;
                        this.editSearchQuery = '';
                        this.editSifenFilter = 'todos';
                        this.editSearchResults = [];
                        this.searchEditableVentas();
                        this.playSound('info');
                    },

                    openPresupuestoImportModal() {
                        this.showPresupuestoImportModal = true;
                        this.presupuestoSearchQuery = '';
                        this.presupuestoEstadoFilter = 'activo';
                        this.presupuestoSearchResults = [];
                        this.searchPresupuestos();
                        this.playSound('info');
                    },

                    async searchEditableVentas() {
                        const reqId = ++this._editSearchReqId;
                        if (this._editSearchAbortController) {
                            try { this._editSearchAbortController.abort(); } catch (e) { /* ignore */ }
                        }
                        const controller = new AbortController();
                        this._editSearchAbortController = controller;
                        const timeoutId = setTimeout(() => {
                            try { controller.abort(); } catch (e) { /* ignore */ }
                        }, 12000);

                        this.loadingEditSearch = true;
                        try {
                            const res = await fetch(`api/venta_edit.php?action=search&q=${encodeURIComponent(this.editSearchQuery)}&sifen_filter=${encodeURIComponent(this.editSifenFilter)}&id_empresa=${this.idEmpresa}&id_usuario=${this.idUsuario}`, {
                                signal: controller.signal,
                                cache: 'no-store'
                            });
                            const data = await res.json();
                            if (reqId !== this._editSearchReqId) return;

                            if (data.success) {
                                this.editSearchResults = data.ventas || [];
                            } else {
                                this.toast(data.message || 'Error al buscar ventas', 'error');
                            }
                        } catch (e) {
                            if (reqId !== this._editSearchReqId) return;
                            console.error('Error searching editable sales:', e);
                            this.toast(
                                (e && e.name === 'AbortError')
                                    ? 'Tiempo de espera agotado al cargar ventas'
                                    : 'Error de conexión',
                                'error'
                            );
                        } finally {
                            clearTimeout(timeoutId);
                            if (reqId === this._editSearchReqId) {
                                this.loadingEditSearch = false;
                                this._editSearchAbortController = null;
                            }
                        }
                    },

                    async searchPresupuestos() {
                        const reqId = ++this._presupuestoSearchReqId;
                        if (this._presupuestoSearchAbortController) {
                            try { this._presupuestoSearchAbortController.abort(); } catch (e) { /* ignore */ }
                        }
                        const controller = new AbortController();
                        this._presupuestoSearchAbortController = controller;
                        const timeoutId = setTimeout(() => {
                            try { controller.abort(); } catch (e) { /* ignore */ }
                        }, 12000);

                        this.loadingPresupuestoSearch = true;
                        try {
                            const params = new URLSearchParams({
                                action: 'list',
                                q: this.presupuestoSearchQuery,
                                estado: this.presupuestoEstadoFilter,
                                periodo: 'todo',
                                limit: '100',
                                id_empresa: this.idEmpresa
                            });
                            const res = await fetch(`api/presupuestos_manage.php?${params.toString()}`, {
                                signal: controller.signal,
                                cache: 'no-store'
                            });
                            const data = await res.json();
                            if (reqId !== this._presupuestoSearchReqId) return;

                            if (data.success) {
                                this.presupuestoSearchResults = data.rows || [];
                            } else {
                                this.toast(data.message || 'Error al buscar presupuestos', 'error');
                            }
                        } catch (e) {
                            if (reqId !== this._presupuestoSearchReqId) return;
                            console.error('Error searching presupuestos:', e);
                            this.toast(
                                (e && e.name === 'AbortError')
                                    ? 'Tiempo de espera agotado al cargar presupuestos'
                                    : 'Error de conexión',
                                'error'
                            );
                        } finally {
                            clearTimeout(timeoutId);
                            if (reqId === this._presupuestoSearchReqId) {
                                this.loadingPresupuestoSearch = false;
                                this._presupuestoSearchAbortController = null;
                            }
                        }
                    },

                    async loadPresupuestoForImport(idFactura) {
                        const hasCartItems = Array.isArray(this.cart) && this.cart.length > 0;
                        if (hasCartItems) {
                            const confirmed = await this.showConfirm({
                                title: 'Importar Presupuesto',
                                message: 'El ticket actual tiene productos. ¿Desea reemplazarlo por el presupuesto seleccionado?',
                                icon: '📥',
                                type: 'warning',
                                confirmText: 'Importar'
                            });
                            if (!confirmed) return;
                        }

                        this.loadingPresupuestoSearch = true;
                        try {
                            const res = await fetch(`api/presupuestos_manage.php?action=load&id=${encodeURIComponent(idFactura)}&id_empresa=${this.idEmpresa}`, {
                                cache: 'no-store'
                            });
                            const data = await res.json();

                            if (data.success) {
                                this.cart = [];
                                this.currentTicket.selectedCliente = null;
                                this.currentTicket.clienteSearch = '';
                                this.editingVenta = null;
                                this.paymentsList = [];

                                if (data.cliente) {
                                    this.currentTicket.selectedCliente = data.cliente;
                                    this.currentTicket.clienteSearch = data.cliente.nombre || '';
                                }

                                (data.items || []).forEach((item) => {
                                    this.cart.push({
                                        id: item.id,
                                        extracto_id: item.extracto_id,
                                        codigo: item.codigo,
                                        descripcion: item.descripcion,
                                        cantidad: item.cantidad,
                                        precio: item.precio,
                                        costo: item.costo,
                                        tasa_iva: this.normalizeIvaRate(item.tasa_iva),
                                        stock: item.stock,
                                        controla_stock: item.controla_stock,
                                        vende_sin_stock: item.vende_sin_stock
                                    });
                                });

                                this.importedPresupuesto = {
                                    id_factura: data.venta?.id_factura || idFactura,
                                    nro_factura: data.venta?.nro_factura || ('PRES-' + idFactura)
                                };
                                this.showPresupuestoImportModal = false;
                                this.toast(`Presupuesto ${data.venta?.nro_factura || ('#' + idFactura)} importado`, 'success');
                                this.playSound('success');
                                this.saveTickets();
                                this.focusPrimaryInput();
                            } else {
                                this.toast(data.message || 'Error al cargar presupuesto', 'error');
                                this.playSound('error');
                            }
                        } catch (e) {
                            console.error('Error loading presupuesto for import:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.loadingPresupuestoSearch = false;
                        }
                    },

                    async retrySifenFromList(venta) {
                        try {
                            const ok = await this.showConfirm({
                                title: 'Reintentar SIFEN',
                                message: `¿Reintentar envío SIFEN para ${venta.nro_factura}?`,
                                icon: '⚡',
                                type: 'warning',
                                confirmText: 'Reintentar'
                            });
                            if (!ok) return;

                            const formData = new FormData();
                            formData.append('id_factura', venta.id_factura);
                            formData.append('id_empresa', this.idEmpresa);

                            const res = await fetch('api/sifen_retry.php', {
                                method: 'POST',
                                body: formData
                            });
                            const data = await res.json();

                            if (data.success) {
                                this.toast('SIFEN reprocesado correctamente', 'success');
                                this.searchEditableVentas();
                            } else {
                                await this.showAlert('Error SIFEN', data.message || 'No se pudo reprocesar SIFEN', '⚠️', 'danger');
                            }
                        } catch (e) {
                            console.error('retrySifenFromList error:', e);
                            this.toast('Error de conexión al reprocesar SIFEN', 'error');
                        }
                    },

                    async consultSifenStatusFromList(venta) {
                        try {
                            const ok = await this.showConfirm({
                                title: 'Consultar estado SIFEN',
                                message: `¿Consultar estado del lote para ${venta.nro_factura}?`,
                                icon: '🔎',
                                type: 'info',
                                confirmText: 'Consultar'
                            });
                            if (!ok) return;

                            const formData = new FormData();
                            formData.append('id_factura', venta.id_factura);
                            formData.append('id_empresa', this.idEmpresa);
                            formData.append('consult_only', '1');

                            const res = await fetch('api/sifen_retry.php', {
                                method: 'POST',
                                body: formData
                            });
                            const data = await res.json();

                            if (data.success) {
                                this.toast(data.message || 'Estado SIFEN actualizado', 'success');
                                this.searchEditableVentas();
                            } else {
                                await this.showAlert('Estado SIFEN', data.message || 'No se pudo consultar el estado de lote', '⚠️', 'warning');
                            }
                        } catch (e) {
                            console.error('consultSifenStatusFromList error:', e);
                            this.toast('Error de conexión al consultar estado SIFEN', 'error');
                        }
                    },

                    async loadVentaForEdit(id_factura) {
                        this.loadingEditSearch = true;
                        try {
                            const res = await fetch(`${this.getEditApiEndpoint()}?action=load&id=${id_factura}&id_empresa=${this.idEmpresa}`);
                            const data = await res.json();

                            if (data.success) {
                                // Limpiar carrito actual
                                this.cart = [];
                                this.currentTicket.selectedCliente = null;

                                // Cargar cliente
                                if (data.cliente) {
                                    this.currentTicket.selectedCliente = data.cliente;
                                    this.currentTicket.clienteSearch = data.cliente.nombre;
                                }

                                // Cargar items al carrito
                                data.items.forEach(item => {
                                this.cart.push({
                                    id: item.id,
                                    extracto_id: item.extracto_id,
                                    codigo: item.codigo,
                                    descripcion: item.descripcion,
                                    cantidad: item.cantidad,
                                    precio: item.precio,
                                    costo: item.costo,
                                    tasa_iva: this.normalizeIvaRate(item.tasa_iva),
                                    stock: item.stock,
                                    controla_stock: item.controla_stock,
                                    vende_sin_stock: item.vende_sin_stock
                                });
                            });

                                // Guardar referencia a la venta que estamos editando
                                this.editingVenta = data.venta;
                                this.importedPresupuesto = null;

                                this.showEditSearchModal = false;
                                this.toast(`Editando ${this.getManagedDocumentLabel()} #${data.venta.nro_factura}`, 'success');
                                this.playSound('success');
                                this.saveTickets();
                            } else {
                                this.toast(data.message || `Error al cargar ${this.getManagedDocumentLabel().toLowerCase()}`, 'error');
                                this.playSound('error');
                            }
                        } catch (e) {
                            console.error('Error loading sale for edit:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.loadingEditSearch = false;
                        }
                    },

                    async loadVentaForView(idFactura) {
                        try {
                            this.loadingEditSearch = true;

                            const res = await fetch(`api/get_venta.php?id=${idFactura}&id_empresa=${this.idEmpresa}`);
                            const data = await res.json();

                            const ventaData = data.venta || data.factura || null;
                            if (data.success && ventaData) {
                                console.log('📥 loadVentaForView - Datos recibidos:', ventaData);

                                // Determinar tipo_documento: si tiene CDC largo, es tipo 3 (electrónica)
                                const hasCDC = ventaData.cdc && String(ventaData.cdc).trim().length > 10;
                                let tipoDoc = parseInt(ventaData.tipo_documento) || 0;

                                // Si no tiene tipo_documento pero tiene CDC, es electrónica
                                if (tipoDoc === 0 && hasCDC) {
                                    tipoDoc = 3;
                                }
                                // Si no tiene tipo definido y no tiene CDC, es común
                                if (tipoDoc === 0) {
                                    tipoDoc = 1;
                                }

                                // Preparar lastVenta para el modal de impresión
                                this.lastVenta = {
                                    id_factura: ventaData.id_factura || ventaData.id,
                                    nro_factura: ventaData.nro_factura,
                                    fecha: ventaData.fecha,
                                    total: ventaData.total,
                                    cliente: ventaData.cliente || data.cliente?.nombre || null,
                                    cliente_ruc: ventaData.ruc || data.cliente?.ruc || null,
                                    cliente_email: null,
                                    cliente_telefono: null,
                                    cdc: ventaData.cdc || null,
                                    tipo_documento: tipoDoc
                                };

                                console.log('💾 lastVenta preparada:', this.lastVenta);
                                console.log('🔍 CDC válido?', hasCDC, 'Tipo documento:', tipoDoc);

                                // Determinar el script correcto para el ticket
                                const isElectro = hasCDC;
                                let script = isElectro ? 'kude.php' : 'ticket.php';

                                // Mostrar modal de impresión
                                this.ticketUrl = script + '?id=' + (ventaData.id_factura || ventaData.id || idFactura) + '&id_empresa=' + this.idEmpresa;
                                this.webPrintModalMode = false;
                                this.showTicketModal = true;

                                this.toast(`Venta #${ventaData.nro_factura} cargada`, 'success');
                            } else {
                                this.toast(data.message || 'Venta no encontrada', 'error');
                            }
                        } catch (e) {
                            console.error('Error loading sale for view:', e);
                            this.toast('Error al cargar la venta', 'error');
                        } finally {
                            this.loadingEditSearch = false;
                        }
                    },

                    async reprintVentaFromList(venta) {
                        try {
                            if (!venta || !venta.id_factura) {
                                this.toast('Venta no válida para reimpresión', 'error');
                                return;
                            }

                            const hasCDC = venta.cdc && String(venta.cdc).trim().length > 10;
                            const isFE = parseInt(venta.tipo_documento) === 3 || hasCDC;
                            const docType = isFE ? 'electro' : 'comun';

                            this.lastVenta = {
                                id_factura: venta.id_factura,
                                nro_factura: venta.nro_factura || ('#' + venta.id_factura),
                                fecha: venta.fecha || null,
                                total: venta.total || 0,
                                cdc: venta.cdc || null,
                                tipo_documento: isFE ? 3 : 0
                            };

                            await this.directPrint(venta.id_factura, docType, venta.nro_factura || '', { reprintOnly: true });
                        } catch (e) {
                            console.error('Error en reimpresión desde lista:', e);
                            this.toast('Error al reimprimir venta', 'error');
                        }
                    },

                    async updateVenta() {
                        if (!this.editingVenta) {
                            this.toast(`No hay ${this.getManagedDocumentLabel().toLowerCase()} en edición`, 'error');
                            return;
                        }

                        if (this.processingSale) return;
                        this.processingSale = true;
                        this.playSound('info');

                        try {
                            // Preparar pagos (mismo esquema que nueva venta)
                            const cashPayment = this.paymentsList.find(p => p.method === 'efectivo');
                            const mainPayment = this.paymentsList[0] || { method: 'efectivo' };
                            const paymentsPayload = (this.paymentsList && this.paymentsList.length > 0)
                                ? this.paymentsList
                                : [{ method: mainPayment.method || 'efectivo', amount: this.total }];

                            const response = await fetch(`${this.getEditApiEndpoint()}?action=update&id_empresa=${this.idEmpresa}`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    id_factura: this.editingVenta.id_factura,
                                    id_cliente: this.currentTicket.selectedCliente?.id || 0,
                                    items: this.cart.map(item => ({
                                        id: item.id,
                                        codigo: item.codigo,
                                        descripcion: item.descripcion,
                                        cantidad: item.cantidad,
                                        precio: item.precio,
                                        tasa_iva: this.normalizeIvaRate(item.tasa_iva)
                                    })),
                                    total: this.total,
                                    payment_method: mainPayment.method,
                                    payments: paymentsPayload,
                                    voucher_number: this.paymentsList.find(p => p.method === 'tarjeta')?.voucher_number || '',
                                    transfer_reference: this.paymentsList.find(p => p.method === 'transferencia')?.transfer_reference || '',
                                    qr_transaction_code: this.paymentsList.find(p => p.method === 'pix')?.qr_transaction_code || this.paymentsList.find(p => p.method === 'qr')?.qr_transaction_code || '',
                                    cash_received: cashPayment?.cash_received || 0,
                                    cash_change: cashPayment?.cash_change || 0
                                })
                            });

                            const result = await response.json();

                            if (result.success) {
                                const idFactura = result.data?.id_factura || this.editingVenta.id_factura;
                                const nroFactura = result.data?.nro_factura || this.editingVenta.nro_factura || '';
                                const tipoDoc = this.editingVenta?.tipo_documento ?? this.selectedDocType ?? 'comun';

                                this.lastVenta = {
                                    id_factura: idFactura,
                                    nro_factura: nroFactura,
                                    total: result.data?.total || this.total || 0,
                                    tipo_documento: tipoDoc
                                };

                                this.showPreviewModal = false;
                                this.showCheckoutModal = false;
                                this.closeTicketModal();

                                if (this.isPresupuestoMode() || this.isPedidoProveedorMode()) {
                                    this.lastVenta.fecha = result.data?.fecha || this.editingVenta?.fecha || null;
                                    this.lastVenta.cliente = result.data?.cliente || this.currentTicket.selectedCliente?.nombre || null;
                                    this.lastVenta.cliente_ruc = result.data?.cliente_ruc || this.currentTicket.selectedCliente?.ruc || null;
                                    this.lastVenta.items = Array.isArray(result.data?.items) ? result.data.items : [];
                                    this.showManagedDocumentActions();
                                } else {
                                    await this.directPrint(idFactura, tipoDoc, nroFactura, { reprintOnly: true });
                                }

                                this.clearCart();
                                this.toast(`${this.getManagedDocumentLabel()} actualizado correctamente`, 'success');
                                this.playSound('success');
                            } else {
                                this.toast(result.message || `Error al actualizar ${this.getManagedDocumentLabel().toLowerCase()}`, 'error');
                                this.playSound('error');
                            }
                        } catch (e) {
                            console.error('Error updating sale:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.processingSale = false;
                        }
                    },

                    cancelEditMode() {
                        // Verificar si vino por URL directa para edición
                        const urlParams = new URLSearchParams(window.location.search);
                        if (urlParams.get('id_venta') || urlParams.get('edit_id')) {
                            if (window.opener) {
                                window.close();
                            } else {
                                window.location.href = '../facturas_sifen.php';
                            }
                            return;
                        }

                        this.editingVenta = null;
                        this.clearCart();
                        this.toast('Edición cancelada', 'warning');
                    },


                    // Sugerencias y búsquedaltar coincidencias en texto
                    highlightMatch(text, query) {
                        if (!query || !text) return text;
                        const terms = query.replace(/[<>=]\s*\d+/, '').trim().split(/\s+/).filter(t => t.length > 1);
                        let result = text;
                        terms.forEach(term => {
                            const regex = new RegExp(`(${term})`, 'gi');
                            result = result.replace(regex, '<mark>$1</mark>');
                        });
                        return result;
                    },

                    sortProductsForDisplay(items = []) {
                        const list = Array.isArray(items) ? [...items] : [];
                        if (list.length <= 1) return list;
                        const cartIds = new Set(
                            (this.cart || [])
                                .map((item) => Number(item?.id || 0))
                                .filter((id) => id > 0)
                        );
                        return list.sort((a, b) => {
                            const aInCart = cartIds.has(Number(a?.id || 0)) ? 1 : 0;
                            const bInCart = cartIds.has(Number(b?.id || 0)) ? 1 : 0;
                            if (aInCart !== bInCart) return bInCart - aInCart;
                            const aDesc = String(a?.descripcion || '').trim();
                            const bDesc = String(b?.descripcion || '').trim();
                            return aDesc.localeCompare(bDesc, 'es-PY', { sensitivity: 'base', numeric: true });
                        });
                    },

                    // Carrito
                    async hydrateProductForCart(producto) {
                        if (!producto) return null;
                        if (Number(producto.detalle_cargado || 0) === 1) return producto;
                        const id = Number(producto.id || 0);
                        if (id <= 0) return producto;
                        try {
                            const params = new URLSearchParams({
                                action: 'by_id',
                                id: id,
                                id_empresa: this.idEmpresa,
                                tipo_precio: this.selectedPriceType
                            });
                            const res = await fetch(`api/productos.php?${params.toString()}`);
                            const data = await res.json();
                            return data?.producto || producto;
                        } catch (e) {
                            console.error('Error hidratando producto para carrito:', e);
                            return producto;
                        }
                    },

                    async addToCartFast(producto) {
                        const added = await this.addToCart(producto);
                        if (!added) return;
                        if (Number(producto?.detalle_cargado || 0) === 1) return;
                        this.hydrateCartProductInBackground(producto);
                    },

                    async hydrateCartProductInBackground(producto) {
                        const id = Number(producto?.id || 0);
                        if (id <= 0) return;
                        if (this._pendingCartHydrations[id]) return;

                        this._pendingCartHydrations[id] = true;
                        try {
                            const hydrated = await this.hydrateProductForCart(producto);
                            if (hydrated && Number(hydrated.id || 0) === id) {
                                this.applyHydratedProductToCart(hydrated);
                            }
                        } finally {
                            delete this._pendingCartHydrations[id];
                        }
                    },

                    applyHydratedProductToCart(producto) {
                        const id = Number(producto?.id || 0);
                        if (id <= 0 || !Array.isArray(this.cart) || this.cart.length === 0) return;
                        const normalizedIva = this.normalizeIvaRate(producto.tasa_iva);
                        let changed = false;

                        this.cart = this.cart.map((item) => {
                            if (Number(item?.id || 0) !== id) return item;
                            changed = true;
                            return {
                                ...item,
                                precio: parseFloat(producto.precio ?? item.precio ?? 0),
                                precio_min: parseFloat(producto.precio_min ?? item.precio_min ?? 0),
                                stock_inicial: parseFloat(producto.stock ?? item.stock_inicial ?? 0),
                                vende_sin_stock: parseInt(producto.vende_sin_stock ?? item.vende_sin_stock ?? 0),
                                controla_stock: parseInt(producto.controla_stock ?? item.controla_stock ?? 0),
                                usaserial: parseInt(producto.usaserial ?? item.usaserial ?? 0),
                                uses_serial: parseInt(producto.usaserial ?? item.usaserial ?? 0) === 1,
                                tasa_iva: normalizedIva,
                                imagen: producto.imagen || item.imagen || '',
                                imagen_updated: producto.imagen_updated || item.imagen_updated || '',
                                editablePrecio: parseInt(producto.edita_precio ?? 0) === 1,
                                editableDescripcion: parseInt(producto.editable ?? 0) === 1
                            };
                        });

                        if (changed) {
                            this.recalculate();
                        }
                    },

                    ensurePixabayForPopularProducts() {
                        const list = Array.isArray(this.popularProducts) ? this.popularProducts.slice(0, 24) : [];
                        list.forEach((producto, index) => {
                            setTimeout(() => {
                                this.ensurePixabayImagePersisted(producto);
                            }, index * 120);
                        });
                    },

                    async ensurePixabayImagePersisted(producto) {
                        const idProducto = Number(producto?.idproducto || producto?.id || 0);
                        const descripcion = String(producto?.descripcion || '').trim();
                        const imagenActual = String(producto?.imagen || producto?.imagen_url || '').trim();
                        if (!idProducto || !descripcion) return;
                        if (this.autoPixabayPersisting[idProducto]) return;

                        const needsPixabay =
                            imagenActual === '' ||
                            imagenActual.includes('/public/pos/api/imagen_proxy.php') ||
                            imagenActual.includes('/pos/api/imagen_proxy.php');

                        if (!needsPixabay) return;

                        this.autoPixabayPersisting[idProducto] = true;
                        try {
                            const searchUrl = `/public/pos/api/buscar_imagenes.php?q=${encodeURIComponent(descripcion)}&page=1&_t=${Date.now()}`;
                            const searchRes = await fetch(searchUrl, { cache: 'no-store' });
                            const searchData = await searchRes.json();
                            const firstImage = Array.isArray(searchData?.images) ? (searchData.images[0] || null) : null;
                            const imageUrl = String(firstImage?.large || firstImage?.preview || firstImage?.thumbnail || '').trim();
                            if (!imageUrl) return;

                            const saveRes = await fetch('/public/pos/api/imagen_proxy.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action: 'upload_url',
                                    idproducto: idProducto,
                                    id_empresa: Number(this.idEmpresa),
                                    image_url: imageUrl
                                })
                            });
                            const saveData = await saveRes.json().catch(() => ({}));
                            if (!saveRes.ok || !saveData?.success) {
                                const msg = String(saveData?.error || '');
                                if (
                                    saveRes.status === 400 ||
                                    msg.includes('Máximo') ||
                                    msg.includes('URL de imagen inválida') ||
                                    msg.includes('Tipo de archivo no permitido') ||
                                    msg.includes('excede el tamaño máximo')
                                ) {
                                    return;
                                }
                                return;
                            }

                            syncProductImageFromCache(idProducto, saveData.cache_url || '', this);
                        } catch (e) {
                            console.error('Error autopersistiendo imagen Pixabay:', e);
                        } finally {
                            delete this.autoPixabayPersisting[idProducto];
                        }
                    },

                    createCartLineUid() {
                        return `cart-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
                    },

                    async fetchAvailableSeries(productId) {
                        const params = new URLSearchParams({
                            action: 'series',
                            id: String(productId),
                            id_empresa: String(this.idEmpresa)
                        });
                        const response = await fetch(`api/productos.php?${params.toString()}`, { cache: 'no-store' });
                        const result = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            throw new Error(result?.error || `No se pudo consultar seriales (HTTP ${response.status})`);
                        }
                        return Array.isArray(result?.series) ? result.series : [];
                    },

                    async openSerialPicker(producto) {
                        this.serialPickerProduct = producto || null;
                        this.serialPickerSeries = [];
                        this.serialPickerError = '';
                        this.serialPickerLoading = true;
                        this.showSerialPickerModal = true;
                        try {
                            const rows = await this.fetchAvailableSeries(Number(producto?.id || 0));
                            this.serialPickerSeries = rows;
                            if (!rows.length) {
                                this.serialPickerError = 'No hay seriales/IMEI disponibles para este producto.';
                            }
                        } catch (error) {
                            this.serialPickerError = error?.message || 'No se pudieron cargar los seriales.';
                        } finally {
                            this.serialPickerLoading = false;
                        }
                    },

                    closeSerialPicker() {
                        this.showSerialPickerModal = false;
                        this.serialPickerLoading = false;
                        this.serialPickerError = '';
                        this.serialPickerSeries = [];
                        this.serialPickerProduct = null;
                    },

                    buildCartItem(producto, selectedSerial = null) {
                        const editablePrecio = parseInt(producto.edita_precio) === 1;
                        const editableDescripcion = parseInt(producto.editable) === 1;
                        const usesSerial = parseInt(producto.usaserial || 0) === 1;
                        return {
                            uid: this.createCartLineUid(),
                            id: producto.id,
                            codigo: producto.codigo,
                            descripcion: producto.descripcion,
                            precio: parseFloat(producto.precio),
                            precio_min: parseFloat(producto.precio_min || 0),
                            stock_inicial: parseFloat(producto.stock || 0),
                            vende_sin_stock: parseInt(producto.vende_sin_stock || 0),
                            controla_stock: parseInt(producto.controla_stock || 0),
                            cantidad: 1,
                            tasa_iva: this.normalizeIvaRate(producto.tasa_iva),
                            imagen: producto.imagen || '',
                            imagen_updated: producto.imagen_updated || '',
                            editablePrecio,
                            editableDescripcion,
                            usaserial: parseInt(producto.usaserial || 0),
                            uses_serial: usesSerial,
                            serial_id: selectedSerial ? Number(selectedSerial.id || 0) : 0,
                            serial_code: selectedSerial ? String(selectedSerial.serie || '') : '',
                            serial_type: selectedSerial ? String(selectedSerial.tipo || 'SERIAL') : ''
                        };
                    },

                    async selectSerialForProduct(serial) {
                        const producto = this.serialPickerProduct;
                        if (!producto || !serial) return false;
                        this.cart.push(this.buildCartItem(producto, serial));
                        this.closeSerialPicker();
                        this.searchQuery = '';
                        this.productos = [];
                        this.recalculate();
                        if (Number(producto?.detalle_cargado || 0) !== 1) {
                            this.hydrateCartProductInBackground(producto);
                        }
                        this.$nextTick(() => this.$refs.searchInput?.focus());
                        return true;
                    },

                    async addToCart(producto) {
                        if ((typeof producto?.usaserial === 'undefined' || producto?.usaserial === null) && Number(producto?.detalle_cargado || 0) !== 1) {
                            producto = await this.hydrateProductForCart(producto);
                        }
                        const usesSerial = parseInt(producto?.usaserial || 0) === 1;
                        if (usesSerial) {
                            await this.openSerialPicker(producto);
                            if (this.serialPickerLoading || this.serialPickerError) {
                                if (this.serialPickerError) {
                                    this.toast(this.serialPickerError, 'error');
                                    this.playSound('error');
                                }
                                return false;
                            }
                            return false;
                        }

                        const existing = this.cart.find(item => item.id === producto.id && !item.uses_serial);
                        if (existing) {
                            if (!this.skipStockAndDeleteGuards() && parseInt(existing.controla_stock) !== -1 && parseInt(existing.vende_sin_stock) === 0) {
                                const available = parseFloat(existing.stock_inicial || 0);
                                if (existing.cantidad + 1 > available) {
                                    this.toast(`Stock insuficiente. Disponible: ${available}`, 'error');
                                    this.playSound('error');
                                    return false;
                                }
                            }
                            existing.cantidad++;
                        } else {
                            if (!this.skipStockAndDeleteGuards() && parseInt(producto.controla_stock) !== -1 && parseInt(producto.vende_sin_stock) === 0) {
                                const available = parseFloat(producto.stock || 0);
                                if (available <= 0) {
                                    this.toast('Sin stock disponible.', 'error');
                                    this.playSound('error');
                                    return false;
                                }
                            }
                            this.cart.push(this.buildCartItem(producto));
                        }
                        // Limpiar búsqueda
                        this.searchQuery = '';
                        this.productos = [];
                        this.$refs.searchInput.focus();
                        return true;
                    },

                    updateQty(index, delta) {
                        const item = this.cart[index];
                        if (item?.uses_serial) {
                            this.toast('Los productos con serial/IMEI se agregan una vez por línea.', 'info');
                            return;
                        }
                        const newQty = item.cantidad + delta;
                        const minQty = item.esBalanza ? 0.001 : 1;

                        if (newQty < minQty) return;

                        // Validación de stock: si controla_stock != -1 y vende_sin_stock == 0
                        if (!this.skipStockAndDeleteGuards() && delta > 0 && parseInt(item.controla_stock) !== -1 && parseInt(item.vende_sin_stock) === 0) {
                            const available = parseFloat(item.stock_inicial || 0);
                            if (newQty > available) {
                                this.toast(`Stock insuficiente. Disponible: ${available}`, 'error');
                                this.playSound('error');
                                return;
                            }
                        }

                        item.cantidad = newQty;
                        this.clearItemDeleteAuthorization(index);
                        this.recalculate();
                    },

                    updateQtyManual(index, value) {
                        const item = this.cart[index];
                        if (item?.uses_serial) {
                            item.cantidad = 1;
                            this.recalculate();
                            return;
                        }
                        const newQty = parseFloat(value);
                        const minQty = item.esBalanza ? 0.001 : 1; // Permitir 3 decimales en balanza

                        if (isNaN(newQty) || newQty < minQty) {
                            item.cantidad = minQty;
                            this.recalculate();
                            return;
                        }

                        // Validación de stock: si controla_stock != -1 y vende_sin_stock == 0
                        if (!this.skipStockAndDeleteGuards() && parseInt(item.controla_stock) !== -1 && parseInt(item.vende_sin_stock) === 0) {
                            const available = parseFloat(item.stock_inicial || 0);
                            if (newQty > available) {
                                this.toast(`Stock insuficiente. Disponible: ${available}`, 'error');
                                this.playSound('error');
                                // Revertir al valor anterior o al máximo disponible
                                item.cantidad = Math.max(minQty, Math.min(item.cantidad, available));
                                this.$nextTick(() => this.recalculate());
                                return;
                            }
                        }

                        item.cantidad = newQty;
                        this.clearItemDeleteAuthorization(index);
                        this.recalculate();
                    },

                    updatePrice(index, value) {
                        const precio = parseFloat(value);
                        const item = this.cart[index];
                        if (!item) return;
                        const minPrice = parseFloat(item.precio_min || 0);
                        if (!Number.isNaN(precio) && precio >= 0) {
                            if (minPrice > 0 && precio < minPrice) {
                                this.toast(`Monto no autorizado. El precio mínimo es ${this.formatMoney(minPrice)}`, 'error');
                                this.requestPriceOverride(item, precio, minPrice, 'EDIT_LINE').then((auth) => {
                                    if (auth?.approved) {
                                        this.cart[index].precio = precio;
                                        this.clearItemDeleteAuthorization(index);
                                        this.toast(`Precio autorizado y actualizado: ${this.formatMoney(precio)}`, 'success');
                                        this.recalculate();
                                    }
                                });
                                this.recalculate();
                                return;
                            }
                            this.cart[index].precio = precio;
                            this.clearItemDeleteAuthorization(index);
                            this.recalculate();
                        }
                    },

                    async requestPriceOverride(item, precioIntentado, precioMinimo, origen = 'POS_WEB') {
                        try {
                            const res = await fetch('api/venta.php?action=request_price_override', {
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
                            const data = await res.json();
                            if (data?.success) {
                                this.toast(data?.message || 'Solicitud enviada a administrador', 'warning');
                                return data;
                            }
                        } catch (e) {
                            // no bloquear UI por error de red en la notificación
                        }
                        return null;
                    },

                    async ensureCartPriceAuthorizations(origen = 'CHECKOUT_WEB') {
                        for (const item of (this.cart || [])) {
                            const minPrice = parseFloat(item?.precio_min || 0);
                            const currentPrice = parseFloat(item?.precio || 0);
                            if (minPrice > 0 && currentPrice < minPrice) {
                                this.toast(`Monto no autorizado. El precio mínimo es ${this.formatMoney(minPrice)}`, 'error');
                                const auth = await this.requestPriceOverride(item, currentPrice, minPrice, origen);
                                if (auth?.approved) {
                                    this.toast(`Precio autorizado: ${this.formatMoney(currentPrice)}`, 'success');
                                    continue;
                                }
                                return false;
                            }
                        }
                        return true;
                    },

                    updateDescripcion(index, value) {
                        this.cart[index].descripcion = value;
                        this.clearItemDeleteAuthorization(index);
                        this.recalculate();
                    },

                    clearItemDeleteAuthorization(index) {
                        const item = this.cart[index];
                        if (!item || (!item.delete_authorized && !item.delete_request_pending)) return;
                        this.cart[index] = {
                            ...item,
                            delete_authorized: false,
                            delete_authorized_by: '',
                            delete_request_pending: false
                        };
                    },

                    async pollDeleteApprovalStatuses() {
                        if (this._deleteApprovalPollBusy) return;
                        const idProductos = [];
                        for (const item of (this.cart || [])) {
                            if (!item?.delete_request_pending || item?.delete_authorized) continue;
                            const idProducto = Number(item?.id || 0);
                            if (idProducto > 0 && !idProductos.includes(idProducto)) {
                                idProductos.push(idProducto);
                            }
                        }
                        if (!idProductos.length) return;

                        this._deleteApprovalPollBusy = true;
                        try {
                            const res = await fetch('api/venta.php?action=request_item_delete_status', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    id_empresa: this.idEmpresa,
                                    id_usuario: this.idUsuario,
                                    id_productos: idProductos
                                })
                            });
                            const data = await res.json();
                            if (!data?.success || !Array.isArray(data?.items)) return;

                            const states = new Map();
                            for (const row of data.items) {
                                states.set(Number(row?.id_producto || 0), row || {});
                            }

                            let changed = false;
                            this.cart = this.cart.map((item) => {
                                if (!item?.delete_request_pending || item?.delete_authorized) return item;
                                const state = states.get(Number(item?.id || 0));
                                if (!state) return item;
                                const estado = String(state?.estado || '').toUpperCase();
                                if (estado === 'APROBADO') {
                                    changed = true;
                                    return {
                                        ...item,
                                        delete_request_pending: false,
                                        delete_authorized: true,
                                        delete_authorized_by: String(state?.approved_by_login || 'admin')
                                    };
                                }
                                if (estado === 'RECHAZADO') {
                                    changed = true;
                                    return {
                                        ...item,
                                        delete_request_pending: false,
                                        delete_authorized: false,
                                        delete_authorized_by: ''
                                    };
                                }
                                return item;
                            });
                            if (changed) {
                                this.recalculate();
                            }
                        } catch (e) {
                        } finally {
                            this._deleteApprovalPollBusy = false;
                        }
                    },

                    async removeFromCart(index) {
                        const item = this.cart[index];
                        if (!item) return;
                        if (this.skipStockAndDeleteGuards()) {
                            this.cart.splice(index, 1);
                            this.recalculate();
                            this.toast('Ítem eliminado', 'success');
                            this.playSound('warning');
                            return;
                        }
                        if (item.delete_request_pending && !item.delete_authorized) return;
                        if (item.delete_authorized) {
                            this.cart.splice(index, 1);
                            this.recalculate();
                            this.toast('Ítem eliminado', 'success');
                            this.playSound('warning');
                            return;
                        }
                        const auth = await this.requestItemDeleteApproval(item, 'DELETE_WEB');
                        if (auth?.approved) {
                            this.cart[index] = {
                                ...this.cart[index],
                                delete_request_pending: false,
                                delete_authorized: true,
                                delete_authorized_by: String(auth?.approved_by_login || 'admin')
                            };
                            this.recalculate();
                        } else if (auth?.pending_approval) {
                            this.cart[index] = {
                                ...this.cart[index],
                                delete_request_pending: true,
                                delete_authorized: false,
                                delete_authorized_by: ''
                            };
                            this.recalculate();
                        }
                        this.playSound('warning');
                    },

                    async requestItemDeleteApproval(item, origen = 'POS_WEB') {
                        try {
                            const res = await fetch('api/venta.php?action=request_item_delete', {
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
                            const data = await res.json();
                            if (data?.success) {
                                return data;
                            } else {
                                this.toast(data?.message || data?.error || 'No se pudo enviar solicitud', 'error');
                            }
                        } catch (e) {
                            this.toast('Error de conexión al solicitar autorización', 'error');
                        }
                        return null;
                    },

                    recalculate() {
                        // Trigger reactivity
                        this.cart = [...this.cart];
                    },

                    // Clientes
                    shouldLookupSifen(query) {
                        const q = String(query || '').trim();
                        // Permitir CI/RUC con o sin DV (el backend normaliza y consulta sin DV).
                        return /^[0-9.-]{5,20}$/.test(q);
                    },

                    toggleVoiceSearch(target = 'products') {
                        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                        if (!SpeechRecognition) {
                            this.showToast?.('🎤 Tu navegador no soporta búsqueda por voz', 'warning') || alert('Tu navegador no soporta búsqueda por voz');
                            return;
                        }
                        const isCliente = target === 'clientes';
                        const listeningProp = isCliente ? 'voiceListeningCliente' : 'voiceListening';
                        const recProp = isCliente ? '_voiceRecognitionCliente' : '_voiceRecognition';

                        // Si ya está escuchando, detener
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
                                    this.currentTicket.clienteSearch = transcript;
                                    this.clienteSelectedIdx = 0;
                                    this.searchClientes(false);
                                } else {
                                    this.searchQuery = transcript;
                                    this.searchProducts();
                                    this.$refs.searchInput?.focus();
                                }
                            }
                        };
                        recognition.onerror = (event) => {
                            if (event.error !== 'aborted' && event.error !== 'no-speech') {
                                this.showToast?.('🎤 Error de voz: ' + event.error, 'error');
                            }
                        };
                        recognition.onend = () => {
                            this[listeningProp] = false;
                            this[recProp] = null;
                        };
                        recognition.start();
                    },

                    async searchClientes(checkSifen = false) {
                        const query = this.clienteSearch.trim();
                        if (query.length < 2) {
                            this.clientesResults = [];
                            return;
                        }

                        this.loadingClientes = true;
                        try {
                            const res = await fetch(`api/clientes.php?action=search&q=${encodeURIComponent(query)}&id_empresa=${this.idEmpresa}`);
                            const data = await res.json();
                            this.clientesResults = data.clientes || [];

                            // Solo buscar en SET si: se presionó Enter, no hay resultados locales y parece RUC (con DV)
                            if (checkSifen && this.clientesResults.length === 0 && this.shouldLookupSifen(query)) {
                                const queryKey = query.replace(/\s+/g, '');
                                if (this.sifenLookupInFlight && this.lastSifenLookupQuery === queryKey) {
                                    return;
                                }
                                this.sifenLookupInFlight = true;
                                this.lastSifenLookupQuery = queryKey;

                                // Mostrar modal informando que se buscará en SET
                                this.showInfoModal({
                                    title: 'Cliente no encontrado',
                                    message: 'El cliente no existe en el sistema local.<br><br>Buscando en <strong>SET (SIFEN)</strong>...',
                                    icon: '🔍',
                                    loading: true,
                                    type: 'loading'
                                });

                                try {
                                    const ctrl = new AbortController();
                                    const timeoutId = setTimeout(() => ctrl.abort(), 10000);
                                    const resSifen = await fetch(`api/clientes.php?action=sifen_lookup&ruc=${encodeURIComponent(query)}&id_empresa=${this.idEmpresa}`, {
                                        signal: ctrl.signal
                                    });
                                    clearTimeout(timeoutId);

                                    if (!resSifen.ok) {
                                        throw new Error(`HTTP ${resSifen.status}: ${resSifen.statusText}`);
                                    }

                                    const responseText = await resSifen.text();
                                    console.log('SIFEN Response:', responseText);

                                    let dataSifen;
                                    try {
                                        dataSifen = JSON.parse(responseText);
                                    } catch (parseError) {
                                        console.error('JSON Parse Error:', parseError);
                                        console.error('Response Text:', responseText);
                                        throw new Error('Respuesta inválida del servidor SIFEN');
                                    }

                                    if (dataSifen.cliente) {
                                        const cliente = dataSifen.cliente;

                                        // Mostrar modal de éxito con datos del cliente
                                        this.showInfoModal({
                                            title: '✅ Cliente encontrado',
                                            message: `<strong>${cliente.nombre}</strong><br>` +
                                                `RUC/CI: ${cliente.ruc || cliente.cedula || query}<br>` +
                                                (cliente.direccion ? `Dirección: ${cliente.direccion}<br>` : '') +
                                                `<br><span class="text-green-500 font-medium">Cliente insertado correctamente en el sistema.</span>`,
                                            icon: '👤',
                                            type: 'success',
                                            loading: false
                                        });

                                        // Auto-seleccionar el cliente encontrado
                                        this.selectedCliente = cliente;
                                        this.clienteSearch = cliente.nombre;
                                        this.showClienteDropdown = false;
                                        this.clientesResults = [];
                                        this.saveTickets();

                                        this.playSound('success');

                                        // Enfocar en COBRAR si hay items en carrito (después de cerrar modal)
                                        if (this.cart.length > 0) {
                                            setTimeout(() => {
                                                if (!this.infoModal.show) {
                                                    this.openCheckout();
                                                }
                                            }, 2000);
                                        }
                                    } else if (dataSifen.error) {
                                        // Mostrar error del SET
                                        this.showInfoModal({
                                            title: 'Error en SET',
                                            message: dataSifen.error,
                                            icon: '⚠️',
                                            type: 'warning',
                                            loading: false
                                        });
                                    } else {
                                        // RUC no encontrado en SET
                                        this.showInfoModal({
                                            title: 'No encontrado',
                                            message: `El RUC/CI <strong>${query}</strong> no fue encontrado en el registro del SET (SIFEN).<br><br>Verifique que el número sea correcto.`,
                                            icon: '❌',
                                            type: 'error',
                                            loading: false
                                        });
                                    }
                                } catch (sifenError) {
                                    console.error('SIFEN Lookup Error:', sifenError);
                                    this.closeInfoModal();
                                    this.toast('SET (SIFEN) no disponible ahora. Podés continuar con carga manual.', 'warning');
                                } finally {
                                    this.sifenLookupInFlight = false;
                                }
                            }
                        } catch (e) {
                            console.error('Error searching clients:', e);
                            this.toast('Error en búsqueda de clientes', 'error');
                        } finally {
                            this.loadingClientes = false;
                        }
                    },

                    getLastVentaPaymentMethod() {
                        return String(
                            this.lastVenta?.medio_cobro
                            || this.lastVenta?.payment_method
                            || (this.paymentsList && this.paymentsList[0] ? this.paymentsList[0].method : '')
                            || this.simplePaymentMethod
                            || ''
                        );
                    },

                    async printTicket() {
                        if (!this.lastVenta?.id_factura) {
                            return;
                        }
                        this.beginPrintFallbackGuard(6000);

                        const idFactura = this.lastVenta.id_factura;
                        const tipoDoc = this.lastVenta.tipo_documento ?? this.selectedDocType ?? 'comun';
                        const nroFactura = this.lastVenta?.nro_factura || '';
                        const paymentMethod = this.getLastVentaPaymentMethod();

                        if (!this.directPrintEnabled || !this.canUseLocalAgent()) {
                            this.openWebPrintTicket(idFactura, tipoDoc, nroFactura, paymentMethod);
                            return;
                        }

                        try {
                            if (!this.qzConnected) {
                                const alive = await this.probeLocalAgentHealth();
                                if (!alive) {
                                    this.openWebPrintTicket(idFactura, tipoDoc, nroFactura, paymentMethod);
                                    return;
                                }
                            }

                            await this.directPrint(idFactura, tipoDoc, nroFactura, { paymentMethod });
                        } catch (e) {
                            console.warn('Fallback a impresión web para lastVenta:', e);
                            this.openWebPrintTicket(idFactura, tipoDoc, nroFactura, paymentMethod);
                        }
                    },

                    async salir() {
                        // Si estamos editando, limpiar el POS completamente antes de salir
                        if (this.editingVenta) {
                            this.clearCart();
                            // Al limpiar, se guarda estado vacío y se sale del modo edición
                        }

                        // Verificar si hay tickets con items
                        const ticketsConItems = this.tickets.filter(t => t.cart.length > 0);
                        if (ticketsConItems.length > 0) {
                            const confirmed = await this.showConfirm({
                                title: 'Salir del POS',
                                message: `Tienes ${ticketsConItems.length} ticket(s) con productos.\n¿Salir de todos modos?\nLos datos se guardarán automáticamente.`,
                                icon: '🚪',
                                type: 'warning',
                                confirmText: 'Salir'
                            });
                            if (!confirmed) return;
                        }
                        // Guardar antes de salir
                        this.saveTickets();

                        // Intentar cerrar usando la función del padre
                        try {
                            if (typeof parent.cerrarApp === 'function') {
                                parent.cerrarApp();
                                return;
                            }

                            // Fallback: Forzar recarga del menú principal
                            if (window.top && window.top.location) {
                                window.top.location.href = '../menu/menu.php';
                            } else {
                                window.location.href = '../menu/menu.php';
                            }
                        } catch (e) {
                            console.error("Error al salir:", e);
                            window.location.href = '../menu/menu.php';
                        }
                    },

                    isLastVentaEligibleForSifen() {
                        if (!this.lastVenta || !this.lastVenta.fecha || !this.lastVenta.id_factura) {
                            console.log('isLastVentaEligibleForSifen: No hay venta válida', this.lastVenta);
                            return false;
                        }

                        // Comparar solo la fecha, sin importar la hora
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);

                        const ventaFecha = new Date(this.lastVenta.fecha.replace(' ', 'T'));
                        ventaFecha.setHours(0, 0, 0, 0);

                        const isToday = (today.getTime() === ventaFecha.getTime());
                        const tipoDoc = parseInt(this.lastVenta.tipo_documento) || 0;

                        // Verificar si tiene CDC (considerar null, undefined, string vacío o string con espacios)
                        const hasCDC = this.lastVenta.cdc && this.lastVenta.cdc.trim().length > 0;

                        // Mostrar botón si:
                        // 1. No es electrónica (tipo 0=nota, 1=autoimpresa, 2=otro) 
                        // 2. O es electrónica pero falló (tipo 3 pero sin CDC válido)
                        const isNotElectronic = (tipoDoc !== 3);
                        const failedElectronic = (tipoDoc === 3 && !hasCDC);

                        const canEmitFE = isToday && (isNotElectronic || failedElectronic);

                        console.log('🔍 isLastVentaEligibleForSifen:', {
                            fecha_venta: this.lastVenta.fecha,
                            today_formatted: today.toISOString().split('T')[0],
                            venta_formatted: ventaFecha.toISOString().split('T')[0],
                            isToday,
                            tipoDoc,
                            isNotElectronic,
                            failedElectronic,
                            cdc: this.lastVenta.cdc,
                            hasCDC,
                            '✅ PUEDE_EMITIR_FE': canEmitFE
                        });

                        return canEmitFE;
                    },

                    /**
                     * Emite a SIFEN y espera el resultado (para uso en flujo de venta).
                     * A diferencia de convertirSifen(), este retorna Promise y no muestra modales.
                     * @param {number} idFactura - ID de la factura a emitir
                     * @returns {Promise} - Resuelve con el CDC o rechaza con error
                     */
                    async emitirSifenYEsperar(idFactura, options = {}) {
                        const formData = new FormData();
                        formData.append('id_factura', idFactura);
                        formData.append('id_empresa', this.idEmpresa);
                        formData.append('convert', '1'); // Convertir a electrónica
                        if (options && options.fastPrint) {
                            formData.append('fast_print', '1');
                        }

                        const res = await fetch('api/sifen_retry.php', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await res.json();
                        if (data.success && data.cdc) {
                            // Actualizar lastVenta con el CDC
                            if (this.lastVenta && this.lastVenta.id_factura == idFactura) {
                                this.lastVenta.cdc = data.cdc;
                                this.lastVenta.tipo_documento = 3;
                            }
                            console.log('⚡ SIFEN OK - CDC:', data.cdc);
                            return {
                                cdc: data.cdc,
                                estado: data.estado || '',
                                message: data.message || data.mensaje || '',
                                prot_cons_lote_sifen: data.prot_cons_lote_sifen || ''
                            };
                        } else {
                            const msg = String(data?.message || data?.mensaje || 'Error al emitir FE');
                            const prot = String(data?.prot_cons_lote_sifen || '').trim();
                            if (this.isSifenPendiente(msg) || prot !== '') {
                                throw new Error(msg || 'Lote recibido en SIFEN. Pendiente de confirmación final.');
                            }
                            throw new Error(data.message || 'Error al emitir FE');
                        }
                    },

                    async enqueueSifenEmision(idFactura, accion = 'emitir') {
                        const formData = new FormData();
                        formData.append('id_factura', idFactura);
                        formData.append('id_empresa', this.idEmpresa);
                        formData.append('queue_action', 'enqueue');
                        formData.append('accion', accion === 'consultar' ? 'consultar' : 'emitir');
                        formData.append('prioridad', '3');

                        const res = await fetch('api/sifen_retry.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();
                        if (!data.success) {
                            throw new Error(data.message || 'No se pudo encolar FE');
                        }
                        return data;
                    },

                    isSifenPendiente(message) {
                        const m = String(message || '').toLowerCase();
                        return (
                            m.includes('aún no confirmó la aprobación') ||
                            m.includes('aun no confirmo la aprobacion') ||
                            m.includes('pendiente') ||
                            m.includes('lote recibido con éxito') ||
                            m.includes('lote recibido con exito') ||
                            m.includes('lote recibido') ||
                            m.includes('procesamiento de lote') ||
                            m.includes('concluido') ||
                            m.includes('duplicado') ||
                            m.includes('documento electrónico duplicado') ||
                            m.includes('documento electronico duplicado')
                        );
                    },

                    async rollbackVentaElectronica(idFactura) {
                        const res = await fetch('api/venta.php?action=rollback_fe', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                id_factura: idFactura,
                                id_empresa: this.idEmpresa,
                                id_usuario: this.idUsuario
                            })
                        });

                        const data = await res.json();
                        if (!data.success) {
                            throw new Error(data.message || 'No se pudo revertir la venta electrónica');
                        }
                        return true;
                    },

                    async convertirSifen() {
                        if (this.loadingSifen || !this.lastVenta?.id_factura) return;

                        this.loadingSifen = true;
                        try {
                            const formData = new FormData();
                            formData.append('id_factura', this.lastVenta.id_factura);
                            formData.append('id_empresa', this.idEmpresa);
                            // Si no era electrónica, pasar flag para habilitarla
                            if (parseInt(this.lastVenta.tipo_documento) !== 3) {
                                formData.append('convert', '1');
                            }

                            const res = await fetch('api/sifen_retry.php', {
                                method: 'POST',
                                body: formData
                            });

                            const data = await res.json();
                            if (data.success) {
                                this.toast('Factura Electrónica emitida con éxito', 'success');
                                this.lastVenta.cdc = data.cdc;
                                this.lastVenta.tipo_documento = 3;
                                // Actualizar URL del ticket
                                let script = 'kude.php';
                                this.ticketUrl = `${script}?id=${this.lastVenta.id_factura}&id_empresa=${this.idEmpresa}&autoprint=1`;
                            } else {
                                // Mostrar error en modal centrado
                                await this.showConfirm({
                                    title: '❌ Error al Emitir FE',
                                    message: data.message || 'Error desconocido al procesar la factura electrónica',
                                    icon: '⚠️',
                                    type: 'error',
                                    confirmText: 'Cerrar',
                                    showCancel: false
                                });
                            }
                        } catch (e) {
                            console.error('Error converting to SIFEN:', e);
                            // Mostrar error de conexión en modal centrado
                            await this.showConfirm({
                                title: '❌ Error de Conexión',
                                message: 'No se pudo conectar con el servidor SIFEN. Por favor, verifique su conexión e intente nuevamente.',
                                icon: '🔌',
                                type: 'error',
                                confirmText: 'Cerrar',
                                showCancel: false
                            });
                        } finally {
                            this.loadingSifen = false;
                        }
                    },

                    async enviarEmail() {
                        if (!this.lastVenta || !this.lastVenta.id_factura) {
                            this.toast('No hay venta activa', 'error');
                            return;
                        }

                        if (!this.emailInput || !this.emailInput.includes('@')) {
                            this.toast('Email inválido', 'error');
                            return;
                        }

                        this.sendingEmail = true;
                        try {
                            // Intentar obtener el HTML del iframe del ticket
                            let ticketHtml = '';
                            try {
                                // Buscar cualquier iframe que contenga un documento de venta (.php)
                                const iframe = document.querySelector('iframe[src*=".php"]');
                                if (iframe && iframe.contentDocument) {
                                    ticketHtml = iframe.contentDocument.body.innerHTML;
                                }
                            } catch (e) {
                                console.warn('No se pudo obtener el HTML del iframe:', e);
                            }

                            const clienteNombre = this.lastVenta.cliente || this.lastVenta.cliente_nombre || 'Cliente';
                            const res = await fetch('../../modelos/kude_email.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    email: this.emailInput,
                                    id_factura: this.lastVenta.id_factura,
                                    cdc: this.lastVenta.cdc || '',
                                    nro_factura: this.lastVenta.nro_factura,
                                    cliente: clienteNombre,
                                    total: this.formatMoney(this.lastVenta.total),
                                    html_content: ticketHtml
                                })
                            });
                            const data = await res.json();
                            if (data.success) {
                                this.toast('Email enviado correctamente', 'success');
                                this.showEmailModal = false;
                            } else {
                                this.toast('Error: ' + (data.error || 'No se pudo enviar'), 'error');
                            }
                        } catch (e) {
                            console.error('Error enviando email:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.sendingEmail = false;
                        }
                    },

                    // Preparar modal de WhatsApp con datos del cliente
                    prepareWhatsApp() {
                        // Intentar obtener el teléfono de múltiples fuentes
                        let phone = this.lastVenta?.cliente_telefono ||
                            this.currentTicket?.selectedCliente?.telefono ||
                            '';

                        console.log('📱 prepareWhatsApp - Teléfono detectado:', {
                            lastVenta_telefono: this.lastVenta?.cliente_telefono,
                            currentTicket_telefono: this.currentTicket?.selectedCliente?.telefono,
                            selectedCliente_completo: this.currentTicket?.selectedCliente,
                            phone_final: phone
                        });

                        // Limpiar el teléfono de caracteres no numéricos
                        phone = String(phone || '').replace(/\D/g, '');

                        if (phone) {
                            // Intentar detectar el código de país del número
                            let foundCountry = null;

                            // Ordenar países por longitud de código (más largo primero)
                            const sortedCountries = [...this.countries].sort((a, b) => b.code.length - a.code.length);

                            for (const country of sortedCountries) {
                                if (phone.startsWith(country.code)) {
                                    foundCountry = country;
                                    // Remover el código de país del número
                                    phone = phone.substring(country.code.length);
                                    break;
                                }
                            }

                            // Si encontramos un país, actualizar el selector
                            if (foundCountry) {
                                this.selectedCountryCode = foundCountry.code;
                                console.log(`📱 Código de país detectado del teléfono: ${foundCountry.flag} ${foundCountry.name} (+${foundCountry.code})`);
                            }

                            // Si el número empieza con 0, quitarlo (formato local)
                            if (phone.startsWith('0')) {
                                phone = phone.substring(1);
                            }

                            this.phoneInput = phone;
                        } else {
                            this.phoneInput = '';
                        }

                        this.showWhatsAppModal = true;
                    },

                    enviarWhatsApp() {
                        if (!this.lastVenta || !this.lastVenta.id_factura) {
                            this.toast('No hay venta activa', 'error');
                            return;
                        }

                        let phone = this.phoneInput.replace(/\D/g, '');
                        if (phone.startsWith('0')) phone = phone.substring(1);
                        if (phone.length < 6) {
                            this.toast('Número de teléfono inválido', 'error');
                            return;
                        }

                        const countryCode = this.selectedCountryCode;
                        const full = countryCode + phone;

                        if (this.isPresupuestoMode()) {
                            const msgPresupuesto = this.buildPresupuestoWhatsAppMessage();
                            window.open(`https://wa.me/${full}?text=${encodeURIComponent(msgPresupuesto)}`, '_blank');
                            this.showWhatsAppModal = false;
                            this.toast('Abriendo WhatsApp con el presupuesto...', 'success');
                            return;
                        }

                        if (this.isPedidoProveedorMode()) {
                            const msgPedido = this.buildPedidoWhatsAppMessage();
                            window.open(`https://wa.me/${full}?text=${encodeURIComponent(msgPedido)}`, '_blank');
                            this.showWhatsAppModal = false;
                            this.toast('Abriendo WhatsApp con el pedido...', 'success');
                            return;
                        }

                        const isElectro = (this.lastVenta.cdc && this.lastVenta.cdc.length > 10);
                        const verificationLink = isElectro ?
                            `https://ekuatia.set.gov.py/consultas?cdc=${this.lastVenta.cdc}` :
                            `https://sistemax.com.py/verificar_nota.php?id=${this.lastVenta.id_factura}&id_empresa=${this.idEmpresa}`;

                        const title = isElectro ? '*FACTURA ELECTRÓNICA*' : '*NOTA DE COMPRA*';
                        const clienteNombre = this.lastVenta.cliente || this.lastVenta.cliente_nombre || 'Cliente';
                        const msg = `${title}\n\nNro: ${this.lastVenta.nro_factura}\nCliente: ${clienteNombre}\nTotal: *${this.formatMoney(this.lastVenta.total)} Gs*\n\n🔗 *Verificar:* ${verificationLink}\n\n_SistemaX_`;

                        window.open(`https://wa.me/${full}?text=${encodeURIComponent(msg)}`, '_blank');
                        this.showWhatsAppModal = false;
                        this.toast('Abriendo WhatsApp...', 'success');
                    },

                    newSale() {
                        this.showSuccessModal = false;
                        this.cart = [];
                        this.selectedCliente = null;
                        this.clienteSearch = '';
                        this.lastVenta = null;
                        this.$refs.searchInput.focus();
                    },

                    // ===== MODAL DETALLE PRODUCTO =====

                    normalizeCartItemProduct(item) {
                        const source = item || {};
                        const id = Number(source.id || source.idproducto || 0);
                        if (!id) return null;
                        return {
                            id,
                            idproducto: id,
                            codigo: String(source.codigo || source.cve_producto || ''),
                            descripcion: String(source.descripcion || source.desproducto || 'Producto'),
                            precio: Number(source.precio || source.precio_venta || 0),
                            stock: Number(source.stock || source.saldo || 0),
                            tasa_iva: Number(source.tasa_iva || source.iva || 0),
                            controla_stock: Number(source.controla_stock ?? 1),
                            vende_sin_stock: Number(source.vende_sin_stock ?? 0),
                            precio_min: Number(source.precio_min || 0),
                            edita_precio: Number(source.edita_precio || 0),
                            editable: Number(source.editable || 0),
                            usaserial: Number(source.usaserial || source.uses_serial || 0),
                            imagen: String(source.imagen || ''),
                            imagen_updated: String(source.imagen_updated || '')
                        };
                    },

                    previewCartItem(item) {
                        const producto = this.normalizeCartItemProduct(item);
                        if (!producto?.id) return;
                        this.schedulePreviewForProduct(producto, null, 60);
                    },

                    openCartItemDetail(item) {
                        const producto = this.normalizeCartItemProduct(item);
                        if (!producto?.id) return;
                        this.openProductDetail(producto);
                    },

                    async openProductDetail(producto) {
                        if (!producto || !producto.id) return;

                        const productId = String(producto.id);
                        const cachedDetail = this._productDetailCache[productId]
                            || this._searchPreviewDetailCache[productId]
                            || this.buildPlaceholderProductDetail(producto)
                            || null;
                        const hasUsableCachedDetail = !!(
                            cachedDetail
                            && cachedDetail.success
                            && cachedDetail.scope
                            && cachedDetail.scope !== 'placeholder'
                        );

                        this.showProductDetailModal = true;
                        this.loadingDetail = false;
                        this.loadingHeavyDetail = false;
                        this.detailContentReady = false;
                        this.detailImageError = false;
                        this.selectedDetailImage = null;
                        this.productDetail = cachedDetail;
                        this.currentProductId = producto.id;

                        if (cachedDetail && cachedDetail.producto) {
                            this.selectedDetailImage = this.getDetailGalleryImages()[0] || null;
                        }

                        requestAnimationFrame(() => {
                            if (String(this.currentProductId || '') !== productId) return;
                            this.detailContentReady = true;
                        });

                        this.loadingHeavyDetail = !cachedDetail || cachedDetail.heavy_loaded !== true;
                        setTimeout(async () => {
                            if (String(this.currentProductId || '') !== productId) return;
                            try {
                                let baseDetail = cachedDetail;
                                if (!hasUsableCachedDetail) {
                                    const liteData = await this.fetchProductDetail(producto.id, 'lite', { cache: 'no-store' });
                                    if (String(this.currentProductId || '') !== productId) return;
                                    if (liteData && liteData.success) {
                                        baseDetail = this.mergeProductDetail(cachedDetail, liteData);
                                        this.productDetail = baseDetail;
                                        this._productDetailCache[productId] = baseDetail;
                                        this._searchPreviewDetailCache[productId] = baseDetail;
                                        this.selectedDetailImage = this.getDetailGalleryImages()[0] || null;
                                    }
                                }

                                const data = await this.fetchProductDetail(producto.id, 'full', { cache: 'no-store' });
                                if (String(this.currentProductId || '') !== productId) return;
                                if (data.success) {
                                    this.productDetail = this.mergeProductDetail(baseDetail, data);
                                    this._productDetailCache[productId] = this.productDetail;
                                    this._searchPreviewDetailCache[productId] = this.productDetail;
                                    this.selectedDetailImage = this.getDetailGalleryImages()[0] || null;
                                } else {
                                    console.error('Error loading product detail:', data.error);
                                }
                            } catch (e) {
                                console.error('Error fetching product detail:', e);
                            } finally {
                                if (String(this.currentProductId || '') === productId) {
                                    this.loadingHeavyDetail = false;
                                }
                            }
                        }, 0);
                    },

                    withImageVersion(url, version = '') {
                        const baseUrl = String(url || '').trim();
                        if (!baseUrl) return '';
                        const normalized = baseUrl.startsWith('/_lib') ? '/public' + baseUrl : baseUrl;
                        const sep = normalized.includes('?') ? '&' : '?';
                        return `${normalized}${sep}v=${encodeURIComponent(version || '')}`;
                    },

                    getDetailGalleryImages() {
                        const producto = this.productDetail?.producto || {};
                        const list = Array.isArray(producto.imagenes) ? producto.imagenes : [];
                        if (list.length > 0) return list;
                        const fallbackUrl = producto.imagen || producto.imagen_url || '';
                        return fallbackUrl ? [{ url: fallbackUrl, thumb_url: fallbackUrl, principal: 1 }] : [];
                    },

                    getDetailImageProduct() {
                        const producto = this.productDetail?.producto || {};
                        const selected = this.selectedDetailImage || this.getDetailGalleryImages()[0] || null;
                        return {
                            id: producto.idproducto || producto.id || this.currentProductId,
                            descripcion: producto.descripcion || 'producto',
                            imagen: selected?.url || producto.imagen || producto.imagen_url || '',
                            imagen_updated: producto.imagen_updated || '',
                            imagenes: this.getDetailGalleryImages().map((img) => img?.url || '').filter(Boolean)
                        };
                    },

                    getDetailPrimaryImageUrl() {
                        return String(this.getDetailImageProduct()?.imagen || '').trim();
                    },

                    selectDetailImage(image) {
                        this.selectedDetailImage = image || null;
                    },

                    isSelectedDetailImage(image) {
                        const current = this.selectedDetailImage || this.getDetailGalleryImages()[0] || null;
                        if (!current || !image) return false;
                        return String(current.url || current.thumb_url || '') === String(image.url || image.thumb_url || '');
                    },

                    async deleteDetailImage(image) {
                        const producto = this.productDetail?.producto || null;
                        const idProducto = producto?.idproducto || producto?.id || this.currentProductId || 0;
                        const fileId = String(image?.file_id || '').trim();
                        if (!idProducto || !fileId || this.deletingDetailImage) return;

                        const ok = window.confirm('¿Suprimir esta imagen del producto?');
                        if (!ok) return;

                        this.deletingDetailImage = true;
                        try {
                            const response = await fetch('/public/productos/api/imagen.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action: 'delete',
                                    file_id: fileId,
                                    idproducto: idProducto,
                                    id_empresa: Number(this.idEmpresa || 0)
                                })
                            });
                            const result = await response.json().catch(() => ({}));
                            if (!result?.success) {
                                throw new Error(result?.error || `No se pudo suprimir la imagen${response.ok ? '' : ` (HTTP ${response.status})`}`);
                            }

                            await syncProductImagesFromServer(idProducto, Number(this.idEmpresa), this);

                            if (this.currentProductId) {
                                const res = await fetch(`api/producto_detalle.php?id=${idProducto}&id_empresa=${this.idEmpresa}`, { cache: 'no-store' });
                                const data = await res.json();
                                if (data?.success) {
                                    this.productDetail = data;
                                    this.selectedDetailImage = this.getDetailGalleryImages()[0] || null;
                                }
                            }

                            this.toast('Imagen suprimida', 'success');
                        } catch (error) {
                            console.error('Error deleting product image:', error);
                            this.toast(error?.message || 'No se pudo suprimir la imagen', 'error');
                        } finally {
                            this.deletingDetailImage = false;
                        }
                    },

                    getActiveKardexProduct() {
                        return this.productDetail?.producto
                            || this.searchPreviewDetail?.producto
                            || this.lastFocusedPreviewDetail?.producto
                            || null;
                    },

                    getActiveKardexSucursales() {
                        const fromDetail = Array.isArray(this.productDetail?.stock_por_sucursal) ? this.productDetail.stock_por_sucursal : [];
                        if (fromDetail.length) return fromDetail;
                        const fromPreview = Array.isArray(this.searchPreviewDetail?.stock_por_sucursal) ? this.searchPreviewDetail.stock_por_sucursal : [];
                        if (fromPreview.length) return fromPreview;
                        const fromLastPreview = Array.isArray(this.lastFocusedPreviewDetail?.stock_por_sucursal) ? this.lastFocusedPreviewDetail.stock_por_sucursal : [];
                        return fromLastPreview;
                    },

                    async loadKardexData({ idProducto, idSucursal = 0 } = {}) {
                        if (!idProducto) return;
                        this.loadingKardex = true;
                        try {
                            const params = new URLSearchParams({
                                id: String(idProducto),
                                id_empresa: String(this.idEmpresa)
                            });
                            if (Number(idSucursal) > 0) {
                                params.set('id_sucursal', String(idSucursal));
                            }
                            const res = await fetch(`api/producto_kardex.php?${params.toString()}`, { cache: 'no-store' });
                            const data = await res.json();
                            if (!data?.success) {
                                throw new Error(data?.error || 'No se pudo cargar el kardex');
                            }
                            this.kardexProducto = data.producto || this.kardexProducto;
                            this.kardexSucursal = data.sucursal || null;
                            this.kardexMovimientos = Array.isArray(data.movimientos) ? data.movimientos : [];
                            this.kardexStockActual = Number(data.stock_actual || 0);
                            const apiSucursales = Array.isArray(data.sucursales) ? data.sucursales : [];
                            if (apiSucursales.length) {
                                this.kardexSucursales = apiSucursales;
                            } else if (!this.kardexSucursales.length) {
                                this.kardexSucursales = this.getActiveKardexSucursales();
                            }
                            if (Number(idSucursal) > 0) {
                                this.kardexSelectedSucursalId = Number(idSucursal);
                            }
                        } catch (e) {
                            console.error('Error loading kardex:', e);
                            this.kardexMovimientos = [];
                            this.toast(e?.message || 'No se pudo cargar el kardex', 'error');
                        } finally {
                            this.loadingKardex = false;
                        }
                    },

                    async switchKardexTab(tab) {
                        if (!this.kardexProducto?.idproducto && !this.kardexProducto?.id) return;
                        this.kardexTab = tab === 'global' ? 'global' : 'sucursales';
                        if (this.kardexTab === 'global') {
                            this.kardexSucursal = null;
                            await this.loadKardexData({
                                idProducto: this.kardexProducto?.idproducto || this.kardexProducto?.id,
                                idSucursal: 0
                            });
                            return;
                        }

                        const selected = this.kardexSucursales.find(s => Number(s.id_sucursal) === Number(this.kardexSelectedSucursalId))
                            || this.kardexSucursales[0]
                            || null;
                        if (selected) {
                            await this.selectKardexSucursal(selected);
                        } else {
                            this.kardexMovimientos = [];
                        }
                    },

                    async selectKardexSucursal(sucursal) {
                        if (!sucursal) return;
                        this.kardexSelectedSucursalId = Number(sucursal.id_sucursal || 0);
                        this.kardexSucursal = sucursal;
                        this.kardexTab = 'sucursales';
                        await this.loadKardexData({
                            idProducto: this.kardexProducto?.idproducto || this.kardexProducto?.id,
                            idSucursal: this.kardexSelectedSucursalId
                        });
                    },

                    async openKardexModal(sucursal = null) {
                        const producto = this.getActiveKardexProduct();
                        const idProducto = producto?.idproducto || producto?.id || 0;
                        if (!idProducto) return;

                        this.kardexProducto = producto;
                        this.kardexSucursales = this.getActiveKardexSucursales();
                        this.kardexSelectedSucursalId = Number(sucursal?.id_sucursal || this.kardexSucursales[0]?.id_sucursal || 0);
                        this.kardexTab = this.kardexSelectedSucursalId > 0 ? 'sucursales' : 'global';
                        this.kardexMovimientos = [];
                        this.kardexStockActual = 0;
                        this.kardexSucursal = sucursal || this.kardexSucursales.find(s => Number(s.id_sucursal) === this.kardexSelectedSucursalId) || null;
                        this.showKardexModal = true;

                        await this.loadKardexData({
                            idProducto,
                            idSucursal: this.kardexTab === 'sucursales' ? this.kardexSelectedSucursalId : 0
                        });
                    },

                    async addToCartFromDetail() {
                        if (!this.productDetail?.producto) return;

                        const p = this.productDetail.producto;
                        const precio = this.productDetail.precios?.[0]?.precio || p.precio_venta;

                        const producto = {
                            id: p.idproducto,
                            codigo: p.codigo,
                            descripcion: p.descripcion,
                            precio: parseFloat(precio),
                            stock: p.stock_global,
                            tasa_iva: this.normalizeIvaRate(p.tasa_iva),
                            controla_stock: p.controla_stock,
                            vende_sin_stock: p.vende_sin_stock,
                            precio_min: p.costo || 0,
                            imagen: p.imagen || p.imagen_url || '',
                            imagen_updated: p.imagen_updated || '',
                            edita_precio: p.edita_precio || 0,
                            editable: p.editable || 0,
                            usaserial: (typeof p.usaserial === 'undefined' ? 0 : p.usaserial),
                            detalle_cargado: 1
                        };

                        const added = await this.addToCart(producto);
                        if (added) {
                            this.showProductDetailModal = false;
                        }
                    },

                    async toggleProductDiscontinuedFromDetail(event) {
                        const checked = !!event?.target?.checked;
                        if (!checked) {
                            if (event?.target) event.target.checked = Number(this.productDetail?.producto?.descontinuado || 0) === 1;
                            return;
                        }

                        const producto = this.productDetail?.producto || null;
                        const idProducto = Number(producto?.idproducto || producto?.id || 0);
                        if (!idProducto) {
                            if (event?.target) event.target.checked = false;
                            return;
                        }

                        const confirmed = await this.showConfirm({
                            title: 'Descontinuar producto',
                            message: `¿Descontinuar "${producto?.descripcion || 'este producto'}"?`,
                            icon: '📦',
                            type: 'danger',
                            confirmText: 'Descontinuar'
                        });

                        if (!confirmed) {
                            if (event?.target) event.target.checked = false;
                            return;
                        }

                        this.discontinuingProduct = true;
                        try {
                            const res = await fetch('/public/productos/api/eliminar.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action: 'soft_delete',
                                    idproducto: idProducto,
                                    id_empresa: this.idEmpresa
                                })
                            });
                            const data = await res.json();
                            if (!data?.success) {
                                throw new Error(data?.error || 'No se pudo descontinuar el producto');
                            }

                            if (this.productDetail?.producto) {
                                this.productDetail.producto.descontinuado = 1;
                                this.productDetail.producto.Estado = 0;
                            }
                            this.toast(data.message || 'Producto descontinuado', 'success');
                            this.showProductDetailModal = false;
                            this.productDetail = null;
                            this.currentProductId = null;
                            this.searchProducts();
                            this.loadPopularProducts();
                        } catch (e) {
                            if (event?.target) event.target.checked = false;
                            this.toast(e?.message || 'No se pudo descontinuar el producto', 'error');
                        } finally {
                            this.discontinuingProduct = false;
                        }
                    },

                    // Regenerar imagen del producto (preferencia Pixabay)
                    async regenerarImagen(idProducto, descripcion) {
                        if (!idProducto) return;

                        const btn = document.getElementById('btn-regenerar-img');
                        if (btn) {
                            btn.disabled = true;
                            btn.innerHTML = '🔄 Buscando...';
                        }

                        try {
                            const url = `/public/pos/api/imagen_proxy.php?id=${idProducto}&q=${encodeURIComponent(descripcion || 'producto')}&prefer=pixabay&refresh=1`;
                            const response = await fetch(url);

                            if (response.ok) {
                                // Actualizar todas las imágenes del producto en la página
                                const imgs = document.querySelectorAll(`img[data-product-id="${idProducto}"]`);
                                const newSrc = url + '&t=' + Date.now(); // Cache buster
                                imgs.forEach(img => {
                                    img.src = newSrc;
                                    img.style.display = 'block';
                                });
                                POSAudio.play('success');
                                if (btn) btn.innerHTML = '✅ Actualizada';
                            } else {
                                POSAudio.play('error');
                                if (btn) btn.innerHTML = '❌ Error';
                            }
                        } catch (e) {
                            console.error('Error regenerando imagen:', e);
                            POSAudio.play('error');
                            if (btn) btn.innerHTML = '❌ Error';
                        }

                        setTimeout(() => {
                            if (btn) {
                                btn.disabled = false;
                                btn.innerHTML = '🔄 Regenerar Imagen';
                            }
                        }, 2000);
                    },

                    // ===== MODAL BUSCAR IMAGEN =====

                    openImageSearchModal(producto) {
                        if (!producto || !producto.id) return;
                        this.imageSearchProduct = producto;
                        this.imageManagerImages = [];
                        this.imageManagerBusyFileId = '';
                        this.supplierSearchUrl = '';
                        this.imageUrlInput = '';
                        this.imageBase64 = '';
                        this.searchResults = [];
                        this.searchTranslated = '';
                        this.searchTotal = 0;
                        this.searchPage = 1;
                        this.showImageSearchModal = true;
                        this.loadImageManagerImages();
                    },

                    closeImageSearchModal() {
                        this.showImageSearchModal = false;
                        this.imageSearchProduct = null;
                        this.imageManagerImages = [];
                        this.imageManagerBusyFileId = '';
                        this.imageManagerLoading = false;
                        this.supplierSearchUrl = '';
                        this.imageUrlInput = '';
                        this.imageBase64 = '';
                        this.searchResults = [];
                        this.searchTranslated = '';
                        this.searchTotal = 0;
                        this.searchPage = 1;
                    },

                    async loadImageManagerImages() {
                        if (!this.imageSearchProduct?.id) return;
                        this.imageManagerLoading = true;
                        try {
                            const res = await fetch(`/public/productos/api/imagen.php?action=list&idproducto=${encodeURIComponent(this.imageSearchProduct.id)}&id_empresa=${encodeURIComponent(this.idEmpresa)}`, { cache: 'no-store' });
                            const data = await res.json().catch(() => ({}));
                            this.imageManagerImages = Array.isArray(data?.data) ? data.data : [];
                        } catch (e) {
                            console.error('Error loading product images manager:', e);
                            this.imageManagerImages = [];
                        } finally {
                            this.imageManagerLoading = false;
                        }
                    },

                    normalizeSupplierSearchUrl(rawUrl) {
                        let value = String(rawUrl || '').trim();
                        if (!value) return '';
                        if (!/^https?:\/\//i.test(value)) {
                            value = 'https://' + value.replace(/^\/+/, '');
                        }
                        try {
                            return new URL(value).toString();
                        } catch (e) {
                            return '';
                        }
                    },

                    openSupplierSearch() {
                        const baseUrl = this.normalizeSupplierSearchUrl(this.supplierSearchUrl);
                        const query = String(this.imageSearchProduct?.descripcion || '').trim();
                        if (!baseUrl || !query) {
                            this.toast('Ingrese una URL de proveedor válida', 'warning');
                            return;
                        }

                        let targetUrl = '';
                        try {
                            const url = new URL(baseUrl);
                            const hasPlaceholder = url.href.includes('{q}');
                            if (hasPlaceholder) {
                                targetUrl = url.href.replaceAll('{q}', encodeURIComponent(query));
                            } else if (url.searchParams.has('q')) {
                                url.searchParams.set('q', query);
                                targetUrl = url.toString();
                            } else if (url.searchParams.has('search')) {
                                url.searchParams.set('search', query);
                                targetUrl = url.toString();
                            } else {
                                const siteSearch = `site:${url.hostname} ${query}`;
                                targetUrl = `https://www.google.com/search?q=${encodeURIComponent(siteSearch)}`;
                            }
                        } catch (e) {
                            this.toast('No se pudo preparar la búsqueda del proveedor', 'error');
                            return;
                        }

                        window.open(targetUrl, '_blank', 'noopener,noreferrer');
                    },

                    defaultPreviewEquivForm() {
                        return {
                            idproducto: null,
                            cve_producto: '',
                            desproducto: '',
                            referencia: 0,
                            grupo: 0,
                            marca: 0,
                            modelo: 0,
                            color: 0,
                            unidad_medida: '',
                            equivalencia: '',
                            iva: '1',
                            impuesto: 10,
                            codigo_barra: '',
                            precio_compra: 0,
                            precio_venta: 0,
                            stock_minimo: 0,
                            stock_maximo: 0,
                            saldo_actual: 0,
                            stock_inicial: 0,
                            id_sucursal: '',
                            controla_stock: -1,
                            edita_precio: 1,
                            vende_sin_stock: -1,
                            usaserial: -1,
                            descripcion_larga: '',
                            ncm: '',
                            origen: '',
                            peso: 0,
                            ancho: 0,
                            alto: 0,
                            largo: 0,
                            foto_url: '',
                            catalogo_url: '',
                            obs: '',
                            publicar_web: 0,
                            aplicaciones_modo: 'agrupado',
                            precios: [],
                            codigos_barra_extra: [],
                            equivalentes: [],
                            aplicaciones: [],
                        };
                    },

                    previewEquivGetCatalogField(tabla) {
                        const map = {
                            marcas_cod_conversion: 'nombre',
                            marcas_aplicacion: 'nombre',
                            anios_aplicacion: 'nombre',
                            modelos_aplicacion: 'nombre',
                            motores_aplicacion: 'nombre',
                            codigos_motor_aplicacion: 'nombre'
                        };
                        return map[tabla] || 'nombre';
                    },

                    previewEquivNormalizeCatalogItems(tabla, items) {
                        const field = this.previewEquivGetCatalogField(tabla);
                        return (Array.isArray(items) ? items : []).map((it, idx) => {
                            const id = it?.id ?? it?.ID ?? it?.Id ?? idx + 1;
                            const nombre = it?.[field] ?? it?.nombre ?? '';
                            return { ...it, id, nombre, [field]: nombre };
                        });
                    },

                    previewEquivApplyCatalogBootstrap(payload) {
                        const data = payload && typeof payload === 'object' ? payload : {};
                        this.previewEquivEditor.catMarcasCodConversion = this.previewEquivNormalizeCatalogItems('marcas_cod_conversion', data.marcas_cod_conversion || []);
                        this.previewEquivEditor.catMarcasAplicacion = this.previewEquivNormalizeCatalogItems('marcas_aplicacion', data.marcas_aplicacion || []);
                        this.previewEquivEditor.catAniosAplicacion = this.previewEquivNormalizeCatalogItems('anios_aplicacion', data.anios_aplicacion || []);
                        this.previewEquivEditor.catModelosAplicacion = this.previewEquivNormalizeCatalogItems('modelos_aplicacion', data.modelos_aplicacion || []);
                        this.previewEquivEditor.catMotoresAplicacion = this.previewEquivNormalizeCatalogItems('motores_aplicacion', data.motores_aplicacion || []);
                        this.previewEquivEditor.catCodigosMotorAplicacion = this.previewEquivNormalizeCatalogItems('codigos_motor_aplicacion', data.codigos_motor_aplicacion || []);
                    },

                    previewEquivGetCatalogArray(tabla) {
                        const map = {
                            marcas_cod_conversion: this.previewEquivEditor.catMarcasCodConversion,
                            marcas_aplicacion: this.previewEquivEditor.catMarcasAplicacion,
                            anios_aplicacion: this.previewEquivEditor.catAniosAplicacion,
                            modelos_aplicacion: this.previewEquivEditor.catModelosAplicacion,
                            motores_aplicacion: this.previewEquivEditor.catMotoresAplicacion,
                            codigos_motor_aplicacion: this.previewEquivEditor.catCodigosMotorAplicacion,
                        };
                        return Array.isArray(map[tabla]) ? map[tabla] : [];
                    },

                    previewEquivSetCatalogArray(tabla, rows) {
                        const data = Array.isArray(rows) ? rows : [];
                        if (tabla === 'marcas_cod_conversion') this.previewEquivEditor.catMarcasCodConversion = data;
                        else if (tabla === 'marcas_aplicacion') this.previewEquivEditor.catMarcasAplicacion = data;
                        else if (tabla === 'anios_aplicacion') this.previewEquivEditor.catAniosAplicacion = data;
                        else if (tabla === 'modelos_aplicacion') this.previewEquivEditor.catModelosAplicacion = data;
                        else if (tabla === 'motores_aplicacion') this.previewEquivEditor.catMotoresAplicacion = data;
                        else if (tabla === 'codigos_motor_aplicacion') this.previewEquivEditor.catCodigosMotorAplicacion = data;
                    },

                    async loadPreviewEquivCatalogos() {
                        const res = await fetch(`/public/productos/api/catalogos.php?action=bootstrap&id_empresa=${encodeURIComponent(this.idEmpresa)}`, { cache: 'no-store' });
                        const data = await res.json();
                        if (data?.success) {
                            this.previewEquivApplyCatalogBootstrap(data.data || {});
                        }
                    },

                    previewEquivNormalizeEquivalencias(rows) {
                        return (Array.isArray(rows) ? rows : [])
                            .map((row, idx) => ({
                                marca_cod_conversion: String(row?.marca_cod_conversion || '').trim(),
                                conversion: String(row?.conversion || '').trim(),
                                orden: idx + 1,
                            }))
                            .filter((row) => row.marca_cod_conversion !== '' || row.conversion !== '');
                    },

                    previewEquivNormalizeAplicaciones(rows) {
                        return (Array.isArray(rows) ? rows : [])
                            .map((row, idx) => ({
                                marca_aplicacion: String(row?.marca_aplicacion || '').trim(),
                                vehiculo_marca: String(row?.vehiculo_marca || '').trim(),
                                vehiculo_modelo: String(row?.vehiculo_modelo || '').trim(),
                                anio: String(row?.anio || '').trim(),
                                motor: String(row?.motor || '').trim(),
                                codigo_motor: String(row?.codigo_motor || '').trim(),
                                orden: idx + 1,
                            }))
                            .filter((row) =>
                                row.marca_aplicacion !== '' ||
                                row.vehiculo_marca !== '' ||
                                row.vehiculo_modelo !== '' ||
                                row.anio !== '' ||
                                row.motor !== '' ||
                                row.codigo_motor !== ''
                            );
                    },

                    previewEquivAplicacionTieneDetalle(row) {
                        if (row?.__is_app_group) return false;
                        if (row?.__is_new_app) return true;
                        return [row?.vehiculo_marca, row?.vehiculo_modelo, row?.anio, row?.motor, row?.codigo_motor]
                            .some((value) => String(value || '').trim() !== '');
                    },

                    previewEquivNewAplicacionGroupToken() {
                        return `peq_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
                    },

                    previewEquivEquivalenciasAgrupadas() {
                        const groups = new Map();
                        (Array.isArray(this.previewEquivEditor.form?.equivalentes) ? this.previewEquivEditor.form.equivalentes : []).forEach((row, idx) => {
                            const marcaCod = String(row?.marca_cod_conversion || '').trim();
                            const key = marcaCod || `eq-${idx}`;
                            if (!groups.has(key)) {
                                groups.set(key, { key, marca_cod_conversion: marcaCod, indexes: [], conversionRows: [] });
                            }
                            const group = groups.get(key);
                            group.indexes.push(idx);
                            group.conversionRows.push({ ...row, _idx: idx });
                        });
                        return Array.from(groups.values());
                    },

                    previewEquivAplicacionesAgrupadas() {
                        const groups = new Map();
                        (Array.isArray(this.previewEquivEditor.form?.aplicaciones) ? this.previewEquivEditor.form.aplicaciones : []).forEach((row, idx) => {
                            const marcaAplic = String(row?.marca_aplicacion || row?.vehiculo_marca || '').trim();
                            const groupToken = String(row?.__group_token || '').trim();
                            const key = `${marcaAplic}||${groupToken || idx}`;
                            if (!groups.has(key)) {
                                groups.set(key, {
                                    key,
                                    group_token: groupToken,
                                    marca_aplicacion: marcaAplic,
                                    indexes: [],
                                    appRows: [],
                                    hasAppGroupHeader: false,
                                    showAppGroup: false,
                                });
                            }
                            const group = groups.get(key);
                            group.indexes.push(idx);
                            if (row?.__is_app_group) {
                                group.hasAppGroupHeader = true;
                            } else if (this.previewEquivAplicacionTieneDetalle(row)) {
                                group.appRows.push({ ...row, _idx: idx });
                            }
                        });
                        groups.forEach((group) => {
                            const resumen = group.appRows.map((row) => [
                                String(row.vehiculo_marca || '').trim(),
                                String(row.vehiculo_modelo || '').trim(),
                                String(row.anio || '').trim(),
                                String(row.motor || '').trim(),
                                String(row.codigo_motor || '').trim(),
                            ].filter(Boolean).join(' / ')).filter(Boolean);
                            group.detalle_resumen = resumen.join(' | ');
                            group.showAppGroup = group.hasAppGroupHeader || group.appRows.length > 0;
                        });
                        return Array.from(groups.values());
                    },

                    previewEquivGetAplicacionGroupRows(group) {
                        const indexes = Array.isArray(group?.indexes) ? group.indexes : [];
                        return indexes
                            .map((idx) => Number(idx))
                            .filter((idx) => Number.isInteger(idx) && idx >= 0 && idx < this.previewEquivEditor.form.aplicaciones.length)
                            .map((idx) => ({ ...(this.previewEquivEditor.form.aplicaciones[idx] || {}), _idx: idx }))
                            .filter((row) => !row?.__is_app_group && this.previewEquivAplicacionTieneDetalle(row));
                    },

                    previewEquivSetEquivalenciaGroupField(group, field, value) {
                        const val = String(value || '');
                        (Array.isArray(group?.indexes) ? group.indexes : []).forEach((idx) => {
                            if (this.previewEquivEditor.form.equivalentes[idx]) {
                                this.previewEquivEditor.form.equivalentes[idx][field] = val;
                            }
                        });
                    },

                    previewEquivSetAplicacionGroupField(group, field, value) {
                        const val = String(value || '');
                        (Array.isArray(group?.indexes) ? group.indexes : []).forEach((idx) => {
                            if (this.previewEquivEditor.form.aplicaciones[idx]) {
                                this.previewEquivEditor.form.aplicaciones[idx][field] = val;
                                if (field === 'marca_aplicacion' && !String(this.previewEquivEditor.form.aplicaciones[idx].vehiculo_marca || '').trim()) {
                                    this.previewEquivEditor.form.aplicaciones[idx].vehiculo_marca = val;
                                }
                            }
                        });
                    },

                    previewEquivAgregarCodigoConversion() {
                        this.previewEquivEditor.form.equivalentes.push({ marca_cod_conversion: '', conversion: '' });
                    },

                    previewEquivAgregarCodigoConversionEnGrupo(group) {
                        this.previewEquivEditor.form.equivalentes.push({
                            marca_cod_conversion: String(group?.marca_cod_conversion || '').trim(),
                            conversion: '',
                        });
                    },

                    previewEquivEliminarEquivalenciaFila(idx) {
                        const pos = Number(idx);
                        if (!Number.isInteger(pos) || pos < 0 || pos >= this.previewEquivEditor.form.equivalentes.length) return;
                        this.previewEquivEditor.form.equivalentes.splice(pos, 1);
                    },

                    previewEquivEliminarEquivalenciaGrupo(group) {
                        const indexes = Array.isArray(group?.indexes) ? group.indexes.map((idx) => Number(idx)).filter((idx) => Number.isInteger(idx) && idx >= 0 && idx < this.previewEquivEditor.form.equivalentes.length).sort((a, b) => b - a) : [];
                        indexes.forEach((idx) => this.previewEquivEditor.form.equivalentes.splice(idx, 1));
                    },

                    previewEquivAgregarAplicacion() {
                        const groupToken = this.previewEquivNewAplicacionGroupToken();
                        this.previewEquivEditor.form.aplicaciones.push({
                            conversion: '',
                            marca_aplicacion: '',
                            vehiculo_marca: '',
                            vehiculo_modelo: '',
                            anio: '',
                            motor: '',
                            codigo_motor: '',
                            __is_app_group: true,
                            __group_token: groupToken,
                        });
                        this.previewEquivEditor.form.aplicaciones.push({
                            marca_aplicacion: '',
                            vehiculo_marca: '',
                            vehiculo_modelo: '',
                            anio: '',
                            motor: '',
                            codigo_motor: '',
                            __is_new_app: true,
                            __is_app_group: false,
                            __group_token: groupToken,
                        });
                    },

                    previewEquivAgregarAplicacionEnGrupo(group) {
                        const insertAt = Array.isArray(group?.indexes) && group.indexes.length > 0
                            ? (Math.max(...group.indexes.map((idx) => Number(idx)).filter((idx) => Number.isInteger(idx))) + 1)
                            : this.previewEquivEditor.form.aplicaciones.length;
                        this.previewEquivEditor.form.aplicaciones.splice(insertAt, 0, {
                            marca_aplicacion: String(group?.marca_aplicacion || '').trim(),
                            vehiculo_marca: '',
                            vehiculo_modelo: '',
                            anio: '',
                            motor: '',
                            codigo_motor: '',
                            __is_new_app: true,
                            __is_app_group: false,
                            __group_token: String(group?.group_token || ''),
                        });
                    },

                    previewEquivEliminarAplicacionFila(idx) {
                        const pos = Number(idx);
                        if (!Number.isInteger(pos) || pos < 0 || pos >= this.previewEquivEditor.form.aplicaciones.length) return;
                        this.previewEquivEditor.form.aplicaciones.splice(pos, 1);
                    },

                    previewEquivEliminarAplicacionGrupo(group) {
                        const indexes = Array.isArray(group?.indexes) ? group.indexes.map((idx) => Number(idx)).filter((idx) => Number.isInteger(idx) && idx >= 0 && idx < this.previewEquivEditor.form.aplicaciones.length).sort((a, b) => b - a) : [];
                        indexes.forEach((idx) => this.previewEquivEditor.form.aplicaciones.splice(idx, 1));
                    },

                    async previewEquivEnsureCatalogValue(tabla, value) {
                        const nombre = String(value || '').trim();
                        if (!nombre) return;
                        const current = this.previewEquivGetCatalogArray(tabla);
                        const exists = current.some((item) => String(item?.nombre || '').trim().toLowerCase() === nombre.toLowerCase());
                        if (exists) return;
                        try {
                            const res = await fetch('/public/productos/api/catalogos.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'create', tabla, nombre, id_empresa: this.idEmpresa })
                            });
                            const data = await res.json();
                            if (data?.success) {
                                this.previewEquivSetCatalogArray(tabla, this.previewEquivNormalizeCatalogItems(tabla, data.data || []));
                            }
                        } catch (_) {}
                    },

                    async openPreviewEquivalentesEditor() {
                        const productId = Number(this.searchPreviewDetail?.producto?.idproducto || this.searchPreviewDetail?.producto?.id || 0);
                        this.showPreviewEquivalentesModal = true;
                        this.previewEquivEditor.error = '';
                        if (!productId) {
                            this.previewEquivEditor.error = 'Producto invalido';
                            return;
                        }
                        if (Number(this.previewEquivEditor.loadedProductId || 0) === productId && this.previewEquivEditor.form?.idproducto) {
                            return;
                        }
                        this.previewEquivEditor.loading = true;
                        try {
                            await this.loadPreviewEquivCatalogos();
                            await this.loadPreviewEquivEditorProduct(productId);
                        } catch (e) {
                            this.previewEquivEditor.error = e?.message || 'No se pudo cargar el editor';
                        } finally {
                            this.previewEquivEditor.loading = false;
                        }
                    },

                    async loadPreviewEquivEditorProduct(productId) {
                        const res = await fetch(`/public/productos/api/detalle.php?id=${encodeURIComponent(productId)}&id_empresa=${encodeURIComponent(this.idEmpresa)}`, { cache: 'no-store' });
                        const data = await res.json();
                        if (!data?.success || !data?.data?.producto) {
                            throw new Error(data?.error || 'No se pudo cargar el producto');
                        }
                        const p = data.data.producto || {};
                        const codigosBarra = Array.isArray(data.data.codigos_barra) ? data.data.codigos_barra : [];
                        const codigoPrincipal = codigosBarra.length > 0 ? String(codigosBarra[0] || '').trim() : String(p.codigo_barra || '').trim();
                        const codigosExtra = codigosBarra.slice(1).map((codigo) => ({ codigo: String(codigo || '').trim() })).filter((row) => row.codigo !== '');
                        this.previewEquivEditor.form = {
                            ...this.defaultPreviewEquivForm(),
                            idproducto: p.idproducto,
                            cve_producto: p.cve_producto || p.codigo || '',
                            desproducto: p.desproducto || p.descripcion || '',
                            referencia: /^\d+$/.test(String(p.referencia || '')) ? parseInt(p.referencia, 10) : (p.referencia || 0),
                            grupo: p.grupo || 0,
                            marca: p.marca || 0,
                            modelo: p.modelo || 0,
                            color: p.color || 0,
                            unidad_medida: p.unidad_medida || '',
                            equivalencia: p.equivalencia || '',
                            iva: String(p.iva || 1),
                            impuesto: p.impuesto || 10,
                            codigo_barra: codigoPrincipal,
                            precio_compra: parseFloat(p.precio_compra) || 0,
                            precio_venta: parseFloat(p.precio_venta) || 0,
                            stock_minimo: parseFloat(p.stock_minimo) || 0,
                            stock_maximo: parseFloat(p.stock_maximo) || 0,
                            saldo_actual: parseFloat(p.saldo) || 0,
                            controla_stock: parseInt(p.controla_stock) || -1,
                            edita_precio: parseInt(p.edita_precio) || 1,
                            vende_sin_stock: parseInt(p.vende_sin_stock) || -1,
                            usaserial: parseInt(p.usaserial) || -1,
                            descripcion_larga: p.descripcion_larga || '',
                            ncm: p.ncm || '',
                            origen: p.origen || '',
                            peso: parseFloat(p.peso) || 0,
                            ancho: parseFloat(p.ancho) || 0,
                            alto: parseFloat(p.alto) || 0,
                            largo: parseFloat(p.largo) || 0,
                            foto_url: p.foto_url || '',
                            obs: p.obs || '',
                            publicar_web: parseInt(p.publicar_web) || 0,
                            precios: (data.data.precios || []).map((pr) => ({
                                tipo: String(pr?.tipo ?? ''),
                                tipo_nombre: String(pr?.tipo_nombre || '').trim(),
                                costo: parseFloat(pr.costo) || 0,
                                porcentaje: parseFloat(pr.porcentaje) || 0,
                                precio: parseFloat(pr.precio) || 0,
                                moneda: pr.moneda || 'PYG',
                            })),
                            codigos_barra_extra: codigosExtra,
                            equivalentes: this.previewEquivNormalizeEquivalencias(((data.data.equivalentes || []).length ? data.data.equivalentes : (data.data.aplicaciones || [])).filter((aplic) =>
                                String(aplic?.marca_cod_conversion || '').trim() !== '' || String(aplic?.conversion || '').trim() !== ''
                            )),
                            aplicaciones: ((data.data.aplicaciones_detalle || []).length ? data.data.aplicaciones_detalle : (data.data.aplicaciones || []))
                                .filter((aplic) =>
                                    String(aplic?.marca_aplicacion || '').trim() !== '' ||
                                    String(aplic?.vehiculo_marca || '').trim() !== '' ||
                                    String(aplic?.vehiculo_modelo || '').trim() !== '' ||
                                    String(aplic?.anio || '').trim() !== '' ||
                                    String(aplic?.motor || '').trim() !== '' ||
                                    String(aplic?.codigo_motor || '').trim() !== ''
                                )
                                .map((row, idx) => ({ ...row, __group_token: String(row?.marca_aplicacion || row?.vehiculo_marca || idx) })),
                        };
                        this.previewEquivEditor.loadedProductId = Number(productId || 0);
                    },

                    previewEquivBuildAplicacionesPayload() {
                        return this.previewEquivNormalizeAplicaciones(this.previewEquivEditor.form.aplicaciones).map((row, idx) => ({
                            marca_aplicacion: row.marca_aplicacion,
                            vehiculo_marca: row.vehiculo_marca,
                            vehiculo_modelo: row.vehiculo_modelo,
                            anio: row.anio,
                            motor: row.motor,
                            codigo_motor: row.codigo_motor,
                            orden: idx + 1,
                        }));
                    },

                    previewEquivBuildPayload() {
                        const form = this.previewEquivEditor.form || {};
                        const codigosBarra = [];
                        const principal = String(form.codigo_barra || '').trim();
                        if (principal) codigosBarra.push(principal);
                        (Array.isArray(form.codigos_barra_extra) ? form.codigos_barra_extra : []).forEach((cb) => {
                            const codigo = String(cb?.codigo || '').trim();
                            if (codigo) codigosBarra.push(codigo);
                        });
                        const codigosBarraUnicos = [...new Set(codigosBarra)];
                        const ivaMap = { 1: 10, 2: 5, 3: 0 };
                        return {
                            action: form.idproducto ? 'update' : 'create',
                            ...form,
                            id_empresa: this.idEmpresa,
                            impuesto: ivaMap[form.iva] ?? form.impuesto ?? 10,
                            codigo_barra: codigosBarraUnicos[0] || '',
                            codigos_barra: codigosBarraUnicos,
                            equivalentes: this.previewEquivNormalizeEquivalencias(form.equivalentes),
                            aplicaciones: this.previewEquivBuildAplicacionesPayload(),
                            precios: Array.isArray(form.precios) ? form.precios : [],
                        };
                    },

                    async savePreviewEquivEditor() {
                        if (this.previewEquivEditor.saving || !this.previewEquivEditor.form?.idproducto) return;
                        this.previewEquivEditor.saving = true;
                        this.previewEquivEditor.error = '';
                        try {
                            const response = await fetch('/public/productos/api/guardar.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(this.previewEquivBuildPayload())
                            });
                            const result = await response.json();
                            if (!result?.success) {
                                this.previewEquivEditor.error = result?.error || 'No se pudo guardar';
                                return;
                            }
                            const productId = Number(result?.idproducto || this.previewEquivEditor.form?.idproducto || 0);
                            if (productId > 0) {
                                await this.loadPreviewEquivEditorProduct(productId);
                                await this.refreshPreviewEquivDetail(productId);
                            }
                            this.toast(result?.message || 'Equivalencias actualizadas', 'success');
                        } catch (e) {
                            this.previewEquivEditor.error = e?.message || 'Error de conexión';
                        } finally {
                            this.previewEquivEditor.saving = false;
                        }
                    },

                    async refreshPreviewEquivDetail(productId) {
                        const res = await fetch(`api/producto_detalle.php?id=${encodeURIComponent(productId)}&id_empresa=${encodeURIComponent(this.idEmpresa)}`, { cache: 'no-store' });
                        const data = await res.json();
                        if (!data?.success) return;
                        const key = String(productId);
                        this._productDetailCache[key] = data;
                        this._searchPreviewDetailCache[key] = data;
                        if (String(this.searchPreviewDetail?.producto?.idproducto || this.searchPreviewDetail?.producto?.id || '') === key) {
                            this.searchPreviewDetail = data;
                            this.lastFocusedPreviewDetail = data;
                            this.syncSearchPreviewGallery();
                        } else if (String(this.lastFocusedPreviewDetail?.producto?.idproducto || this.lastFocusedPreviewDetail?.producto?.id || '') === key) {
                            this.lastFocusedPreviewDetail = data;
                        }
                        if (String(this.productDetail?.producto?.idproducto || this.productDetail?.producto?.id || '') === key) {
                            this.productDetail = data;
                            this.selectedDetailImage = this.getDetailGalleryImages()[0] || null;
                        }
                    },

                    async refreshManagedProductImages(productId) {
                        if (!productId) return;
                        await syncProductImagesFromServer(productId, this.idEmpresa, this);
                        await this.loadImageManagerImages();

                        try {
                            const res = await fetch(`api/producto_detalle.php?id=${productId}&id_empresa=${this.idEmpresa}`, { cache: 'no-store' });
                            const data = await res.json();
                            if (!data?.success) return;

                            if (String(this.productDetail?.producto?.idproducto || this.productDetail?.producto?.id || '') === String(productId)) {
                                this.productDetail = data;
                                this.selectedDetailImage = this.getDetailGalleryImages()[0] || null;
                            }
                            if (String(this.searchPreviewDetail?.producto?.idproducto || this.searchPreviewDetail?.producto?.id || '') === String(productId)) {
                                this.searchPreviewDetail = data;
                                this.lastFocusedPreviewDetail = data;
                                this.syncSearchPreviewGallery();
                            } else if (String(this.lastFocusedPreviewDetail?.producto?.idproducto || this.lastFocusedPreviewDetail?.producto?.id || '') === String(productId)) {
                                this.lastFocusedPreviewDetail = data;
                            }
                        } catch (e) {
                            console.error('Error refreshing product detail after image update:', e);
                        }
                    },

                    async setManagedImagePrincipal(image) {
                        const productId = Number(this.imageSearchProduct?.id || 0);
                        const fileId = String(image?.file_id || image?.drive_file_id || '').trim();
                        if (!productId || !fileId || this.imageManagerBusyFileId) return;

                        this.imageManagerBusyFileId = fileId;
                        try {
                            const response = await fetch('/public/productos/api/imagen.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action: 'set_principal',
                                    file_id: fileId,
                                    idproducto: productId,
                                    id_empresa: Number(this.idEmpresa || 0)
                                })
                            });
                            const result = await response.json().catch(() => ({}));
                            if (!result?.success) {
                                throw new Error(result?.error || 'No se pudo marcar la imagen principal');
                            }
                            await this.refreshManagedProductImages(productId);
                            this.toast('Imagen principal actualizada', 'success');
                        } catch (e) {
                            console.error('Error setting main product image:', e);
                            this.toast(e?.message || 'No se pudo marcar la imagen principal', 'error');
                        } finally {
                            this.imageManagerBusyFileId = '';
                        }
                    },

                    async deleteManagedImage(image) {
                        const productId = Number(this.imageSearchProduct?.id || 0);
                        const fileId = String(image?.file_id || image?.drive_file_id || '').trim();
                        if (!productId || !fileId || this.imageManagerBusyFileId) return;

                        const ok = window.confirm('¿Suprimir esta imagen del producto?');
                        if (!ok) return;

                        this.imageManagerBusyFileId = fileId;
                        try {
                            const response = await fetch('/public/productos/api/imagen.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action: 'delete',
                                    file_id: fileId,
                                    idproducto: productId,
                                    id_empresa: Number(this.idEmpresa || 0)
                                })
                            });
                            const result = await response.json().catch(() => ({}));
                            if (!result?.success) {
                                throw new Error(result?.error || 'No se pudo suprimir la imagen');
                            }
                            await this.refreshManagedProductImages(productId);
                            this.toast('Imagen suprimida', 'success');
                        } catch (e) {
                            console.error('Error deleting managed image:', e);
                            this.toast(e?.message || 'No se pudo suprimir la imagen', 'error');
                        } finally {
                            this.imageManagerBusyFileId = '';
                        }
                    },

                    // Buscar imágenes en Pixabay con traducción automática
                    async searchImagesPixabay() {
                        if (!this.imageSearchProduct?.descripcion) return;
                        
                        this.searchingImages = true;
                        this.searchPage = 1;
                        this.searchResults = []; // Limpiar resultados anteriores
                        
                        try {
                            // Agregar timestamp para evitar caché
                            const url = `/public/pos/api/buscar_imagenes.php?q=${encodeURIComponent(this.imageSearchProduct.descripcion)}&page=1&_t=${Date.now()}`;
                            console.log('Buscando imágenes:', url);
                            
                            const response = await fetch(url);
                            const data = await response.json();
                            
                            console.log('Respuesta búsqueda:', data.source, data.translated_query, 'Imágenes:', data.images?.length);
                            
                            if (data.success) {
                                this.searchResults = data.images || [];
                                this.searchTranslated = data.translated_query;
                                this.searchTotal = data.total;
                                
                                if (data.images && data.images.length > 0) {
                                    POSAudio.play('success');
                                } else if (data.message) {
                                    this.toast(data.message, 'warning');
                                }
                            } else {
                                this.toast(data.error || 'Error buscando imágenes', 'error');
                            }
                        } catch (e) {
                            console.error('Error buscando imágenes:', e);
                            this.toast('Error de conexión', 'error');
                        } finally {
                            this.searchingImages = false;
                        }
                    },

                    // Cargar más imágenes
                    async loadMoreImages() {
                        if (!this.searchTranslated || this.searchingImages) return;
                        
                        this.searchingImages = true;
                        this.searchPage++;
                        
                        try {
                            const response = await fetch(`/public/pos/api/buscar_imagenes.php?q=${encodeURIComponent(this.imageSearchProduct.descripcion)}&page=${this.searchPage}`);
                            const data = await response.json();
                            
                            if (data.success && data.images.length > 0) {
                                this.searchResults = [...this.searchResults, ...data.images];
                            }
                        } catch (e) {
                            console.error('Error cargando más imágenes:', e);
                        } finally {
                            this.searchingImages = false;
                        }
                    },

                    // Seleccionar imagen de Pixabay
                    selectPixabayImage(imageUrl) {
                        this.imageUrlInput = imageUrl;
                        this.imageBase64 = '';
                        POSAudio.play('click');
                        
                        // Scroll al área de preview/guardar
                        this.$nextTick(() => {
                            const urlInput = this.$refs.urlInput;
                            if (urlInput) {
                                urlInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        });
                    },

                    // Manejar pegado de imagen desde clipboard
                    handleImagePaste(event) {
                        // Solo procesar si el modal está abierto
                        if (!this.showImageSearchModal) return;
                        
                        const items = event.clipboardData?.items;
                        if (!items) return;

                        for (const item of items) {
                            if (item.type.startsWith('image/')) {
                                event.preventDefault();
                                const file = item.getAsFile();
                                if (file) {
                                    const reader = new FileReader();
                                    reader.onload = (e) => {
                                        this.imageBase64 = e.target.result;
                                        this.imageUrlInput = ''; // Limpiar URL si se pega imagen
                                        POSAudio.play('success');
                                    };
                                    reader.readAsDataURL(file);
                                }
                                return;
                            }
                        }
                    },

                    async pasteImageFromClipboard() {
                        if (!this.showImageSearchModal) return;
                        if (!navigator.clipboard?.read) {
                            this.toast('Su navegador no permite pegar imagen con botón. Use Ctrl+V.', 'warning');
                            return;
                        }

                        try {
                            const items = await navigator.clipboard.read();
                            for (const item of items) {
                                const imageType = item.types.find((type) => String(type).startsWith('image/'));
                                if (!imageType) continue;
                                const blob = await item.getType(imageType);
                                const reader = new FileReader();
                                reader.onload = (e) => {
                                    this.imageBase64 = e.target?.result || '';
                                    this.imageUrlInput = '';
                                    POSAudio.play('success');
                                };
                                reader.readAsDataURL(blob);
                                return;
                            }
                            this.toast('No se encontró una imagen en el portapapeles', 'warning');
                        } catch (e) {
                            console.error('Error reading clipboard image:', e);
                            this.toast('No se pudo acceder al portapapeles. Pruebe con Ctrl+V.', 'error');
                        }
                    },

                    async saveProductImage() {
                        if (!this.imageSearchProduct || (!this.imageUrlInput && !this.imageBase64)) return;

                        this.savingImage = true;

                        try {
                            let response;
                            let data;

                            if (this.imageBase64) {
                                const blob = await (await fetch(this.imageBase64)).blob();
                                const fd = new FormData();
                                fd.append('action', 'upload');
                                fd.append('idproducto', String(this.imageSearchProduct.id));
                                fd.append('id_empresa', String(this.idEmpresa));
                                fd.append('imagen', blob, `modal_${this.imageSearchProduct.id}_${Date.now()}.png`);
                                response = await fetch('/public/productos/api/imagen.php', {
                                    method: 'POST',
                                    body: fd
                                });
                            } else {
                                // Limpiar y validar URL
                                let imageUrl = this.imageUrlInput.trim();
                                
                                // Si no tiene protocolo, agregar https://
                                if (!imageUrl.match(/^https?:\/\//i)) {
                                    if (imageUrl.startsWith('//')) {
                                        imageUrl = 'https:' + imageUrl;
                                    } else if (imageUrl.startsWith('www.')) {
                                        imageUrl = 'https://' + imageUrl;
                                    } else {
                                        POSAudio.play('error');
                                        this.showAlert('URL inválida', 'La URL debe empezar con http:// o https://\n\nAsegúrate de copiar la dirección completa de la imagen.', '⚠️', 'danger');
                                        this.savingImage = false;
                                        return;
                                    }
                                }

                                response = await fetch('/public/productos/api/imagen.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        action: 'upload_url',
                                        idproducto: Number(this.imageSearchProduct.id),
                                        id_empresa: Number(this.idEmpresa),
                                        image_url: imageUrl
                                    })
                                });
                            }

                            data = await response.json().catch(() => ({}));

                            if (data.success) {
                                const productId = this.imageSearchProduct.id;
                                await this.refreshManagedProductImages(productId);
                                this.toast('Imagen agregada correctamente', 'success');
                                POSAudio.play('success');
                                this.imageUrlInput = '';
                                this.imageBase64 = '';
                                this.searchResults = [];
                            } else {
                                POSAudio.play('error');
                                const errorMsg = data.error || (!response.ok ? `La solicitud devolvió HTTP ${response.status}` : 'No se pudo guardar la imagen');
                                this.showAlert('Error al guardar imagen', errorMsg, '❌', 'danger');
                            }
                        } catch (e) {
                            console.error('Error guardando imagen:', e);
                            POSAudio.play('error');
                            this.showAlert('Error de conexión', e.message || 'No se pudo conectar con el servidor', '❌', 'danger');
                        } finally {
                            this.savingImage = false;
                        }
                    },

                    formatDate(dateStr) {
                        if (!dateStr) return '-';
                        try {
                            const date = new Date(dateStr);
                            return date.toLocaleDateString('es-PY', {
                                day: '2-digit',
                                month: '2-digit',
                                year: 'numeric'
                            });
                        } catch (e) {
                            return dateStr;
                        }
                    }
                }
            }
        </script>
        <!-- Modal WhatsApp -->
        <div
            x-show="showWhatsAppModal"
            x-cloak
            class="fixed inset-0 bg-black/70 flex items-center justify-center z-[110] backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-800 rounded-3xl p-6 max-w-sm w-full mx-4 shadow-2xl border border-slate-700">
                <div class="flex items-center gap-4 mb-6">
                    <div class="bg-green-500/20 p-3 rounded-2xl text-[#25D366] text-3xl">💬</div>
                    <div>
                        <h3 class="text-xl font-bold dark:text-white">Enviar WhatsApp</h3>
                        <p class="text-xs text-slate-400">Verifique el número del cliente</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1.5">Número de Celular</label>
                        <div class="flex gap-2">
                            <select
                                x-model="selectedCountryCode"
                                class="bg-slate-100 dark:bg-slate-900 px-3 py-3 rounded-xl border border-slate-700 text-sm font-bold dark:text-white focus:ring-2 focus:ring-green-500 outline-none cursor-pointer">
                                <template x-for="country in countries" :key="country.code">
                                    <option :value="country.code" x-text="country.flag + ' +' + country.code"></option>
                                </template>
                            </select>
                            <input
                                type="tel"
                                x-model="phoneInput"
                                class="flex-1 bg-slate-100 dark:bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-lg font-bold dark:text-white focus:ring-2 focus:ring-green-500 outline-none"
                                placeholder="981123456"
                                @keydown.enter="enviarWhatsApp()">
                        </div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button @click="showWhatsAppModal = false" class="flex-1 py-3 font-bold text-slate-400 hover:text-white transition-colors">Cancelar</button>
                        <button @click="enviarWhatsApp()" class="flex-[2] bg-[#25D366] hover:bg-[#128C7E] text-white font-bold py-3 rounded-xl shadow-lg transition-all active:scale-95">Abrir WhatsApp</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Email -->
        <div
            x-show="showEmailModal"
            x-cloak
            class="fixed inset-0 bg-black/70 flex items-center justify-center z-[110] backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-800 rounded-3xl p-6 max-w-sm w-full mx-4 shadow-2xl border border-slate-700">
                <div class="flex items-center gap-4 mb-6">
                    <div class="bg-blue-500/20 p-3 rounded-2xl text-blue-400 text-3xl">📧</div>
                    <div>
                        <h3 class="text-xl font-bold dark:text-white">Enviar Email</h3>
                        <p class="text-xs text-slate-400">Se enviará el comprobante digital</p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1.5">Correo Electrónico</label>
                        <input
                            type="email"
                            x-model="emailInput"
                            class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-700 rounded-xl px-4 py-3 text-lg font-bold dark:text-white focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="cliente@ejemplo.com"
                            @keydown.enter="enviarEmail()">
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button @click="showEmailModal = false" class="flex-1 py-3 font-bold text-slate-400 hover:text-white transition-colors">Cancelar</button>
                        <button
                            @click="enviarEmail()"
                            :disabled="sendingEmail"
                            class="flex-[2] bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl shadow-lg transition-all active:scale-95 disabled:opacity-50 flex items-center justify-center gap-2">
                            <span x-show="!sendingEmail">Enviar Email</span>
                            <span x-show="sendingEmail" class="animate-spin text-xl">⏳</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Vuelto Simplificado -->
        <div
            x-show="showSimpleChangeModal"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="fixed inset-0 bg-black/70 z-[80] backdrop-blur-md overflow-y-auto p-2 md:p-4"
            @keydown.escape.window="closeSimpleChangeModal()">
            <div class="min-h-full w-full flex items-start justify-center">
            <div x-show="showSimpleChangeModal"
                 x-transition:enter="transition ease-out duration-200 delay-75"
                 x-transition:enter-start="opacity-0 scale-90 translate-y-4"
                 x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 class="bg-gradient-to-b from-white to-slate-100 dark:from-slate-800 dark:to-slate-900 rounded-2xl max-w-xl w-full my-2 md:my-4 p-3 md:p-4 shadow-2xl shadow-black/40 border border-slate-200 dark:border-slate-600/50 relative overflow-hidden max-h-[calc(100vh-16px)] md:max-h-[calc(100vh-32px)] flex flex-col">

                <!-- Total a Pagar (Header integrado) -->
                <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-4 text-center rounded-xl">
                    <div class="text-[10px] font-bold text-blue-200 uppercase tracking-[0.2em] mb-1">Total a Pagar</div>
                    <div class="text-3xl font-black text-white tracking-tight" x-text="formatMoney(total) + ' Gs'"></div>
                </div>

                <div class="px-2 py-2 md:px-3 md:py-3 space-y-4 overflow-y-auto flex-1 min-h-0 pr-1">
                    <!-- Tipo Documento -->
                    <div x-show="!userConfig.isVendedor">
                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Tipo Documento</label>
                        <div class="grid gap-1.5" :class="docTypes.length > 2 ? 'grid-cols-3' : 'grid-cols-2'">
                            <template x-for="dt in docTypes" :key="dt.id">
                                <button type="button"
                                    @mousedown="startLongPress('doc', dt.id)"
                                    @mouseup="endLongPress()"
                                    @mouseleave="endLongPress()"
                                    @touchstart.passive="startLongPress('doc', dt.id)"
                                    @touchend="endLongPress()"
                                    @touchcancel="endLongPress()"
                                    @click="if(!wasLongPress()) selectedDocType = dt.id"
                                    @contextmenu.prevent
                                    class="w-full py-2 px-2 rounded-lg text-xs font-bold border-2 transition-all text-center truncate relative"
                                    :class="selectedDocType === dt.id
                                        ? 'border-blue-500 bg-blue-500/20 text-blue-300'
                                        : 'border-slate-600 bg-slate-800 text-slate-400 hover:border-slate-500'">
                                    <span x-text="dt.icon + ' ' + dt.name"></span>
                                    <div x-show="defaultDocType === dt.id" class="absolute top-0 right-0 z-10">
                                        <span class="text-[9px] bg-amber-500 text-white px-1.5 py-0.5 rounded-bl-lg rounded-tr-lg font-black shadow-md" style="color:#fff !important;">⭐ FIJADO</span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Forma de Pago -->
                    <div x-show="!userConfig.isVendedor">
                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Forma de Pago</label>
                        <div class="grid gap-2 grid-cols-3">
                            <template x-for="pm in paymentMethodsCobroModal" :key="pm.id">
                                <button x-show="isPaymentMethodAllowed(pm.id)" type="button"
                                    @mousedown="startLongPress('payment', pm.id)"
                                    @mouseup="endLongPress()"
                                    @mouseleave="endLongPress()"
                                    @touchstart.passive="startLongPress('payment', pm.id)"
                                    @touchend="endLongPress()"
                                    @touchcancel="endLongPress()"
                                    @click="if(!wasLongPress()) selectSimplePaymentMethod(pm.id)"
                                    @contextmenu.prevent
                                    :style="({
                                        efectivo: 'background:rgba(16,185,129,.22); border-color:rgba(16,185,129,.65); color:#d1fae5;',
                                        tarjeta: 'background:rgba(14,165,233,.22); border-color:rgba(14,165,233,.65); color:#e0f2fe;',
                                        transferencia: 'background:rgba(139,92,246,.22); border-color:rgba(139,92,246,.65); color:#ede9fe;',
                                        pix: 'background:rgba(217,70,239,.22); border-color:rgba(217,70,239,.65); color:#fae8ff;',
                                        credito: 'background:rgba(245,158,11,.22); border-color:rgba(245,158,11,.65); color:#fef3c7;'
                                    })[pm.id] || ''"
                                    class="py-3 px-2 rounded-xl text-xs font-black border transition-all flex flex-col items-center justify-center gap-1 relative"
                                    :class="simplePaymentMethod === pm.id
                                        ? 'ring-2 shadow-lg scale-[1.02] ' + ({
                                            efectivo: 'ring-emerald-300/80 shadow-emerald-500/30',
                                            tarjeta: 'ring-sky-300/80 shadow-sky-500/30',
                                            transferencia: 'ring-violet-300/80 shadow-violet-500/30',
                                            pix: 'ring-fuchsia-300/80 shadow-fuchsia-500/30',
                                            credito: 'ring-amber-300/80 shadow-amber-500/30'
                                        })[pm.id]
                                        : ''">
                                    <div class="w-full flex justify-center">
                                        <span class="text-2xl" x-text="pm.icon"></span>
                                    </div>
                                    <span class="leading-none" x-text="pm.name"></span>
                                    <div x-show="defaultPaymentMethod === pm.id" class="absolute top-0 right-0 z-10">
                                        <span class="text-[9px] bg-amber-500 text-white px-1.5 py-0.5 rounded-bl-lg rounded-tr-xl font-black shadow-md" style="color:#fff !important;">⭐ FIJADO</span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div x-show="!simplePaymentMethod" class="rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-100/70 dark:bg-slate-900/40 py-5 text-center">
                        <p class="text-sm font-semibold text-slate-700 dark:text-slate-300">Seleccione una forma de pago para continuar</p>
                    </div>

                    <!-- Input Monto -->
                    <div x-show="simplePaymentMethod">
                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5"
                               x-text="simplePaymentMethod === 'efectivo' ? 'Monto Recibido' : 'Monto a Cobrar'"></label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-lg font-black text-blue-500 dark:text-blue-300 drop-shadow-sm">₲</span>
                            <input
                                x-ref="simpleCashInput"
                                type="text"
                                inputmode="numeric"
                                :value="getEffectiveSimpleCashReceived() ? Number(getEffectiveSimpleCashReceived()).toLocaleString('es-PY') : ''"
                                @focus="$event.target.select()"
                                @input="
                                    let raw = $event.target.value.replace(/[^0-9]/g, '');
                                    simpleCashReceived = raw;
                                    let pos = $event.target.selectionStart;
                                    let oldLen = $event.target.value.length;
                                    $event.target.value = raw ? Number(raw).toLocaleString('es-PY') : '';
                                    let newLen = $event.target.value.length;
                                    $nextTick(() => { $event.target.setSelectionRange(pos + (newLen - oldLen), pos + (newLen - oldLen)); });
                                "
                                @keydown.enter="confirmSimpleSale()"
                                style="font-size:1.5rem; font-weight:900; -webkit-appearance:none; -moz-appearance:none; appearance:none;"
                                class="w-full bg-white dark:bg-slate-950/95 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 border-2 border-slate-400 dark:border-slate-500 rounded-xl px-4 py-3.5 pl-9 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400 focus:ring-2 focus:ring-blue-500/30 dark:focus:ring-blue-400/30 shadow-sm dark:shadow-[0_0_0_1px_rgba(148,163,184,0.08)] transition-all text-right tabular-nums"
                                placeholder="0">
                        </div>
                    </div>

                    <!-- Billetes rápidos (solo efectivo) -->
                    <div x-show="simplePaymentMethod === 'efectivo'" class="grid grid-cols-3 gap-2">
                        <button type="button" @click="simpleCashReceived = 5000" class="py-2 rounded-lg border border-emerald-400/50 bg-emerald-500/20 text-emerald-100 font-bold text-xs">5.000</button>
                        <button type="button" @click="simpleCashReceived = 10000" class="py-2 rounded-lg border border-emerald-400/50 bg-emerald-500/20 text-emerald-100 font-bold text-xs">10.000</button>
                        <button type="button" @click="simpleCashReceived = 20000" class="py-2 rounded-lg border border-emerald-400/50 bg-emerald-500/20 text-emerald-100 font-bold text-xs">20.000</button>
                        <button type="button" @click="simpleCashReceived = 50000" class="py-2 rounded-lg border border-emerald-400/50 bg-emerald-500/20 text-emerald-100 font-bold text-xs">50.000</button>
                        <button type="button" @click="simpleCashReceived = 100000" class="py-2 rounded-lg border border-emerald-400/50 bg-emerald-500/20 text-emerald-100 font-bold text-xs">100.000</button>
                        <button type="button" @click="simpleCashReceived = 200000" class="py-2 rounded-lg border border-emerald-400/50 bg-emerald-500/20 text-emerald-100 font-bold text-xs">200.000</button>
                    </div>

                    <!-- Campos por método -->
                    <div x-show="simplePaymentMethod === 'tarjeta'" class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Tipo Tarjeta (PY)</label>
                                <select x-model="simpleCardFinancingType" @change="normalizeSimpleCardFields()"
                                    class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-3 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                                    <template x-for="opt in simpleCardFinancingTypeOptions" :key="opt.value">
                                        <option :value="opt.value" x-text="opt.label"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Procesador (PY)</label>
                                <select x-model="simpleCardProcessor" @change="normalizeSimpleCardFields()"
                                    class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-3 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                                    <template x-for="opt in simpleCardProcessorOptions" :key="opt.value">
                                        <option :value="opt.value" x-text="opt.label"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Cuotas (PY)</label>
                                <select x-model="simpleCardInstallments" @change="normalizeSimpleCardFields()"
                                    class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-3 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                                    <template x-for="n in getSimpleCardInstallmentOptions()" :key="n">
                                        <option :value="n" x-text="n + 'x'"></option>
                                    </template>
                                </select>
                            </div>
                            <label class="flex items-center gap-2 rounded-lg border border-amber-500/40 bg-amber-100 dark:bg-amber-500/10 px-3 py-2 text-[10px] text-amber-800 dark:text-amber-200">
                                <input type="checkbox" x-model="simpleCardMockDecline" class="accent-amber-400">
                                <span><strong>QA:</strong> Forzar rechazo</span>
                            </label>
                        </div>
                        <div class="grid grid-cols-[1fr_auto] gap-2">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Referencia Terminal (Voucher/NSU)</label>
                                <input
                                    type="text"
                                    x-model="simpleReference"
                                    class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none"
                                    placeholder="Voucher / Lote / Ref. terminal">
                            </div>
                            <button
                                type="button"
                                @click="captureCardFromTerminal()"
                                :disabled="simpleCardCaptureLoading"
                                class="px-4 py-3 rounded-xl font-bold text-xs text-white bg-sky-600 hover:bg-sky-500 shadow-lg shadow-sky-600/25 transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                                <span x-show="!simpleCardCaptureLoading">Capturar Tarjeta</span>
                                <span x-show="simpleCardCaptureLoading">Procesando...</span>
                            </button>
                        </div>
                        <p x-show="simpleCardCaptureError" class="text-[11px] text-red-300" x-text="simpleCardCaptureError"></p>
                        <div x-show="simpleCardCaptureData" class="rounded-lg border border-sky-500/30 bg-sky-500/10 px-3 py-2 text-[10px] text-sky-100 grid grid-cols-2 gap-x-3 gap-y-1">
                            <div><strong>Ref:</strong> <span x-text="simpleCardCaptureData?.reference || '-'"></span></div>
                            <div><strong>Aut:</strong> <span x-text="simpleCardCaptureData?.auth_code || '-'"></span></div>
                            <div><strong>NSU:</strong> <span x-text="simpleCardCaptureData?.nsu || '-'"></span></div>
                            <div><strong>RRN:</strong> <span x-text="simpleCardCaptureData?.rrn || '-'"></span></div>
                            <div><strong>Lote:</strong> <span x-text="simpleCardCaptureData?.batch || '-'"></span></div>
                            <div><strong>Marca:</strong> <span x-text="simpleCardCaptureData?.brand || '-'"></span></div>
                        </div>
                    </div>

                    <div x-show="simplePaymentMethod === 'transferencia'">
                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5"
                               x-text="'Referencia Transferencia'"></label>
                        <input
                            type="text"
                            x-model="simpleReference"
                            class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none"
                            placeholder="Ingrese referencia">
                    </div>

                    <div x-show="simplePaymentMethod === 'pix'" class="space-y-2">
                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Referencia PIX (TXID)</label>
                        <input
                            type="text"
                            x-model="simpleReference"
                            class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none"
                            placeholder="Se completa al generar PIX">

                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" @click="createPixCharge()"
                                :disabled="simplePixLoading || simplePixStatusLoading"
                                class="px-4 py-2.5 rounded-xl font-bold text-xs text-white bg-fuchsia-600 hover:bg-fuchsia-500 transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                                <span x-show="!simplePixLoading">Generar PIX</span>
                                <span x-show="simplePixLoading">Generando...</span>
                            </button>
                            <button type="button" @click="refreshPixStatus()"
                                :disabled="simplePixLoading || simplePixStatusLoading"
                                class="px-4 py-2.5 rounded-xl font-bold text-xs text-white bg-indigo-600 hover:bg-indigo-500 transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                                <span x-show="!simplePixStatusLoading">Consultar Estado</span>
                                <span x-show="simplePixStatusLoading">Consultando...</span>
                            </button>
                        </div>

                        <div x-show="simplePixData" class="rounded-lg border border-fuchsia-500/30 bg-fuchsia-500/10 px-3 py-2 text-[11px] text-fuchsia-100">
                            <div class="flex items-center justify-between">
                                <span><strong>Estado:</strong> <span x-text="simplePixData?.status || 'PENDING'"></span></span>
                                <span :class="simplePixData?.paid ? 'text-emerald-300 font-bold' : 'text-amber-300 font-bold'"
                                      x-text="simplePixData?.paid ? 'PAGADO' : 'PENDIENTE'"></span>
                            </div>
                            <div class="mt-1"><strong>TXID:</strong> <span x-text="simplePixData?.reference || '-'"></span></div>
                            <div class="mt-1" x-show="simplePixData?.expires_at"><strong>Vence:</strong> <span x-text="simplePixData?.expires_at"></span></div>
                            <div class="mt-2 flex justify-center" x-show="simplePixData?.qr_image_url">
                                <img :src="simplePixData?.qr_image_url" alt="QR PIX" class="w-44 h-44 rounded bg-white p-2">
                            </div>
                            <div class="mt-2 text-[10px] break-all" x-show="simplePixData?.payload_qr">
                                <strong>Copia e pega:</strong> <span x-text="simplePixData?.payload_qr"></span>
                            </div>
                            <button type="button" @click="markPixPaidQa()"
                                class="mt-2 px-3 py-1.5 rounded-lg border border-amber-500/40 bg-amber-500/10 text-amber-200 text-[10px] font-bold">
                                QA: Marcar pagado
                            </button>
                        </div>
                        <p x-show="simplePixError" class="text-[11px] text-red-300" x-text="simplePixError"></p>
                    </div>

                    <div x-show="simplePaymentMethod === 'credito'" class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Cuotas</label>
                                <input type="number" min="1" x-model="simpleCreditInstallments" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Vto. 1ra Cuota</label>
                                <input type="date" x-model="simpleCreditDueDate" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Interes Normal % Mes</label>
                                <input type="number" min="0" step="0.01" x-model="simpleCreditInterestPct" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Interes Moratorio % Mes</label>
                                <input type="number" min="0" step="0.01" x-model="simpleCreditMoraPct" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-[0.15em] mb-1.5">Dias de Gracia</label>
                                <input type="number" min="0" step="1" x-model="simpleCreditGraceDays" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none">
                            </div>
                        </div>
                        <input type="text" x-model="simpleCreditNotes" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-xl px-4 py-3 text-sm font-bold text-slate-900 dark:text-white outline-none" placeholder="Observación (opcional)">
                        <p class="text-[10px] text-amber-700 dark:text-amber-300" x-show="!currentTicket.selectedCliente?.id">Debe seleccionar un cliente para vender a crédito</p>
                    </div>

                    <!-- Vuelto/Faltante -->
                    <div x-show="simplePaymentMethod" class="rounded-xl p-3.5 text-center transition-colors duration-300"
                         :class="getEffectiveSimpleCashReceived() >= total
                            ? 'bg-emerald-500/15 border border-emerald-500/30'
                            : 'bg-red-500/10 border border-red-500/20'">
                        <div class="text-[10px] font-bold uppercase tracking-[0.15em] mb-0.5"
                             :class="getEffectiveSimpleCashReceived() >= total ? 'text-emerald-400' : 'text-red-400'"
                             x-text="(getEffectiveSimpleCashReceived() >= total) ? (simplePaymentMethod === 'efectivo' ? 'Su Vuelto' : 'Cobertura') : 'Faltante'"></div>
                        <div class="text-3xl font-black tabular-nums"
                             :class="getEffectiveSimpleCashReceived() >= total ? 'text-emerald-400' : 'text-red-400'">
                            <span x-text="formatMoney(Math.abs(getEffectiveSimpleCashReceived() - total)) + ' Gs'"></span>
                        </div>
                    </div>

                    <!-- Acciones -->
                    <div class="grid grid-cols-2 gap-2.5 pt-1">
                        <button
                            @click="closeSimpleChangeModal()"
                            class="py-3 rounded-xl font-semibold text-sm text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700 hover:text-slate-900 dark:hover:text-slate-200 transition-all active:scale-95">
                            Cancelar
                        </button>
                        <button
                            @click="confirmSimpleSale()"
                            :disabled="
                                !simplePaymentMethod ||
                                (getEffectiveSimpleCashReceived() <= 0) ||
                                (simplePaymentMethod === 'efectivo' && getEffectiveSimpleCashReceived() < Number(total || 0)) ||
                                (simplePaymentMethod !== 'efectivo' && getEffectiveSimpleCashReceived() < Number(total || 0)) ||
                                ((simplePaymentMethod === 'tarjeta' || simplePaymentMethod === 'transferencia' || simplePaymentMethod === 'pix') && !String(simpleReference || '').trim()) ||
                                (simplePaymentMethod === 'pix' && !(simplePixData && simplePixData.paid)) ||
                                (simplePaymentMethod === 'credito' && !currentTicket.selectedCliente?.id)
                            "
                            class="py-3 rounded-xl font-bold text-sm text-white bg-blue-600 hover:bg-blue-500 shadow-lg shadow-blue-600/25 active:scale-95 transition-all disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100 flex items-center justify-center gap-2">
                            <i class="fas fa-print"></i> Cobrar e Imprimir
                        </button>
                    </div>
                </div>
            </div>
            </div>
        </div>

    <div x-show="showSerialPickerModal" x-cloak
         x-transition.opacity
         class="fixed inset-0 z-[95] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4"
         @keydown.escape.window="closeSerialPicker()">
        <div class="w-full max-w-2xl rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl overflow-hidden">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-800 bg-slate-950">
                <div>
                    <h3 class="text-base font-bold text-white">Seleccionar serial/IMEI</h3>
                    <p class="text-xs text-slate-400" x-text="serialPickerProduct?.descripcion || 'Producto con control de serie'"></p>
                </div>
                <button type="button" @click="closeSerialPicker()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <div class="p-5 space-y-4">
                <div x-show="serialPickerLoading" class="text-sm text-slate-300">Cargando seriales disponibles...</div>
                <div x-show="!serialPickerLoading && serialPickerError" class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200" x-text="serialPickerError"></div>
                <div x-show="!serialPickerLoading && !serialPickerError" class="max-h-[55vh] overflow-y-auto space-y-2">
                    <template x-for="serie in serialPickerSeries" :key="serie.id">
                        <button type="button"
                                @click="selectSerialForProduct(serie)"
                                class="w-full rounded-xl border border-slate-700 bg-slate-800/80 px-4 py-3 text-left hover:border-cyan-500/60 hover:bg-slate-800 transition-all">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-mono font-semibold text-cyan-300" x-text="serie.serie"></p>
                                    <p class="text-[11px] text-slate-400">
                                        <span x-text="serie.tipo || 'SERIAL'"></span>
                                        <span x-show="serie.obs" x-text="' • ' + serie.obs"></span>
                                    </p>
                                </div>
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-emerald-300">Disponible</span>
                            </div>
                        </button>
                    </template>
                </div>
            </div>
            <div class="flex justify-end px-5 py-4 border-t border-slate-800 bg-slate-950">
                <button type="button" @click="closeSerialPicker()" class="rounded-lg border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    <!-- ══════ Modal Instalación App de Impresión ══════ -->
    <div x-show="showQzInstallModal" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         class="fixed inset-0 bg-black/70 flex items-center justify-center z-[90] backdrop-blur-md"
         @keydown.escape.window="showQzInstallModal = false">
        <div x-show="showQzInstallModal"
             x-transition:enter="transition ease-out duration-200 delay-75"
             x-transition:enter-start="opacity-0 scale-90" x-transition:enter-end="opacity-100 scale-100"
             class="bg-gradient-to-b from-slate-800 to-slate-900 rounded-2xl max-w-md w-full mx-4 shadow-2xl border border-slate-600/50 overflow-hidden">

            <!-- Header -->
            <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-4 text-center">
                <div class="text-3xl mb-1">🖨️</div>
                <h2 class="text-lg font-black text-white">App de Impresión</h2>
                <p class="text-amber-100 text-xs mt-1">Impresora: <strong x-text="printerName || 'No configurada'"></strong></p>
            </div>

            <div class="px-6 py-5 space-y-4">
                <!-- Estado conexión -->
                <div class="rounded-lg p-3" :class="qzConnected ? 'bg-emerald-500/10 border border-emerald-500/30' : 'bg-red-500/10 border border-red-500/30'">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full" :class="qzConnected ? 'bg-emerald-400 animate-pulse' : 'bg-red-400'"></div>
                        <span class="text-sm font-bold"
                              :class="qzConnected ? (directPrintEnabled ? 'text-emerald-400' : 'text-amber-400') : 'text-red-400'"
                              x-text="qzConnected ? (directPrintEnabled ? 'Conectado — Impresión directa activa' : 'Conectado — Impresión directa desactivada') : 'Desconectado'"></span>
                    </div>
                    <!-- Info diagnóstico -->
                    <div class="mt-2 ml-5 space-y-1">
                        <p class="text-[10px] text-slate-500">Protocolo: <span class="text-slate-300" x-text="location.protocol"></span> | Proveedor: <span class="text-slate-300" x-text="printProvider"></span></p>
                        <p x-show="qzConnected && _qzPrintersList.length" class="text-[10px] text-emerald-400/70">🖨️ Impresoras: <span x-text="_qzPrintersList.join(', ')"></span></p>
                        <p x-show="_qzLastError" class="text-[10px] text-red-400">❌ <span x-text="_qzLastError"></span></p>
                    </div>
                </div>

                <!-- Conectado: mostrar impresoras -->
                <div x-show="qzConnected && directPrintEnabled" class="space-y-2">
                    <p class="text-xs text-emerald-400 text-center font-bold">✅ Todo listo. Los tickets se imprimen directo.</p>
                </div>
                <div x-show="qzConnected && !directPrintEnabled" class="space-y-2">
                    <p class="text-xs text-amber-400 text-center font-bold">⚠️ Impresión directa está desactivada. Se usará impresión web/PDF.</p>
                </div>

                <!-- Desconectado: fallback web -->
                <div x-show="!qzConnected" class="space-y-3">
                    <p class="text-xs text-slate-300 bg-slate-800/60 border border-slate-700 rounded-lg p-3">
                        La impresión directa no está conectada. El sistema usará impresión web del navegador automáticamente.
                    </p>

                    <a href="/public/apps-moviles.php"
                       class="w-full py-2.5 rounded-xl font-semibold text-sm text-cyan-300 border border-cyan-700/50 hover:bg-cyan-900/20 transition-all active:scale-95 flex items-center justify-center gap-2">
                        <i class="fas fa-download"></i> Descargar Agent Print
                    </a>

                    <a href="/public/apps-moviles.php" target="_blank" rel="noopener"
                       class="w-full py-2.5 rounded-xl font-semibold text-sm text-violet-300 border border-violet-700/50 hover:bg-violet-900/20 transition-all active:scale-95 flex items-center justify-center gap-2">
                        <i class="fas fa-play-circle"></i> Video tutorial
                    </a>

                    <button @click="launchNativeAgentFromWeb()"
                            :disabled="launchingAgent"
                            class="w-full py-2.5 rounded-xl font-semibold text-sm text-indigo-200 border border-indigo-700/50 hover:bg-indigo-900/20 transition-all active:scale-95 disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        <span x-show="!launchingAgent" class="flex items-center gap-2"><i class="fas fa-rocket"></i> Iniciar Agent desde navegador</span>
                        <span x-show="launchingAgent" class="flex items-center gap-2"><i class="fas fa-spinner fa-spin"></i> Iniciando Agent...</span>
                    </button>

                    <!-- Botón verificar -->
                    <button @click="retryQzConnect()"
                            :disabled="qzCheckingConnection"
                            class="w-full py-3 rounded-xl font-bold text-sm text-white bg-emerald-600 hover:bg-emerald-500 shadow-lg shadow-emerald-600/25 active:scale-95 transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        <span x-show="!qzCheckingConnection" class="flex items-center gap-2">Reintentar conexión directa</span>
                        <span x-show="qzCheckingConnection" class="flex items-center gap-2"><i class="fas fa-spinner fa-spin"></i> Conectando...</span>
                    </button>

                    <!-- Botón calibración -->
                    <button @click="printCalibration()"
                            x-show="qzConnected"
                            class="w-full py-2.5 rounded-xl font-semibold text-sm text-amber-300 border border-amber-700/50 hover:bg-amber-900/30 transition-all active:scale-95 flex items-center justify-center gap-2">
                        <i class="fas fa-ruler"></i> Imprimir Test de Calibración
                    </button>
                </div>

                <!-- Cerrar -->
                <button @click="showQzInstallModal = false; _pendingPrintId = null"
                        class="w-full py-2.5 rounded-xl font-semibold text-sm text-slate-400 border border-slate-700 hover:bg-slate-700 hover:text-slate-200 transition-all active:scale-95">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    <!-- Script de refuerzo para wallpaper (por si el CSS inicial no se aplicó) -->
    <script>
    (function() {
        // Refuerzo: aplicar wallpaper después de que el DOM esté listo
        function aplicarWallpaperRefuerzo() {
            console.log('[POS Refuerzo] Wallpaper deprecado en POS');
        }
        
        // Ejecutar inmediatamente y también en DOMContentLoaded por seguridad
        aplicarWallpaperRefuerzo();
        document.addEventListener('DOMContentLoaded', aplicarWallpaperRefuerzo);
        
        // También ejecutar cuando Alpine termine de inicializar (puede sobrescribir estilos)
        document.addEventListener('alpine:initialized', aplicarWallpaperRefuerzo);
    })();
    </script>
    
    <!-- Video Wallpaper desde sessionStorage -->
    <script>
    (function() {
        function aplicarVideoDesdeCache() {
            console.log('[POS Video Wallpaper] Deprecado en POS');
        }
        
        // Aplicar video cacheado inmediatamente
        aplicarVideoDesdeCache();
        
        // Reintentar al cargar DOM por si no estaba listo
        document.addEventListener('DOMContentLoaded', aplicarVideoDesdeCache);
    })();
    </script>
</body>

</html>
