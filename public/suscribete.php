<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/Support/whatsapp_queue.php';
require_once __DIR__ . '/../src/Modules/Empresas/SuscripcionController.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Evita que el navegador restaure el estado previo del formulario al volver.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function esc($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function normalizarLoginAdmin(string $value, string $fallback = 'admin'): string
{
    $login = trim(mb_strtolower($value, 'UTF-8'));
    if ($login !== '' && function_exists('iconv')) {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $login);
        if ($tmp !== false) {
            $login = (string)$tmp;
        }
    }
    $login = preg_replace('/\s+/', '.', $login);
    $login = preg_replace('/[^a-z0-9._-]/', '', (string)$login);
    $login = preg_replace('/[._-]{2,}/', '.', (string)$login);
    $login = trim((string)$login, '._-');

    if ($login === '') {
        $fb = trim(mb_strtolower($fallback, 'UTF-8'));
        if ($fb !== '' && function_exists('iconv')) {
            $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $fb);
            if ($tmp !== false) {
                $fb = (string)$tmp;
            }
        }
        $fb = preg_replace('/[^a-z0-9]/', '', (string)$fb);
        $login = ($fb !== '' ? $fb : 'admin');
    }

    $login = substr($login, 0, 32);
    if (strlen($login) < 4) {
        $login = str_pad($login, 4, '0');
    }
    return $login;
}

function envValue(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v !== false && $v !== null && $v !== '') return (string)$v;
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];

    // Compatibilidad con variables legacy sin prefijo SISTEMAX_.
    $aliases = [
        'SISTEMAX_WHATSAPP_ENDPOINT' => 'WHATSAPP_ENDPOINT',
        'SISTEMAX_WHATSAPP_AUTH_BEARER' => 'WHATSAPP_AUTH_BEARER',
        'SISTEMAX_WHATSAPP_TOKEN' => 'WHATSAPP_TOKEN',
        'SISTEMAX_WHATSAPP_PHONE_NUMBER_ID' => 'WHATSAPP_PHONE_NUMBER_ID',
        'SISTEMAX_WHATSAPP_GRAPH_VERSION' => 'WHATSAPP_GRAPH_VERSION',
        'SISTEMAX_WHATSAPP_ENABLED' => 'WHATSAPP_ENABLED',
        'SISTEMAX_SUPPORT_WHATSAPP' => 'SUPPORT_WHATSAPP',
        'SISTEMAX_WHATSAPP_ADMIN_TO' => 'WHATSAPP_ADMIN_TO',
    ];
    $legacyKey = $aliases[$key] ?? '';
    if ($legacyKey !== '') {
        $legacyVal = getenv($legacyKey);
        if ($legacyVal !== false && $legacyVal !== null && $legacyVal !== '') return (string)$legacyVal;
        if (isset($_SERVER[$legacyKey]) && $_SERVER[$legacyKey] !== '') return (string)$_SERVER[$legacyKey];
        if (isset($_ENV[$legacyKey]) && $_ENV[$legacyKey] !== '') return (string)$_ENV[$legacyKey];
    }

    $fromfile = envFileValue($key, '');
    if ($fromfile !== '') return $fromfile;

    return $default;
}


function envFileValue(string $key, string $default = ''): string
{
    static $cache = null;
    if (!is_array($cache)) {
        $cache = [];
        $path = '/etc/environment';
        if (is_readable($path)) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '' || $line[0] === '#') continue;
                $eq = strpos($line, '=');
                if ($eq === false) continue;
                $k = trim(substr($line, 0, $eq));
                $v = trim(substr($line, $eq + 1));
                if ($k === '') continue;
                if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                    $v = substr($v, 1, -1);
                }
                $cache[$k] = $v;
            }
        }
    }
    return (string)($cache[$key] ?? $default);
}


function ensureSolicitudesTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saas_solicitudes (
            id_solicitud INT NOT NULL AUTO_INCREMENT,
            empresa VARCHAR(150) NOT NULL,
            contacto VARCHAR(120) NOT NULL,
            email VARCHAR(160) NOT NULL,
            telefono VARCHAR(40) NULL,
            ruc VARCHAR(40) NULL,
            mensaje VARCHAR(500) NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'nuevo',
            ip_origen VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            invite_token VARCHAR(80) NULL,
            invite_expires_at DATETIME NULL,
            invite_sent_at DATETIME NULL,
            invite_used_at DATETIME NULL,
            admin_login VARCHAR(80) NULL,
            admin_name VARCHAR(150) NULL,
            admin_user_id INT NULL,
            admin_created_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_solicitud),
            KEY idx_estado_fecha (estado, created_at),
            KEY idx_email (email),
            KEY idx_invite_token (invite_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = [
        'invite_token' => "ALTER TABLE saas_solicitudes ADD COLUMN invite_token VARCHAR(80) NULL AFTER user_agent",
        'invite_expires_at' => "ALTER TABLE saas_solicitudes ADD COLUMN invite_expires_at DATETIME NULL AFTER invite_token",
        'invite_sent_at' => "ALTER TABLE saas_solicitudes ADD COLUMN invite_sent_at DATETIME NULL AFTER invite_expires_at",
        'invite_used_at' => "ALTER TABLE saas_solicitudes ADD COLUMN invite_used_at DATETIME NULL AFTER invite_sent_at",
        'admin_login' => "ALTER TABLE saas_solicitudes ADD COLUMN admin_login VARCHAR(80) NULL AFTER invite_used_at",
        'admin_name' => "ALTER TABLE saas_solicitudes ADD COLUMN admin_name VARCHAR(150) NULL AFTER admin_login",
        'admin_user_id' => "ALTER TABLE saas_solicitudes ADD COLUMN admin_user_id INT NULL AFTER admin_name",
        'admin_created_at' => "ALTER TABLE saas_solicitudes ADD COLUMN admin_created_at DATETIME NULL AFTER admin_user_id",
    ];

    foreach ($columns as $column => $sql) {
        $check = $pdo->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'saas_solicitudes'
              AND COLUMN_NAME = :col
            LIMIT 1
        ");
        $check->execute([':col' => $column]);
        if (!$check->fetchColumn()) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saas_solicitudes_mails (
            id_mail INT NOT NULL AUTO_INCREMENT,
            id_solicitud INT NULL,
            tipo VARCHAR(30) NOT NULL,
            destino VARCHAR(190) NOT NULL,
            asunto VARCHAR(255) NOT NULL,
            provider VARCHAR(30) NOT NULL DEFAULT 'smtp',
            estado VARCHAR(20) NOT NULL,
            error_msg VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_mail),
            KEY idx_solicitud_fecha (id_solicitud, created_at),
            KEY idx_tipo_estado (tipo, estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    sx_ensure_whatsapp_outbox_table($pdo);
}

function normalizarPlanSuscripcion(string $value): string
{
    $plan = strtolower(trim($value));
    $aliases = [
        'gratis' => 'gratis_fe',
        'gratisfe' => 'gratis_fe',
        'gratis_fe' => 'gratis_fe',
        'emprendedor' => 'emprendedor_fe',
        'emprendedorfe' => 'emprendedor_fe',
        'emprendedor_fe' => 'emprendedor_fe',
        'pro' => 'pro_fe',
        'profe' => 'pro_fe',
        'pro_fe' => 'pro_fe',
        'empresa' => 'empresa_fe',
        'empresafe' => 'empresa_fe',
        'empresa_fe' => 'empresa_fe',
    ];

    return $aliases[$plan] ?? 'empresa_fe';
}

function suscribetePlanesComerciales(): array
{
    static $planes = null;
    if (is_array($planes)) {
        return $planes;
    }

    $planes = [
        'gratis_fe' => [
            'codigo' => 'gratis_fe',
            'nombre' => 'Gratis FE',
            'precio' => 0,
            'precio_label' => 'Gs. 0',
            'descripcion' => 'Factura Electrónica gratis para empezar con límites claros de uso.',
            'badge' => 'Factura electrónica gratis',
            'resumen' => [
                '1 usuario, 1 sucursal y 1 caja',
                'Hasta 30 comprobantes por mes',
                'Hasta 100 productos y 100 clientes',
                'Stock básico y Powered by SistemaX',
            ],
            'apps' => [
                'venta_pos',
                'ventas',
                'productos',
                'contactos',
                'mi_caja',
                'habilitacion_sifen',
                'estado_fe',
            ],
            'limits' => [
                'max_usuarios' => 1,
                'max_sucursales' => 1,
                'max_cajas' => 1,
                'max_comprobantes_mes' => 30,
                'max_productos' => 100,
                'max_clientes' => 100,
                'max_proveedores' => 20,
                'stock_nivel' => 'basico',
                'cuentas_corrientes_nivel' => 'no',
                'reportes_nivel' => 'basico',
                'soporte_nivel' => 'limitado',
                'permite_factura_electronica' => 1,
                'permite_presupuestos' => 0,
                'permite_pedidos' => 0,
                'permite_impresion_directa' => 0,
                'permite_whatsapp_email' => 0,
                'permite_permisos_usuario' => 0,
                'permite_api_integraciones' => 0,
            ],
        ],
        'emprendedor_fe' => [
            'codigo' => 'emprendedor_fe',
            'nombre' => 'Emprendedor FE',
            'precio' => 99000,
            'precio_label' => 'Gs. 99.000',
            'descripcion' => 'Más capacidad para negocios que ya venden todos los días.',
            'badge' => 'Plan de arranque',
            'resumen' => [
                '2 usuarios y 2 cajas',
                'Hasta 300 comprobantes por mes',
                'Hasta 2.000 productos',
                'Presupuestos, pedidos, email y WhatsApp',
            ],
            'apps' => [
                'venta_pos',
                'ventas',
                'compras',
                'productos',
                'contactos',
                'mi_caja',
                'cuentas',
                'presupuesto_clientes',
                'pedido_proveedores',
                'habilitacion_sifen',
                'estado_fe',
            ],
            'limits' => [
                'max_usuarios' => 2,
                'max_sucursales' => 1,
                'max_cajas' => 2,
                'max_comprobantes_mes' => 300,
                'max_productos' => 2000,
                'max_clientes' => null,
                'max_proveedores' => null,
                'stock_nivel' => 'completo',
                'cuentas_corrientes_nivel' => 'basico',
                'reportes_nivel' => 'basico',
                'soporte_nivel' => 'normal',
                'permite_factura_electronica' => 1,
                'permite_presupuestos' => 1,
                'permite_pedidos' => 1,
                'permite_impresion_directa' => 1,
                'permite_whatsapp_email' => 1,
                'permite_permisos_usuario' => 0,
                'permite_api_integraciones' => 0,
            ],
        ],
        'pro_fe' => [
            'codigo' => 'pro_fe',
            'nombre' => 'Pro FE',
            'precio' => 199000,
            'precio_label' => 'Gs. 199.000',
            'descripcion' => 'Operación profesional con control, equipos y sucursales.',
            'badge' => 'Más recomendado',
            'resumen' => [
                '5 usuarios y hasta 3 sucursales',
                'Hasta 3.000 comprobantes por mes',
                'Productos ilimitados',
                'Cuentas corrientes, reportes y permisos',
            ],
            'apps' => [
                'venta_pos',
                'ventas',
                'compras',
                'productos',
                'contactos',
                'mi_caja',
                'cajas',
                'cuentas',
                'panel',
                'gastos',
                'sucursales',
                'presupuesto_clientes',
                'pedido_proveedores',
                'habilitacion_sifen',
                'estado_fe',
            ],
            'limits' => [
                'max_usuarios' => 5,
                'max_sucursales' => 3,
                'max_cajas' => null,
                'max_comprobantes_mes' => 3000,
                'max_productos' => null,
                'max_clientes' => null,
                'max_proveedores' => null,
                'stock_nivel' => 'completo',
                'cuentas_corrientes_nivel' => 'avanzado',
                'reportes_nivel' => 'avanzado',
                'soporte_nivel' => 'prioritario',
                'permite_factura_electronica' => 1,
                'permite_presupuestos' => 1,
                'permite_pedidos' => 1,
                'permite_impresion_directa' => 1,
                'permite_whatsapp_email' => 1,
                'permite_permisos_usuario' => 1,
                'permite_api_integraciones' => 0,
            ],
        ],
        'empresa_fe' => [
            'codigo' => 'empresa_fe',
            'nombre' => 'Empresa FE',
            'precio' => null,
            'precio_label' => 'A medida',
            'descripcion' => 'Modelo actual completo de SistemaX, con la potencia operativa total.',
            'badge' => 'Modelo actual completo',
            'resumen' => [
                'Usuarios y sucursales según necesidad',
                'Alto volumen o sin límite práctico',
                'Integraciones y automatizaciones',
                'Soporte prioritario y personalización',
            ],
            'apps' => [],
            'apps_mode' => 'business_type_full',
            'limits' => [
                'max_usuarios' => null,
                'max_sucursales' => null,
                'max_cajas' => null,
                'max_comprobantes_mes' => null,
                'max_productos' => null,
                'max_clientes' => null,
                'max_proveedores' => null,
                'stock_nivel' => 'completo',
                'cuentas_corrientes_nivel' => 'avanzado',
                'reportes_nivel' => 'avanzado',
                'soporte_nivel' => 'prioritario',
                'permite_factura_electronica' => 1,
                'permite_presupuestos' => 1,
                'permite_pedidos' => 1,
                'permite_impresion_directa' => 1,
                'permite_whatsapp_email' => 1,
                'permite_permisos_usuario' => 1,
                'permite_api_integraciones' => 1,
            ],
        ],
    ];

    return $planes;
}

function suscribetePlanDefinicion(string $codigo): array
{
    $planes = suscribetePlanesComerciales();
    $planCodigo = normalizarPlanSuscripcion($codigo);
    return $planes[$planCodigo] ?? $planes['empresa_fe'];
}

function planPermiteElegirTipoNegocio(string $planCodigo): bool
{
    return normalizarPlanSuscripcion($planCodigo) === 'empresa_fe';
}

function ensureEmpresaPlanConfigTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saas_empresa_plan_config (
            id INT NOT NULL AUTO_INCREMENT,
            id_empresa INT NOT NULL,
            plan_codigo VARCHAR(50) NOT NULL,
            plan_nombre VARCHAR(120) NOT NULL,
            precio_mensual DECIMAL(12,2) NULL,
            moneda VARCHAR(10) NOT NULL DEFAULT 'PYG',
            max_usuarios INT NULL,
            max_sucursales INT NULL,
            max_cajas INT NULL,
            max_comprobantes_mes INT NULL,
            max_productos INT NULL,
            max_clientes INT NULL,
            max_proveedores INT NULL,
            stock_nivel VARCHAR(20) NOT NULL DEFAULT 'basico',
            cuentas_corrientes_nivel VARCHAR(20) NOT NULL DEFAULT 'no',
            reportes_nivel VARCHAR(20) NOT NULL DEFAULT 'basico',
            soporte_nivel VARCHAR(20) NOT NULL DEFAULT 'limitado',
            permite_factura_electronica TINYINT(1) NOT NULL DEFAULT 0,
            permite_presupuestos TINYINT(1) NOT NULL DEFAULT 0,
            permite_pedidos TINYINT(1) NOT NULL DEFAULT 0,
            permite_impresion_directa TINYINT(1) NOT NULL DEFAULT 0,
            permite_whatsapp_email TINYINT(1) NOT NULL DEFAULT 0,
            permite_permisos_usuario TINYINT(1) NOT NULL DEFAULT 0,
            permite_api_integraciones TINYINT(1) NOT NULL DEFAULT 0,
            apps_habilitadas_json LONGTEXT NULL,
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_empresa_plan (id_empresa),
            KEY idx_plan_codigo (plan_codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function guardarConfiguracionPlanEmpresa(PDO $pdo, int $idEmpresa, string $planCodigo, string $tipoNegocio): void
{
    if ($idEmpresa <= 0) {
        return;
    }

    ensureEmpresaPlanConfigTable($pdo);

    $plan = suscribetePlanDefinicion($planCodigo);
    $limits = $plan['limits'] ?? [];
    $apps = array_values(array_unique(array_map('strval', $plan['apps'] ?? [])));
    $metadata = [
        'tipo_negocio' => trim($tipoNegocio),
        'apps_mode' => (string)($plan['apps_mode'] ?? 'explicit'),
        'precio_label' => (string)($plan['precio_label'] ?? ''),
        'descripcion' => (string)($plan['descripcion'] ?? ''),
        'resumen' => array_values(array_map('strval', $plan['resumen'] ?? [])),
    ];

    $stmt = $pdo->prepare("
        INSERT INTO saas_empresa_plan_config (
            id_empresa,
            plan_codigo,
            plan_nombre,
            precio_mensual,
            moneda,
            max_usuarios,
            max_sucursales,
            max_cajas,
            max_comprobantes_mes,
            max_productos,
            max_clientes,
            max_proveedores,
            stock_nivel,
            cuentas_corrientes_nivel,
            reportes_nivel,
            soporte_nivel,
            permite_factura_electronica,
            permite_presupuestos,
            permite_pedidos,
            permite_impresion_directa,
            permite_whatsapp_email,
            permite_permisos_usuario,
            permite_api_integraciones,
            apps_habilitadas_json,
            metadata_json
        ) VALUES (
            :id_empresa,
            :plan_codigo,
            :plan_nombre,
            :precio_mensual,
            'PYG',
            :max_usuarios,
            :max_sucursales,
            :max_cajas,
            :max_comprobantes_mes,
            :max_productos,
            :max_clientes,
            :max_proveedores,
            :stock_nivel,
            :cuentas_corrientes_nivel,
            :reportes_nivel,
            :soporte_nivel,
            :permite_factura_electronica,
            :permite_presupuestos,
            :permite_pedidos,
            :permite_impresion_directa,
            :permite_whatsapp_email,
            :permite_permisos_usuario,
            :permite_api_integraciones,
            :apps_habilitadas_json,
            :metadata_json
        )
        ON DUPLICATE KEY UPDATE
            plan_codigo = VALUES(plan_codigo),
            plan_nombre = VALUES(plan_nombre),
            precio_mensual = VALUES(precio_mensual),
            moneda = VALUES(moneda),
            max_usuarios = VALUES(max_usuarios),
            max_sucursales = VALUES(max_sucursales),
            max_cajas = VALUES(max_cajas),
            max_comprobantes_mes = VALUES(max_comprobantes_mes),
            max_productos = VALUES(max_productos),
            max_clientes = VALUES(max_clientes),
            max_proveedores = VALUES(max_proveedores),
            stock_nivel = VALUES(stock_nivel),
            cuentas_corrientes_nivel = VALUES(cuentas_corrientes_nivel),
            reportes_nivel = VALUES(reportes_nivel),
            soporte_nivel = VALUES(soporte_nivel),
            permite_factura_electronica = VALUES(permite_factura_electronica),
            permite_presupuestos = VALUES(permite_presupuestos),
            permite_pedidos = VALUES(permite_pedidos),
            permite_impresion_directa = VALUES(permite_impresion_directa),
            permite_whatsapp_email = VALUES(permite_whatsapp_email),
            permite_permisos_usuario = VALUES(permite_permisos_usuario),
            permite_api_integraciones = VALUES(permite_api_integraciones),
            apps_habilitadas_json = VALUES(apps_habilitadas_json),
            metadata_json = VALUES(metadata_json)
    ");

    $stmt->execute([
        ':id_empresa' => $idEmpresa,
        ':plan_codigo' => $plan['codigo'],
        ':plan_nombre' => $plan['nombre'],
        ':precio_mensual' => $plan['precio'],
        ':max_usuarios' => $limits['max_usuarios'],
        ':max_sucursales' => $limits['max_sucursales'],
        ':max_cajas' => $limits['max_cajas'],
        ':max_comprobantes_mes' => $limits['max_comprobantes_mes'],
        ':max_productos' => $limits['max_productos'],
        ':max_clientes' => $limits['max_clientes'],
        ':max_proveedores' => $limits['max_proveedores'],
        ':stock_nivel' => $limits['stock_nivel'] ?? 'basico',
        ':cuentas_corrientes_nivel' => $limits['cuentas_corrientes_nivel'] ?? 'no',
        ':reportes_nivel' => $limits['reportes_nivel'] ?? 'basico',
        ':soporte_nivel' => $limits['soporte_nivel'] ?? 'limitado',
        ':permite_factura_electronica' => (int)($limits['permite_factura_electronica'] ?? 0),
        ':permite_presupuestos' => (int)($limits['permite_presupuestos'] ?? 0),
        ':permite_pedidos' => (int)($limits['permite_pedidos'] ?? 0),
        ':permite_impresion_directa' => (int)($limits['permite_impresion_directa'] ?? 0),
        ':permite_whatsapp_email' => (int)($limits['permite_whatsapp_email'] ?? 0),
        ':permite_permisos_usuario' => (int)($limits['permite_permisos_usuario'] ?? 0),
        ':permite_api_integraciones' => (int)($limits['permite_api_integraciones'] ?? 0),
        ':apps_habilitadas_json' => json_encode($apps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function calcularDV(string $rucBase): string
{
    $ruc = preg_replace('/[^0-9]/', '', $rucBase);
    if ($ruc === '') {
        return '';
    }

    $k = 2;
    $total = 0;
    for ($i = strlen($ruc) - 1; $i >= 0; $i--) {
        $total += ((int)$ruc[$i]) * $k;
        $k++;
        if ($k > 11) {
            $k = 2;
        }
    }
    $resto = $total % 11;
    return (string)(($resto > 1) ? (11 - $resto) : 0);
}

function getBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function smtpReadResponse($socket): array
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    $code = (int)substr(trim($response), 0, 3);
    return [$code, trim($response)];
}

function smtpCommand($socket, string $command, array $expectedCodes): array
{
    fwrite($socket, $command . "\r\n");
    [$code, $response] = smtpReadResponse($socket);
    return [in_array($code, $expectedCodes, true), $code, $response];
}

function smtpSendMailHostinger(string $to, string $subject, string $html, string $fromEmail, string $replyTo): array
{
    $host = trim(envValue('SISTEMAX_SMTP_HOST', 'smtp.hostinger.com'));
    $port = (int)envValue('SISTEMAX_SMTP_PORT', '465');
    $username = trim(envValue('SISTEMAX_SMTP_USER', $fromEmail));
    $password = trim(envValue('SISTEMAX_SMTP_PASS', '@Armagedon?1023840?'));
    $timeout = 20;

    if ($password === '') {
        return ['success' => false, 'provider' => 'smtp', 'error' => 'Falta SISTEMAX_SMTP_PASS'];
    }

    $socket = @stream_socket_client(
        'ssl://' . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) {
        error_log('Suscribete SMTP connect error: ' . $errstr . ' (' . $errno . ')');
        return ['success' => false, 'provider' => 'smtp', 'error' => 'SMTP connect error: ' . $errstr . ' (' . $errno . ')'];
    }
    stream_set_timeout($socket, $timeout);

    [$code, $greeting] = smtpReadResponse($socket);
    if ($code !== 220) {
        fclose($socket);
        error_log('Suscribete SMTP greeting error: ' . $greeting);
        return ['success' => false, 'provider' => 'smtp', 'error' => 'SMTP greeting inválido: ' . $greeting];
    }

    [$okEhlo] = smtpCommand($socket, 'EHLO sistemax.pro', [250]);
    if (!$okEhlo) {
        fclose($socket);
        return ['success' => false, 'provider' => 'smtp', 'error' => 'SMTP EHLO rechazado'];
    }

    [$okAuth] = smtpCommand($socket, 'AUTH LOGIN', [334]);
    [$okUser] = smtpCommand($socket, base64_encode($username), [334]);
    [$okPass] = smtpCommand($socket, base64_encode($password), [235]);
    if (!$okAuth || !$okUser || !$okPass) {
        fclose($socket);
        error_log('Suscribete SMTP auth error');
        return ['success' => false, 'provider' => 'smtp', 'error' => 'SMTP auth login/clave rechazada'];
    }

    [$okFrom] = smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
    [$okRcpt] = smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    [$okData] = smtpCommand($socket, 'DATA', [354]);
    if (!$okFrom || !$okRcpt || !$okData) {
        fclose($socket);
        error_log('Suscribete SMTP envelope error');
        return ['success' => false, 'provider' => 'smtp', 'error' => 'SMTP envelope/data rechazado'];
    }

    $headers = [
        'Date: ' . date('r'),
        'From: Sistemax <' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Reply-To: ' . $replyTo,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $html . "\r\n.";
    fwrite($socket, $data . "\r\n");
    [$codeEnd] = smtpReadResponse($socket);
    smtpCommand($socket, 'QUIT', [221, 250]);
    fclose($socket);

    if ($codeEnd === 250) {
        return ['success' => true, 'provider' => 'smtp', 'error' => null];
    }
    return ['success' => false, 'provider' => 'smtp', 'error' => 'SMTP respuesta final: ' . $codeEnd];
}

function sendEmailWithFallback(string $to, string $subject, string $html, string $fromEmail, string $replyTo): array
{
    $smtp = smtpSendMailHostinger($to, $subject, $html, $fromEmail, $replyTo);
    if (!empty($smtp['success'])) {
        return ['success' => true, 'provider' => 'smtp', 'error' => null];
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Sistemax <' . $fromEmail . '>',
        'Reply-To: ' . $replyTo,
        'X-Mailer: PHP/' . phpversion(),
    ];
    $mailOk = @mail($to, $subject, $html, implode("\r\n", $headers));
    if ($mailOk) {
        return ['success' => true, 'provider' => 'mail', 'error' => null];
    }
    return [
        'success' => false,
        'provider' => 'mail',
        'error' => !empty($smtp['error']) ? $smtp['error'] . ' | fallback mail() falló' : 'fallback mail() falló',
    ];
}

function logMailAttempt(PDO $pdo, ?int $idSolicitud, string $tipo, string $destino, string $asunto, array $result): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO saas_solicitudes_mails
            (id_solicitud, tipo, destino, asunto, provider, estado, error_msg)
            VALUES
            (:id_solicitud, :tipo, :destino, :asunto, :provider, :estado, :error_msg)
        ");
        $stmt->execute([
            ':id_solicitud' => $idSolicitud,
            ':tipo' => substr($tipo, 0, 30),
            ':destino' => substr($destino, 0, 190),
            ':asunto' => substr($asunto, 0, 255),
            ':provider' => substr((string)($result['provider'] ?? 'smtp'), 0, 30),
            ':estado' => (!empty($result['success']) ? 'enviado' : 'error'),
            ':error_msg' => !empty($result['error']) ? substr((string)$result['error'], 0, 500) : null,
        ]);
    } catch (Exception $e) {
        error_log('Suscribete logMailAttempt error: ' . $e->getMessage());
    }
}

function sendSolicitudToSupportMail(array $solicitud): array
{
    $fromEmail = trim(envValue('SISTEMAX_SUPPORT_EMAIL', 'soporte@sistemax.pro'));
    $replyTo = trim((string)($solicitud['email'] ?? $fromEmail));
    $supportTo = trim(envValue('SISTEMAX_SUPPORT_EMAIL', 'soporte@sistemax.pro'));
    $subject = 'Nueva solicitud de suscripción - ' . (string)($solicitud['empresa'] ?? 'SistemaX.Pro');
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#0f172a">'
        . '<h2 style="margin:0 0 12px">Nueva solicitud recibida</h2>'
        . '<p><strong>Empresa:</strong> ' . esc($solicitud['empresa'] ?? '') . '</p>'
        . '<p><strong>Contacto:</strong> ' . esc($solicitud['contacto'] ?? '') . '</p>'
        . '<p><strong>Email:</strong> ' . esc($solicitud['email'] ?? '') . '</p>'
        . '<p><strong>Teléfono:</strong> ' . esc($solicitud['telefono'] ?? '') . '</p>'
        . '<p><strong>RUC:</strong> ' . esc($solicitud['ruc'] ?? '') . '</p>'
        . '<p><strong>Mensaje:</strong><br>' . nl2br(esc($solicitud['mensaje'] ?? '')) . '</p>'
        . '<hr style="margin:16px 0;border:none;border-top:1px solid #e2e8f0">'
        . '<p><strong>Link de registro admin:</strong><br><a href="' . esc($solicitud['link'] ?? '') . '">' . esc($solicitud['link'] ?? '') . '</a></p>'
        . '</body></html>';

    return sendEmailWithFallback($supportTo, $subject, $html, $fromEmail, $replyTo);
}

function sendSolicitanteAutoReplyMail(string $to, string $link): array
{
    $fromEmail = trim(envValue('SISTEMAX_SUPPORT_EMAIL', 'soporte@sistemax.pro'));
    $replyTo = trim(envValue('SISTEMAX_SUPPORT_EMAIL', 'soporte@sistemax.pro'));
    $subject = 'SistemaX.Pro - Continuación de activación';
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#0f172a;line-height:1.6">'
        . '<p>Estimado/a,</p>'
        . '<p>Gracias por suscribirse a SistemaX.Pro</p>'
        . '<p>Nos complace darle la bienvenida y confirmar que hemos recibido correctamente su solicitud de suscripción.</p>'
        . '<p>Para continuar con el proceso de activación, le solicitamos completar el siguiente paso inicial:</p>'
        . '<p>👉 <strong>Crear el usuario Administrador de su empresa</strong></p>'
        . '<p>Acceda al enlace seguro a continuación para registrar el usuario administrador principal de su organización:</p>'
        . '<p>🔗 <strong>Crear usuario Administrador</strong><br>'
        . '<a href="' . esc($link) . '">' . esc($link) . '</a></p>'
        . '<p>{{LINK_CREACION_ADMIN}}<br>' . esc($link) . '</p>'
        . '</body></html>';

    return sendEmailWithFallback($to, $subject, $html, $fromEmail, $replyTo);
}

function normalizeWhatsappToE164(string $raw, string $defaultCountry = '+595'): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $defaultDigits = preg_replace('/\D+/', '', $defaultCountry);
    if ($defaultDigits === '') {
        $defaultDigits = '595';
    }

    if (strpos($raw, '+') === 0) {
        return preg_replace('/\D+/', '', $raw);
    }

    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '00') === 0) {
        return substr($digits, 2);
    }
    if ($digits[0] === '0') {
        return $defaultDigits . ltrim($digits, '0');
    }
    if (strpos($digits, $defaultDigits) === 0) {
        return $digits;
    }
    if (strlen($digits) <= 10) {
        return $defaultDigits . $digits;
    }
    return $digits;
}

function sendWhatsAppViaEndpoint(string $toE164, string $message): array
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'provider' => 'whatsapp-endpoint', 'error' => 'cURL no disponible en PHP'];
    }
    $endpoint = trim(envValue('SISTEMAX_WHATSAPP_ENDPOINT', ''));
    if ($endpoint === '') {
        return ['success' => false, 'provider' => 'whatsapp-endpoint', 'error' => 'SISTEMAX_WHATSAPP_ENDPOINT no configurado'];
    }

    $token = trim(envValue('SISTEMAX_WHATSAPP_AUTH_BEARER', ''));
    $payload = json_encode([
        'to' => $toE164,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['success' => false, 'provider' => 'whatsapp-endpoint', 'error' => 'No se pudo serializar payload'];
    }

    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 25,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['success' => false, 'provider' => 'whatsapp-endpoint', 'error' => ('curl error: ' . $err)];
    }
    if ($http < 200 || $http >= 300) {
        return ['success' => false, 'provider' => 'whatsapp-endpoint', 'error' => ('HTTP ' . $http . ': ' . substr((string)$resp, 0, 300))];
    }
    return ['success' => true, 'provider' => 'whatsapp-endpoint', 'error' => null];
}

function sendWhatsAppViaMeta(string $toE164, string $message): array
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => 'cURL no disponible en PHP'];
    }
    $token = trim(envValue('SISTEMAX_WHATSAPP_TOKEN', ''));
    $phoneNumberId = trim(envValue('SISTEMAX_WHATSAPP_PHONE_NUMBER_ID', ''));
    $version = trim(envValue('SISTEMAX_WHATSAPP_GRAPH_VERSION', 'v21.0'));
    if ($token === '' || $phoneNumberId === '') {
        return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => 'Faltan SISTEMAX_WHATSAPP_TOKEN o SISTEMAX_WHATSAPP_PHONE_NUMBER_ID'];
    }

    $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $toE164,
        'type' => 'text',
        'text' => [
            'preview_url' => true,
            'body' => $message,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => 'No se pudo serializar payload'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 25,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => ('curl error: ' . $err)];
    }
    $data = json_decode((string)$resp, true);
    if ($http < 200 || $http >= 300) {
        $metaErr = is_array($data) ? ($data['error']['message'] ?? '') : '';
        $msg = $metaErr !== '' ? $metaErr : substr((string)$resp, 0, 300);
        return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => ('HTTP ' . $http . ': ' . $msg)];
    }
    $messageId = is_array($data) ? ($data['messages'][0]['id'] ?? '') : '';
    if ($messageId === '') {
        return ['success' => false, 'provider' => 'meta-whatsapp', 'error' => 'Respuesta sin message_id'];
    }
    return ['success' => true, 'provider' => 'meta-whatsapp', 'error' => null];
}

