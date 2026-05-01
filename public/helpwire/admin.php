<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Location: /public/menu/menu.php', true, 302);
exit;

$idEmpresa = (int)Session::getIdEmpresa();
if (!in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true)) {
    header('Location: /public/helpwire/index.php', true, 302);
    exit;
}

require __DIR__ . '/../soporte/desktop_admin.php';
