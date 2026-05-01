<?php

/**
 * Script de prueba para verificar detección de balanza en smx_1039
 */

// Simular el flujo de interpretar_codigo
$codigo = isset($_GET['codigo']) ? trim($_GET['codigo']) : '2000037000958';
$id_empresa = 1039;

echo "<h2>Test de Balanza - Empresa 1039 (smx_1039)</h2>";
echo "<p><strong>Código a probar:</strong> $codigo</p>";
echo "<p><strong>ID Empresa:</strong> $id_empresa</p>";
echo "<hr>";

// Conectar a la base de datos
$dbHost = '45.160.33.98';
$dbUser = 'sistemax';
$dbPass = 'Armagedon123';
$dbName = 'smx_1040';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<h3>✓ Conexión exitosa a $dbName</h3>";

    // Buscar configuración de balanza
    $sql = "
    SELECT *
    FROM balanzas
    WHERE id_empresa = :id_empresa
      AND activo = 1
      AND prefijo IS NOT NULL
      AND prefijo <> ''
      AND :codigo LIKE CONCAT(prefijo, '%')
    ORDER BY LENGTH(prefijo) DESC
    LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_empresa' => $id_empresa, ':codigo' => $codigo_limpio]);
    $balanza = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($balanza) {
        echo "<h3>✓ Configuración de balanza encontrada:</h3>";
        echo "<pre>" . print_r($balanza, true) . "</pre>";

        // Validar longitud
        $longitud_codigo = (int)$balanza['longitud_codigo'];
        if ($longitud_codigo > 0 && strlen($codigo_limpio) !== $longitud_codigo) {
            echo "<p style='color:red;'>✗ Error: Longitud incorrecta. Esperado: $longitud_codigo, Recibido: " . strlen($codigo_limpio) . "</p>";
        } else {
            echo "<p style='color:green;'>✓ Longitud correcta</p>";

            // Extraer datos
            $posProd = (int)$balanza['pos_inicio_producto'];
            $lenProd = (int)$balanza['largo_producto'];
            $posVal = (int)$balanza['pos_inicio_valor'];
            $lenVal = (int)$balanza['largo_valor'];
            $divisor = (float)$balanza['divisor_valor'];

            $startProd = max(0, $posProd - 1);
            $startVal = max(0, $posVal - 1);

            $rawProd = substr($codigo_limpio, $startProd, $lenProd);
            $rawVal = substr($codigo_limpio, $startVal, $lenVal);

            echo "<p><strong>Producto extraído:</strong> $rawProd</p>";
            echo "<p><strong>Valor extraído:</strong> $rawVal</p>";

            $idproducto = ltrim($rawProd, '0');
            if ($idproducto === '') $idproducto = '0';

            // Buscar en código_barra
            $stmt2 = $pdo->prepare("SELECT id_producto FROM codigo_barra WHERE codigo_barra = :codigo LIMIT 1");
            $stmt2->execute([':codigo' => $idproducto]);
            $cb = $stmt2->fetch(PDO::FETCH_ASSOC);

            if ($cb) {
                $idproducto = $cb['id_producto'];
                echo "<p style='color:green;'>✓ Producto encontrado en código_barra: $idproducto</p>";
            } else {
                echo "<p style='color:orange;'>⚠ Producto no encontrado en código_barra, usando código directo: $idproducto</p>";
            }

            // Calcular valor
            $valorNum = (float)preg_replace('/\D+/', '', $rawVal);
            if ($divisor <= 0) $divisor = 1;
            $valor = $valorNum / $divisor;

            echo "<p><strong>Valor calculado:</strong> $valor " . ($balanza['modo'] === 'peso' ? 'kg' : 'unidades') . "</p>";

            // Buscar producto
            $stmt3 = $pdo->prepare("SELECT descripcion FROM tblproductos WHERE idproducto = :id LIMIT 1");
            $stmt3->execute([':id' => $idproducto]);
            $prod = $stmt3->fetch(PDO::FETCH_ASSOC);

            if ($prod) {
                echo "<p><strong>Descripción:</strong> " . htmlspecialchars($prod['descripcion']) . "</p>";
            } else {
                echo "<p style='color:orange;'>⚠ Producto no encontrado en tblproductos</p>";
            }

            echo "<hr>";
            echo "<h3>✓ Resultado JSON (como lo devolvería la API):</h3>";
            $resultado = [
                "es_balanza" => true,
                "idproducto" => $idproducto,
                "descripcion" => $prod['descripcion'] ?? '',
                "modo" => $balanza['modo'],
                "valor" => $valor,
                "raw_producto" => $rawProd,
                "raw_valor" => $rawVal,
                "codigo" => $codigo_limpio,
                "prefijo" => $balanza['prefijo'],
                "nombre_balanza" => $balanza['nombre_modelo']
            ];
            echo "<pre>" . json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        }
    } else {
        echo "<p style='color:red;'>✗ No se encontró configuración de balanza para este código</p>";
        echo "<p>Verificar:</p>";
        echo "<ul>";
        echo "<li>¿El prefijo '$codigo_limpio[0]' está registrado?</li>";
        echo "<li>¿La balanza está activa?</li>";
        echo "<li>¿El id_empresa es correcto ($id_empresa)?</li>";
        echo "</ul>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h3>Probar con otro código:</h3>";
echo '<form method="get">';
echo '<input type="text" name="codigo" placeholder="Código de balanza (ej: 2001234005678)" value="' . htmlspecialchars($codigo) . '" size="30">';
echo '<button type="submit">Probar</button>';
echo '</form>';
