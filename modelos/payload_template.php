// Obtener id_factura desde parámetros GET/POST o usar valor por defecto para pruebas
$id_factura = isset($_GET['id_factura']) ? $_GET['id_factura'] : (isset($_POST['id_factura']) ? $_POST['id_factura'] : '1');

// Debug: Mostrar el ID de factura que se está usando
echo "<!-- DEBUG: Usando id_factura = " . htmlspecialchars($id_factura) . " -->\n";

// CONEXIÓN A LA BASE DE DATOS
$host = '168.231.95.50';
$username = 'sistemax';
$password = 'Armagedon123';
$database = 'serproc1';

$mysqli = new mysqli($host, $username, $password, $database);
if ($mysqli->connect_error) {
    echo "<!-- ERROR CONEXIÓN BD: " . $mysqli->connect_error . " -->\n";
    die('Error de conexión a la base de datos');
}
$mysqli->set_charset('utf8');
echo "<!-- DEBUG: Conexión a BD exitosa -->\n";

// Consulta principal de la factura con mysqli
$sql_fact = "SELECT * FROM v_documento_completo WHERE dNumDoc = ?";
$stmt_fact = $mysqli->prepare($sql_fact);
if (!$stmt_fact) {
    echo "<!-- ERROR PREPARE FACTURA: " . $mysqli->error . " -->\n";
    $fact = [];
} else {
    $stmt_fact->bind_param('s', $id_factura);
    $stmt_fact->execute();
    $result_fact = $stmt_fact->get_result();
    $fact = $result_fact->fetch_all(MYSQLI_ASSOC);
    $stmt_fact->close();
    echo "<!-- DEBUG: Consulta factura ejecutada, registros: " . count($fact) . " -->\n";
}

// DEBUG DETALLADO: Mostrar contenido completo de $fact
echo "<!-- DEBUG FACT RAW: " . print_r($fact, true) . " -->
";
echo "<!-- DEBUG FACT TYPE: " . gettype($fact) . " -->
";
echo "<!-- DEBUG FACT EMPTY: " . (empty($fact) ? 'TRUE' : 'FALSE') . " -->
";
echo "<!-- DEBUG FACT IS_ARRAY: " . (is_array($fact) ? 'TRUE' : 'FALSE') . " -->
";

// Verificar si la consulta de factura retornó datos
if (empty($fact) || !is_array($fact) || !isset($fact[0])) {
    echo "<!-- DEBUG: No se encontraron datos de factura para ID: " . htmlspecialchars($id_factura) . " -->
";
    $fact = [[
        'id_factura' => $id_factura,
        'nro_factura' => '001-001-0000001',
        'id_cliente' => '12345678',
        'id_empresa' => '1',
        'forma_pago' => '1',
        'timbrado' => '12345678',
        'total' => '1000'
    ]];
} else {
    echo "<!-- DEBUG: Factura encontrada: " . count($fact) . " registros -->
";
    echo "<!-- DEBUG FACT[0]: " . print_r($fact[0], true) . " -->
";
}

// Validar que $fact tiene datos
if (empty($fact) || !is_array($fact) || !isset($fact[0])) {
    echo "<!-- ERROR: No se encontraron datos de factura, usando valores por defecto -->\n";
    $fact = [[
        'id_factura' => $id_factura,
        'nro_factura' => '001-001-0000001',
        'id_cliente' => '12345678',
        'id_empresa' => '1',
        'forma_pago' => '1',
        'timbrado' => '12345678',
        'total' => '1000'
    ]];
}

$fact = $fact[0];
echo "<!-- DEBUG FACT FINAL: " . print_r($fact, true) . " -->
";

// Consulta de items/productos de la factura con mysqli
echo "<!-- DEBUG: Buscando items para factura ID: " . htmlspecialchars($id_factura) . " -->\n";
$sql_item = "SELECT * FROM de_items WHERE id_de = ?";
$stmt_item = $mysqli->prepare($sql_item);
if (!$stmt_item) {
    echo "<!-- ERROR PREPARE ITEMS: " . $mysqli->error . " -->\n";
    $item = [];
} else {
    $stmt_item->bind_param('i', 1); // Usar id_de = 1 que corresponde al documento
    $stmt_item->execute();
    $result_item = $stmt_item->get_result();
    $item = $result_item->fetch_all(MYSQLI_ASSOC);
    $stmt_item->close();
    echo "<!-- DEBUG: Consulta items ejecutada, registros: " . count($item) . " -->\n";
}

// DEBUG DETALLADO: Mostrar contenido completo de $item
echo "<!-- DEBUG ITEM RAW: " . print_r($item, true) . " -->\n";
echo "<!-- DEBUG ITEM TYPE: " . gettype($item) . " -->\n";
echo "<!-- DEBUG ITEM EMPTY: " . (empty($item) ? 'TRUE' : 'FALSE') . " -->\n";
echo "<!-- DEBUG ITEM IS_ARRAY: " . (is_array($item) ? 'TRUE' : 'FALSE') . " -->\n";
echo "<!-- DEBUG ITEM COUNT: " . (is_array($item) ? count($item) : '0') . " -->\n";

// Verificar si la consulta de items retornó datos
if (empty($item) || !is_array($item)) {
    echo "<!-- DEBUG: No se encontraron items para factura ID: " . htmlspecialchars($id_factura) . " -->\n";
    $item = [[
        'codigo' => 'PROD001',
        'descripcion' => 'Producto genérico',
        'precio' => '1000',
        'salida' => '1',
        'descuento' => '0',
        'tipo_iva' => '3',
        'sifen_item_cUniMed' => '77'
    ]];
} else {
    echo "<!-- DEBUG: Items encontrados: " . count($item) . " registros -->\n";
    // Mostrar cada item encontrado
    foreach ($item as $index => $producto) {
        echo "<!-- DEBUG ITEM[" . $index . "]: " . print_r($producto, true) . " -->\n";
    }
}

// Consulta del cliente con mysqli
$cliente_doc = isset($fact['dRucRec']) ? $fact['dRucRec'] : '12345678';
echo "<!-- DEBUG: Buscando cliente con documento: " . htmlspecialchars($cliente_doc) . " -->\n";
$sql_cli = "SELECT * FROM buscarga_cliente WHERE cedula = ?";
$stmt_cli = $mysqli->prepare($sql_cli);
if (!$stmt_cli) {
    echo "<!-- ERROR PREPARE CLIENTE: " . $mysqli->error . " -->\n";
    $cli = [];
} else {
    $stmt_cli->bind_param('s', $cliente_doc);
    $stmt_cli->execute();
    $result_cli = $stmt_cli->get_result();
    $cli = $result_cli->fetch_all(MYSQLI_ASSOC);
    $stmt_cli->close();
    echo "<!-- DEBUG: Consulta cliente ejecutada, registros: " . count($cli) . " -->\n";
}

// DEBUG DETALLADO: Mostrar contenido completo de $cli
echo "<!-- DEBUG CLI RAW: " . print_r($cli, true) . " -->
";
echo "<!-- DEBUG CLI TYPE: " . gettype($cli) . " -->
";
echo "<!-- DEBUG CLI EMPTY: " . (empty($cli) ? 'TRUE' : 'FALSE') . " -->
";
echo "<!-- DEBUG CLI IS_ARRAY: " . (is_array($cli) ? 'TRUE' : 'FALSE') . " -->
";