function sendWhatsAppMessage(string $toRaw, string $message, string $defaultCountry = '+595'): array
{
    $enabled = strtolower(trim(envValue('SISTEMAX_WHATSAPP_ENABLED', '1')));
    if (in_array($enabled, ['0', 'false', 'off', 'no'], true)) {
        return ['success' => false, 'provider' => 'whatsapp', 'error' => 'WhatsApp deshabilitado por configuración'];
    }
    $toE164 = normalizeWhatsappToE164($toRaw, $defaultCountry);
    if ($toE164 === '' || strlen($toE164) < 10) {
        return ['success' => false, 'provider' => 'whatsapp', 'error' => 'Número destino inválido'];
    }

    $endpoint = trim(envValue('SISTEMAX_WHATSAPP_ENDPOINT', ''));
    if ($endpoint !== '') {
        return sendWhatsAppViaEndpoint($toE164, $message);
    }
    return sendWhatsAppViaMeta($toE164, $message);
}

function consultarRucSifen(string $rucRaw): array
{
    $rucSoloNum = preg_replace('/[^0-9]/', '', $rucRaw);
    if (strlen($rucSoloNum) < 5) {
        return ['success' => false, 'error' => 'RUC inválido'];
    }

    $baseDir = __DIR__;
    $certPassFijo = trim(envValue('SISTEMAX_SIFEN_CERT_PASS', '3nvcEcwW'));
    $certNames = [];
    $envCert = trim(envValue('SISTEMAX_SIFEN_LOOKUP_CERT', ''));
    if ($envCert !== '') {
        $certNames[] = $envCert;
    }
    $certNames = array_values(array_unique(array_merge(
        $certNames,
        ['80118689.p12', 'sistemax_eas.p12', 'FABIOAUGUSTOVALDEZFRANCO.p12', 'FABIOAUGUSTOVALDEZFRANCO_20260213_174040.p12']
    )));

    $searchDirs = [
        $baseDir . '/_lib/php-sifen3-custom/certificados/',
        $baseDir . '/_lib/php-sifen3/certificados/',
        $baseDir . '/_lib/sifen/certificados/',
        $baseDir . '/_lib/certificados/',
    ];

    $certPath = '';
    $certs = [];
    foreach ($searchDirs as $dir) {
        foreach ($certNames as $name) {
            $cand = $dir . $name;
            if (!file_exists($cand) || filesize($cand) <= 0) {
                continue;
            }
            $pkcs12Content = (string)@file_get_contents($cand);
            if ($pkcs12Content !== '' && openssl_pkcs12_read($pkcs12Content, $tmpCerts, $certPassFijo)) {
                $certPath = $cand;
                $certs = $tmpCerts;
                break 2;
            }
        }
    }

    if ($certPath === '') {
        return ['success' => false, 'error' => 'No se encontró certificado SIFEN válido (archivo faltante/vacío o clave incorrecta)'];
    }

    $pemContent = $certs['pkey'] . "\n" . $certs['cert'] . "\n";
    if (!empty($certs['extracerts']) && is_array($certs['extracerts'])) {
        foreach ($certs['extracerts'] as $extra) {
            $pemContent .= $extra . "\n";
        }
    }

    $pemTmpPath = sys_get_temp_dir() . '/sifen_lookup_' . uniqid('', true) . '.pem';
    file_put_contents($pemTmpPath, $pemContent);

    $soapXml = '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
  <soap:Body>
    <xsd:rEnviConsRUC>
      <xsd:dId>1</xsd:dId>
      <xsd:dRUCCons>' . htmlspecialchars($rucSoloNum, ENT_QUOTES, 'UTF-8') . '</xsd:dRUCCons>
    </xsd:rEnviConsRUC>
  </soap:Body>
</soap:Envelope>';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://sifen.set.gov.py/de/ws/consultas/consulta-ruc.wsdl',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $soapXml,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/soap+xml; charset=utf-8',
            'SOAPAction: ""',
        ],
        CURLOPT_SSLCERT => $pemTmpPath,
        CURLOPT_SSLCERTPASSWD => '',
        CURLOPT_SSLKEY => $pemTmpPath,
        CURLOPT_SSLKEYPASSWD => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($pemTmpPath);

    if ($curlError) {
        return ['success' => false, 'error' => 'Error de conexión con SIFEN'];
    }
    if ($httpCode !== 200 || empty($response)) {
        return ['success' => false, 'error' => 'SIFEN no respondió correctamente'];
    }

    try {
        $cleanXml = preg_replace('/<(\/?)\w+:(\w+)/', '<$1$2', (string)$response);
        $xmlRes = new SimpleXMLElement((string)$cleanXml);
        $dCodRes = (string)($xmlRes->xpath('//dCodRes')[0] ?? '');

        if ($dCodRes !== '0502' && $dCodRes !== '0260') {
            $dMsgRes = (string)($xmlRes->xpath('//dMsgRes')[0] ?? 'RUC no encontrado en SIFEN');
            return ['success' => false, 'error' => $dMsgRes];
        }

        $xContr = $xmlRes->xpath('//xContRUC')[0] ?? $xmlRes->xpath('//xContr')[0] ?? null;
        if (!$xContr) {
            return ['success' => false, 'error' => 'Respuesta inválida de SIFEN'];
        }

        $foundNombre = trim((string)($xContr->dRazCons ?? $xContr->dRazSoc ?? ''));
        $foundRucBase = trim((string)($xContr->dRUCCons ?? $xContr->dRUC ?? ''));
        $foundDV = calcularDV($foundRucBase);

        if ($foundNombre === '' || $foundRucBase === '') {
            return ['success' => false, 'error' => 'No se pudo validar el RUC en SIFEN'];
        }

        return [
            'success' => true,
            'data' => [
                'razon_social' => $foundNombre,
                'ruc_base' => $foundRucBase,
                'dv' => $foundDV,
                'ruc' => $foundRucBase . '-' . $foundDV,
            ],
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Error procesando respuesta de SIFEN'];
    }
}

$action = trim((string)($_GET['action'] ?? ''));
if ($action === 'sifen_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rucIn = trim((string)($_GET['ruc'] ?? ''));
        if ($rucIn === '') {
            echo json_encode(['success' => false, 'error' => 'RUC requerido']);
            exit;
        }
        $lookup = consultarRucSifen($rucIn);
        if (!$lookup['success']) {
            echo json_encode(['success' => false, 'error' => $lookup['error'] ?? 'RUC no encontrado en SIFEN']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'data' => $lookup['data']
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error consultando SIFEN']);
    }
    exit;
}

if ($action === 'admin_login_check') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $loginRaw = trim((string)($_GET['login'] ?? ''));
        $login = normalizarLoginAdmin($loginRaw, 'admin');
        if ($loginRaw === '' || $login === '') {
            echo json_encode(['success' => false, 'error' => 'Usuario requerido']);
            exit;
        }
        if (!preg_match('/^[a-z0-9._-]{4,32}$/', $login)) {
            echo json_encode(['success' => false, 'error' => 'Usuario inválido']);
            exit;
        }

        $pdo = Database::getMasterConnection();
        $stmt = $pdo->prepare("SELECT id_login, id_empresa, name FROM sec_users WHERE login = :login LIMIT 1");
        $stmt->execute([':login' => $login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        echo json_encode([
            'success' => true,
            'login' => $login,
            'exists' => (bool)$row,
            'data' => $row ? [
                'id_login' => (int)($row['id_login'] ?? 0),
                'id_empresa' => (int)($row['id_empresa'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
            ] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Error validando usuario admin']);
    }
    exit;
}

function normalizarRucBase(string $ruc): string
{
    return preg_replace('/[^0-9]/', '', $ruc);
}

function normalizarRucConDvLocal(string $rucRaw): string
{
    $rucRaw = trim($rucRaw);
    if ($rucRaw === '') {
        return '';
    }

    if (strpos($rucRaw, '-') !== false) {
        [$basePart, $dvPart] = array_pad(explode('-', $rucRaw, 2), 2, '');
        $base = preg_replace('/[^0-9]/', '', $basePart);
        $dv = preg_replace('/[^0-9]/', '', $dvPart);
        if ($base === '') {
            return '';
        }
        if ($dv === '') {
            $dv = calcularDV($base);
        }
        return $base . '-' . substr($dv, 0, 1);
    }

    $base = preg_replace('/[^0-9]/', '', $rucRaw);
    if ($base === '') {
        return '';
    }
    return $base . '-' . calcularDV($base);
}

function phoneCountries(): array
{
    return [
        ['code' => '+595', 'flag' => '🇵🇾', 'name' => 'Paraguay'],
        ['code' => '+54', 'flag' => '🇦🇷', 'name' => 'Argentina'],
        ['code' => '+55', 'flag' => '🇧🇷', 'name' => 'Brasil'],
        ['code' => '+598', 'flag' => '🇺🇾', 'name' => 'Uruguay'],
        ['code' => '+56', 'flag' => '🇨🇱', 'name' => 'Chile'],
        ['code' => '+34', 'flag' => '🇪🇸', 'name' => 'España'],
        ['code' => '+1', 'flag' => '🇺🇸', 'name' => 'USA/Canadá'],
    ];
}

function tiposNegocioBase(): array
{
    return [
        ['nombre' => 'Comercial', 'disponible' => 1],
        ['nombre' => 'Estación de Servicio', 'disponible' => 1],
        ['nombre' => 'Taller Mecánico', 'disponible' => 1],
        ['nombre' => 'Gastronomía', 'disponible' => 1],
        ['nombre' => 'Farmacia', 'disponible' => 1],
        ['nombre' => 'Ferretería', 'disponible' => 1],
        ['nombre' => 'Supermercado', 'disponible' => 1],
        ['nombre' => 'Distribuidora', 'disponible' => 1],
        ['nombre' => 'Servicios', 'disponible' => 1],
        ['nombre' => 'E-commerce', 'disponible' => 1],
    ];
}

function obtenerTiposNegocioDisponibles(PDO $pdo): array
{
    $fallback = tiposNegocioBase();
    try {
        $hasDisponible = false;
        try {
            $stCol = $pdo->query("SHOW COLUMNS FROM tipo_negocio LIKE 'disponible'");
            $hasDisponible = (bool)($stCol && $stCol->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $hasDisponible = false;
        }
        $stmt = $pdo->query("
            SELECT nombre, " . ($hasDisponible ? "COALESCE(disponible, 1)" : "1") . " AS disponible
            FROM tipo_negocio
            WHERE activo = 1
            ORDER BY orden ASC, nombre ASC
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $items = [];
        foreach ($rows as $row) {
            $nombre = trim((string)($row['nombre'] ?? ''));
            if ($nombre !== '') {
                $items[] = [
                    'nombre' => $nombre,
                    'disponible' => (int)($row['disponible'] ?? 1) === 1 ? 1 : 0,
                ];
            }
        }
        if (!empty($items)) {
            return $items;
        }
    } catch (Throwable $e) {
        // Fallback local si tabla no existe en este entorno.
    }
    return $fallback;
}

function sanitizarTipoNegocio(string $tipo, array $tiposDisponibles): string
{
    $tipo = trim($tipo);
    $nombres = [];
    foreach ($tiposDisponibles as $t) {
        $nombre = trim((string)($t['nombre'] ?? ''));
        if ($nombre !== '') {
            $nombres[] = $nombre;
        }
    }
    if (empty($nombres)) {
        return ($tipo !== '' ? $tipo : 'Comercial');
    }
    if ($tipo !== '' && in_array($tipo, $nombres, true)) {
        return $tipo;
    }
    return $nombres[0];
}

function splitPhone(string $telefono): array
{
    $raw = trim($telefono);
    if ($raw === '') {
        return ['country' => '+595', 'number' => ''];
    }
    $countries = phoneCountries();
    foreach ($countries as $c) {
        if (strpos($raw, $c['code']) === 0) {
            $number = trim(substr($raw, strlen($c['code'])));
            return ['country' => $c['code'], 'number' => preg_replace('/[^0-9]/', '', $number)];
        }
    }
    return ['country' => '+595', 'number' => preg_replace('/[^0-9]/', '', $raw)];
}

function buildPhone(string $country, string $number): string
{
    $countryClean = trim($country);
    $numberClean = preg_replace('/[^0-9]/', '', $number);
    if ($numberClean === '') {
        return '';
    }
    if ($countryClean === '') {
        $countryClean = '+595';
    }
    return $countryClean . ' ' . $numberClean;
}

function procesarLogoEmpresaUpload(array $file): array
{
    if (empty($file) || !isset($file['error'])) {
        return ['ok' => true, 'filename' => '', 'error' => ''];
    }
    if ((int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'filename' => '', 'error' => ''];
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'filename' => '', 'error' => 'No se pudo subir el logo.'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'filename' => '', 'error' => 'Archivo de logo inválido.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 4 * 1024 * 1024) {
        return ['ok' => false, 'filename' => '', 'error' => 'El logo debe pesar hasta 4MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)$finfo->file($tmp));
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $ext = $allowed[$mime] ?? '';
    if ($ext === '') {
        return ['ok' => false, 'filename' => '', 'error' => 'Formato de logo no permitido. Use JPG, PNG, WEBP o GIF.'];
    }

    $dir = __DIR__ . '/_lib/file/img/empresa';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'filename' => '', 'error' => 'No se pudo crear carpeta de logos.'];
    }

    $name = 'empresa_logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!@move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'filename' => '', 'error' => 'No se pudo guardar el logo.'];
    }
    @chmod($dest, 0644);

    return ['ok' => true, 'filename' => $name, 'error' => ''];
}

function smxLogoAllowedMimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
}

function smxLogoStorageDir(): string
{
    return __DIR__ . '/_lib/file/img/empresa';
}

function smxGuardarLogoEmpresaContenido(string $bytes, string $mime): array
{
    $allowed = smxLogoAllowedMimes();
    $mime = strtolower(trim($mime));
    $ext = $allowed[$mime] ?? '';
    if ($ext === '') {
        return ['ok' => false, 'filename' => '', 'error' => 'Formato de logo no permitido.'];
    }

    $dir = smxLogoStorageDir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'filename' => '', 'error' => 'No se pudo crear carpeta de logos.'];
    }

    $name = 'empresa_logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (@file_put_contents($dest, $bytes, LOCK_EX) === false) {
        return ['ok' => false, 'filename' => '', 'error' => 'No se pudo guardar el logo descargado.'];
    }
    @chmod($dest, 0644);

    return ['ok' => true, 'filename' => $name, 'error' => ''];
}

function procesarLogoEmpresaUrl(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return ['ok' => true, 'filename' => '', 'error' => ''];
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'filename' => '', 'error' => 'La URL del logo no es válida.'];
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'filename' => '', 'error' => 'La URL del logo debe usar http o https.'];
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'follow_location' => 1,
            'max_redirects' => 3,
            'user_agent' => 'SistemaX Logo Fetcher/1.0',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $bytes = @file_get_contents($url, false, $context);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'filename' => '', 'error' => 'No se pudo descargar el logo desde la URL indicada.'];
    }

    if (strlen($bytes) > 4 * 1024 * 1024) {
        return ['ok' => false, 'filename' => '', 'error' => 'La imagen del logo supera el límite de 4MB.'];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'smx_logo_');
    if ($tmp === false) {
        return ['ok' => false, 'filename' => '', 'error' => 'No se pudo preparar la descarga del logo.'];
    }
    @file_put_contents($tmp, $bytes, LOCK_EX);

    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$finfo->file($tmp));
    } finally {
        @unlink($tmp);
    }

    return smxGuardarLogoEmpresaContenido($bytes, $mime);
}

