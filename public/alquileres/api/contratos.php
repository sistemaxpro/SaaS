<?php

require_once __DIR__ . '/common.php';

try {
    [$pdo, $db] = smxAlqDb();
    $action = $_GET['action'] ?? 'list';

    if ($action === 'catalogs') {
        $propiedades = $pdo->query("SELECT id_propiedad, codigo, nombre, canon_mensual, deposito_garantia, dia_vencimiento, mora_diaria, moneda, estado FROM `{$db}`.`alq_propiedades` ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $inquilinos = $pdo->query("SELECT id_inquilino, nombre_razon, documento, ruc, telefono, email, whatsapp, consentimiento_whatsapp FROM `{$db}`.`alq_inquilinos` ORDER BY nombre_razon")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        smxAlqJson(['ok' => true, 'propiedades' => $propiedades, 'inquilinos' => $inquilinos]);
    }

    if ($action === 'list') {
        $sql = "
            SELECT
                c.*,
                p.codigo,
                p.nombre,
                p.direccion,
                p.tipo_inmueble,
                i.nombre_razon,
                i.documento,
                i.ruc,
                i.telefono,
                i.email,
                i.whatsapp,
                i.consentimiento_whatsapp
            FROM `{$db}`.`alq_contratos` c
            INNER JOIN `{$db}`.`alq_propiedades` p ON p.id_propiedad = c.id_propiedad
            INNER JOIN `{$db}`.`alq_inquilinos` i ON i.id_inquilino = c.id_inquilino
            ORDER BY c.updated_at DESC, c.id_contrato DESC
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $stored = json_decode((string)($row['clausulas_json'] ?? ''), true);
            $row['clausulas'] = is_array($stored) && $stored ? $stored : smxAlqContractClauses($row);
        }
        unset($row);
        smxAlqJson(['ok' => true, 'rows' => $rows]);
    }

    if ($action === 'save_tenant') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($payload['id_inquilino'] ?? 0);
        $data = [
            'tipo_persona' => trim((string)($payload['tipo_persona'] ?? 'fisica')),
            'nombre_razon' => trim((string)($payload['nombre_razon'] ?? '')),
            'documento' => trim((string)($payload['documento'] ?? '')),
            'ruc' => trim((string)($payload['ruc'] ?? '')),
            'telefono' => trim((string)($payload['telefono'] ?? '')),
            'email' => trim((string)($payload['email'] ?? '')),
            'whatsapp' => trim((string)($payload['whatsapp'] ?? '')),
            'domicilio' => trim((string)($payload['domicilio'] ?? '')),
            'consentimiento_whatsapp' => (int)($payload['consentimiento_whatsapp'] ?? 0) === 1 ? 1 : 0,
            'consentimiento_fecha' => (int)($payload['consentimiento_whatsapp'] ?? 0) === 1 ? date('Y-m-d H:i:s') : null,
            'observacion' => trim((string)($payload['observacion'] ?? '')),
        ];
        if ($data['nombre_razon'] === '') {
            smxAlqJson(['ok' => false, 'error' => 'Nombre o razón social es obligatorio'], 422);
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE `{$db}`.`alq_inquilinos`
                SET tipo_persona=:tipo_persona, nombre_razon=:nombre_razon, documento=:documento, ruc=:ruc,
                    telefono=:telefono, email=:email, whatsapp=:whatsapp, domicilio=:domicilio,
                    consentimiento_whatsapp=:consentimiento_whatsapp,
                    consentimiento_fecha=:consentimiento_fecha, observacion=:observacion
                WHERE id_inquilino=:id
            ");
            $data['id'] = $id;
            $stmt->execute($data);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO `{$db}`.`alq_inquilinos`
                    (tipo_persona, nombre_razon, documento, ruc, telefono, email, whatsapp, domicilio, consentimiento_whatsapp, consentimiento_fecha, observacion)
                VALUES
                    (:tipo_persona, :nombre_razon, :documento, :ruc, :telefono, :email, :whatsapp, :domicilio, :consentimiento_whatsapp, :consentimiento_fecha, :observacion)
            ");
            $stmt->execute($data);
            $id = (int)$pdo->lastInsertId();
        }

        smxAlqJson(['ok' => true, 'id_inquilino' => $id]);
    }

    if ($action === 'save_contract') {
        smxAlqRequirePost();
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($payload['id_contrato'] ?? 0);
        $clauses = $payload['clausulas'] ?? [];
        if (!is_array($clauses) || !$clauses) {
            $clauses = smxAlqContractClauses($payload);
        }

        $data = [
            'numero_contrato' => trim((string)($payload['numero_contrato'] ?? '')),
            'id_propiedad' => (int)($payload['id_propiedad'] ?? 0),
            'id_inquilino' => (int)($payload['id_inquilino'] ?? 0),
            'fecha_firma' => trim((string)($payload['fecha_firma'] ?? '')),
            'fecha_inicio' => trim((string)($payload['fecha_inicio'] ?? '')),
            'fecha_fin' => trim((string)($payload['fecha_fin'] ?? '')),
            'destino_inmueble' => trim((string)($payload['destino_inmueble'] ?? 'vivienda')),
            'moneda' => strtoupper(trim((string)($payload['moneda'] ?? 'PYG'))),
            'canon_mensual' => (float)($payload['canon_mensual'] ?? 0),
            'expensas_mensuales' => (float)($payload['expensas_mensuales'] ?? 0),
            'servicios_mensuales' => (float)($payload['servicios_mensuales'] ?? 0),
            'deposito_garantia' => (float)($payload['deposito_garantia'] ?? 0),
            'dia_vencimiento' => max(1, min(28, (int)($payload['dia_vencimiento'] ?? 10))),
            'mora_fija' => (float)($payload['mora_fija'] ?? 0),
            'incremento_tipo' => trim((string)($payload['incremento_tipo'] ?? 'manual')),
            'incremento_valor' => (float)($payload['incremento_valor'] ?? 0),
            'ajuste_observacion' => trim((string)($payload['ajuste_observacion'] ?? '')),
            'firmado_en' => trim((string)($payload['firmado_en'] ?? 'Asunción')),
            'estado' => trim((string)($payload['estado'] ?? 'activo')),
            'clausulas_json' => json_encode($clauses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'observacion' => trim((string)($payload['observacion'] ?? '')),
            'created_by' => (int)($_SESSION['id_login'] ?? 0),
        ];
        if ($data['numero_contrato'] === '' || $data['id_propiedad'] <= 0 || $data['id_inquilino'] <= 0) {
            smxAlqJson(['ok' => false, 'error' => 'Número de contrato, propiedad e inquilino son obligatorios'], 422);
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE `{$db}`.`alq_contratos`
                SET numero_contrato=:numero_contrato, id_propiedad=:id_propiedad, id_inquilino=:id_inquilino,
                    fecha_firma=:fecha_firma, fecha_inicio=:fecha_inicio, fecha_fin=:fecha_fin, destino_inmueble=:destino_inmueble,
                    moneda=:moneda, canon_mensual=:canon_mensual, expensas_mensuales=:expensas_mensuales,
                    servicios_mensuales=:servicios_mensuales, deposito_garantia=:deposito_garantia, dia_vencimiento=:dia_vencimiento,
                    mora_fija=:mora_fija, incremento_tipo=:incremento_tipo, incremento_valor=:incremento_valor,
                    ajuste_observacion=:ajuste_observacion, firmado_en=:firmado_en, estado=:estado,
                    clausulas_json=:clausulas_json, observacion=:observacion
                WHERE id_contrato=:id
            ");
            $data['id'] = $id;
            unset($data['created_by']);
            $stmt->execute($data);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO `{$db}`.`alq_contratos`
                    (numero_contrato, id_propiedad, id_inquilino, fecha_firma, fecha_inicio, fecha_fin, destino_inmueble, moneda,
                     canon_mensual, expensas_mensuales, servicios_mensuales, deposito_garantia, dia_vencimiento, mora_fija,
                     incremento_tipo, incremento_valor, ajuste_observacion, firmado_en, estado, clausulas_json, observacion, created_by)
                VALUES
                    (:numero_contrato, :id_propiedad, :id_inquilino, :fecha_firma, :fecha_inicio, :fecha_fin, :destino_inmueble, :moneda,
                     :canon_mensual, :expensas_mensuales, :servicios_mensuales, :deposito_garantia, :dia_vencimiento, :mora_fija,
                     :incremento_tipo, :incremento_valor, :ajuste_observacion, :firmado_en, :estado, :clausulas_json, :observacion, :created_by)
            ");
            $stmt->execute($data);
            $id = (int)$pdo->lastInsertId();
        }

        smxAlqJson(['ok' => true, 'id_contrato' => $id]);
    }

    smxAlqJson(['ok' => false, 'error' => 'Acción no soportada'], 400);
} catch (Throwable $e) {
    smxAlqJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
