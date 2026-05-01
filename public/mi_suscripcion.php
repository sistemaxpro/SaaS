<?php
/**
 * Mi Suscripción
 * Panel para que cada empresa vea y gestione su suscripción SaaS
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Verificar sesión
Session::requireLogin('/public/login.php');

$id_login = (int)(Session::getIdLogin() ?? 0);
$id_empresa = (int)(Session::getIdEmpresa() ?? 0);
$id_sucursal = (int)(Session::get('id_sucursal', 0) ?? 0);
$usr_priv_admin = ($_SESSION['usr_priv_admin'] ?? 'N') === 'Y';
$usr_name = $_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '';

/**
 * Resolver empresa activa priorizando sesión actual y fallback robusto.
 */
function resolveEmpresaActiva(?PDO $pdoMaster, int $idLogin = 0): int
{
    $candidatos = [
        (int)(Session::getIdEmpresa() ?? 0),
        (int)($_SESSION['id_empresa'] ?? 0),
        (int)($_SESSION['id_empresa_activa'] ?? 0),
    ];
    foreach ($candidatos as $id) {
        if ($id > 0) {
            return $id;
        }
    }

    if ($pdoMaster) {
        $dbu = trim((string)(Session::getDbase() ?? ''));
        if ($dbu !== '') {
            $stmt = $pdoMaster->prepare("SELECT id_empresa FROM empresa WHERE dbase = ? AND activo = 1 LIMIT 1");
            $stmt->execute([$dbu]);
            $idByDb = (int)($stmt->fetchColumn() ?: 0);
            if ($idByDb > 0) {
                return $idByDb;
            }
        }
        if ($idLogin > 0) {
            $stmt = $pdoMaster->prepare("SELECT id_empresa FROM sec_users WHERE id_login = ? LIMIT 1");
            $stmt->execute([$idLogin]);
            $idByUser = (int)($stmt->fetchColumn() ?: 0);
            if ($idByUser > 0) {
                return $idByUser;
            }
        }
    }

    return 0;
}

if (false) { // eliminado: redirect empresa 169 a suscripciones
}

