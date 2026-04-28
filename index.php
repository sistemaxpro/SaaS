<?php
/**
 * Entrada principal del proyecto.
 * En DEV redirige directo al login; en PROD a la landing.
 */
require_once __DIR__ . '/config/bootstrap.php';

if (defined('SISTEMAX_IS_DEV') && SISTEMAX_IS_DEV) {
    header('Location: /public/login.php');
} else {
    header('Location: /public/index.php');
}
exit;