// Verificar si la consulta de cliente retornó datos
if (empty($cli) || !is_array($cli) || !isset($cli[0])) {
    echo "<!-- DEBUG: No se encontró cliente con documento: " . htmlspecialchars($cliente_doc) . " -->
";
    $cli = [[
        'documento' => $cliente_doc,
        'cliente' => 'Cliente Genérico',
        'telefono' => '021-123456',
        'email' => 'cliente@ejemplo.com',
        'direccion' => 'Dirección del cliente',
        'departamento' => '11',
        'distrito' => '143',
        'ciudad' => '3344'
    ]];
} else {
    echo "<!-- DEBUG: Cliente encontrado: " . count($cli) . " registros -->
";
    echo "<!-- DEBUG CLI[0]: " . print_r($cli[0], true) . " -->
";
}

// Validar que $cli tiene datos
if (empty($cli) || !is_array($cli) || !isset($cli[0])) {
    echo "<!-- ERROR: No se encontraron datos de cliente, usando valores por defecto -->\n";
    $cli = [[
        'documento' => $cliente_doc,
        'cliente' => 'Cliente Genérico',
        'telefono' => '021-123456',
        'email' => 'cliente@ejemplo.com',
        'direccion' => 'Dirección del cliente',
        'departamento' => '11',
        'distrito' => '143',
        'ciudad' => '3344'
    ]];
}

$cli = $cli[0];
echo "<!-- DEBUG CLI FINAL: " . print_r($cli, true) . " -->
";

// Consulta de la empresa con mysqli
$empresa_id = isset($fact['id_empresa']) ? $fact['id_empresa'] : '1';
echo "<!-- DEBUG: Buscando empresa con ID: " . htmlspecialchars($empresa_id) . " -->\n";
$sql_emp = "SELECT * FROM empresa WHERE id_empresa = ?";
$stmt_emp = $mysqli->prepare($sql_emp);
if (!$stmt_emp) {
    echo "<!-- ERROR PREPARE EMPRESA: " . $mysqli->error . " -->\n";
    $emp = [];
} else {
    $stmt_emp->bind_param('s', $empresa_id);
    $stmt_emp->execute();
    $result_emp = $stmt_emp->get_result();
    $emp = $result_emp->fetch_all(MYSQLI_ASSOC);
    $stmt_emp->close();
    echo "<!-- DEBUG: Consulta empresa ejecutada, registros: " . count($emp) . " -->\n";
}

// Cerrar conexión
$mysqli->close();
echo "<!-- DEBUG: Conexión BD cerrada -->\n";

// DEBUG DETALLADO: Mostrar contenido completo de $emp
echo "<!-- DEBUG EMP RAW: " . print_r($emp, true) . " -->
";
echo "<!-- DEBUG EMP TYPE: " . gettype($emp) . " -->
";
echo "<!-- DEBUG EMP EMPTY: " . (empty($emp) ? 'TRUE' : 'FALSE') . " -->
";
echo "<!-- DEBUG EMP IS_ARRAY: " . (is_array($emp) ? 'TRUE' : 'FALSE') . " -->
";

// Verificar si la consulta de empresa retornó datos
if (empty($emp) || !is_array($emp) || !isset($emp[0])) {
    echo "<!-- DEBUG: No se encontró empresa con ID: " . htmlspecialchars($empresa_id) . " -->
";
    $emp = [[
        'ruc' => '80000001-7',
        'empresa' => 'Empresa Ejemplo S.A.',
        'cod_act' => '47190',
        'des_act' => 'Venta al por menor en comercios no especializados',
        'telefono' => '021-123456',
        'email' => 'empresa@ejemplo.com',
        'direccion' => 'Dirección de la empresa',
        'departamento' => '11',
        'distrito' => '143',
        'ciudad_id' => '3344',
        'vigencia_ini' => '2024-01-01'
    ]];
} else {
    echo "<!-- DEBUG: Empresa encontrada: " . count($emp) . " registros -->
";
    echo "<!-- DEBUG EMP[0]: " . print_r($emp[0], true) . " -->
";
}

// Validar que $emp tiene datos
if (empty($emp) || !is_array($emp) || !isset($emp[0])) {
    echo "<!-- ERROR: No se encontraron datos de empresa, usando valores por defecto -->\n";
    $emp = [[
        'ruc' => '80000001-7',
        'empresa' => 'Empresa Ejemplo S.A.',
        'cod_act' => '47190',
        'des_act' => 'Venta al por menor en comercios no especializados',
        'telefono' => '021-123456',
        'email' => 'empresa@ejemplo.com',
        'direccion' => 'Dirección de la empresa',
        'departamento' => '11',
        'distrito' => '143',
        'ciudad_id' => '3344',
        'vigencia_ini' => '2024-01-01'
    ]];
}

$emp = $emp[0];
echo "<!-- DEBUG EMP FINAL: " . print_r($emp, true) . " -->
";

// Debug final: Mostrar datos cargados con validaciones
echo "<!-- ========== DEBUG FINAL: RESUMEN DE DATOS CARGADOS ========== -->\n";
echo "<!-- Factura ID: " . htmlspecialchars($fact['id_factura'] ?? 'NO_DATA') . " -->\n";
echo "<!-- Factura Número: " . htmlspecialchars($fact['nro_factura'] ?? 'NO_DATA') . " -->\n";
echo "<!-- Cliente Doc: " . htmlspecialchars($cli['documento'] ?? 'NO_DATA') . " -->\n";
echo "<!-- Cliente Nombre: " . htmlspecialchars($cli['cliente'] ?? 'NO_DATA') . " -->\n";
echo "<!-- Empresa RUC: " . htmlspecialchars($emp['ruc'] ?? 'NO_DATA') . " -->\n";
echo "<!-- Empresa Nombre: " . htmlspecialchars($emp['empresa'] ?? 'NO_DATA') . " -->\n";
echo "<!-- Items Count: " . (is_array($item) ? count($item) : '0') . " -->\n";

// Verificar variables críticas con más detalle
$errores_criticos = [];
$warnings = [];

// Validar factura
if (empty($fact) || !is_array($fact)) {
    $errores_criticos[] = 'Variable $fact no es válida';
} else {
    if (empty($fact['id_factura'])) $errores_criticos[] = 'ID Factura vacío';
    if (empty($fact['nro_factura'])) $warnings[] = 'Número de factura vacío';
    if (empty($fact['id_cliente'])) $warnings[] = 'ID Cliente vacío';
    if (empty($fact['id_empresa'])) $warnings[] = 'ID Empresa vacío';
}

// Validar cliente
if (empty($cli) || !is_array($cli)) {
    $errores_criticos[] = 'Variable $cli no es válida';
} else {
    if (empty($cli['documento'])) $errores_criticos[] = 'Documento cliente vacío';
    if (empty($cli['cliente'])) $warnings[] = 'Nombre cliente vacío';
}

// Validar empresa
if (empty($emp) || !is_array($emp)) {
    $errores_criticos[] = 'Variable $emp no es válida';
} else {
    if (empty($emp['ruc'])) $errores_criticos[] = 'RUC empresa vacío';
    if (empty($emp['empresa'])) $warnings[] = 'Nombre empresa vacío';
}

// Validar items
if (empty($item) || !is_array($item)) {
    $errores_criticos[] = 'Items vacíos o no válidos';
} else {
    foreach ($item as $index => $it) {
        if (empty($it['descripcion'])) $warnings[] = "Item " . $index . ": descripción vacía";
        if (empty($it['salida']) || $it['salida'] <= 0) $warnings[] = "Item " . $index . ": cantidad inválida";
        if (empty($it['precio']) || $it['precio'] <= 0) $warnings[] = "Item " . $index . ": precio inválido";
    }
}

