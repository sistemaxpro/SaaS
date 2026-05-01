<?php

// Configurar OpenSSL para soportar certificados legacy (SHA256/RC2)
// Necesario para certificados .p12 antiguos en OpenSSL 3.x
$legacyCnf = dirname(__DIR__, 2) . '/openssl-legacy.cnf';
if (file_exists($legacyCnf)) {
    putenv('OPENSSL_CONF=' . $legacyCnf);
}

// Forzar ruta de módulos de OpenSSL 3.x (legacy provider)
if (!getenv('OPENSSL_MODULES')) {
    $modulesDir = '/usr/lib/x86_64-linux-gnu/ossl-modules';
    if (is_dir($modulesDir)) {
        putenv('OPENSSL_MODULES=' . $modulesDir);
    }
}

/**
 * Librería de Integración SIFEN para POS
 * Refactorizada basada en "Blank ScriptCase" funcional (Armagedon)
 * Ubicación: /pos/api/
 */

// Cargar SDK SIFEN con fallback (custom -> estándar) y validación de archivo no vacío.
$sdkCandidates = [
    dirname(__DIR__, 2) . '/_lib/php-sifen3-custom/src/php-sifen.php',
    dirname(__DIR__, 2) . '/_lib/php-sifen3/src/php-sifen.php',
];
$sdkLoaded = false;
foreach ($sdkCandidates as $sdkFile) {
    if (is_file($sdkFile) && filesize($sdkFile) > 1000) {
        require_once $sdkFile;
        $sdkLoaded = class_exists('\\sifen\\KEY') && class_exists('\\sifen\\Sifen');
        if ($sdkLoaded) {
            break;
        }
    }
}
if (!$sdkLoaded) {
    throw new RuntimeException('No se pudo cargar el SDK SIFEN (clases KEY/Sifen no disponibles).');
}
require_once dirname(__DIR__) . '/config/db_config.php';

