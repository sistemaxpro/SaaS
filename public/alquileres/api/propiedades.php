<?php

require_once __DIR__ . '/common.php';

try {
    [$pdo, $db] = smxAlqDb();
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $rows = $pdo->query("
            SELECT p.*,
                   (
                       SELECT COALESCE(im.url_cover, im.url_preview, im.url_original, im.archivo_local, '')
                       FROM `{$db}`.`alq_propiedad_imagenes` im
                       WHERE im.id_propiedad = p.id_propiedad
                       ORDER BY im.principal DESC, im.orden ASC, im.id_imagen ASC
                       LIMIT 1
                   ) AS imagen_principal,
                   (
                       SELECT COUNT(*)
                       FROM `{$db}`.`alq_propiedad_imagenes` im
                       WHERE im.id_propiedad = p.id_propiedad
                   ) AS imagenes_count
            FROM `{$db}`.`alq_propiedades` p
            ORDER BY p.updated_at DESC, p.id_propiedad DESC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        smxAlqJson(['ok' => true, 'rows' => $rows]);
    }

    if ($action === 'save') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($payload['id_propiedad'] ?? 0);
        $data = [
            'codigo' => trim((string)($payload['codigo'] ?? '')),
            'nombre' => trim((string)($payload['nombre'] ?? '')),
            'tipo_inmueble' => trim((string)($payload['tipo_inmueble'] ?? 'departamento')),
            'direccion' => trim((string)($payload['direccion'] ?? '')),
            'ciudad' => trim((string)($payload['ciudad'] ?? '')),
            'matricula' => trim((string)($payload['matricula'] ?? '')),
            'padron' => trim((string)($payload['padron'] ?? '')),
            'area_m2' => (float)($payload['area_m2'] ?? 0),
            'moneda' => strtoupper(trim((string)($payload['moneda'] ?? 'PYG'))),
            'canon_mensual' => (float)($payload['canon_mensual'] ?? 0),
            'deposito_garantia' => (float)($payload['deposito_garantia'] ?? 0),
            'dia_vencimiento' => max(1, min(28, (int)($payload['dia_vencimiento'] ?? 10))),
            'mora_diaria' => (float)($payload['mora_diaria'] ?? 0),
            'iva_incluido' => (int)($payload['iva_incluido'] ?? 1) === 1 ? 1 : 0,
            'estado' => trim((string)($payload['estado'] ?? 'disponible')),
            'observacion' => trim((string)($payload['observacion'] ?? '')),
        ];
        if ($data['codigo'] === '' || $data['nombre'] === '') {
            smxAlqJson(['ok' => false, 'error' => 'Código y nombre son obligatorios'], 422);
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE `{$db}`.`alq_propiedades`
                SET codigo=:codigo, nombre=:nombre, tipo_inmueble=:tipo_inmueble, direccion=:direccion, ciudad=:ciudad,
                    matricula=:matricula, padron=:padron, area_m2=:area_m2, moneda=:moneda, canon_mensual=:canon_mensual,
                    deposito_garantia=:deposito_garantia, dia_vencimiento=:dia_vencimiento, mora_diaria=:mora_diaria,
                    iva_incluido=:iva_incluido, estado=:estado, observacion=:observacion
                WHERE id_propiedad=:id
            ");
            $data['id'] = $id;
            $stmt->execute($data);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO `{$db}`.`alq_propiedades`
                    (codigo, nombre, tipo_inmueble, direccion, ciudad, matricula, padron, area_m2, moneda, canon_mensual,
                     deposito_garantia, dia_vencimiento, mora_diaria, iva_incluido, estado, observacion)
                VALUES
                    (:codigo, :nombre, :tipo_inmueble, :direccion, :ciudad, :matricula, :padron, :area_m2, :moneda, :canon_mensual,
                     :deposito_garantia, :dia_vencimiento, :mora_diaria, :iva_incluido, :estado, :observacion)
            ");
            $stmt->execute($data);
        }

        smxAlqJson(['ok' => true]);
    }

    smxAlqJson(['ok' => false, 'error' => 'Acción no soportada'], 400);
} catch (Throwable $e) {
    smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
