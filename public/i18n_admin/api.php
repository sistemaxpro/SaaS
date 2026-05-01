<?php

require_once __DIR__ . '/../../config/bootstrap.php';

Session::requireLogin('/public/login.php');

$idEmpresa = (int)Session::get('id_empresa');
$isAdmin = Session::isAdmin() || !empty($_SESSION['usr_ti']);
if ($idEmpresa !== 169 || !$isAdmin) {
    Response::forbidden('Acceso restringido');
}

if (!SmxI18n::ensureDbOverridesTable()) {
    Response::error('No se pudo asegurar la tabla de overrides', 500);
}

function i18n_admin_db(): PDO
{
    return Database::getMasterConnection();
}

function i18n_admin_input(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        return json_decode($raw ?: '{}', true) ?: [];
    }
    return $_POST ?: [];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'list') {
        $locale = SmxI18n::normalizeLocale($_GET['locale'] ?? null) ?? 'es';
        $search = trim((string)($_GET['search'] ?? ''));
        $db = i18n_admin_db();
        $sql = "SELECT id, locale, message_key, override_value, notes, active, created_at, updated_at
                FROM " . MASTER_DB . ".smx_i18n_overrides
                WHERE locale = :locale";
        $params = [':locale' => $locale];
        if ($search !== '') {
            $sql .= " AND (message_key LIKE :search_key OR override_value LIKE :search_value OR notes LIKE :search_notes)";
            $params[':search_key'] = '%' . $search . '%';
            $params[':search_value'] = '%' . $search . '%';
            $params[':search_notes'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY active DESC, message_key ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        Response::success($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    if ($action === 'catalog') {
        $locale = SmxI18n::normalizeLocale($_GET['locale'] ?? null) ?? 'es';
        $search = trim((string)($_GET['search'] ?? ''));
        $flat = SmxI18n::flattenLocaleMessages($locale);
        $rows = [];
        foreach ($flat as $key => $value) {
            if ($search !== '' && stripos($key . ' ' . $value, $search) === false) {
                continue;
            }
            $rows[] = [
                'message_key' => $key,
                'base_value' => $value,
            ];
        }
        Response::success(array_values($rows));
    }

    if ($action === 'save') {
        $input = i18n_admin_input();
        $locale = SmxI18n::normalizeLocale($input['locale'] ?? null);
        $messageKey = trim((string)($input['message_key'] ?? ''));
        $overrideValue = trim((string)($input['override_value'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));
        $active = (int)($input['active'] ?? 1) === 1 ? 1 : 0;
        $id = (int)($input['id'] ?? 0);

        if ($locale === null) {
            Response::error('Locale invalido', 422);
        }
        if ($messageKey === '') {
            Response::error('La clave es obligatoria', 422);
        }
        if ($overrideValue === '') {
            Response::error('El valor override es obligatorio', 422);
        }

        $db = i18n_admin_db();
        if ($id > 0) {
            $stmt = $db->prepare("
                UPDATE " . MASTER_DB . ".smx_i18n_overrides
                SET locale = :locale,
                    message_key = :message_key,
                    override_value = :override_value,
                    notes = :notes,
                    active = :active,
                    updated_by = :updated_by
                WHERE id = :id
            ");
            $stmt->execute([
                ':locale' => $locale,
                ':message_key' => $messageKey,
                ':override_value' => $overrideValue,
                ':notes' => $notes !== '' ? $notes : null,
                ':active' => $active,
                ':updated_by' => (int)Session::getIdLogin(),
                ':id' => $id,
            ]);
            Response::success(['id' => $id], 'Override actualizado');
        }

        $stmt = $db->prepare("
            INSERT INTO " . MASTER_DB . ".smx_i18n_overrides
                (locale, message_key, override_value, notes, active, created_by, updated_by)
            VALUES
                (:locale, :message_key, :override_value, :notes, :active, :created_by, :updated_by)
            ON DUPLICATE KEY UPDATE
                override_value = VALUES(override_value),
                notes = VALUES(notes),
                active = VALUES(active),
                updated_by = VALUES(updated_by)
        ");
        $stmt->execute([
            ':locale' => $locale,
            ':message_key' => $messageKey,
            ':override_value' => $overrideValue,
            ':notes' => $notes !== '' ? $notes : null,
            ':active' => $active,
            ':created_by' => (int)Session::getIdLogin(),
            ':updated_by' => (int)Session::getIdLogin(),
        ]);
        Response::success(['message_key' => $messageKey], 'Override guardado');
    }

    if ($action === 'delete') {
        $input = i18n_admin_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            Response::error('ID invalido', 422);
        }
        $db = i18n_admin_db();
        $stmt = $db->prepare("
            UPDATE " . MASTER_DB . ".smx_i18n_overrides
            SET active = 0,
                updated_by = :updated_by
            WHERE id = :id
        ");
        $stmt->execute([
            ':updated_by' => (int)Session::getIdLogin(),
            ':id' => $id,
        ]);
        Response::success(['id' => $id], 'Override desactivado');
    }

    Response::error('Accion no valida', 400);
} catch (Throwable $e) {
    error_log('[i18n_admin/api] ' . $e->getMessage());
    Response::error('Error interno', 500);
}
