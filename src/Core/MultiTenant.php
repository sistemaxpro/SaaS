<?php

/**
 * Multi-Empresa / Multi-Moneda / Multi-Sucursal
 */

class MultiTenant
{
    /**
     * Obtener monedas de la empresa actual
     */
    public static function getMonedas(): array
    {
        $db = Database::getSessionEmpresaConnection();
        $dbase = Session::getDbase();

        $stmt = $db->prepare("SELECT * FROM {$dbase}.monedas WHERE activo = 1 ORDER BY id_moneda");
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Obtener moneda por defecto
     */
    public static function getMonedaDefault(): ?array
    {
        $monedas = self::getMonedas();
        foreach ($monedas as $moneda) {
            if (($moneda['por_defecto'] ?? 0) == 1) {
                return $moneda;
            }
        }
        return $monedas[0] ?? null;
    }

    /**
     * Obtener sucursales de la empresa actual
     */
    public static function getSucursales(): array
    {
        $db = Database::getSessionEmpresaConnection();
        $dbase = Session::getDbase();

        $stmt = $db->prepare("SELECT * FROM {$dbase}.sucursales WHERE activo = 1 ORDER BY id_sucursal");
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Obtener sucursal activa del usuario
     */
    public static function getSucursalActiva(): ?int
    {
        return Session::get('id_sucursal');
    }

    /**
     * Establecer sucursal activa
     */
    public static function setSucursalActiva(int $idSucursal): void
    {
        Session::set('id_sucursal', $idSucursal);
    }

    /**
     * Obtener información de la empresa actual
     */
    public static function getEmpresaActual(): ?array
    {
        $idEmpresa = Session::getIdEmpresa();
        if (!$idEmpresa) {
            return null;
        }
        try {
            return Database::getEmpresaInfo($idEmpresa);
        } catch (Exception $e) {
            error_log("[WARN] No se pudo obtener empresa actual desde master DB: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Cambiar empresa activa (solo para usuarios con múltiples empresas)
     */
    public static function cambiarEmpresa(int $idEmpresa): bool
    {
        try {
            $empresa = Database::getEmpresaInfo($idEmpresa);
            if (!$empresa) {
                return false;
            }

            Session::setEmpresa($empresa);
            return true;
        } catch (Exception $e) {
            error_log("[ERROR] Error al cambiar empresa: " . $e->getMessage());
            return false;
        }
    }
}
