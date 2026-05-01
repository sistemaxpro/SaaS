<?php

/**
 * API REST - Suscripciones SaaS
 * Endpoints para gestión de catálogo de apps, suscripciones y pagos
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../src/Modules/Empresas/SuscripcionController.php';

header('Content-Type: application/json');

// Obtener método y acción
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Verificar autenticación para la mayoría de acciones
$isLoggedIn = Session::isLoggedIn();
$idEmpresa = $isLoggedIn ? Session::getIdEmpresa() : 0;
$isAdmin = $isLoggedIn && (($_SESSION['usr_priv_admin'] ?? 'N') === 'Y');
// Super admin: cualquier admin puede editar apps del catálogo global (antes estaba limitado a empresa 169)
$isSuperAdmin = $isAdmin;

// Acciones públicas (no requieren login)
$accionesPublicas = ['verificar_acceso', 'generar_pago_ueno'];

if (!$isLoggedIn && !in_array($action, $accionesPublicas)) {
    Response::error('No autorizado', 401);
}

// Router
try {
    switch ($method) {
        case 'GET':
            handleGet($action);
            break;
        case 'POST':
            handlePost($action);
            break;
        case 'PUT':
            handlePut($action);
            break;
        case 'DELETE':
            handleDelete($action);
            break;
        default:
            Response::error('Método no permitido', 405);
    }
} catch (Exception $e) {
    error_log("[API Suscripciones] Error: " . $e->getMessage());
    Response::error('Error interno', 500);
}

// =========================================================================
// HANDLERS
// =========================================================================

function handleGet(string $action): void
{
    global $idEmpresa, $isAdmin, $isSuperAdmin;

    switch ($action) {
        // --- CATÁLOGO DE APPS ---
        case 'apps':
            // Sincronizar todas las apps del catálogo
            $syncResult = SuscripcionController::ensureAllCatalogApps();

            $resultado = SuscripcionController::listarApps([
                'solo_activas' => false
            ]);

            // Agregar información de sincronización a la respuesta
            if ($isSuperAdmin) {
                $resultado['_sync_info'] = [
                    'synced_total' => $syncResult['total'] ?? 0,
                    'synced_count' => count($syncResult['synced'] ?? []),
                    'errors_count' => count($syncResult['errors'] ?? [])
                ];
            }

            echo json_encode($resultado);
            break;

        case 'sync_catalog':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $syncResults = SuscripcionController::ensureAllCatalogApps();
            echo json_encode([
                'success' => true,
                'message' => 'Catálogo sincronizado',
                'sync_results' => $syncResults
            ]);
            break;

        case 'negocio_tipos':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $db = Database::getMasterConnection();
            ensureTipoNegocioSchema($db);
            $stmt = $db->query("
                SELECT id, nombre, orden, activo, COALESCE(disponible, 1) AS disponible
                FROM tipo_negocio
                WHERE activo = 1
                ORDER BY orden ASC, nombre ASC
            ");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'negocio_templates':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $db = Database::getMasterConnection();
            ensureTipoNegocioSchema($db);
            $stmt = $db->query("
                SELECT
                    tn.nombre AS negocio,
                    tna.id_app,
                    tna.orden
                FROM tipo_negocio_app tna
                INNER JOIN tipo_negocio tn ON tn.id = tna.tipo_negocio_id
                WHERE tna.activo = 1
                  AND tn.activo = 1
                ORDER BY tn.nombre ASC, tna.orden ASC, tna.id ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $r) {
                $neg = trim((string)($r['negocio'] ?? ''));
                $idApp = (int)($r['id_app'] ?? 0);
                if ($neg === '' || $idApp <= 0) continue;
                if (!isset($map[$neg])) $map[$neg] = [];
                $map[$neg][] = $idApp;
            }
            echo json_encode(['success' => true, 'data' => $rows, 'map' => $map]);
            break;

        case 'app':
            $idApp = (int)($_GET['id'] ?? 0);
            if ($idApp <= 0) {
                Response::error('ID de app requerido', 400);
            }
            $resultado = SuscripcionController::getApp($idApp);
            echo json_encode($resultado);
            break;

        // --- SUSCRIPCIONES ---
        case 'suscripcion_activa':
            $empresaId = (int)($_GET['id_empresa'] ?? $idEmpresa);
            // Solo super admin puede ver otras empresas
            if ($empresaId != $idEmpresa && !$isSuperAdmin) {
                Response::error('No tiene permiso', 403);
            }
            $usarFallbackGestion = (int)($_GET['fallback_latest'] ?? 0) === 1;
            $resultado = $usarFallbackGestion
                ? SuscripcionController::getSuscripcionGestionEmpresa($empresaId)
                : SuscripcionController::getSuscripcionActiva($empresaId);
            echo json_encode($resultado);
            break;

        case 'suscripciones':
            $empresaId = (int)($_GET['id_empresa'] ?? $idEmpresa);
            if ($empresaId != $idEmpresa && !$isSuperAdmin) {
                Response::error('No tiene permiso', 403);
            }
            $resultado = SuscripcionController::listarSuscripciones($empresaId, $_GET);
            echo json_encode($resultado);
            break;

        case 'suscripciones_todas':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin puede listar todas', 403);
            }
            $resultado = SuscripcionController::listarTodas($_GET);
            echo json_encode($resultado);
            break;

        case 'verificar_acceso':
            $empresaId = (int)($_GET['id_empresa'] ?? $idEmpresa);
            $resultado = SuscripcionController::verificarAcceso($empresaId);
            echo json_encode($resultado);
            break;

        case 'apps_empresa':
            $empresaId = (int)($_GET['id_empresa'] ?? $idEmpresa);
            $modulo = (int)($_GET['modulo'] ?? 0);
            if ($empresaId != $idEmpresa && !$isSuperAdmin) {
                Response::error('No tiene permiso', 403);
            }
            $resultado = SuscripcionController::getAppsEmpresa($empresaId, $modulo);
            echo json_encode($resultado);
            break;

        // --- EMPRESAS (para selector) ---
        case 'empresas':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $db = Database::getMasterConnection();
            $stmt = $db->query("SELECT id_empresa, empresa, ruc, activo, logos, email, direccion FROM empresa WHERE activo = 1 ORDER BY empresa");
            $empresas = $stmt->fetchAll();
            
            // Procesar URLs de logos
            $rutaImagenEmpresas = '/public/_lib/file/img/empresa/';
            $rutaImagenEmpresasDisk = dirname(__DIR__, 2) . '/_lib/file/img/empresa/';
            foreach ($empresas as &$emp) {
                $logo = trim((string)($emp['logos'] ?? ''));
                $emp['logo_url'] = null;

                if ($logo === '') {
                    continue;
                }

                // URLs absolutas externas: se respetan
                if (preg_match('#^https?://#i', $logo)) {
                    $emp['logo_url'] = $logo;
                    continue;
                }

                // Si viene ruta local absoluta o nombre de archivo, validar existencia física.
                $logoPath = parse_url($logo, PHP_URL_PATH) ?: $logo;
                $logoFile = basename($logoPath);
                if ($logoFile === '') {
                    continue;
                }

                $diskPath = $rutaImagenEmpresasDisk . $logoFile;
                if (is_file($diskPath)) {
                    $emp['logo_url'] = rtrim($rutaImagenEmpresas, '/') . '/' . rawurlencode($logoFile);
                }
            }
            unset($emp);
            
            echo json_encode(['success' => true, 'data' => $empresas]);
            break;

        case 'solicitudes':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $resultado = SuscripcionController::listarSolicitudes($_GET);
            echo json_encode($resultado);
            break;

        // --- DASHBOARD / ESTADÍSTICAS ---
        case 'dashboard_kpis':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $resultado = getDashboardKPIs();
            echo json_encode($resultado);
            break;

        case 'dashboard_ingresos_mensuales':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $meses = (int)($_GET['meses'] ?? 12);
            $resultado = getIngresosMensuales($meses);
            echo json_encode($resultado);
            break;

        case 'dashboard_estado_pagos':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $resultado = getEstadoPagos();
            echo json_encode($resultado);
            break;

        case 'dashboard_proximos_vencer':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $dias = (int)($_GET['dias'] ?? 7);
            $resultado = getProximosVencer($dias);
            echo json_encode($resultado);
            break;

        default:
            Response::error('Acción GET no reconocida: ' . $action, 400);
    }
}

function handlePost(string $action): void
{
    global $idEmpresa, $isAdmin, $isSuperAdmin;

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        // --- CATÁLOGO DE APPS ---
        case 'crear_app':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin puede crear apps', 403);
            }
            $resultado = SuscripcionController::crearApp($input);
            echo json_encode($resultado);
            break;

        case 'actualizar_app':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin puede modificar', 403);
            }
            $idApp = (int)($_GET['id'] ?? $input['id_app'] ?? 0);
            if ($idApp <= 0) {
                Response::error('ID de app requerido', 400);
            }
            $resultado = SuscripcionController::actualizarApp($idApp, $input);
            echo json_encode($resultado);
            break;

        case 'ensure_balanza_app':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $resultado = SuscripcionController::ensureBalanzaApp();
            echo json_encode($resultado);
            break;

        case 'ensure_db_migrador_app':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $resultado = SuscripcionController::ensureDbMigradorApp();
            echo json_encode($resultado);
            break;

        // --- SUSCRIPCIONES ---
        case 'crear_suscripcion':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin puede crear suscripciones', 403);
            }
            $empresaId = (int)($input['id_empresa'] ?? 0);
            if ($empresaId <= 0) {
                Response::error('ID de empresa requerido', 400);
            }
            $input['created_by'] = Session::getIdLogin();
            $resultado = SuscripcionController::crearSuscripcion($empresaId, $input);
            echo json_encode($resultado);
            break;

        case 'renovar_suscripcion':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $empresaId = (int)($input['id_empresa'] ?? 0);
            if ($empresaId <= 0) {
                Response::error('ID de empresa requerido', 400);
            }
            $resultado = SuscripcionController::renovarMes($empresaId);
            echo json_encode($resultado);
            break;

        case 'toggle_anulacion_suscripcion':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $idSuscripcion = (int)($input['id_suscripcion'] ?? 0);
            $anular = (int)($input['anular'] ?? 1) === 1;
            if ($idSuscripcion <= 0) {
                Response::error('ID de suscripción requerido', 400);
            }
            $resultado = SuscripcionController::toggleAnulacionSuscripcion($idSuscripcion, $anular);
            echo json_encode($resultado);
            break;

        case 'agregar_app':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $idSuscripcion = (int)($input['id_suscripcion'] ?? 0);
            $idApp = (int)($input['id_app'] ?? 0);
            if ($idSuscripcion <= 0 || $idApp <= 0) {
                Response::error('ID de suscripción y app requeridos', 400);
            }
            $resultado = SuscripcionController::agregarApp($idSuscripcion, $idApp, $input);
            echo json_encode($resultado);
            break;

        case 'quitar_app':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $idSuscripcion = (int)($input['id_suscripcion'] ?? 0);
            $idApp = (int)($input['id_app'] ?? 0);
            if ($idSuscripcion <= 0 || $idApp <= 0) {
                Response::error('ID de suscripción y app requeridos', 400);
            }
            $resultado = SuscripcionController::quitarApp($idSuscripcion, $idApp);
            echo json_encode($resultado);
            break;

        case 'actualizar_periodo_suscripcion':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $idSuscripcion = (int)($input['id_suscripcion'] ?? 0);
            $periodoInicio = trim((string)($input['periodo_inicio'] ?? ''));
            $periodoFin = trim((string)($input['periodo_fin'] ?? ''));
            $fechaVencimientoManual = trim((string)($input['fecha_vencimiento'] ?? ''));
            if ($idSuscripcion <= 0) {
                Response::error('ID de suscripción requerido', 400);
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoFin)) {
                Response::error('Formato de fecha inválido (YYYY-MM-DD)', 400);
            }
            $tsIni = strtotime($periodoInicio);
            $tsFin = strtotime($periodoFin);
            if (!$tsIni || !$tsFin) {
                Response::error('Fechas inválidas', 400);
            }
            if ($tsIni > $tsFin) {
                Response::error('La fecha de inicio no puede ser mayor que la fecha de fin', 400);
            }
            if ($fechaVencimientoManual !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaVencimientoManual)) {
                Response::error('Formato de fecha_vencimiento inválido (YYYY-MM-DD)', 400);
            }
            if ($fechaVencimientoManual !== '') {
                $tsVenc = strtotime($fechaVencimientoManual);
                if (!$tsVenc) {
                    Response::error('Fecha de vencimiento inválida', 400);
                }
                if ($tsVenc < $tsFin) {
                    Response::error('La fecha de vencimiento no puede ser menor al fin del período', 400);
                }
            }

            $db = Database::getMasterConnection();
            $sql = "UPDATE saas_suscripcion SET periodo_inicio = ?, periodo_fin = ?";
            $params = [$periodoInicio, $periodoFin];

            if (columnExists($db, 'saas_suscripcion', 'fecha_vencimiento')) {
                if ($fechaVencimientoManual !== '') {
                    $sql .= ", fecha_vencimiento = ?";
                    $params[] = $fechaVencimientoManual;
                } else {
                    $sql .= ", fecha_vencimiento = DATE_ADD(?, INTERVAL COALESCE(dias_gracia, 0) DAY)";
                    $params[] = $periodoFin;
                }
            }
            $sql .= " WHERE id_suscripcion = ?";
            $params[] = $idSuscripcion;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $stmtData = $db->prepare("
                SELECT id_suscripcion, periodo_inicio, periodo_fin, fecha_vencimiento
                FROM saas_suscripcion
                WHERE id_suscripcion = ?
                LIMIT 1
            ");
            $stmtData->execute([$idSuscripcion]);
            $sus = $stmtData->fetch(PDO::FETCH_ASSOC);
            if (!$sus) {
                Response::error('Suscripción no encontrada', 404);
            }
            echo json_encode([
                'success' => true,
                'message' => 'Período de suscripción actualizado',
                'data' => $sus
            ]);
            break;

        case 'toggle_sponsor':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $idSuscripcion = (int)($input['id_suscripcion'] ?? 0);
            $sponsor = (int)($input['sponsor'] ?? 0) === 1 ? 1 : 0;
            if ($idSuscripcion <= 0) {
                Response::error('ID de suscripción requerido', 400);
            }

            $db = Database::getMasterConnection();
            if (!columnExists($db, 'saas_suscripcion', 'es_sponsor')) {
                try {
                    ensureSaasSuscripcionSponsorColumn($db);
                } catch (Throwable $e) {
                    error_log("[API Suscripciones] toggle_sponsor: no se pudo asegurar columna es_sponsor: " . $e->getMessage());
                    Response::error('No se pudo habilitar Sponsor en la base de datos. Intente nuevamente.', 500);
                }
            }

            if ($sponsor === 1) {
                $stmt = $db->prepare("
                    UPDATE saas_suscripcion
                    SET es_sponsor = 1,
                        estado = 'activa',
                        estado_pago = 'pagado',
                        subtotal = 0,
                        total = 0,
                        descuento = 0,
                        fecha_pago = CURDATE()
                    WHERE id_suscripcion = ?
                ");
                $stmt->execute([$idSuscripcion]);
            } else {
                $stmt = $db->prepare("
                    SELECT
                        COALESCE(SUM(CASE WHEN COALESCE(sa.activo,1)=1 THEN COALESCE(sa.subtotal,0) ELSE 0 END), 0) AS subtotal_calc,
                        COALESCE(s.descuento, 0) AS descuento_actual
                    FROM saas_suscripcion s
                    LEFT JOIN saas_suscripcion_apps sa ON sa.id_suscripcion = s.id_suscripcion
                    WHERE s.id_suscripcion = ?
                    GROUP BY s.id_suscripcion, s.descuento
                    LIMIT 1
                ");
                $stmt->execute([$idSuscripcion]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    Response::error('Suscripción no encontrada', 404);
                }
                $subtotalCalc = (float)($row['subtotal_calc'] ?? 0);
                $descuentoActual = (float)($row['descuento_actual'] ?? 0);
                $totalCalc = max(0, $subtotalCalc - $descuentoActual);
                $estadoPago = $totalCalc <= 0 ? 'pagado' : 'pendiente';

                $stmt = $db->prepare("
                    UPDATE saas_suscripcion
                    SET es_sponsor = 0,
                        subtotal = ?,
                        total = ?,
                        estado_pago = ?,
                        fecha_pago = CASE WHEN ? = 'pagado' THEN COALESCE(fecha_pago, CURDATE()) ELSE NULL END
                    WHERE id_suscripcion = ?
                ");
                $stmt->execute([$subtotalCalc, $totalCalc, $estadoPago, $estadoPago, $idSuscripcion]);
            }

            $stmtData = $db->prepare("
                SELECT id_suscripcion, id_empresa, periodo_inicio, periodo_fin, total, estado, estado_pago, COALESCE(es_sponsor,0) AS es_sponsor
                FROM saas_suscripcion
                WHERE id_suscripcion = ?
                LIMIT 1
            ");
            $stmtData->execute([$idSuscripcion]);
            $sus = $stmtData->fetch(PDO::FETCH_ASSOC);
            if (!$sus) {
                Response::error('Suscripción no encontrada', 404);
            }
            echo json_encode([
                'success' => true,
                'message' => $sponsor === 1
                    ? 'Empresa marcada como Sponsor (sin cobro)'
                    : 'Modo Sponsor desactivado',
                'data' => $sus
            ]);
            break;

        case 'guardar_negocio_template':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $negocio = trim((string)($input['negocio'] ?? ''));
            $ids = $input['app_ids'] ?? [];
            if ($negocio === '') {
                Response::error('Negocio requerido', 400);
            }
            if (!is_array($ids)) $ids = [];
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $ids = array_values(array_filter($ids, fn($v) => $v > 0));

            $db = Database::getMasterConnection();
            ensureTipoNegocioSchema($db);
            $stmtTipo = $db->prepare("SELECT id FROM tipo_negocio WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
            $stmtTipo->execute([$negocio]);
            $tipoId = (int)($stmtTipo->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
            if ($tipoId <= 0) {
                $ordStmt = $db->query("SELECT COALESCE(MAX(orden), 0) + 10 AS next_ord FROM tipo_negocio");
                $nextOrd = (int)($ordStmt->fetch(PDO::FETCH_ASSOC)['next_ord'] ?? 10);
                $insTipo = $db->prepare("INSERT INTO tipo_negocio (nombre, orden, activo) VALUES (?, ?, 1)");
                $insTipo->execute([$negocio, $nextOrd]);
                $tipoId = (int)$db->lastInsertId();
            }
            $db->beginTransaction();
            $del = $db->prepare("DELETE FROM tipo_negocio_app WHERE tipo_negocio_id = ?");
            $del->execute([$tipoId]);
            if (!empty($ids)) {
                $ins = $db->prepare("INSERT INTO tipo_negocio_app (tipo_negocio_id, id_app, orden, activo) VALUES (?, ?, ?, 1)");
                $ord = 1;
                foreach ($ids as $idApp) {
                    $ins->execute([$tipoId, $idApp, $ord++]);
                }
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Plantilla de negocio guardada']);
            break;

        case 'crear_negocio_tipo':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $nombre = trim((string)($input['nombre'] ?? ''));
            $disponible = (int)($input['disponible'] ?? 1) === 1 ? 1 : 0;
            if ($nombre === '') {
                Response::error('Nombre requerido', 400);
            }
            $db = Database::getMasterConnection();
            ensureTipoNegocioSchema($db);
            $stmt = $db->prepare("SELECT id FROM tipo_negocio WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
            $stmt->execute([$nombre]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                Response::error('Ese tipo de negocio ya existe', 400);
            }
            $ordStmt = $db->query("SELECT COALESCE(MAX(orden), 0) + 10 AS next_ord FROM tipo_negocio");
            $nextOrd = (int)($ordStmt->fetch(PDO::FETCH_ASSOC)['next_ord'] ?? 10);
            $ins = $db->prepare("INSERT INTO tipo_negocio (nombre, orden, activo, disponible) VALUES (?, ?, 1, ?)");
            $ins->execute([$nombre, $nextOrd, $disponible]);
            echo json_encode(['success' => true, 'message' => 'Tipo de negocio creado', 'id' => (int)$db->lastInsertId()]);
            break;

        case 'actualizar_negocio_tipo':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $id = (int)($input['id'] ?? 0);
            $nombreActualInput = trim((string)($input['nombre_actual'] ?? ''));
            $nombreNuevo = trim((string)($input['nombre'] ?? ''));
            $disponible = (int)($input['disponible'] ?? 1) === 1 ? 1 : 0;
            if ($nombreNuevo === '') {
                Response::error('Nombre requerido', 400);
            }
            $db = Database::getMasterConnection();
            ensureTipoNegocioSchema($db);

            if ($id <= 0 && $nombreActualInput !== '') {
                $findId = $db->prepare("SELECT id FROM tipo_negocio WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
                $findId->execute([$nombreActualInput]);
                $id = (int)($findId->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
            }

            $stmt = $id > 0
                ? $db->prepare("SELECT nombre FROM tipo_negocio WHERE id = ? LIMIT 1")
                : $db->prepare("SELECT nombre FROM tipo_negocio WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
            $stmt->execute($id > 0 ? [$id] : [$nombreActualInput]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $nombreAnterior = (string)($row['nombre'] ?? '');
            if ($nombreAnterior === '') {
                // Fallback: permitir renombrar negocios no formalizados en tipo_negocio.
                $nombreAnterior = $nombreActualInput;
            }
            if ($nombreAnterior === '') {
                Response::error('Tipo de negocio no encontrado', 404);
            }

            $dup = $db->prepare("SELECT id FROM tipo_negocio WHERE LOWER(nombre) = LOWER(?) AND id <> ? LIMIT 1");
            $dup->execute([$nombreNuevo, $id]);
            if ($dup->fetch(PDO::FETCH_ASSOC)) {
                Response::error('Ya existe otro tipo con ese nombre', 400);
            }

            $db->beginTransaction();
            if ($id > 0) {
                $db->prepare("UPDATE tipo_negocio SET nombre = ?, disponible = ? WHERE id = ?")->execute([$nombreNuevo, $disponible, $id]);
            } else {
                // Si el tipo no existia formalmente, asegurar alta del nuevo nombre.
                $insTipo = $db->prepare("INSERT IGNORE INTO tipo_negocio (nombre, orden, activo, disponible) VALUES (?, 100, 1, ?)");
                $insTipo->execute([$nombreNuevo, $disponible]);
                $db->prepare("UPDATE tipo_negocio SET disponible = ? WHERE LOWER(nombre) = LOWER(?)")->execute([$disponible, $nombreNuevo]);
            }
            if ($nombreAnterior !== '' && $nombreAnterior !== $nombreNuevo) {
                $db->prepare("UPDATE saas_apps_catalogo SET negocio = ? WHERE LOWER(TRIM(COALESCE(negocio, ''))) = LOWER(TRIM(?))")
                    ->execute([$nombreNuevo, $nombreAnterior]);
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Tipo de negocio actualizado']);
            break;

        case 'eliminar_negocio_tipo':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $id = (int)($input['id'] ?? 0);
            $nombreInput = trim((string)($input['nombre'] ?? ''));
            if ($id <= 0 && $nombreInput === '') {
                Response::error('ID o nombre requerido', 400);
            }
            $db = Database::getMasterConnection();
            ensureTipoNegocioSchema($db);

            if ($id <= 0 && $nombreInput !== '') {
                $findId = $db->prepare("SELECT id FROM tipo_negocio WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
                $findId->execute([$nombreInput]);
                $id = (int)($findId->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
            }

            $stmt = $id > 0
                ? $db->prepare("SELECT nombre FROM tipo_negocio WHERE id = ? LIMIT 1")
                : $db->prepare("SELECT nombre FROM tipo_negocio WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
            $stmt->execute($id > 0 ? [$id] : [$nombreInput]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                if ($nombreInput !== '') {
                    // Fallback: si no existe tipo formal, limpiar plantilla y normalizar apps.
                    $db->beginTransaction();
                    $db->prepare("UPDATE saas_apps_catalogo SET negocio = NULL WHERE LOWER(TRIM(COALESCE(negocio, ''))) = LOWER(TRIM(?))")
                        ->execute([$nombreInput]);
                    if ($db->query("SHOW TABLES LIKE 'saas_negocio_templates'")->fetch(PDO::FETCH_ASSOC)) {
                        $db->prepare("DELETE FROM saas_negocio_templates WHERE LOWER(TRIM(negocio)) = LOWER(TRIM(?))")
                            ->execute([$nombreInput]);
                    }
                    if ($db->query("SHOW TABLES LIKE 'saas_negocios_tipos'")->fetch(PDO::FETCH_ASSOC)) {
                        $db->prepare("DELETE FROM saas_negocios_tipos WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?))")
                            ->execute([$nombreInput]);
                    }
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Negocio suprimido']);
                    break;
                }
                Response::error('Tipo de negocio no encontrado', 404);
            }
            $nombre = (string)($row['nombre'] ?? '');

            $db->beginTransaction();
            $db->prepare("DELETE FROM tipo_negocio_app WHERE tipo_negocio_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM tipo_negocio WHERE id = ?")->execute([$id]);
            $db->prepare("UPDATE saas_apps_catalogo SET negocio = NULL WHERE LOWER(TRIM(COALESCE(negocio, ''))) = LOWER(TRIM(?))")
                ->execute([$nombre]);
            if ($db->query("SHOW TABLES LIKE 'saas_negocio_templates'")->fetch(PDO::FETCH_ASSOC)) {
                $db->prepare("DELETE FROM saas_negocio_templates WHERE LOWER(TRIM(negocio)) = LOWER(TRIM(?))")
                    ->execute([$nombre]);
            }
            if ($db->query("SHOW TABLES LIKE 'saas_negocios_tipos'")->fetch(PDO::FETCH_ASSOC)) {
                $db->prepare("DELETE FROM saas_negocios_tipos WHERE id = ? OR LOWER(TRIM(nombre)) = LOWER(TRIM(?))")
                    ->execute([$id, $nombre]);
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Tipo de negocio suprimido']);
            break;

        case 'reset_negocio_tipos':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $db = Database::getMasterConnection();
            ensureTipoNegocioSchema($db);
            $db->beginTransaction();
            $db->exec("DELETE FROM tipo_negocio_app");
            $db->exec("DELETE FROM tipo_negocio");
            $db->exec("UPDATE saas_apps_catalogo SET negocio = NULL");
            if ($db->query("SHOW TABLES LIKE 'saas_negocio_templates'")->fetch(PDO::FETCH_ASSOC)) {
                $db->exec("DELETE FROM saas_negocio_templates");
            }
            if ($db->query("SHOW TABLES LIKE 'saas_negocios_tipos'")->fetch(PDO::FETCH_ASSOC)) {
                $db->exec("DELETE FROM saas_negocios_tipos");
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Tipos de negocio reiniciados. Estado limpio (0).']);
            break;

        case 'hidratar_empresa_negocio':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $empresaId = (int)($input['id_empresa'] ?? 0);
            $negocio = trim((string)($input['negocio'] ?? ''));
            if ($empresaId <= 0 || $negocio === '') {
                Response::error('id_empresa y negocio son requeridos', 400);
            }

            $db = Database::getMasterConnection();
            ensureTipoNegocioSchema($db);

            $stmtTpl = $db->prepare("
                SELECT tna.id_app
                FROM tipo_negocio_app tna
                INNER JOIN tipo_negocio tn ON tn.id = tna.tipo_negocio_id
                WHERE LOWER(tn.nombre) = LOWER(?)
                  AND tn.activo = 1
                  AND tna.activo = 1
                ORDER BY tna.orden ASC, tna.id ASC
            ");
            $stmtTpl->execute([$negocio]);
            $appIds = array_values(array_unique(array_map('intval', $stmtTpl->fetchAll(PDO::FETCH_COLUMN))));

            if (empty($appIds)) {
                $stmtCat = $db->prepare("
                    SELECT id_app
                    FROM saas_apps_catalogo
                    WHERE activo = 1 AND COALESCE(negocio, 'Comercial') = ?
                    ORDER BY orden ASC
                ");
                $stmtCat->execute([$negocio]);
                $appIds = array_values(array_unique(array_map('intval', $stmtCat->fetchAll(PDO::FETCH_COLUMN))));
            }

            if (empty($appIds)) {
                echo json_encode([
                    'success' => true,
                    'message' => "Sin cambios: el negocio \"{$negocio}\" no tiene apps configuradas todavía.",
                    'data' => [
                        'id_suscripcion' => null,
                        'negocio' => $negocio,
                        'apps_consideradas' => 0,
                        'agregadas' => 0,
                        'existentes' => 0,
                        'errores' => [],
                        'warning' => 'Configura apps en la plantilla del negocio y vuelve a hidratar.'
                    ]
                ]);
                break;
            }

            $sus = SuscripcionController::getSuscripcionActiva($empresaId);
            if (!($sus['success'] ?? false) || empty($sus['data'])) {
                $crear = SuscripcionController::crearSuscripcion($empresaId, ['created_by' => Session::getIdLogin()]);
                if (!($crear['success'] ?? false)) {
                    Response::error($crear['error'] ?? 'No se pudo crear suscripción', 500);
                }
                $sus = SuscripcionController::getSuscripcionActiva($empresaId);
            }

            if (!($sus['success'] ?? false) || empty($sus['data']['id_suscripcion'])) {
                Response::error('No se pudo obtener suscripción activa', 500);
            }

            $idSuscripcion = (int)$sus['data']['id_suscripcion'];
            $existentes = [];
            if (!empty($sus['data']['apps']) && is_array($sus['data']['apps'])) {
                $existentes = array_map(fn($a) => (int)($a['id_app'] ?? 0), $sus['data']['apps']);
            }

            $agregadas = 0;
            $omitidas = 0;
            $errores = [];
            foreach ($appIds as $idApp) {
                if (in_array($idApp, $existentes, true)) {
                    $omitidas++;
                    continue;
                }
                $r = SuscripcionController::agregarApp($idSuscripcion, $idApp, []);
                if ($r['success'] ?? false) {
                    $agregadas++;
                } else {
                    $errores[] = "App {$idApp}: " . ($r['error'] ?? 'error');
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Hidratación completada: {$agregadas} agregadas, {$omitidas} existentes",
                'data' => [
                    'id_suscripcion' => $idSuscripcion,
                    'negocio' => $negocio,
                    'apps_consideradas' => count($appIds),
                    'agregadas' => $agregadas,
                    'existentes' => $omitidas,
                    'errores' => $errores
                ]
            ]);
            break;

        // --- PAGOS ---
        case 'marcar_pagado':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin puede marcar pagos', 403);
            }
            $idSuscripcion = (int)($input['id_suscripcion'] ?? 0);
            if ($idSuscripcion <= 0) {
                Response::error('ID de suscripción requerido', 400);
            }
            $resultado = SuscripcionController::marcarPagado($idSuscripcion, $input);
            echo json_encode($resultado);
            break;

        case 'generar_pago_ueno':
            $idSuscripcion = (int)($input['id_suscripcion'] ?? 0);
            if ($idSuscripcion <= 0) {
                Response::error('ID de suscripción requerido', 400);
            }
            
            // Generar datos para Ueno
            $resultado = SuscripcionController::generarPagoUeno($idSuscripcion);
            if (!$resultado['success']) {
                echo json_encode($resultado);
                break;
            }
            
            // Llamar a API de Ueno para crear el pago
            $datosUeno = $resultado['data']['datos_ueno'];
            $suscripcion = $resultado['data']['suscripcion'];
            
            // Cargar configuración de Ueno
            $uenoConfigPath = __DIR__ . '/../../pos/api/ueno_config.php';
            if (file_exists($uenoConfigPath)) {
                require_once $uenoConfigPath;
            } else {
                // Config por defecto
                define('UENO_API_URL', 'https://api.upay.com.py');
                define('UENO_TOKEN', getenv('UENO_TOKEN') ?: '');
                define('UENO_CURRENCY', 'PYG');
                define('UENO_RETURN_URL', 'https://sistemax.com.py/pagar_suscripcion.php');
                define('UENO_WEBHOOK_URL', 'https://sistemax.com.py/pos/api/ueno_webhook.php');
            }
            
            $payload = [
                'order' => [
                    'id' => $resultado['data']['payment_id'],
                    'description' => $datosUeno['description'],
                    'amount' => $datosUeno['amount'],
                    'currency' => UENO_CURRENCY,
                    'reference' => (string)$idSuscripcion,
                    'metadata' => $datosUeno['metadata']
                ],
                'notificationType' => 'link',
                'returnUrl' => UENO_RETURN_URL . "?id={$idSuscripcion}&status=success",
                'cancelUrl' => UENO_RETURN_URL . "?id={$idSuscripcion}&status=cancelled",
                'webhookUrl' => UENO_WEBHOOK_URL
            ];
            
            $ch = curl_init(UENO_API_URL . '/invoices');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . UENO_TOKEN
            ]);
            
            $responseJSON = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $response = json_decode($responseJSON, true);
            
            if ($httpCode >= 200 && $httpCode < 300 && isset($response['url'])) {
                echo json_encode([
                    'success' => true,
                    'checkout_url' => $response['url'],
                    'id_operacion' => $response['id'] ?? ''
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $response['message'] ?? 'Error al conectar con pasarela de pago',
                    'debug' => defined('UENO_ENV') && UENO_ENV === 'sandbox' ? $response : null
                ]);
            }
            break;

        case 'solicitud_estado':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $idSolicitud = (int)($input['id_solicitud'] ?? 0);
            $estado = (string)($input['estado'] ?? '');
            if ($idSolicitud <= 0) {
                Response::error('ID de solicitud requerido', 400);
            }
            $resultado = SuscripcionController::actualizarEstadoSolicitud($idSolicitud, $estado);
            echo json_encode($resultado);
            break;

        case 'solicitud_actualizar':
            if (!$isSuperAdmin) {
                Response::error('Solo super admin', 403);
            }
            $idSolicitud = (int)($input['id_solicitud'] ?? 0);
            if ($idSolicitud <= 0) {
                Response::error('ID de solicitud requerido', 400);
            }
            $resultado = SuscripcionController::actualizarSolicitud($idSolicitud, $input);
            echo json_encode($resultado);
            break;

        default:
            Response::error('Acción POST no reconocida: ' . $action, 400);
    }
}

function handlePut(string $action): void
{
    global $isSuperAdmin;

    if (!$isSuperAdmin) {
        Response::error('Solo super admin puede modificar', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    switch ($action) {
        case 'actualizar_app':
            $idApp = (int)($_GET['id'] ?? $input['id_app'] ?? 0);
            if ($idApp <= 0) {
                Response::error('ID de app requerido', 400);
            }
            $resultado = SuscripcionController::actualizarApp($idApp, $input);
            echo json_encode($resultado);
            break;

        default:
            Response::error('Acción PUT no reconocida: ' . $action, 400);
    }
}

function columnExists(PDO $db, string $table, string $column): bool
{
    try {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return false;
        }

        $schemas = [];
        $dbName = (string)($db->query("SELECT DATABASE()")->fetchColumn() ?: '');
        if ($dbName !== '') $schemas[] = $dbName;
        $schemas[] = 'serproc1';
        $schemas = array_values(array_unique($schemas));

        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = :schema
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        ");
        foreach ($schemas as $schema) {
            $stmt->execute([
                ':schema' => $schema,
                ':table' => $safeTable,
                ':column' => $safeColumn
            ]);
            if ((bool)$stmt->fetchColumn()) {
                return true;
            }
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

function ensureSaasSuscripcionSponsorColumn(PDO $db): void
{
    if (columnExists($db, 'saas_suscripcion', 'es_sponsor')) {
        return;
    }

    $schemas = [];
    try {
        $dbName = (string)($db->query("SELECT DATABASE()")->fetchColumn() ?: '');
        if ($dbName !== '') $schemas[] = $dbName;
    } catch (Throwable $e) {
        // ignore
    }
    $schemas[] = 'serproc1';
    $schemas = array_values(array_unique(array_filter($schemas)));

    $lastError = null;
    foreach ($schemas as $schema) {
        try {
            $safeSchema = preg_replace('/[^a-zA-Z0-9_]/', '', $schema);
            if ($safeSchema === '') continue;
            $db->exec("ALTER TABLE `{$safeSchema}`.`saas_suscripcion` ADD COLUMN es_sponsor TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $e) {
            $lastError = $e;
        }
        // Si otra request la creó en paralelo, continuar normalmente.
        if (columnExists($db, 'saas_suscripcion', 'es_sponsor')) {
            return;
        }
    }

    if (columnExists($db, 'saas_suscripcion', 'es_sponsor')) {
        return;
    }

    if ($lastError) {
        throw $lastError;
    }
    throw new RuntimeException('No se pudo asegurar columna es_sponsor');
}

function ensureTipoNegocioSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS tipo_negocio (
            id INT NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(120) NOT NULL,
            orden INT NOT NULL DEFAULT 100,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            disponible TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_nombre (nombre),
            KEY idx_orden (orden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $st = $db->prepare("SHOW COLUMNS FROM tipo_negocio LIKE 'disponible'");
        $st->execute();
        if (!$st->fetch(PDO::FETCH_ASSOC)) {
            $db->exec("ALTER TABLE tipo_negocio ADD COLUMN disponible TINYINT(1) NOT NULL DEFAULT 1 AFTER activo");
        }
    } catch (Throwable $e) {}

    $db->exec("
        CREATE TABLE IF NOT EXISTS tipo_negocio_app (
            id INT NOT NULL AUTO_INCREMENT,
            tipo_negocio_id INT NOT NULL,
            id_app INT NOT NULL,
            orden INT NOT NULL DEFAULT 100,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_tipo_app (tipo_negocio_id, id_app),
            KEY idx_tipo (tipo_negocio_id),
            KEY idx_app (id_app),
            CONSTRAINT fk_tipo_negocio_app_tipo
                FOREIGN KEY (tipo_negocio_id) REFERENCES tipo_negocio(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ensureBasicAppsForEmptyBusinessTypes($db);
}

function getBasicCommerceAppIds(PDO $db): array
{
    $preferredCodes = [
        'pos',
        'venta_pos',
        'ventas',
        'compras',
        'productos',
        'contactos',
        'clientes',
        'cajas',
        'cuentas',
        'panel'
    ];

    $hasDisponible = false;
    try {
        $stCol = $db->prepare("SHOW COLUMNS FROM saas_apps_catalogo LIKE 'disponible'");
        $stCol->execute();
        $hasDisponible = (bool)$stCol->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $hasDisponible = false;
    }

    $in = implode(',', array_fill(0, count($preferredCodes), '?'));
    $sql = "
        SELECT id_app, codigo
        FROM saas_apps_catalogo
        WHERE activo = 1
          " . ($hasDisponible ? "AND COALESCE(disponible,1)=1" : "") . "
          AND codigo IN ({$in})
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($preferredCodes);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byCode = [];
    foreach ($rows as $r) {
        $code = strtolower(trim((string)($r['codigo'] ?? '')));
        $id = (int)($r['id_app'] ?? 0);
        if ($code !== '' && $id > 0) $byCode[$code] = $id;
    }

    $ordered = [];
    foreach ($preferredCodes as $code) {
        if (isset($byCode[$code])) $ordered[] = (int)$byCode[$code];
    }
    return array_values(array_unique(array_filter($ordered)));
}

function ensureBasicAppsForEmptyBusinessTypes(PDO $db): void
{
    $basicAppIds = getBasicCommerceAppIds($db);
    if (empty($basicAppIds)) {
        return;
    }

    $stmtTipos = $db->query("
        SELECT tn.id
        FROM tipo_negocio tn
        LEFT JOIN tipo_negocio_app tna
            ON tna.tipo_negocio_id = tn.id
           AND tna.activo = 1
        WHERE tn.activo = 1
        GROUP BY tn.id
        HAVING COUNT(tna.id) = 0
    ");
    $tiposSinApps = array_map('intval', $stmtTipos->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (empty($tiposSinApps)) {
        return;
    }

    $ins = $db->prepare("
        INSERT IGNORE INTO tipo_negocio_app (tipo_negocio_id, id_app, orden, activo)
        VALUES (?, ?, ?, 1)
    ");
    foreach ($tiposSinApps as $tipoId) {
        $ord = 10;
        foreach ($basicAppIds as $idApp) {
            $ins->execute([$tipoId, $idApp, $ord]);
            $ord += 10;
        }
    }
}

function handleDelete(string $action): void
{
    global $isSuperAdmin;

    if (!$isSuperAdmin) {
        Response::error('Solo super admin puede eliminar', 403);
    }

    switch ($action) {
        case 'eliminar_app':
            $idApp = (int)($_GET['id'] ?? 0);
            if ($idApp <= 0) {
                Response::error('ID de app requerido', 400);
            }
            $resultado = SuscripcionController::eliminarApp($idApp);
            echo json_encode($resultado);
            break;

        case 'quitar_app':
            $idSuscripcion = (int)($_GET['id_suscripcion'] ?? 0);
            $idApp = (int)($_GET['id_app'] ?? 0);
            if ($idSuscripcion <= 0 || $idApp <= 0) {
                Response::error('IDs requeridos', 400);
            }
            $resultado = SuscripcionController::quitarApp($idSuscripcion, $idApp);
            echo json_encode($resultado);
            break;

        case 'solicitud_eliminar':
            $idSolicitud = (int)($_GET['id_solicitud'] ?? 0);
            if ($idSolicitud <= 0) {
                Response::error('ID de solicitud requerido', 400);
            }
            $resultado = SuscripcionController::eliminarSolicitud($idSolicitud);
            echo json_encode($resultado);
            break;

        default:
            Response::error('Acción DELETE no reconocida: ' . $action, 400);
    }
}

// =========================================================================
// FUNCIONES DE DASHBOARD
// =========================================================================

function getDashboardKPIs(): array
{
    $db = Database::getMasterConnection();
    
    $mesActual = date('Y-m-01');
    $mesAnterior = date('Y-m-01', strtotime('-1 month'));
    
    // Empresas con suscripción activa
    $stmt = $db->query("
        SELECT COUNT(DISTINCT id_empresa) as total 
        FROM saas_suscripcion 
        WHERE estado IN ('activa', 'gracia')
    ");
    $empresasActivas = (int)$stmt->fetch()['total'];
    
    // Ingresos del mes actual (pagados)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as total 
        FROM saas_suscripcion 
        WHERE estado_pago = 'pagado' 
        AND periodo_inicio >= ?
    ");
    $stmt->execute([$mesActual]);
    $ingresosMes = (float)$stmt->fetch()['total'];
    
    // Ingresos mes anterior (para comparación)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as total 
        FROM saas_suscripcion 
        WHERE estado_pago = 'pagado' 
        AND periodo_inicio >= ? 
        AND periodo_inicio < ?
    ");
    $stmt->execute([$mesAnterior, $mesActual]);
    $ingresosMesAnterior = (float)$stmt->fetch()['total'];
    
    // Variación porcentual
    $variacionIngresos = $ingresosMesAnterior > 0 
        ? round((($ingresosMes - $ingresosMesAnterior) / $ingresosMesAnterior) * 100, 1) 
        : 0;
    
    // Suscripciones pendientes de pago
    $stmt = $db->query("
        SELECT COUNT(*) as total 
        FROM saas_suscripcion 
        WHERE estado_pago IN ('pendiente', 'atrasado')
        AND estado != 'cancelada'
    ");
    $pendientesPago = (int)$stmt->fetch()['total'];
    
    // Monto pendiente
    $stmt = $db->query("
        SELECT COALESCE(SUM(total), 0) as total 
        FROM saas_suscripcion 
        WHERE estado_pago IN ('pendiente', 'atrasado')
        AND estado != 'cancelada'
    ");
    $montoPendiente = (float)$stmt->fetch()['total'];
    
    // Suscripciones en período de gracia
    $stmt = $db->query("
        SELECT COUNT(*) as total 
        FROM saas_suscripcion 
        WHERE estado = 'gracia'
    ");
    $enGracia = (int)$stmt->fetch()['total'];
    
    // Suscripciones vencidas/bloqueadas
    $stmt = $db->query("
        SELECT COUNT(*) as total 
        FROM saas_suscripcion 
        WHERE estado = 'vencida'
    ");
    $vencidas = (int)$stmt->fetch()['total'];
    
    // Tasa de cobro (pagados vs total del mes)
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN estado_pago = 'pagado' THEN 1 ELSE 0 END) as pagados
        FROM saas_suscripcion 
        WHERE periodo_inicio >= ?
    ");
    $stmt->execute([$mesActual]);
    $tasaData = $stmt->fetch();
    $tasaCobro = $tasaData['total'] > 0 
        ? round(($tasaData['pagados'] / $tasaData['total']) * 100, 1) 
        : 0;
    
    // Total empresas registradas
    $stmt = $db->query("SELECT COUNT(*) as total FROM empresa WHERE activo = 1");
    $totalEmpresas = (int)$stmt->fetch()['total'];
    
    return [
        'success' => true,
        'data' => [
            'empresas_activas' => $empresasActivas,
            'total_empresas' => $totalEmpresas,
            'ingresos_mes' => $ingresosMes,
            'ingresos_mes_anterior' => $ingresosMesAnterior,
            'variacion_ingresos' => $variacionIngresos,
            'pendientes_pago' => $pendientesPago,
            'monto_pendiente' => $montoPendiente,
            'en_gracia' => $enGracia,
            'vencidas' => $vencidas,
            'tasa_cobro' => $tasaCobro,
            'mes_actual' => date('F Y')
        ]
    ];
}

function getIngresosMensuales(int $meses = 12): array
{
    $db = Database::getMasterConnection();
    
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(periodo_inicio, '%Y-%m') as mes,
            DATE_FORMAT(periodo_inicio, '%b %Y') as mes_label,
            COALESCE(SUM(CASE WHEN estado_pago = 'pagado' THEN total ELSE 0 END), 0) as ingresos,
            COALESCE(SUM(CASE WHEN estado_pago IN ('pendiente', 'atrasado') THEN total ELSE 0 END), 0) as pendiente,
            COUNT(*) as suscripciones
        FROM saas_suscripcion
        WHERE periodo_inicio >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        GROUP BY DATE_FORMAT(periodo_inicio, '%Y-%m'), DATE_FORMAT(periodo_inicio, '%b %Y')
        ORDER BY mes ASC
    ");
    $stmt->execute([$meses]);
    
    return [
        'success' => true,
        'data' => $stmt->fetchAll()
    ];
}

function getEstadoPagos(): array
{
    $db = Database::getMasterConnection();
    
    $stmt = $db->query("
        SELECT 
            estado_pago,
            COUNT(*) as cantidad,
            COALESCE(SUM(total), 0) as monto
        FROM saas_suscripcion
        WHERE estado != 'cancelada'
        AND periodo_inicio >= DATE_FORMAT(CURDATE(), '%Y-01-01')
        GROUP BY estado_pago
    ");
    
    $data = [];
    while ($row = $stmt->fetch()) {
        $data[$row['estado_pago']] = [
            'cantidad' => (int)$row['cantidad'],
            'monto' => (float)$row['monto']
        ];
    }
    
    return [
        'success' => true,
        'data' => $data
    ];
}

function getProximosVencer(int $dias = 7): array
{
    $db = Database::getMasterConnection();
    
    $stmt = $db->prepare("
        SELECT 
            s.id_suscripcion,
            s.nro_factura,
            s.id_empresa,
            e.empresa as nombre_empresa,
            e.ruc,
            s.periodo_fin,
            s.total,
            s.estado,
            s.estado_pago,
            DATEDIFF(s.periodo_fin, CURDATE()) as dias_restantes
        FROM saas_suscripcion s
        INNER JOIN empresa e ON s.id_empresa = e.id_empresa
        WHERE s.estado IN ('activa', 'gracia')
        AND s.estado_pago != 'pagado'
        AND s.periodo_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY s.periodo_fin ASC
        LIMIT 20
    ");
    $stmt->execute([$dias]);
    
    return [
        'success' => true,
        'data' => $stmt->fetchAll()
    ];
}
