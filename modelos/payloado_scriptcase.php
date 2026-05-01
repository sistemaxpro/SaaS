// =====================================
// OBTENER DATOS DESDE SCRIPTCASE
// =====================================
sc_lookup_field(fact, "SELECT * FROM factura_ventas WHERE id_factura = '[id_factura]'");
$fact = $fact[0];

sc_lookup_field(emp, "SELECT * FROM serproc1.empresa WHERE id_empresa = '[id_empresa]'");
$emp = $emp[0];

$id_cliente = $fact['id_cliente'];

sc_lookup_field(cli, "SELECT * FROM clientes WHERE id = '$id_cliente'");
$cli = $cli[0];

sc_lookup_field(cja, "SELECT * FROM cajas WHERE id_caja = '[id_caja]'");
$cja = $cja[0];


// =====================================
// CONFIGURACIÓN SERVIDOR SIFEN
// =====================================
$SIFEN_SERVER_URL = 'http://localhost:8000/factura_xml_cdc.php';
$SIFEN_TIMEOUT     = 30;


sc_lookup(item, "
    SELECT
        codigo,
        descripcion,
        precio,
        salida      AS cantidad,
        tipo_iva,
        0           AS descuento,
        100         AS proporcion_iva,
        '77'        AS unidad_medida
    FROM extracto_productos
    WHERE idfactura = '[id_factura]'
");

// =====================================
// ARMAR CONCEPTOS
// =====================================
$conceptos = [];
if (is_array({item}) && count({item}) > 0) {

    // DEBUG opcional para ver la forma del resultado
    // echo "<pre>"; print_r({item}); echo "</pre>";

    foreach ({item} as $row) {
        $tipo_iva = (int)$row[4];
        // mapa: 3->1%  | 1->3% | otros->2%  (ajusta si tu lógica es distinta)
        $tasa_iva = ($tipo_iva == 3 ? 1 : ($tipo_iva == 1 ? 3 : 2));

        $conceptos[] = [
            'codigo'         => (string)$row[0],
            'descripcion'    => (string)$row[1],
            'precio'         => (float)$row[2],
            'cantidad'       => (float)$row[3],
            'tasa_iva'       => $tasa_iva,
            'descuento'      => 0,
            'proporcion_iva' => 100,
            'unidad_medida'  => 77,
        ];
    }
}


// =====================================
// DEBUG: MOSTRAR JSON DE CONCEPTOS
// =====================================
echo "<pre>";
echo json_encode($conceptos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "</pre>";
// =====================================
// VARIABLES AUXILIARES
// =====================================

// RUC y DV
$ruc = $emp['ruc'];
$dv  = '';
if (strpos($ruc, '-') !== false) {
    list($ruc, $dv) = explode('-', $emp['ruc']);
}

// Establecimiento, expedición y secuencia
$estab = $exped = $secuencia = '';
if (!empty($fact['nro_factura']) && strpos($fact['nro_factura'], '-') !== false) {
    list($estab, $exped, $secuencia) = explode('-', $fact['nro_factura']);
} else if (empty($fact['nro_factura'])) {
    // Calcular la secuencia automáticamente
    $estab = '001';
    $exped = '001';
    
    // Consultar la última secuencia usada
	$timbrado = $emp['timbrado'];
    sc_lookup(ultima_secuencia, "SELECT MAX(CAST(SUBSTRING_INDEX(nro_factura, '-', -1) AS UNSIGNED)) as max_secuencia FROM factura_ventas WHERE nro_factura IS NOT NULL AND nro_factura != '' AND nro_factura LIKE '%-%-%' AND timbrado = $timbrado");
    
    $max_secuencia = 0;
    if (!empty({ultima_secuencia}) && isset({ultima_secuencia}[0]['max_secuencia'])) {
        $max_secuencia = (int){ultima_secuencia}[0]['max_secuencia'];
    }
    
    // Incrementar la secuencia
    $nueva_secuencia = $max_secuencia + 1;
    $secuencia = str_pad($nueva_secuencia, 6, '0', STR_PAD_LEFT);
    
    // Actualizar el número de factura en la base de datos
    $nuevo_nro_factura = $estab . '-' . $exped . '-' . $secuencia;
    sc_exec_sql("UPDATE factura_ventas SET nro_factura = '$nuevo_nro_factura' WHERE id_factura = '".[id_factura]."'");

    
// Actualizar la variable local
$fact['nro_factura'] = $nuevo_nro_factura;
list($estab, $exped, $secuencia) = explode('-', $fact['nro_factura']);
$ndoc = (int)$secuencia;
}
// Moneda
$fact_id_mon = $fact['id_moneda'];
switch ($fact_id_mon) {
    case 1:
        $mon = 'PYG';
        break;
    case 2:
        $mon = 'USD';
        break;
    case 3:
        $mon = 'BRL';
        break;
    default:
        $mon = 'PYG';
        break;
}

// Total
$total_factura = (float)($fact['total'] ?? 0);

// =====================================
// ARMAR PAYLOAD
// =====================================
$payload = [
    'emisor' => [
        'ruc'                => $ruc,
        'dv'                 => $dv,
        'razon_social'       => $emp['empresa'],
        'tipo_contribuyente' => '2',
        'ciudad'             => 1,
        'direccion'          => $emp['direccion'],
        'telefono'           => $emp['telefono'],
        'email'              => $emp['email'],
        'act_eco'            => $emp['cod_act'],
        'act_eco_desc'       => $emp['des_act'],
    ],
    'receptor' => [
        'documento'    => $cli['documento'],
        'tipo_doc'     => 'ruc',
        'razon_social' => $cli['cliente'] ?? "Cliente sin nombre",
        'direccion'    => $cli['direccion'] ?? "no determnado",
        'telefono'     => $cli['telefono'] ?? "021200200",
        'email'        => $cli['email'] ?? "soporte@sistemax.com.py",
        'ciudad'       => 1,
    ],
    'conceptos' => $conceptos,
    'factura' => [
        'ndoc'               => $ndoc,
        'condicion'          => 'contado',
        'timbrado'           => $emp['timbrado'],
        'fec_timbrado'       => $emp['vigencia_ini'],
        'cod_establecimiento'=> $estab ?: '001',
        'cod_expedicion'     => $exped ?: '001',
        'moneda'             => $mon,
        'cambio'             => 1,
    ],
    'formas_pago' => [[
        'tipo_pago' => 1,
        'monto'     => $total_factura,
        'moneda'    => $mon,
        'cambio'    => 1,
    ]],
    'sifen_config' => [
        'certificado' => 'certificados/'.$ruc.'.p12',
        'password'    => $emp['cert_pass'],
        'ambiente'    => 'prod',
        'idc'         => $emp['id_csc'],
        'csc'         => $emp['csc'],
    ],
];

// =====================================
// DEBUG: MOSTRAR PAYLOAD ENVIADO AL REST API
// =====================================
echo "<h3>===== PAYLOAD ENVIADO AL REST API =====</h3>";
echo "<pre>";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "</pre>";
echo "<hr>";

// =====================================
// ENVIAR A API SIFEN
// =====================================
$ch = curl_init($SIFEN_SERVER_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_TIMEOUT, $SIFEN_TIMEOUT);

$response = curl_exec($ch);
if ($response === false) {
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'error'=>curl_error($ch)], JSON_UNESCAPED_UNICODE);
    exit;
}
curl_close($ch);

