<?php

require_once __DIR__ . '/../../config/bootstrap.php';

$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true) ?: [];
}

$locale = SmxI18n::normalizeLocale($input['locale'] ?? $_POST['locale'] ?? $_GET['locale'] ?? null);
if ($locale === null) {
    Response::error('Idioma inválido', 422, [
        'locale' => SmxI18n::getLocale(),
    ]);
}

$resolved = SmxI18n::setLocale($locale);

Response::success([
    'locale' => $resolved,
    'supported' => SmxI18n::getLocaleOptions(),
]);
