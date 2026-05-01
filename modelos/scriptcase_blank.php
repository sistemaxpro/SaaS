<?php
date_default_timezone_set('America/Montevideo');

// Cargar variables desde .env si existe
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            putenv(trim($name) . '=' . trim($value));
        }
    }
}

// Función para crear/verificar tabla SQLite
function createFacturaVentasTable()
{
    try {
        $db = new SQLite3('factura_ventas.db');
        $db->exec('
            CREATE TABLE IF NOT EXISTS factura_ventas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                numero_lote TEXT,
                estado_fe TEXT,
                cdc TEXT,
                fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
        return $db;
    } catch (Exception $e) {
        return null;
    }
}

// Función para crear/verificar tabla de clientes
function createClientesTable()
{
    try {
        $db = new SQLite3('clientes.db');
        $db->exec('
            CREATE TABLE IF NOT EXISTS clientes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nombre TEXT NOT NULL,
                ruc TEXT,
                direccion TEXT,
                telefono TEXT,
                email TEXT
            )
        ');

        // Verificar si ya hay datos
        $result = $db->query('SELECT COUNT(*) as count FROM clientes');
        $row = $result->fetchArray();

        // Si no hay datos, insertar clientes de ejemplo
        if ($row['count'] == 0) {
            $clientes_ejemplo = [
                ['Juan Pérez', '12345678-9', 'Av. España 123, Asunción', '0981123456', 'juan.perez@email.com'],
                ['María González', '98765432-1', 'Calle Palma 456, Asunción', '0982234567', 'maria.gonzalez@email.com'],
                ['Carlos Rodríguez', '11223344-5', 'Av. Mariscal López 789, Asunción', '0983345678', 'carlos.rodriguez@email.com'],
                ['Ana Martínez', '55667788-9', 'Calle Independencia 321, Asunción', '0984456789', 'ana.martinez@email.com'],
                ['Luis Fernández', '99887766-3', 'Av. Eusebio Ayala 654, Asunción', '0985567890', 'luis.fernandez@email.com'],
                ['Carmen Silva', '44556677-8', 'Calle Cerro Corá 987, Asunción', '0986678901', 'carmen.silva@email.com'],
                ['Roberto Benítez', '33445566-2', 'Av. Artigas 147, Asunción', '0987789012', 'roberto.benitez@email.com'],
                ['Patricia López', '22334455-6', 'Calle Yegros 258, Asunción', '0988890123', 'patricia.lopez@email.com']
            ];

            $stmt = $db->prepare('INSERT INTO clientes (nombre, ruc, direccion, telefono, email) VALUES (?, ?, ?, ?, ?)');
            foreach ($clientes_ejemplo as $cliente) {
                $stmt->bindValue(1, $cliente[0], SQLITE3_TEXT);
                $stmt->bindValue(2, $cliente[1], SQLITE3_TEXT);
                $stmt->bindValue(3, $cliente[2], SQLITE3_TEXT);
                $stmt->bindValue(4, $cliente[3], SQLITE3_TEXT);
                $stmt->bindValue(5, $cliente[4], SQLITE3_TEXT);
                $stmt->execute();
            }
        }

        return $db;
    } catch (Exception $e) {
        return null;
    }
}

// Función para buscar clientes
function buscarClientes($termino)
{
    try {
        $db = new SQLite3('clientes.db');
        $stmt = $db->prepare('SELECT * FROM clientes WHERE nombre LIKE ? OR ruc LIKE ? LIMIT 10');
        $termino_busqueda = '%' . $termino . '%';
        $stmt->bindValue(1, $termino_busqueda, SQLITE3_TEXT);
        $stmt->bindValue(2, $termino_busqueda, SQLITE3_TEXT);

        $result = $stmt->execute();
        $clientes = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $clientes[] = $row;
        }

        $db->close();
        return $clientes;
    } catch (Exception $e) {
        return [];
    }
}

// Inicializar tabla de clientes
createClientesTable();