// Mostrar errores y warnings
if (!empty($errores_criticos)) {
    echo "<!-- ERRORES CRÍTICOS: " . implode(', ', $errores_criticos) . " -->\n";
}
if (!empty($warnings)) {
    echo "<!-- WARNINGS: " . implode(', ', $warnings) . " -->\n";
}
if (empty($errores_criticos) && empty($warnings)) {
    echo "<!-- ESTADO: Todos los datos están correctos -->\n";
}
echo "<!-- ========== FIN DEBUG FINAL ========== -->\n";

// Mostrar resumen completo para referencia
echo "<!-- DEBUG FINAL COMPLETO: ";
echo "\nFactura ID: " . htmlspecialchars($fact['id_factura'] ?? 'NULL');
echo "\nFactura Numero: " . htmlspecialchars($fact['nro_factura'] ?? 'NULL');
echo "\nEmisor RUC: " . htmlspecialchars($emp['ruc'] ?? 'NULL');
echo "\nEmisor Empresa: " . htmlspecialchars($emp['empresa'] ?? 'NULL');
echo "\nCliente Doc: " . htmlspecialchars($cli['documento'] ?? 'NULL');
echo "\nCliente Nombre: " . htmlspecialchars($cli['cliente'] ?? 'NULL');
echo "\nItems Count: " . count($item);
if (!empty($item) && is_array($item)) {
    foreach ($item as $index => $producto) {
        echo "\nItem " . $index . " Codigo: " . htmlspecialchars($producto['codigo'] ?? 'NULL');
        echo "\nItem " . $index . " Descripcion: " . htmlspecialchars($producto['descripcion'] ?? 'NULL');
        echo "\nItem " . $index . " Precio: " . htmlspecialchars($producto['precio'] ?? 'NULL');
    }
}
echo "\n-->\n";

// Usar errores críticos para continuar
if (!empty($errores_criticos)) {
    echo "<!-- ERRORES CRÍTICOS DETECTADOS: " . implode(', ', $errores_criticos) . " -->\n";
    echo "<!-- USANDO VALORES POR DEFECTO PARA CONTINUAR -->\n";
}

// ============================================================================
// FUNCIONES DE VALIDACIÓN Y VALORES POR DEFECTO
// ============================================================================


/**
 * Determinar tipo de documento
 */
function obtener_tipo_documento($documento) {
    if (empty($documento)) return 1; // CI por defecto
    
    $documento = str_replace(['-', '.', ' '], '', $documento);
    
    if (strlen($documento) >= 8 && strpos($documento, '-') !== false) {
        return 2; // RUC
    }
    return 1; // CI
}

/**
 * Calcular IVA automáticamente
 */
function calcular_iva($precio_unitario, $cantidad, $descuento = 0, $iva_tipo = 3) {
    $subtotal = ($precio_unitario * $cantidad) - $descuento;
    
    switch ($iva_tipo) {
        case 3: // IVA 10%
            $iva_base = round($subtotal / 1.1);
            $iva = $subtotal - $iva_base;
            break;
        case 2: // IVA 5%
            $iva_base = round($subtotal / 1.05);
            $iva = $subtotal - $iva_base;
            break;
        case 1: // Exento
        default:
            $iva_base = $subtotal;
            $iva = 0;
            break;
    }
    
    return [
        'iva_base' => $iva_base,
        'iva' => $iva,
        'subtotal' => $subtotal
    ];
}


// ============================================================================
// DATOS DEL EMISOR (Empresa) - DESDE BASE DE DATOS
// ============================================================================

// Asignar datos del emisor (empresa) con validaciones robustas
$ruc_emisor = (!empty($emp['ruc']) && strlen(trim($emp['ruc'])) > 0) ? trim($emp['ruc']) : '80000001-7';
$razon_social_emisor = (!empty($emp['empresa']) && strlen(trim($emp['empresa'])) > 0) ? trim($emp['empresa']) : 'Empresa Ejemplo S.A.';
$nombre_fantasia_emisor = (!empty($emp['empresa']) && strlen(trim($emp['empresa'])) > 0) ? trim($emp['empresa']) : 'Empresa Ejemplo S.A.';
$actividad_codigo = (!empty($emp['cod_act']) && strlen(trim($emp['cod_act'])) > 0) ? trim($emp['cod_act']) : '47190';
$actividad_descripcion = (!empty($emp['des_act']) && strlen(trim($emp['des_act'])) > 0) ? trim($emp['des_act']) : 'Venta al por menor en comercios no especializados';
$telefono_emisor = (!empty($emp['telefono']) && strlen(trim($emp['telefono'])) > 0) ? trim($emp['telefono']) : '021-123456';
$email_emisor = (!empty($emp['email']) && strlen(trim($emp['email'])) > 0) ? trim($emp['email']) : 'empresa@ejemplo.com';
$direccion_emisor = (!empty($emp['direccion']) && strlen(trim($emp['direccion'])) > 0) ? trim($emp['direccion']) : 'Dirección de la empresa';

// UBICACIÓN GEOGRÁFICA DEL EMISOR - Valores por defecto SIFEN con validaciones
$emisor_departamento = (!empty($emp['departamento']) && strlen(trim($emp['departamento'])) > 0) ? trim($emp['departamento']) : '11';
$emisor_distrito = (!empty($emp['distrito']) && strlen(trim($emp['distrito'])) > 0) ? trim($emp['distrito']) : '143';
$emisor_ciudad = (!empty($emp['ciudad_id']) && strlen(trim($emp['ciudad_id'])) > 0) ? trim($emp['ciudad_id']) : '3344';

// Debug emisor
echo "<!-- DEBUG EMISOR FINAL: RUC=" . htmlspecialchars($ruc_emisor) . ", Razón=" . htmlspecialchars($razon_social_emisor) . " -->\n";

// ============================================================================
// DATOS DEL RECEPTOR (Cliente) - DESDE BASE DE DATOS
// ============================================================================

$cliente_documento_numero = (!empty($cli['documento']) && strlen(trim($cli['documento'])) > 0) ? trim($cli['documento']) : '12345678';
$cliente_documento_tipo = obtener_tipo_documento($cliente_documento_numero) == 2 ? 'ruc' : 'ci';  // Tipo determinado automáticamente
$cliente_razon_social = (!empty($cli['cliente']) && strlen(trim($cli['cliente'])) > 0) ? trim($cli['cliente']) : 'Cliente Genérico';
$cliente_telefono = (!empty($cli['telefono']) && strlen(trim($cli['telefono'])) > 0) ? trim($cli['telefono']) : '021-123456';
$cliente_email = (!empty($cli['email']) && strlen(trim($cli['email'])) > 0) ? trim($cli['email']) : 'cliente@ejemplo.com';
$cliente_direccion = (!empty($cli['direccion']) && strlen(trim($cli['direccion'])) > 0) ? trim($cli['direccion']) : 'Dirección del cliente';

// UBICACIÓN GEOGRÁFICA DEL RECEPTOR - Valores por defecto SIFEN con validaciones
$receptor_departamento = (!empty($cli['departamento']) && strlen(trim($cli['departamento'])) > 0) ? trim($cli['departamento']) : '11';
$receptor_distrito = (!empty($cli['distrito']) && strlen(trim($cli['distrito'])) > 0) ? trim($cli['distrito']) : '143';
$receptor_ciudad = (!empty($cli['ciudad']) && strlen(trim($cli['ciudad'])) > 0) ? trim($cli['ciudad']) : '3344';

// Debug receptor
echo "<!-- DEBUG RECEPTOR FINAL: Doc=" . htmlspecialchars($cliente_documento_numero) . ", Nombre=" . htmlspecialchars($cliente_razon_social) . " -->\n";

// ============================================================================
// DATOS DE LA FACTURA - DESDE BASE DE DATOS
// ============================================================================

