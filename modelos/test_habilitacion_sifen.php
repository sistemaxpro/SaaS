<?php

/**
 * Script de prueba para verificar configuración de habilitacion_sifen
 */

if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}

$masterDb = defined('MASTER_DB') ? MASTER_DB : ($_SESSION['dbu'] ?? 'serproc1');
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

$id_empresa = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : 169;

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $pdo->exec("SET NAMES utf8");

    echo "<h2>Verificación de Configuración SIFEN - Empresa ID: {$id_empresa}</h2>";

    // 1. Verificar datos de empresa
    echo "<h3>1. Datos de Empresa</h3>";
    $stmt = $pdo->prepare("SELECT * FROM $masterDb.empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($empresa) {
        echo "<pre>";
        echo "ID: {$empresa['id_empresa']}\n";
        echo "Empresa: {$empresa['empresa']}\n";
        echo "RUC: {$empresa['ruc']}-{$empresa['dv']}\n";
        echo "Database: {$empresa['dbase']}\n";
        echo "</pre>";
    } else {
        echo "<p style='color:red'>❌ Empresa no encontrada</p>";
        exit;
    }

    // 2. Verificar habilitacion_sifen
    echo "<h3>2. Habilitación SIFEN</h3>";
    $stmtHab = $pdo->prepare("SELECT * FROM $masterDb.habilitacion_sifen WHERE id_empresa = :id AND activo = 1");
    $stmtHab->execute([':id' => $id_empresa]);
    $habilitaciones = $stmtHab->fetchAll(PDO::FETCH_ASSOC);

    if (empty($habilitaciones)) {
        echo "<p style='color:red'>❌ No hay habilitación SIFEN activa para esta empresa</p>";
        echo "<p>Por favor configure en la tabla <code>" . MASTER_DB . ".habilitacion_sifen</code></p>";
        exit;
    }

    foreach ($habilitaciones as $hab) {
        echo "<div style='border:1px solid #ccc; padding:10px; margin:10px 0'>";
        echo "<pre>";
        echo "✅ ID Habilitación: {$hab['id']}\n";
        echo "RUC: {$hab['ruc']}-{$hab['dv']}\n";
        echo "Razón Social: {$hab['razon_social']}\n";
        echo "Ambiente: {$hab['ambiente']}\n";
        echo "Timbrado: {$hab['numero_timbrado']}\n";
        echo "Fecha Vigencia: {$hab['fecha_inicio_vigencia']} - {$hab['fecha_fin_vigencia']}\n";
        echo "Certificado: {$hab['cert_nombre']}\n";
        echo "Cert Path: {$hab['cert_path']}\n";
        echo "CSC: " . (empty($hab['csc']) ? '❌ NO CONFIGURADO' : '✅ Configurado') . "\n";
        echo "ID CSC: {$hab['id_csc']}\n";
        echo "</pre>";

        // Verificar que el certificado existe
        if (!empty($hab['cert_nombre']) && !empty($hab['cert_path'])) {
            $certPath = rtrim($hab['cert_path'], '/') . '/' . $hab['cert_nombre'];
            if (file_exists($certPath)) {
                echo "<p style='color:green'>✅ Certificado encontrado: {$certPath}</p>";
                echo "<p>Tamaño: " . filesize($certPath) . " bytes</p>";
            } else {
                echo "<p style='color:red'>❌ Certificado NO encontrado: {$certPath}</p>";
            }
        } else {
            echo "<p style='color:orange'>⚠️ cert_nombre o cert_path no configurado</p>";
        }

        // 3. Verificar actividades económicas
        echo "<h4>Actividades Económicas</h4>";
        $stmtAct = $pdo->prepare("SELECT a.* 
            FROM $masterDb.habilitacion_sifen_actividades a
            INNER JOIN $masterDb.habilitacion_sifen h ON a.id_habilitacion = h.id
            WHERE h.id_empresa = :id_empresa AND h.activo = 1 
            ORDER BY a.principal DESC");
        $stmtAct->execute([':id_empresa' => $id_empresa]);
        $actividades = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

        if (empty($actividades)) {
            echo "<p style='color:red'>❌ No hay actividades económicas configuradas</p>";
        } else {
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>Principal</th><th>Código</th><th>Descripción</th></tr>";
            foreach ($actividades as $act) {
                $principal = $act['principal'] == 1 ? '✅ SI' : 'No';
                $style = $act['principal'] == 1 ? 'background:#dfd; font-weight:bold' : '';
                echo "<tr style='{$style}'><td>{$principal}</td><td>{$act['codigo']}</td><td>{$act['descripcion']}</td></tr>";
            }
            echo "</table>";

            // Verificar actividad principal
            $actPrincipal = array_filter($actividades, function ($a) {
                return $a['principal'] == 1;
            });
            if (empty($actPrincipal)) {
                echo "<p style='color:red'>❌ No hay actividad económica marcada como principal</p>";
            } else {
                echo "<p style='color:green'>✅ Actividad principal configurada</p>";
            }
        }

        // 4. Verificar documentos habilitados
        echo "<h4>Documentos Habilitados</h4>";
        $stmtDoc = $pdo->prepare("SELECT * FROM $masterDb.habilitacion_sifen_documentos WHERE id_habilitacion = :id AND activo = 1");
        $stmtDoc->execute([':id' => $hab['id']]);
        $docs = $stmtDoc->fetchAll(PDO::FETCH_ASSOC);

        if (empty($docs)) {
            echo "<p style='color:orange'>⚠️ No hay documentos habilitados</p>";
        } else {
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>Tipo</th><th>Establecimiento</th><th>Punto Exp.</th><th>Descripción</th></tr>";
            foreach ($docs as $doc) {
                $tipoDesc = '';
                switch ($doc['tipo_documento']) {
                    case 1:
                        $tipoDesc = 'Factura Electrónica';
                        break;
                    case 4:
                        $tipoDesc = 'Autofactura Electrónica';
                        break;
                    case 5:
                        $tipoDesc = 'Nota de Crédito Electrónica';
                        break;
                    case 6:
                        $tipoDesc = 'Nota de Débito Electrónica';
                        break;
                    case 7:
                        $tipoDesc = 'Nota de Remisión Electrónica';
                        break;
                }
                $est = str_pad($doc['codigo_establecimiento'], 3, '0', STR_PAD_LEFT);
                $pto = str_pad($doc['punto_expedicion'], 3, '0', STR_PAD_LEFT);
                echo "<tr><td>{$doc['tipo_documento']}</td><td>{$est}</td><td>{$pto}</td><td>{$tipoDesc}</td></tr>";
            }
            echo "</table>";

            // Verificar NR específicamente (tipo 7)
            $nrDocs = array_filter($docs, function ($d) {
                return $d['tipo_documento'] == 7;
            });
            if (empty($nrDocs)) {
                echo "<p style='color:red'>❌ No hay Notas de Remisión (tipo 7) habilitadas</p>";
            } else {
                echo "<p style='color:green'>✅ Notas de Remisión habilitadas: " . count($nrDocs) . "</p>";
            }
        }

        echo "</div>";
    }

    // 4. Test de carga de configuración (como lo hace nr_sifen_api.php)
    echo "<h3>3. Test de Configuración Final</h3>";
    $habilitacion = $habilitaciones[0];

    $ambienteRaw = $habilitacion['ambiente'] ?? 'TEST';
    $ambienteCodigo = (strtoupper($ambienteRaw) === 'PROD' || $ambienteRaw === '1') ? '1' : '2';

    // Obtener actividad económica principal
    $stmtAct = $pdo->prepare("SELECT a.codigo, a.descripcion 
        FROM $masterDb.habilitacion_sifen_actividades a
        INNER JOIN $masterDb.habilitacion_sifen h ON a.id_habilitacion = h.id
        WHERE h.id_empresa = :id_empresa AND h.activo = 1 AND a.principal = 1 
        LIMIT 1");
    $stmtAct->execute([':id_empresa' => $id_empresa]);
    $actividad = $stmtAct->fetch(PDO::FETCH_ASSOC);

    $config = [
        'eruc' => $habilitacion['ruc'],
        'edv' => $habilitacion['dv'],
        'erazon_social' => $habilitacion['razon_social'],
        'ambiente_sifen' => $ambienteCodigo,
        'cert_nombre' => $habilitacion['cert_nombre'] ?? '',
        'cert_path' => $habilitacion['cert_path'] ?? '',
        'cert_pass' => $habilitacion['cert_pass'] ?? '',
        'csc' => $habilitacion['csc'] ?? '',
        'id_csc' => $habilitacion['id_csc'] ?? '1',
        'timbrado' => $habilitacion['numero_timbrado'] ?? '',
        'fecha_timbrado' => $habilitacion['fecha_inicio_vigencia'] ?? date('Y-m-d'),
        'cod_act' => $actividad['codigo'] ?? '',
        'des_act' => $actividad['descripcion'] ?? '',
    ];

    echo "<pre>";
    echo "Config que usará nr_sifen_api.php:\n";
    echo "RUC: {$config['eruc']}-{$config['edv']}\n";
    echo "Ambiente: {$config['ambiente_sifen']} (" . ($config['ambiente_sifen'] == '1' ? 'PRODUCCIÓN' : 'TEST') . ")\n";
    echo "Timbrado: {$config['timbrado']}\n";
    echo "CSC: " . (empty($config['csc']) ? '❌ NO' : '✅ SI') . "\n";
    echo "Certificado: {$config['cert_nombre']}\n";
    echo "Actividad Económica: {$config['cod_act']} - {$config['des_act']}\n";

    if (!empty($config['cert_nombre']) && !empty($config['cert_path'])) {
        $certPath = rtrim($config['cert_path'], '/') . '/' . $config['cert_nombre'];
        echo "\n";
        if (file_exists($certPath)) {
            echo "✅ CERTIFICADO OK: {$certPath}\n";
        } else {
            echo "❌ CERTIFICADO NO ENCONTRADO: {$certPath}\n";
        }
    }
    echo "</pre>";

    // Validaciones finales
    echo "<h3>4. Validaciones</h3>";
    $errores = [];
    $warnings = [];

    if (empty($config['timbrado'])) {
        $errores[] = "Timbrado no configurado";
    }
    if (empty($config['csc'])) {
        $errores[] = "CSC no configurado";
    }
    if (empty($config['cert_nombre'])) {
        $errores[] = "cert_nombre no configurado";
    }
    if (empty($config['cert_path'])) {
        $errores[] = "cert_path no configurado";
    }
    if (empty($config['cert_pass'])) {
        $errores[] = "cert_pass no configurado";
    }
    if (empty($config['cod_act'])) {
        $warnings[] = "Actividad económica principal no configurada en habilitacion_sifen_actividades";
    }

    if (!empty($errores)) {
        echo "<div style='background:#fee; padding:10px; border:1px solid red'>";
        echo "<h4 style='color:red'>❌ Errores Críticos:</h4><ul>";
        foreach ($errores as $e) {
            echo "<li>{$e}</li>";
        }
        echo "</ul></div>";
    }

    if (!empty($warnings)) {
        echo "<div style='background:#ffc; padding:10px; border:1px solid orange'>";
        echo "<h4 style='color:orange'>⚠️ Advertencias:</h4><ul>";
        foreach ($warnings as $w) {
            echo "<li>{$w}</li>";
        }
        echo "</ul></div>";
    }

    if (empty($errores) && empty($warnings)) {
        echo "<div style='background:#dfd; padding:20px; border:1px solid green; text-align:center'>";
        echo "<h2 style='color:green'>✅ Configuración Correcta</h2>";
        echo "<p>La empresa está lista para emitir documentos electrónicos</p>";
        echo "</div>";
    }
} catch (Exception $e) {
    echo "<div style='background:#fee; padding:10px; border:1px solid red'>";
    echo "<h3>Error:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "</div>";
}
?>

<style>
    body {
        font-family: Arial, sans-serif;
        margin: 20px;
    }

    h2 {
        color: #333;
        border-bottom: 2px solid #333;
        padding-bottom: 10px;
    }

    h3 {
        color: #555;
        margin-top: 30px;
    }

    pre {
        background: #f5f5f5;
        padding: 10px;
        border-radius: 5px;
    }

    table {
        border-collapse: collapse;
        margin: 10px 0;
    }

    th {
        background: #333;
        color: white;
    }
</style>