<?php
/**
 * Endpoint QZ legacy deprecado.
 * La impresión ahora usa Sistemax Agent (localhost:17890).
 */

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => false,
    'deprecated' => true,
    'message' => 'QZ Tray fue deprecado. Use Sistemax Agent.',
], JSON_UNESCAPED_UNICODE);
