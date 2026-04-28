<?php

/**
 * Cron Job: Verificar Vencimientos de Suscripciones
 * Ejecutar diariamente: 0 8 * * * php /path/to/verificar_vencimientos.php
 * 
 * - Actualiza estados de suscripciones (gracia, vencida)
 * - Envía emails de advertencia 3 días antes del vencimiento
 * - Envía email de bloqueo cuando se vence
 */

// Detectar si se ejecuta desde CLI o web
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
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
define('LOG_FILE', __DIR__ . '/../logs/cron_vencimientos_' . date('Y-m') . '.log');
define('EMAIL_FROM', 'no-reply@sistemax.com.py');
define('EMAIL_FROM_NAME', 'SistemaX');
define('DIAS_AVISO_ANTICIPADO', 3); // Enviar advertencia 3 días antes

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
 * Enviar email desde template
 */
function enviarEmail(string $templateName, string $to, string $subject, array $variables): bool
{
    try {
        $templatePath = __DIR__ . "/../src/Templates/emails/{$templateName}.html";
        if (!file_exists($templatePath)) {
            logMessage("ERROR: Template no encontrado: {$templatePath}");
            return false;
        }
        
        $template = file_get_contents($templatePath);
        
        // Reemplazar variables
        $htmlBody = $template;
        foreach ($variables as $key => $value) {
            $htmlBody = str_replace("{{{$key}}}", $value, $htmlBody);
        }
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . EMAIL_FROM_NAME . ' <' . EMAIL_FROM . '>',
            'Reply-To: soporte@sistemax.com.py',
            'X-Mailer: SistemaX/1.0'
        ];
        
        $resultado = mail($to, $subject, $htmlBody, implode("\r\n", $headers));
        
        if ($resultado) {
            logMessage("OK: Email '{$templateName}' enviado a {$to}");
        } else {
            logMessage("ERROR: Falló envío '{$templateName}' a {$to}");
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
logMessage("INICIO: Verificación de vencimientos");
logMessage("========================================");

try {
    $db = Database::getMasterConnection();
    
    // 1. Actualizar estados de suscripciones
    logMessage("Paso 1: Actualizando estados...");
    $resultadoEstados = SuscripcionController::actualizarEstados();
    logMessage($resultadoEstados['message'] ?? 'Estados actualizados');
    
    // 2. Enviar advertencias de vencimiento (3 días antes)
    logMessage("Paso 2: Enviando advertencias de vencimiento...");
    
    $fechaAviso = date('Y-m-d', strtotime('+' . DIAS_AVISO_ANTICIPADO . ' days'));
    
    $sql = "
        SELECT s.*, e.empresa, e.ruc, e.email
        FROM saas_suscripcion s
        JOIN empresa e ON e.id_empresa = s.id_empresa
        WHERE s.estado = 'activa'
          AND s.estado_pago != 'pagado'
          AND s.notificado_vencimiento = 0
          AND s.fecha_vencimiento <= ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$fechaAviso]);
    $porVencer = $stmt->fetchAll();
    
    logMessage("Suscripciones por vencer: " . count($porVencer));
    
    foreach ($porVencer as $susc) {
        if (empty($susc['email'])) continue;
        
        $diasRestantes = max(0, (int)((strtotime($susc['fecha_vencimiento']) - time()) / 86400));
        
        $variables = [
            'EMPRESA_NOMBRE' => $susc['empresa'],
            'NRO_FACTURA' => $susc['nro_factura'],
            'PERIODO' => date('d/m/Y', strtotime($susc['periodo_inicio'])) . ' - ' . date('d/m/Y', strtotime($susc['periodo_fin'])),
            'FECHA_VENCIMIENTO' => date('d/m/Y', strtotime($susc['fecha_vencimiento'])),
            'TOTAL' => number_format($susc['total'], 0, ',', '.'),
            'DIAS_RESTANTES' => $diasRestantes,
            'DIAS_GRACIA' => $susc['dias_gracia'],
            'LINK_PAGO' => "https://sistemax.com.py/pagar_suscripcion.php?id={$susc['id_suscripcion']}",
            'YEAR' => date('Y')
        ];
        
        $enviado = enviarEmail(
            'advertencia_vencimiento',
            $susc['email'],
            "⚠️ Tu suscripción SistemaX vence en {$diasRestantes} días",
            $variables
        );
        
        if ($enviado) {
            $stmtUpdate = $db->prepare("UPDATE saas_suscripcion SET notificado_vencimiento = 1 WHERE id_suscripcion = ?");
            $stmtUpdate->execute([$susc['id_suscripcion']]);
        }
    }
    
    // 3. Enviar avisos de bloqueo
    logMessage("Paso 3: Enviando avisos de bloqueo...");
    
    $sql = "
        SELECT s.*, e.empresa, e.ruc, e.email
        FROM saas_suscripcion s
        JOIN empresa e ON e.id_empresa = s.id_empresa
        WHERE s.estado = 'vencida'
          AND s.notificado_bloqueo = 0
    ";
    
    $stmt = $db->query($sql);
    $bloqueadas = $stmt->fetchAll();
    
    logMessage("Suscripciones bloqueadas: " . count($bloqueadas));
    
    foreach ($bloqueadas as $susc) {
        if (empty($susc['email'])) continue;
        
        $variables = [
            'EMPRESA_NOMBRE' => $susc['empresa'],
            'EMPRESA_RUC' => $susc['ruc'],
            'NRO_FACTURA' => $susc['nro_factura'],
            'PERIODO' => date('d/m/Y', strtotime($susc['periodo_inicio'])) . ' - ' . date('d/m/Y', strtotime($susc['periodo_fin'])),
            'FECHA_BLOQUEO' => date('d/m/Y'),
            'TOTAL' => number_format($susc['total'], 0, ',', '.'),
            'LINK_PAGO' => "https://sistemax.com.py/pagar_suscripcion.php?id={$susc['id_suscripcion']}",
            'YEAR' => date('Y')
        ];
        
        $enviado = enviarEmail(
            'suscripcion_bloqueada',
            $susc['email'],
            "🔒 Su acceso a SistemaX ha sido suspendido",
            $variables
        );
        
        if ($enviado) {
            $stmtUpdate = $db->prepare("UPDATE saas_suscripcion SET notificado_bloqueo = 1 WHERE id_suscripcion = ?");
            $stmtUpdate->execute([$susc['id_suscripcion']]);
        }
    }
    
    // 4. Resumen
    logMessage("========================================");
    logMessage("RESUMEN:");
    logMessage("- Advertencias enviadas: " . count($porVencer));
    logMessage("- Bloqueos notificados: " . count($bloqueadas));
    logMessage("========================================");
    logMessage("FIN");
    
} catch (Exception $e) {
    logMessage("ERROR FATAL: " . $e->getMessage());
    exit(1);
}