$numero_documento = (!empty($fact['nro_factura']) && strlen(trim($fact['nro_factura'])) > 0) ? trim($fact['nro_factura']) : '001-001-0000001';  // Número desde BD con validación
list($establecimiento, $expedicion, $secuencia) = explode('-', $numero_documento, 3);

$condicion_operacion = (!empty($fact['forma_pago']) && strlen(trim($fact['forma_pago'])) > 0) ? trim($fact['forma_pago']) : '1';  // 1=Contado, 2=Crédito
$timbrado = (!empty($fact['timbrado']) && strlen(trim($fact['timbrado'])) > 0) ? trim($fact['timbrado']) : '12345678';  // Timbrado desde BD factura
$fecha_timbrado = (!empty($emp['vigencia_ini']) && strlen(trim($emp['vigencia_ini'])) > 0) ? trim($emp['vigencia_ini']) : '2024-01-01';  // Fecha timbrado
$moneda = 'PYG';  // Moneda desde BD o PYG por defecto
$tipo_cambio =  1;  // Tipo cambio desde BD
$plazo_credito = 1;  // Plazo si es crédito

// Debug factura
echo "<!-- DEBUG FACTURA FINAL: Número=" . htmlspecialchars($numero_documento) . ", Timbrado=" . htmlspecialchars($timbrado) . " -->\n";

// ============================================================================
// PRODUCTOS/CONCEPTOS - DESDE BASE DE DATOS
// ============================================================================

$conceptos = [];

// Construir conceptos desde los items de la factura con validaciones robustas
if (!empty($item) && is_array($item)) {
    foreach ($item as $index => $producto) {
        // Validar y limpiar cada campo del producto
        $descripcion = (!empty($producto['descripcion']) && strlen(trim($producto['descripcion'])) > 0) ? trim($producto['descripcion']) : 'Producto sin descripción ' . ($index + 1);
        $cantidad = (!empty($producto['salida']) && is_numeric($producto['salida']) && floatval($producto['salida']) > 0) ? floatval($producto['salida']) : 1;
        $precio_unitario = (!empty($producto['precio']) && is_numeric($producto['precio']) && floatval($producto['precio']) > 0) ? floatval($producto['precio']) : 1000;
        $descuento = (!empty($producto['descuento']) && is_numeric($producto['descuento'])) ? floatval($producto['descuento']) : 0;
        $iva_tipo = (!empty($producto['tipo_iva']) && is_numeric($producto['tipo_iva'])) ? intval($producto['tipo_iva']) : 3; // 3=10%, 2=5%, 1=Exento
        $unidad_medida = (!empty($producto['sifen_item_cUniMed']) && is_numeric($producto['sifen_item_cUniMed'])) ? intval($producto['sifen_item_cUniMed']) : 77;
        
        // Calcular IVA automáticamente
        $calculo_iva = calcular_iva($precio_unitario, $cantidad, $descuento, $iva_tipo);
        
        $conceptos[] = [
            'codigo' => (!empty($producto['codigo']) && strlen(trim($producto['codigo'])) > 0) ? trim($producto['codigo']) : sprintf('PROD%03d', $index + 1),
            'descripcion' => $descripcion,
            'cantidad' => $cantidad,
            'unidad_medida' => $unidad_medida,
            'precio_unitario' => $precio_unitario,
            'descuento' => $descuento,
            'iva_tipo' => $iva_tipo,
            'iva_base' => $calculo_iva['iva_base'],
            'iva' => $calculo_iva['iva']
        ];
        
        // Debug cada concepto
        echo "<!-- DEBUG CONCEPTO " . $index . ": " . htmlspecialchars($descripcion) . ", Cant=" . $cantidad . ", Precio=" . $precio_unitario . ", IVA=" . $iva_tipo . " -->\n";
    }
} else {
    echo "<!-- DEBUG: Usando concepto por defecto porque no hay items válidos -->\n";
}

// Si no hay items, crear uno por defecto
if (empty($conceptos)) {
    echo "<!-- ERROR: No se pudieron crear conceptos, usando concepto de emergencia -->\n";
    $conceptos = [
        [
            'codigo' => 'PROD001',
            'descripcion' => 'Producto genérico',
            'cantidad' => 1,
            'unidad_medida' => 77, // Unidad
            'precio_unitario' => 1000,
            'descuento' => 0,
            'iva_tipo' => 3, // IVA 10%
            'iva_base' => 909,
            'iva' => 91
        ]
    ];
}

// Validar que tenemos al menos un concepto
if (empty($conceptos)) {
    echo "<!-- ERROR CRÍTICO: No se pudieron crear conceptos, usando concepto de emergencia -->\n";
    $conceptos[] = [
        'codigo' => 'EMERG001',
        'descripcion' => 'Producto de Emergencia',
        'cantidad' => 1,
        'unidad_medida' => 77,
        'precio_unitario' => 1000,
        'descuento' => 0,
        'iva_tipo' => 3,
        'iva_base' => 909,
        'iva' => 91
    ];
}

echo "<!-- DEBUG CONCEPTOS FINAL: Total=" . count($conceptos) . " conceptos creados -->\n";

// ============================================================================
// FORMAS DE PAGO - CALCULADO AUTOMÁTICAMENTE CON VALIDACIONES
// ============================================================================

// Calcular total de la factura con validaciones
$total_factura = 0;
if (!empty($conceptos) && is_array($conceptos)) {
    foreach ($conceptos as $concepto) {
        $precio = (!empty($concepto['precio_unitario']) && is_numeric($concepto['precio_unitario'])) ? floatval($concepto['precio_unitario']) : 0;
        $cantidad = (!empty($concepto['cantidad']) && is_numeric($concepto['cantidad'])) ? floatval($concepto['cantidad']) : 1;
        $descuento = (!empty($concepto['descuento']) && is_numeric($concepto['descuento'])) ? floatval($concepto['descuento']) : 0;
        $iva = (!empty($concepto['iva']) && is_numeric($concepto['iva'])) ? floatval($concepto['iva']) : 0;
        
        $subtotal = ($precio * $cantidad) - $descuento + $iva;
        $total_factura += $subtotal;
    }
}

// Validar que el total sea positivo
if ($total_factura <= 0) {
    echo "<!-- WARNING: Total calculado es 0 o negativo, usando total mínimo -->\n";
    $total_factura = 1000; // Total mínimo de emergencia
}

// Formas de pago con validaciones (por defecto efectivo)
$formas_pago = [];

// Intentar obtener forma de pago desde la BD
$tipo_pago_bd = (!empty($fact['forma_pago']) && strlen(trim($fact['forma_pago'])) > 0) ? trim($fact['forma_pago']) : '1';
$tipo_pago_sifen = ($tipo_pago_bd == '2') ? '02' : '01'; // 2=Tarjeta, 1=Efectivo

$formas_pago[] = [
    'tipo' => $tipo_pago_sifen,
    'monto' => $total_factura,
    'moneda' => 'PYG',
    'tipo_cambio' => 1
];

// Validar que tenemos al menos una forma de pago
if (empty($formas_pago)) {
    echo "<!-- ERROR: No se pudieron crear formas de pago, usando efectivo por defecto -->\n";
    $formas_pago[] = [
        'tipo' => '01', // Efectivo
        'monto' => $total_factura,
        'moneda' => 'PYG',
        'tipo_cambio' => 1
    ];
}

echo "<!-- DEBUG FORMAS DE PAGO: Total=" . $total_factura . ", Tipo=" . $formas_pago[0]['tipo'] . " -->
";

// ============================================================================
// RESUMEN FINAL DE VALIDACIÓN - VERIFICAR TODAS LAS VARIABLES CRÍTICAS
// ============================================================================

