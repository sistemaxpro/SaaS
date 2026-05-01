<?php
require_once __DIR__ . '/common.php';

function tallerVehiculoFotoUrlCatalogo(int $idEmpresa, int $idVehiculo, array $foto): string
{
    $storage = strtolower(trim((string)($foto['storage'] ?? 'local')));
    $url = trim((string)($foto['url'] ?? ''));
    $ruta = trim((string)($foto['ruta'] ?? ''));

    if ($storage === 'r2' && $url !== '') {
        return $url;
    }
    if ($url !== '' && preg_match('~^https?://~i', $url)) {
        return $url;
    }
    if ($ruta === '') {
        return '';
    }

    $safe = rawurlencode($ruta);
    return "/public/_lib/file/img/taller_vehiculos/e{$idEmpresa}/v{$idVehiculo}/{$safe}";
}

$idEmpresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);
if ($idEmpresa <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'id_empresa requerido']);
    exit;
}

try {
    $conn = tallerConn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $masterPdo = $conn['masterPdo'] ?? Database::getMasterConnection();
    $masterDb = $conn['masterDb'] ?? MASTER_DB;

    $useContactos = tallerHasTable($pdo, $db, 'clientes')
        && tallerHasColumn($pdo, $db, 'clientes', 'id')
        && tallerHasColumn($pdo, $db, 'clientes', 'nombre');

    if ($useContactos) {
        $telefonoExpr = tallerHasColumn($pdo, $db, 'clientes', 'telefono') ? "c.telefono" : "''";
        $docExpr = tallerHasColumn($pdo, $db, 'clientes', 'numero')
            ? "c.numero"
            : (tallerHasColumn($pdo, $db, 'clientes', 'documento') ? "c.documento" : "''");
        $saldoExpr = tallerHasColumn($pdo, $db, 'clientes', 'saldo_guaranies')
            ? "COALESCE(c.saldo_guaranies, 0)"
            : (tallerHasColumn($pdo, $db, 'clientes', 'saldo') ? "COALESCE(c.saldo, 0)" : "0");
        $whereEstado = tallerHasColumn($pdo, $db, 'clientes', 'estado') ? "AND c.estado = 1" : "";
        $whereCuenta = tallerHasColumn($pdo, $db, 'clientes', 'cuenta') ? "AND c.cuenta = 3" : "";

        $clientesSql = "SELECT c.id AS id_cliente, c.nombre, {$telefonoExpr} AS telefono, {$docExpr} AS documento, {$saldoExpr} AS saldo
                        FROM {$db}.clientes c
                        WHERE 1=1 {$whereEstado} {$whereCuenta}
                        ORDER BY c.nombre ASC";
        $clientes = $pdo->query($clientesSql)->fetchAll(PDO::FETCH_ASSOC);

        $vehiculosSql = "SELECT v.id_vehiculo, v.id_cliente, v.marca, v.modelo, v.anio, v.chapa,
                                COALESCE(NULLIF(v.chassis, ''), v.vin, '') AS chassis,
                                COALESCE(v.color, '') AS color,
                                (
                                    SELECT COALESCE(NULLIF(vf.url, ''), vf.ruta)
                                    FROM {$db}.taller_vehiculo_fotos vf
                                    WHERE vf.id_vehiculo = v.id_vehiculo
                                    ORDER BY vf.id_foto DESC
                                    LIMIT 1
                                ) AS foto_ruta,
                                v.km_actual, c.nombre AS cliente
                         FROM {$db}.taller_vehiculos v
                         LEFT JOIN {$db}.clientes c ON c.id = v.id_cliente
                         WHERE v.activo = 1
                         ORDER BY v.chapa ASC";
        $vehiculos = $pdo->query($vehiculosSql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $clientes = $pdo->query("SELECT id_cliente, nombre, telefono, documento, 0 AS saldo FROM {$db}.taller_clientes WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
        $vehiculos = $pdo->query("SELECT v.id_vehiculo, v.id_cliente, v.marca, v.modelo, v.anio, v.chapa,
                                         COALESCE(NULLIF(v.chassis, ''), v.vin, '') AS chassis,
                                         COALESCE(v.color, '') AS color,
                                         (
                                             SELECT COALESCE(NULLIF(vf.url, ''), vf.ruta)
                                             FROM {$db}.taller_vehiculo_fotos vf
                                             WHERE vf.id_vehiculo = v.id_vehiculo
                                             ORDER BY vf.id_foto DESC
                                             LIMIT 1
                                         ) AS foto_ruta,
                                         v.km_actual, c.nombre AS cliente
                                  FROM {$db}.taller_vehiculos v
                                  LEFT JOIN {$db}.taller_clientes c ON c.id_cliente = v.id_cliente
                                  WHERE v.activo = 1
                                  ORDER BY v.chapa ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $fotosMap = [];
    $idsVehiculos = array_values(array_filter(array_map(static fn($x) => (int)($x['id_vehiculo'] ?? 0), $vehiculos), static fn($x) => $x > 0));
    if (!empty($idsVehiculos)) {
        $placeholders = implode(',', array_fill(0, count($idsVehiculos), '?'));
        $stFotos = $pdo->prepare("
            SELECT id_vehiculo, ruta, storage, file_id, url
            FROM {$db}.taller_vehiculo_fotos
            WHERE id_vehiculo IN ({$placeholders})
            ORDER BY id_foto DESC
        ");
        $stFotos->execute($idsVehiculos);
        $allFotos = $stFotos->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($allFotos as $f) {
            $idv = (int)($f['id_vehiculo'] ?? 0);
            if ($idv <= 0) continue;
            $resolvedUrl = tallerVehiculoFotoUrlCatalogo($idEmpresa, $idv, $f);
            if ($resolvedUrl === '') continue;
            if (!isset($fotosMap[$idv])) $fotosMap[$idv] = [];
            $fotosMap[$idv][] = $resolvedUrl;
        }
    }

    foreach ($vehiculos as &$v) {
        $idv = (int)($v['id_vehiculo'] ?? 0);
        $urls = $fotosMap[$idv] ?? [];
        if (empty($urls)) {
            $ruta = trim((string)($v['foto_ruta'] ?? ''));
            if ($ruta !== '') {
                $urls[] = preg_match('~^https?://~i', $ruta)
                    ? $ruta
                    : tallerVehiculoFotoUrlCatalogo($idEmpresa, $idv, ['ruta' => $ruta]);
            }
        }
        $v['fotos_urls'] = $urls;
        $v['foto_url'] = $urls[0] ?? '';
    }
    unset($v);

    $servicios = $pdo->query("SELECT id_servicio, servicio, descripcion, costo_base, duracion_horas
                              FROM {$db}.taller_servicios
                              WHERE activo = 1
                              ORDER BY servicio ASC")->fetchAll(PDO::FETCH_ASSOC);

    $mecanicosStmt = $masterPdo->prepare("
        SELECT id_login,
               login,
               COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(login), ''), CONCAT('Usuario #', id_login)) AS nombre
        FROM {$masterDb}.sec_users
        WHERE id_empresa = :id_empresa
          AND COALESCE(active, 'Y') = 'Y'
        ORDER BY nombre ASC, login ASC
    ");
    $mecanicosStmt->execute([':id_empresa' => $idEmpresa]);
    $mecanicos = $mecanicosStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'data' => [
            'clientes' => $clientes,
            'vehiculos' => $vehiculos,
            'servicios' => $servicios,
            'mecanicos' => $mecanicos,
            'estados_ot' => ['abierta', 'en_proceso', 'finalizada', 'entregada', 'cancelada'],
            'prioridades' => ['baja', 'media', 'alta', 'urgente'],
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