function emitirFacturaElectronica($idFactura, $pdo, $dbName, $idEmpresa, array $options = [])
{
    $fastPrint = !empty($options['fast_print']);
    $logDir = '';
    $tmpCandidates = [
        dirname(__DIR__, 2) . '/_lib/tmp',
        rtrim(sys_get_temp_dir(), '/\\') . '/sistemaxpro_sifen_tmp'
    ];
    foreach ($tmpCandidates as $candidateDir) {
        if (!is_dir($candidateDir)) {
            @mkdir($candidateDir, 0775, true);
        }
        if (is_dir($candidateDir) && is_writable($candidateDir)) {
            $logDir = $candidateDir;
            break;
        }
    }
    if ($logDir === '') {
        throw new Exception('No hay carpeta temporal escribible para procesar SIFEN.');
    }
    $logFile = $logDir . '/pos_sifen_debug.log';

    $log = function ($msg) use ($logFile) {
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " | $msg\n", FILE_APPEND);
    };

    $log(">>> INICIO EMISIÓN: ID_FACTURA=$idFactura, Empresa=$idEmpresa, DB=$dbName");

    $extractXmlValue = function ($xml, array $tags): string {
        $xml = trim((string)$xml);
        if ($xml === '') {
            return '';
        }

        try {
            $sx = @simplexml_load_string($xml);
            if ($sx !== false) {
                foreach ($tags as $tag) {
                    $r = $sx->xpath("//*[local-name()='{$tag}']");
                    if (is_array($r) && isset($r[0])) {
                        $val = trim((string)$r[0]);
                        if ($val !== '') {
                            return $val;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Fallback regex abajo
        }

        foreach ($tags as $tag) {
            if (preg_match('/<[^:>]*:?' . preg_quote($tag, '/') . '>\s*(.*?)\s*<\/[^:>]*:?' . preg_quote($tag, '/') . '>/si', $xml, $m)) {
                $val = trim((string)($m[1] ?? ''));
                if ($val !== '') {
                    return $val;
                }
            }
        }
        return '';
    };

    try {
        // 1. Obtener Configuración SIFEN desde habilitacion_sifen (por empresa/sucursal)
        $log("OBTENIENDO CONFIG SIFEN para empresa ID: $idEmpresa");
        $masterDb = 'serproc1'; // Siempre serproc1: empresa y habilitacion_sifen están en la master DB
        $pdoMaster = getMasterConnection();

        $stmtEmp = $pdoMaster->prepare("SELECT * FROM {$masterDb}.empresa WHERE id_empresa = :id");
        $stmtEmp->execute([':id' => $idEmpresa]);
        $empresa = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if (!$empresa) {
            throw new Exception("Empresa no encontrada");
        }

        $stmtHab = $pdoMaster->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen WHERE id_empresa = :id AND activo = 1 ORDER BY id DESC LIMIT 1");
        $stmtHab->execute([':id' => $idEmpresa]);
        $habilitacion = $stmtHab->fetch(PDO::FETCH_ASSOC);
        if (!$habilitacion) {
            throw new Exception("No se encontró configuración SIFEN activa para esta empresa. Configure en habilitacion_sifen.");
        }
        $rucEmisorBase = preg_replace('/\D+/', '', (string)($habilitacion['ruc'] ?? $empresa['ruc'] ?? ''));
        if ($rucEmisorBase === '') {
            throw new Exception("No se encontró RUC del emisor en habilitacion_sifen ni empresa");
        }
        $dvEmisorBase = (string)($habilitacion['dv'] ?? $empresa['dv'] ?? \sifen\Sifen::getRuc($rucEmisorBase, 'dv'));

        $stmtAct = $pdoMaster->prepare("SELECT a.codigo, a.descripcion
            FROM {$masterDb}.habilitacion_sifen_actividades a
            INNER JOIN {$masterDb}.habilitacion_sifen h ON a.id_habilitacion = h.id
            WHERE h.id_empresa = :id_empresa AND h.activo = 1 AND a.principal = 1
            LIMIT 1");
        $stmtAct->execute([':id_empresa' => $idEmpresa]);
        $actividad = $stmtAct->fetch(PDO::FETCH_ASSOC);

        $log("CONFIG SIFEN CARGADA: ID={$habilitacion['id']}, Empresa={$habilitacion['razon_social']}, RUC={$rucEmisorBase}");

        $ambienteRaw = strtoupper(trim($habilitacion['ambiente'] ?? 'TEST'));
        $ambiente = ($ambienteRaw === 'PROD' || $ambienteRaw === '1') ? 'prod' : 'test';
        $log("ENV: $ambiente (Raw de DB: $ambienteRaw)");

        // Cargar certificado desde serproc1.habilitacion_sifen.cert_path
        // El archivo físico debe existir en: public/_lib/php-sifen3/certificados/{$certificado}
        $certPath = '';
        $certificado = '';

        if (!empty($habilitacion['cert_path'])) {
            $certificado = basename(trim((string)$habilitacion['cert_path']));
        }
        if ($certificado === '' && !empty($habilitacion['cert_nombre'])) {
            $certificado = basename(trim((string)$habilitacion['cert_nombre']));
        }

        if ($certificado !== '') {
            $candidate = dirname(__DIR__, 2) . '/_lib/php-sifen3/certificados/' . $certificado;
            if (file_exists($candidate)) {
                $certPath = $candidate;
            }
        }

        if (empty($certPath) || !file_exists($certPath)) {
            throw new Exception("Certificado digital no encontrado. Verifique cert_path en " . MASTER_DB . ".habilitacion_sifen y el archivo en /public/_lib/php-sifen3/certificados/{$certificado}");
        }

        $log("CERTIFICADO: Usando $certPath");
        $certPass = $habilitacion['cert_pass'] ?? '';
        $empresaCertPass = $empresa['cert_pass'] ?? '';
        $empresaPassCert = $empresa['password_certificado'] ?? '';
        $empresaClaveCert = $empresa['clave_certificado'] ?? '';

        // 2. Generar PEM manualmente y correctamente
        $pkcs12Content = file_get_contents($certPath);
        $log("PKCS12: Archivo=$certPath, tamaño=" . strlen($pkcs12Content) . " bytes");

        $certs = null;
        $passCandidates = array_values(array_filter(array_unique([
            $certPass,
            $empresaCertPass,
            $empresaPassCert,
            $empresaClaveCert,
            ''
        ]), fn($v) => $v !== null));

        $certPassUsed = null;
        foreach ($passCandidates as $candidate) {
            if (@openssl_pkcs12_read($pkcs12Content, $certs, $candidate)) {
                $certPassUsed = $candidate;
                break;
            }
        }

        // Si PHP openssl_pkcs12_read falla, intentar con comando openssl del sistema (legacy)
        if ($certPassUsed === null) {
            $log("PHP openssl_pkcs12_read falló, intentando con comando openssl legacy...");

            // Intentar con cada contraseña candidata usando openssl del sistema
            foreach ($passCandidates as $candidate) {
                $escapedPath = escapeshellarg($certPath);
                $escapedPass = escapeshellarg($candidate);

                // Extraer clave privada y certificado usando openssl con -legacy
                $pemTmp = $logDir . '/cert_tmp_' . $idEmpresa . '_' . uniqid() . '.pem';
                $cmd = "openssl pkcs12 -in {$escapedPath} -passin pass:{$candidate} -out " . escapeshellarg($pemTmp) . " -nodes -legacy 2>&1";
                $output = shell_exec($cmd);

                if (file_exists($pemTmp) && filesize($pemTmp) > 100) {
                    $pemContent = file_get_contents($pemTmp);
                    @unlink($pemTmp);

                    if (strpos($pemContent, 'PRIVATE KEY') !== false && strpos($pemContent, 'CERTIFICATE') !== false) {
                        $certPassUsed = $candidate;
                        $log("PKCS12: Convertido exitosamente con openssl legacy");

                        // Guardar el PEM final
                        $pemPath = $logDir . '/cert_pos_' . $idEmpresa . '.pem';
                        if (file_put_contents($pemPath, $pemContent) === false) {
                            throw new Exception("No se pudo escribir PEM temporal en $pemPath");
                        }
                        $log("PEM GENERADO EXITOSAMENTE (via openssl legacy)");

                        // Saltar la generación normal de PEM
                        goto skip_pem_generation;
                    }
                }
                @unlink($pemTmp);
            }

            $log("ERROR OPENSSL: " . openssl_error_string());
            throw new Exception("Error al leer el certificado PKCS12. Verifique la contraseña. (Archivo: '$certPath')");
        }

        $certPass = $certPassUsed;
        $log("PKCS12: Leído exitosamente con contraseña configurada");

        // El PEM debe contener la clave privada y el certificado
        $pemContent = $certs['pkey'] . "\n" . $certs['cert'] . "\n";
        if (!empty($certs['extracerts'])) {
            foreach ($certs['extracerts'] as $extra) {
                $pemContent .= $extra . "\n";
            }
        }

        $pemPath = $logDir . '/cert_pos_' . $idEmpresa . '.pem';
        if (!file_put_contents($pemPath, $pemContent)) {
            throw new Exception("No se pudo escribir el archivo PEM temporal en $pemPath");
        }
        $log("PEM GENERADO EXITOSAMENTE");

        skip_pem_generation:

        // 3. Instanciar Clases Base SIFEN
        date_default_timezone_set('America/Asuncion');

        // Usamos el PEM. SIFEN ignorará el bloque de conversión de .p12
        // El PEM generado ya está desencriptado
        $key = new \sifen\KEY($pemPath, '');
        $sifen = new \sifen\Sifen($ambiente, $key);
        $cscValue = (string)($habilitacion['csc'] ?? '');
        $idcValue = (string)($habilitacion['id_csc'] ?? '1');
        if (method_exists($sifen, 'setCSC')) {
            $sifen->setCSC($cscValue);
        } else {
            $sifen->csc = $cscValue;
        }
        if (method_exists($sifen, 'setIdc')) {
            $sifen->setIdc($idcValue);
        } elseif (method_exists($sifen, 'setIDC')) {
            $sifen->setIDC($idcValue);
        } else {
            $sifen->idc = $idcValue;
        }
        $log("SIFEN INSTANCIADO: Ambiente=$ambiente");

        // 3. Obtener Datos de la Factura y Cliente
        $stmtFact = $pdo->prepare("
            SELECT f.*, c.nombre as cli_nom, c.numero as cli_ruc, c.direccion as cli_dir, c.email as cli_email, c.telefono as cli_tel, c.ciudad as cli_ciudad
            FROM $dbName.factura_ventas f
            LEFT JOIN $dbName.clientes c ON c.id = f.id_cliente
            WHERE f.id_factura = :id
        ");
        $stmtFact->execute([':id' => $idFactura]);
        $fact = $stmtFact->fetch(PDO::FETCH_ASSOC);

        if (!$fact) throw new Exception("Registro de factura no encontrado en la base de datos");

        // 4. Resolver Establecimiento/Punto por Sucursal (antes de poblar FE)
        $establecimientoConfig = '001';
        $puntoConfig = '001';
        $idSucursal = null;
        $cajaNombre = null;

        $cajaRow = null;
        if (!empty($fact['id_sucursal'])) {
            $idSucursal = (int)$fact['id_sucursal'];
        } elseif (!empty($fact['id_caja'])) {
            $stmtCajaSuc = $pdo->prepare("SELECT * FROM $dbName.cajas WHERE id_caja = :id LIMIT 1");
            $stmtCajaSuc->execute([':id' => $fact['id_caja']]);
            $cajaData = $stmtCajaSuc->fetch(PDO::FETCH_ASSOC);
            if ($cajaData) {
                $cajaRow = $cajaData;
                $idSucursal = (int)$cajaData['id_sucursal'];
                $cajaNombre = $cajaData['caja'] ?? null;
            }
        }

        if (!empty($idSucursal)) {
            $stmtSuc = $pdoMaster->prepare("SELECT id, codigo_establecimiento, punto_expedicion_defecto
                FROM {$masterDb}.habilitacion_sifen_sucursales
                WHERE id_habilitacion = :id_hab AND id_sucursal = :id_suc AND activo = 1
                LIMIT 1");
            $stmtSuc->execute([
                ':id_hab' => $habilitacion['id'],
                ':id_suc' => $idSucursal
            ]);
            $sucursalConf = $stmtSuc->fetch(PDO::FETCH_ASSOC);
            if ($sucursalConf) {
                $establecimientoConfig = str_pad($sucursalConf['codigo_establecimiento'], 3, '0', STR_PAD_LEFT);
                $puntoConfig = str_pad($sucursalConf['punto_expedicion_defecto'], 3, '0', STR_PAD_LEFT);

                // Intentar mapear punto por caja (opcional)
                if (!empty($cajaNombre)) {
                    $stmtPunto = $pdoMaster->prepare("SELECT codigo_punto
                        FROM {$masterDb}.habilitacion_sifen_puntos_expedicion
                        WHERE id_habilitacion_sucursal = :id_hs AND activo = 1 AND descripcion LIKE :desc
                        LIMIT 1");
                    $stmtPunto->execute([
                        ':id_hs' => $sucursalConf['id'],
                        ':desc' => '%' . $cajaNombre . '%'
                    ]);
                    $puntoRow = $stmtPunto->fetch(PDO::FETCH_ASSOC);
                    if ($puntoRow) {
                        $puntoConfig = str_pad($puntoRow['codigo_punto'], 3, '0', STR_PAD_LEFT);
                    }
                }
            }
        }

        // Fallback: Si no hay sucursal configurada, obtener de habilitacion_sifen_documentos (tipo_documento = 1 para FE)
        if ($establecimientoConfig === '001' && $puntoConfig === '001') {
            $stmtDocDefault = $pdoMaster->prepare("
                SELECT codigo_establecimiento, punto_expedicion
                FROM {$masterDb}.habilitacion_sifen_documentos
                WHERE id_habilitacion = :id_hab AND tipo_documento = 1 AND activo = 1
                LIMIT 1
            ");
            $stmtDocDefault->execute([':id_hab' => $habilitacion['id']]);
            $docDefault = $stmtDocDefault->fetch(PDO::FETCH_ASSOC);
            if ($docDefault) {
                $establecimientoConfig = str_pad($docDefault['codigo_establecimiento'], 3, '0', STR_PAD_LEFT);
                $puntoConfig = str_pad($docDefault['punto_expedicion'], 3, '0', STR_PAD_LEFT);
                $log("FALLBACK DOCUMENTOS: Establecimiento=$establecimientoConfig, Punto=$puntoConfig");
            }
        }

        // 5. Preparar datos base y poblar tabla FE
        $docRaw = trim($fact['cli_ruc'] ?? '0');
        $tipoDoc = (strpos($docRaw, '-') !== false) ? 'ruc' : 'ci';
        if ($docRaw == '0' || empty($docRaw)) {
            $docRaw = '0';
            $tipoDoc = 'ci';
        }

        $numFacturaParts = explode('-', $fact['nro_factura']);
        $ndoc = str_replace('-', '', $numFacturaParts[2] ?? $fact['nro_factura']);

        $denominacionSucursal = $empresa['empresa'] ?? 'SUCURSAL';
        if (!empty($idSucursal)) {
            try {
                $stmtNomSuc = $pdo->prepare("SELECT descripcion FROM $dbName.sucursales WHERE id_sucursal = :id LIMIT 1");
                $stmtNomSuc->execute([':id' => $idSucursal]);
                $nomSuc = $stmtNomSuc->fetchColumn();
                if (!empty($nomSuc)) $denominacionSucursal = $nomSuc;
            } catch (Exception $e) {
                // Mantener valor por defecto
            }
        }

        $fechaEmision = $fact['fecha'] ?? date('Y-m-d H:i:s');
        $fechaLimiteEnvio = date('Y-m-d H:i:s', strtotime($fechaEmision . ' +1 day'));
        $fechaLimiteTransmision = date('Y-m-d', strtotime($fechaEmision . ' +7 day'));

        $numeroCasaEmisor = $habilitacion['numero_casa'] ?? null;
        if (empty($numeroCasaEmisor)) {
            $numeroCasaEmisor = '0';
        }

        $numeroCasaCliente = $fact['cli_nro_casa'] ?? null;
        if (empty($numeroCasaCliente)) {
            $numeroCasaCliente = '0';
        }

        $pickHab = function (array $row, array $keys, $default = '') {
            foreach ($keys as $k) {
                if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
                    return $row[$k];
                }
            }
            return $default;
        };

        // Fuente oficial de timbrado/vigencia: serproc1.habilitacion_sifen (empresa logada)
        $numeroTimbrado = (string)$pickHab($habilitacion, ['numero_timbrado', 'timbrado'], ($fact['timbrado'] ?? ''));
        $fechaInicioTimbrado = (string)$pickHab(
            $habilitacion,
            ['fecha_inicio_vigencia', 'fecha_inicio_timbrado', 'inicio_vigencia'],
            date('Y-m-d')
        );
        if (!empty($fechaInicioTimbrado)) {
            $fechaInicioTimbrado = date('Y-m-d', strtotime($fechaInicioTimbrado));
        }
        $fechaFinTimbrado = (string)$pickHab(
            $habilitacion,
            ['fecha_fin_vigencia', 'fecha_fin_timbrado', 'vencimiento', 'fecha_vencimiento'],
            date('Y-m-d', strtotime('+1 year'))
        );
        if (!empty($fechaFinTimbrado)) {
            $fechaFinTimbrado = date('Y-m-d', strtotime($fechaFinTimbrado));
        }
        $establecimientoFromCaja = str_pad((string)($cajaRow['factura_1'] ?? $establecimientoConfig), 3, '0', STR_PAD_LEFT);
        $expedicionFromCaja = str_pad((string)($cajaRow['factura_2'] ?? $puntoConfig), 3, '0', STR_PAD_LEFT);

        $ivaFactura = 0;
        if (isset($fact['iva10']) || isset($fact['iva5'])) {
            $ivaFactura = (float)($fact['iva10'] ?? 0) + (float)($fact['iva5'] ?? 0);
        }

        try {
            $stmtFe = $pdo->prepare("SELECT id FROM $dbName.fe WHERE id_factura = :id LIMIT 1");
            $stmtFe->execute([':id' => $idFactura]);
            $feExists = (bool)$stmtFe->fetchColumn();

            if (!$feExists) {
                $stmtInsertFe = $pdo->prepare("INSERT INTO $dbName.fe
                    (id_factura, numero_timbrado, establecimiento, punto_expedicion, numero_documento,
                     fecha_inicio_timbrado, fecha_fin_timbrado, ruc_emisor, dv_emisor, razon_social_emisor,
                     direccion_emisor, numero_casa_emisor, complemento_direccion1, complemento_direccion2,
                     telefono_emisor, email_emisor, denominacion_sucursal, codigo_actividad_economica,
                     naturaleza_receptor, tipo_operacion, pais_receptor, cliente, ruc_cliente,
                     direccion_cliente, numero_casa_cliente, complemento_direccion1_cliente, complemento_direccion2_cliente,
                     telefono_cliente, email_cliente, nro_factura, fecha_emision, fecha_limite_envio,
                     estado_transmision, total, iva, intentos_transmision, fecha_limite_transmision, estado_electronico)
                    VALUES
                    (:id_factura, :numero_timbrado, :establecimiento, :punto_expedicion, :numero_documento,
                     :fecha_inicio_timbrado, :fecha_fin_timbrado, :ruc_emisor, :dv_emisor, :razon_social_emisor,
                     :direccion_emisor, :numero_casa_emisor, :complemento_direccion1, :complemento_direccion2,
                     :telefono_emisor, :email_emisor, :denominacion_sucursal, :codigo_actividad_economica,
                     :naturaleza_receptor, :tipo_operacion, :pais_receptor, :cliente, :ruc_cliente,
                     :direccion_cliente, :numero_casa_cliente, :complemento_direccion1_cliente, :complemento_direccion2_cliente,
                     :telefono_cliente, :email_cliente, :nro_factura, :fecha_emision, :fecha_limite_envio,
                     :estado_transmision, :total, :iva, :intentos_transmision, :fecha_limite_transmision, :estado_electronico)");

                $stmtInsertFe->execute([
                    ':id_factura' => $idFactura,
                    ':numero_timbrado' => $numeroTimbrado,
                    ':establecimiento' => $establecimientoFromCaja,
                    ':punto_expedicion' => $expedicionFromCaja,
                    ':numero_documento' => $ndoc,
                    ':fecha_inicio_timbrado' => $fechaInicioTimbrado,
                    ':fecha_fin_timbrado' => $fechaFinTimbrado,
                    ':ruc_emisor' => (string)$rucEmisorBase,
                    ':dv_emisor' => (string)$dvEmisorBase,
                    ':razon_social_emisor' => (string)($habilitacion['razon_social'] ?? $empresa['empresa']),
                    ':direccion_emisor' => (string)($habilitacion['direccion'] ?? $empresa['direccion'] ?? 'PARAGUAY'),
                    ':numero_casa_emisor' => $numeroCasaEmisor,
                    ':complemento_direccion1' => null,
                    ':complemento_direccion2' => null,
                    ':telefono_emisor' => (string)($habilitacion['telefono'] ?? $empresa['telefono'] ?? '0900000000'),
                    ':email_emisor' => (string)($habilitacion['email'] ?? $empresa['email'] ?? 'soporte@sistemax.com.py'),
                    ':denominacion_sucursal' => $denominacionSucursal,
                    ':codigo_actividad_economica' => (string)($actividad['codigo'] ?? '62010'),
                    ':naturaleza_receptor' => ($tipoDoc === 'ruc') ? '1' : '2',
                    ':tipo_operacion' => '1',
                    ':pais_receptor' => 'PY',
                    ':cliente' => (string)($fact['cli_nom'] ?: 'CLIENTE SIN NOMBRE'),
                    ':ruc_cliente' => (string)($docRaw ?: '0'),
                    ':direccion_cliente' => (string)($fact['cli_dir'] ?: 'SIN DIRECCION'),
                    ':numero_casa_cliente' => $numeroCasaCliente,
                    ':complemento_direccion1_cliente' => null,
                    ':complemento_direccion2_cliente' => null,
                    ':telefono_cliente' => (string)($fact['cli_tel'] ?: '000000000'),
                    ':email_cliente' => (string)($fact['cli_email'] ?: 'sin-email@sistemax.com.py'),
                    ':nro_factura' => (string)$fact['nro_factura'],
                    ':fecha_emision' => $fechaEmision,
                    ':fecha_limite_envio' => $fechaLimiteEnvio,
                    ':estado_transmision' => 'PENDIENTE',
                    ':total' => (float)($fact['total'] ?? 0),
                    ':iva' => $ivaFactura,
                    ':intentos_transmision' => 0,
                    ':fecha_limite_transmision' => $fechaLimiteTransmision,
                    ':estado_electronico' => 'Pendiente'
                ]);
            } else {
                // Re-sincronizar datos fiscales clave para FE ya existente
                $stmtSyncFe = $pdo->prepare("UPDATE $dbName.fe
                    SET numero_timbrado = :numero_timbrado,
                        establecimiento = :establecimiento,
                        punto_expedicion = :punto_expedicion,
                        fecha_inicio_timbrado = :fecha_inicio_timbrado,
                        fecha_fin_timbrado = :fecha_fin_timbrado
                    WHERE id_factura = :id_factura");
                $stmtSyncFe->execute([
                    ':numero_timbrado' => $numeroTimbrado,
                    ':establecimiento' => $establecimientoFromCaja,
                    ':punto_expedicion' => $expedicionFromCaja,
                    ':fecha_inicio_timbrado' => $fechaInicioTimbrado,
                    ':fecha_fin_timbrado' => $fechaFinTimbrado,
                    ':id_factura' => $idFactura
                ]);
            }
        } catch (Exception $e) {
            $log("FE TABLE ERROR: " . $e->getMessage());
        }

        // Leer registro FE para usarlo en la emisión
        $feRow = null;
        try {
            $stmtFeRow = $pdo->prepare("SELECT * FROM $dbName.fe WHERE id_factura = :id LIMIT 1");
            $stmtFeRow->execute([':id' => $idFactura]);
            $feRow = $stmtFeRow->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $log("FE ROW ERROR: " . $e->getMessage());
        }
        if (!$feRow) {
            $feRow = [];
        }

        // 6. Mapear Emisor (desde FE si existe)
        $emisor = new \sifen\Emisor([
            'ruc' => \sifen\Sifen::getRuc((string)($feRow['ruc_emisor'] ?? $rucEmisorBase)),
            'dv' => (string)($feRow['dv_emisor'] ?? $dvEmisorBase),
            'razon_social' => (string)($feRow['razon_social_emisor'] ?? $habilitacion['razon_social'] ?? $empresa['empresa']),
            'tipo_contribuyente' => (string)($habilitacion['tipo_contribuyente'] ?? '2'),
            'ciudad' => (int)($habilitacion['cod_ciudad'] ?? 5393),
            'direccion' => (string)($feRow['direccion_emisor'] ?? $habilitacion['direccion'] ?? $empresa['direccion'] ?? 'PARAGUAY'),
            'telefono' => (string)($feRow['telefono_emisor'] ?? $habilitacion['telefono'] ?? $empresa['telefono'] ?? '0900000000'),
            'email' => (string)($feRow['email_emisor'] ?? $habilitacion['email'] ?? $empresa['email'] ?? 'soporte@sistemax.com.py'),
            'act_eco' => (string)($feRow['codigo_actividad_economica'] ?? $actividad['codigo'] ?? '62010'),
            'act_eco_desc' => (string)($actividad['descripcion'] ?? 'ACTIVIDADES DE PROGRAMACIÓN INFORMÁTICA')
        ]);

        // 7. Mapear Receptor (desde FE si existe)
        $receptor = new \sifen\Receptor([
            'documento' => (string)($feRow['ruc_cliente'] ?? $docRaw),
            'tipo_doc' => $tipoDoc,
            'razon_social' => (string)($feRow['cliente'] ?? ($fact['cli_nom'] ?: 'CLIENTE SIN NOMBRE')),
            'direccion' => (string)($feRow['direccion_cliente'] ?? ($fact['cli_dir'] ?: 'SIN DIRECCION')),
            'telefono' => (string)($feRow['telefono_cliente'] ?? ($fact['cli_tel'] ?: '000000000')),
            'email' => (string)($feRow['email_cliente'] ?? ($fact['cli_email'] ?: 'sin-email@sistemax.com.py')),
            'ciudad' => (int)($fact['cli_ciudad'] ?: 1)
        ]);

        // 6. Mapear Items desde extracto_productos
        $stmtItems = $pdo->prepare("
             SELECT i.*
             FROM $dbName.extracto_productos i
             WHERE i.idfactura = :id AND i.salida > 0
        ");
        $stmtItems->execute([':id' => $idFactura]);
        $itemsDB = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $conceptos = [];
        $totalSumaItems = 0;
        foreach ($itemsDB as $itm) {
            // Mapeo correcto: tipo_iva en extracto_productos
            // 0 = Exento (0%), 2 = IVA 5%, 3 = IVA 10%
            $tipoIvaDB = (int)$itm['tipo_iva'];
            if ($tipoIvaDB == 0 || $tipoIvaDB == 1) {
                $tasa = 0; // Exento
            } elseif ($tipoIvaDB == 2) {
                $tasa = 5;
            } else {
                $tasa = 10; // 3 o cualquier otro valor
            }

            $concepto = new \sifen\Concepto([
                'codigo' => $itm['codigo'] ?: $itm['idproducto'],
                'descripcion' => substr($itm['descripcion'], 0, 120),
                'precio' => (float)$itm['precio'],
                'cantidad' => (float)$itm['salida'],
                'tasa_iva' => $tasa,
                'descuento' => 0,
                'proporcion_iva' => 100,
                'unidad_medida' => '77'
            ]);
            $conceptos[] = $concepto;
            $totalSumaItems += ($concepto->precio * $concepto->cantidad);
        }

        // 7. Configurar Condición y Pagos
        // Regla correcta POS:
        // - Crédito SOLO cuando forma_pago=5 o condicion textual=credito.
        // - Tarjeta/transferencia/pix siguen siendo contado.
        $formaPagoPos = (int)($fact['forma_pago'] ?? 1);
        $condicionRaw = strtolower(trim((string)($fact['condicion'] ?? '')));
        $formaVentaRaw = isset($fact['forma_venta']) ? (int)$fact['forma_venta'] : 0;

        $isCredito = false;
        if ($formaPagoPos === 5) {
            $isCredito = true;
        } elseif ($condicionRaw === 'credito' || $condicionRaw === 'crédito') {
            $isCredito = true;
        } elseif ($formaVentaRaw > 0 && $formaVentaRaw !== 1) {
            // Compatibilidad con esquemas legacy donde forma_venta codifica condición.
            $isCredito = true;
        }

        $condicionStr = $isCredito ? 'credito' : 'contado';

        $formas_pagos = [];
        if ($condicionStr === 'contado') {
            // Mapeo POS -> SIFEN (IMPORTANTE: en SIFEN, 2 = Cheque).
            // POS: 1=Efectivo, 2=Tarjeta, 3=Transferencia, 4=QR/PIX, 5=Crédito.
            $formaPagoPosRaw = (int)($fact['forma_pago'] ?? 1);
            $medioSifen = 1; // efectivo por defecto
            $cardMeta = [
                'financing_type' => '',
                'brand' => '',
                'processor' => '',
                'auth_code' => '',
                'nsu' => '',
                'masked_pan' => '',
            ];

            $mapCardBrand = static function (string $brand): array {
                $b = strtolower(trim($brand));
                if ($b === '') return [99, 'OTRO'];
                if (strpos($b, 'visa') !== false) return [1, 'VISA'];
                if (strpos($b, 'master') !== false) return [2, 'MASTERCARD'];
                if (strpos($b, 'american') !== false || strpos($b, 'amex') !== false) return [3, 'AMEX'];
                if (strpos($b, 'maestro') !== false) return [4, 'MAESTRO'];
                if (strpos($b, 'cabal') !== false) return [5, 'CABAL'];
                if (strpos($b, 'diners') !== false) return [6, 'DINERS'];
                if (strpos($b, 'jcb') !== false) return [7, 'JCB'];
                if (strpos($b, 'elo') !== false) return [8, 'ELO'];
                return [99, strtoupper(substr($brand, 0, 30)) ?: 'OTRO'];
            };

            if ($formaPagoPosRaw === 2) {
                // Si se conoce el tipo de tarjeta, distinguir crédito/débito.
                $cardFinType = '';
                try {
                    $stmtPagoMeta = $pdo->prepare("
                        SELECT card_financing_type, card_brand, card_processor, card_auth_code, card_nsu, card_masked_pan
                        FROM {$dbName}.factura_ventas_pagos
                        WHERE id_factura = :id
                          AND LOWER(COALESCE(method, '')) IN ('tarjeta','card')
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $stmtPagoMeta->execute([':id' => $idFactura]);
                    $rowPagoMeta = $stmtPagoMeta->fetch(PDO::FETCH_ASSOC) ?: [];
                    if (!$rowPagoMeta) {
                        $stmtPagoMeta = $pdo->prepare("
                            SELECT card_financing_type, card_brand, card_processor, card_auth_code, card_nsu, card_masked_pan
                            FROM {$dbName}.factura_ventas_pagos
                            WHERE id_factura = :id
                            ORDER BY id DESC
                            LIMIT 1
                        ");
                        $stmtPagoMeta->execute([':id' => $idFactura]);
                        $rowPagoMeta = $stmtPagoMeta->fetch(PDO::FETCH_ASSOC) ?: [];
                    }
                    $cardMeta = [
                        'financing_type' => strtolower(trim((string)($rowPagoMeta['card_financing_type'] ?? ''))),
                        'brand' => trim((string)($rowPagoMeta['card_brand'] ?? '')),
                        'processor' => trim((string)($rowPagoMeta['card_processor'] ?? '')),
                        'auth_code' => trim((string)($rowPagoMeta['card_auth_code'] ?? '')),
                        'nsu' => trim((string)($rowPagoMeta['card_nsu'] ?? '')),
                        'masked_pan' => trim((string)($rowPagoMeta['card_masked_pan'] ?? '')),
                    ];
                    $cardFinType = $cardMeta['financing_type'];
                } catch (Throwable $e) {
                    $cardFinType = '';
                    $cardMeta = [
                        'financing_type' => '',
                        'brand' => '',
                        'processor' => '',
                        'auth_code' => '',
                        'nsu' => '',
                        'masked_pan' => '',
                    ];
                }
                $medioSifen = ($cardFinType === 'debito' || $cardFinType === 'débito') ? 4 : 3; // 3=TC, 4=TD
            } elseif ($formaPagoPosRaw === 3) {
                $medioSifen = 5; // Transferencia
            } elseif ($formaPagoPosRaw === 4) {
                $medioSifen = 21; // Pago electrónico (QR/PIX)
            } elseif ($formaPagoPosRaw === 1) {
                $medioSifen = 1; // Efectivo
            }

            $formaPagoItem = [
                'tipo_pago' => $medioSifen,
                'monto' => $totalSumaItems,
                'moneda' => 'PYG',
                'cambio' => 1
            ];
            if ($medioSifen === 3 || $medioSifen === 4) {
                [$denTarj, $denTarjDesc] = $mapCardBrand((string)$cardMeta['brand']);
                $authDigits = preg_replace('/\D+/', '', (string)($cardMeta['auth_code'] ?: $cardMeta['nsu']));
                if (!is_string($authDigits)) $authDigits = '';
                $last4 = preg_replace('/\D+/', '', (string)$cardMeta['masked_pan']);
                if (!is_string($last4)) $last4 = '';
                if (strlen($last4) >= 4) $last4 = substr($last4, -4);
                if (strlen($authDigits) < 6 || strlen($authDigits) > 10) $authDigits = '';
                if (strlen($last4) !== 4) $last4 = '';

                $formaPagoItem['den_tarjeta'] = $denTarj;            // E621
                $formaPagoItem['den_tarjeta_desc'] = $denTarjDesc;   // E622
                $formaPagoItem['forma_proc_pago'] = 1;               // E626: POS
                if ($authDigits !== '') {
                    $formaPagoItem['cod_aut_ope'] = $authDigits;     // E627 (opcional)
                }
                if ($last4 !== '') {
                    $formaPagoItem['num_tarjeta'] = $last4;          // E629 (opcional)
                }
            }
            $formas_pagos[] = new \sifen\FormasPago($formaPagoItem);
        }

        // 9. Armar Factura Objeto

        $facturaData = [
            'emisor' => $emisor,
            'receptor' => $receptor,
            'formas_pago' => $formas_pagos,
            'conceptos' => $conceptos,
            'ndoc' => $ndoc,
            'condicion' => $condicionStr,
            'timbrado' => (string)($feRow['numero_timbrado'] ?? $numeroTimbrado),
            'fec_timbrado' => (string)($feRow['fecha_inicio_timbrado'] ?? $fechaInicioTimbrado),
            'cod_establecimiento' => (string)($feRow['establecimiento'] ?? $establecimientoFromCaja),
            'cod_expedicion' => (string)($feRow['punto_expedicion'] ?? $expedicionFromCaja),
            'moneda' => 'PYG',
            'cambio' => 1
        ];

        if ($condicionStr === 'credito') {
            $facturaData['plazo'] = [
                'condicion' => 1, // 1=Plazo (Días)
                'valor' => 30      // Default 30 días si no se especifica
            ];
        }

        $facturaObj = new \sifen\Factura(\sifen\DTE::FACT_VENTA, $facturaData);
        $sifen->agregarFactura($facturaObj);

        // 9. Construir y Enviar
        $log("CONSTRUYENDO XML...");

        $oldCwd = getcwd();
        chdir($logDir);
        $log("CWD ACTUAL: " . getcwd());

        try {
            // Generar XML sin firma
            $xmlDraft = $sifen->buildXML(false);
            $xmlGeneradoStr = $xmlDraft->asXML();
            $log("XML GENERADO (SIN FIRMA)");

            try {
                $stmtFeGen = $pdo->prepare("UPDATE $dbName.fe SET xml_generado = :xml_g WHERE id_factura = :id");
                $stmtFeGen->execute([':xml_g' => $xmlGeneradoStr, ':id' => $idFactura]);
            } catch (Exception $e) {
                $log("FE UPDATE XML_GENERADO ERROR: " . $e->getMessage());
            }

            // Generar XML firmado en modo LOTE ASÍNCRONO (rEnvioLote), recomendado para SIFEN Paraguay.
            $xmlRequest = $sifen->buildXML(true);
            $log("XML CONSTRUIDO Y FIRMADO (modo lote asíncrono)");

            // Extraer CDC generado (ya con DV)
            $cdcFinal = $facturaObj->cdc . \sifen\Sifen::getRuc($facturaObj->cdc, 'dv');
            $log("CDC GENERADO: $cdcFinal");

            // Guardado INMEDIATO del CDC y XML Firmado
            $xmlSignedStr = $xmlRequest->asXML();
            // Volvemos al CWD original para PDO y otros si fuera necesario (aunque $pdo es global)
            chdir($oldCwd);

            $stmtPre = $pdo->prepare("UPDATE $dbName.factura_ventas SET cdc = :cdc, xml_firmado = :xml_f, fecha_generacion_xml = NOW(), estado_sifen = 'Guardado Local' WHERE id_factura = :id");
            $stmtPre->execute([':cdc' => $cdcFinal, ':xml_f' => $xmlSignedStr, ':id' => $idFactura]);
            $log("PERSISTENCIA LOCAL EXITOSA: Factura $idFactura");

            try {
                $stmtFeLocal = $pdo->prepare("UPDATE $dbName.fe
                    SET xml_generado = :xml_g, xml_firmado = :xml_f, estado_transmision = 'LOCAL', estado_electronico = 'Guardado Local', fecha_transmision = NOW()
                    WHERE id_factura = :id");
                $stmtFeLocal->execute([
                    ':xml_g' => $xmlGeneradoStr,
                    ':xml_f' => $xmlSignedStr,
                    ':id' => $idFactura
                ]);
            } catch (Exception $e) {
                $log("FE UPDATE LOCAL ERROR: " . $e->getMessage());
            }
        } catch (Throwable $signErr) {
            chdir($oldCwd);
            $log("ERROR DURANTE FIRMA/CONSTRUCCIÓN: " . $signErr->getMessage());
            throw $signErr;
        }

        $log("ENVIANDO A SIFEN ($ambiente)...");
        $respuesta = $sifen->enviar($xmlRequest);
        $xmlEnvioRespuesta = (string)($respuesta['response'] ?? '');
        if (trim($xmlEnvioRespuesta) !== '') {
            $log("RESPUESTA ENVIO XML (inicio): " . substr(preg_replace('/\s+/', ' ', $xmlEnvioRespuesta), 0, 800));
        }

        $jsonEnvio = null;
        if (trim($xmlEnvioRespuesta) !== '' && preg_match('/^\s*[\{\[]/', $xmlEnvioRespuesta)) {
            $jsonEnvio = json_decode($xmlEnvioRespuesta, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonEnvio)) {
                $errTxt = trim((string)($jsonEnvio['error'] ?? $jsonEnvio['message'] ?? ''));
                if ($errTxt !== '') {
                    throw new Exception('Error de transporte/proxy SIFEN: ' . $errTxt);
                }
            }
        }

        if (($respuesta['status'] ?? '') !== 'ok') {
            $msgErr = $respuesta['error'] ?? 'Error desconocido';
            $msgErrUpper = strtoupper((string)$msgErr);
            $isDuplicado = (strpos($msgErrUpper, 'DUPLIC') !== false);

            // Caso especial: SIFEN indica documento duplicado.
            // No cancelar ciegamente: consultar estado real por CDC.
            if ($isDuplicado && !empty($cdcFinal) && method_exists($sifen, 'siConsDE')) {
                $estadoDup = 'Pendiente';
                $msgDup = 'Documento electrónico duplicado. SIFEN recibió previamente este CDC; pendiente de confirmación final.';
                $xmlDup = '';
                $protAutDup = null;

                try {
                    $resDe = $sifen->siConsDE((string)$cdcFinal);
                    if (is_array($resDe)) {
                        $xmlDup = (string)($resDe['response'] ?? $resDe['xml'] ?? '');
                        if ($xmlDup === '') {
                            $tmpXml = json_encode($resDe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $xmlDup = is_string($tmpXml) ? $tmpXml : '';
                        }
                    } elseif (is_object($resDe)) {
                        $tmpXml = json_encode($resDe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $xmlDup = is_string($tmpXml) ? $tmpXml : '';
                    } else {
                        $xmlDup = (string)$resDe;
                    }

                    $msgResDe = $extractXmlValue($xmlDup, ['dMsgRes', 'dDesMotivoRechazo', 'xMotivoRechazo', 'dMotivo', 'dDesRes']);
                    $estResDe = strtoupper($extractXmlValue($xmlDup, ['dEstRes']));
                    $codResDe = strtoupper($extractXmlValue($xmlDup, ['dCodRes']));
                    $protAutDup = $extractXmlValue($xmlDup, ['dProtAut']) ?: null;
                    $msgUpperDe = strtoupper((string)$msgResDe);

                    $aprobDe = strpos($msgUpperDe, 'APROBADO') !== false
                        || in_array($estResDe, ['APROBADO', '1', '001', '100'], true)
                        || in_array($codResDe, ['APROBADO', '001', '100'], true);
                    $rechDe = strpos($msgUpperDe, 'RECHAZ') !== false
                        || strpos($msgUpperDe, 'DENEG') !== false
                        || strpos($msgUpperDe, 'INVALID') !== false
                        || in_array($estResDe, ['RECHAZADO', '2', '002', '200'], true)
                        || in_array($codResDe, ['RECHAZADO', '0500', '500'], true);

                    if ($aprobDe) {
                        $estadoDup = 'Aprobado';
                        $msgDup = ($msgResDe !== '' ? $msgResDe : 'Documento ya aprobado previamente en SIFEN');
                    } elseif ($rechDe) {
                        $estadoDup = 'Rechazado';
                        $msgDup = ($msgResDe !== '' ? $msgResDe : 'Documento rechazado en SIFEN');
                    } elseif ($msgResDe !== '') {
                        $msgDup = $msgResDe;
                    }
                } catch (Throwable $eDup) {
                    $log('DUPLICADO: fallo consulta por CDC: ' . $eDup->getMessage());
                }

                $stmtDup = $pdo->prepare("UPDATE $dbName.factura_ventas
                    SET estado_sifen = :est,
                        mensaje_sifen = :msg,
                        protocolo_autorizacion = :prot_aut,
                        xml_respuesta = :xml_r,
                        fecha_envio_sifen = NOW()
                    WHERE id_factura = :id");
                $stmtDup->execute([
                    ':est' => $estadoDup,
                    ':msg' => substr($msgDup, 0, 255),
                    ':prot_aut' => $protAutDup,
                    ':xml_r' => $xmlDup,
                    ':id' => $idFactura
                ]);

                try {
                    $stmtFeDup = $pdo->prepare("UPDATE $dbName.fe
                        SET estado_transmision = :tx,
                            estado_electronico = :est,
                            mensaje_set = :msg,
                            acuse_sifen = :xml_r,
                            fecha_transmision = NOW()
                        WHERE id_factura = :id");
                    $txDup = 'PENDIENTE';
                    if ($estadoDup === 'Aprobado') $txDup = 'ENVIADO';
                    if ($estadoDup === 'Rechazado') $txDup = 'RECHAZADO';
                    $stmtFeDup->execute([
                        ':tx' => $txDup,
                        ':est' => $estadoDup,
                        ':msg' => substr($msgDup, 0, 255),
                        ':xml_r' => $xmlDup,
                        ':id' => $idFactura
                    ]);
                } catch (Exception $e) {
                    $log("FE UPDATE DUP ERROR: " . $e->getMessage());
                }

                return [
                    'success' => ($estadoDup === 'Aprobado'),
                    'cdc' => $cdcFinal,
                    'estado' => $estadoDup,
                    'mensaje' => $msgDup,
                    'message' => $msgDup,
                    'qr' => '',
                    'prot_cons_lote_sifen' => null
                ];
            }

            // Incluir CSC y RUC en el mensaje de error para diagnóstico
            $configDetails = " [RUC: " . ($rucEmisorBase ?: 'N/A') . " | CSC: " . (isset($habilitacion['csc']) ? substr($habilitacion['csc'], 0, 4) . "..." : 'N/A') . "]";
            $msgErrWithConfig = $msgErr . $configDetails;

            $stmtFail = $pdo->prepare("UPDATE $dbName.factura_ventas SET estado_sifen = 'Error Envío', mensaje_sifen = :msg WHERE id_factura = :id");
            $stmtFail->execute([':msg' => substr($msgErrWithConfig, 0, 255), ':id' => $idFactura]);
            try {
                $stmtFeFail = $pdo->prepare("UPDATE $dbName.fe
                    SET estado_transmision = 'ERROR', estado_electronico = 'Error Envío', mensaje_error = :msg, intentos_transmision = intentos_transmision + 1
                    WHERE id_factura = :id");
                $stmtFeFail->execute([':msg' => substr($msgErrWithConfig, 0, 255), ':id' => $idFactura]);
            } catch (Exception $e) {
                $log("FE UPDATE ERROR ENVIO: " . $e->getMessage());
            }
            throw new Exception("Error en envío a SIFEN: " . $msgErrWithConfig);
        }

        $protConsLote = $sifen->getProtConsLote();
        $log("RESPUESTA ENVIO: ProtConsLote=" . ($protConsLote ?: 'N/A'));
        $qrUrl = '';
        if (method_exists($sifen, 'getQrUrl')) {
            $qrUrl = (string)$sifen->getQrUrl();
        } elseif (!empty($sifen->facturas) && isset($sifen->facturas[0]) && isset($sifen->facturas[0]->qrUrl)) {
            $qrUrl = (string)$sifen->facturas[0]->qrUrl;
        }
        if ($qrUrl !== '') {
            // El SDK puede devolver entidades HTML (&amp;). Normalizar a URL real.
            $qrUrl = html_entity_decode($qrUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $qrUrl = str_replace('&amp;', '&', $qrUrl);
            $qrUrl = trim($qrUrl);
        }

        $estadoValidado = 'Pendiente';
        $mensajeSifen = '';
        $protocoloAuto = null;
        $resXml = $xmlEnvioRespuesta;
        $evalEstado = function (string $xml, string $msgFallback = '', ?string $protFallback = null) use ($extractXmlValue, $cdcFinal) {
            $msgRes = $extractXmlValue($xml, ['dMsgRes', 'dMsgResLot']);
            if ($msgRes === '') {
                $msgRes = trim($msgFallback);
            }

            $estRes = strtoupper($extractXmlValue($xml, ['dEstRes']));
            $codRes = strtoupper($extractXmlValue($xml, ['dCodRes']));
            $cdcResp = strtoupper(trim($extractXmlValue($xml, ['dCDC', 'Id', 'id'])));
            $protAut = $extractXmlValue($xml, ['dProtAut']) ?: ($protFallback ?? '');
            $resUpper = strtoupper($xml);
            $msgUpper = strtoupper((string)$msgRes);

            // IMPORTANTE:
            // dCodRes=0300 en rResEnviLoteDe solo significa "lote recibido",
            // NO aprobación del documento.
            // Aprobado se determina con evidencia de resultado del DE (dEstRes/dCodRes detalle).
            $isAprobado = strpos($msgUpper, 'APROBADO') !== false
                || in_array($estRes, ['APROBADO', '1', '001', '100'], true)
                || in_array($codRes, ['APROBADO', '001', '100'], true)
                || preg_match('/<[^>]*:dEstRes>\s*(1|001|100|APROBADO)\s*<\/[^>]*:dEstRes>/i', $xml)
                || preg_match('/<[^>]*:dCodRes>\s*(001|100)\s*<\/[^>]*:dCodRes>/i', $xml);
            $isRechazado = strpos($resUpper, 'RECHAZ') !== false
                || strpos($msgUpper, 'RECHAZ') !== false
                || strpos($msgUpper, 'DENEG') !== false
                || strpos($msgUpper, 'INVALID') !== false
                || in_array($estRes, ['RECHAZADO', '2', '002', '200'], true)
                || in_array($codRes, ['RECHAZADO', '0500', '500'], true)
                || preg_match('/<[^>]*:dEstRes>\s*(2|002|200|RECHAZADO)\s*<\/[^>]*:dEstRes>/i', $xml)
                || preg_match('/<[^>]*:dCodRes>\s*(0500|500)\s*<\/[^>]*:dCodRes>/i', $xml);

            // Si el XML trae CDC y no coincide con el documento emitido, no marcar aprobado.
            if ($isAprobado && $cdcResp !== '' && strtoupper(trim((string)$cdcFinal)) !== '' && $cdcResp !== strtoupper(trim((string)$cdcFinal))) {
                $isAprobado = false;
                $msgRes = trim(($msgRes !== '' ? $msgRes . ' | ' : '') . 'CDC de respuesta no coincide con el CDC emitido');
            }

            if ($isAprobado) {
                return ['Aprobado', $msgRes !== '' ? $msgRes : 'Aprobado por SIFEN', $protAut];
            }
            if ($isRechazado) {
                return ['Rechazado', $msgRes !== '' ? $msgRes : 'Rechazado por SIFEN', $protAut];
            }
            return ['Pendiente', $msgRes, $protAut];
        };

        if ($fastPrint) {
            // Modo impresión rápida:
            // Se detiene tras generar, firmar y enviar (lote recibido o envío aceptado),
            // dejando confirmación final para consulta asíncrona.
            $estadoValidado = 'Pendiente';
            $mensajeSifen = 'FE generada y firmada. Lote enviado a SIFEN; confirmación final pendiente.';
            $protocoloAuto = null;
            $log("MODO FAST_PRINT activo: se difiere confirmación final por protocolo/CDC.");
        } elseif (!empty($protConsLote)) {
            $log("LOTE RECIBIDO. Prot: $protConsLote. Iniciando consultas de estado...");
            // Espera activa más amplia para emisión en flujo de caja:
            // crear -> firmar -> enviar -> confirmar por protocolo -> imprimir.
            $maxIntentos = 30; // ~60s con sleep(2)
            for ($intento = 1; $intento <= $maxIntentos; $intento++) {
                sleep(2);
                $resultadoLote = $sifen->queryLote($protConsLote);
                $resXmlLoop = (string)($resultadoLote['response'] ?? '');
                if (trim($resXmlLoop) !== '') {
                    $resXml = $resXmlLoop;
                }
                $log("CONSULTA $intento: Resultado obtenido");
                [$estadoTmp, $msgTmp, $protTmp] = $evalEstado(
                    $resXml,
                    (string)($resultadoLote['dMsgResLot'] ?? ''),
                    $protocoloAuto
                );
                if ($msgTmp !== '') {
                    $mensajeSifen = $msgTmp;
                }
                if (!empty($protTmp)) {
                    $protocoloAuto = $protTmp;
                }
                $estadoValidado = $estadoTmp;
                if ($estadoValidado === 'Aprobado' || $estadoValidado === 'Rechazado') {
                    break;
                }

                // Refuerzo: consultar por CDC mientras el lote está pendiente,
                // para detectar resolución final aunque queryLote siga "en proceso".
                if (!empty($cdcFinal) && method_exists($sifen, 'siConsDE')) {
                    try {
                        $resDe = $sifen->siConsDE((string)$cdcFinal);
                        $xmlDe = '';
                        if (is_array($resDe)) {
                            $xmlDe = (string)($resDe['response'] ?? $resDe['xml'] ?? '');
                            if ($xmlDe === '') {
                                $tmpJsonDe = json_encode($resDe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                $xmlDe = is_string($tmpJsonDe) ? $tmpJsonDe : '';
                            }
                        } elseif (is_object($resDe)) {
                            $tmpJsonDe = json_encode($resDe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $xmlDe = is_string($tmpJsonDe) ? $tmpJsonDe : '';
                        } else {
                            $xmlDe = (string)$resDe;
                        }

                        if ($xmlDe !== '') {
                            [$estadoDe, $msgDe, $protDe] = $evalEstado($xmlDe, '', $protocoloAuto);
                            if ($msgDe !== '') $mensajeSifen = $msgDe;
                            if (!empty($protDe)) $protocoloAuto = $protDe;
                            if ($estadoDe === 'Aprobado' || $estadoDe === 'Rechazado') {
                                $estadoValidado = $estadoDe;
                                $resXml = $xmlDe;
                                $log("CONSULTA CDC resolvió estado final: $estadoValidado");
                                break;
                            }
                        }
                    } catch (Throwable $eDe) {
                        $log("CONSULTA CDC intento $intento falló: " . $eDe->getMessage());
                    }
                }

                $log("Lote aún en proceso (Intento $intento)...");
            }
        } else {
            // Algunos entornos/proxys responden envío directo sin dProtConsLote.
            [$estadoTmp, $msgTmp, $protTmp] = $evalEstado(
                $resXml,
                (string)(method_exists($sifen, 'getMsgRes') ? $sifen->getMsgRes() : ''),
                (string)(method_exists($sifen, 'getProtAut') ? $sifen->getProtAut() : '')
            );
            $estadoValidado = $estadoTmp;
            $mensajeSifen = $msgTmp;
            $protocoloAuto = $protTmp ?: null;
            $log("SIN LOTE: estado directo=$estadoValidado, prot_aut=" . ($protocoloAuto ?: 'N/A'));
        }

        if ($mensajeSifen === '') {
            $mensajeSifen = ($estadoValidado === 'Pendiente')
                ? 'SIFEN aún no confirmó la aprobación del documento'
                : ('Estado SIFEN: ' . $estadoValidado);
        }

        // Actualizar Tabla de Factura con resultado final
        $stmtUpdate = $pdo->prepare("
            UPDATE $dbName.factura_ventas 
            SET estado_sifen = :est,
                mensaje_sifen = :msg,
                prot_cons_lote_sifen = :prot,
                protocolo_autorizacion = :prot_aut,
                qr_sifen = :qr,
                xml_respuesta = :xml_r,
                fecha_envio_sifen = NOW()
            WHERE id_factura = :id
        ");

        $stmtUpdate->execute([
            ':est' => $estadoValidado,
            ':msg' => substr($mensajeSifen, 0, 255),
            ':prot' => $protConsLote,
            ':prot_aut' => $protocoloAuto,
            ':qr' => $qrUrl,
            ':xml_r' => $resXml,
            ':id' => $idFactura
        ]);

        try {
            $stmtFeOk = $pdo->prepare("UPDATE $dbName.fe
                SET estado_transmision = :tx, estado_electronico = :est, mensaje_set = :msg, acuse_sifen = :xml_r, fecha_transmision = NOW()
                WHERE id_factura = :id");
            $txState = 'PENDIENTE';
            if ($estadoValidado === 'Aprobado') $txState = 'ENVIADO';
            if ($estadoValidado === 'Rechazado') $txState = 'RECHAZADO';
            $stmtFeOk->execute([
                ':tx' => $txState,
                ':est' => $estadoValidado,
                ':msg' => substr($mensajeSifen, 0, 255),
                ':xml_r' => $resXml,
                ':id' => $idFactura
            ]);
        } catch (Exception $e) {
            $log("FE UPDATE FINAL ERROR: " . $e->getMessage());
        }

        $isOk = ($estadoValidado === 'Aprobado') || ($fastPrint && $estadoValidado === 'Pendiente');
        $log(($isOk ? "ÉXITO" : "FIN NO APROBADO") . ": Factura $idFactura procesada. CDC=$cdcFinal Estado=$estadoValidado");

        $msgFinal = trim((string)$mensajeSifen);
        if ($msgFinal === '') {
            $msgFinal = 'Resultado de emisión FE: ' . $estadoValidado;
        }

        return [
            'success' => $isOk,
            'cdc' => $cdcFinal,
            'estado' => $estadoValidado,
            'fast_print' => $fastPrint,
            'mensaje' => $msgFinal,
            'message' => $msgFinal,
            'qr' => $qrUrl,
            'prot_cons_lote_sifen' => $protConsLote
        ];
    } catch (Throwable $e) {
        $log("ERROR CRÍTICO (Throwable): " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
        $rucSafe = isset($rucEmisorBase) ? $rucEmisorBase : 'N/A';
        $cscSafe = (isset($habilitacion['csc']) ? substr($habilitacion['csc'], 0, 4) . "..." : 'N/A');
        $extra = " [RUC: " . $rucSafe . " | CSC: " . $cscSafe . "]";
        $origen = " (Origen: " . basename((string)$e->getFile()) . ":" . (int)$e->getLine() . ")";
        return [
            'success' => false,
            'message' => $e->getMessage() . $origen . $extra
        ];
    }
}