header('Content-Type: application/json');
echo $response;

// =====================================
// ACTUALIZAR FACTURA_VENTAS CON RESPUESTA SIFEN
// =====================================
$response_data = json_decode($response, true);

if ($response_data && isset($response_data['success']) && $response_data['success']) {
    // Extraer datos de la respuesta desde el campo 'datos'
    $datos = isset($response_data['datos']) ? $response_data['datos'] : [];
    
    $cdc = isset($datos['cdc']) ? $datos['cdc'] : '';
    $qr_url = isset($datos['qr_url']) ? $datos['qr_url'] : '';
    $qr_image = isset($datos['qr_image']) ? $datos['qr_image'] : '';
    $fecha_generacion = isset($datos['fecha_generacion']) ? $datos['fecha_generacion'] : '';
    $xml_original = isset($datos['xml']) ? addslashes($datos['xml']) : '';
    $xml_firmado = isset($datos['xml_firmado']) ? addslashes($datos['xml_firmado']) : '';
    $de_id = isset($datos['de_id']) ? $datos['de_id'] : '';
    $protocolo = isset($datos['protocolo']) ? $datos['protocolo'] : '';
    $estado = isset($datos['estado']) ? $datos['estado'] : 'AUTORIZADO';
    $mensaje = isset($datos['mensaje']) ? addslashes($datos['mensaje']) : 'Factura procesada correctamente';
    
    // Preparar campos para actualización
    $fecha_actual = date('Y-m-d H:i:s');
    
    // Construir query de actualización con mapeo correcto
    $update_query = "UPDATE factura_ventas SET 
        fecha_envio_sifen = '$fecha_actual',
        estado_sifen = '$estado',
        de_id_sifen = '$de_id',
        prot_aut_sifen = '$protocolo',
        mensaje_sifen = '$mensaje',
        hash_cdc = '$cdc',
        qr_sifen = '$qr_url',
        qr = '$qr_image',
        fecha_emision = '$fecha_generacion',
        xml_original = '$xml_original',
        xml_firmado = '$xml_firmado',
        estado_electronico = 'AUTORIZADO',
        intentos_transmision = intentos_transmision + 1
        WHERE id_factura = '[id_factura]'";
    
    // Ejecutar actualización
    sc_exec_sql($update_query);
    
} else {
    // En caso de error, actualizar solo el intento y estado
    $error_msg = isset($response_data['error']) ? addslashes($response_data['error']) : 'Error desconocido';
    $fecha_actual = date('Y-m-d H:i:s');
    
    $update_error_query = "UPDATE factura_ventas SET 
        fecha_envio_sifen = '$fecha_actual',
        estado_sifen = 'ERROR',
        mensaje_sifen = '$error_msg',
        estado_electronico = 'RECHAZADO',
        intentos_transmision = intentos_transmision + 1
        WHERE id_factura = '[id_factura]'";
    
    sc_exec_sql($update_error_query);
}