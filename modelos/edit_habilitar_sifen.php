<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_GET['id_empresa']) && $_GET['id_empresa'] !== '') {
    $_SESSION['id_empresa'] = (int) $_GET['id_empresa'];
}

require_once __DIR__ . '/habilitacion_sifen.php';