function getOrCreateSuscripcionOperable(PDO $pdo, int $idEmpresa, ?string $createdBy = null): int
{
    // 1) Preferir suscripción activa/gracia.
    $stmt = $pdo->prepare("
        SELECT id_suscripcion
        FROM saas_suscripcion
        WHERE id_empresa = ?
          AND estado IN ('activa', 'gracia')
        ORDER BY periodo_inicio DESC, id_suscripcion DESC
        LIMIT 1
    ");
    $stmt->execute([$idEmpresa]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    // 2) Reusar si ya existe suscripción para el período actual.
    $periodoInicio = date('Y-m-01');
    $periodoFin = date('Y-m-t');
    $stmt = $pdo->prepare("
        SELECT id_suscripcion
        FROM saas_suscripcion
        WHERE id_empresa = ?
          AND periodo_inicio = ?
        ORDER BY id_suscripcion DESC
        LIMIT 1
    ");
    $stmt->execute([$idEmpresa, $periodoInicio]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    // 3) Crear una suscripción nueva del período actual.
    $diasGracia = 5;
    $fechaVencimiento = date('Y-m-d', strtotime($periodoFin . " + {$diasGracia} days"));
    $anio = date('Y');
    $mes = date('m');
    $stmt = $pdo->query("SELECT COALESCE(MAX(id_suscripcion), 0) + 1 AS siguiente FROM saas_suscripcion");
    $siguiente = (int)($stmt->fetch(PDO::FETCH_ASSOC)['siguiente'] ?? 1);
    $nroFactura = sprintf("SAAS-%s-%s-%04d", $anio, $mes, $siguiente);

    $stmt = $pdo->prepare("
        INSERT INTO saas_suscripcion
            (id_empresa, periodo_inicio, periodo_fin, nro_factura, fecha_vencimiento, estado, estado_pago, dias_gracia, created_by)
        VALUES
            (?, ?, ?, ?, ?, 'activa', 'pendiente', ?, ?)
    ");
    $stmt->execute([
        $idEmpresa,
        $periodoInicio,
        $periodoFin,
        $nroFactura,
        $fechaVencimiento,
        $diasGracia,
        $createdBy ?: null,
    ]);

    return (int)$pdo->lastInsertId();
}

function recalculateSuscripcionTotal(PDO $pdo, int $idSuscripcion): void
{
    $hasSponsor = false;
    try {
        $c = $pdo->query("SHOW COLUMNS FROM saas_suscripcion LIKE 'es_sponsor'");
        $hasSponsor = (bool)$c->fetch(PDO::FETCH_ASSOC);
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

function ensureSuscripcionAuditTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saas_suscripcion_auditoria (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            id_empresa INT NOT NULL,
            id_suscripcion INT NULL,
            id_suscripcion_app INT NULL,
            id_app INT NULL,
            accion VARCHAR(60) NOT NULL,
            detalle TEXT NULL,
            id_login INT NULL,
            usuario VARCHAR(120) NULL,
            id_sucursal INT NULL,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_empresa_fecha (id_empresa, created_at),
            KEY idx_suscripcion (id_suscripcion),
            KEY idx_app (id_app),
            KEY idx_accion (accion)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function logSuscripcionAudit(PDO $pdo, array $data): void
{
    static $ready = false;
    if (!$ready) {
        ensureSuscripcionAuditTable($pdo);
        $ready = true;
    }

    $stmt = $pdo->prepare("
        INSERT INTO saas_suscripcion_auditoria
            (id_empresa, id_suscripcion, id_suscripcion_app, id_app, accion, detalle, id_login, usuario, id_sucursal, ip, user_agent)
        VALUES
            (:id_empresa, :id_suscripcion, :id_suscripcion_app, :id_app, :accion, :detalle, :id_login, :usuario, :id_sucursal, :ip, :user_agent)
    ");
    $stmt->execute([
        ':id_empresa' => (int)($data['id_empresa'] ?? 0),
        ':id_suscripcion' => isset($data['id_suscripcion']) ? (int)$data['id_suscripcion'] : null,
        ':id_suscripcion_app' => isset($data['id_suscripcion_app']) ? (int)$data['id_suscripcion_app'] : null,
        ':id_app' => isset($data['id_app']) ? (int)$data['id_app'] : null,
        ':accion' => (string)($data['accion'] ?? 'accion_desconocida'),
        ':detalle' => isset($data['detalle']) ? (string)$data['detalle'] : null,
        ':id_login' => isset($data['id_login']) ? (int)$data['id_login'] : null,
        ':usuario' => isset($data['usuario']) ? (string)$data['usuario'] : null,
        ':id_sucursal' => isset($data['id_sucursal']) ? (int)$data['id_sucursal'] : null,
        ':ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

function saasCatalogHasColumn(PDO $pdo, string $column): bool
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
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$key]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function saasCatalogDisponibleFilter(PDO $pdo, string $alias = 'a'): string
{
    $safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'a';
    if (!saasCatalogHasColumn($pdo, 'disponible')) {
        return '';
    }
    return " AND COALESCE({$safeAlias}.disponible, 1) = 1";
}

function resolveCatalogIconSvg(string $iconoSvg): string
{
    $icon = trim($iconoSvg);
    if ($icon === '') {
        return '';
    }

    if (str_starts_with($icon, '<svg')) {
        return 'data:image/svg+xml;base64,' . base64_encode($icon);
    }

    if (preg_match('~^https?://~i', $icon)) {
        return $icon;
    }

    $path = (string)(parse_url($icon, PHP_URL_PATH) ?? '');
    if ($path === '') {
        $path = $icon;
    }
    if (strpos($path, '..') !== false) {
        return '';
    }

    $projectRoot = dirname(__DIR__);

    if (str_starts_with($path, '/public/')) {
        $disk = $projectRoot . $path;
        return is_file($disk) ? $path : '';
    }

    if (str_starts_with($path, '/')) {
        $disk = $projectRoot . $path;
        if (is_file($disk)) {
            return $path;
        }
        $diskPublic = __DIR__ . $path;
        if (is_file($diskPublic)) {
            return '/public' . $path;
        }
        return '';
    }

    $rel = ltrim($path, '/');
    $disk = __DIR__ . '/' . $rel;
    if (is_file($disk)) {
        return '/public/' . $rel;
    }

    return '';
}

// ============ HANDLER AJAX PARA AGREGAR SUSCRIPCIÓN ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $masterPdo = Database::getMasterConnection();
        $id_empresa = resolveEmpresaActiva($masterPdo, $id_login);
        if ($id_empresa <= 0) {
            echo json_encode(['success' => false, 'error' => 'No se pudo resolver la empresa activa']);
            exit;
        }
        
        if ($_POST['action'] === 'agregar_app') {
            $id_app = intval($_POST['id_app'] ?? 0);
            
            if ($id_app <= 0) {
                echo json_encode(['success' => false, 'error' => 'App no válida']);
                exit;
            }
            
            // Verificar que la app existe, está activa y habilitada para contratar.
            $filtroDisponible = saasCatalogDisponibleFilter($masterPdo, 'a');
            $stmtApp = $masterPdo->prepare("SELECT a.* FROM saas_apps_catalogo a WHERE a.id_app = ? AND a.activo = 1{$filtroDisponible}");
            $stmtApp->execute([$id_app]);
            $app = $stmtApp->fetch(PDO::FETCH_ASSOC);
            
            if (!$app) {
                echo json_encode(['success' => false, 'error' => 'La aplicación no está disponible']);
                exit;
            }
            
            // Deprecado el bloqueo por "suscripción activa":
            // si no hay una vigente, se crea automáticamente para el período actual.
            $id_suscripcion = getOrCreateSuscripcionOperable($masterPdo, (int)$id_empresa, $usr_name ?: null);
            
            // Verificar que no tenga ya esta app suscrita
            $stmtCheck = $masterPdo->prepare("SELECT id FROM saas_suscripcion_apps WHERE id_suscripcion = ? AND id_app = ? AND activo = 1");
            $stmtCheck->execute([$id_suscripcion, $id_app]);
            if ($stmtCheck->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Ya tienes esta aplicación suscrita']);
                exit;
            }
            
            // Insertar nueva app en la suscripción
            $stmtInsert = $masterPdo->prepare("
                INSERT INTO saas_suscripcion_apps (id_suscripcion, id_app, codigo_app, nombre_app, precio_unitario, subtotal, activo, fecha_activacion)
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmtInsert->execute([
                $id_suscripcion,
                $id_app,
                $app['codigo'],
                $app['nombre'],
                $app['precio_mensual'],
                $app['precio_mensual']
            ]);
            $idSuscripcionApp = (int)$masterPdo->lastInsertId();
            recalculateSuscripcionTotal($masterPdo, (int)$id_suscripcion);
            logSuscripcionAudit($masterPdo, [
                'id_empresa' => (int)$id_empresa,
                'id_suscripcion' => (int)$id_suscripcion,
                'id_suscripcion_app' => $idSuscripcionApp,
                'id_app' => (int)$id_app,
                'accion' => 'agregar_app',
                'detalle' => json_encode([
                    'app' => (string)($app['nombre'] ?? ''),
                    'codigo' => (string)($app['codigo'] ?? ''),
                    'precio_mensual' => (float)($app['precio_mensual'] ?? 0),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id_login' => (int)$id_login,
                'usuario' => (string)$usr_name,
                'id_sucursal' => (int)$id_sucursal,
            ]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'App agregada exitosamente',
                'app_nombre' => $app['nombre']
            ]);
            exit;
        }

        if ($_POST['action'] === 'agregar_grupo') {
            $tipo = trim((string)($_POST['tipo'] ?? ''));
            $valor = trim((string)($_POST['valor'] ?? ''));
            if (!in_array($tipo, ['negocio', 'modulo'], true) || $valor === '') {
                echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
                exit;
            }

            $campo = ($tipo === 'negocio') ? 'negocio' : 'modulo';
            $idSuscripcion = getOrCreateSuscripcionOperable($masterPdo, (int)$id_empresa, $usr_name ?: null);

            $apps = [];
            if ($tipo === 'negocio') {
                // Priorizar el modelo/plantilla aplicada por tipo de negocio.
                $filtroDisponible = saasCatalogDisponibleFilter($masterPdo, 'a');
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
                      AND LOWER(TRIM(tn.nombre)) = LOWER(TRIM(:valor))
                      AND a.id_app NOT IN (
                        SELECT sa.id_app
                        FROM saas_suscripcion_apps sa
                        WHERE sa.id_suscripcion = :id_suscripcion
                          AND COALESCE(sa.activo, 1) = 1
                      )
                    ORDER BY COALESCE(tna.orden, a.orden, 9999), a.nombre
                ";
                $stmtModelo = $masterPdo->prepare($sqlModelo);
                $stmtModelo->execute([
                    ':valor' => $valor,
                    ':id_suscripcion' => $idSuscripcion,
                ]);
                $apps = $stmtModelo->fetchAll(PDO::FETCH_ASSOC);
            }

            // Fallback: si no hay plantilla para ese grupo, usar el campo legacy del catálogo.
            if (empty($apps)) {
                $filtroDisponible = saasCatalogDisponibleFilter($masterPdo, 'a');
                $sql = "
                    SELECT a.*
                    FROM saas_apps_catalogo a
                    WHERE a.activo = 1
                          {$filtroDisponible}
                      AND COALESCE(a.obligatoria, 0) = 0
                      AND COALESCE(a.{$campo}, 'General') = :valor
                      AND a.id_app NOT IN (
                        SELECT sa.id_app
                        FROM saas_suscripcion_apps sa
                        WHERE sa.id_suscripcion = :id_suscripcion
                          AND COALESCE(sa.activo, 1) = 1
                      )
                    ORDER BY a.orden ASC
                ";
                $stmtApps = $masterPdo->prepare($sql);
                $stmtApps->execute([
                    ':valor' => $valor,
                    ':id_suscripcion' => $idSuscripcion,
                ]);
                $apps = $stmtApps->fetchAll(PDO::FETCH_ASSOC);
            }

            $agregadas = 0;
            $appsAgregadas = [];
            foreach ($apps as $app) {
                $stmtInsert = $masterPdo->prepare("
                    INSERT INTO saas_suscripcion_apps
                        (id_suscripcion, id_app, codigo_app, nombre_app, precio_unitario, subtotal, activo, fecha_activacion)
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                $precio = (float)($app['precio_mensual'] ?? 0);
                $stmtInsert->execute([
                    $idSuscripcion,
                    (int)$app['id_app'],
                    (string)$app['codigo'],
                    (string)$app['nombre'],
                    $precio,
                    $precio,
                ]);
                $agregadas++;
                $appsAgregadas[] = [
                    'id_suscripcion_app' => (int)$masterPdo->lastInsertId(),
                    'id_app' => (int)$app['id_app'],
                    'codigo' => (string)$app['codigo'],
                    'nombre' => (string)$app['nombre'],
                ];
            }

            recalculateSuscripcionTotal($masterPdo, (int)$idSuscripcion);
            logSuscripcionAudit($masterPdo, [
                'id_empresa' => (int)$id_empresa,
                'id_suscripcion' => (int)$idSuscripcion,
                'accion' => 'agregar_grupo',
                'detalle' => json_encode([
                    'tipo' => (string)$tipo,
                    'valor' => (string)$valor,
                    'agregadas' => (int)$agregadas,
                    'apps' => $appsAgregadas,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id_login' => (int)$id_login,
                'usuario' => (string)$usr_name,
                'id_sucursal' => (int)$id_sucursal,
            ]);
            echo json_encode([
                'success' => true,
                'agregadas' => $agregadas,
                'message' => $agregadas > 0
                    ? "Se agregaron {$agregadas} apps por {$tipo}: {$valor}"
                    : "No hay apps nuevas para agregar en {$tipo}: {$valor}",
            ]);
            exit;
        }
        
        // Marcar/desmarcar app para cancelar al cierre de factura
        if ($_POST['action'] === 'toggle_cancelar') {
            $id_item = intval($_POST['id_suscripcion'] ?? 0); // id de saas_suscripcion_apps
            
            if ($id_item <= 0) {
                echo json_encode(['success' => false, 'error' => 'Item no válido']);
                exit;
            }
            
            // Verificar que el item pertenece a una suscripción de esta empresa
            $stmtCheck = $masterPdo->prepare("
                SELECT sa.id, sa.id_app, sa.id_suscripcion, IFNULL(sa.cancelar_al_cierre, 0) as cancelar_al_cierre
                FROM saas_suscripcion_apps sa
                JOIN saas_suscripcion s ON s.id_suscripcion = sa.id_suscripcion
                WHERE sa.id = ? AND s.id_empresa = ? AND sa.activo = 1
            ");
            $stmtCheck->execute([$id_item, $id_empresa]);
            $sub = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$sub) {
                echo json_encode(['success' => false, 'error' => 'Item no encontrado']);
                exit;
            }
            
            // Verificar que no sea una app obligatoria
            $stmtApp = $masterPdo->prepare("SELECT obligatoria, nombre FROM saas_apps_catalogo WHERE id_app = ?");
            $stmtApp->execute([$sub['id_app']]);
            $app = $stmtApp->fetch(PDO::FETCH_ASSOC);
            
            if ($app && $app['obligatoria']) {
                echo json_encode(['success' => false, 'error' => 'No puedes cancelar una aplicación obligatoria']);
                exit;
            }
            
            // Toggle el estado de cancelar_al_cierre
            $nuevoEstado = $sub['cancelar_al_cierre'] ? 0 : 1;
            $stmtUpdate = $masterPdo->prepare("UPDATE saas_suscripcion_apps SET cancelar_al_cierre = ?, fecha_solicitud_cancelacion = IF(? = 1, NOW(), NULL) WHERE id = ?");
            $stmtUpdate->execute([$nuevoEstado, $nuevoEstado, $id_item]);
            logSuscripcionAudit($masterPdo, [
                'id_empresa' => (int)$id_empresa,
                'id_suscripcion' => (int)($sub['id_suscripcion'] ?? 0),
                'id_suscripcion_app' => (int)$id_item,
                'id_app' => (int)($sub['id_app'] ?? 0),
                'accion' => $nuevoEstado ? 'marcar_cancelar_al_cierre' : 'revertir_cancelar_al_cierre',
                'detalle' => json_encode([
                    'app' => (string)($app['nombre'] ?? ''),
                    'nuevo_estado' => (int)$nuevoEstado,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id_login' => (int)$id_login,
                'usuario' => (string)$usr_name,
                'id_sucursal' => (int)$id_sucursal,
            ]);
            
            $mensaje = $nuevoEstado 
                ? 'App marcada para cancelar al cierre de factura' 
                : 'Cancelación de app revertida';
            
            echo json_encode([
                'success' => true, 
                'message' => $mensaje,
                'cancelar_al_cierre' => $nuevoEstado,
                'app_nombre' => $app['nombre'] ?? ''
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}
// ============ FIN HANDLER AJAX ============

// Obtener conexión master
try {
    $masterPdo = Database::getMasterConnection();
    $id_empresa = resolveEmpresaActiva($masterPdo, $id_login);
    if ($id_empresa <= 0) {
        throw new RuntimeException('No se pudo resolver la empresa activa en sesión');
    }
} catch (Exception $e) {
    die('Error de conexión: ' . $e->getMessage());
}

// Obtener datos de la empresa
$stmtEmpresa = $masterPdo->prepare("SELECT * FROM empresa WHERE id_empresa = ?");
$stmtEmpresa->execute([$id_empresa]);
$empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

if (!$empresa) {
    die('Empresa no encontrada');
}

// Suscripción de referencia para "Mi Suscripción":
// prioriza activa/gracia, y si no existe toma la más reciente.
$stmtSuscripcionActual = $masterPdo->prepare("
    SELECT id_suscripcion, estado, estado_pago, periodo_inicio, periodo_fin, nro_factura, total
    FROM saas_suscripcion
    WHERE id_empresa = ?
    ORDER BY 
        CASE estado
            WHEN 'activa' THEN 1
            WHEN 'gracia' THEN 2
            WHEN 'vencida' THEN 3
            ELSE 9
        END,
        periodo_inicio DESC,
        id_suscripcion DESC
    LIMIT 1
");
$stmtSuscripcionActual->execute([$id_empresa]);
$suscripcionActual = $stmtSuscripcionActual->fetch(PDO::FETCH_ASSOC) ?: null;
$idSuscripcionActual = (int)($suscripcionActual['id_suscripcion'] ?? 0);

// Obtener apps contratadas de la suscripción de referencia.
// LEFT JOIN para no perder apps si una app fue desactivada/eliminada del catálogo.
$suscripciones = [];
if ($idSuscripcionActual > 0) {
    $stmtSuscripciones = $masterPdo->prepare("
        SELECT sa.*,
               COALESCE(a.nombre, sa.nombre_app) AS app_nombre,
               COALESCE(a.descripcion, sa.nombre_app) AS app_descripcion, 
               a.icono_svg,
               COALESCE(a.color, 'blue') AS color,
               COALESCE(sa.precio_unitario, a.precio_mensual, 0) AS app_precio,
               COALESCE(a.obligatoria, 0) AS obligatoria,
               IFNULL(sa.cancelar_al_cierre, 0) AS cancelar_al_cierre
        FROM saas_suscripcion_apps sa
        LEFT JOIN saas_apps_catalogo a ON sa.id_app = a.id_app
        WHERE sa.id_suscripcion = :id_suscripcion
          AND sa.activo = 1
        ORDER BY COALESCE(a.orden, 9999), COALESCE(a.nombre, sa.nombre_app)
    ");
    $stmtSuscripciones->execute([
        ':id_suscripcion' => $idSuscripcionActual,
    ]);
    $suscripciones = $stmtSuscripciones->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suscripciones as &$sub) {
        $sub['icono_svg_resuelto'] = resolveCatalogIconSvg((string)($sub['icono_svg'] ?? ''));
    }
    unset($sub);
}

// Calcular total mensual
$totalMensual = 0;
foreach ($suscripciones as $sub) {
    $totalMensual += floatval($sub['precio_unitario'] ?? $sub['app_precio'] ?? 0);
}

// Obtener historial de facturas (suscripciones)
$stmtFacturas = $masterPdo->prepare("
    SELECT id_suscripcion, nro_factura, periodo_inicio, periodo_fin, total as monto_total,
           estado, estado_pago, fecha_pago,
           CASE WHEN estado_pago = 'pagado' THEN 1 ELSE 0 END as pagado,
           DATE_FORMAT(periodo_inicio, '%Y-%m') as periodo
    FROM saas_suscripcion 
    WHERE id_empresa = ? 
    ORDER BY periodo_inicio DESC 
    LIMIT 12
");
$stmtFacturas->execute([$id_empresa]);
$facturas = $stmtFacturas->fetchAll(PDO::FETCH_ASSOC);

// Obtener apps disponibles (catálogo activo menos las ya contratadas en la suscripción de referencia)
$filtroDisponible = saasCatalogDisponibleFilter($masterPdo, 'a');
if ($idSuscripcionActual > 0) {
    $stmtDisponibles = $masterPdo->prepare("
        SELECT a.* FROM saas_apps_catalogo a
        WHERE a.activo = 1 
          {$filtroDisponible}
          AND a.id_app NOT IN (
              SELECT sa.id_app 
              FROM saas_suscripcion_apps sa
              WHERE sa.id_suscripcion = ?
                AND sa.activo = 1
          )
        ORDER BY a.orden ASC, a.nombre ASC
    ");
    $stmtDisponibles->execute([$idSuscripcionActual]);
} else {
    $stmtDisponibles = $masterPdo->query("
        SELECT a.* FROM saas_apps_catalogo a
        WHERE a.activo = 1
          {$filtroDisponible}
        ORDER BY a.orden ASC, a.nombre ASC
    ");
}
$appsDisponibles = $stmtDisponibles->fetchAll(PDO::FETCH_ASSOC);
foreach ($appsDisponibles as &$app) {
    $app['icono_svg_resuelto'] = resolveCatalogIconSvg((string)($app['icono_svg'] ?? ''));
}
unset($app);
$appsDisponiblesJson = json_encode($appsDisponibles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$planesComerciales = [
    [
        'codigo' => 'gratis_fe',
        'nombre' => 'Gratis FE',
        'precio' => 'Gs. 0',
        'tag' => 'Factura electrónica gratis',
        'tag_class' => 'bg-emerald-500/18 text-emerald-300 border-emerald-400/25',
        'accent' => 'from-emerald-500 to-teal-500',
        'icon' => 'fa-bolt',
        'descripcion' => 'Para empezar sin costo mensual y emitir tus primeros comprobantes electrónicos.',
        'features' => [
            'Factura electrónica habilitada',
            '1 usuario · 1 sucursal · 1 caja',
            'Hasta 30 comprobantes por mes',
            'Hasta 100 productos y 100 clientes',
            'Stock básico y marca Powered by SistemaX',
        ],
        'cta' => 'Empezar Gratis',
    ],
    [
        'codigo' => 'emprendedor_fe',
        'nombre' => 'Emprendedor FE',
        'precio' => 'Gs. 99.000',
        'tag' => 'Plan de arranque',
        'tag_class' => 'bg-cyan-500/18 text-cyan-300 border-cyan-400/25',
        'accent' => 'from-cyan-500 to-blue-500',
        'icon' => 'fa-rocket',
        'descripcion' => 'Pensado para negocios que ya venden todos los días y necesitan más capacidad.',
        'features' => [
            'Factura electrónica incluida',
            '2 usuarios · 2 cajas',
            'Hasta 300 comprobantes por mes',
            'Hasta 2.000 productos',
            'Presupuestos, pedidos, email, WhatsApp e impresión directa',
        ],
        'cta' => 'Pasar a Emprendedor',
    ],
    [
        'codigo' => 'pro_fe',
        'nombre' => 'Pro FE',
        'precio' => 'Gs. 199.000',
        'tag' => 'Más recomendado',
        'tag_class' => 'bg-violet-500/18 text-violet-300 border-violet-400/25',
        'accent' => 'from-violet-500 to-fuchsia-500',
        'icon' => 'fa-layer-group',
        'descripcion' => 'Para equipos que necesitan control real, más usuarios y gestión completa.',
        'features' => [
            'Factura electrónica incluida',
            '5 usuarios · hasta 3 sucursales',
            'Hasta 3.000 comprobantes por mes',
            'Productos ilimitados',
            'Cuentas corrientes, reportes avanzados y permisos por usuario',
        ],
        'cta' => 'Quiero el plan Pro',
    ],
    [
        'codigo' => 'empresa_fe',
        'nombre' => 'Empresa FE',
        'precio' => 'A medida',
        'tag' => 'Modelo actual completo',
        'tag_class' => 'bg-amber-500/18 text-amber-300 border-amber-400/25',
        'accent' => 'from-amber-500 to-orange-500',
        'icon' => 'fa-building',
        'descripcion' => 'Es el modelo actual completo de SistemaX, pensado para operación intensiva y máxima cobertura funcional.',
        'features' => [
            'Factura electrónica incluida',
            'Usuarios y sucursales según necesidad',
            'Modelo actual completo de SistemaX',
            'Alto volumen o sin límite práctico de comprobantes',
            'Integraciones, automatizaciones y personalización',
            'Soporte prioritario y acompañamiento',
        ],
        'cta' => 'Hablar con ventas',
    ],
];

$estadoActual = strtolower((string)($suscripcionActual['estado'] ?? ''));
$estadoLabel = 'Sin suscripción';
$estadoClass = 'text-slate-300';
if ($estadoActual === 'activa') {
    $estadoLabel = 'Activa';
    $estadoClass = 'text-emerald-400';
} elseif ($estadoActual === 'gracia') {
    $estadoLabel = 'En gracia';
    $estadoClass = 'text-amber-400';
} elseif ($estadoActual === 'vencida') {
    $estadoLabel = 'Vencida';
    $estadoClass = 'text-red-400';
}
?>
<!DOCTYPE html>
<html lang="es">
<script>
    // Forzar tema oscuro ANTES del render
    (function() {
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    })();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>Mi Suscripción - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { 
            darkMode: 'class',
            theme: {
                extend: {}
            }
        }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        [x-cloak] { display: none !important; }
        html, body {
            height: 100%;
            overflow: hidden;
            background: #020617 !important;
            color: #e2e8f0;
        }
        .app-container {
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background:
                radial-gradient(1200px 700px at 10% -10%, rgba(30, 64, 175, 0.22), transparent 60%),
                radial-gradient(900px 600px at 100% 0%, rgba(15, 23, 42, 0.35), transparent 65%),
                linear-gradient(180deg, #0b1220 0%, #020617 100%) !important;
        }
        .app-content { flex: 1; overflow-y: auto; }
        .glass-card {
            background: rgba(30, 41, 59, 0.9) !important;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: white !important;
        }
        .app-icon-small {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .app-icon-small svg, .app-icon-small img { width: 20px; height: 20px; }
        .plan-card {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at top right, rgba(255,255,255,0.08), transparent 30%),
                linear-gradient(180deg, rgba(15, 23, 42, 0.94), rgba(2, 6, 23, 0.96));
            border: 1px solid rgba(148, 163, 184, 0.18);
        }
        .plan-card::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, rgba(255,255,255,0), rgba(255,255,255,0.85), rgba(255,255,255,0));
            opacity: 0.35;
        }
        .plan-feature {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            color: rgba(226, 232, 240, 0.92);
            font-size: 0.9rem;
            line-height: 1.35rem;
        }
    </style>
</head>
<body class="text-white">
    <div x-data="miSuscripcionApp()" x-cloak class="app-container">
        <!-- Loading Overlay -->
        <div x-show="loading" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center">
            <div class="bg-slate-800 rounded-2xl p-8 shadow-2xl flex flex-col items-center gap-4">
                <i class="fas fa-spinner fa-spin text-4xl text-blue-500"></i>
                <p class="text-white/80 font-medium">Procesando...</p>
            </div>
        </div>
        
        <!-- Header -->
        <header class="bg-slate-900/80 backdrop-blur-xl border-b border-white/10 flex-shrink-0 sticky top-0 z-40">
            <div class="w-full px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}if(typeof parent.cerrarAppMobile==='function'){parent.cerrarAppMobile();return;}}catch(e){} window.location.href='/public/menu/menu.php';" class="text-white/60 hover:text-slate-800 hover:text-white transition-colors">
                        <i class="fas fa-arrow-left text-xl"></i>
                    </button>
                    <div>
                        <h1 class="text-xl font-bold text-white">Mi Suscripción</h1>
                        <p class="text-white/60 text-sm"><?= htmlspecialchars($empresa['nombre_empresa'] ?? $empresa['razon_social'] ?? 'Mi Empresa') ?></p>
                        <p class="text-white/40 text-xs">Empresa #<?= (int)$id_empresa ?><?= $id_sucursal > 0 ? (' · Sucursal #' . (int)$id_sucursal) : '' ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-white/80 text-sm font-medium"><?= htmlspecialchars($usr_name) ?></span>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="app-content p-6">
            <div class="max-w-6xl mx-auto space-y-6">
                
                <!-- Resumen -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Total Mensual -->
                    <div class="glass-card rounded-2xl p-6 shadow-lg">
                        <div class="flex items-center gap-4">
                            <div class="w-14 h-14 bg-gradient-to-br from-emerald-500 to-green-600 rounded-xl flex items-center justify-center">
                                <i class="fas fa-credit-card text-white text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-white/60">Total Mensual</p>
                                <p class="text-2xl font-bold text-white">
                                    <?= number_format($totalMensual, 0, ',', '.') ?> <span class="text-sm font-normal">Gs</span>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Apps Activas -->
                    <div class="glass-card rounded-2xl p-6 shadow-lg">
                        <div class="flex items-center gap-4">
                            <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center">
                                <i class="fas fa-cubes text-white text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-white/60">Apps Activas</p>
                                <p class="text-2xl font-bold text-white"><?= count($suscripciones) ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Estado -->
                    <div class="glass-card rounded-2xl p-6 shadow-lg">
                        <div class="flex items-center gap-4">
                            <div class="w-14 h-14 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center">
                                <i class="fas fa-check-circle text-white text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-white/60">Estado</p>
                                <p class="text-lg font-bold <?= htmlspecialchars($estadoClass) ?>"><?= htmlspecialchars($estadoLabel) ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Layout dos columnas: Ofertadas | Contratadas -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- IZQUIERDA: Suscripciones Ofertadas -->
                    <div class="glass-card rounded-2xl p-6 shadow-lg space-y-6">
                        <div class="flex items-center justify-between mb-5">
                            <h2 class="text-lg font-bold text-white">
                                <i class="fas fa-tags text-blue-400 mr-2"></i>
                                Planes SistemaX
                            </h2>
                            <span class="text-xs text-white/40">Factura Electrónica incluida desde Gratis</span>
                        </div>
                        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                            <?php foreach ($planesComerciales as $plan): ?>
                                <div class="plan-card rounded-3xl p-5 shadow-lg">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex items-start gap-4">
                                            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br <?= htmlspecialchars($plan['accent']) ?> flex items-center justify-center text-white shadow-lg">
                                                <i class="fas <?= htmlspecialchars($plan['icon']) ?> text-xl"></i>
                                            </div>
                                            <div>
                                                <div class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] <?= htmlspecialchars($plan['tag_class']) ?>">
                                                    <?= htmlspecialchars($plan['tag']) ?>
                                                </div>
                                                <h3 class="mt-3 text-xl font-black text-white"><?= htmlspecialchars($plan['nombre']) ?></h3>
                                                <p class="mt-2 text-sm text-white/65"><?= htmlspecialchars($plan['descripcion']) ?></p>
                                            </div>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <div class="text-2xl font-black text-white"><?= htmlspecialchars($plan['precio']) ?></div>
                                            <div class="text-xs uppercase tracking-[0.18em] text-white/40"><?= $plan['precio'] === 'A medida' ? 'cotización' : 'por mes' ?></div>
                                        </div>
                                    </div>
                                    <div class="mt-5 space-y-3">
                                        <?php foreach ($plan['features'] as $feature): ?>
                                            <div class="plan-feature">
                                                <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-white/8 text-emerald-300">
                                                    <i class="fas fa-check text-[10px]"></i>
                                                </span>
                                                <span><?= htmlspecialchars($feature) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="mt-6 flex flex-wrap gap-3">
                                        <a href="https://wa.me/595984000000?text=Hola,%20quiero%20informacion%20del%20plan%20<?= rawurlencode($plan['nombre']) ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-xl bg-white text-slate-900 px-4 py-2 text-sm font-bold transition hover:bg-cyan-300">
                                            <i class="fab fa-whatsapp mr-2"></i><?= htmlspecialchars($plan['cta']) ?>
                                        </a>
                                        <span class="inline-flex items-center rounded-xl border border-white/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-white/45">
                                            SistemaX FE
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="rounded-2xl border border-cyan-400/15 bg-cyan-400/8 p-4">
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 text-cyan-300"><i class="fas fa-circle-info"></i></div>
                                <div>
                                    <p class="text-sm font-semibold text-cyan-200">Gancho comercial recomendado</p>
                                    <p class="mt-1 text-sm text-white/70">
                                        Promocioná fuerte el plan <strong class="text-white">Gratis FE</strong>: factura electrónica sin costo mensual, con límite de uso.
                                        El upgrade se empuja por más comprobantes, más usuarios, más sucursales y más control.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-white/10 pt-5">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-bold uppercase tracking-[0.18em] text-white/80">Apps adicionales disponibles</h3>
                                <span class="text-xs text-white/40"><?= count($appsDisponibles) ?> disponibles</span>
                            </div>

                            <?php if (empty($appsDisponibles)): ?>
                                <div class="text-center py-8 text-white/60">
                                    <i class="fas fa-check-double text-4xl mb-3 opacity-50"></i>
                                    <p>Ya tienes todas las apps disponibles</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3 max-h-[34vh] overflow-y-auto pr-1">
                                    <?php foreach ($appsDisponibles as $app): ?>
                                        <?php 
                                            $color = $app['color'] ?? 'gray';
                                            $gradientClass = $colorClasses[$color] ?? 'from-gray-400 to-gray-500';
                                            $precio = floatval($app['precio_mensual'] ?? 0);
                                        ?>
                                        <div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700 hover:border-blue-400 hover:bg-slate-700 cursor-pointer transition-all group"
                                             @click="agregarApp(<?= (int)$app['id_app'] ?>, '<?= htmlspecialchars(addslashes($app['nombre'])) ?>', <?= $precio ?>)">
                                            <div class="flex items-center gap-3">
                                                <div class="app-icon-small bg-gradient-to-br <?= $gradientClass ?> text-white group-hover:opacity-100 opacity-60 transition-opacity">
                                                    <?php if (!empty($app['icono_svg_resuelto'])): ?>
                                                        <img src="<?= htmlspecialchars($app['icono_svg_resuelto']) ?>" alt="" class="w-6 h-6 object-contain">
                                                    <?php else: ?>
                                                        <i class="fas fa-cube"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <h3 class="font-semibold text-white/70 group-hover:text-white truncate transition-colors">
                                                        <?= htmlspecialchars($app['nombre']) ?>
                                                    </h3>
                                                    <p class="text-xs text-white/40 truncate">
                                                        <?= htmlspecialchars($app['descripcion'] ?? '') ?>
                                                    </p>
                                                </div>
                                                <div class="text-right shrink-0">
                                                    <p class="font-bold text-white/70">
                                                        <?= $precio > 0 ? number_format($precio, 0, ',', '.') : 'Gratis' ?>
                                                    </p>
                                                    <?php if ($precio > 0): ?>
                                                        <p class="text-xs text-white/40">Gs/mes</p>
                                                    <?php endif; ?>
                                                    <button class="mt-1 text-xs bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity">
                                                        <i class="fas fa-plus mr-1"></i>Agregar
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- DERECHA: Suscripciones Contratadas -->
                    <div class="glass-card rounded-2xl p-6 shadow-lg">
                        <div class="flex items-center justify-between mb-5">
                            <h2 class="text-lg font-bold text-white">
                                <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                Suscripciones Contratadas
                            </h2>
                            <span class="text-xs text-white/40"><?= count($suscripciones) ?> activas</span>
                        </div>

                        <?php if (empty($suscripciones)): ?>
                            <div class="text-center py-8 text-white/60">
                                <i class="fas fa-inbox text-4xl mb-3 opacity-50"></i>
                                <p>No tienes apps suscritas</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3 max-h-[60vh] overflow-y-auto pr-1">
                                <?php foreach ($suscripciones as $sub): ?>
                                    <?php 
                                        $color = $sub['color'] ?? 'blue';
                                        $colorClasses = [
                                            'blue' => 'from-blue-500 to-blue-600',
                                            'green' => 'from-green-500 to-green-600',
                                            'purple' => 'from-purple-500 to-purple-600',
                                            'orange' => 'from-orange-500 to-orange-600',
                                            'red' => 'from-red-500 to-red-600',
                                            'cyan' => 'from-cyan-500 to-cyan-600',
                                            'pink' => 'from-pink-500 to-pink-600',
                                            'emerald' => 'from-emerald-500 to-emerald-600',
                                        ];
                                        $gradientClass = $colorClasses[$color] ?? $colorClasses['blue'];
                                        $precio = floatval($sub['precio_personalizado'] ?? $sub['app_precio'] ?? 0);
                                        $cancelarAlCierre = !empty($sub['cancelar_al_cierre']);
                                        $esObligatoria = !empty($sub['obligatoria']);
                                    ?>
                                    <div class="bg-slate-800 rounded-xl p-4 border-2 transition-all hover:shadow-md
                                                <?= $cancelarAlCierre ? 'border-red-700 bg-red-900/20' : 'border-slate-700' ?>">
                                        <div class="flex items-center gap-3">
                                            <div class="app-icon-small bg-gradient-to-br <?= $gradientClass ?> text-white <?= $cancelarAlCierre ? 'opacity-50' : '' ?>">
                                                <?php if (!empty($sub['icono_svg_resuelto'])): ?>
                                                    <img src="<?= htmlspecialchars($sub['icono_svg_resuelto']) ?>" alt="" class="w-6 h-6 object-contain">
                                                <?php else: ?>
                                                    <i class="fas fa-cube"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <h3 class="font-semibold truncate <?= $cancelarAlCierre ? 'text-white/60 line-through' : 'text-white' ?>">
                                                    <?= htmlspecialchars($sub['app_nombre']) ?>
                                                </h3>
                                                <p class="text-xs text-white/60 truncate">
                                                    <?= htmlspecialchars($sub['app_descripcion'] ?? '') ?>
                                                </p>
                                                <?php if ($cancelarAlCierre): ?>
                                                    <p class="text-xs text-red-400 mt-1">
                                                        <i class="fas fa-exclamation-triangle mr-1"></i>Se cancelará al cierre
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-right flex flex-col items-end gap-2 shrink-0">
                                                <div>
                                                    <p class="font-bold <?= $cancelarAlCierre ? 'text-white/40' : 'text-white' ?>">
                                                        <?= number_format($precio, 0, ',', '.') ?>
                                                    </p>
                                                    <p class="text-xs text-white/60">Gs/mes</p>
                                                </div>
                                                <?php if (!$esObligatoria): ?>
                                                    <?php if ($cancelarAlCierre): ?>
                                                        <button @click="toggleCancelar(<?= (int)$sub['id'] ?>, '<?= htmlspecialchars(addslashes($sub['app_nombre'])) ?>', true)"
                                                                class="text-xs bg-green-500 hover:bg-green-600 text-white px-2 py-1 rounded-lg transition-colors">
                                                            <i class="fas fa-undo mr-1"></i>Revertir
                                                        </button>
                                                    <?php else: ?>
                                                        <button @click="toggleCancelar(<?= (int)$sub['id'] ?>, '<?= htmlspecialchars(addslashes($sub['app_nombre'])) ?>', false)"
                                                                class="text-xs bg-red-900/30 hover:bg-red-900/50 text-red-400 px-2 py-1 rounded-lg transition-colors">
                                                            <i class="fas fa-times mr-1"></i>Cancelar
                                                        </button>
                                                    <?php endif; ?>
                                                <?php elseif ($esObligatoria): ?>
                                                    <span class="text-xs text-white/40">
                                                        <i class="fas fa-lock mr-1"></i>Obligatoria
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Historial de Facturas -->
                <div class="glass-card rounded-2xl p-6 shadow-lg">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-lg font-bold text-white">
                            <i class="fas fa-file-invoice text-purple-500 mr-2"></i>
                            Historial de Facturas
                        </h2>
                    </div>
                    
                    <?php if (empty($facturas)): ?>
                        <div class="text-center py-8 text-white/60">
                            <i class="fas fa-file-invoice text-4xl mb-3 opacity-50"></i>
                            <p>No hay facturas generadas</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="text-left text-xs text-white/60 uppercase tracking-wider">
                                        <th class="pb-3 font-medium">Período</th>
                                        <th class="pb-3 font-medium">Monto</th>
                                        <th class="pb-3 font-medium">Estado</th>
                                        <th class="pb-3 font-medium">Fecha Pago</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-700">
                                    <?php foreach ($facturas as $factura): ?>
                                        <?php
                                            $periodo = $factura['periodo'] ?? '';
                                            $monto = floatval($factura['monto_total'] ?? 0);
                                            $pagado = $factura['pagado'] ?? 0;
                                            $fechaPago = $factura['fecha_pago'] ?? null;
                                        ?>
                                        <tr class="text-sm">
                                            <td class="py-3 font-medium text-white">
                                                <?= htmlspecialchars($periodo) ?>
                                            </td>
                                            <td class="py-3 text-white/80">
                                                <?= number_format($monto, 0, ',', '.') ?> Gs
                                            </td>
                                            <td class="py-3">
                                                <?php if ($pagado): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-green-900/30 text-green-400  text-green-400">
                                                        <i class="fas fa-check-circle"></i> Pagado
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-yellow-900/30 text-yellow-400 bg-yellow-900/30 text-yellow-400">
                                                        <i class="fas fa-clock"></i> Pendiente
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 text-white/60">
                                                <?= $fechaPago ? date('d/m/Y', strtotime($fechaPago)) : '-' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Soporte -->
                <div class="glass-card rounded-2xl p-6 shadow-lg">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center">
                            <i class="fab fa-whatsapp text-white text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-bold text-white">¿Necesitas agregar apps o soporte?</h3>
                            <p class="text-sm text-white/60">Contacta con nuestro equipo de soporte</p>
                        </div>
                        <a href="https://wa.me/595984000000?text=Hola,%20necesito%20ayuda%20con%20mi%20suscripción%20SistemaX" 
                           target="_blank"
                           class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-xl font-semibold transition-all shadow-lg">
                            <i class="fab fa-whatsapp mr-2"></i>
                            WhatsApp
                        </a>
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <script>
        function miSuscripcionApp() {
            return {
                loading: false,
                appsDisponibles: <?= $appsDisponiblesJson ?: '[]' ?>,
                
                init() {
                    console.log('Mi Suscripción cargado');
                },
                
                async agregarApp(idApp, nombreApp, precio) {
                    const precioFormateado = precio > 0 ? precio.toLocaleString('es-PY') + ' Gs/mes' : 'Gratis';
                    
                    if (!confirm(`¿Deseas agregar "${nombreApp}" a tu suscripción?\n\nPrecio: ${precioFormateado}`)) {
                        return;
                    }
                    
                    this.loading = true;
                    
                    try {
                        const formData = new FormData();
                        formData.append('action', 'agregar_app');
                        formData.append('id_app', idApp);
                        
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        });
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            alert('✅ ' + data.message);
                            // Recargar la página para mostrar la nueva app
                            window.location.reload();
                        } else {
                            alert('❌ ' + (data.error || 'Error al agregar la app'));
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        alert('❌ Error de conexión');
                    } finally {
                        this.loading = false;
                    }
                },

                async toggleCancelar(idSuscripcion, nombreApp, estaMaradaCancelar) {
                    const mensaje = estaMaradaCancelar 
                        ? `¿Deseas REVERTIR la cancelación de "${nombreApp}"?\n\nLa app seguirá activa en tu suscripción.`
                        : `¿Deseas CANCELAR "${nombreApp}" al cierre de factura?\n\n⚠️ La app permanecerá activa hasta el fin del período actual, luego se desactivará automáticamente.`;
                    
                    if (!confirm(mensaje)) {
                        return;
                    }
                    
                    this.loading = true;
                    
                    try {
                        const formData = new FormData();
                        formData.append('action', 'toggle_cancelar');
                        formData.append('id_suscripcion', idSuscripcion);
                        
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        });
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            alert('✅ ' + data.message);
                            window.location.reload();
                        } else {
                            alert('❌ ' + (data.error || 'Error al procesar la solicitud'));
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        alert('❌ Error de conexión');
                    } finally {
                        this.loading = false;
                    }
                }
            };
        }
    </script>
</body>
</html>
