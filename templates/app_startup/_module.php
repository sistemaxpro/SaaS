<?php
/**
 * Template de helpers del modulo.
 * Copia este archivo si tu app necesita funciones compartidas.
 */

require_once __DIR__ . '/config/db_config.php';

function appStartupCurrentDb(): array
{
    $idEmpresa = (int)(Session::getIdEmpresa() ?? 0);
    if ($idEmpresa <= 0) {
        throw new RuntimeException('No hay empresa seleccionada');
    }

    return getEmpresaConnection($idEmpresa);
}