echo "<!-- ========== RESUMEN FINAL DE VALIDACIÓN ========== -->
";
echo "<!-- EMISOR: RUC=" . htmlspecialchars($ruc_emisor) . ", Razón=" . htmlspecialchars($razon_social_emisor) . " -->
";
echo "<!-- RECEPTOR: Doc=" . htmlspecialchars($cliente_documento_numero) . ", Razón=" . htmlspecialchars($cliente_razon_social) . " -->
";
echo "<!-- FACTURA: Número=" . htmlspecialchars($numero_documento) . ", Timbrado=" . htmlspecialchars($timbrado) . " -->
";
echo "<!-- CONCEPTOS: Total=" . count($conceptos) . " items -->
";
echo "<!-- FORMAS PAGO: Total=" . count($formas_pago) . ", Monto=" . $total_factura . " -->
";

// Verificar variables críticas y reportar errores
$errores_criticos = [];
$advertencias = [];

if (empty($ruc_emisor) || $ruc_emisor === '80000001-7') {
    $errores_criticos[] = 'RUC emisor no válido o por defecto';
}
if (empty($razon_social_emisor) || $razon_social_emisor === 'Empresa por Defecto') {
    $advertencias[] = 'Razón social emisor por defecto';
}
if (empty($numero_documento_receptor) || $numero_documento_receptor === '22222222-2') {
    $advertencias[] = 'Documento receptor por defecto';
}
if (empty($conceptos) || count($conceptos) === 0) {
    $errores_criticos[] = 'No hay conceptos válidos';
}
if (empty($formas_pago) || count($formas_pago) === 0) {
    $errores_criticos[] = 'No hay formas de pago válidas';
}
if ($total_factura <= 0) {
    $errores_criticos[] = 'Total de factura inválido';
}

if (!empty($errores_criticos)) {
    echo "<!-- ERRORES CRÍTICOS: " . implode(', ', $errores_criticos) . " -->
";
}
if (!empty($advertencias)) {
    echo "<!-- ADVERTENCIAS: " . implode(', ', $advertencias) . " -->
";
}

if (empty($errores_criticos)) {
    echo "<!-- ✓ VALIDACIÓN EXITOSA: Todas las variables críticas tienen valores válidos -->
";
} else {
    echo "<!-- ✗ VALIDACIÓN FALLIDA: Hay errores críticos que deben resolverse -->
";
}

echo "<!-- ================================================== -->
";

/* Si hay múltiples formas de pago en BD, agregar aquí
if (isset($fact['forma_pago_2']) && !empty($fact['forma_pago_2'])) {
    $monto_2 = floatval($fact['monto_pago_2'], 0));
    if ($monto_2 > 0) {
        $formas_pago[0]['monto'] = $total_factura - $monto_2; // Ajustar primer pago
        $formas_pago[] = [
            'forma_pago' => intval($fact['forma_pago_2']),
            'monto' => $monto_2,
            'moneda' => $moneda
        ];
    }
}
*/

function calcular_totales($conceptos = null) {
    // Si no se pasa $conceptos como parámetro, usar la variable global
    if (empty($conceptos)) {
        global $conceptos;
    }
    
    $total_sin_iva = 0;
    $total_iva = 0;
    
    foreach ($conceptos as $concepto) {
        $subtotal = ($concepto['cantidad'] * $concepto['precio_unitario']) - $concepto['descuento'];
        $total_sin_iva += $concepto['iva_base'];
        $total_iva += $concepto['iva'];
    }
    
    return [
        'total_sin_iva' => $total_sin_iva,
        'total_iva' => $total_iva,
        'total_general' => $total_sin_iva + $total_iva
    ];
}

// Nueva función para calcular totales con datos directos
function calcular_totales_con_datos($conceptos_param) {
    $total_sin_iva = 0;
    $total_iva = 0;
    
    foreach ($conceptos_param as $concepto) {
        $subtotal = ($concepto['cantidad'] * $concepto['precio_unitario']) - $concepto['descuento'];
        $total_sin_iva += $concepto['iva_base'];
        $total_iva += $concepto['iva'];
    }
    
    return [
        'total_sin_iva' => $total_sin_iva,
        'total_iva' => $total_iva,
        'total_general' => $total_sin_iva + $total_iva
    ];
}

// ============================================================================
// CONSTRUCCIÓN DEL PAYLOAD
// ============================================================================

/**
 * Construir el payload completo - VERSIÓN ORIGINAL (CON GLOBALES)
 */
function construir_payload() {
    // Declarar variables globales necesarias
    global $ruc_emisor, $razon_social_emisor, $nombre_fantasia_emisor, $actividad_codigo, $actividad_descripcion;
    global $telefono_emisor, $email_emisor, $direccion_emisor, $emisor_departamento, $emisor_distrito, $emisor_ciudad;
    global $cliente_documento_numero, $cliente_documento_tipo, $cliente_razon_social, $cliente_telefono, $cliente_email, $cliente_direccion;
    global $receptor_departamento, $receptor_distrito, $receptor_ciudad;
    global $conceptos, $formas_pago, $numero_documento, $condicion_operacion, $timbrado, $fecha_timbrado;
    global $establecimiento, $expedicion, $moneda, $tipo_cambio, $plazo_credito;
    
    // Construir payload
    $payload = [
        'emisor' => [
            'ruc' => $ruc_emisor,
            'razon_social' => $razon_social_emisor,
            'nombre_fantasia' => $nombre_fantasia_emisor,
            'actividades_economicas' => [
                [
                    'codigo' => $actividad_codigo,
                    'descripcion' => $actividad_descripcion
                ]
            ],
            'direccion' => [
                'departamento' => $emisor_departamento,
                'distrito' => $emisor_distrito,
                'ciudad' => $emisor_ciudad,
                'direccion' => $direccion_emisor
            ],
            'telefono' => $telefono_emisor,
            'email' => $email_emisor
        ],
        'receptor' => [
            'documento_tipo' => $cliente_documento_tipo,
            'documento_numero' => $cliente_documento_numero,
            'razon_social' => $cliente_razon_social,
            'direccion' => [
                'departamento' => $receptor_departamento,
                'distrito' => $receptor_distrito,
                'ciudad' => $receptor_ciudad,
                'direccion' => $cliente_direccion
            ],
            'telefono' => $cliente_telefono,
            'email' => $cliente_email
        ],
        'conceptos' => $conceptos,
        'formas_pagos' => $formas_pago,
        'ndoc' => $numero_documento,
        'condicion' => $condicion_operacion,
        'timbrado' => $timbrado,
        'fec_timbrado' => $fecha_timbrado,
        'cod_establecimiento' => $establecimiento,
        'cod_expedicion' => $expedicion,
        'moneda' => $moneda,
        'cambio' => $tipo_cambio,
        'plazo' => $plazo_credito
    ];
    
    return $payload;
}

/**
 * Construir el payload completo - NUEVA VERSIÓN (CON PARÁMETROS)
 */
