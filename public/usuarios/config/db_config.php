<?php
/**
 * Usuarios - Configuración de Base de Datos
 * Wrapper para usar el sistema de conexión de v1
 */

if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../../../config/bootstrap.php';
}

Session::start();

/**
 * Obtiene conexión al master (serproc1)
 */
function getMasterPdo() {
    return Database::getMasterConnection();
}
