<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Location: /public/menu/menu.php', true, 302);
exit;

$idEmpresa = (int)Session::getIdEmpresa();
if (in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true)) {
    header('Location: /public/helpwire/admin.php', true, 302);
    exit;
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isAndroid = (bool)preg_match('/Android/i', $ua);
$isIOS = (bool)preg_match('/iPhone|iPad|iPod/i', $ua);
if ($isAndroid || $isIOS) {
    $platform = $isAndroid ? 'android' : 'ios';
    header('Location: /public/helpwire/download.php?platform=' . rawurlencode($platform), true, 302);
    exit;
}

require __DIR__ . '/../soporte/index.php';