function validateWhatsappNumber(string $country, string $number): array
{
    $numberClean = preg_replace('/[^0-9]/', '', $number);
    if ($numberClean === '') {
        return [false, 'El número de WhatsApp es obligatorio.'];
    }
    if (preg_match('/^(\d)\1+$/', $numberClean)) {
        return [false, 'El número de WhatsApp no es válido.'];
    }

    $rules = [
        '+595' => ['min' => 9, 'max' => 9, 'pattern' => '/^9[0-9]{8}$/', 'msg' => 'WhatsApp de Paraguay: 9 dígitos, inicia con 9.'],
        '+54'  => ['min' => 10, 'max' => 10],
        '+55'  => ['min' => 10, 'max' => 11],
        '+598' => ['min' => 8, 'max' => 9],
        '+56'  => ['min' => 9, 'max' => 9],
        '+34'  => ['min' => 9, 'max' => 9],
        '+1'   => ['min' => 10, 'max' => 10],
    ];

    $rule = $rules[trim($country)] ?? ['min' => 8, 'max' => 15];
    $len = strlen($numberClean);
    if ($len < (int)$rule['min'] || $len > (int)$rule['max']) {
        return [false, 'El número de WhatsApp no tiene un formato válido para el país seleccionado.'];
    }
    if (!empty($rule['pattern']) && !preg_match((string)$rule['pattern'], $numberClean)) {
        return [false, (string)($rule['msg'] ?? 'El número de WhatsApp no es válido.')];
    }

    return [true, ''];
}

