<?php
require_once __DIR__ . '/../../config/bootstrap.php';

Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_mercaderias');

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
$forceDesktop = isset($_GET['desktop']) || isset($_COOKIE['inventario_desktop']);

$target = ($isMobile && !$forceDesktop)
    ? '/public/inventario/mobile.php?focus=traslados'
    : '/public/inventario/index.php?focus=traslados&desktop=1';

header('Location: ' . $target);
exit;