function construir_payload_con_datos($fact, $item, $cli, $emp) {
    echo "<!-- DEBUG PAYLOAD_CON_DATOS: Iniciando construcción con datos directos -->\n";
    echo "<!-- DEBUG FACT RECIBIDO: " . print_r($fact, true) . " -->\n";
    echo "<!-- DEBUG ITEM RECIBIDO: " . print_r($item, true) . " -->\n";
    echo "<!-- DEBUG CLI RECIBIDO: " . print_r($cli, true) . " -->\n";
    echo "<!-- DEBUG EMP RECIBIDO: " . print_r($emp, true) . " -->\n";
    
    // Validar que los datos recibidos no sean nulos
    if (empty($fact) || !is_array($fact)) {
        echo "<!-- ERROR: fact está vacío o no es array -->\n";
        $fact = [
            'id_factura' => '1',
            'nro_factura' => '001-001-0000001',
            'id_cliente' => '12345678',
            'id_empresa' => '1',
            'forma_pago' => '1',
            'timbrado' => '12345678',
            'total' => '1000'
        ];
    }
    
    if (empty($item) || !is_array($item)) {
        echo "<!-- ERROR: item está vacío o no es array -->\n";
        $item = [[
            'codigo' => 'PROD001',
            'descripcion' => 'Producto genérico',
            'precio' => '1000',
            'salida' => '1',
            'descuento' => '0',
            'tipo_iva' => '3',
            'sifen_item_cUniMed' => '77'
        ]];
    }
    
    if (empty($cli) || !is_array($cli)) {
        echo "<!-- ERROR: cli está vacío o no es array -->\n";
        $cli = [
            'documento' => '12345678',
            'cliente' => 'Cliente Genérico',
            'telefono' => '021-123456',
            'email' => 'cliente@ejemplo.com',
            'direccion' => 'Dirección del cliente',
            'departamento' => '11',
            'distrito' => '143',
            'ciudad' => '3344'
        ];
    }
    
    if (empty($emp) || !is_array($emp)) {
        echo "<!-- ERROR: emp está vacío o no es array -->\n";
        $emp = [
            'ruc' => '80000001-7',
            'empresa' => 'Empresa Ejemplo S.A.',
            'cod_act' => '47190',
            'des_act' => 'Venta al por menor en comercios no especializados',
            'telefono' => '021-123456',
            'email' => 'empresa@ejemplo.com',
            'direccion' => 'Dirección de la empresa',
            'departamento' => '11',
            'distrito' => '143',
            'ciudad_id' => '3344',
            'vigencia_ini' => '2024-01-01'
        ];
    }
    
    // DATOS DEL EMISOR (Empresa) - DESDE PARÁMETROS
    $ruc_emisor = (!empty($emp['ruc']) && strlen(trim($emp['ruc'])) > 0) ? trim($emp['ruc']) : '80000001-7';
    $razon_social_emisor = (!empty($emp['empresa']) && strlen(trim($emp['empresa'])) > 0) ? trim($emp['empresa']) : 'Empresa Ejemplo S.A.';
    $nombre_fantasia_emisor = (!empty($emp['empresa']) && strlen(trim($emp['empresa'])) > 0) ? trim($emp['empresa']) : 'Empresa Ejemplo S.A.';
    $actividad_codigo = (!empty($emp['cod_act']) && strlen(trim($emp['cod_act'])) > 0) ? trim($emp['cod_act']) : '47190';
    $actividad_descripcion = (!empty($emp['des_act']) && strlen(trim($emp['des_act'])) > 0) ? trim($emp['des_act']) : 'Venta al por menor en comercios no especializados';
    $telefono_emisor = (!empty($emp['telefono']) && strlen(trim($emp['telefono'])) > 0) ? trim($emp['telefono']) : '021-123456';
    $email_emisor = (!empty($emp['email']) && strlen(trim($emp['email'])) > 0) ? trim($emp['email']) : 'empresa@ejemplo.com';
    $direccion_emisor = (!empty($emp['direccion']) && strlen(trim($emp['direccion'])) > 0) ? trim($emp['direccion']) : 'Dirección de la empresa';
    $emisor_departamento = (!empty($emp['departamento']) && strlen(trim($emp['departamento'])) > 0) ? trim($emp['departamento']) : '11';
    $emisor_distrito = (!empty($emp['distrito']) && strlen(trim($emp['distrito'])) > 0) ? trim($emp['distrito']) : '143';
    $emisor_ciudad = (!empty($emp['ciudad_id']) && strlen(trim($emp['ciudad_id'])) > 0) ? trim($emp['ciudad_id']) : '3344';
    
    // DATOS DEL RECEPTOR (Cliente) - DESDE PARÁMETROS
    $cliente_documento_numero = (!empty($cli['documento']) && strlen(trim($cli['documento'])) > 0) ? trim($cli['documento']) : '12345678';
    $cliente_documento_tipo = obtener_tipo_documento($cliente_documento_numero) == 2 ? 'ruc' : 'ci';
    $cliente_razon_social = (!empty($cli['cliente']) && strlen(trim($cli['cliente'])) > 0) ? trim($cli['cliente']) : 'Cliente Genérico';
    $cliente_telefono = (!empty($cli['telefono']) && strlen(trim($cli['telefono'])) > 0) ? trim($cli['telefono']) : '021-123456';
    $cliente_email = (!empty($cli['email']) && strlen(trim($cli['email'])) > 0) ? trim($cli['email']) : 'cliente@ejemplo.com';
    $cliente_direccion = (!empty($cli['direccion']) && strlen(trim($cli['direccion'])) > 0) ? trim($cli['direccion']) : 'Dirección del cliente';
    $receptor_departamento = (!empty($cli['departamento']) && strlen(trim($cli['departamento'])) > 0) ? trim($cli['departamento']) : '11';
    $receptor_distrito = (!empty($cli['distrito']) && strlen(trim($cli['distrito'])) > 0) ? trim($cli['distrito']) : '143';
    $receptor_ciudad = (!empty($cli['ciudad']) && strlen(trim($cli['ciudad'])) > 0) ? trim($cli['ciudad']) : '3344';
    
    // DATOS DE LA FACTURA - DESDE PARÁMETROS
    $numero_documento = (!empty($fact['nro_factura']) && strlen(trim($fact['nro_factura'])) > 0) ? trim($fact['nro_factura']) : '001-001-0000001';
    list($establecimiento, $expedicion, $secuencia) = explode('-', $numero_documento, 3);
    $condicion_operacion = (!empty($fact['forma_pago']) && strlen(trim($fact['forma_pago'])) > 0) ? trim($fact['forma_pago']) : '1';
    $timbrado = (!empty($fact['timbrado']) && strlen(trim($fact['timbrado'])) > 0) ? trim($fact['timbrado']) : '12345678';
    $fecha_timbrado = (!empty($emp['vigencia_ini']) && strlen(trim($emp['vigencia_ini'])) > 0) ? trim($emp['vigencia_ini']) : '2024-01-01';
    $moneda = 'PYG';
    $tipo_cambio = 1;
    $plazo_credito = 1;
    
    // PRODUCTOS/CONCEPTOS - DESDE PARÁMETROS
    $conceptos = [];
    
    if (!empty($item) && is_array($item)) {
        foreach ($item as $index => $producto) {
            $descripcion = (!empty($producto['descripcion']) && strlen(trim($producto['descripcion'])) > 0) ? trim($producto['descripcion']) : 'Producto sin descripción ' . ($index + 1);
            $cantidad = (!empty($producto['salida']) && is_numeric($producto['salida']) && floatval($producto['salida']) > 0) ? floatval($producto['salida']) : 1;
            $precio_unitario = (!empty($producto['precio']) && is_numeric($producto['precio']) && floatval($producto['precio']) > 0) ? floatval($producto['precio']) : 1000;
            $descuento = (!empty($producto['descuento']) && is_numeric($producto['descuento'])) ? floatval($producto['descuento']) : 0;
            $iva_tipo = (!empty($producto['tipo_iva']) && is_numeric($producto['tipo_iva'])) ? intval($producto['tipo_iva']) : 3;
            $unidad_medida = (!empty($producto['sifen_item_cUniMed']) && is_numeric($producto['sifen_item_cUniMed'])) ? intval($producto['sifen_item_cUniMed']) : 77;
            
            $calculo_iva = calcular_iva($precio_unitario, $cantidad, $descuento, $iva_tipo);
            
            $conceptos[] = [
                'codigo' => (!empty($producto['codigo']) && strlen(trim($producto['codigo'])) > 0) ? trim($producto['codigo']) : sprintf('PROD%03d', $index + 1),
                'descripcion' => $descripcion,
                'cantidad' => $cantidad,
                'unidad_medida' => $unidad_medida,
                'precio_unitario' => $precio_unitario,
                'descuento' => $descuento,
                'iva_tipo' => $iva_tipo,
                'iva_base' => $calculo_iva['iva_base'],
                'iva' => $calculo_iva['iva']
            ];
        }
    }
    
    if (empty($conceptos)) {
        $conceptos = [[
            'codigo' => 'PROD001',
            'descripcion' => 'Producto genérico',
            'cantidad' => 1,
            'unidad_medida' => 77,
            'precio_unitario' => 1000,
            'descuento' => 0,
            'iva_tipo' => 3,
            'iva_base' => 909,
            'iva' => 91
        ]];
    }
    
    // FORMAS DE PAGO
    $formas_pago = [[
        'tipo' => '01',
        'monto' => array_sum(array_column($conceptos, 'precio_unitario')),
        'moneda' => $moneda,
        'tipo_cambio' => 1
    ]];
    
    // Construir payload
    $payload = [
        'emisor' => [
            'ruc' => $ruc_emisor,
            'razon_social' => $razon_social_emisor,
            'nombre_fantasia' => $nombre_fantasia_emisor,
            'actividades_economicas' => [
                [
                    'codigo' => $actividad_codigo,
                    'descripcion' => $actividad_descripcion
                ]
            ],
            'direccion' => [
                'departamento' => $emisor_departamento,
                'distrito' => $emisor_distrito,
                'ciudad' => $emisor_ciudad,
                'direccion' => $direccion_emisor
            ],
            'telefono' => $telefono_emisor,
            'email' => $email_emisor
        ],
        'receptor' => [
            'documento_tipo' => $cliente_documento_tipo,
            'documento_numero' => $cliente_documento_numero,
            'razon_social' => $cliente_razon_social,
            'direccion' => [
                'departamento' => $receptor_departamento,
                'distrito' => $receptor_distrito,
                'ciudad' => $receptor_ciudad,
                'direccion' => $cliente_direccion
            ],
            'telefono' => $cliente_telefono,
            'email' => $cliente_email
        ],
        'conceptos' => $conceptos,
        'formas_pagos' => $formas_pago,
        'ndoc' => $numero_documento,
        'condicion' => $condicion_operacion,
        'timbrado' => $timbrado,
        'fec_timbrado' => $fecha_timbrado,
        'cod_establecimiento' => $establecimiento,
        'cod_expedicion' => $expedicion,
        'moneda' => $moneda,
        'cambio' => $tipo_cambio,
        'plazo' => $plazo_credito
    ];
    
    echo "<!-- DEBUG PAYLOAD CONSTRUIDO EXITOSAMENTE CON DATOS DIRECTOS -->\n";
    return $payload;
}

