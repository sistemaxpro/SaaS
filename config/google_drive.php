<?php
/**
 * Configuración de Google Drive — OAuth 2.0
 *
 * Los archivos se guardan en el Drive del usuario real (no Service Account)
 * usando OAuth 2.0 refresh_token para obtener access_tokens automáticamente.
 *
 * Configurar via: /public/setup/google_drive_setup.php
 */
return [
    // ── OAuth 2.0 credentials (configurar via wizard) ──
    'oauth_client_id'     => '',
    'oauth_client_secret' => '',
    'oauth_refresh_token' => '',

    // ── Carpeta raíz en Google Drive ──
    'root_folder_id' => '',

    // ── Configuración de imágenes ──
    'image' => [
        'max_size_mb'     => 5,
        'max_width'       => 1200,
        'max_height'      => 1200,
        'quality'         => 82,
        'format'          => 'webp',
        'thumb_size'      => 200,
        'max_per_product' => 5,
    ],

    // ── Hacer archivos públicos (necesario para mostrar en la app) ──
    'public_access' => true,

    // ── Cache de access_token ──
    'token_cache_path' => sys_get_temp_dir() . '/sistemax_gdrive_token.json',
];