// Manejar búsqueda AJAX de clientes
if ($_GET && isset($_GET['action']) && $_GET['action'] === 'buscar_clientes') {
    $termino = $_GET['termino'] ?? '';
    $clientes = buscarClientes($termino);

    header('Content-Type: application/json');
    echo json_encode($clientes);
    exit;
}

// Procesar el formulario si se envía
if ($_POST && isset($_POST['action'])) {
    if ($_POST['action'] === 'send_sifen') {
        // Parametrizar modo de envío
        $modo_envio = getenv('SIFEN_MODO') ?: 'test'; // test o produccion
        $url_sifen = ($modo_envio === 'produccion')
            ? 'https://sifen.gov.py/ws/endpoint_produccion.php' // Reemplaza por la URL real de producción
            : 'http://localhost:8000/factura_final.php';
        // Procesar múltiples conceptos
        $conceptos = [];
        if (isset($_POST['conceptos']) && is_array($_POST['conceptos'])) {
            foreach ($_POST['conceptos'] as $concepto) {
                if (!empty($concepto['codigo']) && !empty($concepto['descripcion'])) {
                    $conceptos[] = [
                        "codigo" => $concepto['codigo'],
                        "descripcion" => $concepto['descripcion'],
                        "precio" => (int)($concepto['precio'] ?? 0),
                        "cantidad" => (int)($concepto['cantidad'] ?? 1),
                        "tasa_iva" => (int)($concepto['tasa_iva'] ?? 10)
                    ];
                }
            }
        }

        // Generar JSON automáticamente con datos del emisor por defecto
        $json_data = [
            "emisor" => [
                "ruc" => "80062286",
                "dv" => "3",
                "razon_social" => "EMPRESA SA",
                "telefono" => "0981123456",
                "email" => "empresa@email.com",
                "direccion" => "Dirección 123"
            ],
            "receptor" => [
                "documento" => $_POST['receptor_documento'] ?? '',
                "tipo_doc" => $_POST['receptor_tipo_doc'] ?? 'ci',
                "razon_social" => $_POST['receptor_razon_social'] ?? '',
                "telefono" => $_POST['receptor_telefono'] ?? '',
                "email" => $_POST['receptor_email'] ?? ''
            ],
            "conceptos" => $conceptos,
            "factura" => [
                "ndoc" => $_POST['factura_ndoc'] ?? '',
                "condicion" => $_POST['factura_condicion'] ?? 'contado',
                "timbrado" => "18556965",
                "fec_timbrado" => "2025-04-28"
            ]
        ];

        // Configurar cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_sifen);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($json_data))
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        echo "<div class='result-section'>";
        echo "<h3>Respuesta del Servidor:</h3>";
        echo "<div style='margin-bottom:10px;padding:8px 12px;background:#f1f5f9;border-radius:6px;color:#334155;font-size:1rem;'>";
        echo "<strong>Modo de envío:</strong> " . strtoupper($modo_envio) . "<br>";
        echo "<strong>URL SIFEN:</strong> " . htmlspecialchars($url_sifen) . "</div>";

        if ($curl_error) {
            echo "<div class='error'>Error cURL: " . htmlspecialchars($curl_error) . "</div>";
        } else {
            echo "<p><strong>Código HTTP:</strong> " . $http_code . "</p>";
            echo "<pre class='response-output'>" . htmlspecialchars($response) . "</pre>";
            // Mostrar mensaje de rechazo SIFEN con timbrado si corresponde
            if (strpos($response, '1104') !== false && strpos($response, 'no se encuentra en estado ACTIVO') !== false) {
                echo "<div class='error' style='background:#ffeaea;color:#b91c1c;padding:15px;border-radius:10px;margin-top:15px;border:1px solid #fca5a5;'>";
                echo "<strong>Motivo de Rechazo SIFEN</strong><br>";
                echo "Mensaje del Sistema:<br>";
                echo "[Código: 1104] El número de timbrado informado (<b>18556965</b>) no se encuentra en estado ACTIVO";
                echo "</div>";
            }

            // Procesar respuesta si es exitosa
            if ($http_code == 200 && $response) {
                $response_data = json_decode($response, true);

                if ($response_data && isset($response_data['numero_lote'])) {
                    $numero_lote = $response_data['numero_lote'];
                    $cdc = $response_data['cdc'] ?? '';

                    // Guardar en base de datos
                    $db = createFacturaVentasTable();
                    if ($db) {
                        $stmt = $db->prepare('INSERT INTO factura_ventas (numero_lote, estado_fe, cdc) VALUES (?, ?, ?)');
                        $stmt->bindValue(1, $numero_lote, SQLITE3_TEXT);
                        $stmt->bindValue(2, 'ENVIADO', SQLITE3_TEXT);
                        $stmt->bindValue(3, $cdc, SQLITE3_TEXT);

                        if ($stmt->execute()) {
                            echo "<div style='background: #dcfce7; color: #166534; padding: 15px; border-radius: 10px; margin-top: 15px; border: 1px solid #bbf7d0;'>";
                            echo "<h4><i class='fas fa-check-circle'></i> ¡Factura enviada exitosamente!</h4>";
                            echo "<p><strong>Número de Lote:</strong> " . htmlspecialchars($numero_lote) . "</p>";
                            echo "<p><strong>Estado:</strong> ENVIADO</p>";
                            if ($cdc) {
                                echo "<p><strong>CDC:</strong> " . htmlspecialchars($cdc) . "</p>";
                            }
                            echo "<p><strong>Fecha:</strong> " . date('Y-m-d H:i:s') . "</p>";
                            echo "</div>";
                        } else {
                            echo "<div class='error'>Error al guardar en base de datos</div>";
                        }
                        $db->close();
                    } else {
                        echo "<div class='error'>Error al conectar con la base de datos</div>";
                    }
                } else {
                    echo "<div class='error'>Respuesta del servidor no contiene número de lote válido</div>";
                }
            }
        }
        echo "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIFEN - Generador de Facturas Electrónicas</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .action-buttons {
            background: #f1f5f9;
            padding: 25px 40px;
            border-bottom: 1px solid #e2e8f0;
        }

        .form-container {
            padding: 40px;
        }

        .form-section {
            margin-bottom: 30px;
            background: #f8fafc;
            border-radius: 15px;
            padding: 25px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .form-section:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .section-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            color: #1e293b;
        }

        .section-header i {
            font-size: 1.5rem;
            margin-right: 12px;
            color: #4f46e5;
        }

        .section-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .required {
            color: #ef4444;
        }

        .conceptos-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 2px dashed #d1d5db;
        }

        .concepto-item {
            background: #f1f5f9;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid #e2e8f0;
            position: relative;
        }

        .concepto-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .concepto-number {
            background: #4f46e5;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .btn-remove {
            background: #ef4444;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .btn-remove:hover {
            background: #dc2626;
            transform: scale(1.05);
        }

        .btn-add {
            background: #10b981;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            margin-top: 15px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-add:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        .search-container {
            position: relative;
            margin-bottom: 20px;
        }

        .search-input {
            width: 100%;
            padding: 12px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .search-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .search-item:hover {
            background-color: #f8fafc;
        }

        .search-item:last-child {
            border-bottom: none;
        }

        /* Estilos para el buscador de clientes */
        .client-search-container {
            position: relative;
            margin-bottom: 25px;
        }

        .client-search-input {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .client-search-input:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
            background: white;
        }

        .client-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            max-height: 350px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            margin-top: 5px;
        }

        .client-search-item {
            padding: 18px 20px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .client-search-item:hover {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            transform: translateX(5px);
        }

        .client-search-item:last-child {
            border-bottom: none;
        }

        .client-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }

        .client-details {
            color: #6b7280;
            font-size: 0.9rem;
            display: flex;
            gap: 15px;
        }

        .client-ruc {
            font-weight: 500;
        }

        .totals-section {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-top: 20px;
        }

        .totals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
        }

        .total-item {
            text-align: center;
        }

        .total-label {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-bottom: 5px;
        }

        .total-value {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .button-group {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(79, 70, 229, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .btn-success:hover {
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        }

        .result-section {
            margin-top: 30px;
            background: #f8fafc;
            border-radius: 15px;
            padding: 25px;
            border: 1px solid #e2e8f0;
        }

        .json-output,
        .response-output {
            background: #1e293b;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 10px;
            overflow-x: auto;
            font-family: 'Fira Code', 'Courier New', monospace;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .error {
            background: #fef2f2;
            color: #dc2626;
            padding: 15px;
            border-radius: 10px;
            border: 1px solid #fecaca;
        }

        @media (max-width: 768px) {
            .container {
                margin: 10px;
                border-radius: 15px;
            }

            .form-container {
                padding: 20px;
            }

            .header h1 {
                font-size: 2rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .button-group {
                flex-direction: column;
                align-items: center;
            }
        }

        .hidden {
            display: none;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-receipt"></i> SIFEN</h1>
            <p>Generador de Facturas Electrónicas Moderno</p>
        </div>

        <div class="action-buttons">
            <form method="POST" id="facturaForm">
                <div class="button-group">
                    <button type="submit" name="action" value="send_sifen" class="btn-primary btn-success">
                        <i class="fas fa-paper-plane"></i> Enviar a SIFEN
                    </button>
                </div>
        </div>

        <div class="form-container">

            <!-- Datos del Receptor -->
            <div class="form-section">
                <div class="section-header">
                    <i class="fas fa-user"></i>
                    <h3>Datos del Cliente</h3>
                </div>

                <!-- Buscador de Clientes -->
                <div class="client-search-container">
                    <div class="form-group">
                        <label for="clientSearch">Buscar Cliente</label>
                        <input type="text" id="clientSearch" class="client-search-input" placeholder="🔍 Buscar por nombre o RUC..." autocomplete="off">
                        <div class="client-search-results" id="clientSearchResults"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="receptor_documento">Documento <span class="required">*</span></label>
                        <input type="text" id="receptor_documento" name="receptor_documento" value="<?= $_POST['receptor_documento'] ?? '5311558' ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="receptor_tipo_doc">Tipo de Documento</label>
                        <select id="receptor_tipo_doc" name="receptor_tipo_doc">
                            <option value="ci" <?= ($_POST['receptor_tipo_doc'] ?? 'ci') === 'ci' ? 'selected' : '' ?>>CI</option>
                            <option value="ruc" <?= ($_POST['receptor_tipo_doc'] ?? '') === 'ruc' ? 'selected' : '' ?>>RUC</option>
                            <option value="pasaporte" <?= ($_POST['receptor_tipo_doc'] ?? '') === 'pasaporte' ? 'selected' : '' ?>>Pasaporte</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="receptor_razon_social">Razón Social <span class="required">*</span></label>
                        <input type="text" id="receptor_razon_social" name="receptor_razon_social" value="<?= $_POST['receptor_razon_social'] ?? 'CLIENTE' ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="receptor_direccion">Dirección</label>
                        <input type="text" id="receptor_direccion" name="receptor_direccion" value="<?= $_POST['receptor_direccion'] ?? '' ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="receptor_telefono">Teléfono</label>
                        <input type="text" id="receptor_telefono" name="receptor_telefono" value="<?= $_POST['receptor_telefono'] ?? '0961268274' ?>">
                    </div>
                    <div class="form-group">
                        <label for="receptor_email">Email</label>
                        <input type="email" id="receptor_email" name="receptor_email" value="<?= $_POST['receptor_email'] ?? 'cliente@email.com' ?>">
                    </div>
                </div>
            </div>

            <!-- Conceptos de Facturación -->
            <div class="form-section">
                <div class="section-header">
                    <i class="fas fa-shopping-cart"></i>
                    <h3>Productos y Servicios</h3>
                </div>

                <div class="search-container">
                    <input type="text" class="search-input" id="productSearch" placeholder="🔍 Buscar productos por código o descripción...">
                    <div class="search-results" id="searchResults"></div>
                </div>

                <div class="conceptos-container" id="conceptosContainer">
                    <div class="concepto-item" data-index="0">
                        <div class="concepto-header">
                            <span class="concepto-number">Producto 1</span>
                            <button type="button" class="btn-remove" onclick="removeConcepto(0)" style="display: none;">
                                <i class="fas fa-trash"></i> Eliminar
                            </button>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Código <span class="required">*</span></label>
                                <input type="text" name="conceptos[0][codigo]" value="001" required>
                            </div>
                            <div class="form-group">
                                <label>Descripción <span class="required">*</span></label>
                                <input type="text" name="conceptos[0][descripcion]" value="Producto/Servicio" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Precio Unitario <span class="required">*</span></label>
                                <input type="number" name="conceptos[0][precio]" value="100000" required onchange="calculateTotals()">
                            </div>
                            <div class="form-group">
                                <label>Cantidad <span class="required">*</span></label>
                                <input type="number" name="conceptos[0][cantidad]" value="1" required onchange="calculateTotals()">
                            </div>
                            <div class="form-group">
                                <label>IVA (%)</label>
                                <select name="conceptos[0][tasa_iva]" onchange="calculateTotals()">
                                    <option value="0">0%</option>
                                    <option value="5">5%</option>
                                    <option value="10" selected>10%</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" class="btn-add" onclick="addConcepto()">
                    <i class="fas fa-plus"></i> Agregar Producto
                </button>

                <div class="totals-section">
                    <div class="totals-grid">
                        <div class="total-item">
                            <div class="total-label">Subtotal</div>
                            <div class="total-value" id="subtotal">₲ 100.000</div>
                        </div>
                        <div class="total-item">
                            <div class="total-label">IVA</div>
                            <div class="total-value" id="totalIva">₲ 10.000</div>
                        </div>
                        <div class="total-item">
                            <div class="total-label">Total</div>
                            <div class="total-value" id="totalGeneral">₲ 110.000</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Datos de la Factura -->
            <div class="form-section">
                <div class="section-header">
                    <i class="fas fa-file-invoice"></i>
                    <h3>Información de la Factura</h3>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="factura_ndoc">Número de Documento <span class="required">*</span></label>
                        <input type="text" id="factura_ndoc" name="factura_ndoc" value="<?= $_POST['factura_ndoc'] ?? '1001' ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="factura_condicion">Condición de Pago</label>
                        <select id="factura_condicion" name="factura_condicion">
                            <option value="contado" <?= ($_POST['factura_condicion'] ?? 'contado') === 'contado' ? 'selected' : '' ?>>Contado</option>
                            <option value="credito" <?= ($_POST['factura_condicion'] ?? '') === 'credito' ? 'selected' : '' ?>>Crédito</option>
                        </select>
                    </div>
                </div>
            </div>

            </form>
        </div>
    </div>

    <script>
        // Base de datos de productos simulada
        const productos = [{
                codigo: '001',
                descripcion: 'Producto/Servicio',
                precio: 100000
            },
            {
                codigo: '002',
                descripcion: 'Consultoría IT',
                precio: 500000
            },
            {
                codigo: '003',
                descripcion: 'Desarrollo Web',
                precio: 800000
            },
            {
                codigo: '004',
                descripcion: 'Mantenimiento Sistema',
                precio: 300000
            },
            {
                codigo: '005',
                descripcion: 'Hosting Anual',
                precio: 150000
            },
            {
                codigo: '006',
                descripcion: 'Licencia Software',
                precio: 250000
            },
            {
                codigo: '007',
                descripcion: 'Soporte Técnico',
                precio: 200000
            },
            {
                codigo: '008',
                descripcion: 'Capacitación',
                precio: 400000
            },
            {
                codigo: '009',
                descripcion: 'Backup Cloud',
                precio: 120000
            },
            {
                codigo: '010',
                descripcion: 'Seguridad Informática',
                precio: 600000
            }
        ];

        let conceptoIndex = 1;

        // Búsqueda de productos
        document.getElementById('productSearch').addEventListener('input', function() {
            const query = this.value.toLowerCase();
            const results = document.getElementById('searchResults');

            if (query.length < 2) {
                results.style.display = 'none';
                return;
            }

            const filtered = productos.filter(p =>
                p.codigo.toLowerCase().includes(query) ||
                p.descripcion.toLowerCase().includes(query)
            );

            if (filtered.length > 0) {
                results.innerHTML = filtered.map(p =>
                    `<div class="search-item" onclick="selectProduct('${p.codigo}', '${p.descripcion}', ${p.precio})">
                        <strong>${p.codigo}</strong> - ${p.descripcion} (₲ ${p.precio.toLocaleString()})
                    </div>`
                ).join('');
                results.style.display = 'block';
            } else {
                results.style.display = 'none';
            }
        });

        // Seleccionar producto de la búsqueda
        function selectProduct(codigo, descripcion, precio) {
            addConcepto();
            const lastIndex = conceptoIndex - 1;
            const container = document.querySelector(`[data-index="${lastIndex}"]`);
            container.querySelector('input[name*="[codigo]"]').value = codigo;
            container.querySelector('input[name*="[descripcion]"]').value = descripcion;
            container.querySelector('input[name*="[precio]"]').value = precio;

            document.getElementById('searchResults').style.display = 'none';
            document.getElementById('productSearch').value = '';
            calculateTotals();
        }

        // Agregar nuevo concepto
        function addConcepto() {
            const container = document.getElementById('conceptosContainer');
            const newConcepto = document.createElement('div');
            newConcepto.className = 'concepto-item';
            newConcepto.setAttribute('data-index', conceptoIndex);

            newConcepto.innerHTML = `
                <div class="concepto-header">
                    <span class="concepto-number">Producto ${conceptoIndex + 1}</span>
                    <button type="button" class="btn-remove" onclick="removeConcepto(${conceptoIndex})">
                        <i class="fas fa-trash"></i> Eliminar
                    </button>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Código <span class="required">*</span></label>
                        <input type="text" name="conceptos[${conceptoIndex}][codigo]" required>
                    </div>
                    <div class="form-group">
                        <label>Descripción <span class="required">*</span></label>
                        <input type="text" name="conceptos[${conceptoIndex}][descripcion]" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Precio Unitario <span class="required">*</span></label>
                        <input type="number" name="conceptos[${conceptoIndex}][precio]" required onchange="calculateTotals()">
                    </div>
                    <div class="form-group">
                        <label>Cantidad <span class="required">*</span></label>
                        <input type="number" name="conceptos[${conceptoIndex}][cantidad]" value="1" required onchange="calculateTotals()">
                    </div>
                    <div class="form-group">
                        <label>IVA (%)</label>
                        <select name="conceptos[${conceptoIndex}][tasa_iva]" onchange="calculateTotals()">
                            <option value="0">0%</option>
                            <option value="5">5%</option>
                            <option value="10" selected>10%</option>
                        </select>
                    </div>
                </div>
            `;

            container.appendChild(newConcepto);
            conceptoIndex++;
            updateRemoveButtons();
        }

        // Eliminar concepto
        function removeConcepto(index) {
            const concepto = document.querySelector(`[data-index="${index}"]`);
            if (concepto) {
                concepto.remove();
                updateRemoveButtons();
                calculateTotals();
            }
        }

        // Actualizar botones de eliminar
        function updateRemoveButtons() {
            const conceptos = document.querySelectorAll('.concepto-item');
            conceptos.forEach((concepto, index) => {
                const removeBtn = concepto.querySelector('.btn-remove');
                if (conceptos.length > 1) {
                    removeBtn.style.display = 'block';
                } else {
                    removeBtn.style.display = 'none';
                }
            });
        }

        // Calcular totales
        function calculateTotals() {
            let subtotal = 0;
            let totalIva = 0;

            document.querySelectorAll('.concepto-item').forEach(concepto => {
                const precio = parseFloat(concepto.querySelector('input[name*="[precio]"]').value) || 0;
                const cantidad = parseFloat(concepto.querySelector('input[name*="[cantidad]"]').value) || 0;
                const tasaIva = parseFloat(concepto.querySelector('select[name*="[tasa_iva]"]').value) || 0;

                const subtotalItem = precio * cantidad;
                const ivaItem = subtotalItem * (tasaIva / 100);

                subtotal += subtotalItem;
                totalIva += ivaItem;
            });

            const total = subtotal + totalIva;

            document.getElementById('subtotal').textContent = `₲ ${subtotal.toLocaleString()}`;
            document.getElementById('totalIva').textContent = `₲ ${totalIva.toLocaleString()}`;
            document.getElementById('totalGeneral').textContent = `₲ ${total.toLocaleString()}`;
        }

        // Validación en tiempo real
        document.getElementById('facturaForm').addEventListener('input', function(e) {
            if (e.target.type === 'number' || e.target.name.includes('precio') || e.target.name.includes('cantidad')) {
                calculateTotals();
            }

            // Validación de campos requeridos
            const requiredFields = document.querySelectorAll('input[required], select[required]');
            let allValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    allValid = false;
                    field.style.borderColor = '#ef4444';
                } else {
                    field.style.borderColor = '#e5e7eb';
                }
            });
        });

        // Buscador de clientes interactivo
        let clientSearchTimeout;

        document.getElementById('clientSearch').addEventListener('input', function() {
            const query = this.value.trim();
            const results = document.getElementById('clientSearchResults');

            // Limpiar timeout anterior
            clearTimeout(clientSearchTimeout);

            if (query.length < 2) {
                results.style.display = 'none';
                return;
            }

            // Debounce para evitar demasiadas consultas
            clientSearchTimeout = setTimeout(() => {
                searchClients(query);
            }, 300);
        });

        function searchClients(query) {
            const results = document.getElementById('clientSearchResults');

            // Realizar búsqueda AJAX
            fetch(`?action=buscar_clientes&q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(clients => {
                    if (clients.length > 0) {
                        results.innerHTML = clients.map(client =>
                            `<div class="client-search-item" onclick="selectClient(${client.id}, '${client.nombre}', '${client.ruc}', '${client.direccion}', '${client.telefono}', '${client.email}')">
                                <div class="client-name">${client.nombre}</div>
                                <div class="client-details">
                                    <span class="client-ruc">RUC: ${client.ruc}</span>
                                    <span>${client.direccion}</span>
                                </div>
                            </div>`
                        ).join('');
                        results.style.display = 'block';
                    } else {
                        results.innerHTML = '<div class="client-search-item">No se encontraron clientes</div>';
                        results.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error al buscar clientes:', error);
                    results.style.display = 'none';
                });
        }

        function selectClient(id, nombre, ruc, direccion, telefono, email) {
            // Auto-rellenar campos del cliente
            document.getElementById('receptor_razon_social').value = nombre;
            document.getElementById('receptor_documento').value = ruc;
            document.getElementById('receptor_direccion').value = direccion || '';
            document.getElementById('receptor_telefono').value = telefono || '';
            document.getElementById('receptor_email').value = email || '';

            // Determinar tipo de documento basado en la longitud del RUC
            const tipoDoc = ruc.length > 8 ? 'ruc' : 'ci';
            document.getElementById('receptor_tipo_doc').value = tipoDoc;

            // Limpiar búsqueda
            document.getElementById('clientSearch').value = nombre;
            document.getElementById('clientSearchResults').style.display = 'none';

            // Efecto visual de confirmación
            const searchInput = document.getElementById('clientSearch');
            searchInput.style.borderColor = '#10b981';
            searchInput.style.backgroundColor = '#f0fdf4';

            setTimeout(() => {
                searchInput.style.borderColor = '#e2e8f0';
                searchInput.style.backgroundColor = '#f8fafc';
            }, 2000);
        }

        // Ocultar resultados de búsqueda al hacer clic fuera
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-container')) {
                document.getElementById('searchResults').style.display = 'none';
            }
            if (!e.target.closest('.client-search-container')) {
                document.getElementById('clientSearchResults').style.display = 'none';
            }
        });

        // Inicializar cálculos
        calculateTotals();
        updateRemoveButtons();
    </script>
</body>

</html>