<?php
require_once __DIR__ . '/config/bootstrap.php';

$_SESSION['id_empresa'] = 169;
$_SESSION['usr_priv_admin'] = 'Y';
$_SESSION['usr_id'] = 1;

$pdo = Database::getEmpresaConnection(169);

// Verificar si ya existe una estación de prueba
$stmt = $pdo->query("SELECT COUNT(*) FROM estacion_surtidores");
$existentes = (int)$stmt->fetchColumn();

if ($existentes > 0) {
    echo "✓ Estación de prueba ya configurada con " . $existentes . " surtidores\n";
} else {
    echo "Configurando Estación de Prueba...\n";

    // 1. Crear 2 tanques
    $tanques = [
        ['nombre' => 'Tanque Nafta', 'id_combustible' => 1, 'capacidad' => 5000],
        ['nombre' => 'Tanque Diésel', 'id_combustible' => 4, 'capacidad' => 3000],
    ];

    $tanque_ids = [];
    foreach ($tanques as $tanque) {
        $stmt = $pdo->prepare("INSERT INTO estacion_tanques (nombre, id_combustible, capacidad_litros, tipo_medicion, activo) VALUES (?, ?, ?, 'manual', 'Y')");
        $stmt->execute([$tanque['nombre'], $tanque['id_combustible'], $tanque['capacidad']]);
        $tanque_ids[] = $pdo->lastInsertId();
    }
    echo "  ✓ 2 tanques creados\n";

    // 2. Crear 3 surtidores
    $surtidores = [];
    for ($i = 1; $i <= 3; $i++) {
        $stmt = $pdo->prepare("INSERT INTO estacion_surtidores (nombre, nro_surtidor, tipo_control, activo) VALUES (?, ?, 'manual', 'Y')");
        $stmt->execute(["Surtidor " . $i, $i]);
        $surtidores[] = $pdo->lastInsertId();
    }
    echo "  ✓ 3 surtidores creados\n";

    // 3. Crear 6 picos (2 por surtidor)
    $pico_num = 1;
    $combustibles_por_surtidor = [1, 4]; // Nafta, Diésel

    foreach ($surtidores as $surtidor_idx => $surtidor_id) {
        foreach ($combustibles_por_surtidor as $combustible_idx => $combustible_id) {
            $tanque_id = $tanque_ids[$combustible_idx];
            $stmt = $pdo->prepare("INSERT INTO estacion_picos (id_surtidor, nro_pico, nombre, id_combustible, id_tanque, totalizador_actual, activo)
                                  VALUES (?, ?, ?, ?, ?, 0, 'Y')");
            $stmt->execute([$surtidor_id, $pico_num, "Pico $pico_num", $combustible_id, $tanque_id]);
            $pico_num++;
        }
    }
    echo "  ✓ 6 picos creados (2 por surtidor)\n";

    // 4. Registrar lecturas iniciales de tanques
    foreach ($tanque_ids as $tanque_id) {
        $stmt = $pdo->prepare("INSERT INTO estacion_lecturas_tanque (id_tanque, fecha_hora, litros_medidos, tipo, registrado_por)
                              VALUES (?, NOW(), 2500, 'apertura', 1)");
        $stmt->execute([$tanque_id]);
    }
    echo "  ✓ Lecturas iniciales de tanques registradas\n";

    echo "\n✅ Estación de Prueba Lista\n";
}

// Mostrar configuración
echo "\n" . str_repeat("═", 60) . "\n";
echo "CONFIGURACIÓN ACTUAL\n";
echo str_repeat("═", 60) . "\n";

$stmt = $pdo->query("SELECT id, nombre, nro_surtidor FROM estacion_surtidores WHERE activo = 'Y' ORDER BY nro_surtidor");
$surtidores = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nSurtidores: " . count($surtidores) . "\n";
foreach ($surtidores as $s) {
    echo "  - Surtidor " . $s['nro_surtidor'] . " (ID: " . $s['id'] . ")\n";
}

$stmt = $pdo->query("SELECT id, nombre, capacidad_litros FROM estacion_tanques WHERE activo = 'Y' ORDER BY id");
$tanques = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nTanques: " . count($tanques) . "\n";
foreach ($tanques as $t) {
    echo "  - " . $t['nombre'] . " (" . $t['capacidad_litros'] . "L)\n";
}

$stmt = $pdo->query("SELECT COUNT(*) FROM estacion_picos WHERE activo = 'Y'");
$picos = (int)$stmt->fetchColumn();
echo "\nPicos: " . $picos . "\n";

echo "\n" . str_repeat("═", 60) . "\n";
echo "MÓDULOS DISPONIBLES:\n";
echo "  1. Dashboard: /estacion/\n";
echo "  2. Surtidores: /estacion-surtidores/\n";
echo "  3. Tanques: /estacion-tanques/\n";
echo "  4. Turnos: /estacion-turnos/\n";
echo "  5. Despachos: /estacion-despachos/\n";
echo "  6. Cierres: /estacion-cierres/\n";
echo str_repeat("═", 60) . "\n";
