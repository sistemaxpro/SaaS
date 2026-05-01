<?php

/**
 * Cron Job: Renovar Suscripciones Mensuales
 * Ejecutar el día 1 de cada mes: 0 1 1 * * php /path/to/renovar_suscripciones.php
 * 
 * Copia los items (apps) del mes anterior a una nueva suscripción
 * y envía email de nueva factura
 */

// Detectar si se ejecuta desde CLI o web
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    // Si se accede por web, verificar token de seguridad
    $token = $_GET['token'] ?? '';
    $tokenValido = getenv('CRON_SECRET_TOKEN') ?: 'sistemax_cron_2026';
    
    if ($token !== $tokenValido) {
        http_response_code(403);
        die('Acceso no autorizado');
    }
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/Modules/Empresas/SuscripcionController.php';

// Configuración
define('LOG_FILE', __DIR__ . '/../logs/cron_renovar_' . date('Y-m') . '.log');
define('EMAIL_FROM', 'no-reply@sistemax.com.py');
define('EMAIL_FROM_NAME', 'SistemaX');

/**
 * Log de mensajes
 */
function logMessage(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] {$message}\n";
    
    file_put_contents(LOG_FILE, $logLine, FILE_APPEND);
    
    if (php_sapi_name() === 'cli') {
        echo $logLine;
    }
}

/**
 * Enviar email de nueva factura
 */
function enviarEmailNuevaFactura(array $suscripcion, array $empresa): bool
{
    try {
        // Cargar template
        $templatePath = __DIR__ . '/../src/Templates/emails/nueva_factura.html';
        if (!file_exists($templatePath)) {
            logMessage("ERROR: Template no encontrado: {$templatePath}");
            return false;
        }
        
        $template = file_get_contents($templatePath);
        
        // Generar lista de apps HTML
        $appsHtml = '';
        if (!empty($suscripcion['apps'])) {
            foreach ($suscripcion['apps'] as $app) {
                $precio = number_format($app['precio_unitario'], 0, ',', '.');
                $appsHtml .= "<div class=\"app-item\">
                    <span class=\"app-name\">{$app['nombre_app']}</span>
                    <span class=\"app-price\">₲ {$precio}</span>
                </div>";
            }
        }
        
        // Reemplazar variables
        $variables = [
            '{{LOGO_URL}}' => 'https://sistemax.com.py/_lib/img/grp__NM__img__NM__logo_smx_300px.png',
            '{{EMPRESA_NOMBRE}}' => $empresa['empresa'] ?? 'Cliente',
            '{{EMPRESA_RUC}}' => $empresa['ruc'] ?? '',
            '{{NRO_FACTURA}}' => $suscripcion['nro_factura'],
            '{{FECHA_EMISION}}' => date('d/m/Y', strtotime($suscripcion['fecha_emision'])),
            '{{PERIODO}}' => date('d/m/Y', strtotime($suscripcion['periodo_inicio'])) . ' - ' . date('d/m/Y', strtotime($suscripcion['periodo_fin'])),
            '{{FECHA_VENCIMIENTO}}' => date('d/m/Y', strtotime($suscripcion['fecha_vencimiento'])),
            '{{APPS_LIST}}' => $appsHtml,
            '{{TOTAL}}' => number_format($suscripcion['total'], 0, ',', '.'),
            '{{LINK_PAGO}}' => "https://sistemax.com.py/pagar_suscripcion.php?id={$suscripcion['id_suscripcion']}",
            '{{DIAS_GRACIA}}' => $suscripcion['dias_gracia'] ?? 5,
            '{{YEAR}}' => date('Y')
        ];
        
        $htmlBody = str_replace(array_keys($variables), array_values($variables), $template);
        
        // Preparar email
        $to = $empresa['email'] ?? null;
        if (empty($to)) {
            logMessage("WARN: Empresa {$empresa['id_empresa']} sin email configurado");
            return false;
        }
        
        $subject = "Nueva Factura SistemaX - {$suscripcion['nro_factura']}";
        
        // Headers para HTML
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . EMAIL_FROM_NAME . ' <' . EMAIL_FROM . '>',
            'Reply-To: soporte@sistemax.com.py',
            'X-Mailer: SistemaX/1.0'
        ];
        
        // Enviar
        $resultado = mail($to, $subject, $htmlBody, implode("\r\n", $headers));
        
        if ($resultado) {
            logMessage("OK: Email enviado a {$to}");
        } else {
            logMessage("ERROR: Falló envío de email a {$to}");
        }
        
        return $resultado;
    } catch (Exception $e) {
        logMessage("ERROR: Excepción enviando email: " . $e->getMessage());
        return false;
    }
}

// =========================================================================
// INICIO DEL PROCESO
// =========================================================================

logMessage("========================================");
logMessage("INICIO: Renovación de suscripciones mensuales");
logMessage("========================================");

try {
    $db = Database::getMasterConnection();
    
    // Obtener suscripciones para renovar
    $resultado = SuscripcionController::getSuscripcionesParaRenovar();
    
    if (!$resultado['success']) {
        logMessage("ERROR: " . ($resultado['error'] ?? 'Error desconocido'));
        exit(1);
    }
    
    $suscripciones = $resultado['data'];
    $total = count($suscripciones);
    
    logMessage("Suscripciones a renovar: {$total}");
    
    if ($total === 0) {
        logMessage("No hay suscripciones para renovar este mes");
        logMessage("FIN");
        exit(0);
    }
    
    $exitos = 0;
    $errores = 0;
    
    foreach ($suscripciones as $susc) {
        $idEmpresa = $susc['id_empresa'];
        $idSuscAnterior = $susc['id_suscripcion'];
        
        logMessage("Procesando empresa ID: {$idEmpresa}");
        
        // Renovar suscripción
        $resultadoRenovar = SuscripcionController::renovarMes($idEmpresa, $idSuscAnterior);
        
        if (!$resultadoRenovar['success']) {
            logMessage("ERROR renovando empresa {$idEmpresa}: " . ($resultadoRenovar['error'] ?? ''));
            $errores++;
            continue;
        }
        
        $idNuevaSuscripcion = $resultadoRenovar['data']['id_suscripcion'];
        logMessage("OK: Suscripción #{$idNuevaSuscripcion} creada para empresa {$idEmpresa}");
        
        // Obtener datos completos para email
        $resultadoSusc = SuscripcionController::getSuscripcionActiva($idEmpresa);
        if (!$resultadoSusc['success']) {
            logMessage("WARN: No se pudo obtener datos para email");
            $exitos++;
            continue;
        }
        
        $suscripcionCompleta = $resultadoSusc['data'];
        
        // Obtener datos de empresa
        $stmt = $db->prepare("SELECT * FROM empresa WHERE id_empresa = ?");
        $stmt->execute([$idEmpresa]);
        $empresa = $stmt->fetch();
        
        // Enviar email
        if (enviarEmailNuevaFactura($suscripcionCompleta, $empresa)) {
            // Marcar como notificado
            $stmt = $db->prepare("UPDATE saas_suscripcion SET notificado_nueva = 1 WHERE id_suscripcion = ?");
            $stmt->execute([$idNuevaSuscripcion]);
        }
        
        $exitos++;
    }
    
    logMessage("========================================");
    logMessage("RESUMEN:");
    logMessage("- Total procesadas: {$total}");
    logMessage("- Exitosas: {$exitos}");
    logMessage("- Con errores: {$errores}");
    logMessage("========================================");
    logMessage("FIN");
    
} catch (Exception $e) {
    logMessage("ERROR FATAL: " . $e->getMessage());
    exit(1);
}