/**
 * Enviar payload a SIFEN API
 */
function enviar_a_sifen($payload, $url_api = 'http://localhost:8000/factura_final.php') {
    $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url_api,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json_payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json_payload)
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $respuesta = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($respuesta === false || !empty($error)) {
        throw new Exception('Error en curl: ' . $error);
    }
    
    return [
        'http_code' => $http_code,
        'response' => $respuesta,
        'success' => $http_code === 200
    ];
}

/**
 * Guardar payload como archivo JSON
 */
function guardar_payload_json($payload, $nombre_archivo = null) {
    if ($nombre_archivo === null) {
        $nombre_archivo = 'payload_' . date('Y-m-d_H-i-s') . '.json';
    }
    
    $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    $archivo_path = __DIR__ . '/payloads/' . $nombre_archivo;
    
    // Crear directorio si no existe
    $directorio = dirname($archivo_path);
    if (!is_dir($directorio)) {
        mkdir($directorio, 0755, true);
    }
    
    $resultado = file_put_contents($archivo_path, $json_payload);
    
    if ($resultado === false) {
        throw new Exception('Error al guardar archivo JSON: ' . $archivo_path);
    }
    
    return [
        'archivo' => $nombre_archivo,
        'path' => $archivo_path,
        'size' => $resultado
    ];
}
// ============================================================================
// EJECUCIÓN Y EJEMPLO DE USO
// ============================================================================

// ============================================================================
// EJEMPLO DE USO CON SCRIPTCASE
// ============================================================================

/*
Este archivo está configurado para usar con ScriptCase.
Las variables sc_lookup_field ya están definidas arriba:
- $fact: Datos de la factura
- $item: Items/productos de la factura  
- $cli: Datos del cliente
- $emp: Datos de la empresa

Para usar en ScriptCase:

1. Incluir este archivo en su aplicación ScriptCase
2. Ejecutar las consultas sc_lookup_field antes de incluir este archivo
3. El payload se construirá automáticamente con los datos de BD
4. Usar las funciones disponibles:

// Construir payload automáticamente
$payload = construir_payload();

// Guardar como archivo JSON
guardar_payload_json($payload, 'factura_' . $numero_documento . '.json');

// Enviar directamente a SIFEN
$respuesta = enviar_a_sifen($payload);

// Mostrar respuesta
echo "<pre>" . json_encode($respuesta, JSON_PRETTY_PRINT) . "</pre>";

Todos los valores se toman automáticamente de las consultas de BD
con valores por defecto compatibles con SIFEN.
*/

