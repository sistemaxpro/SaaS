<?php
/**
 * Redirect permanente al menú principal (menu/menu.php).
 * Este archivo se mantiene por compatibilidad con URLs cacheadas,
 * bookmarks y el Service Worker.
 */
require_once __DIR__ . '/../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Location: /public/menu/menu.php', true, 301);
exit;