function buscarEmpresaPorRucDetalle(PDO $pdo, string $rucBase): ?array
{
    if ($rucBase === '') {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT id_empresa, ruc, empresa
        FROM " . MASTER_DB . ".empresa
        WHERE REPLACE(REPLACE(REPLACE(TRIM(COALESCE(ruc, '')), '.', ''), '-', ''), ' ', '') = :ruc
        ORDER BY id_empresa DESC
        LIMIT 1
    ");
    $stmt->execute([':ruc' => $rucBase]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function buscarEmpresaPorRuc(PDO $pdo, string $rucBase): int
{
    $row = buscarEmpresaPorRucDetalle($pdo, $rucBase);
    return (int)($row['id_empresa'] ?? 0);
}

function existeEmpresaDuplicada(PDO $pdo, string $rucNormalizado, string $email): array
{
    $rucBase = normalizarRucBase($rucNormalizado);
    $emailNorm = mb_strtolower(trim($email), 'UTF-8');

    $dupRuc = false;
    if ($rucBase !== '') {
        $stmtRuc = $pdo->prepare("
            SELECT id_empresa
            FROM " . MASTER_DB . ".empresa
            WHERE REPLACE(REPLACE(ruc, '.', ''), '-', '') = :ruc
            LIMIT 1
        ");
        $stmtRuc->execute([':ruc' => $rucBase]);
        $dupRuc = (bool)$stmtRuc->fetchColumn();
    }

    $dupEmail = false;
    if ($emailNorm !== '') {
        $stmtEmail = $pdo->prepare("
            SELECT id_empresa
            FROM " . MASTER_DB . ".empresa
            WHERE LOWER(TRIM(email)) = :email
            LIMIT 1
        ");
        $stmtEmail->execute([':email' => $emailNorm]);
        $dupEmail = (bool)$stmtEmail->fetchColumn();
    }

    return ['dup_ruc' => $dupRuc, 'dup_email' => $dupEmail];
}

function crearEmpresaDesdeSuscribete(PDO $pdo, array $data): int
{
    $colsStmt = $pdo->query("SHOW COLUMNS FROM " . MASTER_DB . ".empresa");
    $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!$cols) {
        throw new Exception('No se pudo leer estructura de empresa');
    }

    $rucNormalizado = (string)($data['ruc'] ?? '');
    $rucBase = normalizarRucBase($rucNormalizado);
    if ($rucBase === '') {
        throw new Exception('RUC inválido');
    }

    $dv = calcularDV($rucBase);
    $rucConDv = $rucBase . '-' . $dv;
    $tipoNegocio = trim((string)($data['tipo_negocio'] ?? 'Comercial'));
    if ($tipoNegocio === '') {
        $tipoNegocio = 'Comercial';
    }

    $baseData = [
        'empresa' => substr((string)($data['empresa'] ?? ''), 0, 50),
        'nombre_fantasia' => substr((string)($data['empresa'] ?? ''), 0, 255),
        'ruc' => substr($rucConDv, 0, 15),
        'dv' => (int)$dv,
        'email' => substr((string)($data['email'] ?? ''), 0, 100),
        'telefono' => substr((string)($data['telefono'] ?? ''), 0, 30),
        'dbase' => '',
        'direccion' => substr((string)($data['direccion'] ?? 'Asuncion'), 0, 255),
        'ciudad' => substr((string)($data['ciudad'] ?? 'Asuncion'), 0, 100),
        'pais' => substr((string)($data['pais'] ?? 'Paraguay'), 0, 100),
        'server' => 'localhost',
        'password' => 'Armagedon123',
        'numero_casa' => '123',
        'moneda_principal' => 1,
        'estado' => 'ACTIVO',
        'active' => 'Y',
        'activo' => 1,
        'software' => 1,
        'id_grupo' => 1,
        'logos' => substr((string)($data['logos'] ?? ''), 0, 255),
        'fondo' => '',
        'rubro' => substr($tipoNegocio, 0, 120),
        'tipo_negocio' => substr($tipoNegocio, 0, 120),
        'negocio' => substr($tipoNegocio, 0, 120),
        'giro' => substr($tipoNegocio, 0, 120),
        'factura_electronica' => 0,
        'fe' => 0,
    ];

    $insertData = [];
    foreach ($cols as $col) {
        $field = (string)$col['Field'];
        $type = strtolower((string)$col['Type']);
        $nullable = ((string)$col['Null']) === 'YES';
        $default = $col['Default'];
        $extra = strtolower((string)$col['Extra']);
        if (strpos($extra, 'auto_increment') !== false) {
            continue;
        }

        if (array_key_exists($field, $baseData)) {
            $insertData[$field] = $baseData[$field];
            continue;
        }
        if ($default !== null) {
            continue;
        }
        if ($nullable) {
            $insertData[$field] = null;
            continue;
        }

        if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
            $insertData[$field] = 0;
        } elseif (strpos($type, 'date') === 0) {
            $insertData[$field] = date('Y-m-d');
        } elseif (strpos($type, 'datetime') === 0 || strpos($type, 'timestamp') === 0) {
            $insertData[$field] = date('Y-m-d H:i:s');
        } elseif (strpos($type, 'time') === 0) {
            $insertData[$field] = '00:00:00';
        } else {
            $insertData[$field] = '';
        }
    }

    $columns = array_keys($insertData);
    $quotedColumns = array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $columns);
    $placeholders = array_map(static fn($c) => ':' . $c, $columns);
    $sql = "INSERT INTO " . MASTER_DB . ".empresa (" . implode(',', $quotedColumns) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    foreach ($insertData as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->execute();
    $idEmpresa = (int)$pdo->lastInsertId();
    if ($idEmpresa <= 0) {
        throw new Exception('No se pudo crear la empresa');
    }

    SuscripcionController::asegurarSuscripcionEmpresa($idEmpresa, [
        'created_by' => (int)($_SESSION['id_login'] ?? 0),
    ]);

    $dbase = 'empresa_' . $idEmpresa;
    $upd = $pdo->prepare("UPDATE " . MASTER_DB . ".empresa SET dbase = :db, id_grupo = :ig WHERE id_empresa = :id");
    $upd->execute([
        ':db' => $dbase,
        ':ig' => $idEmpresa,
        ':id' => $idEmpresa
    ]);

    return $idEmpresa;
}

function resolverDbSourceDisponible(PDO $pdo, int $software = 1): string
{
    $candidatos = ($software === 2)
        ? ['flota_ovetense', 'tienda_169', 'empresa_169']
        : ['tienda_169', 'empresa_169', 'flota_ovetense'];

    foreach ($candidatos as $dbName) {
        $stmt = $pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($dbName));
        if ($stmt && $stmt->fetchColumn()) {
            return $dbName;
        }
    }
    return '';
}

function crearEstructuraEmpresa(PDO $pdo, int $idEmpresa, int $software = 1): void
{
    $dbName = 'empresa_' . $idEmpresa;
    $source = resolverDbSourceDisponible($pdo, $software);
    if ($source === '') {
        throw new Exception('No se encontró base plantilla para crear empresa');
    }

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($source) . " AND TABLE_TYPE = 'BASE TABLE'");
    $tablas = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    foreach ($tablas as $tabla) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbName}`.`{$tabla}` LIKE `{$source}`.`{$tabla}`");
    }
}

function tablaEmpresaExiste(PDO $pdo, string $dbName, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = :db
          AND TABLE_NAME = :tb
        LIMIT 1
    ");
    $stmt->execute([':db' => $dbName, ':tb' => $table]);
    return (bool)$stmt->fetchColumn();
}

function nextTableId(PDO $pdo, string $dbName, string $table, string $idColumn): int
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(`{$idColumn}`), 0) + 1 FROM `{$dbName}`.`{$table}`");
    return (int)($stmt ? $stmt->fetchColumn() : 1);
}

function seedEmpresaDatosIniciales(PDO $pdo, int $idEmpresa): void
{
    if ($idEmpresa <= 0) {
        return;
    }
    $dbName = 'empresa_' . $idEmpresa;
    try {
        if (!tablaEmpresaExiste($pdo, $dbName, 'sucursales')) {
            return;
        }

        // 1) Sucursal principal
        $stmtSuc = $pdo->prepare("SELECT id_sucursal FROM `{$dbName}`.sucursales WHERE LOWER(TRIM(sucursal)) = 'casa central' LIMIT 1");
        $stmtSuc->execute();
        $idSucursal = (int)$stmtSuc->fetchColumn();
        if ($idSucursal <= 0) {
            $idSucursal = nextTableId($pdo, $dbName, 'sucursales', 'id_sucursal');
            $insSuc = $pdo->prepare("
                INSERT INTO `{$dbName}`.sucursales
                (id_sucursal, sucursal, pais, ciudad, direccion, telefono)
                VALUES
                (:id, 'CASA CENTRAL', 'PARAGUAY', 'ASUNCION', '', '')
            ");
            $insSuc->execute([':id' => $idSucursal]);
        }

        // 2) Monedas base
        if (tablaEmpresaExiste($pdo, $dbName, 'monedas')) {
            $monedas = [
                ['id' => 1, 'moneda' => 'GUARANI', 'compra' => 1, 'venta' => 1, 'simbolo' => 'Gs', 'p_decimal' => 0],
                ['id' => 2, 'moneda' => 'REAL', 'compra' => 1400, 'venta' => 1500, 'simbolo' => 'R$', 'p_decimal' => 2],
                ['id' => 3, 'moneda' => 'DOLAR', 'compra' => 7200, 'venta' => 7400, 'simbolo' => 'US$', 'p_decimal' => 2],
            ];
            foreach ($monedas as $m) {
                $stmtMon = $pdo->prepare("SELECT id_moneda FROM `{$dbName}`.monedas WHERE id_moneda = :id LIMIT 1");
                $stmtMon->execute([':id' => $m['id']]);
                if ($stmtMon->fetchColumn()) {
                    continue;
                }
                $insMon = $pdo->prepare("
                    INSERT INTO `{$dbName}`.monedas
                    (id_moneda, id_sucursal, moneda, compra, venta, simbolo, p_decimal)
                    VALUES
                    (:id, :id_sucursal, :moneda, :compra, :venta, :simbolo, :p_decimal)
                ");
                $insMon->execute([
                    ':id' => $m['id'],
                    ':id_sucursal' => $idSucursal,
                    ':moneda' => $m['moneda'],
                    ':compra' => $m['compra'],
                    ':venta' => $m['venta'],
                    ':simbolo' => $m['simbolo'],
                    ':p_decimal' => $m['p_decimal'],
                ]);
            }
        }

        // 3) Caja principal + métodos de cobro
        if (tablaEmpresaExiste($pdo, $dbName, 'cajas')) {
            $stmtCaja = $pdo->prepare("SELECT id_caja FROM `{$dbName}`.cajas WHERE LOWER(TRIM(caja)) = 'caja principal' LIMIT 1");
            $stmtCaja->execute();
            $idCaja = (int)$stmtCaja->fetchColumn();
            if ($idCaja <= 0) {
                $idCaja = nextTableId($pdo, $dbName, 'cajas', 'id_caja');
                $insCaja = $pdo->prepare("
                    INSERT INTO `{$dbName}`.cajas
                    (id_empresa, id_caja, caja, tipo, id_sucursal, id_moneda, saldo_maximo, timbrado, vencimiento, factura_1, factura_2, factura_3, factura_legal, factura_comun, ticket_comun, ticket_legal, moneda_df, tipo_doc_df, forma_pago_df, tipo_precio_df, tipo_venta_df, id_cli_df, cobro_df, metodo_cobro_permitido)
                    VALUES
                    (:id_empresa, :id_caja, 'CAJA PRINCIPAL', 1, :id_sucursal, 1, 50000000.00, '0', CURDATE(), 1, 1, 1, 'factura_legal', 'factura_comun', 'ticket_comun', 'ticket_legal', 1, 1, 1, 1, 1, 1, 1, :metodos)
                ");
                $insCaja->execute([
                    ':id_empresa' => $idEmpresa,
                    ':id_caja' => $idCaja,
                    ':id_sucursal' => $idSucursal,
                    ':metodos' => 'pendiente,efectivo,tarjeta,transferencia,pix,credito',
                ]);
            } else {
                $updCaja = $pdo->prepare("
                    UPDATE `{$dbName}`.cajas
                    SET metodo_cobro_permitido = :metodos
                    WHERE id_caja = :id_caja
                ");
                $updCaja->execute([
                    ':metodos' => 'pendiente,efectivo,tarjeta,transferencia,pix,credito',
                    ':id_caja' => $idCaja,
                ]);
            }
        }

        // 4) Banco principal
        if (tablaEmpresaExiste($pdo, $dbName, 'bancos')) {
            $stmtBan = $pdo->prepare("SELECT id_banco FROM `{$dbName}`.bancos WHERE LOWER(TRIM(banco)) = 'banco principal' LIMIT 1");
            $stmtBan->execute();
            $idBanco = (int)$stmtBan->fetchColumn();
            if ($idBanco <= 0) {
                $idBanco = nextTableId($pdo, $dbName, 'bancos', 'id_banco');
                $insBan = $pdo->prepare("
                    INSERT INTO `{$dbName}`.bancos
                    (id_empresa, id_sucursal, id_banco, banco, numero_cuenta, direccion, pais, ciudad, telefono, email, limite_credito, fecha_ingreso, estado, id_moneda, tipo)
                    VALUES
                    (:id_empresa, :id_sucursal, :id_banco, 'BANCO PRINCIPAL', '', '', 'PY', 'ASUNCION', '', '', 0, CURDATE(), 'ACTIVO', 1, 1)
                ");
                $insBan->execute([
                    ':id_empresa' => $idEmpresa,
                    ':id_sucursal' => $idSucursal,
                    ':id_banco' => $idBanco,
                ]);
            }
        }

        // 5) Cuenta contable base
        if (tablaEmpresaExiste($pdo, $dbName, 'cuentas')) {
            $stmtCue = $pdo->prepare("SELECT id FROM `{$dbName}`.cuentas WHERE id_cuenta = '1.1.1.01' LIMIT 1");
            $stmtCue->execute();
            if (!(int)$stmtCue->fetchColumn()) {
                $idCuenta = nextTableId($pdo, $dbName, 'cuentas', 'id');
                $insCue = $pdo->prepare("
                    INSERT INTO `{$dbName}`.cuentas
                    (id, id_grupo, id_subgrupo, id_linea, id_sublinea, id_cuenta, cuenta, fijo, requiere_chapa, sistema, asentable, grupo)
                    VALUES
                    (:id, '1', '1.1', '1.1.1', '1.1.1.0', '1.1.1.01', 'CAJA GENERAL', 1, 0, 'N', 'S', 'ACTIVO')
                ");
                $insCue->execute([':id' => $idCuenta]);
            }
        }

        // 6) Tipo precio: Normal y Mayorista
        if (tablaEmpresaExiste($pdo, $dbName, 'tipo_precio')) {
            $tiposPrecio = [
                ['tipo' => 'NORMAL', 'porcentaje' => 0, 'moneda' => 1],
                ['tipo' => 'MAYORISTA', 'porcentaje' => 0, 'moneda' => 1],
            ];
            foreach ($tiposPrecio as $tp) {
                $stmtTp = $pdo->prepare("SELECT id FROM `{$dbName}`.tipo_precio WHERE UPPER(TRIM(tipo)) = :tipo LIMIT 1");
                $stmtTp->execute([':tipo' => $tp['tipo']]);
                if ($stmtTp->fetchColumn()) {
                    continue;
                }
                $idTipoPrecio = nextTableId($pdo, $dbName, 'tipo_precio', 'id');
                $insTp = $pdo->prepare("
                    INSERT INTO `{$dbName}`.tipo_precio
                    (id, tipo, porcentaje, moneda, descuento)
                    VALUES
                    (:id, :tipo, :porcentaje, :moneda, 0)
                ");
                $insTp->execute([
                    ':id' => $idTipoPrecio,
                    ':tipo' => $tp['tipo'],
                    ':porcentaje' => $tp['porcentaje'],
                    ':moneda' => $tp['moneda'],
                ]);
            }
        }

        // 7) Cliente contado + contacto
        $idClienteContado = 0;
        if (tablaEmpresaExiste($pdo, $dbName, 'clientes')) {
            $stmtCli = $pdo->prepare("SELECT id FROM `{$dbName}`.clientes WHERE UPPER(TRIM(nombre)) = 'CLIENTE CONTADO' LIMIT 1");
            $stmtCli->execute();
            $idClienteContado = (int)$stmtCli->fetchColumn();
            if ($idClienteContado <= 0) {
                $idClienteContado = nextTableId($pdo, $dbName, 'clientes', 'id');
                $insCli = $pdo->prepare("
                    INSERT INTO `{$dbName}`.clientes
                    (id, sucursal, cuenta, fecha, documento, numero, nombre, direccion, pais, ciudad, estado, login, saldo_dolares, saldo_reales, saldo_guaranies, saldo, llave, telefono, email)
                    VALUES
                    (:id, :sucursal, 1, CURDATE(), '0', '0', 'CLIENTE CONTADO', '', 1, 1, 1, 0, 0, 0, 0, 0, :llave, '', '')
                ");
                $insCli->execute([
                    ':id' => $idClienteContado,
                    ':sucursal' => $idSucursal,
                    ':llave' => 'CLI-' . $idClienteContado,
                ]);
            }
        }
        if ($idClienteContado > 0 && tablaEmpresaExiste($pdo, $dbName, 'contacto_cliente')) {
            $stmtCc = $pdo->prepare("SELECT id FROM `{$dbName}`.contacto_cliente WHERE id_cliente = :id_cliente LIMIT 1");
            $stmtCc->execute([':id_cliente' => $idClienteContado]);
            if (!(int)$stmtCc->fetchColumn()) {
                $idContacto = nextTableId($pdo, $dbName, 'contacto_cliente', 'id');
                $insCc = $pdo->prepare("
                    INSERT INTO `{$dbName}`.contacto_cliente
                    (id, id_cliente, tipo, contacto, detalle, id_login)
                    VALUES
                    (:id, :id_cliente, 1, 'CONTACTO PRINCIPAL', '', 0)
                ");
                $insCc->execute([
                    ':id' => $idContacto,
                    ':id_cliente' => $idClienteContado,
                ]);
            }
        }
        if ($idClienteContado > 0 && tablaEmpresaExiste($pdo, $dbName, 'cliente_tipo_venta')) {
            $stmtCtv = $pdo->prepare("SELECT 1 FROM `{$dbName}`.cliente_tipo_venta WHERE id_cliente = :id_cliente AND tipo_venta = 1 LIMIT 1");
            $stmtCtv->execute([':id_cliente' => $idClienteContado]);
            if (!$stmtCtv->fetchColumn()) {
                $insCtv = $pdo->prepare("INSERT INTO `{$dbName}`.cliente_tipo_venta (id_cliente, tipo_venta) VALUES (:id_cliente, 1)");
                $insCtv->execute([':id_cliente' => $idClienteContado]);
            }
        }

        // 8) Producto inicial + precios + código de barras
        $idProducto = 0;
        if (tablaEmpresaExiste($pdo, $dbName, 'tblproductos')) {
            $stmtProd = $pdo->prepare("SELECT idproducto FROM `{$dbName}`.tblproductos WHERE UPPER(TRIM(cve_producto)) = 'SERV001' LIMIT 1");
            $stmtProd->execute();
            $idProducto = (int)$stmtProd->fetchColumn();
            if ($idProducto <= 0) {
                $idProducto = nextTableId($pdo, $dbName, 'tblproductos', 'idproducto');
                $insProd = $pdo->prepare("
                    INSERT INTO `{$dbName}`.tblproductos
                    (idproducto, cve_producto, desproducto, referencia, precio_compra, precio_venta, impuesto, iva, moneda, Estado, grupo, subgrupo, saldo, controla_stock, vende_sin_stock, decimal_cantidad, codigo_barra)
                    VALUES
                    (:id, 'SERV001', 'SERVICIO BASE', 'SERVICIO BASE', 0, 0, 10, 1, 1, 1, 0, 0, 0, 1, 1, 0, '0000000000001')
                ");
                $insProd->execute([':id' => $idProducto]);
            }
        }

        if ($idProducto > 0 && tablaEmpresaExiste($pdo, $dbName, 'mercaderia_precio')) {
            // Relacionar producto con tipo de precio 1 (normal) y 2 (mayorista) cuando existan.
            $tipoIds = [];
            if (tablaEmpresaExiste($pdo, $dbName, 'tipo_precio')) {
                $stTp = $pdo->query("SELECT id, UPPER(TRIM(tipo)) AS tipo FROM `{$dbName}`.tipo_precio WHERE UPPER(TRIM(tipo)) IN ('NORMAL','MAYORISTA')");
                $tipoRows = $stTp ? $stTp->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($tipoRows as $tr) {
                    $tipoIds[(string)$tr['tipo']] = (int)$tr['id'];
                }
            }

            foreach (['NORMAL', 'MAYORISTA'] as $tpName) {
                $tpId = (int)($tipoIds[$tpName] ?? 0);
                if ($tpId <= 0) {
                    continue;
                }
                $stmtMp = $pdo->prepare("SELECT id FROM `{$dbName}`.mercaderia_precio WHERE codigo = :codigo AND tipo = :tipo LIMIT 1");
                $stmtMp->execute([':codigo' => $idProducto, ':tipo' => $tpId]);
                if ($stmtMp->fetchColumn()) {
                    continue;
                }
                $idMp = nextTableId($pdo, $dbName, 'mercaderia_precio', 'id');
                $insMp = $pdo->prepare("
                    INSERT INTO `{$dbName}`.mercaderia_precio
                    (id, codigo, tipo, costo, porcentaje, precio, moneda, id_login, descuento)
                    VALUES
                    (:id, :codigo, :tipo, 0, 0, 0, 1, 0, 0)
                ");
                $insMp->execute([
                    ':id' => $idMp,
                    ':codigo' => $idProducto,
                    ':tipo' => $tpId,
                ]);
            }
        }

        if ($idProducto > 0 && tablaEmpresaExiste($pdo, $dbName, 'codigo_barra')) {
            $stmtCb = $pdo->prepare("SELECT id FROM `{$dbName}`.codigo_barra WHERE id_producto = :id_producto AND codigo_barra = :cb LIMIT 1");
            $stmtCb->execute([':id_producto' => $idProducto, ':cb' => '0000000000001']);
            if (!$stmtCb->fetchColumn()) {
                $idCb = nextTableId($pdo, $dbName, 'codigo_barra', 'id');
                $insCb = $pdo->prepare("
                    INSERT INTO `{$dbName}`.codigo_barra
                    (id, id_producto, codigo_barra, id_login)
                    VALUES
                    (:id, :id_producto, :codigo_barra, 0)
                ");
                $insCb->execute([
                    ':id' => $idCb,
                    ':id_producto' => $idProducto,
                    ':codigo_barra' => '0000000000001',
                ]);
            }
        }

        if ($idProducto > 0 && tablaEmpresaExiste($pdo, $dbName, 'codigo_precio')) {
            $stmtCp = $pdo->prepare("SELECT id FROM `{$dbName}`.codigo_precio WHERE cve_producto = 'SERV001' LIMIT 1");
            $stmtCp->execute();
            if (!$stmtCp->fetchColumn()) {
                $idCp = nextTableId($pdo, $dbName, 'codigo_precio', 'id');
                $insCp = $pdo->prepare("
                    INSERT INTO `{$dbName}`.codigo_precio
                    (id, cve_producto, desproducto, precio, obs)
                    VALUES
                    (:id, 'SERV001', 'SERVICIO BASE', 0, '')
                ");
                $insCp->execute([':id' => $idCp]);
            }
        }
    } catch (Throwable $e) {
        error_log('Suscribete seed empresa inicial error: ' . $e->getMessage());
    }
}

function crearEmpresaDesdeSolicitud(PDO $pdo, array $data): int
{
    $colsStmt = $pdo->query("SHOW COLUMNS FROM empresa");
    $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!$cols) {
        throw new Exception('No se pudo leer estructura de empresa');
    }

    $rucBase = normalizarRucBase((string)($data['ruc'] ?? ''));
    if ($rucBase === '') {
        throw new Exception('RUC inválido');
    }

    $dv = calcularDV($rucBase);
    $rucNormalizado = $rucBase . '-' . $dv;
    $tipoNegocio = trim((string)($data['tipo_negocio'] ?? 'Comercial'));
    if ($tipoNegocio === '') {
        $tipoNegocio = 'Comercial';
    }

    $baseData = [
        'empresa' => substr((string)($data['empresa'] ?? ''), 0, 50),
        'nombre_fantasia' => substr((string)($data['empresa'] ?? ''), 0, 255),
        'ruc' => substr($rucNormalizado, 0, 15),
        'dv' => (int)$dv,
        'telefono' => substr((string)($data['telefono'] ?? ''), 0, 30),
        'email' => substr((string)($data['email'] ?? ''), 0, 100),
        'direccion' => (string)($data['direccion'] ?? ''),
        'ciudad' => substr((string)($data['ciudad'] ?? 'Asunción'), 0, 30),
        'pais' => substr((string)($data['pais'] ?? 'Paraguay'), 0, 60),
        'activo' => 1,
        'active' => 'Y',
        'software' => 1,
        'id_grupo' => 1,
        'logos' => '',
        'fondo' => '',
        'rubro' => substr($tipoNegocio, 0, 120),
        'tipo_negocio' => substr($tipoNegocio, 0, 120),
        'negocio' => substr($tipoNegocio, 0, 120),
        'giro' => substr($tipoNegocio, 0, 120),
        'factura_electronica' => 0,
        'fe' => 0,
        'web' => 0,
        'dbase' => '',
    ];

    $insertData = [];
    foreach ($cols as $col) {
        $field = (string)$col['Field'];
        $type = strtolower((string)$col['Type']);
        $nullable = ((string)$col['Null']) === 'YES';
        $default = $col['Default'];
        $extra = strtolower((string)$col['Extra']);

        if (strpos($extra, 'auto_increment') !== false) {
            continue;
        }
        if (array_key_exists($field, $baseData)) {
            $insertData[$field] = $baseData[$field];
            continue;
        }
        if ($default !== null) {
            continue;
        }
        if ($nullable) {
            $insertData[$field] = null;
            continue;
        }

        if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
            $insertData[$field] = 0;
        } elseif (strpos($type, 'date') === 0) {
            $insertData[$field] = date('Y-m-d');
        } elseif (strpos($type, 'datetime') === 0 || strpos($type, 'timestamp') === 0) {
            $insertData[$field] = date('Y-m-d H:i:s');
        } elseif (strpos($type, 'time') === 0) {
            $insertData[$field] = '00:00:00';
        } else {
            $insertData[$field] = '';
        }
    }

    if (empty($insertData['empresa']) || empty($insertData['ruc'])) {
        throw new Exception('Datos mínimos de empresa incompletos');
    }

    $columns = array_keys($insertData);
    $quotedColumns = array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $columns);
    $placeholders = array_map(static fn($c) => ':' . $c, $columns);
    $sql = "INSERT INTO empresa (" . implode(',', $quotedColumns) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    foreach ($insertData as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->execute();

    $idEmpresa = (int)$pdo->lastInsertId();
    if ($idEmpresa <= 0) {
        throw new Exception('No se pudo crear la empresa');
    }

    $dbName = 'empresa_' . $idEmpresa;
    $upd = $pdo->prepare("UPDATE empresa SET id_grupo = :idg, dbase = :db WHERE id_empresa = :id");
    $upd->execute([
        ':idg' => $idEmpresa,
        ':db' => $dbName,
        ':id' => $idEmpresa,
    ]);

    crearEstructuraEmpresa($pdo, $idEmpresa, 1);
    return $idEmpresa;
}

function crearSuscripcionPruebaInicial(PDO $pdo, int $idEmpresa, int $diasPrueba = 15): void
{
    if ($idEmpresa <= 0) {
        return;
    }
    try {
        $tblCheck = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'saas_suscripcion'
            LIMIT 1
        ");
        $tblCheck->execute();
        if (!$tblCheck->fetchColumn()) {
            return;
        }

        $stmtEx = $pdo->prepare("SELECT id_suscripcion FROM saas_suscripcion WHERE id_empresa = :id ORDER BY periodo_inicio DESC, id_suscripcion DESC LIMIT 1");
        $stmtEx->execute([':id' => $idEmpresa]);
        if ((int)$stmtEx->fetchColumn() > 0) {
            return;
        }

        $dias = max(1, (int)$diasPrueba);
        $inicio = new DateTimeImmutable(date('Y-m-d'));
        $fin = $inicio->modify('+' . ($dias - 1) . ' day');

        $colsStmt = $pdo->query("SHOW COLUMNS FROM saas_suscripcion");
        $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!$cols) {
            return;
        }

        $base = [
            'id_empresa' => $idEmpresa,
            'periodo_inicio' => $inicio->format('Y-m-d'),
            'periodo_fin' => $fin->format('Y-m-d'),
            'nro_factura' => 'TRIAL-' . $inicio->format('Ymd') . '-' . $idEmpresa,
            'fecha_emision' => date('Y-m-d H:i:s'),
            'fecha_vencimiento' => $fin->format('Y-m-d'),
            'subtotal' => 0,
            'descuento' => 0,
            'total' => 0,
            'moneda' => 'PYG',
            'estado' => 'activa',
            'estado_pago' => 'pendiente',
            'dias_gracia' => 0,
            'obs' => 'Alta automática: período de prueba de 15 días.',
            'notificado_nueva' => 0,
            'notificado_vencimiento' => 0,
            'notificado_bloqueo' => 0,
        ];

        $insertData = [];
        foreach ($cols as $col) {
            $field = (string)($col['Field'] ?? '');
            $type = strtolower((string)($col['Type'] ?? ''));
            $nullable = ((string)($col['Null'] ?? '')) === 'YES';
            $default = $col['Default'] ?? null;
            $extra = strtolower((string)($col['Extra'] ?? ''));
            if ($field === '' || strpos($extra, 'auto_increment') !== false) {
                continue;
            }
            if (array_key_exists($field, $base)) {
                $insertData[$field] = $base[$field];
                continue;
            }
            if ($default !== null) {
                continue;
            }
            if ($nullable) {
                $insertData[$field] = null;
                continue;
            }
            if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
                $insertData[$field] = 0;
            } elseif (strpos($type, 'date') === 0) {
                $insertData[$field] = date('Y-m-d');
            } elseif (strpos($type, 'datetime') === 0 || strpos($type, 'timestamp') === 0) {
                $insertData[$field] = date('Y-m-d H:i:s');
            } elseif (strpos($type, 'time') === 0) {
                $insertData[$field] = '00:00:00';
            } else {
                $insertData[$field] = '';
            }
        }

        if (empty($insertData['id_empresa']) || empty($insertData['periodo_inicio']) || empty($insertData['periodo_fin'])) {
            return;
        }

        $columns = array_keys($insertData);
        $quotedColumns = array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $columns);
        $placeholders = array_map(static fn($c) => ':' . $c, $columns);
        $sql = "INSERT INTO saas_suscripcion (" . implode(',', $quotedColumns) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        foreach ($insertData as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('Suscribete trial create error: ' . $e->getMessage());
    }
}

function suscribeteCatalogoHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];
    $key = strtolower(trim($column));
    if ($key === '') {
        return false;
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'saas_apps_catalogo'
          AND COLUMN_NAME = :column
        LIMIT 1
    ");
    $stmt->execute([':column' => $key]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function suscribeteCatalogDisponibleFilter(PDO $pdo, string $alias = 'a'): string
{
    $safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'a';
    $extra = " AND {$safeAlias}.codigo NOT IN (" . $pdo->quote('pos_autorrepuestos') . ")";
    if (!suscribeteCatalogoHasColumn($pdo, 'disponible')) {
        return $extra;
    }
    return " AND COALESCE({$safeAlias}.disponible, 1) = 1{$extra}";
}

function obtenerSuscripcionOperableEmpresa(PDO $pdo, int $idEmpresa): int
{
    if ($idEmpresa <= 0) {
        return 0;
    }

    $queries = [
        "
            SELECT id_suscripcion
            FROM saas_suscripcion
            WHERE id_empresa = :id_empresa
              AND estado IN ('activa', 'gracia')
              AND CURDATE() BETWEEN periodo_inicio AND DATE_ADD(periodo_fin, INTERVAL COALESCE(dias_gracia, 0) DAY)
            ORDER BY periodo_inicio DESC, id_suscripcion DESC
            LIMIT 1
        ",
        "
            SELECT id_suscripcion
            FROM saas_suscripcion
            WHERE id_empresa = :id_empresa
              AND estado <> 'cancelada'
            ORDER BY periodo_inicio DESC, id_suscripcion DESC
            LIMIT 1
        ",
        "
            SELECT id_suscripcion
            FROM saas_suscripcion
            WHERE id_empresa = :id_empresa
            ORDER BY id_suscripcion DESC
            LIMIT 1
        ",
    ];

    foreach ($queries as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_empresa' => $idEmpresa]);
        $idSuscripcion = (int)$stmt->fetchColumn();
        if ($idSuscripcion > 0) {
            return $idSuscripcion;
        }
    }

    return 0;
}

function recalculateSuscripcionTotalLocal(PDO $pdo, int $idSuscripcion): void
{
    if ($idSuscripcion <= 0) {
        return;
    }

    $hasSponsor = false;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM saas_suscripcion LIKE 'es_sponsor'");
        $hasSponsor = $stmt ? (bool)$stmt->fetch(PDO::FETCH_ASSOC) : false;
    } catch (Throwable $e) {
        $hasSponsor = false;
    }

    $sql = $hasSponsor
        ? "
            UPDATE saas_suscripcion s
            SET s.total = CASE
                    WHEN COALESCE(s.es_sponsor, 0) = 1 THEN 0
                    ELSE (
                        SELECT COALESCE(SUM(
                            (COALESCE(sa.cantidad, 1) * COALESCE(sa.precio_unitario, 0)) - COALESCE(sa.descuento, 0)
                        ), 0)
                        FROM saas_suscripcion_apps sa
                        WHERE sa.id_suscripcion = s.id_suscripcion
                          AND COALESCE(sa.activo, 1) = 1
                    )
                END,
                s.estado_pago = CASE
                    WHEN COALESCE(s.es_sponsor, 0) = 1 THEN 'pagado'
                    ELSE s.estado_pago
                END
            WHERE s.id_suscripcion = ?
        "
        : "
            UPDATE saas_suscripcion s
            SET s.total = (
                SELECT COALESCE(SUM(
                    (COALESCE(sa.cantidad, 1) * COALESCE(sa.precio_unitario, 0)) - COALESCE(sa.descuento, 0)
                ), 0)
                FROM saas_suscripcion_apps sa
                WHERE sa.id_suscripcion = s.id_suscripcion
                  AND COALESCE(sa.activo, 1) = 1
            )
            WHERE s.id_suscripcion = ?
        ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$idSuscripcion]);
}

function obtenerAppsCatalogoPorCodigos(PDO $pdo, array $codigos, int $idSuscripcion): array
{
    $codigos = array_values(array_unique(array_filter(array_map(
        static fn($v) => strtolower(trim((string)$v)),
        $codigos
    ))));
    if (!$codigos || $idSuscripcion <= 0) {
        return [];
    }

    $filtroDisponible = suscribeteCatalogDisponibleFilter($pdo, 'a');
    $placeholders = [];
    $params = [':id_suscripcion' => $idSuscripcion];
    foreach ($codigos as $idx => $codigo) {
        $ph = ':codigo_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $codigo;
    }

    $sql = "
        SELECT a.*
        FROM saas_apps_catalogo a
        WHERE a.activo = 1
              {$filtroDisponible}
          AND COALESCE(a.obligatoria, 0) = 0
          AND LOWER(TRIM(a.codigo)) IN (" . implode(',', $placeholders) . ")
          AND a.id_app NOT IN (
            SELECT sa.id_app
            FROM saas_suscripcion_apps sa
            WHERE sa.id_suscripcion = :id_suscripcion
              AND COALESCE(sa.activo, 1) = 1
          )
        ORDER BY a.orden ASC, a.nombre ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function obtenerAppsPlanEmpresaCompleta(PDO $pdo, string $tipoNegocio, int $idSuscripcion): array
{
    $appsTipo = obtenerAppsInicialesPorTipoNegocio($pdo, $tipoNegocio, $idSuscripcion);
    if ($appsTipo) {
        return $appsTipo;
    }

    $filtroDisponible = suscribeteCatalogDisponibleFilter($pdo, 'a');
    $stmt = $pdo->prepare("
        SELECT a.*
        FROM saas_apps_catalogo a
        WHERE a.activo = 1
              {$filtroDisponible}
          AND COALESCE(a.obligatoria, 0) = 0
          AND a.id_app NOT IN (
            SELECT sa.id_app
            FROM saas_suscripcion_apps sa
            WHERE sa.id_suscripcion = :id_suscripcion
              AND COALESCE(sa.activo, 1) = 1
          )
        ORDER BY a.orden ASC, a.nombre ASC
    ");
    $stmt->execute([':id_suscripcion' => $idSuscripcion]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function obtenerAppsInicialesPorPlan(PDO $pdo, string $planCodigo, string $tipoNegocio, int $idSuscripcion): array
{
    $plan = suscribetePlanDefinicion($planCodigo);
    $appsMode = (string)($plan['apps_mode'] ?? 'explicit');
    if ($appsMode === 'business_type_full') {
        return obtenerAppsPlanEmpresaCompleta($pdo, $tipoNegocio, $idSuscripcion);
    }

    return obtenerAppsCatalogoPorCodigos($pdo, $plan['apps'] ?? [], $idSuscripcion);
}

function obtenerAppsInicialesPorTipoNegocio(PDO $pdo, string $tipoNegocio, int $idSuscripcion): array
{
    $tipo = trim($tipoNegocio);
    if ($tipo === '' || $idSuscripcion <= 0) {
        return [];
    }

    $filtroDisponible = suscribeteCatalogDisponibleFilter($pdo, 'a');
    $sqlModelo = "
        SELECT a.*
        FROM tipo_negocio_app tna
        INNER JOIN tipo_negocio tn ON tn.id = tna.tipo_negocio_id
        INNER JOIN saas_apps_catalogo a ON a.id_app = tna.id_app
        WHERE tn.activo = 1
          AND tna.activo = 1
          AND a.activo = 1
              {$filtroDisponible}
          AND COALESCE(a.obligatoria, 0) = 0
          AND LOWER(TRIM(tn.nombre)) = LOWER(TRIM(:tipo_negocio))
          AND a.id_app NOT IN (
            SELECT sa.id_app
            FROM saas_suscripcion_apps sa
            WHERE sa.id_suscripcion = :id_suscripcion
              AND COALESCE(sa.activo, 1) = 1
          )
        ORDER BY COALESCE(tna.orden, a.orden, 9999), a.nombre
    ";
    $stmtModelo = $pdo->prepare($sqlModelo);
    $stmtModelo->execute([
        ':tipo_negocio' => $tipo,
        ':id_suscripcion' => $idSuscripcion,
    ]);
    $apps = $stmtModelo->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($apps) {
        return $apps;
    }

    $sqlLegacy = "
        SELECT a.*
        FROM saas_apps_catalogo a
        WHERE a.activo = 1
              {$filtroDisponible}
          AND COALESCE(a.obligatoria, 0) = 0
          AND COALESCE(a.negocio, 'General') = :tipo_negocio
          AND a.id_app NOT IN (
            SELECT sa.id_app
            FROM saas_suscripcion_apps sa
            WHERE sa.id_suscripcion = :id_suscripcion
              AND COALESCE(sa.activo, 1) = 1
          )
        ORDER BY a.orden ASC, a.nombre ASC
    ";
    $stmtLegacy = $pdo->prepare($sqlLegacy);
    $stmtLegacy->execute([
        ':tipo_negocio' => $tipo,
        ':id_suscripcion' => $idSuscripcion,
    ]);
    return $stmtLegacy->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function insertarSuscripcionAppInicial(PDO $pdo, int $idSuscripcion, array $app): void
{
    static $colsCache = null;
    if (!is_array($colsCache)) {
        $stmt = $pdo->query("SHOW COLUMNS FROM saas_suscripcion_apps");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $colsCache = [];
        foreach ($rows as $row) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $colsCache[$field] = $row;
            }
        }
    }

    if (empty($colsCache) || $idSuscripcion <= 0) {
        return;
    }

    $precio = (float)($app['precio_mensual'] ?? 0);
    $base = [
        'id_suscripcion' => $idSuscripcion,
        'id_app' => (int)($app['id_app'] ?? 0),
        'codigo_app' => (string)($app['codigo'] ?? ''),
        'nombre_app' => (string)($app['nombre'] ?? ''),
        'cantidad' => 1,
        'precio_unitario' => $precio,
        'descuento' => 0,
        'subtotal' => $precio,
        'activo' => 1,
        'fecha_activacion' => date('Y-m-d H:i:s'),
        'cancelar_al_cierre' => 0,
    ];

    $insertData = [];
    foreach ($colsCache as $field => $meta) {
        $type = strtolower((string)($meta['Type'] ?? ''));
        $nullable = ((string)($meta['Null'] ?? '')) === 'YES';
        $default = $meta['Default'] ?? null;
        $extra = strtolower((string)($meta['Extra'] ?? ''));
        if (strpos($extra, 'auto_increment') !== false) {
            continue;
        }
        if (array_key_exists($field, $base)) {
            $insertData[$field] = $base[$field];
            continue;
        }
        if ($default !== null) {
            continue;
        }
        if ($nullable) {
            $insertData[$field] = null;
            continue;
        }
        if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
            $insertData[$field] = 0;
        } elseif (strpos($type, 'date') === 0) {
            $insertData[$field] = date('Y-m-d');
        } elseif (strpos($type, 'datetime') === 0 || strpos($type, 'timestamp') === 0) {
            $insertData[$field] = date('Y-m-d H:i:s');
        } elseif (strpos($type, 'time') === 0) {
            $insertData[$field] = '00:00:00';
        } else {
            $insertData[$field] = '';
        }
    }

    $columns = array_keys($insertData);
    $quotedColumns = array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $columns);
    $placeholders = array_map(static fn($c) => ':' . $c, $columns);
    $stmt = $pdo->prepare("
        INSERT INTO saas_suscripcion_apps (" . implode(',', $quotedColumns) . ")
        VALUES (" . implode(',', $placeholders) . ")
    ");
    foreach ($insertData as $field => $value) {
        $stmt->bindValue(':' . $field, $value);
    }
    $stmt->execute();
}

function asignarAppsInicialesPorTipoNegocio(PDO $pdo, int $idEmpresa, string $tipoNegocio): int
{
    if ($idEmpresa <= 0 || trim($tipoNegocio) === '') {
        return 0;
    }

    try {
        $idSuscripcion = obtenerSuscripcionOperableEmpresa($pdo, $idEmpresa);
        if ($idSuscripcion <= 0) {
            crearSuscripcionPruebaInicial($pdo, $idEmpresa, 15);
            $idSuscripcion = obtenerSuscripcionOperableEmpresa($pdo, $idEmpresa);
        }
        if ($idSuscripcion <= 0) {
            return 0;
        }

        $apps = obtenerAppsInicialesPorTipoNegocio($pdo, $tipoNegocio, $idSuscripcion);
        if (!$apps) {
            return 0;
        }

        $agregadas = 0;
        foreach ($apps as $app) {
            if ((int)($app['id_app'] ?? 0) <= 0) {
                continue;
            }
            insertarSuscripcionAppInicial($pdo, $idSuscripcion, $app);
            $agregadas++;
        }

        if ($agregadas > 0) {
            recalculateSuscripcionTotalLocal($pdo, $idSuscripcion);
        }
        return $agregadas;
    } catch (Throwable $e) {
        error_log('Suscribete assign apps by business type error: ' . $e->getMessage());
        return 0;
    }
}

function contarAppsActivasSuscripcionEmpresa(PDO $pdo, int $idEmpresa): int
{
    if ($idEmpresa <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(sa.id)
        FROM saas_suscripcion s
        INNER JOIN saas_suscripcion_apps sa ON sa.id_suscripcion = s.id_suscripcion
        WHERE s.id_empresa = :id_empresa
          AND COALESCE(sa.activo, 1) = 1
    ");
    $stmt->execute([':id_empresa' => $idEmpresa]);
    return (int)$stmt->fetchColumn();
}

function contarPlantillaAppsTipoNegocio(PDO $pdo, string $tipoNegocio): int
{
    $tipo = trim($tipoNegocio);
    if ($tipo === '') {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tipo_negocio_app tna
        INNER JOIN tipo_negocio tn ON tn.id = tna.tipo_negocio_id
        INNER JOIN saas_apps_catalogo a ON a.id_app = tna.id_app
        WHERE tn.activo = 1
          AND tna.activo = 1
          AND a.activo = 1
          AND COALESCE(a.obligatoria, 0) = 0
          AND LOWER(TRIM(tn.nombre)) = LOWER(TRIM(:tipo_negocio))
    ");
    $stmt->execute([':tipo_negocio' => $tipo]);
    return (int)$stmt->fetchColumn();
}

function asegurarAppsInicialesTipoNegocio(PDO $pdo, int $idEmpresa, string $tipoNegocio): int
{
    $appsActuales = contarAppsActivasSuscripcionEmpresa($pdo, $idEmpresa);
    if ($appsActuales > 0) {
        return $appsActuales;
    }

    $agregadas = asignarAppsInicialesPorTipoNegocio($pdo, $idEmpresa, $tipoNegocio);
    $appsFinales = contarAppsActivasSuscripcionEmpresa($pdo, $idEmpresa);
    if ($appsFinales > 0) {
        return $appsFinales;
    }

    $plantilla = contarPlantillaAppsTipoNegocio($pdo, $tipoNegocio);
    if ($plantilla > 0) {
        error_log('Suscribete warning: empresa ' . $idEmpresa . ' quedó sin apps pese a tener plantilla para tipo_negocio=' . $tipoNegocio . ' (plantilla=' . $plantilla . ', agregadas=' . $agregadas . ')');
    }

    return $appsFinales;
}

function asignarAppsInicialesPorPlan(PDO $pdo, int $idEmpresa, string $tipoNegocio, string $planCodigo): int
{
    if ($idEmpresa <= 0) {
        return 0;
    }

    try {
        $idSuscripcion = obtenerSuscripcionOperableEmpresa($pdo, $idEmpresa);
        if ($idSuscripcion <= 0) {
            crearSuscripcionPruebaInicial($pdo, $idEmpresa, 15);
            $idSuscripcion = obtenerSuscripcionOperableEmpresa($pdo, $idEmpresa);
        }
        if ($idSuscripcion <= 0) {
            return 0;
        }

        $apps = obtenerAppsInicialesPorPlan($pdo, $planCodigo, $tipoNegocio, $idSuscripcion);
        if (!$apps) {
            return 0;
        }

        $agregadas = 0;
        foreach ($apps as $app) {
            if ((int)($app['id_app'] ?? 0) <= 0) {
                continue;
            }
            insertarSuscripcionAppInicial($pdo, $idSuscripcion, $app);
            $agregadas++;
        }

        if ($agregadas > 0) {
            recalculateSuscripcionTotalLocal($pdo, $idSuscripcion);
        }

        return $agregadas;
    } catch (Throwable $e) {
        error_log('Suscribete assign apps by plan error: ' . $e->getMessage());
        return 0;
    }
}

function configurarAltaEmpresaSegunPlan(PDO $pdo, int $idEmpresa, string $tipoNegocio, string $planCodigo): void
{
    if ($idEmpresa <= 0) {
        return;
    }

    $plan = suscribetePlanDefinicion($planCodigo);
    crearSuscripcionPruebaInicial($pdo, $idEmpresa, 15);
    guardarConfiguracionPlanEmpresa($pdo, $idEmpresa, $plan['codigo'], $tipoNegocio);
    asignarAppsInicialesPorPlan($pdo, $idEmpresa, $tipoNegocio, $plan['codigo']);
}

function crearUsuarioAdminEmpresa(PDO $pdo, int $idEmpresa, array $data): int
{
    $login = strtolower(trim((string)($data['login'] ?? '')));
    $name = trim((string)($data['nombre'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['telefono'] ?? ''));
    $documento = trim((string)($data['documento'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if (!preg_match('/^[a-z0-9._-]{4,32}$/', $login)) {
        throw new Exception('Usuario admin inválido. Use 4-32 caracteres: letras minúsculas, números, punto, guión o guión bajo.');
    }

    $stmtDup = $pdo->prepare("SELECT id_login, id_empresa FROM sec_users WHERE login = :login LIMIT 1");
    $stmtDup->execute([':login' => $login]);
    $dupUser = $stmtDup->fetch(PDO::FETCH_ASSOC);

    if ($dupUser) {
        $dupEmpresa = (int)($dupUser['id_empresa'] ?? 0);
        if ($dupEmpresa !== $idEmpresa) {
            throw new Exception('Ese usuario ya existe en otra empresa. Elegí otro.');
        }

        // Si ya existe para esta empresa, forzar perfil admin primario.
        $idLogin = (int)($dupUser['id_login'] ?? 0);
        if ($idLogin <= 0) {
            throw new Exception('No se pudo resolver el usuario administrador existente.');
        }

        $stmtUpd = $pdo->prepare("
            UPDATE sec_users
            SET name = :name,
                email = :email,
                phone = :phone,
                documento = :documento,
                pswd = :pswd,
                active = 'Y',
                priv_admin = 'Y',
                id_empresa = :emp,
                id_grupo = :ig,
                id_sucursal = 1,
                id_caja = 1,
                role = 'ADMIN'
            WHERE id_login = :id
            LIMIT 1
        ");
        $stmtUpd->execute([
            ':name' => ($name !== '' ? $name : 'Administrador'),
            ':email' => $email,
            ':phone' => $phone,
            ':documento' => $documento,
            ':pswd' => md5($password),
            ':emp' => $idEmpresa,
            ':ig' => $idEmpresa,
            ':id' => $idLogin,
        ]);
    } else {
        $sql = "INSERT INTO sec_users (login, name, email, phone, documento, pswd, active, priv_admin, id_empresa, id_grupo, id_sucursal, id_caja, genero, role, foto)
                VALUES (:login, :name, :email, :phone, :documento, :pswd, 'Y', 'Y', :emp, :ig, 1, 1, 1, 'ADMIN', 'defaultuser.png')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':login' => $login,
            ':name' => ($name !== '' ? $name : 'Administrador'),
            ':email' => $email,
            ':phone' => $phone,
            ':documento' => $documento,
            ':pswd' => md5($password),
            ':emp' => $idEmpresa,
            ':ig' => $idEmpresa,
        ]);
        $idLogin = (int)$pdo->lastInsertId();
    }

    $stmtGroup = $pdo->prepare("SELECT 1 FROM sec_users_groups WHERE login = :login AND id_grupo = :ig LIMIT 1");
    $stmtGroup->execute([':login' => $login, ':ig' => $idEmpresa]);
    if (!$stmtGroup->fetch(PDO::FETCH_ASSOC)) {
        $insGroup = $pdo->prepare("INSERT INTO sec_users_groups (group_id, id_grupo, login) VALUES (1, :ig, :login)");
        $insGroup->execute([':ig' => $idEmpresa, ':login' => $login]);
    }

    // Seguridad adicional: garantizar flag admin en registro primario.
    $stmtForceAdmin = $pdo->prepare("UPDATE sec_users SET priv_admin = 'Y', active = 'Y', role = 'ADMIN' WHERE id_login = :id LIMIT 1");
    $stmtForceAdmin->execute([':id' => $idLogin]);

    return $idLogin;
}

function registrarAdminComoContactoEmpresa(PDO $pdo, int $idEmpresa, array $data): void
{
    if ($idEmpresa <= 0) {
        return;
    }
    $dbName = 'empresa_' . $idEmpresa;
    if (!tablaEmpresaExiste($pdo, $dbName, 'clientes')) {
        return;
    }

    $nombre = trim((string)($data['nombre'] ?? 'ADMINISTRADOR'));
    $documento = preg_replace('/[^0-9]/', '', (string)($data['documento'] ?? ''));
    $telefono = trim((string)($data['telefono'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    if ($nombre === '' || $documento === '') {
        return;
    }

    try {
        $stmtEx = $pdo->prepare("SELECT id FROM `{$dbName}`.clientes WHERE numero = :numero LIMIT 1");
        $stmtEx->execute([':numero' => $documento]);
        $idCliente = (int)$stmtEx->fetchColumn();
        if ($idCliente <= 0) {
            $idCliente = nextTableId($pdo, $dbName, 'clientes', 'id');
            $stmtSuc = $pdo->query("SELECT COALESCE(MIN(id_sucursal),1) FROM `{$dbName}`.sucursales");
            $idSucursal = (int)($stmtSuc ? $stmtSuc->fetchColumn() : 1);
            if ($idSucursal <= 0) {
                $idSucursal = 1;
            }
            $stmtIns = $pdo->prepare("
                INSERT INTO `{$dbName}`.clientes
                (id, sucursal, cuenta, fecha, documento, numero, nombre, direccion, pais, ciudad, estado, login, saldo_dolares, saldo_reales, saldo_guaranies, saldo, llave, telefono, email)
                VALUES
                (:id, :sucursal, 1, CURDATE(), 'CI', :numero, :nombre, '', 1, 1, 1, 0, 0, 0, 0, 0, :llave, :telefono, :email)
            ");
            $stmtIns->execute([
                ':id' => $idCliente,
                ':sucursal' => $idSucursal,
                ':numero' => $documento,
                ':nombre' => $nombre,
                ':llave' => 'ADM-' . $idCliente,
                ':telefono' => $telefono,
                ':email' => $email,
            ]);
        } else {
            $stmtUpd = $pdo->prepare("
                UPDATE `{$dbName}`.clientes
                SET nombre = :nombre, telefono = :telefono, email = :email
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':id' => $idCliente,
                ':nombre' => $nombre,
                ':telefono' => $telefono,
                ':email' => $email,
            ]);
        }

        if (tablaEmpresaExiste($pdo, $dbName, 'contacto_cliente')) {
            $stmtC = $pdo->prepare("SELECT id FROM `{$dbName}`.contacto_cliente WHERE id_cliente = :id_cliente AND UPPER(TRIM(contacto)) = UPPER(:contacto) LIMIT 1");
            $stmtC->execute([':id_cliente' => $idCliente, ':contacto' => $nombre]);
            if (!$stmtC->fetchColumn()) {
                $idContacto = nextTableId($pdo, $dbName, 'contacto_cliente', 'id');
                $stmtInsC = $pdo->prepare("
                    INSERT INTO `{$dbName}`.contacto_cliente
                    (id, id_cliente, tipo, contacto, detalle, id_login)
                    VALUES
                    (:id, :id_cliente, 1, :contacto, :detalle, 0)
                ");
                $stmtInsC->execute([
                    ':id' => $idContacto,
                    ':id_cliente' => $idCliente,
                    ':contacto' => $nombre,
                    ':detalle' => $telefono,
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('Suscribete registrarAdminComoContactoEmpresa error: ' . $e->getMessage());
    }
}

function verificarAdminPrimario(PDO $pdo, int $idEmpresa, int $idLogin, string $login): array
{
    $stmtUser = $pdo->prepare("
        SELECT id_login, login, priv_admin, active, role, id_empresa, id_grupo
        FROM sec_users
        WHERE id_login = :id
        LIMIT 1
    ");
    $stmtUser->execute([':id' => $idLogin]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtGroup = $pdo->prepare("
        SELECT 1
        FROM sec_users_groups
        WHERE login = :login AND id_grupo = :ig
        LIMIT 1
    ");
    $stmtGroup->execute([':login' => $login, ':ig' => $idEmpresa]);
    $hasGroup = (bool)$stmtGroup->fetchColumn();

    $ok = $user
        && strtoupper((string)($user['priv_admin'] ?? 'N')) === 'Y'
        && strtoupper((string)($user['active'] ?? 'N')) === 'Y'
        && (int)($user['id_empresa'] ?? 0) === $idEmpresa
        && $hasGroup;

    return [
        'ok' => $ok,
        'user' => $user,
        'has_group' => $hasGroup,
    ];
}

function solicitudAdminYaCreado(PDO $pdo, array $solicitud): bool
{
    $adminUserId = (int)($solicitud['admin_user_id'] ?? 0);
    if ($adminUserId > 0) {
        $stmt = $pdo->prepare("SELECT 1 FROM sec_users WHERE id_login = :id LIMIT 1");
        $stmt->execute([':id' => $adminUserId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    $adminLogin = trim((string)($solicitud['admin_login'] ?? ''));
    if ($adminLogin !== '') {
        $stmt = $pdo->prepare("SELECT 1 FROM sec_users WHERE login = :login LIMIT 1");
        $stmt->execute([':login' => $adminLogin]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    return false;
}

$ok = false;
$error = '';
$successEmail = '';
$successWhatsapp = '';
$successWarning = '';
$tokenMode = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$directRegisterMode = ($tokenMode === '') && (
    (string)($_GET['registro'] ?? '') === '1'
    || (string)($_POST['registro'] ?? '') === '1'
);
$solicitudToken = null;
$tokenDone = false;
$tokenAdminCheck = null;
$selectedPlanCodigo = normalizarPlanSuscripcion((string)($_GET['plan'] ?? $_POST['plan'] ?? 'empresa_fe'));
$selectedPlan = suscribetePlanDefinicion($selectedPlanCodigo);
$tokenForm = [
    'login' => '',
    'nombre' => '',
    'ci_admin' => '',
    'empresa' => '',
    'tipo_negocio' => 'Comercial',
    'email' => '',
    'phone_country' => '+595',
    'phone_number' => '',
    'ruc' => '',
    'direccion' => '',
    'ciudad' => 'Asunción',
    'pais' => 'Paraguay',
    'password' => '',
    'password2' => '',
    'plan' => $selectedPlanCodigo,
    'logo_name' => '',
    'logo_url' => '',
];
$form = [
    'nombre' => '',
    'apellido' => '',
    'empresa' => '',
    'contacto' => '',
    'email' => '',
    'phone_country' => '+595',
    'phone_number' => '',
    'ruc' => '',
    'mensaje' => '',
];
$tiposNegocio = tiposNegocioBase();
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$normalMode = ($tokenMode === '');
$flash = null;
if ($normalMode && $requestMethod === 'GET' && isset($_SESSION['suscribete_flash']) && is_array($_SESSION['suscribete_flash'])) {
    $flash = $_SESSION['suscribete_flash'];
    unset($_SESSION['suscribete_flash']);
}

try {
    $pdo = Database::getMasterConnection();
    ensureSolicitudesTable($pdo);
    $tiposNegocio = obtenerTiposNegocioDisponibles($pdo);
    $nombresTiposNegocio = array_map(
        static fn($t) => ((int)($t['disponible'] ?? 1) === 1) ? trim((string)($t['nombre'] ?? '')) : '',
        $tiposNegocio
    );
    $nombresTiposNegocio = array_values(array_filter($nombresTiposNegocio, static fn($v) => $v !== ''));

    if ($directRegisterMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $selectedPlanCodigo = normalizarPlanSuscripcion((string)($_POST['plan'] ?? $selectedPlanCodigo));
        $selectedPlan = suscribetePlanDefinicion($selectedPlanCodigo);
        $tokenForm['plan'] = $selectedPlanCodigo;
        $tokenForm['nombre'] = trim((string)($_POST['nombre'] ?? ''));
        $tokenForm['ci_admin'] = preg_replace('/[^0-9]/', '', (string)($_POST['ci_admin'] ?? ''));
        $tokenForm['empresa'] = trim((string)($_POST['empresa'] ?? ''));
        $tokenForm['tipo_negocio'] = planPermiteElegirTipoNegocio($selectedPlanCodigo)
            ? sanitizarTipoNegocio((string)($_POST['tipo_negocio'] ?? ''), $tiposNegocio)
            : 'Comercial';
        $tokenForm['email'] = trim((string)($_POST['email'] ?? ''));
        $tokenForm['phone_country'] = trim((string)($_POST['phone_country'] ?? '+595'));
        $tokenForm['phone_number'] = preg_replace('/[^0-9]/', '', (string)($_POST['phone_number'] ?? ''));
        $tokenForm['ruc'] = trim((string)($_POST['ruc'] ?? ''));
        $tokenForm['direccion'] = trim((string)($_POST['direccion'] ?? ''));
        $tokenForm['ciudad'] = trim((string)($_POST['ciudad'] ?? ''));
        $tokenForm['pais'] = trim((string)($_POST['pais'] ?? 'Paraguay'));
        $tokenForm['logo_url'] = trim((string)($_POST['logo_url'] ?? ''));
        $tokenForm['password'] = (string)($_POST['password'] ?? '');
        $tokenForm['password2'] = (string)($_POST['password2'] ?? '');
        $tokenForm['login'] = normalizarLoginAdmin(
            (string)($_POST['login'] ?? ''),
            (string)($_POST['email'] ?? 'admin')
        );
        $tokenTelefono = buildPhone($tokenForm['phone_country'], $tokenForm['phone_number']);

        if ($tokenForm['empresa'] === '' || $tokenForm['nombre'] === '' || $tokenForm['ruc'] === '' || $tokenForm['login'] === '' || $tokenForm['ci_admin'] === '') {
            $error = 'Complete empresa, nombre admin, CI admin, usuario y RUC/CI.';
        } elseif (strlen($tokenForm['ci_admin']) < 4) {
            $error = 'CI del administrador inválido.';
        } elseif (planPermiteElegirTipoNegocio($selectedPlanCodigo) && ($tokenForm['tipo_negocio'] === '' || !in_array($tokenForm['tipo_negocio'], $nombresTiposNegocio, true))) {
            $error = 'Seleccione un tipo de negocio válido.';
        } elseif ($tokenForm['email'] !== '' && !filter_var($tokenForm['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'El email no es válido.';
        } elseif ($tokenForm['phone_number'] === '') {
            $error = 'El número de WhatsApp es obligatorio.';
        } else {
            [$waTokenOk, $waTokenError] = validateWhatsappNumber($tokenForm['phone_country'], $tokenForm['phone_number']);
            if (!$waTokenOk) {
                $error = $waTokenError;
            }
        }

        if ($error === '' && ($tokenForm['password'] === '' || strlen($tokenForm['password']) < 8)) {
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        }
        if ($error === '' && $tokenForm['password'] !== $tokenForm['password2']) {
            $error = 'La confirmación de contraseña no coincide.';
        }

        if ($error === '') {
            $logoRes = procesarLogoEmpresaUpload($_FILES['logo_empresa'] ?? []);
            if ($logoRes['ok'] && (string)$logoRes['filename'] === '' && $tokenForm['logo_url'] !== '') {
                $logoRes = procesarLogoEmpresaUrl($tokenForm['logo_url']);
            }
            if (!$logoRes['ok']) {
                $error = (string)$logoRes['error'];
            } else {
                $tokenForm['logo_name'] = (string)$logoRes['filename'];
            }
        }

        if ($error === '') {
            $tokenForm['ruc'] = normalizarRucConDvLocal($tokenForm['ruc']);
            if ($tokenForm['ruc'] === '') {
                $error = 'RUC/CI inválido.';
            }
        }

        if ($error === '') {
            try {
                $rucBase = normalizarRucBase($tokenForm['ruc']);
                $empresaDup = buscarEmpresaPorRucDetalle($pdo, $rucBase);
                $empresaId = (int)($empresaDup['id_empresa'] ?? 0);
                if ($empresaId > 0) {
                    throw new Exception('Ya existe una empresa registrada con ese RUC/CI. Empresa #' . $empresaId . ' · RUC: ' . (string)($empresaDup['ruc'] ?? '') . ' · Nombre: ' . (string)($empresaDup['empresa'] ?? ''));
                }

                $empresaId = crearEmpresaDesdeSuscribete($pdo, [
                    'empresa' => $tokenForm['empresa'],
                    'tipo_negocio' => $tokenForm['tipo_negocio'],
                    'email' => $tokenForm['email'],
                    'telefono' => $tokenTelefono,
                    'ruc' => $tokenForm['ruc'],
                    'direccion' => $tokenForm['direccion'],
                    'ciudad' => ($tokenForm['ciudad'] !== '' ? $tokenForm['ciudad'] : 'Asunción'),
                    'pais' => ($tokenForm['pais'] !== '' ? $tokenForm['pais'] : 'Paraguay'),
                    'logos' => $tokenForm['logo_name'],
                ]);

                crearEstructuraEmpresa($pdo, $empresaId, 1);
                seedEmpresaDatosIniciales($pdo, $empresaId);
                $userId = crearUsuarioAdminEmpresa($pdo, $empresaId, [
                    'login' => $tokenForm['login'],
                    'nombre' => $tokenForm['nombre'],
                    'documento' => $tokenForm['ci_admin'],
                    'email' => $tokenForm['email'],
                    'telefono' => $tokenTelefono,
                    'password' => $tokenForm['password'],
                ]);
                registrarAdminComoContactoEmpresa($pdo, $empresaId, [
                    'nombre' => $tokenForm['nombre'],
                    'documento' => $tokenForm['ci_admin'],
                    'telefono' => $tokenTelefono,
                    'email' => $tokenForm['email'],
                ]);
                configurarAltaEmpresaSegunPlan($pdo, $empresaId, $tokenForm['tipo_negocio'], $selectedPlanCodigo);
                $tokenAdminCheck = verificarAdminPrimario($pdo, $empresaId, $userId, $tokenForm['login']);
                $tokenDone = true;
            } catch (Exception $e) {
                error_log('Suscribete direct register error: ' . $e->getMessage());
                $error = $e->getMessage();
            }
        }
    } elseif ($tokenMode !== '') {
        $stmtToken = $pdo->prepare("
            SELECT *
            FROM saas_solicitudes
            WHERE invite_token = :token
              AND (invite_expires_at IS NULL OR invite_expires_at >= NOW())
            ORDER BY id_solicitud DESC
            LIMIT 1
        ");
        $stmtToken->execute([':token' => $tokenMode]);
        $solicitudToken = $stmtToken->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$solicitudToken) {
            $error = 'El enlace no es válido o ya venció.';
        } else {
            if ($tokenForm['empresa'] === '') {
                $tokenForm['empresa'] = (string)($solicitudToken['empresa'] ?? '');
                $tokenForm['nombre'] = (string)($solicitudToken['contacto'] ?? '');
                $tokenForm['email'] = (string)($solicitudToken['email'] ?? '');
                $phoneParts = splitPhone((string)($solicitudToken['telefono'] ?? ''));
                $tokenForm['phone_country'] = $phoneParts['country'];
                $tokenForm['phone_number'] = $phoneParts['number'];
                $tokenForm['ruc'] = (string)($solicitudToken['ruc'] ?? '');
            }
        }

        if ($solicitudToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $selectedPlanCodigo = normalizarPlanSuscripcion((string)($_POST['plan'] ?? $selectedPlanCodigo));
            $selectedPlan = suscribetePlanDefinicion($selectedPlanCodigo);
            $tokenForm['plan'] = $selectedPlanCodigo;
            $tokenForm['nombre'] = trim((string)($_POST['nombre'] ?? ''));
            $tokenForm['ci_admin'] = preg_replace('/[^0-9]/', '', (string)($_POST['ci_admin'] ?? ''));
            $tokenForm['empresa'] = trim((string)($_POST['empresa'] ?? ''));
            $tokenForm['tipo_negocio'] = planPermiteElegirTipoNegocio($selectedPlanCodigo)
                ? sanitizarTipoNegocio((string)($_POST['tipo_negocio'] ?? ''), $tiposNegocio)
                : 'Comercial';
            $tokenForm['email'] = trim((string)($_POST['email'] ?? ''));
            $tokenForm['phone_country'] = trim((string)($_POST['phone_country'] ?? '+595'));
            $tokenForm['phone_number'] = preg_replace('/[^0-9]/', '', (string)($_POST['phone_number'] ?? ''));
            $tokenForm['ruc'] = trim((string)($_POST['ruc'] ?? ''));
            $tokenForm['direccion'] = trim((string)($_POST['direccion'] ?? ''));
            $tokenForm['ciudad'] = trim((string)($_POST['ciudad'] ?? ''));
            $tokenForm['pais'] = trim((string)($_POST['pais'] ?? 'Paraguay'));
            $tokenForm['password'] = (string)($_POST['password'] ?? '');
            $tokenForm['password2'] = (string)($_POST['password2'] ?? '');
            $tokenForm['login'] = normalizarLoginAdmin(
                (string)($_POST['login'] ?? ''),
                (string)($_POST['email'] ?? 'admin')
            );
            $tokenTelefono = buildPhone($tokenForm['phone_country'], $tokenForm['phone_number']);

            if (solicitudAdminYaCreado($pdo, $solicitudToken)) {
                $error = 'Esta solicitud ya registró un usuario administrador.';
            } elseif ($tokenForm['empresa'] === '' || $tokenForm['nombre'] === '' || $tokenForm['ruc'] === '' || $tokenForm['ci_admin'] === '') {
                $error = 'Complete empresa, nombre, CI admin y RUC/CI.';
            } elseif (strlen($tokenForm['ci_admin']) < 4) {
                $error = 'CI del administrador inválido.';
            } elseif (planPermiteElegirTipoNegocio($selectedPlanCodigo) && ($tokenForm['tipo_negocio'] === '' || !in_array($tokenForm['tipo_negocio'], $nombresTiposNegocio, true))) {
                $error = 'Seleccione un tipo de negocio válido.';
            } elseif ($tokenForm['phone_number'] === '') {
                $error = 'El número de WhatsApp es obligatorio.';
            } elseif ($tokenForm['email'] !== '' && !filter_var($tokenForm['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'El email no es válido.';
            } elseif ($tokenForm['password'] === '' || strlen($tokenForm['password']) < 8) {
                $error = 'La contraseña debe tener al menos 8 caracteres.';
            } elseif ($tokenForm['password'] !== $tokenForm['password2']) {
                $error = 'La confirmación de contraseña no coincide.';
            } else {
                [$waTokenOk, $waTokenError] = validateWhatsappNumber($tokenForm['phone_country'], $tokenForm['phone_number']);
                if (!$waTokenOk) {
                    $error = $waTokenError;
                }
                if ($error === '') {
                    $tokenForm['ruc'] = normalizarRucConDvLocal($tokenForm['ruc']);
                    if ($tokenForm['ruc'] === '') {
                        $error = 'RUC/CI inválido.';
                    } else {
                        try {
                        $rucBase = normalizarRucBase($tokenForm['ruc']);
                        $empresaDup = buscarEmpresaPorRucDetalle($pdo, $rucBase);
                        $empresaId = (int)($empresaDup['id_empresa'] ?? 0);
                        $empresaCreadaAhora = false;
                        if ($empresaId <= 0) {
                            $empresaId = crearEmpresaDesdeSolicitud($pdo, [
                                'empresa' => $tokenForm['empresa'],
                                'tipo_negocio' => $tokenForm['tipo_negocio'],
                                'email' => $tokenForm['email'],
                                'telefono' => $tokenTelefono,
                                'ruc' => $tokenForm['ruc'],
                                'direccion' => $tokenForm['direccion'],
                                'ciudad' => ($tokenForm['ciudad'] !== '' ? $tokenForm['ciudad'] : 'Asunción'),
                                'pais' => ($tokenForm['pais'] !== '' ? $tokenForm['pais'] : 'Paraguay'),
                            ]);
                            $empresaCreadaAhora = true;
                        }
                        if ($empresaCreadaAhora) {
                            seedEmpresaDatosIniciales($pdo, $empresaId);
                        }

                        $userId = crearUsuarioAdminEmpresa($pdo, $empresaId, [
                            'login' => $tokenForm['login'],
                            'nombre' => $tokenForm['nombre'],
                            'documento' => $tokenForm['ci_admin'],
                            'email' => $tokenForm['email'],
                            'telefono' => $tokenTelefono,
                            'password' => $tokenForm['password'],
                        ]);
                        registrarAdminComoContactoEmpresa($pdo, $empresaId, [
                            'nombre' => $tokenForm['nombre'],
                            'documento' => $tokenForm['ci_admin'],
                            'telefono' => $tokenTelefono,
                            'email' => $tokenForm['email'],
                        ]);
                        configurarAltaEmpresaSegunPlan($pdo, $empresaId, $tokenForm['tipo_negocio'], $selectedPlanCodigo);
                        $tokenAdminCheck = verificarAdminPrimario($pdo, $empresaId, $userId, $tokenForm['login']);

                        $stmtUpd = $pdo->prepare("
                            UPDATE saas_solicitudes
                            SET admin_login = :admin_login,
                                admin_name = :admin_name,
                                admin_user_id = :admin_user_id,
                                empresa = :empresa,
                                contacto = :contacto,
                                email = :email,
                                telefono = :telefono,
                                ruc = :ruc,
                                admin_created_at = NOW(),
                                invite_used_at = NOW(),
                                estado = CASE WHEN estado = 'nuevo' THEN 'contactado' ELSE estado END
                            WHERE id_solicitud = :id
                        ");
                        $stmtUpd->execute([
                            ':admin_login' => $tokenForm['login'],
                            ':admin_name' => $tokenForm['nombre'],
                            ':admin_user_id' => $userId,
                            ':empresa' => $tokenForm['empresa'],
                            ':contacto' => $tokenForm['nombre'],
                            ':email' => $tokenForm['email'],
                            ':telefono' => $tokenTelefono,
                            ':ruc' => $tokenForm['ruc'],
                            ':id' => (int)$solicitudToken['id_solicitud'],
                        ]);

                        $tokenDone = true;
                        $solicitudToken['ruc'] = $tokenForm['ruc'];
                        } catch (Exception $e) {
                            error_log('Suscribete token create company/admin error: ' . $e->getMessage());
                            $error = $e->getMessage();
                        }
                    }
                }
            }
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['nombre'] = trim((string)($_POST['nombre'] ?? ''));
        $form['apellido'] = trim((string)($_POST['apellido'] ?? ''));
        $form['contacto'] = trim($form['nombre'] . ' ' . $form['apellido']);
        $form['empresa'] = trim((string)($_POST['empresa'] ?? ''));
        $form['email'] = trim((string)($_POST['email'] ?? ''));
        $form['phone_country'] = trim((string)($_POST['phone_country'] ?? '+595'));
        $form['phone_number'] = preg_replace('/[^0-9]/', '', (string)($_POST['phone_number'] ?? ''));
        $form['ruc'] = trim((string)($_POST['ruc'] ?? ''));
        $form['mensaje'] = trim((string)($_POST['mensaje'] ?? ''));
        $formTelefono = buildPhone($form['phone_country'], $form['phone_number']);

        if ($form['nombre'] === '' || $form['apellido'] === '') {
            $error = 'Complete nombre y apellido.';
        } elseif ($form['phone_number'] === '') {
            $error = 'El número de WhatsApp es obligatorio.';
        } else {
            [$waOk, $waError] = validateWhatsappNumber($form['phone_country'], $form['phone_number']);
            if (!$waOk) {
                $error = $waError;
            }
            $rucNormalizado = '';
            if ($error === '') {
                $inviteToken = bin2hex(random_bytes(24));
                $inviteLink = getBaseUrl() . '/public/suscribete.php?token=' . urlencode($inviteToken);
                $empresaSolicitud = $form['contacto'];

                $pdo->beginTransaction();
                try {
                    // Solo bloquear duplicados por WhatsApp.
                    $stmtDupReq = $pdo->prepare("
                        SELECT id_solicitud
                        FROM saas_solicitudes
                        WHERE TRIM(telefono) = :telefono
                        LIMIT 1
                    ");
                    $stmtDupReq->execute([
                        ':telefono' => trim($formTelefono),
                    ]);
                    if ($stmtDupReq->fetch(PDO::FETCH_ASSOC)) {
                        throw new Exception('Ya existe una solicitud con ese WhatsApp.');
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO saas_solicitudes
                        (empresa, contacto, email, telefono, ruc, mensaje, ip_origen, user_agent, invite_token, invite_expires_at, invite_sent_at)
                        VALUES
                        (:empresa, :contacto, :email, :telefono, :ruc, :mensaje, :ip, :ua, :token, DATE_ADD(NOW(), INTERVAL 72 HOUR), NOW())
                    ");
                    $stmt->execute([
                        ':empresa' => $empresaSolicitud,
                        ':contacto' => $form['contacto'],
                        ':email' => '',
                        ':telefono' => $formTelefono,
                        ':ruc' => null,
                        ':mensaje' => null,
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                        ':token' => $inviteToken,
                    ]);
                    $idSolicitud = (int)$pdo->lastInsertId();

                    $pdo->commit();
                    $ok = true;
                    $successEmail = '';
                    $successWhatsapp = $formTelefono;

                    // Envío de correos fuera de la transacción:
                    // no revertir empresa/solicitud si el SMTP falla.
                    $mailPayload = [
                        'empresa' => $empresaSolicitud,
                        'contacto' => $form['contacto'],
                        'email' => '',
                        'telefono' => $formTelefono,
                        'ruc' => '',
                        'mensaje' => '',
                        'link' => $inviteLink,
                    ];
                    $resSupport = sendSolicitudToSupportMail($mailPayload);
                    $resReply = ['success' => true, 'provider' => 'skip', 'error' => null];
                    $adminWaTarget = trim(envValue('SISTEMAX_SUPPORT_WHATSAPP', envValue('SISTEMAX_WHATSAPP_ADMIN_TO', '+5445991283116')));
                    $resAdminWa = ['success' => true, 'provider' => 'skip', 'error' => null];
                    if ($adminWaTarget !== '') {
                        $adminMsg = "Nueva solicitud SistemaX\n"
                            . "Empresa: {$empresaSolicitud}\n"
                            . "Contacto: {$form['contacto']}\n"
                            . "WhatsApp: {$formTelefono}\n"
                            . "Registro admin: {$inviteLink}";
                        $resAdminWa = sx_enqueue_whatsapp($pdo, [
                            'tipo' => 'whatsapp_admin',
                            'id_solicitud' => $idSolicitud > 0 ? $idSolicitud : null,
                            'destino' => $adminWaTarget,
                            'mensaje' => $adminMsg,
                            'payload' => ['empresa' => $empresaSolicitud, 'contacto' => $form['contacto']],
                            'max_intentos' => 8,
                        ]);
                    }
                    $solicitanteMsg = "Hola {$form['contacto']}, gracias por tu solicitud en SistemaX.\n"
                        . "Este es tu enlace unico para crear tu cuenta admin:\n{$inviteLink}\n"
                        . "Si no solicitaste este acceso, ignora este mensaje.";
                    $resSolicWa = sx_enqueue_whatsapp($pdo, [
                        'tipo' => 'whatsapp_solicitante',
                        'id_solicitud' => $idSolicitud > 0 ? $idSolicitud : null,
                        'destino' => $formTelefono,
                        'mensaje' => $solicitanteMsg,
                        'payload' => ['empresa' => $empresaSolicitud, 'contacto' => $form['contacto']],
                        'max_intentos' => 8,
                    ]);
                    $waDispatch = sx_process_whatsapp_outbox($pdo, 10);

                    logMailAttempt(
                        $pdo,
                        $idSolicitud > 0 ? $idSolicitud : null,
                        'soporte',
                        trim(envValue('SISTEMAX_SUPPORT_EMAIL', 'soporte@sistemax.pro')),
                        'Nueva solicitud de suscripción - ' . $empresaSolicitud,
                        $resSupport
                    );
                    logMailAttempt(
                        $pdo,
                        $idSolicitud > 0 ? $idSolicitud : null,
                        'autorespuesta',
                        '(sin email)',
                        'SistemaX.Pro - Continuación de activación',
                        $resReply
                    );
                    logMailAttempt(
                        $pdo,
                        $idSolicitud > 0 ? $idSolicitud : null,
                        'whatsapp_admin_queue',
                        ($adminWaTarget !== '' ? $adminWaTarget : '(sin destino)'),
                        'WhatsApp soporte - Cola',
                        $resAdminWa
                    );
                    logMailAttempt(
                        $pdo,
                        $idSolicitud > 0 ? $idSolicitud : null,
                        'whatsapp_solicitante_queue',
                        $formTelefono,
                        'WhatsApp solicitante - Cola',
                        $resSolicWa
                    );

                    if (
                        empty($resSupport['success'])
                        || empty($resReply['success'])
                        || empty($resAdminWa['success'])
                        || empty($resSolicWa['success'])
                        || (($waDispatch['failed'] ?? 0) > 0)
                    ) {
                        $successWarning = 'La solicitud fue registrada, pero hubo un problema al enviar una o más notificaciones automáticas.';
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = $e->getMessage() ?: 'No se pudo enviar la solicitud en este momento.';
                    error_log('Suscribete error: ' . $e->getMessage());
                }
            }
        }

        // PRG: evita que al reabrir se mantengan datos/error del POST.
        $_SESSION['suscribete_flash'] = [
            'ok' => $ok ? 1 : 0,
            'error' => $error,
            'success_email' => $successEmail,
            'success_whatsapp' => $successWhatsapp,
            'success_warning' => $successWarning,
        ];
        header('Location: /public/suscribete.php');
        exit;
    }
} catch (Exception $e) {
    $error = 'No se pudo inicializar el formulario.';
    error_log('Suscribete init error: ' . $e->getMessage());
}

if ($flash !== null) {
    $ok = !empty($flash['ok']);
    $error = (string)($flash['error'] ?? '');
    $successEmail = (string)($flash['success_email'] ?? '');
    $successWhatsapp = (string)($flash['success_whatsapp'] ?? '');
    $successWarning = (string)($flash['success_warning'] ?? '');
}

$showSuccessModal = (!$directRegisterMode && $tokenMode === '' && $ok);
$showErrorModal = ($error !== '');
$registroPlanQuery = '/public/suscribete.php?registro=1&plan=' . rawurlencode($selectedPlanCodigo) . '&v=20260327183227';
?>
<!doctype html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#3b82f6">
    <title>Suscribite gratis - SistemaX</title>

    <style id="dark-inputs-critical">
        html.dark input[type="text"],
        html.dark input[type="password"],
        .dark input[type="text"],
        .dark input[type="password"],
        :root.dark input[type="text"],
        :root.dark input[type="password"] {
            background-color: #1e293b !important;
            border-color: #475569 !important;
            color: #f1f5f9 !important;
        }

        #video-background {
            position: fixed;
            top: 50%;
            left: 50%;
            min-width: 100%;
            min-height: 100%;
            width: auto;
            height: auto;
            transform: translate(-50%, -50%);
            z-index: -2;
            object-fit: cover;
            opacity: 0;
            transition: opacity 1.5s ease-in-out;
        }

        #video-background.loaded {
            opacity: 1;
        }

        #video-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: -1;
            background: linear-gradient(135deg,
                rgba(15, 23, 42, 0.38) 0%,
                rgba(55, 65, 81, 0.24) 50%,
                rgba(15, 23, 42, 0.38) 100%);
            pointer-events: none;
        }

        .dark #video-overlay {
            background: linear-gradient(135deg,
                rgba(2, 6, 23, 0.52) 0%,
                rgba(31, 41, 55, 0.34) 50%,
                rgba(2, 6, 23, 0.52) 100%);
        }

        body {
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-repeat: no-repeat;
        }

        .liquid-glass-card {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.72), rgba(255, 255, 255, 0.46));
            backdrop-filter: blur(64px) saturate(210%) brightness(1.04);
            -webkit-backdrop-filter: blur(64px) saturate(210%) brightness(1.04);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow:
                0 30px 70px -22px rgba(15, 23, 42, 0.35),
                0 8px 18px -8px rgba(15, 23, 42, 0.18),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.62),
                inset 0 -1px 0 0 rgba(255, 255, 255, 0.24);
            position: relative;
            overflow: hidden;
        }

        .dark .liquid-glass-card {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.62), rgba(15, 23, 42, 0.48));
            backdrop-filter: blur(64px) saturate(230%) brightness(0.9);
            -webkit-backdrop-filter: blur(64px) saturate(230%) brightness(0.9);
            border: 1px solid rgba(148, 163, 184, 0.22);
            box-shadow:
                0 35px 80px -26px rgba(0, 0, 0, 0.7),
                0 10px 22px -10px rgba(0, 0, 0, 0.45),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.13),
                inset 0 -1px 0 0 rgba(255, 255, 255, 0.04),
                0 0 90px -26px rgba(148, 163, 184, 0.18);
        }

        .liquid-glass-input {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.78), rgba(255, 255, 255, 0.54)) !important;
            backdrop-filter: blur(30px) saturate(175%);
            -webkit-backdrop-filter: blur(30px) saturate(175%);
            border: 1px solid rgba(255, 255, 255, 0.48) !important;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.62),
                inset 0 -1px 0 rgba(255, 255, 255, 0.2),
                0 6px 18px -12px rgba(15, 23, 42, 0.22);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .dark .liquid-glass-input {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.7), rgba(15, 23, 42, 0.58)) !important;
            backdrop-filter: blur(30px) saturate(195%);
            -webkit-backdrop-filter: blur(30px) saturate(195%);
            border: 1px solid rgba(148, 163, 184, 0.24) !important;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.1),
                inset 0 -1px 0 rgba(255, 255, 255, 0.04),
                0 6px 18px -12px rgba(0, 0, 0, 0.45);
        }

        .liquid-glass-input:focus {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.86), rgba(255, 255, 255, 0.7)) !important;
            border: 1px solid rgba(100, 116, 139, 0.65) !important;
            box-shadow:
                0 0 0 4px rgba(148, 163, 184, 0.2),
                0 12px 28px -16px rgba(71, 85, 105, 0.36),
                inset 0 1px 0 rgba(255, 255, 255, 0.72);
            transform: translateY(-1px);
        }

        .dark .liquid-glass-input:focus {
            background: linear-gradient(145deg, rgba(30, 41, 59, 0.8), rgba(15, 23, 42, 0.68)) !important;
            border: 1px solid rgba(148, 163, 184, 0.62) !important;
            box-shadow:
                0 0 0 4px rgba(148, 163, 184, 0.22),
                0 12px 28px -16px rgba(71, 85, 105, 0.42),
                inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        .liquid-glass-button {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.62) 0%, rgba(29, 78, 216, 0.54) 100%);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(191, 219, 254, 0.55);
            box-shadow:
                0 10px 26px -6px rgba(37, 99, 235, 0.42),
                0 4px 10px -2px rgba(30, 64, 175, 0.28),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.25),
                inset 0 -1px 0 0 rgba(0, 0, 0, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .liquid-glass-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.2) 0%, transparent 100%);
            pointer-events: none;
        }

        .liquid-glass-button:hover {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.78) 0%, rgba(29, 78, 216, 0.72) 100%);
            box-shadow:
                0 14px 34px -6px rgba(37, 99, 235, 0.5),
                0 6px 12px -2px rgba(30, 64, 175, 0.34),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.3),
                0 0 40px -8px rgba(59, 130, 246, 0.32);
            transform: translateY(-3px);
        }

        .liquid-glass-button:active {
            transform: translateY(-1px);
            box-shadow:
                0 8px 18px -6px rgba(30, 64, 175, 0.34),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.2);
        }
        [x-cloak] { display: none !important; }

        @keyframes fade-in {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slide-up {
            from { opacity: 0; transform: translateY(22px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-fade-in {
            animation: fade-in 0.5s ease-out both;
        }

        .animate-slide-up {
            animation: slide-up 0.55s ease-out both;
        }

        .suscribete-shell {
            width: 100%;
        }

        .suscribete-card {
            width: 100%;
        }

        .suscribete-card--cover-screen {
            width: 100%;
            max-width: 1180px;
            min-height: auto;
            margin: 0 auto;
        }

        @media (min-width: 768px) {
            .suscribete-shell--wide {
                width: 100%;
                max-width: 1180px;
                min-width: 0;
            }
            .suscribete-shell--compact {
                width: 100%;
                max-width: 28rem;
            }

            .suscribete-card--cover-screen {
                width: 100%;
                max-width: 1180px;
                min-height: auto;
            }
        }

        @media (max-width: 767px) {
            .suscribete-shell--fullscreen-mobile {
                position: fixed;
                inset: 0;
                width: 100vw;
                max-width: none;
                min-height: 100vh;
                min-height: 100dvh;
                margin: 0 !important;
                padding-left: 0;
                padding-right: 0;
                padding-top: 0;
                padding-bottom: 0;
                overflow-y: auto;
                overflow-x: hidden;
                -webkit-overflow-scrolling: touch;
                touch-action: pan-y;
            }

            .suscribete-card--fullscreen-mobile {
                width: 100vw;
                max-width: none;
                min-height: 100vh;
                min-height: 100dvh;
                border-radius: 0;
                border-left: 0;
                border-right: 0;
                overflow: visible;
                padding-top: calc(1.25rem + env(safe-area-inset-top));
                padding-right: calc(1rem + env(safe-area-inset-right));
                padding-bottom: calc(1.25rem + env(safe-area-inset-bottom));
                padding-left: calc(1rem + env(safe-area-inset-left));
            }

            .register-card-copy {
                margin-bottom: 1rem;
            }

            .register-form {
                gap: 0.65rem;
            }

            .register-panel {
                padding: 0.75rem;
            }

            .register-grid-compact,
            .register-phone-grid,
            .register-actions {
                grid-template-columns: 1fr !important;
            }

            .register-actions {
                display: grid;
                gap: 0.65rem;
            }

            .register-actions > * {
                width: 100%;
                justify-content: center;
                text-align: center;
            }

            .register-panel label,
            .register-panel p,
            .register-form input,
            .register-form select,
            .register-form button,
            .register-form a {
                font-size: 0.875rem;
            }
        }

        .logo-helper-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .logo-helper-status {
            display: none;
            margin-top: 0.45rem;
            font-size: 0.75rem;
            color: rgb(71 85 105);
        }

        .logo-helper-status.is-visible {
            display: block;
        }

        .logo-url-preview {
            display: none;
            align-items: center;
            gap: 0.75rem;
            margin-top: 0.75rem;
            padding: 0.75rem;
            border-radius: 0.75rem;
            background: rgba(255, 255, 255, 0.28);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .logo-url-preview.is-visible {
            display: flex;
        }

        .logo-url-preview img {
            width: 56px;
            height: 56px;
            object-fit: contain;
            border-radius: 0.75rem;
            background: rgba(255, 255, 255, 0.9);
            padding: 0.35rem;
        }

        .location-capture-wrap {
            position: relative;
        }

        .location-capture-btn {
            position: absolute;
            right: 0.45rem;
            top: 50%;
            transform: translateY(-50%);
            width: 2rem;
            height: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            background: rgba(37, 99, 235, 0.16);
            color: #1d4ed8;
        }

        .location-status {
            display: none;
            margin-top: 0.45rem;
            font-size: 0.75rem;
            color: rgb(71 85 105);
        }

        .location-status.is-visible {
            display: block;
        }

        .mobile-safe-area {
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }

        .register-panel .grid,
        .register-panel .grid > *,
        .register-form .grid,
        .register-form .grid > *,
        .ruc-lookup-wrap,
        .ruc-lookup-wrap > * {
            min-width: 0;
        }

        .register-form input,
        .register-form select,
        .register-form textarea,
        .register-form .liquid-glass-input {
            width: 100%;
            max-width: 100%;
        }

        input,
        button {
            -webkit-tap-highlight-color: transparent;
        }

        @media screen and (max-width: 768px) {
            input[type="text"],
            input[type="password"] {
                font-size: 16px !important;
            }
        }

        @supports (-webkit-touch-callout: none) {
            body {
                -webkit-user-select: none;
                user-select: none;
            }

            input,
            textarea {
                -webkit-user-select: text;
                user-select: text;
            }
        }
    </style>

    <link rel="stylesheet" href="assets/tailwind.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
</head>
<body
    x-data="{ showSuccessModal: <?= $showSuccessModal ? 'true' : 'false' ?>, noticeOpen: <?= $showErrorModal ? 'true' : 'false' ?>, noticeMessage: <?= json_encode((string)$error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> }"
    x-on:suscribete:notice.window="noticeMessage = $event.detail.message || 'Revisá los campos requeridos.'; noticeOpen = true"
    class="bg-gradient-to-br from-blue-500 via-blue-500 to-cyan-500 dark:bg-gradient-to-br dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 min-h-screen flex <?= ($tokenMode !== '' || $directRegisterMode) ? 'items-stretch md:items-center' : 'items-center' ?> justify-center px-0 md:px-4 py-0 supports-[height:100dvh]:min-h-[100dvh] transition-colors duration-300">
<video id="video-background" autoplay muted loop playsinline>
    <source src="" type="video/mp4">
</video>
<div id="video-overlay"></div>

    <main
    id="suscribeteMain"
    class="suscribete-shell <?= ($tokenMode !== '' || $directRegisterMode) ? 'suscribete-shell--wide suscribete-shell--fullscreen-mobile' : 'suscribete-shell--compact' ?> mobile-safe-area mx-auto px-3 md:px-4 py-6 md:py-8"
    <?= ($tokenMode !== '' || $directRegisterMode) ? 'style="width:100%;max-width:1180px;"' : '' ?>>
    <div
        id="suscribeteCard"
        class="suscribete-card liquid-glass-card rounded-2xl p-6 md:p-8 animate-slide-up transition-all duration-500 hover:shadow-2xl <?= ($tokenMode !== '' || $directRegisterMode) ? 'suscribete-card--fullscreen-mobile suscribete-card--cover-screen' : '' ?>"
        <?= ($tokenMode !== '' || $directRegisterMode) ? 'style="width:100%;max-width:1180px;"' : '' ?>>
        <?php if ($tokenMode === ''): ?>
            <div class="text-center mb-4 md:mb-6">
                <img src="/public/assets/images/logo-sistemaxpro.png" alt="sistemax.pro" class="w-28 md:w-32 h-auto object-contain mx-auto">
            </div>
        <?php endif; ?>
        <?php if ($tokenMode !== '' || $directRegisterMode): ?>
            <h1 class="text-2xl font-bold mb-1 text-slate-800 dark:text-slate-100">Registro inicial de empresa</h1>
            <p class="register-card-copy text-sm text-slate-600 dark:text-slate-300 mb-4">Completá tus datos. Al enviar, se crea la empresa, tu usuario administrador y queda configurado el plan seleccionado con sus apps habilitadas.</p>

            <div class="mb-6 rounded-2xl border border-cyan-300/40 bg-white/45 dark:bg-slate-900/35 p-4 shadow-[0_12px_36px_-24px_rgba(14,165,233,0.65)]">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <div class="inline-flex items-center rounded-full border border-cyan-300/40 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-cyan-700 dark:text-cyan-200">
                            <?= esc($selectedPlan['badge']) ?>
                        </div>
                        <h2 class="mt-3 text-xl font-black text-slate-900 dark:text-slate-100"><?= esc($selectedPlan['nombre']) ?></h2>
                        <p class="mt-1 text-sm text-slate-700 dark:text-slate-300"><?= esc($selectedPlan['descripcion']) ?></p>
                    </div>
                    <div class="text-left md:text-right">
                        <div class="text-sm uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Plan seleccionado</div>
                        <div class="mt-1 text-3xl font-black text-cyan-700 dark:text-cyan-200"><?= esc($selectedPlan['precio_label']) ?></div>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-2">
                    <?php foreach (($selectedPlan['resumen'] ?? []) as $feature): ?>
                        <div class="rounded-xl border border-white/30 bg-white/45 dark:bg-slate-950/25 px-3 py-2 text-sm text-slate-700 dark:text-slate-200">
                            <i class="fa-solid fa-check mr-2 text-cyan-600 dark:text-cyan-300"></i><?= esc($feature) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($tokenDone): ?>
                <div class="mb-6 rounded-lg border border-emerald-300/70 bg-emerald-100/70 dark:bg-emerald-900/30 px-4 py-3 text-emerald-800 dark:text-emerald-300 text-sm">
                    Registro completado. Empresa y usuario administrador creados correctamente.
                    <?php if ($tokenMode !== '' && !empty($solicitudToken['ruc'])): ?>
                        Empresa vinculada al RUC <?= esc($solicitudToken['ruc']) ?>.
                    <?php endif; ?>
                    <div class="mt-2">
                        <?php if (is_array($tokenAdminCheck) && !empty($tokenAdminCheck['ok'])): ?>
                            <span class="inline-flex items-center gap-2 rounded-md px-2 py-1 text-xs font-semibold bg-emerald-500/20 border border-emerald-400/60 text-emerald-900 dark:text-emerald-200">
                                Admin creado con privilegios: OK
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-2 rounded-md px-2 py-1 text-xs font-semibold bg-amber-500/20 border border-amber-400/60 text-amber-900 dark:text-amber-200">
                                Admin creado, pero no se pudo verificar privilegios en este momento.
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mb-6 rounded-2xl border border-cyan-300/35 bg-white/45 dark:bg-slate-900/35 p-4 shadow-[0_12px_36px_-24px_rgba(14,165,233,0.65)]">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div class="text-[11px] uppercase tracking-[0.22em] text-cyan-700 dark:text-cyan-200">Progreso de la suscripción</div>
                            <h3 class="mt-1 text-lg font-black text-slate-900 dark:text-slate-100"><?= esc($selectedPlan['nombre']) ?> activado</h3>
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">La empresa quedó dada de alta con las apps y límites iniciales de este plan.</p>
                        </div>
                        <div class="text-left md:text-right">
                            <div class="text-3xl font-black text-cyan-700 dark:text-cyan-200">100%</div>
                            <div class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Configuración inicial</div>
                        </div>
                    </div>
                    <div class="mt-4 h-3 overflow-hidden rounded-full bg-slate-200/80 dark:bg-slate-800/80">
                        <div class="h-full w-full rounded-full bg-gradient-to-r from-cyan-500 via-sky-500 to-emerald-500"></div>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-2 md:grid-cols-4">
                        <div class="rounded-xl border border-white/30 bg-white/45 dark:bg-slate-950/25 px-3 py-2">
                            <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Usuarios</div>
                            <div class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100"><?= esc(($selectedPlan['limits']['max_usuarios'] ?? null) ? (string)$selectedPlan['limits']['max_usuarios'] : 'Según necesidad') ?></div>
                        </div>
                        <div class="rounded-xl border border-white/30 bg-white/45 dark:bg-slate-950/25 px-3 py-2">
                            <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Sucursales</div>
                            <div class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100"><?= esc(($selectedPlan['limits']['max_sucursales'] ?? null) ? (string)$selectedPlan['limits']['max_sucursales'] : 'Según necesidad') ?></div>
                        </div>
                        <div class="rounded-xl border border-white/30 bg-white/45 dark:bg-slate-950/25 px-3 py-2">
                            <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Comprobantes/mes</div>
                            <div class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100"><?= esc(($selectedPlan['limits']['max_comprobantes_mes'] ?? null) ? number_format((int)$selectedPlan['limits']['max_comprobantes_mes'], 0, ',', '.') : 'Alto volumen') ?></div>
                        </div>
                        <div class="rounded-xl border border-white/30 bg-white/45 dark:bg-slate-950/25 px-3 py-2">
                            <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Apps habilitadas</div>
                            <div class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100"><?= esc(!empty($selectedPlan['apps']) ? (string)count($selectedPlan['apps']) : 'Completo') ?></div>
                        </div>
                    </div>
                </div>
                <a href="/public/login.php" class="inline-flex items-center px-4 py-2 rounded-lg liquid-glass-button text-white font-semibold hover:brightness-110">Ir al login</a>
            <?php else: ?>
                    <form method="post" class="register-form space-y-3" enctype="multipart/form-data" autocomplete="off">
                        <?php if ($tokenMode !== ''): ?>
                            <input type="hidden" name="token" value="<?= esc($tokenMode) ?>">
                        <?php else: ?>
                            <input type="hidden" name="registro" value="1">
                        <?php endif; ?>
                        <input type="hidden" name="plan" value="<?= esc($tokenForm['plan']) ?>">

                        <div class="register-panel rounded-lg border border-white/40 dark:border-slate-500/30 bg-white/35 dark:bg-slate-900/30 p-3">
                            <p class="text-xs font-semibold text-slate-600 dark:text-slate-300 mb-2">Datos de empresa</p>
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                                <input name="empresa" value="<?= esc($tokenForm['empresa']) ?>" placeholder="Empresa *" class="liquid-glass-input px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400" required>
                                <?php if (planPermiteElegirTipoNegocio($selectedPlanCodigo)): ?>
                                    <select name="tipo_negocio" class="liquid-glass-input px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100" required>
                                        <?php foreach ($tiposNegocio as $tn): $tnNombre = trim((string)($tn['nombre'] ?? '')); if ($tnNombre === '') continue; $tnDisponible = (int)($tn['disponible'] ?? 1) === 1; ?>
                                            <option value="<?= esc($tnNombre) ?>"
                                                    <?= $tokenForm['tipo_negocio'] === $tnNombre ? 'selected' : '' ?>
                                                    <?= $tnDisponible ? '' : 'disabled' ?>>
                                                <?= esc($tnNombre . ($tnDisponible ? '' : ' (En desarrollo)')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <input type="hidden" name="tipo_negocio" value="Comercial">
                                        <div class="liquid-glass-input px-3 py-2 rounded-lg text-slate-500 dark:text-slate-300 bg-slate-100/60 dark:bg-slate-900/40">
                                            Comercial
                                        </div>
                                        <p class="text-[11px] text-slate-600 dark:text-slate-400">`Tipo de negocio` solo se puede elegir en el plan Empresa FE.</p>
                                    </div>
                                <?php endif; ?>
                                <div class="ruc-lookup-wrap">
                                    <div class="grid grid-cols-[1fr_auto] gap-2">
                                        <input name="ruc" value="<?= esc($tokenForm['ruc']) ?>" placeholder="RUC/CI * (sin dígito verificador para buscar SET)" class="liquid-glass-input ruc-search-field px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400" required>
                                        <button type="button" class="ruc-lookup-btn px-3 py-2 rounded-lg liquid-glass-button text-white text-xs font-semibold whitespace-nowrap">Buscar SET</button>
                                    </div>
                                    <p class="ruc-lookup-status text-[11px] mt-1 text-slate-700 dark:text-slate-300 hidden"></p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mt-3">
                                <input type="email" name="email" value="<?= esc($tokenForm['email']) ?>" placeholder="Email (opcional)" class="liquid-glass-input px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400">
                                <div class="register-phone-grid grid grid-cols-[165px_1fr] gap-2">
                                    <select name="phone_country" class="liquid-glass-input px-2 py-2 rounded-lg text-slate-800 dark:text-slate-100">
                                        <?php foreach (phoneCountries() as $country): ?>
                                            <option value="<?= esc($country['code']) ?>" <?= $tokenForm['phone_country'] === $country['code'] ? 'selected' : '' ?>>
                                                <?= esc($country['flag']) ?> <?= esc($country['name']) ?> <?= esc($country['code']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input name="phone_number" inputmode="tel" autocomplete="tel-national" value="<?= esc($tokenForm['phone_number']) ?>" placeholder="Número WhatsApp *" class="liquid-glass-input phone-number-input px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400" required>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-[1.4fr_1fr_1fr] gap-3 mt-3">
                                <div class="location-capture-wrap">
                                    <input name="direccion" value="<?= esc($tokenForm['direccion']) ?>" placeholder="Dirección" class="liquid-glass-input w-full px-3 py-2 pr-10 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400">
                                    <button type="button" class="location-capture-btn" aria-label="Capturar dirección actual" title="Usar mi ubicación actual">
                                        <i class="fa-solid fa-location-crosshairs"></i>
                                    </button>
                                    <p class="location-status"></p>
                                </div>
                                <input name="ciudad" value="<?= esc($tokenForm['ciudad']) ?>" placeholder="Ciudad" class="liquid-glass-input px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400">
                                <input name="pais" value="<?= esc($tokenForm['pais']) ?>" placeholder="País" class="liquid-glass-input px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400">
                            </div>
                            <div class="mt-3">
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-2">Logo de empresa (opcional)</label>
                                <input type="file" name="logo_empresa" accept="image/png,image/jpeg,image/webp,image/gif" class="liquid-glass-input w-full px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100">
                                <div class="logo-helper-actions mt-2">
                                    <button type="button" class="logo-google-search px-3 py-2 rounded-lg liquid-glass-button text-white text-xs font-semibold">Buscar en Google Images</button>
                                    <button type="button" class="logo-paste-image px-3 py-2 rounded-lg liquid-glass-button text-white text-xs font-semibold">Pegar imagen copiada</button>
                                </div>
                                <p class="logo-helper-status"></p>
                                <input type="url" name="logo_url" value="<?= esc($tokenForm['logo_url']) ?>" placeholder="Pegá aquí la URL de la imagen elegida" class="liquid-glass-input logo-url-input w-full mt-2 px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400">
                                <p class="text-[11px] mt-2 text-slate-600 dark:text-slate-300">Se abrirá Google Images con el nombre de la empresa. Elegí una imagen, copiá la dirección de imagen y pegala aquí.</p>
                                <div class="logo-url-preview<?= $tokenForm['logo_url'] !== '' ? ' is-visible' : '' ?>">
                                    <img src="<?= esc($tokenForm['logo_url'] !== '' ? $tokenForm['logo_url'] : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==') ?>" alt="Preview logo URL" class="logo-url-preview-image">
                                    <div class="text-[11px] text-slate-700 dark:text-slate-300">Vista previa del logo elegido por URL.</div>
                                </div>
                            </div>
                        </div>

                        <div class="register-panel rounded-lg border border-white/40 dark:border-slate-500/30 bg-white/35 dark:bg-slate-900/30 p-3">
                            <p class="text-xs font-semibold text-slate-600 dark:text-slate-300 mb-2">Usuario administrador</p>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <input name="nombre" value="<?= esc($tokenForm['nombre']) ?>" placeholder="Nombre administrador *" class="liquid-glass-input px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400" required>
                                <input name="ci_admin" inputmode="numeric" value="<?= esc($tokenForm['ci_admin']) ?>" placeholder="CI administrador *" class="liquid-glass-input px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400" required>
                                <input name="login" value="<?= esc($tokenForm['login']) ?>" placeholder="Usuario admin *" autocomplete="off" autocapitalize="none" spellcheck="false" class="liquid-glass-input admin-login-input px-3 py-2 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400" required>
                            </div>
                            <p class="login-check-status text-[11px] mt-2 text-slate-700 dark:text-slate-300 hidden"></p>
                        </div>

                        <div class="register-grid-compact grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="relative">
                                <input type="password" name="password" placeholder="Contraseña *" autocomplete="new-password" class="liquid-glass-input w-full px-3 py-2 pr-10 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400" required>
                                <button type="button" class="password-toggle absolute right-2 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-300 hover:text-slate-700 dark:hover:text-slate-100" tabindex="-1" aria-label="Mostrar contraseña">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <div class="relative">
                                <input type="password" name="password2" placeholder="Confirmar contraseña *" autocomplete="new-password" class="liquid-glass-input w-full px-3 py-2 pr-10 rounded-lg text-slate-800 dark:text-slate-100 placeholder:text-slate-500 dark:placeholder:text-slate-400" required>
                                <button type="button" class="password-toggle absolute right-2 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-300 hover:text-slate-700 dark:hover:text-slate-100" tabindex="-1" aria-label="Mostrar contraseña">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="register-actions flex items-center gap-3 pt-1">
                            <button type="submit" class="px-6 py-2.5 rounded-lg liquid-glass-button text-white font-semibold hover:brightness-110">Crear empresa y admin</button>
                            <a href="/public/index.php" class="px-6 py-2.5 rounded-lg text-sm text-slate-700 dark:text-slate-300 liquid-glass-input hover:brightness-105">Volver al landing</a>
                        </div>
                    </form>
            <?php endif; ?>

        <?php elseif ($ok): ?>
            <h1 class="text-2xl font-bold mb-1 text-slate-800 dark:text-slate-100">Registro directo habilitado</h1>
            <p class="text-sm text-slate-600 dark:text-slate-300 mb-6">Ahora podés crear empresa y administrador de forma inmediata, sin esperar invitación.</p>
            <div class="mb-6 rounded-lg border border-emerald-300/70 bg-emerald-100/70 dark:bg-emerald-900/30 px-4 py-3 text-emerald-800 dark:text-emerald-300 text-sm">
                Continuá con el registro inicial para activar tu cuenta.
            </div>
            <a href="<?= esc($registroPlanQuery) ?>" class="inline-flex items-center px-4 py-2 rounded-lg liquid-glass-button text-white font-semibold hover:brightness-110">Abrir registro inicial</a>
        <?php else: ?>
            <h1 class="text-2xl font-bold mb-1 text-slate-800 dark:text-slate-100">Activá tu cuenta</h1>
            <p class="text-sm text-slate-600 dark:text-slate-300 mb-6">Iniciá el registro directo para crear empresa, usuario administrador y contraseña.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1 w-full">
                <a href="<?= esc($registroPlanQuery) ?>" class="liquid-glass-button w-full text-center text-white font-semibold py-3 text-base rounded-lg">Probar ahora</a>
                <a href="/public/index.php" class="w-full px-5 py-3 rounded-lg text-center liquid-glass-input text-sm font-semibold text-slate-700 dark:text-slate-200 hover:brightness-105">Volver al landing page</a>
            </div>
        <?php endif; ?>
    </div>
</main>

<div
    x-show="showSuccessModal"
    x-cloak
    class="fixed inset-0 z-[90] flex items-center justify-center bg-black/45 backdrop-blur-sm p-4">
    <div class="w-full max-w-md rounded-2xl border border-white/20 bg-white/90 dark:bg-slate-900/90 shadow-2xl p-6 animate-slide-up">
        <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-xl bg-emerald-500/20 text-emerald-600 dark:text-emerald-300 flex items-center justify-center text-xl">✓</div>
            <div>
                <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">Gracias por tu solicitud</h3>
                <p class="text-sm text-slate-700 dark:text-slate-300 mt-2">
                    En breve te estaremos enviando por WhatsApp tu link unico para registrarte en sistemax.pro:
                    <strong><?= esc($successWhatsapp !== '' ? $successWhatsapp : $successEmail) ?></strong>
                </p>
                <?php if ($successWarning !== ''): ?>
                    <p class="text-xs text-slate-600 dark:text-slate-300 mt-2">Estamos terminando de procesar tu notificacion. Si no recibis el mensaje en unos minutos, reintentaremos automaticamente.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="mt-5">
            <a href="/public/index.php" class="block w-full px-3 py-2 rounded-lg text-center liquid-glass-button text-white font-semibold">Aceptar</a>
        </div>
    </div>
</div>

<div
    x-show="noticeOpen"
    x-cloak
    class="fixed inset-0 z-[95] flex items-center justify-center bg-slate-950/28 backdrop-blur-md p-4">
    <div class="w-full max-w-sm rounded-3xl border border-white/25 bg-white/78 dark:bg-slate-900/80 shadow-[0_24px_80px_-24px_rgba(15,23,42,0.55)] p-5 text-center animate-slide-up">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-500/12 text-rose-600 dark:text-rose-300">
            <i class="fa-solid fa-circle-exclamation text-lg"></i>
        </div>
        <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Revisá el formulario</h3>
        <p class="mt-2 text-sm leading-6 text-slate-700 dark:text-slate-300" x-text="noticeMessage"></p>
        <button @click="noticeOpen = false" type="button" class="mt-4 inline-flex min-w-[140px] items-center justify-center rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white dark:bg-slate-100 dark:text-slate-900">
            Aceptar
        </button>
    </div>
</div>

<script>
    function formatPhoneDigits(value) {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 15);
        if (digits.length <= 3) return digits;
        if (digits.length <= 6) return digits.slice(0, 3) + ' ' + digits.slice(3);
        if (digits.length <= 10) return digits.slice(0, 3) + ' ' + digits.slice(3, 6) + ' ' + digits.slice(6);
        return digits.slice(0, 3) + ' ' + digits.slice(3, 7) + ' ' + digits.slice(7);
    }

    function bindPhoneInputs() {
        const inputs = document.querySelectorAll('.phone-number-input');
        inputs.forEach((input) => {
            input.addEventListener('input', () => {
                input.value = formatPhoneDigits(input.value);
            });
            input.value = formatPhoneDigits(input.value);
        });
    }

    function setRucStatus(statusEl, type, message) {
        if (!statusEl) return;
        statusEl.classList.remove('hidden', 'text-blue-300', 'text-blue-700', 'text-rose-300', 'text-rose-700', 'text-amber-300', 'text-amber-700');
        if (type === 'ok') {
            statusEl.classList.add('text-blue-700');
            if (document.documentElement.classList.contains('dark')) statusEl.classList.replace('text-blue-700', 'text-blue-300');
        } else if (type === 'error') {
            statusEl.classList.add('text-rose-700');
            if (document.documentElement.classList.contains('dark')) statusEl.classList.replace('text-rose-700', 'text-rose-300');
        } else {
            statusEl.classList.add('text-amber-700');
            if (document.documentElement.classList.contains('dark')) statusEl.classList.replace('text-amber-700', 'text-amber-300');
        }
        statusEl.textContent = message || '';
    }

    function bindRucLookup() {
        const wraps = document.querySelectorAll('.ruc-lookup-wrap');
        wraps.forEach((wrap) => {
            const input = wrap.querySelector('.ruc-search-field');
            const btn = wrap.querySelector('.ruc-lookup-btn');
            const status = wrap.querySelector('.ruc-lookup-status');
            if (!input || !btn) return;

            const runLookup = async () => {
                const ruc = (input.value || '').trim();
                if (ruc.length < 5) {
                    setRucStatus(status, 'error', 'Ingrese un RUC/CI válido para buscar en SET.');
                    return;
                }
                setRucStatus(status, 'loading', 'Buscando en SET (SIFEN)...');
                btn.disabled = true;
                btn.classList.add('opacity-70');
                try {
                    const url = `/public/suscribete.php?action=sifen_lookup&ruc=${encodeURIComponent(ruc)}`;
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    if (!data.success || !data.data) {
                        setRucStatus(status, 'error', data.error || `El RUC/CI ${ruc} no fue encontrado en SET.`);
                        return;
                    }
                    const form = wrap.closest('form');
                    const contactoInput = form?.querySelector('input[name="contacto"]');
                    const nombreInput = form?.querySelector('input[name="nombre"]');
                    const empresaInput = form?.querySelector('input[name="empresa"]');

                    // Prioridad solicitada: completar nombre de contacto (o nombre admin) antes que empresa.
                    if (contactoInput && data.data.razon_social) {
                        contactoInput.value = data.data.razon_social;
                    } else if (nombreInput && data.data.razon_social) {
                        nombreInput.value = data.data.razon_social;
                    } else if (empresaInput && data.data.razon_social) {
                        empresaInput.value = data.data.razon_social;
                    }
                    if (data.data.ruc) {
                        input.value = data.data.ruc;
                    }
                    setRucStatus(status, 'ok', `Cliente encontrado: ${data.data.razon_social || ''}`);
                } catch (e) {
                    setRucStatus(status, 'error', 'No se pudo conectar con el servicio SET (SIFEN).');
                } finally {
                    btn.disabled = false;
                    btn.classList.remove('opacity-70');
                }
            };

            btn.addEventListener('click', runLookup);
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    runLookup();
                }
            });
        });
    }

    function bindPasswordToggles() {
        const buttons = document.querySelectorAll('.password-toggle');
        buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const wrap = btn.closest('.relative');
                const input = wrap ? wrap.querySelector('input[type="password"], input[type="text"]') : null;
                const icon = btn.querySelector('i');
                if (!input || !icon) return;
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                icon.classList.toggle('fa-eye', !show);
                icon.classList.toggle('fa-eye-slash', show);
            });
        });
    }

    function bindAdminLoginCheck() {
        const loginInputs = document.querySelectorAll('.admin-login-input');
        loginInputs.forEach((input) => {
            const form = input.closest('form');
            const status = form ? form.querySelector('.login-check-status') : null;
            let timer = null;
            let requestId = 0;

            const markStatus = (type, message) => {
                if (!status) return;
                status.classList.remove('hidden', 'text-emerald-700', 'text-emerald-300', 'text-rose-700', 'text-rose-300', 'text-amber-700', 'text-amber-300');
                if (type === 'ok') {
                    status.classList.add(document.documentElement.classList.contains('dark') ? 'text-emerald-300' : 'text-emerald-700');
                    input.dataset.loginValid = '1';
                } else if (type === 'error') {
                    status.classList.add(document.documentElement.classList.contains('dark') ? 'text-rose-300' : 'text-rose-700');
                    input.dataset.loginValid = '0';
                } else {
                    status.classList.add(document.documentElement.classList.contains('dark') ? 'text-amber-300' : 'text-amber-700');
                    input.dataset.loginValid = '';
                }
                status.textContent = message || '';
            };

            const validateNow = async () => {
                const raw = (input.value || '').trim();
                if (!raw) {
                    markStatus('warn', 'Ingrese usuario administrador.');
                    return;
                }
                if (!/^[a-z0-9._-]{4,32}$/.test(raw)) {
                    markStatus('error', 'Formato inválido: 4-32 caracteres (a-z, 0-9, . _ -).');
                    return;
                }
                requestId += 1;
                const rid = requestId;
                markStatus('warn', 'Validando disponibilidad...');
                try {
                    const res = await fetch(`/public/suscribete.php?action=admin_login_check&login=${encodeURIComponent(raw)}`, {
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await res.json();
                    if (rid !== requestId) return;
                    if (!data || !data.success) {
                        markStatus('error', data?.error || 'No se pudo validar el usuario.');
                        return;
                    }
                    if (data.exists) {
                        markStatus('error', `El usuario "${data.login || raw}" ya existe. Elegí otro.`);
                    } else {
                        if (data.login && data.login !== raw) {
                            input.value = data.login;
                        }
                        markStatus('ok', `Usuario disponible: ${data.login || raw}`);
                    }
                } catch (e) {
                    markStatus('error', 'Error de red validando el usuario.');
                }
            };

            input.addEventListener('input', () => {
                input.dataset.loginValid = '';
                if (timer) clearTimeout(timer);
                timer = setTimeout(validateNow, 350);
            });
            input.addEventListener('blur', validateNow);

            if (form) {
                form.addEventListener('submit', (e) => {
                    if (input.dataset.loginValid === '0') {
                        e.preventDefault();
                        input.focus();
                    }
                });
            }
        });
    }

    function bindLogoHelpers() {
        document.querySelectorAll('form').forEach((form) => {
            const empresaInput = form.querySelector('input[name="empresa"]');
            const googleBtn = form.querySelector('.logo-google-search');
            const pasteBtn = form.querySelector('.logo-paste-image');
            const fileInput = form.querySelector('input[name="logo_empresa"]');
            const logoUrlInput = form.querySelector('.logo-url-input');
            const previewWrap = form.querySelector('.logo-url-preview');
            const previewImg = form.querySelector('.logo-url-preview-image');
            const helperStatus = form.querySelector('.logo-helper-status');

            const setHelperStatus = (message, isError = false) => {
                if (!helperStatus) return;
                helperStatus.textContent = message || '';
                helperStatus.classList.toggle('is-visible', !!message);
                helperStatus.style.color = isError ? '#be123c' : '';
            };

            if (googleBtn) {
                googleBtn.addEventListener('click', () => {
                    const empresa = (empresaInput?.value || '').trim();
                    if (!empresa) {
                        if (empresaInput) empresaInput.focus();
                        return;
                    }
                    const query = `${empresa} logo`;
                    const url = `https://www.google.com/search?tbm=isch&q=${encodeURIComponent(query)}`;
                    window.open(url, '_blank', 'noopener,noreferrer');
                });
            }

            const refreshPreview = (src = '') => {
                if (!logoUrlInput || !previewWrap || !previewImg) return;
                const url = src || (logoUrlInput.value || '').trim();
                if (!url) {
                    previewWrap.classList.remove('is-visible');
                    previewImg.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
                    return;
                }
                previewImg.src = url;
                previewWrap.classList.add('is-visible');
            };

            if (logoUrlInput) {
                logoUrlInput.addEventListener('input', refreshPreview);
                refreshPreview();
            }

            if (fileInput) {
                fileInput.addEventListener('change', () => {
                    const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                    if (!file) return;
                    const blobUrl = URL.createObjectURL(file);
                    refreshPreview(blobUrl);
                    if (logoUrlInput) {
                        logoUrlInput.value = '';
                    }
                    setHelperStatus(`Imagen cargada: ${file.name}`);
                });
            }

            if (pasteBtn) {
                pasteBtn.addEventListener('click', async () => {
                    if (!navigator.clipboard || !navigator.clipboard.read) {
                        setHelperStatus('Tu navegador no permite pegar imágenes desde el portapapeles.', true);
                        return;
                    }

                    try {
                        setHelperStatus('Leyendo imagen copiada...');
                        const items = await navigator.clipboard.read();
                        let imageBlob = null;
                        for (const item of items) {
                            const type = item.types.find((entry) => entry.startsWith('image/'));
                            if (!type) continue;
                            imageBlob = await item.getType(type);
                            break;
                        }

                        if (!imageBlob) {
                            setHelperStatus('No se encontró una imagen copiada en el portapapeles.', true);
                            return;
                        }

                        const extension = (imageBlob.type.split('/')[1] || 'png').replace(/[^a-z0-9]/gi, '');
                        const file = new File([imageBlob], `logo_copiado.${extension}`, { type: imageBlob.type || 'image/png' });
                        if (!fileInput) {
                            setHelperStatus('No se encontró el campo de archivo para el logo.', true);
                            return;
                        }

                        const dt = new DataTransfer();
                        dt.items.add(file);
                        fileInput.files = dt.files;
                        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
                        setHelperStatus('Imagen copiada aplicada al logo.');
                    } catch (error) {
                        setHelperStatus('No se pudo leer la imagen copiada. Permití acceso al portapapeles.', true);
                    }
                });
            }
        });
    }

    function bindLocationCapture() {
        document.querySelectorAll('form').forEach((form) => {
            const button = form.querySelector('.location-capture-btn');
            const status = form.querySelector('.location-status');
            const direccionInput = form.querySelector('input[name="direccion"]');
            const ciudadInput = form.querySelector('input[name="ciudad"]');
            const paisInput = form.querySelector('input[name="pais"]');
            if (!button || !direccionInput || !ciudadInput || !paisInput) return;

            const setStatus = (message, isError = false) => {
                if (!status) return;
                status.textContent = message || '';
                status.classList.toggle('is-visible', !!message);
                status.style.color = isError ? '#be123c' : '';
            };

            const pickCity = (address) => {
                return address.city || address.town || address.village || address.municipality || address.county || address.state_district || address.state || '';
            };

            const buildAddress = (data) => {
                const address = data.address || {};
                const parts = [
                    address.road || address.pedestrian || address.cycleway || address.footway || '',
                    address.house_number || '',
                    address.suburb || address.neighbourhood || address.quarter || '',
                ].filter(Boolean);
                return parts.join(', ') || (data.display_name || '');
            };

            button.addEventListener('click', async () => {
                if (!navigator.geolocation) {
                    setStatus('Tu navegador no soporta geolocalización.', true);
                    return;
                }

                setStatus('Obteniendo ubicación actual...');
                button.disabled = true;
                try {
                    const position = await new Promise((resolve, reject) => {
                        navigator.geolocation.getCurrentPosition(resolve, reject, {
                            enableHighAccuracy: true,
                            timeout: 10000,
                            maximumAge: 0,
                        });
                    });

                    const lat = position.coords.latitude;
                    const lon = position.coords.longitude;
                    setStatus('Buscando dirección...');
                    const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}&zoom=18&addressdetails=1`;
                    const res = await fetch(url, {
                        headers: {
                            'Accept': 'application/json',
                        },
                    });
                    const data = await res.json();
                    const address = data.address || {};

                    direccionInput.value = buildAddress(data);
                    ciudadInput.value = pickCity(address) || ciudadInput.value;
                    paisInput.value = address.country || paisInput.value || 'Paraguay';
                    setStatus('Dirección cargada desde tu ubicación actual.');
                } catch (error) {
                    setStatus('No se pudo obtener tu ubicación o dirección.', true);
                } finally {
                    button.disabled = false;
                }
            });
        });
    }

    function bindValidationNotice() {
        const emitNotice = (message) => {
            window.dispatchEvent(new CustomEvent('suscribete:notice', {
                detail: { message: message || 'Revisá los campos requeridos.' }
            }));
        };

        const fieldLabel = (field) => {
            if (!field) return 'Revisá los campos requeridos.';
            return field.getAttribute('placeholder')
                || field.getAttribute('aria-label')
                || field.name
                || 'Revisá los campos requeridos.';
        };

        document.querySelectorAll('form').forEach((form) => {
            form.setAttribute('novalidate', 'novalidate');

            form.addEventListener('submit', (event) => {
                const invalid = form.querySelector(':invalid');
                if (!invalid) return;
                event.preventDefault();
                invalid.focus();
                emitNotice(`Falta completar: ${fieldLabel(invalid)}.`);
            });

            form.querySelectorAll('input, select, textarea').forEach((field) => {
                field.addEventListener('invalid', (event) => {
                    event.preventDefault();
                    emitNotice(`Falta completar: ${fieldLabel(field)}.`);
                });
            });
        });
    }

    function forceFullscreenRegisterMobile() {
        const isRegisterMode = <?= ($tokenMode !== '' || $directRegisterMode) ? 'true' : 'false' ?>;
        if (!isRegisterMode) return;

        const apply = () => {
            const main = document.getElementById('suscribeteMain');
            const card = document.getElementById('suscribeteCard');
            if (!main || !card) return;

            if (window.innerWidth >= 768) {
                document.body.style.padding = '';
                document.body.style.alignItems = '';
                document.body.style.overflow = '';

                main.style.position = '';
                main.style.inset = '';
                main.style.width = '';
                main.style.maxWidth = '';
                main.style.minHeight = '';
                main.style.margin = '';
                main.style.padding = '';
                main.style.overflowY = '';
                main.style.overflowX = '';
                main.style.webkitOverflowScrolling = '';
                main.style.touchAction = '';

                card.style.width = '';
                card.style.maxWidth = '';
                card.style.minHeight = '';
                card.style.borderRadius = '';
                card.style.borderLeft = '';
                card.style.borderRight = '';
                card.style.overflow = '';
                card.style.paddingTop = '';
                card.style.paddingRight = '';
                card.style.paddingBottom = '';
                card.style.paddingLeft = '';
                return;
            }

            const style = window.getComputedStyle(document.documentElement);
            const safeTop = style.getPropertyValue('env(safe-area-inset-top)') || '0px';
            const safeRight = style.getPropertyValue('env(safe-area-inset-right)') || '0px';
            const safeBottom = style.getPropertyValue('env(safe-area-inset-bottom)') || '0px';
            const safeLeft = style.getPropertyValue('env(safe-area-inset-left)') || '0px';

            document.body.style.padding = '0';
            document.body.style.alignItems = 'stretch';
            document.body.style.overflow = 'hidden';

            main.style.position = 'fixed';
            main.style.inset = '0';
            main.style.width = '100vw';
            main.style.maxWidth = '100vw';
            main.style.minHeight = '100dvh';
            main.style.margin = '0';
            main.style.padding = '0';
            main.style.overflowY = 'auto';
            main.style.overflowX = 'hidden';
            main.style.webkitOverflowScrolling = 'touch';
            main.style.touchAction = 'pan-y';

            card.style.width = '100vw';
            card.style.maxWidth = '100vw';
            card.style.minHeight = '100dvh';
            card.style.borderRadius = '0';
            card.style.borderLeft = '0';
            card.style.borderRight = '0';
            card.style.overflow = 'visible';
            card.style.paddingTop = `calc(1.25rem + ${safeTop})`;
            card.style.paddingRight = `calc(1rem + ${safeRight})`;
            card.style.paddingBottom = `calc(1.25rem + ${safeBottom})`;
            card.style.paddingLeft = `calc(1rem + ${safeLeft})`;
        };

        apply();
        window.addEventListener('resize', apply);
    }

    function forceRegisterCardCoverScreen() {
        const isRegisterMode = <?= ($tokenMode !== '' || $directRegisterMode) ? 'true' : 'false' ?>;
        if (!isRegisterMode) return;

        const apply = () => {
            const main = document.getElementById('suscribeteMain');
            const card = document.getElementById('suscribeteCard');
            if (!card || !main) return;

            if (window.innerWidth >= 768) {
                main.style.setProperty('width', '', 'important');
                main.style.setProperty('max-width', '', 'important');
                card.style.setProperty('width', '', 'important');
                card.style.setProperty('max-width', '', 'important');
                card.style.setProperty('min-height', '', 'important');
                card.style.setProperty('margin', '', 'important');
                card.style.setProperty('height', '', 'important');
                return;
            }

            card.style.setProperty('width', '100vw', 'important');
            card.style.setProperty('max-width', '100vw', 'important');
            card.style.setProperty('min-height', '100dvh', 'important');
            card.style.setProperty('margin', '0 auto', 'important');
            main.style.setProperty('width', '100vw', 'important');
            main.style.setProperty('max-width', '100vw', 'important');
        };

        apply();
        window.addEventListener('resize', apply);
    }

    const PIXABAY_API_KEY = '54541717-3563ce276d45c226152a49d0b';
    const PIXABAY_API = 'https://pixabay.com/api/videos/';
    const VIDEO_QUERIES = [
        'nature+landscape',
        'abstract+technology',
        'ocean+waves',
        'night+sky+stars',
        'forest+trees',
        'city+lights+night',
        'clouds+sky+timelapse',
        'water+abstract',
        'aurora+borealis',
        'sunset+mountains'
    ];

    function getRandomQuery() {
        return VIDEO_QUERIES[Math.floor(Math.random() * VIDEO_QUERIES.length)];
    }

    function aplicarVideo(url) {
        const video = document.getElementById('video-background');
        if (!video) return;
        const source = video.querySelector('source');
        source.src = url;
        video.load();
        video.oncanplaythrough = function() {
            video.classList.add('loaded');
            video.play().catch(() => {});
        };
    }

    async function obtenerVideoSesion() {
        try {
            const cacheKey = 'pixabay_video_session';
            const cache = JSON.parse(sessionStorage.getItem(cacheKey) || '{}');
            if (cache.videoUrl) {
                aplicarVideo(cache.videoUrl);
                return;
            }
            const query = getRandomQuery();
            const url = `${PIXABAY_API}?key=${PIXABAY_API_KEY}&q=${query}&video_type=film&per_page=50&safesearch=true&min_width=1280`;
            const response = await fetch(url);
            if (!response.ok) return;
            const data = await response.json();
            if (!data.hits || !data.hits.length) return;
            const video = data.hits[Math.floor(Math.random() * data.hits.length)];
            const videoUrl = video.videos.medium?.url || video.videos.small?.url || video.videos.tiny?.url;
            if (!videoUrl) return;
            sessionStorage.setItem(cacheKey, JSON.stringify({ videoUrl, query, videoId: video.id }));
            aplicarVideo(videoUrl);
        } catch (e) {
            console.warn('[Video Wallpaper] Error', e);
        }
    }

    (function() {
        const cache = JSON.parse(sessionStorage.getItem('pixabay_video_session') || '{}');
        if (cache.videoUrl) aplicarVideo(cache.videoUrl);
    })();

    document.addEventListener('DOMContentLoaded', () => {
        const shouldClearOnOpen = <?= ($tokenMode === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') ? 'true' : 'false' ?>;
        if (shouldClearOnOpen) {
            const form = document.getElementById('suscribeteForm');
            if (form) {
                form.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"], textarea').forEach((el) => {
                    el.value = '';
                });
                const country = form.querySelector('select[name="phone_country"]');
                if (country) country.value = '+595';
            }
        }
        const directRegisterMode = <?= $directRegisterMode ? 'true' : 'false' ?>;
        if (directRegisterMode) {
            document.querySelectorAll('input[name="login"], input[name="password"], input[name="password2"]').forEach((el) => {
                el.value = '';
            });
        }
        bindPhoneInputs();
        bindRucLookup();
        bindPasswordToggles();
        bindAdminLoginCheck();
        bindLogoHelpers();
        bindLocationCapture();
        bindValidationNotice();
        forceFullscreenRegisterMobile();
        forceRegisterCardCoverScreen();
        obtenerVideoSesion();
    });
</script>
</body>
</html>