// Manejar solicitud AJAX para envío
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enviar_sifen') {
    header('Content-Type: application/json');
    
    try {
        $payload = construir_payload();
        $resultado = enviar_a_sifen($payload);
        
        echo json_encode([
            'success' => $resultado['success'],
            'http_code' => $resultado['http_code'],
            'response' => $resultado['response'],
            'message' => $resultado['success'] ? 'Payload enviado exitosamente' : 'Error al enviar payload'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit;
}

try {
    // ============================================================================
    // GENERAR EL PAYLOAD SIFEN - USANDO LA NUEVA FUNCIÓN CON DATOS DIRECTOS
    // ============================================================================
    
    echo "<!-- DEBUG: Llamando a construir_payload_con_datos con datos reales -->\n";
    echo "<!-- DEBUG: fact = " . print_r($fact, true) . " -->\n";
    echo "<!-- DEBUG: item = " . print_r($item, true) . " -->\n";
    echo "<!-- DEBUG: cli = " . print_r($cli, true) . " -->\n";
    echo "<!-- DEBUG: emp = " . print_r($emp, true) . " -->\n";
    
    // Usar la nueva función que recibe datos directamente
    $payload = construir_payload_con_datos($fact, $item, $cli, $emp);
    
    echo "<!-- DEBUG: Payload generado exitosamente con datos directos -->\n";
    echo "<!-- DEBUG: Payload size: " . strlen(json_encode($payload)) . " bytes -->\n";
    
    // Respaldo: también generar con la función original para comparación
    echo "<!-- DEBUG: Generando payload con función original para comparación -->\n";
    $payload_original = construir_payload();
    echo "<!-- DEBUG: Payload original size: " . strlen(json_encode($payload_original)) . " bytes -->\n";
    
    // Usar el payload con datos directos como principal
    echo "<!-- DEBUG: Usando payload con datos directos como principal -->\n";
    
    // Guardar archivo JSON
    $archivo_generado = 'payload_generado_' . date('Y-m-d_H-i-s') . '.json';
    guardar_payload_json($payload, $archivo_generado);
    
    // Calcular totales
    $totales = calcular_totales($conceptos);
    
    // Formatear payload para mostrar
    $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    // Respuesta inicial vacía
    $respuesta_formateada = 'Presiona "Enviar a SIFEN" para enviar el payload al API...';
    
    // HTML con estilos mejorados
    echo '
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Payload SIFEN</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 2em;
        }
        .info-section {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .info-card {
            background: white;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #667eea;
        }
        .frames-container {
            display: flex;
            height: 600px;
            border-top: 1px solid #dee2e6;
        }
        .frame {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .frame-header {
            background: #343a40;
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .frame-content {
            flex: 1;
            overflow: auto;
            background: #2d3748;
            color: #e2e8f0;
            font-family: "Courier New", monospace;
            font-size: 12px;
            line-height: 1.4;
        }
        .frame-content pre {
            margin: 0;
            padding: 20px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .payload-frame .frame-header {
            background: #2563eb;
        }
        .response-frame .frame-header {
            background: #059669;
        }
        .response-frame.error .frame-header {
            background: #dc2626;
        }
        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .status-success {
            background: #10b981;
        }
        .status-error {
            background: #ef4444;
        }
        .divider {
            width: 1px;
            background: #dee2e6;
        }
        .enviar-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            min-width: 200px;
        }
        .enviar-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        .enviar-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .mensaje-estado {
            margin-top: 15px;
            padding: 10px;
            border-radius: 6px;
            font-weight: bold;
            min-height: 20px;
        }
        .mensaje-exito {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .mensaje-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .loader {
            animation: pulse 1.5s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        @media (max-width: 768px) {
            .frames-container {
                flex-direction: column;
                height: auto;
            }
            .frame {
                min-height: 300px;
            }
            .divider {
                height: 1px;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🧾 Generador de Payload SIFEN</h1>
            <p>Sistema de Facturación Electrónica</p>
        </div>
        
        <div class="info-section">
            <div class="info-grid">
                <div class="info-card">
                    <strong>📊 Emisor:</strong><br>
                    ' . htmlspecialchars($razon_social_emisor) . '<br>
                    <small>RUC: ' . htmlspecialchars($ruc_emisor) . '</small>
                </div>
                <div class="info-card">
                    <strong>👤 Cliente:</strong><br>
                    ' . htmlspecialchars($cliente_razon_social) . '<br>
                    <small>Doc: ' . htmlspecialchars($cliente_documento_numero) . '</small>
                </div>
                <div class="info-card">
                    <strong>📄 Documento:</strong><br>
                    ' . htmlspecialchars($numero_documento) . '<br>
                    <small>Timbrado: ' . htmlspecialchars($timbrado) . '</small>
                </div>
                <div class="info-card">
                    <strong>💰 Total:</strong><br>
                    Gs. ' . number_format($totales['total_general'], 0, ',', '.') . '<br>
                    <small>Archivo: ' . htmlspecialchars($archivo_generado) . '</small>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button id="enviarBtn" class="enviar-btn">
                    <span id="btnText">🚀 Enviar a SIFEN</span>
                    <span id="btnLoader" class="loader" style="display: none;">⏳ Enviando...</span>
                </button>
                <div id="mensajeEstado" class="mensaje-estado"></div>
            </div>
        </div>
        
        <div class="frames-container">
            <div class="frame payload-frame">
                <div class="frame-header">
                    📤 Payload Enviado al REST API
                </div>
                <div class="frame-content">
                    <pre>' . htmlspecialchars($payload_json) . '</pre>
                </div>
            </div>
            
            <div class="divider"></div>
            
            <div class="frame response-frame" id="responseFrame">
                <div class="frame-header" id="responseHeader">
                    <span class="status-indicator" id="statusIndicator"></span>
                    📥 Respuesta del REST API
                </div>
                <div class="frame-content">
                    <pre id="responseContent">' . htmlspecialchars($respuesta_formateada) . '</pre>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    document.getElementById("enviarBtn").addEventListener("click", function() {
        const btn = this;
        const btnText = document.getElementById("btnText");
        const btnLoader = document.getElementById("btnLoader");
        const mensajeEstado = document.getElementById("mensajeEstado");
        const responseFrame = document.getElementById("responseFrame");
        const responseHeader = document.getElementById("responseHeader");
        const statusIndicator = document.getElementById("statusIndicator");
        const responseContent = document.getElementById("responseContent");
        
        // Deshabilitar botón y mostrar loader
        btn.disabled = true;
        btnText.style.display = "none";
        btnLoader.style.display = "inline";
        mensajeEstado.textContent = "";
        mensajeEstado.className = "mensaje-estado";
        
        // Actualizar frame de respuesta
        responseFrame.className = "frame response-frame";
        statusIndicator.className = "status-indicator";
        responseContent.textContent = "Enviando payload al API SIFEN...";
        
        // Crear FormData para envío
        const formData = new FormData();
        formData.append("action", "enviar_sifen");
        
        // Enviar solicitud AJAX
        fetch(window.location.href, {
            method: "POST",
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Restaurar botón
            btn.disabled = false;
            btnText.style.display = "inline";
            btnLoader.style.display = "none";
            
            if (data.success) {
                // Éxito
                mensajeEstado.textContent = "✅ " + data.message;
                mensajeEstado.className = "mensaje-estado mensaje-exito";
                responseFrame.className = "frame response-frame";
                statusIndicator.className = "status-indicator status-success";
                responseHeader.innerHTML = "<span class=\"status-indicator status-success\"></span>📥 Respuesta del REST API (Éxito)";
                
                // Formatear respuesta
                try {
                    const responseObj = JSON.parse(data.response);
                    responseContent.textContent = JSON.stringify(responseObj, null, 2);
                } catch (e) {
                    responseContent.textContent = data.response;
                }
            } else {
                // Error
                mensajeEstado.textContent = "❌ " + data.message;
                mensajeEstado.className = "mensaje-estado mensaje-error";
                responseFrame.className = "frame response-frame error";
                statusIndicator.className = "status-indicator status-error";
                responseHeader.innerHTML = "<span class=\"status-indicator status-error\"></span>📥 Respuesta del REST API (Error)";
                responseContent.textContent = "HTTP " + data.http_code + "\n" + data.response;
            }
        })
        .catch(error => {
            // Error de conexión
            btn.disabled = false;
            btnText.style.display = "inline";
            btnLoader.style.display = "none";
            
            mensajeEstado.textContent = "❌ Error de conexión: " + error.message;
            mensajeEstado.className = "mensaje-estado mensaje-error";
            responseFrame.className = "frame response-frame error";
            statusIndicator.className = "status-indicator status-error";
            responseHeader.innerHTML = "<span class=\"status-indicator status-error\"></span>📥 Respuesta del REST API (Error de Conexión)";
            responseContent.textContent = "Error de conexión: " + error.message;
        });
    });
    </script>
</body>
</html>';
    
} catch (Exception $e) {
    echo '
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Generador de Payload SIFEN</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .error-container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; }
        .error-header { color: #dc2626; border-bottom: 2px solid #dc2626; padding-bottom: 10px; margin-bottom: 20px; }
        .error-message { background: #fef2f2; border: 1px solid #fecaca; padding: 15px; border-radius: 6px; color: #991b1b; }
    </style>
</head>
<body>
    <div class="error-container">
        <h1 class="error-header">❌ Error en el Generador de Payload</h1>
        <div class="error-message">
            <strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
        </div>
        <p>Verifique que todas las variables estén correctamente configuradas y que las consultas de base de datos sean válidas.</p>
    </div>
</body>
</html>';
}
