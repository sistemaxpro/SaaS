<?php

/**
 * Respuestas HTTP estandarizadas
 */

class Response
{
    /**
     * Respuesta JSON exitosa
     */
    public static function success($data = null, string $message = 'OK', $pagination = null, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];

        if ($pagination !== null) {
            $response['pagination'] = $pagination;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Respuesta JSON de error
     */
    public static function error(string $message, int $code = 400, $errors = null): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'errors' => $errors
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Respuesta de no autorizado
     */
    public static function unauthorized(string $message = 'No autorizado'): void
    {
        self::error($message, 401);
    }

    /**
     * Respuesta de prohibido
     */
    public static function forbidden(string $message = 'Acceso denegado'): void
    {
        self::error($message, 403);
    }

    /**
     * Respuesta de no encontrado
     */
    public static function notFound(string $message = 'Recurso no encontrado'): void
    {
        self::error($message, 404);
    }
}
