<?php

/**
 * Formulario Alta Empresa (sin ScriptCase)
 * Alpine.js + Tailwind
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/Modules/Empresas/SuscripcionController.php';

$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

/**
 * Crear base de datos para nueva empresa y usuario soporte
 */
function crearBaseDatosEmpresa($pdo, $idEmpresa, $empresaData, $masterDb)
{
    $result = ['db_created' => false, 'user_created' => false, 'errors' => [], 'db_name' => ''];

    try {
        $dbName = 'smx_' . $idEmpresa;
        $result['db_name'] = $dbName;

        // 1. Crear la base de datos
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $result['db_created'] = true;

        // 2. Obtener lista de tablas de smx_169 (excluyendo vistas)
        $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES 
                             WHERE TABLE_SCHEMA = 'smx_169' AND TABLE_TYPE = 'BASE TABLE'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Tablas que deben copiarse con datos básicos/catálogos
        $tablasConDatos = [
            'categoria_iva',
            'categorias_isc',
            'ciudad',
            'ciudades',
            'codigos_afectaciones',
            'condiciones_negociaciones',
            'condiciones_operaciones',
            'condiciones_tipos_pagos',
            'departamentos',
            'distritos',
            'emblema',
            'ice_catalogo',
            'indicadores_presencias',
            'ise_catalogo',
            'iva_tipo',
            'localidades',
            'modalidades_transportes',
            'moneda_sifen',
            'nandina',
            'naturaleza_vendedor_autofactura',
            'ncm',
            'notas_creditos_motivos',
            'operaciones',
            'pais',
            'paises',
            'paises_sifen',
            'remisiones_motivos',
            'sec_apps',
            'sec_groups',
            'sec_groups_apps',
            'sec_settings',
            'tasas_isc',
            'tipo_contribuyente',
            'tipo_documento',
            'tipo_emision',
            'tipo_impuesto',
            'tipo_receptor',
            'tipo_regimen',
            'tipo_transaccion',
            'tipos_combustibles',
            'tipos_documentos',
            'tipos_documentos_identidades',
            'tipos_documentos_receptor',
            'tipos_emisiones',
            'tipos_impuestos',
            'tipos_operaciones',
            'tipos_producto',
            'tipos_regimenes',
            'tipos_transacciones',
            'unidad_medida',
            'unidades_medida',
            'unidades_medida_sifen',
            'unidades_medidas',
            'seg_apps',
            'seg_groups',
            'seg_groups_apps',
            'seg_settings'
        ];

        // 3. Copiar estructura y datos de cada tabla
        foreach ($tables as $table) {
            try {
                // Copiar estructura
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$dbName}`.`{$table}` LIKE `smx_169`.`{$table}`");

                // Si es tabla de catálogos, copiar datos
                if (in_array($table, $tablasConDatos)) {
                    $pdo->exec("INSERT IGNORE INTO `{$dbName}`.`{$table}` SELECT * FROM `smx_169`.`{$table}`");
                }
            } catch (Exception $e) {
                $result['errors'][] = "Error en tabla {$table}: " . $e->getMessage();
            }
        }

        // 4. Crear usuario soporte
        $loginSoporte = 'soporte' . $idEmpresa;
        $passwordHash = md5('Armagedon123');
        $nombreEmpresa = $empresaData['empresa'] ?? $empresaData['razon_social'] ?? 'Empresa ' . $idEmpresa;
        $emailEmpresa = $empresaData['email'] ?? '';
        $telefonoEmpresa = $empresaData['telefono'] ?? '';

        // Insertar usuario soporte
        $sqlUser = "INSERT INTO `{$dbName}`.`sec_users` 
                    (login, pswd, name, email, phone, active, priv_admin, ti, id_empresa, id_sucursal, id_grupo, id_caja)
                    VALUES 
                    (:login, :pswd, :name, :email, :phone, 'Y', 'Y', 1, :id_empresa, 1, 1, 1)";
        $stmtUser = $pdo->prepare($sqlUser);
        $stmtUser->execute([
            ':login' => $loginSoporte,
            ':pswd' => $passwordHash,
            ':name' => 'Soporte TI - ' . $nombreEmpresa,
            ':email' => $emailEmpresa,
            ':phone' => $telefonoEmpresa,
            ':id_empresa' => $idEmpresa
        ]);

        // 5. Asignar grupo admin al usuario
        $lastUserId = $pdo->lastInsertId();
        if ($lastUserId) {
            $pdo->exec("INSERT INTO `{$dbName}`.`sec_users_groups` (id_login, id_grupo) VALUES ({$lastUserId}, 1)");
        }

        $result['user_created'] = true;
        $result['login'] = $loginSoporte;
    } catch (Exception $e) {
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}

$action = $_GET['action'] ?? '';
if ($action === 'sifen_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rucRaw = trim($_GET['ruc'] ?? '');
        if (strlen($rucRaw) < 5) {
            echo json_encode(['success' => false, 'error' => 'RUC/Cédula muy corta']);
            exit;
        }

        $baseDir = dirname(__DIR__);

        $certNombreFijo = '80118689.p12';
        $certPassFijo = '3nvcEcwW';

        $candidates = [
            $baseDir . '/_lib/php-sifen3-custom/certificados/' . $certNombreFijo,
            $baseDir . '/_lib/php-sifen3/certificados/' . $certNombreFijo,
            $baseDir . '/_lib/sifen/certificados/' . $certNombreFijo,
            $baseDir . '/_lib/certificados/' . $certNombreFijo,
            $baseDir . '/certificados/' . $certNombreFijo
        ];

        $certPath = '';
        foreach ($candidates as $cand) {
            if (file_exists($cand)) {
                $certPath = $cand;
                break;
            }
        }

        if (!$certPath) {
            throw new Exception('Certificado de consulta no encontrado');
        }

        $pkcs12Content = file_get_contents($certPath);
        if (!openssl_pkcs12_read($pkcs12Content, $certs, $certPassFijo)) {
            throw new Exception('No se pudo leer el certificado PKCS12');
        }

        $pemContent = $certs['pkey'] . "\n" . $certs['cert'] . "\n";
        if (!empty($certs['extracerts'])) {
            foreach ($certs['extracerts'] as $extra) {
                $pemContent .= $extra . "\n";
            }
        }

        $rucSoloNum = preg_replace('/[^0-9]/', '', $rucRaw);
        if ($rucSoloNum === '') {
            throw new Exception('RUC inválido');
        }

        // Crear archivo PEM temporal
        $pemTmpPath = sys_get_temp_dir() . '/sifen_lookup_' . uniqid() . '.pem';
        file_put_contents($pemTmpPath, $pemContent);

        // Construir SOAP XML manualmente
        $soapXml = '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
  <soap:Body>
    <xsd:rEnviConsRUC>
      <xsd:dId>1</xsd:dId>
      <xsd:dRUCCons>' . htmlspecialchars($rucSoloNum) . '</xsd:dRUCCons>
    </xsd:rEnviConsRUC>
  </soap:Body>
</soap:Envelope>';

        // USAR PRODUCCION para consultas de RUC
        $url = 'https://sifen.set.gov.py/de/ws/consultas/consulta-ruc.wsdl';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $soapXml,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/soap+xml; charset=utf-8',
                'SOAPAction: ""'
            ],
            CURLOPT_SSLCERT => $pemTmpPath,
            CURLOPT_SSLCERTPASSWD => '',
            CURLOPT_SSLKEY => $pemTmpPath,
            CURLOPT_SSLKEYPASSWD => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Limpiar PEM temporal
        @unlink($pemTmpPath);

        if ($curlError) {
            throw new Exception('Error de conexión: ' . $curlError);
        }

        if ($httpCode !== 200 || empty($response)) {
            throw new Exception('SIFEN HTTP ' . $httpCode);
        }

        $cleanXml = preg_replace('/<(\/?)\w+:(\w+)/', '<$1$2', $response);
        $xmlRes = new SimpleXMLElement($cleanXml);

        $dCodRes = (string)($xmlRes->xpath('//dCodRes')[0] ?? '');
        if ($dCodRes !== '0502' && $dCodRes !== '0260') {
            $dMsgRes = (string)($xmlRes->xpath('//dMsgRes')[0] ?? 'No encontrado en SIFEN');
            echo json_encode(['success' => false, 'error' => $dMsgRes]);
            exit;
        }

        $xContr = $xmlRes->xpath('//xContRUC')[0] ?? $xmlRes->xpath('//xContr')[0] ?? null;
        if (!$xContr) {
            throw new Exception('Respuesta inválida de SIFEN');
        }

        $foundNombre = trim((string)($xContr->dRazCons ?? $xContr->dRazSoc ?? ''));
        $foundRucBase = trim((string)($xContr->dRUCCons ?? $xContr->dRUC ?? ''));

        // SIFEN no devuelve el DV, hay que calcularlo con algoritmo módulo 11
        function calcularDV($ruc)
        {
            $ruc = preg_replace('/[^0-9]/', '', $ruc);
            if (empty($ruc)) return '';

            $baseMax = 11;
            $k = 2;
            $total = 0;

            for ($i = strlen($ruc) - 1; $i >= 0; $i--) {
                $total += (int)$ruc[$i] * $k;
                $k++;
                if ($k > $baseMax) $k = 2;
            }

            $resto = $total % 11;
            $dv = ($resto > 1) ? (11 - $resto) : 0;

            return (string)$dv;
        }

        $foundDV = calcularDV($foundRucBase);

        if ($foundNombre === '') {
            throw new Exception('Nombre vacío en respuesta SIFEN');
        }

        $foundRuc = $foundRucBase . '-' . $foundDV;

        echo json_encode([
            'success' => true,
            'data' => [
                'razon_social' => $foundNombre,
                'ruc' => $foundRuc,
                'ruc_base' => $foundRucBase,
                'dv' => $foundDV
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $colsStmt = $pdo->query("SHOW COLUMNS FROM {$masterDb}.empresa");
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
        $allowed = array_flip($cols);

        $data = [
            'empresa' => trim($payload['empresa'] ?? ''),
            'ruc' => trim($payload['ruc'] ?? ''),
            'dv' => trim($payload['dv'] ?? ''),
            'telefono' => trim($payload['telefono'] ?? ''),
            'email' => trim($payload['email'] ?? ''),
            'direccion' => trim($payload['direccion'] ?? ''),
            'numero_casa' => trim($payload['numero_casa'] ?? ''),
            'activo' => (int)($payload['activo'] ?? 1),
            'web' => trim($payload['web'] ?? ''),
            'web_url' => trim($payload['web_url'] ?? ''),
            'web_ck' => trim($payload['web_ck'] ?? ''),
            'web_cs' => trim($payload['web_cs'] ?? '')
        ];

        if (empty($data['empresa'])) {
            echo json_encode(['success' => false, 'error' => 'El nombre de empresa es obligatorio']);
            exit;
        }
        if (empty($data['ruc'])) {
            echo json_encode(['success' => false, 'error' => 'El RUC es obligatorio']);
            exit;
        }
        if (empty($data['dv'])) {
            echo json_encode(['success' => false, 'error' => 'El DV es obligatorio']);
            exit;
        }
        if (empty($data['direccion'])) {
            echo json_encode(['success' => false, 'error' => 'La dirección es obligatoria']);
            exit;
        }
        if (empty($data['telefono'])) {
            echo json_encode(['success' => false, 'error' => 'El teléfono es obligatorio']);
            exit;
        }
        if (empty($data['numero_casa'])) {
            echo json_encode(['success' => false, 'error' => 'El número de casa es obligatorio']);
            exit;
        }
        if (empty($data['email'])) {
            echo json_encode(['success' => false, 'error' => 'El email es obligatorio']);
            exit;
        }

        $filtered = [];
        foreach ($data as $k => $v) {
            if (isset($allowed[$k]) && $v !== '') {
                $filtered[$k] = $v;
            }
        }

        if (!isset($filtered['activo']) && isset($allowed['activo'])) {
            $filtered['activo'] = 1;
        }

        if (empty($filtered)) {
            echo json_encode(['success' => false, 'error' => 'No hay datos válidos para guardar']);
            exit;
        }

        $columns = array_keys($filtered);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);
        $sql = "INSERT INTO {$masterDb}.empresa (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        foreach ($filtered as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        $newIdEmpresa = $pdo->lastInsertId();

        // ========== CREAR BASE DE DATOS Y USUARIO SOPORTE ==========
        $setupResult = crearBaseDatosEmpresa($pdo, $newIdEmpresa, $filtered, $masterDb);
        $suscripcionAuto = SuscripcionController::asegurarSuscripcionEmpresa((int)$newIdEmpresa, [
            'created_by' => (int)($_SESSION['id_login'] ?? 0),
        ]);

        echo json_encode([
            'success' => true,
            'id' => $newIdEmpresa,
            'db_created' => $setupResult['db_created'] ?? false,
            'user_created' => $setupResult['user_created'] ?? false,
            'suscripcion_auto' => $suscripcionAuto,
            'db_name' => $setupResult['db_name'] ?? '',
            'setup_errors' => $setupResult['errors'] ?? []
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es" x-data="empresaForm()" class="h-full">

<head>
    <meta charset="utf-8">
    <title>Nueva Empresa</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
    <script>
        (function() {
            function applyTheme(mode) {
                if (mode === 'dark') {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            }

            // Heredar tema: 1) mensaje del padre, 2) localStorage, 3) sistema del navegador
            const stored = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored) {
                applyTheme(stored);
            } else if (systemDark) {
                applyTheme('dark');
            } else {
                applyTheme('light');
            }

            // Escuchar cambios del sistema
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                if (!localStorage.getItem('theme')) {
                    applyTheme(e.matches ? 'dark' : 'light');
                }
            });

            // Escuchar mensaje del padre (modal)
            window.addEventListener('message', (event) => {
                if (event.data && event.data.type === 'theme') {
                    applyTheme(event.data.value);
                }
            });
        })();
    </script>
</head>

<body class="bg-slate-50 h-full dark:bg-slate-900">
    <!-- Modal Notificación Nativo -->
    <div x-data="notifModal()" x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @keydown.escape.window="close()">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 w-full max-w-sm mx-4 overflow-hidden transform transition-all" @click.outside="close()">
            <div class="p-6 text-center">
                <!-- Icono -->
                <div class="mx-auto mb-4 w-14 h-14 rounded-full flex items-center justify-center"
                    :class="{
                        'bg-green-100 dark:bg-green-900/30': type === 'success',
                        'bg-red-100 dark:bg-red-900/30': type === 'error',
                        'bg-yellow-100 dark:bg-yellow-900/30': type === 'warning',
                        'bg-blue-100 dark:bg-blue-900/30': type === 'info'
                    }">
                    <svg x-show="type === 'success'" class="w-7 h-7 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <svg x-show="type === 'error'" class="w-7 h-7 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    <svg x-show="type === 'warning'" class="w-7 h-7 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <svg x-show="type === 'info'" class="w-7 h-7 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <!-- Título -->
                <h3 class="text-lg font-semibold text-slate-800 dark:text-slate-100 mb-2" x-text="title"></h3>
                <!-- Mensaje -->
                <p class="text-sm text-slate-600 dark:text-slate-400" x-text="message"></p>
            </div>
            <div class="px-6 pb-5">
                <button @click="close()" class="w-full py-2.5 rounded-lg font-medium text-white transition"
                    :class="{
                        'bg-green-600 hover:bg-green-700': type === 'success',
                        'bg-red-600 hover:bg-red-700': type === 'error',
                        'bg-yellow-500 hover:bg-yellow-600': type === 'warning',
                        'bg-blue-600 hover:bg-blue-700': type === 'info'
                    }">Aceptar</button>
            </div>
        </div>
    </div>

    <div class="w-full h-full p-6">
        <div class="bg-white dark:bg-slate-800 shadow-sm rounded-xl border border-slate-200 dark:border-slate-700 h-full">
            <div class="p-6 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="relative">
                        <label class="text-sm text-slate-600 dark:text-slate-300">RUC *</label>
                        <input type="text" id="rucInput" required x-init="$nextTick(() => $el.focus())" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.ruc" @input.debounce.600ms="buscarRucSifen()" @keydown.enter.prevent="buscarRucSifen()" @blur="buscarRucSifen()">
                        <div x-show="loadingRuc" class="absolute inset-0 flex items-center justify-center bg-white/80 dark:bg-slate-900/80 rounded-lg mt-6">
                            <span class="text-xs text-blue-600 dark:text-blue-400 font-medium flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Buscando en la SET...
                            </span>
                        </div>
                    </div>
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">DV *</label>
                        <input type="text" id="dvInput" required class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.dv">
                    </div>
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">Empresa *</label>
                        <input type="text" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.empresa">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">Dirección *</label>
                        <input type="text" id="direccionInput" required class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.direccion">
                    </div>
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">Nro. casa *</label>
                        <input type="text" required class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.numero_casa">
                    </div>
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">Teléfono *</label>
                        <input type="text" required class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.telefono">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">Email *</label>
                        <input type="email" required class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.email">
                    </div>
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">Activo</label>
                        <select class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.activo">
                            <option :value="1">Sí</option>
                            <option :value="0">No</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">Web</label>
                        <input type="text" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.web">
                    </div>
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">Web URL</label>
                        <input type="text" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.web_url">
                    </div>
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">Web CK</label>
                        <input type="text" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.web_ck">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="text-sm text-slate-600 dark:text-slate-300">Web CS</label>
                        <input type="text" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-900 dark:border-slate-600 dark:text-slate-100" x-model="form.web_cs">
                    </div>
                </div>


                <div class="flex gap-3 justify-end pt-2">
                    <button class="px-4 py-2 rounded-lg border text-slate-600 dark:text-slate-200 dark:border-slate-600" @click="resetForm()">Limpiar</button>
                    <button class="px-4 py-2 rounded-lg bg-blue-600 text-white" @click="guardar()" :disabled="loading">Guardar</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
    <script>
        // Navegación de campos: Enter = Tab, Flechas arriba/abajo
        (function() {
            const focusableSelector = 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])';

            document.addEventListener('keydown', function(e) {
                const el = e.target;
                if (!el.matches('input, select, textarea')) return;

                const form = el.closest('.p-6');
                if (!form) return;

                const focusables = Array.from(form.querySelectorAll(focusableSelector));
                const currentIndex = focusables.indexOf(el);

                // Enter = Tab (siguiente campo)
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    const next = focusables[currentIndex + 1];
                    if (next) next.focus();
                    return;
                }

                // Flecha abajo = siguiente campo
                if (e.key === 'ArrowDown' && (e.altKey || el.tagName !== 'SELECT')) {
                    e.preventDefault();
                    const next = focusables[currentIndex + 1];
                    if (next) next.focus();
                    return;
                }

                // Flecha arriba = campo anterior
                if (e.key === 'ArrowUp' && (e.altKey || el.tagName !== 'SELECT')) {
                    e.preventDefault();
                    const prev = focusables[currentIndex - 1];
                    if (prev) prev.focus();
                    return;
                }
            });
        })();

        // Sistema de notificaciones nativo Alpine.js
        function notifModal() {
            return {
                show: false,
                title: '',
                message: '',
                type: 'info',
                open(title, message, type = 'info') {
                    this.title = title;
                    this.message = message;
                    this.type = type;
                    this.show = true;
                },
                close() {
                    this.show = false;
                }
            };
        }

        // Función global para mostrar notificaciones
        window.showNotif = function(title, message, type = 'info') {
            const event = new CustomEvent('show-notif', {
                detail: {
                    title,
                    message,
                    type
                }
            });
            window.dispatchEvent(event);
        };

        // Listener global para notificaciones
        document.addEventListener('alpine:init', () => {
            window.addEventListener('show-notif', (e) => {
                const el = document.querySelector('[x-data="notifModal()"]');
                if (el && el.__x) {
                    el.__x.$data.open(e.detail.title, e.detail.message, e.detail.type);
                }
            });
        });

        function empresaForm() {
            return {
                loading: false,
                loadingRuc: false,
                form: {
                    empresa: '',
                    ruc: '',
                    dv: '',
                    telefono: '',
                    email: '',
                    direccion: '',
                    numero_casa: '',
                    activo: 1,
                    web: '',
                    web_url: '',
                    web_ck: '',
                    web_cs: ''
                },
                async buscarRucSifen() {
                    const ruc = (this.form.ruc || '').trim();
                    if (this.loadingRuc || ruc.length < 5) return;
                    this.loadingRuc = true;
                    let found = false;
                    try {
                        const res = await fetch(`form_empresa.php?action=sifen_lookup&ruc=${encodeURIComponent(ruc)}`);
                        const data = await res.json();
                        if (data.success && data.data) {
                            // Usar ruc_base (sin DV) para el campo ruc
                            if (data.data.ruc_base) this.form.ruc = data.data.ruc_base;
                            // Completar DV
                            if (data.data.dv) this.form.dv = data.data.dv;
                            // Completar razón social
                            if (data.data.razon_social) this.form.empresa = data.data.razon_social;
                            found = true;
                        } else if (data.error) {
                            window.showNotif('SIFEN', data.error, 'info');
                        }
                    } catch (e) {
                        console.error('SIFEN lookup error', e);
                        window.showNotif('SIFEN', 'No se pudo consultar SIFEN', 'error');
                    } finally {
                        this.loadingRuc = false;
                        // Enfocar dirección si encontró, DV si no
                        this.$nextTick(() => {
                            if (found) {
                                document.getElementById('direccionInput')?.focus();
                            } else {
                                document.getElementById('dvInput')?.focus();
                            }
                        });
                    }
                },
                resetForm() {
                    Object.assign(this.form, {
                        empresa: '',
                        ruc: '',
                        dv: '',
                        telefono: '',
                        email: '',
                        direccion: '',
                        numero_casa: '',
                        activo: 1,
                        web: '',
                        web_url: '',
                        web_ck: '',
                        web_cs: ''
                    });
                },
                async guardar() {
                    if (!this.form.ruc) {
                        window.showNotif('Falta RUC', 'El RUC es obligatorio', 'warning');
                        document.getElementById('rucInput')?.focus();
                        return;
                    }
                    if (!this.form.dv) {
                        window.showNotif('Falta DV', 'El dígito verificador es obligatorio', 'warning');
                        document.getElementById('dvInput')?.focus();
                        return;
                    }
                    if (!this.form.empresa) {
                        window.showNotif('Falta empresa', 'El nombre de empresa es obligatorio', 'warning');
                        return;
                    }
                    if (!this.form.direccion) {
                        window.showNotif('Falta dirección', 'La dirección es obligatoria', 'warning');
                        return;
                    }
                    if (!this.form.telefono) {
                        window.showNotif('Falta teléfono', 'El teléfono es obligatorio', 'warning');
                        return;
                    }
                    if (!this.form.numero_casa) {
                        window.showNotif('Falta nro. casa', 'El número de casa es obligatorio', 'warning');
                        return;
                    }
                    if (!this.form.email) {
                        window.showNotif('Falta email', 'El email es obligatorio', 'warning');
                        return;
                    }
                    this.loading = true;
                    try {
                        const res = await fetch('form_empresa.php?action=save', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(this.form)
                        });
                        const data = await res.json();
                        if (data.success) {
                            window.showNotif('Éxito', 'Empresa creada correctamente', 'success');
                            this.resetForm();
                        } else {
                            window.showNotif('Error', data.error || 'No se pudo guardar', 'error');
                        }
                    } catch (e) {
                        window.showNotif('Error', 'Error de conexión', 'error');
                    } finally {
                        this.loading = false;
                    }
                }
            };
        }
    </script>
</body>

</html>
