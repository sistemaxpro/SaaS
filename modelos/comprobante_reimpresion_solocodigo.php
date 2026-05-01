<?php
/* =========================
 * PHP: Helpers / Config / Compatibility
 * ========================= */

// Error logging setup
if (!is_dir(__DIR__ . '/debug')) {
    @mkdir(__DIR__ . '/debug', 0777, true);
}

function debug_log_factura($msg) {
    $logFile = __DIR__ . '/debug/factura_debug.log';
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $msg . "\n", FILE_APPEND);
}

// Database Connection (Standalone)
if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../config/bootstrap.php';
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$masterDb = defined('MASTER_DB') ? MASTER_DB : 'serproc1';
$id_empresa_session = $_SESSION['id_empresa'] ?? 169;

try {
    $pdo = Database::getMasterConnection();
    
    // Si no tenemos dbu en sesión, intentar derivar la DB de la empresa
    $stmtDB = $pdo->prepare("SELECT dbase FROM $masterDb.empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa_session]);
    $dbNameActual = $stmtDB->fetchColumn();
} catch(Exception $e) {
    debug_log_factura("Error de conexión DB: " . $e->getMessage());
    // No cortamos ejecución por si es una llamada parcial, pero muchas cosas fallarán.
}

/**
 * Scriptcase Macros Emulation
 */
function traedatos($tabla, $campo_retorno, $campo_llave, $llave) {
    global $pdo;
    try {
        $sql = "SELECT $campo_retorno FROM $tabla WHERE $campo_llave = :llave LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':llave' => $llave]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
}

function id_unico() {
    return uniqid() . bin2hex(random_bytes(4));
}

function sql_escape($val) {
    return addslashes((string)$val);
}

function sc_format_num(&$val, $sep_dec, $sep_mil, $precision, $moneda_pos, $moneda_simb, $moneda) {
    $val = number_format((float)$val, $precision, $sep_dec, $sep_mil);
    if ($moneda_pos == 'S' || $moneda_pos == '1') {
        $val = $moneda . ' ' . $val;
    }
}

function sc_date_conv($date, $from, $to) {
    if (empty($date)) return '';
    $d = date_create($date);
    if (!$d) return $date;
    if ($to == 'dd/mm/aaaa') return date_format($d, 'd/m/Y');
    return date_format($d, 'Y-m-d');
}

/**
 * Scriptcase Macros Emulation
 */
class SC_Select_Result {
    private $stmt;
    public $fields;
    public $EOF = false;
    public function __construct($stmt) {
        $this->stmt = $stmt;
        $this->MoveNext();
    }
    public function MoveNext() {
        $this->fields = $this->stmt->fetch(PDO::FETCH_ASSOC);
        $this->EOF = ($this->fields === false);
    }
    public function Close() {}
}

function sc_lookup(&$arr, $sql) {
    global $pdo;
    try {
        $stmt = $pdo->query($sql);
        $arr = $stmt->fetchAll(PDO::FETCH_NUM);
    } catch (Exception $e) {
        $arr = false;
        debug_log_factura("sc_lookup error: " . $e->getMessage() . " SQL: " . $sql);
    }
}

function sc_exec_sql($sql) {
    global $pdo;
    try {
        $pdo->exec($sql);
    } catch (Exception $e) {
        debug_log_factura("sc_exec_sql error: " . $e->getMessage() . " SQL: " . $sql);
    }
}

function sc_select(&$rs, $sql) {
    global $pdo;
    try {
        $stmt = $pdo->query($sql);
        $rs = new SC_Select_Result($stmt);
    } catch (Exception $e) {
        $rs = false;
        debug_log_factura("sc_select error: " . $e->getMessage() . " SQL: " . $sql);
    }
}

function numtoletras($numero) {
    // Implementación simplificada
    return "TRESCIENTOS MIL GUARANIES"; // TODO: Implementar real si es crítico, o dejar que falle graciosamente
}

$FALCON_CDN = 'https://prium.github.io/falcon/v3.19.0';

if (!defined('SIFEN_PHP_SIFEN_PATH')) {
    // ScriptCase puede desplegar `_lib` en `app/_lib` o `app/smx/_lib` según el entorno.
    $sdkCandidates = [
        // Nueva ubicación indicada: `.../app/smx/_lib/php-sifen/...`
        __DIR__ . '/../_lib/php-sifen3/src/php-sifen.php',
        __DIR__ . '/../_lib/php-sifen3/php-sifen.php',
        // Variante en `app/_lib/...`3
        __DIR__ . '/../../_lib/php-sifen3/src/php-sifen.php',
        __DIR__ . '/../../_lib/php-sifen3/php-sifen.php',
    ];
    $sdkResolved = null;
    foreach ($sdkCandidates as $candidate) {
        $resolved = realpath($candidate);
        if (is_string($resolved) && is_file($resolved)) {
            $sdkResolved = $resolved;
            break;
        }
    }
    define('SIFEN_PHP_SIFEN_PATH', $sdkResolved ?: $sdkCandidates[0]);
}

function pad3($value)
{
    return str_pad((string) $value, 3, '0', STR_PAD_LEFT);
}

function pad7($value)
{
    return str_pad((string) $value, 7, '0', STR_PAD_LEFT);
}

/**
 * Calcula la numeración de factura con el formato "###-###-#######"
 * tomando los datos desde `cajas` (timbrado, factura_1, factura_2, factura_3).
 *
 * Retorna un array con:
 * - timbrado
 * - nro_factura (###-###-#######)
 * - establecimiento (###)
 * - expedicion (###)
 * - numero (#######)
 */
function calcular_numeracion_factura_caja($id_caja)
{
    $id_caja = (string) $id_caja;

    $timbradoRaw = (string) traedatos('cajas', 'timbrado', 'id_caja', $id_caja);
    $factura1Raw = (string) traedatos('cajas', 'factura_1', 'id_caja', $id_caja);
    $factura2Raw = (string) traedatos('cajas', 'factura_2', 'id_caja', $id_caja);
    $factura3Raw = (string) traedatos('cajas', 'factura_3', 'id_caja', $id_caja);

    $timbrado = trim($timbradoRaw);
    $establecimiento = pad3(preg_replace('/\\D+/', '', $factura1Raw));
    $expedicion = pad3(preg_replace('/\\D+/', '', $factura2Raw));
    $numero = pad7(preg_replace('/\\D+/', '', $factura3Raw));

    return [
        'timbrado' => $timbrado,
        'nro_factura' => $establecimiento . '-' . $expedicion . '-' . $numero,
        'establecimiento' => $establecimiento,
        'expedicion' => $expedicion,
        'numero' => $numero,
    ];
}

function factura_nro_es_unica($nro_factura, $id_factura, $timbrado)
{
    $nro_factura = trim((string) $nro_factura);
    if ($nro_factura === '') {
        return false;
    }
    $id_factura = (string) $id_factura;
    $timbrado = trim((string) $timbrado);
    if ($timbrado === '') {
        return false;
    }

    $sql = "SELECT id_factura
            FROM factura_ventas
            WHERE nro_factura = '" . sql_escape($nro_factura) . "'
              AND timbrado = '" . sql_escape($timbrado) . "'
              AND id_factura <> '" . sql_escape($id_factura) . "'
            LIMIT 1";
    sc_lookup($dup, $sql);
    return empty($dup[0][0]);
}

/**
 * Reserva la numeración para una factura:
 * - Lee `cajas` y usa `factura_3` como contador (#######).
 * - Incrementa `cajas.factura_3` para la próxima.
 * - Verifica que `factura_ventas.nro_factura` no se repita; si se repite, avanza el contador hasta encontrar libre.
 * - Persiste `factura_ventas.nro_factura` y `factura_ventas.timbrado`.
 */
function reservar_nro_factura_desde_caja($id_factura, $id_caja)
{
    $id_factura = (string) $id_factura;
    $id_caja = (string) $id_caja;

    // Si ya tiene numeración y no está duplicada, respetar.
    sc_lookup_field($fact_nro, "SELECT nro_factura, timbrado FROM factura_ventas WHERE id_factura = '" . sql_escape($id_factura) . "' LIMIT 1");
    $actual = '';
    $actual_timbrado = '';
    if (!empty($fact_nro) && isset($fact_nro[0]['nro_factura'])) {
        $actual = trim((string) $fact_nro[0]['nro_factura']);
    }
    if (!empty($fact_nro) && isset($fact_nro[0]['timbrado'])) {
        $actual_timbrado = trim((string) $fact_nro[0]['timbrado']);
    }
    if ($actual !== '' && $actual_timbrado !== '' && factura_nro_es_unica($actual, $id_factura, $actual_timbrado)) {
        return [
            'timbrado' => $actual_timbrado,
            'nro_factura' => $actual,
        ];
    }

    // Reservar dentro de una transacción para minimizar repeticiones.
    sc_exec_sql('START TRANSACTION');
    try {
        $intentos = 0;
        $calc = null;

        while ($intentos < 50) {
            $intentos++;
            $sqlCaja = "SELECT timbrado, factura_1, factura_2, factura_3
                        FROM cajas
                        WHERE id_caja = '" . sql_escape($id_caja) . "'
                        FOR UPDATE";
            sc_lookup($caja, $sqlCaja);
            if (empty($caja[0][0])) {
                throw new RuntimeException('No se encontró la caja para reservar numeración.');
            }

            $timbrado = trim((string) $caja[0][0]);
            $factura1Raw = (string) $caja[0][1];
            $factura2Raw = (string) $caja[0][2];
            $factura3Raw = (string) $caja[0][3];

            $establecimiento = pad3(preg_replace('/\\D+/', '', $factura1Raw));
            $expedicion = pad3(preg_replace('/\\D+/', '', $factura2Raw));

            // La numeración incrementa por timbrado (y por establecimiento/expedición).
            // Si el timbrado cambia, el contador debe arrancar según lo ya emitido con ese timbrado.
            $sqlMax = "SELECT MAX(CAST(RIGHT(nro_factura, 7) AS UNSIGNED)) AS max_nro
                       FROM factura_ventas
                       WHERE timbrado = '" . sql_escape($timbrado) . "'
                         AND nro_factura LIKE '" . sql_escape($establecimiento . '-' . $expedicion . '-') . "%'";
            sc_lookup($maxn, $sqlMax);
            $maxUsado = 0;
            if (!empty($maxn[0][0])) {
                $maxUsado = (int) $maxn[0][0];
            }

            $numeroIntCaja = (int) preg_replace('/\\D+/', '', $factura3Raw);
            if ($numeroIntCaja <= 0) {
                $numeroIntCaja = 1;
            }

            $numeroInt = max($numeroIntCaja, $maxUsado + 1);
            $numero = pad7((string) $numeroInt);
            $nro = $establecimiento . '-' . $expedicion . '-' . $numero;

            if (!factura_nro_es_unica($nro, $id_factura, $timbrado)) {
                // Ya existe: avanzar contador y reintentar.
                $next = $numeroInt + 1;
                sc_exec_sql("UPDATE cajas
                             SET factura_3 = '" . sql_escape((string) $next) . "'
                             WHERE id_caja = '" . sql_escape($id_caja) . "'");
                continue;
            }

            // Reservar: incrementar contador para el siguiente.
            $next = $numeroInt + 1;
            sc_exec_sql("UPDATE cajas
                         SET factura_3 = '" . sql_escape((string) $next) . "'
                         WHERE id_caja = '" . sql_escape($id_caja) . "'");

            // Persistir en factura_ventas.
            $timbradoSet = $timbrado !== '' ? (", timbrado = '" . sql_escape($timbrado) . "'") : '';
            sc_exec_sql("UPDATE factura_ventas
                         SET nro_factura = '" . sql_escape($nro) . "'" . $timbradoSet . "
                         WHERE id_factura = '" . sql_escape($id_factura) . "'");

            $calc = [
                'timbrado' => $timbrado,
                'nro_factura' => $nro,
                'establecimiento' => $establecimiento,
                'expedicion' => $expedicion,
                'numero' => $numero,
            ];
            break;
        }

        if ($calc === null) {
            throw new RuntimeException('No se pudo reservar una numeración libre (demasiados intentos).');
        }

        sc_exec_sql('COMMIT');
        return $calc;
    } catch (Throwable $e) {
        sc_exec_sql('ROLLBACK');
        throw $e;
    }
}

function sifen_ndoc_desde_nro_factura($nro_factura)
{
    $nro_factura = trim((string) $nro_factura);
    if ($nro_factura === '') {
        return null;
    }
    $partes = explode('-', $nro_factura);
    if (count($partes) < 3) {
        return null;
    }
    $num = preg_replace('/\\D+/', '', (string) $partes[2]);
    return $num !== '' ? $num : null;
}

if (!function_exists('sql_escape')) {
    function sql_escape($value)
    {
        return str_replace("'", "''", (string) $value);
    }
}

if (!function_exists('debug_log_factura')) {
    function debug_log_factura($message)
    {
        error_log('DEBUG: ' . $message . ' - ' . date('Y-m-d H:i:s'), 3, 'debug/factura_debug.log');
    }
}

function sifen_ruc_sin_dv($ruc)
{
    $ruc = (string) $ruc;
    if (strpos($ruc, '-') !== false) {
        $parts = explode('-', $ruc, 2);
        return preg_replace('/\\D+/', '', (string) $parts[0]);
    }
    return preg_replace('/\\D+/', '', $ruc);
}

function sifen_lib_root()
{
    return dirname(dirname((string) constant('SIFEN_PHP_SIFEN_PATH')));
}

function sifen_cert_path(array $emp)
{
    $rucBase = sifen_ruc_sin_dv($emp['ruc'] ?? '');
    $expected = $rucBase ? (sifen_lib_root() . '/certificados/' . $rucBase . '.p12') : null;
    $GLOBALS['SIFEN_CERT_EXPECTED'] = $expected;
    $candidates = [
        $expected,
        $emp['cert_path'] ?? null,
        $emp['cert_file'] ?? null,
        $emp['certificado'] ?? null,
        __DIR__ . '/noenviar/AKTIV.p12',
        __DIR__ . '/AKTIV.p12',
    ];

    foreach ($candidates as $path) {
        if (!empty($path) && is_string($path) && is_file($path)) {
            return $path;
        }
    }

    return null;
}

function sifen_extraer_xml_desde_payload($payload)
{
    if (!is_string($payload)) {
        return null;
    }

    $payload = trim($payload);
    if ($payload === '' || substr($payload, 0, 1) === '<') {
        return null;
    }

    $payloadNoWs = preg_replace('/\\s+/', '', $payload);
    $bin = base64_decode((string) $payloadNoWs, true);
    if (!is_string($bin) || $bin === '') {
        return null;
    }

    // ZIP: "PK"
    if (substr($bin, 0, 2) === 'PK') {
        $tmp = tempnam(sys_get_temp_dir(), 'sifen_zip_');
        if (!is_string($tmp) || $tmp === '' || file_put_contents($tmp, $bin) === false) {
            return null;
        }

        $xml = null;
        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            $xmlInZip = $zip->getFromName('xml.xml');
            if ($xmlInZip === false) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $name = is_array($stat) ? ($stat['name'] ?? null) : null;
                    if (is_string($name) && $name !== '' && preg_match('/\\.xml$/i', $name)) {
                        $xmlInZip = $zip->getFromIndex($i);
                        break;
                    }
                }
            }
            if ($xmlInZip !== false) {
                $candidate = trim((string) $xmlInZip);
                if ($candidate !== '') {
                    $xml = (string) $xmlInZip;
                }
            }
            $zip->close();
        }

        @unlink($tmp);
        return $xml;
    }

    // A veces puede venir XML en base64.
    if (substr(ltrim($bin), 0, 1) === '<') {
        return (string) $bin;
    }

    return null;
}

function sifen_emitir_factura_venta(array $emp, array $cli, array $conceptos, $ndoc, $condicion, $cod_establecimiento, $cod_expedicion)
{
    $sdkPath = (string) constant('SIFEN_PHP_SIFEN_PATH');
    if (!is_file($sdkPath)) {
        $alt1 = realpath(__DIR__ . '/../_lib/php-sifen/src/php-sifen.php') ?: (__DIR__ . '/../_lib/php-sifen/src/php-sifen.php');
        $alt2 = realpath(__DIR__ . '/../../_lib/php-sifen/src/php-sifen.php') ?: (__DIR__ . '/../../_lib/php-sifen/src/php-sifen.php');
        $alt3 = realpath(__DIR__ . '/../_lib/libraries/grp/php-sifen/src/php-sifen.php') ?: (__DIR__ . '/../_lib/libraries/grp/php-sifen/src/php-sifen.php');
        throw new RuntimeException("No se encontró `php-sifen.php`. Probado: {$sdkPath} | Alternativas: {$alt1} | {$alt2} | {$alt3}");
    }
    require_once $sdkPath;

    $certPath = sifen_cert_path($emp);
    if (empty($certPath)) {
        $expected = $GLOBALS['SIFEN_CERT_EXPECTED'] ?? null;
        $extra = $expected ? (" Cert buscado: " . $expected) : ' Cert buscado: (no se pudo derivar desde el RUC).';
        throw new RuntimeException('No se encontró el certificado .p12.' . $extra);
    }
    if (empty($emp['cert_pass'])) {
        throw new RuntimeException('Falta cert_pass para el certificado.');
    }

    $env = (($emp['ambiente_sifen'] ?? 1) == 2) ? 'prod' : 'test';
    $key = new \sifen\KEY($certPath, (string) $emp['cert_pass']);
    $sifen = new \sifen\Sifen($env, $key);
    $sifen->csc = (string) ($emp['csc'] ?? $sifen->csc);
    $sifen->idc = (string) ($emp['id_csc'] ?? $sifen->idc);

    $emisor = new \sifen\Emisor([
        'ruc' => \sifen\Sifen::getRuc($emp['ruc'] ?? ''),
        'dv' => (string) ($emp['dv'] ?? \sifen\Sifen::getRuc($emp['ruc'] ?? '', 'dv')),
        'razon_social' => (string) ($emp['empresa'] ?? ''),
        'tipo_contribuyente' => (string) ($emp['tipo_contribuyente'] ?? '2'),
        'ciudad' => (int) ($emp['ciudad_id'] ?? 1),
        'direccion' => (string) ($emp['direccion'] ?? ''),
        'telefono' => (string) ($emp['telefono'] ?? ''),
        'email' => (string) ($emp['email'] ?? ''),
        'act_eco' => (string) ($emp['cod_act'] ?? ''),
        'act_eco_desc' => (string) ($emp['des_act'] ?? ''),
    ]);

    $doc = (string) ($cli['numero'] ?? $cli['ruc'] ?? '');
    $tipoDoc = (strpos($doc, '-') !== false) ? 'ruc' : ((strlen(preg_replace('/\\D+/', '', $doc)) >= 7) ? 'ci' : 'ruc');
    $receptor = new \sifen\Receptor([
        'documento' => (string) $doc,
        'tipo_doc' => $tipoDoc,
        'razon_social' => (string) ($cli['nombre'] ?? ''),
        'direccion' => (string) (trim($cli['direccion'] ?? '') ?: 'Sin dirección'),
        'telefono' => (string) (trim($cli['telefono'] ?? '') ?: '000000000'),
        'email' => (string) (trim($cli['email'] ?? '') ?: 'sin-email@example.com'),
        'ciudad' => (int) ($cli['ciudad'] ?? 1),
    ]);

    $conceptosObj = [];
    foreach ($conceptos as $concepto) {
        $conceptosObj[] = new \sifen\Concepto($concepto);
    }

    $totalNeto = 0;
    foreach ($conceptosObj as $conceptoObj) {
        $totalNeto += ($conceptoObj->precio - $conceptoObj->descuento) * $conceptoObj->cantidad;
    }

    $tipoPago = ($condicion === 'contado') ? 1 : 99;
    $formasPago = [new \sifen\FormasPago([
        'tipo_pago' => $tipoPago,
        'monto' => $totalNeto,
        'moneda' => 'PYG',
        'cambio' => 1,
    ])];

    $factura = new \sifen\Factura(\sifen\DTE::FACT_VENTA, [
        'emisor' => $emisor,
        'receptor' => $receptor,
        'formas_pago' => $formasPago,
        'conceptos' => $conceptosObj,
        // IMPORTANTE: `ndoc` (7 dígitos) forma parte del CDC, por lo que debe coincidir con `nro_factura` impreso.
        'ndoc' => (string) $ndoc,
        'condicion' => (string) ($condicion ?: 'contado'),
        'timbrado' => (string) ($emp['timbrado'] ?? ''),
        'fec_timbrado' => (string) ($emp['vigencia_ini'] ?? ''),
        'cod_establecimiento' => pad3($cod_establecimiento),
        'cod_expedicion' => pad3($cod_expedicion),
        'moneda' => 'PYG',
        'cambio' => 1,
    ]);

    $sifen->agregarFactura($factura);
    $xmlRequest = $sifen->buildXML(true);
    $respuesta = $sifen->enviar($xmlRequest);

    // Preferimos capturar el XML firmado directamente del request (sin depender de xmlsigned.xml en disco),
    // porque ScriptCase puede ejecutar sin permisos de escritura o con CWD variable.
    $xmlFirmado = null;
    $xmlEnvelope = ($xmlRequest instanceof SimpleXMLElement) ? $xmlRequest->asXML() : null;
    if (is_string($xmlEnvelope) && $xmlEnvelope !== '') {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        if (@$dom->loadXML($xmlEnvelope)) {
            $xpath = new DOMXPath($dom);
            $xDE = $xpath->query('//*[local-name()="xDE"]')->item(0);
            if ($xDE instanceof DOMElement) {
                if ($xDE->hasChildNodes()) {
                    $inner = '';
                    foreach ($xDE->childNodes as $child) {
                        $inner .= $dom->saveXML($child);
                    }
                    $inner = trim($inner);
                    $xmlFirmado = ($inner !== '') ? $inner : null;
                } else {
                    $text = trim((string) $xDE->textContent);
                    $xmlFirmado = ($text !== '') ? $text : null;
                }
            }
        }
        // Si el payload no es XML y parece base64, puede ser un ZIP (caso lote: xDE = base64(zip(xml.xml))).
        if (is_string($xmlFirmado)) {
            $extraido = sifen_extraer_xml_desde_payload($xmlFirmado);
            if (is_string($extraido) && trim($extraido) !== '') {
                $xmlFirmado = $extraido;
            }
        }
        if ($xmlFirmado === null) {
            // Fallback: guardar el sobre SOAP completo.
            $xmlFirmado = $xmlEnvelope;
        }
    }

    // Fallback adicional: si existe xmlsigned.xml en ubicaciones conocidas, usarlo.
    if ($xmlFirmado === null) {
        $xmlSignedCandidates = array_filter([
            sifen_lib_root() . '/xmlsigned.xml',
            __DIR__ . '/../_lib/php-sifen/xmlsigned.xml',
            getcwd() ? (getcwd() . '/xmlsigned.xml') : null,
            __DIR__ . '/xmlsigned.xml',
        ]);
        foreach ($xmlSignedCandidates as $candidate) {
            if (is_file($candidate)) {
                $contents = file_get_contents($candidate);
                if ($contents !== false && $contents !== '') {
                    $xmlFirmado = $contents;
                    break;
                }
            }
        }
    }

    if (($respuesta['status'] ?? null) !== 'ok') {
        $err = $respuesta['error'] ?? $respuesta['msg'] ?? 'Error SIFEN';
        throw new RuntimeException((string) $err);
    }

    $protConsLote = $sifen->getProtConsLote();
    $loteConsulta = null;
    $msgResult = $sifen->getMsgRes();
    $intentosQuery = 0;
    $maxIntentos = 3;

    if (!empty($protConsLote)) {
        // La SET suele demorar en procesar el lote; consultamos en bucle hasta tener un estado final o agotar intentos.
        while ($intentosQuery < $maxIntentos) {
            $intentosQuery++;
            sleep(3); // Esperar 3 segundos entre consultas
            
            $loteConsulta = $sifen->queryLote($protConsLote);
            $xmlRes = $loteConsulta['xml_respuesta'] ?? '';
            
            // Extraer dEstRes del XML de respuesta para ver si ya finalizó
            $estadoProcesamiento = '';
            if (!empty($xmlRes)) {
                if (preg_match('/<dEstRes>(.*?)<\/dEstRes>/', $xmlRes, $m)) {
                    $estadoProcesamiento = $m[1];
                }
            }

            // Si ya fue aprobado o rechazado, tenemos respuesta final
            if ($estadoProcesamiento === 'Aprobado' || $estadoProcesamiento === 'Rechazado') {
                break;
            }
        }
    }

    $cdc = $factura->cdc . \sifen\Sifen::getRuc($factura->cdc, 'dv');
    
    // Construir una respuesta de texto más legible para el campo xml_respuesta
    // que facilite la depuración o el mostrado en el grid.
    $xml_respuesta_compuesta = json_encode(
        [
            'enviar' => $respuesta, 
            'lote' => $loteConsulta, 
            'intentos' => $intentosQuery,
            'fecha_consulta' => date('Y-m-d H:i:s')
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return [
        'cdc' => $cdc,
        'prot_cons_lote' => $protConsLote,
        'msg' => $msgResult,
        'xml_firmado' => $xmlFirmado,
        'xml_respuesta' => is_string($xml_respuesta_compuesta) ? $xml_respuesta_compuesta : (is_string($respuesta['response'] ?? null) ? $respuesta['response'] : (json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')),
        'raw' => $respuesta,
        'lote' => $loteConsulta,
        'estado_lote' => $estadoProcesamiento ?? null
    ];
}

/* =========================
 * PHP: Inputs / Setup
 * ========================= */

$punto = traedatos('cajas', 'factura_2', 'id_caja', $_SESSION['id_caja']);
$establecimiento = pad3(traedatos('cajas', 'factura_1', 'id_caja', $_SESSION['id_caja']));
$cod_establecimiento = $establecimiento;

$cod_expedicion = pad3(traedatos('cajas', 'factura_2', 'id_caja', $_SESSION['id_caja']));

debug_log_factura('comprobante_venta_reimpresion.php iniciado');

$id_factura = $_GET['id_factura'] ?? $_GET['par_id'] ?? $_SESSION['id_factura'];
debug_log_factura('ID Factura recibido: ' . $id_factura);

// NOTA: la numeración se calcula/persiste al presionar "Factura Electrónica" o "Facturar".
$id_caja_actual = $_GET['id_caja'] ?? $_SESSION['id_caja'];
$nro_factura_calc = '';

// Endpoint AJAX: calcular y guardar nro_factura recién al presionar el botón.
if (isset($_POST['calc_nro']) && $_POST['calc_nro'] == '1') {
    $calc = reservar_nro_factura_desde_caja($id_factura, $id_caja_actual);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $calc !== null,
        'nro_factura' => (string) (($calc ?? [])['nro_factura'] ?? ''),
        'timbrado' => (string) (($calc ?? [])['timbrado'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

sc_exec_sql("DELETE FROM extracto_productos WHERE idfactura = $id_factura AND estado = 0");
//Definir ancho
// Valor por defecto desde base
$ancho_db = traedatos(MASTER_DB . '.sec_users','ancho_papel','id_login',$_SESSION['id_login']);

// Si hay override por GET
if (isset($_GET['ancho']) && in_array($_GET['ancho'], ['200', '400', '1200'])) {
  $ancho = $_GET['ancho'];
} elseif ($ancho_db == '-1') {
  $ancho = '400'; // Valor por defecto para casos donde hay que elegir
} else {
  $ancho = $ancho_db;
}

// Para aplicar al CSS
$ancho_css = $ancho . 'px';

$productos_iva = [];

// Definir la consulta SQL
$sql = "SELECT idproducto, iva FROM tblproductos";

// Ejecutar la consulta
sc_lookup($rs, $sql);

// Verificar si la consulta fue exitosa y tiene registros
if (!empty($rs)) {
    foreach ($rs as $row) {
        // Llenar el arreglo con idproducto como clave y iva como valor
        $productos_iva[$row[0]] = $row[1];
    }
} else {
    debug_log_factura('No se encontraron IVA de productos (tblproductos).');
}


$query = "SELECT idproducto, id, importe, idfactura FROM extracto_productos WHERE idfactura = '$id_factura' and estado = 1 and referencia =200";
sc_select($rs, $query);
if ($rs !== false) 
{
    $texenta = 0;
    $tiva5 = 0;
    $tiva10 = 0;
    $timporte = 0;

	    while (!$rs->EOF) 
		{
	        $id = $rs->fields["id"];
	        $idproducto = $rs->fields["idproducto"];
	        $importe = $rs->fields["importe"];
			$tipo_iva = $productos_iva[$idproducto] ?? traedatos('tblproductos', 'iva', 'idproducto', $idproducto);
			switch($tipo_iva)
				{
					case 1: //exenta
					$texenta += $importe;
					break;
					case 2:
					$tiva5 += $importe;
					break;
					case 3:
					$tiva10 += $importe;
					break;
				}
	        $timporte += $importe;
	        $rs->MoveNext();
	    }
    $rs->Close();

    // Actualiza los totales de la factura en una sola operación.
    $piva5 		= $tiva5 / 21;
    $piva10 	= $tiva10 / 11;
	$tivaf = $tiva10+$tiva5;
	$tpivaf = $piva5+$piva10;
	$timportef = $timporte;
	$texentaf = $texenta;
	$tiva5f = $tiva5;
	$tiva10f = $tiva10;
	$piva5f = $piva5;
	$piva10f = $piva10;
	sc_format_num($timportef	, '.', ',', 2, 'N', '1', '');
	sc_format_num($texentaf	, '.', ',', 2, 'N', '1', '');
	sc_format_num($tiva5f	, '.', ',', 2, 'N', '1', '');
	sc_format_num($tiva10f	, '.', ',', 2, 'N', '1', '');
	sc_format_num($tivaf	, '.', ',', 2, 'N', '1', '');
	sc_format_num($tpivaf	, '.', ',', 2, 'N', '1', '');
	sc_format_num($piva5f	, '.', ',', 2, 'N', '1', '');
	sc_format_num($piva10f	, '.', ',', 2, 'N', '1', '');


    $updateQuery = "UPDATE factura_ventas SET exenta='$texenta', iva5='$tiva5', iva10='$tiva10', piva5='$piva5', piva10='$piva10', importe_gs=$timporte, total=$timporte, cantidad = $timporte WHERE id_factura = '$id_factura'";
    sc_exec_sql($updateQuery);
}

//cobro compuesto
$sql = "select 
id_sucursal,
fecha,
ruc,
cantidad,
id_moneda,
vencimiento,
id_login,
exenta,
iva5,
iva10,
nro_factura,
timbrado,
vencimiento_timbrado,
tipo_venta,
importe_gs,
id_cliente,
cambio
from factura_ventas where id_factura = '".$id_factura."'";
sc_lookup($rs,$sql);
if(isset($rs[0][0]))
{
$id_sucursal = $rs[0][0];
$fecha = $rs[0][1];
$ruc = $rs[0][2];
$cantidad = $rs[0][3];
$id_moneda = $rs[0][4];
$vencimiento = $rs[0][5];
$id_login = $rs[0][6];
$exenta =$rs[0][7];
$iva5 =$rs[0][8];
$iva10 =$rs[0][9];
$nro_factura =$rs[0][10];
if (!empty($nro_factura_calc)) {
    $nro_factura = $nro_factura_calc;
}
$timbrado =$rs[0][11];
$vencimiento_timbrado = $rs[0][12];
$tipo_venta =$rs[0][13];
$importe_gs =$rs[0][14];
$id_cliente = $rs[0][15];
$cambio = $rs[0][16];
$total = floatval($cantidad)*floatval($cambio);
$factura = $tipo_venta == '1' ? 'CONTADO' : 'CRÉDITO';
$importe_gs_f = $total;


$sucursal = traedatos('sucursales','sucursal','id_sucursal', $id_sucursal);
$direccion = traedatos('sucursales','direccion','id_sucursal', $id_sucursal);
$telefono = traedatos('sucursales','telefono','id_sucursal', $id_sucursal);

$central = traedatos('sucursales','sucursal','id_sucursal', '1');
$central_telefono = traedatos($_SESSION['dbu'].'.empresa','telefono','id_empresa',$_SESSION['id_empresa']);
$empresa_de = "De: ". traedatos($_SESSION['dbu'].'.empresa','propietario','id_empresa',$_SESSION['id_empresa']);							  
$ramo =  traedatos($_SESSION['dbu'].'.empresa','ramo','id_empresa',$_SESSION['id_empresa']);	
$clientess = traedatos('clientes','nombre','id',$id_cliente);
$clientess = str_replace("\n"," ", $clientess);

$dircliente = traedatos('clientes','direccion','id',$id_cliente);
$dircliente = str_replace("\n"," ", $dircliente);

$moneda = traedatos('monedas','moneda','id_moneda',$id_moneda);
$sql = "select name from " . MASTER_DB . ".sec_users where id_login = '$id_login'";
sc_lookup($rs,$sql);
if (isset($rs[0][0])){$login = $rs[0][0];}else{$login = 'Login no encontrado';}
$piva5 = $iva5/'21';
$piva10 = $iva10/'11';
$totaliva = $piva5+$piva10;

$sql = "select RPAD(concat(codigo,'-',descripcion),'32',' '),salida,precio,importe
		 from extracto_productos where idfactura = '$id_factura' and referencia ='200' and estado=1";
		sc_select($rs,$sql);
		if ($rs === false)
			{		
			echo "No existe ningun registro";
			}
			else
			{
			$datos = array();	
			$s='0';
			while (!$rs->EOF)
				{
				$desc = $rs->fields[0];
				$desc = str_replace("Ñ","NH", $desc);
				$salida 	= str_pad($rs->fields[1],9,' ',STR_PAD_LEFT);
				$precio 	= str_pad($rs->fields[2],8,' ',STR_PAD_LEFT);
				$importe 	= str_pad($rs->fields[3],9,' ',STR_PAD_LEFT);
				
				$salida = str_pad($salida,9,' ',STR_PAD_LEFT);
				$precio = str_pad($precio,8,' ',STR_PAD_LEFT);
				$importe = str_pad($importe,10,' ',STR_PAD_LEFT);
				$salida_formateado	=$salida; 	
				$precio_formateado	=$precio; 
				$importe_formateado	=$importe;
				sc_format_num($salida_formateado	, '.', ',', 2, 'N', '1', '');
				sc_format_num($precio_formateado	, '.', ',', 0, 'N', '1', '');
				sc_format_num($importe_formateado	, '.', ',', 0, 'N', '1', '');	
				$datos[] = [$desc, $salida_formateado, $precio_formateado, $importe_formateado];
				$rs->MoveNext();			
				}	
				
				$rs->Close();
			}

	

//

$sql = "select 
id_sucursal,
fecha,
ruc,
cantidad,
id_moneda,
vencimiento,
id_login,
forma_pago,
id_cliente,
nro_factura,
obs,
exenta,
iva10,
iva5,
piva10,
piva5,
timbrado,
vencimiento_timbrado,
pagado,
fecha_factura,
id_unico,
cambio
from factura_ventas where id_factura = '".$id_factura."'";
sc_lookup($rs,$sql);
if(isset($rs[0][0]))
{
$id_sucursal 			= $rs[0][0];
$fecha 					= $rs[0][1];
$ruc 					= $rs[0][2];
$total  				= $rs[0][3];
$id_moneda 				= $rs[0][4];
$vencimiento 			= $rs[0][5];
$id_login 				= $rs[0][6];
$forma_pago 			= $rs[0][7] == 2 ? 'CREDITO': 'CONTADO';
$id_cliente 			= $rs[0][8];
$factura_nro 			= $rs[0][9];
if (!empty($nro_factura_calc)) {
    $factura_nro = $nro_factura_calc;
}
$notas					= $rs[0][10];
$exenta					= $rs[0][11];
$iva10					= $rs[0][12];
$iva5					= $rs[0][13];
$piva10					= $rs[0][14];
$piva5					= $rs[0][15];
$timbrado				= $rs[0][16];
$total_sf 				= $total;
$vencimiento_timbrado	= $rs[0][17];
$fecha_factura			=$rs[0][19];
$id_unico 				= $rs[0][20];
$cambio 				= $rs[0][21];

	
$pagado_compuesto = traedatos('cobro_factura_venta_item','sum(cantidad*cambio)','id_factura',$id_factura);

$sqla = "select sum(cantidad*cambio) from extracto_cliente 
where 
numero = '$id_factura' 
and codigo = '$id_cliente' 
and referencia='3'
and operacion='5'";
	
sc_lookup($rsa,$sqla);
if (isset($rsa[0][0]))
	{
	$pagado 	= floatval($rsa[0][0])+floatval($pagado_compuesto);
	
	}
else
	{
	$pagado 	= $pagado_compuesto;
	}
	
$pendiente 			= floatval($total)-floatval($pagado);

$piva5 				= $iva5/'21';
$piva10 			= $iva10/'11';

$totaliva 			= floatval($piva5)+floatval($piva10);


$tiva 				= floatval($iva10)+floatval($iva5);
$tpiva 				= floatval($piva10)+floatval($piva5);
$importe_total		=$total;
$importe_pagado		=$pagado;
$importe_pendiente	=$pendiente;
$pendiente_c		=$pendiente;
	
	
if(empty($id_unico))
	{
	$id_unico = id_unico();
	sc_exec_sql("update factura_ventas set id_unico = '".$id_unico."' where id_factura = '".$id_factura."'");
	}
$sucursal = traedatos('sucursales','sucursal','id_sucursal', $id_sucursal);
$direccion = traedatos('sucursales','direccion','id_sucursal', $id_sucursal);
$direccion = preg_replace("/\r?\n/", " ", $direccion);
$direccion = trim($direccion);								
$telefono = traedatos('sucursales','telefono','id_sucursal', $id_sucursal);
$ruc = traedatos('clientes','numero','id',$id_cliente);
$cliente = traedatos('clientes','nombre','id',$id_cliente);
$cliente_direccion = traedatos('clientes','direccion','id',$id_cliente);
$cliente_direccion = preg_replace("/\r?\n/", " ", $cliente_direccion);
$cliente_direccion = trim($cliente_direccion);
$cliente_telefono = traedatos('clientes','telefono','id',$id_cliente);
$cliente_email = traedatos('clientes','email','id',$id_cliente);
$moneda = traedatos('monedas','simbolo','id_moneda',$id_moneda);
$moneda_nombre=traedatos('monedas','moneda','id_moneda',$id_moneda);
$posicion_decimal = traedatos('monedas','p_decimal','id_moneda',$id_moneda);
$registro = $id_factura;
$total_formateado = $t_importe;	
	
$registro_formateado	=$registro;
$exenta_formateado		=$exenta;		
$etxenta_formateado		=$texenta;		
$iva10_formateado		=$iva10;	
$iva5_formateado		=$iva5;		
$piva10_formateado		=$piva10;		
$piva5_formateado		=$piva5;		
$tiva_formateado		=$tiva;		
$tpiva_formateado		=$tpiva;		
$pagado_formateado		=$pagado;		
$pendiente_formateado	=$pendiente;	
$pendiente_c_formateado	=$pendiente_c;	
sc_format_num($total_formateado			, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($registro_formateado		, '.', ',', 0, 'S', '1', '');
sc_format_num($exenta_formateado		, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($iva10_formateado			, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($iva5_formateado			, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($piva10_formateado		, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($piva5_formateado			, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($tiva_formateado			, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($tpiva_formateado			, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($pagado_formateado		, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($pendiente_formateado		, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($pendiente_c_formateado	, ',', '.', 0, 'S', '1', '');


sc_format_num($texenta_formateado		, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($tiva5_formateado		, '.', ',', 0, 'S', '1', $moneda);
sc_format_num($tiva10_formateado		, '.', ',', 0, 'S', '1', $moneda);
	

$pos_dec=$posicion_decimal;
$PrinPlano=traedatos('setups','valor','nombre','Imprimime Ticket Plano en Post');

$parametro ="?par_id=" . $id_factura . "&db_m=" . ($_SESSION['db'] ?? '') . "&logo_m=" . ($_SESSION['logo'] ?? '') . "&DireccionEmpresa_m=" . ($_SESSION['DireccionEmpresa'] ?? '');
$server = "http://".$_SERVER['SERVER_NAME'];	
$code1 = $server.$_SERVER['PHP_SELF'].$parametro;
$code2 = str_replace('index.php','comprobante_venta.php',$code1);
$code2 = str_replace('comprobante_venta','comprobante_venta_movil',$code2);
$code2 = str_replace('comprobante_venta.php','comprobante_venta_movil.php',$code2);
$code64 = base64_encode($code2);

$ramo = traedatos(MASTER_DB . '.empresa','ramo','id_empresa',$_SESSION['id_empresa']);	
$ramo = preg_replace("/\r?\n/", " ", $ramo);
$ramo = trim($ramo);
	
$_SESSION['DireccionEmpresa'] = preg_replace("/\r?\n/", " ", $_SESSION['DireccionEmpresa']);
$_SESSION['DireccionEmpresa'] = trim($_SESSION['DireccionEmpresa']);
	
$fechavigenciaini = sc_date_conv($fechavigenciaini,"aaaa-mm-dd","dd/mm/aaaa");
$fechavigenciafin = $vencimiento_timbrado;
$fechavigenciafin = date("d/m/Y", strtotime($fechavigenciafin));
	
sc_lookup_field($emp,"select * from " . MASTER_DB . ".empresa where id_empresa = '{$_SESSION['id_empresa']}'"); 
$emp = $emp[0];

// DEBUG: Verificar valores iniciales de emp
error_log("DEBUG: emp cert_pass = " . ($emp['cert_pass'] ?? 'VACIO'));
error_log("DEBUG: emp csc = " . ($emp['csc'] ?? 'VACIO'));
error_log("DEBUG: emp idc = " . ($emp['idc'] ?? 'VACIO'));

// Obtener configuración SIFEN desde tabla empresa
sc_lookup_field($sifen_cfg, "SELECT cert_pass, csc, idc, ambiente_sifen FROM " . MASTER_DB . ".empresa WHERE id_empresa = '{$_SESSION['id_empresa']}' AND activo = 1 LIMIT 1");

// DEBUG: Verificar resultado de consulta sifen_cfg
error_log("DEBUG: sifen_cfg = " . print_r($sifen_cfg, true));

if (!empty($sifen_cfg) && isset($sifen_cfg[0]['cert_pass'])) {
    // Usar valores de la configuración SIFEN
    $emp['cert_pass'] = $sifen_cfg[0]['cert_pass'];
    $emp['csc'] = $sifen_cfg[0]['csc'];
    $emp['id_csc'] = $sifen_cfg[0]['idc'];
    error_log("DEBUG: Usando valores de sifen_cfg");
} else {
    // Fallback a valores desde tabla empresa si no existe configuración SIFEN específica
    $emp['cert_pass'] = $emp['cert_pass'] ?? '12345678';
    $emp['csc'] = $emp['csc'] ?? '1';
    $emp['id_csc'] = $emp['idc'] ?? '1';
    error_log("DEBUG: Usando valores fallback");
}

// DEBUG: Verificar valores finales de emp
error_log("DEBUG: emp final cert_pass = " . ($emp['cert_pass'] ?? 'VACIO'));
error_log("DEBUG: emp final csc = " . ($emp['csc'] ?? 'VACIO'));
error_log("DEBUG: emp final id_csc = " . ($emp['id_csc'] ?? 'VACIO'));
sc_lookup_field($fact,"select * from factura_ventas where id_factura = '$id_factura'");
$fact = $fact[0];

// Si en algún intento anterior se guardó el payload base64 (ZIP) en `xml_firmado`,
// lo convertimos a XML real y lo persistimos (para no tener que re-enviar a SIFEN).
if (!empty($fact['xml_firmado']) && is_string($fact['xml_firmado']) && substr(ltrim($fact['xml_firmado']), 0, 1) !== '<') {
    $xmlExtraido = sifen_extraer_xml_desde_payload($fact['xml_firmado']);
    if (is_string($xmlExtraido) && trim($xmlExtraido) !== '') {
        $fact['xml_firmado'] = $xmlExtraido;
        $updXml = "UPDATE factura_ventas
                   SET xml_firmado = '" . sql_escape($xmlExtraido) . "'
                   WHERE id_factura = '" . sql_escape($id_factura) . "'";
        sc_exec_sql($updXml);
    }
}
$id_cliente = $fact['id_cliente'];
sc_lookup_field($cli,"select * from clientes where id = '$id_cliente'");
$cli = $cli[0];	
	
// Debug: Verificar id_factura antes de la consulta
file_put_contents(__DIR__ . '/debug/factura_debug.log', date('c') . " - DEBUG: id_factura = $id_factura\n", FILE_APPEND);

sc_lookup($item,"SELECT codigo, descripcion, precio, salida, exenta, iva5, iva10 
                 FROM extracto_productos 
                 WHERE idfactura = '{$_SESSION['id_factura']}'");

// Debug: Verificar contenido de $item después de la consulta
file_put_contents(__DIR__ . '/debug/factura_debug.log', date('c') . " - DEBUG: item result = " . print_r($item, true) . "\n", FILE_APPEND);

$conceptos = [];
if (!empty($item[0][0])) {
    foreach (
		$item as $row) {
		if($row[4] > 0){$tasa = 0;}
		if($row[5] > 0){$tasa = 5;}
		if($row[6] > 0){$tasa = 10;}
        $conceptos[] = [
            'codigo'        => $row[0],
            'descripcion'   => $row[1],
            'precio'        => $row[2],
            'cantidad'      => (float) $row[3],
            'tasa_iva'      => $tasa,
            'descuento'     => 0,
            'proporcion_iva'=> 100,
            'unidad_medida' => '77'
        ];
    }
}

// Debug: Verificar contenido final de conceptos
file_put_contents(__DIR__ . '/debug/factura_debug.log', date('c') . " - DEBUG: conceptos final = " . print_r($conceptos, true) . "\n", FILE_APPEND);

$conceptos_json = json_encode($conceptos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Debug: Verificar JSON generado
file_put_contents(__DIR__ . '/debug/factura_debug.log', date('c') . " - DEBUG: conceptos_json = $conceptos_json\n", FILE_APPEND);

$condicion = $fact['forma_pago'] == 1 ? 'contado':'credito';


	

$auto_run_fe = false;

if (isset($_POST['facturar_fe']) && $_POST['facturar_fe'] == '1') {
    // Al presionar "Factura Electrónica", calcular y guardar la numeración desde caja.
    $calc = reservar_nro_factura_desde_caja($id_factura, $id_caja_actual);
    if ($calc !== null) {
        $nro_factura_calc = (string) ($calc['nro_factura'] ?? '');
        if ($nro_factura_calc !== '') {
            $nro_factura = $nro_factura_calc;
        }
    }
    if (empty($fact['cdc'])) {
        $auto_run_fe = true;
    }
}

$partes = explode('-', $nro_factura);
$establecimiento   = $partes[0]; // 001
$expedicion  	   = $partes[1]; // 001
$numero            = $partes[2]; // 0000001
$ndoc              = intval($partes[2]); // 0000001
	
$establecimiento = traedatos('cajas','factura_1','id_caja',$_SESSION['id_caja']); 
$establecimiento =str_pad($establecimiento, 3, '0', STR_PAD_LEFT);
$cod_establecimiento =str_pad($establecimiento, 3, '0', STR_PAD_LEFT);

$cod_expedicion = traedatos('cajas','factura_2','id_caja',$_SESSION['id_caja']);
$cod_expedicion =str_pad($cod_expedicion, 3, '0', STR_PAD_LEFT);
$sql = "select id from talonario where establecimiento = '$cod_establecimiento' and punto = '$cod_expedicion' and timbrado = {$emp['timbrado']}";
sc_lookup($talon,$sql);
if (isset($talon[0][0])){$id_talonario = $talon[0][0];}else{$id_talonario =1;}

	// =========================
	// PHP: Ejecutar SIFEN (Factura Electrónica)
	// =========================
		$run_fe = ($auto_run_fe || (isset($_POST['facturar_fe']) && $_POST['facturar_fe'] == '1'));
		if ($run_fe && ($emp['fe'] ?? 0) == 1 && empty($fact['prot_cons_lote_sifen'])) {
			try {
				$ndoc_sifen = sifen_ndoc_desde_nro_factura($nro_factura_calc ?: (string) ($fact['nro_factura'] ?? ''));
				if (empty($ndoc_sifen)) {
					$ndoc_sifen = (string) $id_factura;
				}
				$fe_result = sifen_emitir_factura_venta($emp, $cli, $conceptos, $ndoc_sifen, $condicion, $cod_establecimiento, $cod_expedicion);

				$cdc_sql = sql_escape($fe_result['cdc'] ?? '');
				$prot_sql = sql_escape($fe_result['prot_cons_lote'] ?? '');
				$xml_firmado_sql = sql_escape((string) ($fe_result['xml_firmado'] ?? ''));
				$xml_respuesta_sql = sql_escape((string) ($fe_result['xml_respuesta'] ?? ''));
			$updateFe = "UPDATE factura_ventas
			             SET cdc = '{$cdc_sql}',
			                 prot_cons_lote_sifen = '{$prot_sql}',
			                 xml_firmado = '{$xml_firmado_sql}',
			                 xml_respuesta = '{$xml_respuesta_sql}'
			             WHERE id_factura = '" . sql_escape($id_factura) . "'";
			sc_exec_sql($updateFe);

			if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode(['status' => true, 'cdc' => $fe_result['cdc'], 'prot_cons_lote' => $fe_result['prot_cons_lote'], 'estado_lote' => $fe_result['estado_lote']]);
				exit;
			}

			header('Location: ' . $_SERVER['REQUEST_URI']);
			exit;
		} catch (Throwable $e) {
			$fe_error = $e->getMessage();
			if (isset($_POST['ajax']) && $_POST['ajax'] == '1') {
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode(['status' => false, 'msg' => $fe_error]);
				exit;
			}
		}
	}

		/* =========================
		 * HTML: Render
		 * ========================= */
		?>
	<!DOCTYPE html>
	<html lang="es">
	<head>
	  <meta charset="utf-8"/>
	  <meta name="viewport" content="width=device-width, initial-scale=1"/>
	  <title>Ticket</title>

		<!-- 1) SOLO estos CSS de Falcon; nada de otro bootstrap.css -->
	  <link href="<?= $FALCON_CDN ?>/assets/css/theme.css" rel="stylesheet" id="style-default">
	  <link href="<?= $FALCON_CDN ?>/assets/css/user.css" rel="stylesheet" id="user-style-default">

	  <!-- 2) Vendors que sí usa Falcon (sin duplicar jQuery) -->
	  <link href="<?= $FALCON_CDN ?>/vendors/select2/select2.min.css" rel="stylesheet">
	  <link href="<?= $FALCON_CDN ?>/vendors/select2-bootstrap-5-theme/select2-bootstrap-5-theme.min.css" rel="stylesheet">
	  <link href="<?= $FALCON_CDN ?>/vendors/datatables.net-bs5/dataTables.bootstrap5.min.css" rel="stylesheet">
	  <link href="<?= $FALCON_CDN ?>/vendors/simplebar/simplebar.min.css" rel="stylesheet">

  <!-- (Opcional) Google Fonts OK -->
  <link rel="preconnect" href="https://fonts.gstatic.com">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,500,600,700|Poppins:300,400,500,600,700,800,900&display=swap" rel="stylesheet">

	<!-- CSS: Ticket -->
	<style>
@media print
{
.oculto-impresion, .oculto-impresion 
*{
display: none !important;
}
}
:root {
--ancho-css: <?php echo $ancho_css; ?>;
}

.ticket {
width:var(--ancho-css);
max-width:var(--ancho-css);
}

td.cliente,
th.cliente {
width:var(--ancho-css);
max-width:var(--ancho-css);
padding: 1px;
}	

td.producto,
th.producto {
width: var(--ancho-css);
max-width:var(--ancho-css);
padding: 1px;

}

.ticket-container {
  width: 100%;
  padding: 0;
  margin: 0;
  display: flex;
  justify-content: center;
  align-items: center;
  height: auto;
  background-color: #f5f5f5;
  box-shadow: 0 0 10px rgba(0,0,0,0.1);
  line-height: 1.0 !important;
}

.ticket-item {
display: flex;
justify-content: space-between;
margin-bottom: 0px;
}

.codigo-descripcion {
flex-basis: 60%; /* Ajusta según necesidad */
}

.cantidad-precio-importe {
flex-basis: 40%; /* Ajusta según necesidad */
text-align: right;
}

.ramos code{
display: flex;
font-size: 0.85rem;
}

.code-container {
position: relative;
display: inline-block;
}

.code-container label {
  position: absolute;
  top: -0.6em;       /* flota justo arriba del borde */
  left: 6px;
  background-color: white;
  padding: 0 3px;
  font-size: 0.70rem;
  line-height: 1;
  pointer-events: none; /* evita que tape clics o selección */
}

.code-container code {
display: inline-block;
padding: 2px;
border: 1px solid black;
font-size: 2rem; /* Tamaño de fuente ajustado */
text-align: right;
padding-top:6px;
}

body, html {
margin: 0;
padding: 0;
height: 100%;
}
.container {
padding: 0;
margin: 0;
}
.ticket-image {
max-width: 80%;
height: 128px;
margin: 0; /* Elimina márgenes alrededor de la imagen */
padding: 0; /* Elimina padding alrededor de la imagen */
border: none; /* Asegura que no haya bordes */
display: block; /* Asegura que la imagen se trate como un bloque */
}
	
#area_print code.text-monospace.text-dark {
  line-height: 1.1 !important;
  margin: 0 !important;
  padding: 0 !important;
  display: block;
}
</style>
	
	
</head>
<body>
  <div id="area_print">
    <div class="d-flex justify-content-center font-sans-serif">
      <div class="card mb-3 oculto-impresion">
        <div class="card-body">
          <div class="row justify-content-between align-items-center">
            <div class="col-md">
              <h5 class="mb-2 mb-md-0">Ticket Nro. <?php echo $registro; ?></h5>
            </div>

            <?php if ($ancho_db == '-1'): ?>
            <div class="col-auto text-center my-2">
              <label for="selector_ancho">Seleccioná el tamaño del papel:</label>
              <select id="selector_ancho" class="form-select form-select-sm" style="width:auto; display:inline-block;">
                <option value="200"  <?php echo $ancho == '200'  ? 'selected' : ''; ?>>40mm</option>
                <option value="400"  <?php echo $ancho == '400'  ? 'selected' : ''; ?>>80mm</option>
                <option value="1200" <?php echo $ancho == '1200' ? 'selected' : ''; ?>>A4 / A5</option>
              </select>
            </div>
            <?php endif; ?>

            <div class="col-auto">
              <?php 
              if ($emp['fe'] == 1 && empty($fact['prot_cons_lote_sifen'])){ ?>
                <!-- ====== FORMULARIO (deja tal cual) ====== -->
                <form id="form-fe" method="post">
                  <input type="hidden" name="facturar_fe" value="1">
                  <input type="hidden" name="id_factura" value="<?php echo $id_factura; ?>">
                  <button class="oculto-impresion btn btn-falcon-info btn-sm mr-2 mb-2 mb-sm-0"
                          type="submit" id="facturar">
                    <i class="fas fa-file-invoice-dollar"></i>Factura Electrónica
                  </button>
                </form>
              <?php }
              if ($emp['fe'] != 1 && empty($fact['nro_factura'])) { ?>
                <button class="oculto-impresion btn btn-falcon-info btn-sm mr-2 mb-2 mb-sm-0" 
                        type="button" onclick="facturar()" 
                        id="facturar">
                  <i class="fas fa-file-invoice-dollar"></i>Facturar
                </button>
              <?php } ?>
              	<button class="oculto-impresion btn btn-falcon-default btn-sm mr-2 mb-2 mb-sm-0" 
						type="button" onclick="imprimir()" 
						id="imprimir_comprobante">
				  <i class="fas fa-print mr-1"></i>Imprimir
				</button>
            </div>
          </div><!-- /.row -->
        </div><!-- /.card-body -->
      </div><!-- /.card -->
	    </div><!-- /.d-flex -->

	    <?php if (!empty($fe_error)) { ?>
	      <div class="alert alert-danger oculto-impresion mx-3 mb-3" role="alert">
	        <?php echo $fe_error; ?>
	      </div>
	      <script>
	        alert(<?php echo json_encode((string) $fe_error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);
	      </script>
	    <?php } ?>

	    <?php if ($ancho > 0) { ?>    
	      <div class="ticket">
	    <?php } ?>

    <div class="card">
      <div class="card-body">
        <div class="row">
          <div class="ticket-container">
            <img src="<?php 
              $logo = traedatos(MASTER_DB . '.empresa','logos','id_empresa',$_SESSION['id_empresa']);
              echo $_SESSION['ruta_imagen_empresas'].$logo; ?>" class="ticket-image"/>
          </div>

          <div class="col-12 text-center">      
            <?php 
            if($factura_nro == "") { ?>
              <code class="fw-bold text-monospace fs-11 text-dark">
                <?php echo traedatos(MASTER_DB . '.empresa', "direccion", "id_empresa", "{$_SESSION['id_empresa']}");?>
                <br>
                FORMA DE PAGO 
                <?php echo $forma_pago ?>
                <?php echo $factura_nro; ?><br>
              </code>
              <code class="fw-bold text-monospace text-dark" style="line-height:1.0; margin:0; padding:0;">
            <?php } else { ?>     
              <code class="fw-bold ramos text-monospace text-dark">
                <?php echo traedatos(MASTER_DB . '.empresa', "direccion", "id_empresa", "{$_SESSION['id_empresa']}");?>
                <br>
                <?php echo $empresa_de.' <br> RUC: '.traedatos($_SESSION['dbu'].'.empresa','ruc','id_empresa',$_SESSION['id_empresa']).'-'.traedatos($_SESSION['dbu'].'.empresa','dv','id_empresa',$_SESSION['id_empresa']); ?>
                <br> 
                <?php echo traedatos($_SESSION['dbu'].'.empresa','ramo','id_empresa',$_SESSION['id_empresa']); ?>
                <br>
              </code>
              <code class="fw-bold text-monospace text-dark" style="line-height:1.0; margin:0; padding:0;"> 
                FACTURA LEGAL
                <?php echo $forma_pago ?>
                <?php echo $factura_nro; ?><br>
                Timbrado
                <?php echo $emp['timbrado']; ?><br>
                <?php
                  $vig_ini = date('d/m/Y', strtotime($emp['vigencia_ini']));
                  $vig_fin = date('d/m/Y', strtotime($emp['vigencia_fin']));
                ?>
                <?php if($emp['fe'] == 1 && !empty($fact['cdc'])): ?>
                  Vigencia inicial: <?php echo $vig_ini; ?><br>
                <?php else: ?>
                  Vigencia: <?php echo $vig_ini . ' a ' . $vig_fin; ?><br>
                <?php endif; ?>
              </code>
            <?php } ?>  
            
           <div class="row row-sm justify-content-between align-items-center">
              <div class="col-sm-12 mt-0 mb-0">
                <code class="fw-bold text-monospace text-dark" style="line-height:1.0; margin:0; padding:0;">
                  <?php 
                    $fecha = date("d/m/Y H:i:s", strtotime($fecha));
                    echo "Fecha de Emisión: ".$fecha; ?><br>
                  Ticket Nro. <?php echo $registro; ?><br>
                  <?php echo "Cliente: ".$cliente; ?><br>
                  <?php echo "RUC: ".$ruc; ?><br>
                </code>

                <table class='table table-sm table-hover fw-black fs-10 w-100'>
                  <thead>
                    <tr>
                      <th class='fw-black border border-dark text-start' scope='col'>
                        <code class='text-monospace text-dark fw-bold'>Código | Producto</code>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
	                      $consulta=" SELECT id,codigo,descripcion,salida,precio,importe,idproducto,obs FROM extracto_productos
	                      where idfactura = '".$id_factura."'  and referencia = '200' and estado = 1";
	                      sc_select($rs,$consulta);
	                      if ($rs !== false)
	                      { 
	                        $cant_palabras = traedatos(MASTER_DB . '.empresa', 'cant_palabra_ticket', 'id_empresa', $_SESSION['id_empresa']);
	                        $imprime_codigo_ticket = traedatos(MASTER_DB . '.empresa', 'imprime_codigo_ticket', 'id_empresa', $_SESSION['id_empresa']); 
	                        $imprime_id_ticket     = traedatos(MASTER_DB . '.empresa', 'imprime_id_ticket', 'id_empresa', $_SESSION['id_empresa']); 

	                        while(!$rs->EOF) 
	                        {
	                          $id = $rs->fields["id"];
	                          $precio    = $rs->fields["precio"];
	                          $importe   = $rs->fields["importe"];
                          $cantidad  = $rs->fields["salida"];
                          $codigo    = $rs->fields["codigo"];
                          $nombre    = $rs->fields["descripcion"];
                          $nombre    = str_replace("Ñ","&Ntilde;", $nombre);
                          $palabras  = preg_split("/[\s.]+/", $nombre, -1, PREG_SPLIT_NO_EMPTY);

	                          switch($cant_palabras) {
	                            case 1: $nombre = $palabras[0]; break;
	                            case 2: $nombre = $palabras[0].' '.$palabras[1]; break;
	                            case 3: $nombre = $palabras[0].' '.$palabras[1].' '.$palabras[2]; break;
                            case 4: $nombre = $palabras[0].' '.$palabras[1].' '.$palabras[2].' '.$palabras[3]; break;
                            default: $nombre = $nombre; break;
                          }

                          $notas = $rs->fields["obs"];
                          $decimal_cantidad = traedatos('tblproductos','decimal_cantidad','idproducto',$rs->fields["idproducto"]);
                          $precio_prod_formateado  = $precio;
                          $importe_prod_formateado = $importe;
                          $cantidad_prod_formateado= $cantidad;  

                          sc_format_num($precio_prod_formateado,  '.', ',', $posicion_decimal, 'S', '1', $moneda);
                          sc_format_num($importe_prod_formateado, '.', ',', $posicion_decimal, 'S', '1', $moneda);
                          sc_format_num($cantidad_prod_formateado,'.', ',', $decimal_cantidad, 'S', '1');
                    ?>
                    <tr>
                      <td class="border border-dark text-start">
                        <div class="ticket-item">
                          <code class="text-monospace text-dark codigo-descripcion">
                            <?php if($imprime_codigo_ticket == 1){ ?>
                              <span class="elemento-codigo badge fs-10 text-dark badge-subtle-primary">
                                <?php echo $codigo; ?>
                              </span><br>
                            <?php } ?>
                            <?php if($imprime_id_ticket == 1){ ?>
                              <span class="elemento-codigo badge fs-10 text-dark badge-subtle-primary">
                                <?php echo 'ID. '.$id; ?>
                              </span>
                            <?php } ?>
                            <?php echo $nombre;?>
                          </code>
                          <code class="text-monospace text-dark cantidad-precio-importe">
                            <?php echo $cantidad_prod_formateado.' X '.$precio_prod_formateado.' = '.$importe_prod_formateado;?>
                          </code>
                        </div>
                      </td>
                    </tr>
                    <?php
                          $rs->MoveNext();
                        }
                        $rs->Close(); 
                      } 
                    ?>
                  </tbody>
                </table>

                <!-- Totales -->
                <div class="row no-gutters justify-content-end">
                  <?php if($factura_nro > "") { ?>
                  <div class="col-auto">
                    <table class="table table-sm table-borderless fs-10 text-end">
                      <tr>
                        <th><code class="fw-bold text-monospace text-dark">Exenta:</code></th>
                        <td class="border border-dark text-end">
                          <code class="fw-bold text-monospace text-dark"><?php echo $texentaf; ?></code>
                        </td>
                      </tr>
                      <tr>
                        <th class="fw-black"><code class="text-monospace text-dark">IVA 10%:</code></th>
                        <td class="border border-dark text-end">
                          <code class="fw-bold text-monospace text-dark"><?php echo $piva10f; ?></code>
                        </td>
                        <td class="border border-dark text-end">
                          <code class="fw-bold text-monospace text-dark"><?php echo $tiva10f; ?></code>
                        </td>
                      </tr>
                      <tr>
                        <th class="fw-black"><code class="text-monospace text-dark">IVA 5%:</code></th>
                        <td class="border border-dark text-end">
                          <code class="fw-bold text-monospace text-dark"><?php echo $piva5f; ?></code>
                        </td>
                        <td class="border border-dark text-end">
                          <code class="fw-bold text-monospace text-dark"><?php echo $tiva5f; ?></code>
                        </td>
                      </tr>
                      <tr class="fw-black">
                        <th><code class="text-monospace text-dark">Total IVA:</code></th>
                        <td class="border border-dark text-end">
                          <code class="fw-bold text-monospace text-dark"><?php echo $tpivaf; ?></code>
                        </td>
                        <td class="border border-dark text-end">
                          <code class="fw-bold text-monospace text-dark"><?php echo $tivaf; ?></code>
                        </td>
                      </tr>
                      <tr>
                        <td colspan="3" class="text-end">
                          <div class="code-container d-inline-block text-end">
                            <label>Total</label>
                            <code class="mb-0 p-2 text-dark text-monospace border border-dark text-end">
                              <?php echo $timportef; ?>
                            </code>
                          </div>    
                        </td>
                      </tr>
                    </table>
                  </div>
                  <?php } else { ?>
                  <div class="col-auto">
                    <table class="table table-sm text-end">
                      <tr>
                        <td>
                          <div class="code-container d-inline-block text-end">
                            <label>Total</label>
                            <code class="mb-0 p-2 fs-5 text-dark text-monospace border border-dark text-end">
                              <?php echo $timportef; ?>
                            </code>
                          </div>    
                        </td>
                      </tr>
                    </table>
                  </div>
                  <?php } ?>

                  <?php 
                    $tabela = "monedas";
                    $where = "moneda not in('$moneda_nombre') limit 3";
                    $query = "SELECT * FROM ".$tabela;
                    $query .= (!empty($where)) ? " WHERE ".$where : "";
                    $total_moneda=0;
                    $cambio_a=0;
                    $cambio_r=0;
                    sc_select($rsm, $query);
                    if($rsm !== false) {
                      while(!$rsm->EOF) {   
                        $id_moneda_r = $rsm->fields["id_moneda"];
                        $moneda_r    = $rsm->fields["moneda"];
                        $simbolo_r   = $rsm->fields["simbolo"];
                        $cambio_r    = $rsm->fields["venta"];
                        $decimal_r   = $rsm->fields["p_decimal"];
                        $cambio_f    = traedatos('monedas','venta','id_moneda',$id_moneda);

                        $total_gs  = floatval($importe_total)*floatval($cambio_f);
                        $cambio_a  = floatval($importe_total)*floatval($cambio_f)/floatval($cambio_r);
                        $cambio_a  = floatval($cambio_a)/100;
                        $total_moneda = floatval($importe_total)*floatval($cambio_a);
                        if($id_moneda == 1){
                          $cambio_a = $cambio_r;
                          $total_moneda = floatval($importe_total)/floatval($cambio_a);
                        }
                        $total_moneda_formateado = $total_moneda;
                        $cambio_a_formateado     = $cambio_a;
                        sc_format_num($total_moneda_formateado, '.', ',', $posicion_decimal, 'S', '1');
                        sc_format_num($cambio_a_formateado, '.', ',', $posicion_decimal, 'S', '1');
                        $rsm->MoveNext();
                        $total_moneda=0;
                        $cambio_a=0;
                        $cambio_r=0;
                      }
                      $rsm->Close();
                    }
                  ?>

                  <div class="mb-0 mt-0 font-sans-serif fs--2" style="text-align: center;">
                    <?php if($emp['fe'] == 1 && !empty($fact['cdc'])) { ?>
                      <div>
                        <!-- QR centrado con URL FIJA -->
                        <div id="qr_consulta_sifen" style="width: 100px; height: 100px; margin: 0 auto;"></div>

                        <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
                        <script>
                          const enlaceQR = "https://ekuatia.set.gov.py/consultas/";
                          new QRCode(document.getElementById("qr_consulta_sifen"), {
                            text: enlaceQR,
                            width: 100,
                            height: 100,
                          });
                        </script>

                        <!-- Enlace y CDC -->
                        <div style="margin-top: 10px; font-size: 11px;">
                          Consulte esta factura electrónica con el número de CDC impreso abajo en:<br>
                          <a href="https://ekuatia.set.gov.py/consultas/" target="_blank">
                            https://ekuatia.set.gov.py/consultas/
                          </a><br>
                          <strong><?php echo $fact['cdc']; ?></strong><br>
                          Este documento es una representación gráfica de un documento electrónico (XML).
                        </div>
                      </div>
                    <?php } ?>

                    <!-- Footer -->
                    <footer>
                      <div class="row no-gutters justify-content-between fs-10 mt-4 mb-3">
                        <div class="col-12 col-sm-auto mx-auto">
                          <code class="text-monospace text-dark">
                            Usuario: <?php echo $login; ?><br>
						<?php if($factura_nro != "") { ?>
				            Original: Cliente<br>Duplicado: Contabilidad<br>
						<?php } else { ?>  
							SIN VALOR FISCAL<br>
						<?php } ?>
                            Sistemax | Gestión Empresarial<br>www.sistemax.com.py
                          </code>
                        </div>
                      </div>
                    </footer>
                  </div>
                </div><!-- /.row no-gutters -->
              </div><!-- /.col-sm-12 -->
            </div><!-- /.row row-sm -->
          </div><!-- /.col-12 -->
        </div><!-- /.row -->

        <?php if(isset($notas)) { ?>
          <div class="card-footer bg-light">
            <p class="fs-10 mb-0 fw-black"><strong>Notas: </strong>
              <?php echo $notas; ?>
            </p>
          </div>
        <?php } ?>

        <?php
          //$forma_pago = traedatos('factura_ventas','forma_pago','id_factura',$id_factura);
          $propietario = traedatos(MASTER_DB . '.empresa','propietario','id_empresa',$_SESSION['id_empresa']);
          if ($forma_pago == "CREDITO") {
            $importe = traedatos('factura_ventas','cantidad','id_factura',$id_factura);
            sc_format_num($importe, '.', ',', $posicion_decimal, 'S', '1');
            $letra = numtoletras($total_sf);
        ?>
          <hr> <!-- Esto es una marca de corte -->
          <table>
            <tr>
              <th class="cliente">
                <code class="text-monospace text-dark">
                  PAGARE A LA ORDEN No.:<?php echo $id_factura;?><br>
                  <?php echo "Fecha: ".$fecha;?>
                </code>
              </th>   
            </tr>
            <tr>
              <td>
                <code class="text-monospace text-dark">
                  El dia ..../...../....., pagaré a <?php echo $propietario;?> la suma de <?php echo $total;?>
                  (<?php echo $letra;?>) La falta de pago del documento originará automáticamente un interés de 5% mensual
                  Autorizo(amos) desde ya al acreedor a la consulta como la base de datos de INFORMACIÓNES COMERCIALES,
                  conforme lo establecido en la ley 1.696/02 Todas las partes intervinientes en este documento se someten
                  a la jurisdicción y competencia de los Jueces y Tribunales de la república del Paraguay y declaran
                  prorrogadas desde ya cualquier otra que pudiera corresponder <br><br>Firma :.....................<br><br>

                  <?php echo "Nombre y Apellido: ".$cliente;?><br>
                  <?php echo "Documento Nro.: ".$ruc;?><br><br><br>
                </code>
              </td>
            </tr>
          </table>
        <?php } ?>
      </div><!-- /.card-body -->
    </div><!-- /.card -->

    <?php if ($ancho > 0) { ?>
      </div><!-- /.ticket -->
    <?php } ?>
  </div><!-- /#area_print -->

  <!-- ======= scripts ======= -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <!-- Bootstrap JS (opcional si ya lo cargás en tu template) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <!-- JS: Ticket / Impresión -->
  <script>
    function facturar() {
      var idfactura = '<?php echo $id_factura; ?>';
      var idEmpresa = '<?php echo $_SESSION['id_empresa']; ?>';

      // 1) Calcular/guardar numeración recién al presionar "Facturar"
      $.ajax({
        data: { calc_nro: '1' },
        type: "POST",
        url: window.location.href,
        dataType: "json"
      }).done(function(r){
        if (!r || r.status !== true) {
          Swal.fire({
            icon: "error",
            title: "Numeración",
            text: "No se pudo calcular/guardar la numeración de factura."
          });
          return;
        }

        // 2) Luego ejecutar el proceso existente de facturación legal
        $.ajax({
          data: { id_factura: idfactura, id_empresa: idEmpresa },
          type: "POST",
          url: "../facturar_venta/facturar_venta.php",
          dataType: "json",
          success: function(data) {
            console.log(data);
            if (data.status === true) {
                 location.reload();
            } else {
              Swal.fire({
                icon: "error",
                title: "Oops...",
                text: data.msg
              });
            }
          },
          error: function(xhr, status, error) {
            Swal.fire({
              icon: "error",
              title: "Error de conexión",
              text: "No se pudo conectar con el servidor. Intente de nuevo."
            });
            console.error("Error AJAX: ", error);
          }
        });
      }).fail(function(){
        Swal.fire({
          icon: "error",
          title: "Numeración",
          text: "No se pudo conectar para calcular la numeración."
        });
      });
    }

    function generateBarcode(codigo){
      var value = codigo;
      var btype = "code128";
      $("#barcode").html("").show().barcode(value, btype);
    }
  
    
  </script>

  <script>
	    // Interceptar submit del form FE
	    $(document).on('submit', '#form-fe', function(e){
	      e.preventDefault();

	      var $b = $('#facturar');
	      if ($b.length) { $b.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Enviando a SIFEN...'); }

	      $.ajax({
	        url: window.location.href,
	        type: 'POST',
	        dataType: 'json',
	        data: { facturar_fe: '1', ajax: '1' },
	        timeout: 120000
	      })
	      .done(function(r2){
	        if(!r2 || !r2.status){
	          var msg = (r2 && (r2.msg || r2.message || r2.error)) || 'Error desconocido SIFEN';
	          if (window.Swal) Swal.fire('SIFEN', msg, 'error');
	          else alert('SIFEN: ' + msg);
	          if ($b.length) { $b.prop('disabled', false).html('<i class="fas fa-file-invoice-dollar"></i>Factura Electrónica'); }
	          return;
	        }
        if (window.Swal) {
          var label = r2.estado_lote || 'Recibido';
          var icon = (label === 'Rechazado') ? 'warning' : 'success';
          Swal.fire('SIFEN', 'Estado: ' + label, icon)
            .then(function(){ location.reload(); });
        } else {
          location.reload();
        }
      })
      .fail(function(xhr){
        var msg = (xhr && xhr.responseText) ? xhr.responseText : 'Error de comunicación con SIFEN';
        if (window.Swal) Swal.fire('SIFEN', msg, 'error');
        else alert('SIFEN: ' + msg);
        if ($b.length) { $b.prop('disabled', false).html('<i class="fas fa-file-invoice-dollar"></i>Factura Electrónica'); }
      });
    });

    // Selector de ancho -> recarga con query param
    document.addEventListener("DOMContentLoaded", function () {
      const selector = document.getElementById("selector_ancho");
      if (selector) {
        selector.addEventListener("change", function () {
          const nuevoAncho = this.value;
          if (nuevoAncho) {
            const url = new URL(window.location.href);
            url.searchParams.set("ancho", nuevoAncho);
            window.location.href = url.toString();
          }
        });
      }
    });
	var empresa   = "<?php echo str_pad($_SESSION['empresa'], 30, ' ', STR_PAD_BOTH); ?>";
    var de        = "<?php echo $empresa_de; ?>";
    var ramo      = "<?php echo $ramo; ?>";
    var pago      = "<?php echo $forma_pago; ?>";
    var sucursal  = "<?php echo 'Sucursal: ' . $sucursal; ?>";
    var central   = "<?php echo 'Central: ' . $_SESSION['DireccionEmpresa']; ?>";
    var ruc       = "<?php echo 'RUC: ' . $_SESSION['RucEmpresa']; ?>";
    var timbrado  = "<?php echo 'Timbrado: ' . $timbrado; ?>";
    var vigencia  = "<?php echo 'Vto. Timbrado: ' . $fechavigenciafin; ?>";
    var tfactura  = "<?php echo $factura; ?>";
    var nfactura  = "<?php echo $nro_factura; ?>";
    var ifactura  = "<?php echo 'ID ' . $id_factura; ?>";
    var fecha     = "<?php echo 'Fecha: ' . $fecha; ?>";
    var ruccli    = "<?php echo 'RUC: ' . $ruc; ?>";
    var cliente   = "<?php echo 'Cliente: ' . $cliente; ?>";
    var dircli    = "<?php echo 'Direccion: ' . $cliente_direccion; ?>";
    var datos     = <?php echo json_encode($datos, JSON_PRETTY_PRINT); ?>;

    var total     = "<?php echo str_pad($timportef, 12, ' ', STR_PAD_LEFT); ?>";
    var exenta    = "<?php echo str_pad($texentaf, 12, ' ', STR_PAD_LEFT); ?>";
    var iva5      = "<?php echo str_pad($tiva5f, 12, ' ', STR_PAD_LEFT); ?>";
    var iva10     = "<?php echo str_pad($tiva10f, 12, ' ', STR_PAD_LEFT); ?>";
    var piva5     = "<?php echo str_pad($piva5f, 12, ' ', STR_PAD_LEFT); ?>";
    var piva10    = "<?php echo str_pad($piva10f, 12, ' ', STR_PAD_LEFT); ?>";
    var totaliva  = "<?php echo str_pad($tivaf, 12, ' ', STR_PAD_LEFT); ?>";

    var original  = "Original: Cliente";
    var duplicado = "Duplicado: Contabilidad";
    var login     = "<?php echo 'Usuario: ' . $login; ?>";
    var programa  = "Sistemax | Gestión empresarial";
    var sitio     = "www.sistemax.com.py";
    var contacto  = "WhatsApp: 0983 657 691";

		
		var LF = chr(10);
		var ESC = chr(27);
		var PrnAlignLeft = ESC+'a'+chr(0);
		var PrnAlignCenter = ESC+'a'+chr(1);
		var PrnAlignRight = ESC+'a'+chr(2);
		var PrnBoldOn = ESC+'G'+chr(1);
		var PrnBoldOff = ESC+'G'+chr(0);	

		function chr(x){
		   return String.fromCharCode(x);
		}

		function BtPrint(prn){
		   var S = "#Intent;scheme=rawbt;";
		   var P = "package=ru.a402d.rawbtprinter;end;";
		   var textEncoded = encodeURI(prn);
		   window.location.href="intent:"+textEncoded+S+P;
		}	

	  function imprimir() {   
      var plano = '<?php echo $PrinPlano; ?>';
      var movil = '<?php echo $_SESSION['dispositivo']; ?>';
      if (movil !== 'Desktop') {
        if (plano === '1') {
          var prn = '';
          prn += empresa+LF;
          prn += de+LF;
          prn += sucursal+LF;
          prn += 'Actividad Economica: '+ramo+LF;
          prn += central+LF; 
          prn += ruc+LF; 
          prn += timbrado+LF;
          prn += vigencia+LF; 
          prn += ifactura+LF;
          prn += 'Forma de pago: '+pago+LF;
          prn += '------------------------------'+LF; 
          prn += 'Factura Nro. : '+nfactura+LF; 
          prn += '------------------------------'+LF; 
          prn += fecha+LF;
          prn += ruccli+LF;
          prn += cliente+LF; 
          prn += dircli+LF;
          prn += '------------------------------'+LF; 
          prn += 'Codigo y Nombre del Producto'+LF;
          prn += 'Cantidad   Precio       Total '+LF; 
          prn += '------------------------------'+LF;
          datos.forEach(function(valor) {
            prn += valor[0]+LF
            prn += valor[1]+'X'+valor[2]+'='+valor[3]+LF;
          }); 
          prn += '------------------------------'+LF;
          prn += 'Total...: ';
          prn += total+LF;
          prn += '------------------------------'+LF; 
          prn += 'Liquidacion de IVA'+LF; 
          prn += '------------------------------'+LF;
          prn += 'Total Exenta....: '+exenta+LF;
          prn += 'Total IVA 5%....: '+iva5+LF;  
          prn += 'Total IVA 10%...: '+iva10+LF;
          prn += 'IVA 5%..........: '+piva5+LF;
          prn += 'IVA 10%.........: '+piva10+LF;   
          prn += '------------------------------'+LF;     
          prn += 'Total IVA...: '+totaliva+LF; 
          prn += '------------------------------'+LF; 
          prn += original+LF;
          prn += duplicado+LF;
          prn += '------------------------------'+LF; 
          prn += programa+LF;
          prn += sitio+LF;  
          prn += contacto;
          BtPrint(prn);
          console.log(prn);   
          window.close(); 
        } else {
          document.getElementById('prin_movil').click();
        }
      } else {
        window.print();
      }
    } 
	  </script>
		  
		  
		  

	  <!-- JS: Vendors -->
	  <script src="<?= $FALCON_CDN ?>/vendors/jquery/jquery.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/popper/popper.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/bootstrap/bootstrap.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/anchorjs/anchor.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/is/is.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/prism/prism.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/select2/select2.full.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/datatables.net/jquery.dataTables.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/datatables.net-bs5/dataTables.bootstrap5.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/datatables.net-fixedcolumns/dataTables.fixedColumns.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/fontawesome/all.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/lodash/lodash.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/vendors/list.js/list.min.js"></script>
	  <script src="<?= $FALCON_CDN ?>/assets/js/theme.js"></script>
	</body>
	</html>
	<?php
		}
	}
