<?php
/**
 * API - Configuración de impresión para Mis Ventas
 * Retorna la caja efectiva del usuario y la impresora configurada.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/bootstrap.php';

Session::start();
Permission::requireAccess('app_grid_factura_venta_global');

$id_empresa = (int)Session::get('id_empresa');
$id_login = (int)Session::get('id_login');
$id_caja = (int)Session::get('id_caja_def', 0);

if ($id_empresa <= 0 || $id_login <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Sesión inválida']);
    exit;
}

try {
    $masterPdo = Database::getMasterConnection();

    $stmtEmpresa = $masterPdo->prepare("SELECT dbase FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
    $stmtEmpresa->execute([':id' => $id_empresa]);
    $dbase = (string)($stmtEmpresa->fetchColumn() ?: '');
    if ($dbase === '') {
        throw new Exception('Empresa sin dbase');
    }

    $sourceCaja = 'session';
    if ($id_caja <= 0) {
        $stmtCajaUser = $masterPdo->prepare("SELECT caja_def FROM " . MASTER_DB . ".sec_users WHERE id_login = :id LIMIT 1");
        $stmtCajaUser->execute([':id' => $id_login]);
        $id_caja = (int)$stmtCajaUser->fetchColumn();
        $sourceCaja = 'sec_users.caja_def';
    }

    // Fallback: primera caja asignada en cajas_usuarios (si existe)
    if ($id_caja <= 0) {
        try {
            $stmtCajaAsig = $masterPdo->prepare("SELECT id_caja FROM {$dbase}.cajas_usuarios WHERE id_login = :id ORDER BY id_caja LIMIT 1");
            $stmtCajaAsig->execute([':id' => $id_login]);
            $id_caja = (int)$stmtCajaAsig->fetchColumn();
            if ($id_caja > 0) {
                $sourceCaja = 'cajas_usuarios';
            }
        } catch (Throwable $e) {
            // tabla puede no existir
        }
    }

    $impresora = '';
    $colImpresora = 'impresora';
    try {
        $cols = $masterPdo->query("SHOW COLUMNS FROM {$dbase}.cajas")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (is_array($cols)) {
            if (in_array('impresora', $cols, true)) {
                $colImpresora = 'impresora';
            } elseif (in_array('impresor', $cols, true)) {
                $colImpresora = 'impresor';
            } else {
                $colImpresora = '';
            }
        }
    } catch (Throwable $e) {
        $colImpresora = 'impresora';
    }

    if ($id_caja > 0 && $colImpresora !== '') {
        try {
            $stmtImp = $masterPdo->prepare("SELECT {$colImpresora} FROM {$dbase}.cajas WHERE id_caja = :id_caja AND id_empresa = :id_empresa LIMIT 1");
            $stmtImp->execute([':id_caja' => $id_caja, ':id_empresa' => $id_empresa]);
            $impresora = (string)($stmtImp->fetchColumn() ?: '');
            if (trim($impresora) === '') {
                $stmtImp2 = $masterPdo->prepare("SELECT {$colImpresora} FROM {$dbase}.cajas WHERE id_caja = :id_caja LIMIT 1");
                $stmtImp2->execute([':id_caja' => $id_caja]);
                $impresora = (string)($stmtImp2->fetchColumn() ?: '');
            }
        } catch (Throwable $e) {
            $stmtImp2 = $masterPdo->prepare("SELECT {$colImpresora} FROM {$dbase}.cajas WHERE id_caja = :id_caja LIMIT 1");
            $stmtImp2->execute([':id_caja' => $id_caja]);
            $impresora = (string)($stmtImp2->fetchColumn() ?: '');
        }
    }

    // Si detectamos caja por fallback, persistir en sesión y usuario para consistencia con POS.
    if ($id_caja > 0 && Session::get('id_caja_def', 0) != $id_caja) {
        Session::set('id_caja_def', $id_caja);
        try {
            $stmtUpd = $masterPdo->prepare("UPDATE " . MASTER_DB . ".sec_users SET caja_def = :caja WHERE id_login = :id LIMIT 1");
            $stmtUpd->execute([':caja' => $id_caja, ':id' => $id_login]);
        } catch (Throwable $e) {
            // no bloquear
        }
    }

    echo json_encode([
        'success' => true,
        'id_caja' => $id_caja,
        'impresora' => trim($impresora),
        'source_caja' => $sourceCaja,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
