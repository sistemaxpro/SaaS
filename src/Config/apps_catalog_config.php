<?php

/**
 * Configuración de apps del catálogo
 * Define todas las apps disponibles en el sistema
 */

return [
    'balanza_electronica' => [
        'codigo' => 'balanza_electronica',
        'nombre' => 'Gestión de Balanza',
        'descripcion' => 'Configuración y prueba de códigos de balanza electrónica',
        'ruta_app' => 'public/balanzas/index.php',
        'icono' => 'beaker',
        'color' => 'amber',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'Inventario',
        'negocio' => 'Comercial',
        'orden' => 58
    ],
    'db_migrador' => [
        'codigo' => 'db_migrador',
        'nombre' => 'Migrador DB',
        'descripcion' => 'Herramienta para migración de datos entre bases de datos',
        'ruta_app' => 'public/db_migrador/index.php',
        'icono' => 'database-import',
        'color' => 'emerald',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'Administracion',
        'negocio' => 'General',
        'orden' => 60
    ],
    'print_agent' => [
        'codigo' => 'print_agent',
        'nombre' => 'Agente de Impresion',
        'descripcion' => 'Control de impresoras desde navegador',
        'ruta_app' => 'public/pos/print_agent.php',
        'icono' => 'printer',
        'color' => 'slate',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'POS',
        'negocio' => 'Comercial',
        'orden' => 40
    ],
    'android_installer' => [
        'codigo' => 'android_installer',
        'nombre' => 'Instalar Geolocalizador',
        'descripcion' => 'Instalar aplicación móvil geolocalizador',
        'ruta_app' => 'public/pos/android_installer.php',
        'icono' => 'android',
        'color' => 'green',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'POS',
        'negocio' => 'Comercial',
        'orden' => 41
    ],
    'bluetooth_printer' => [
        'codigo' => 'bluetooth_printer',
        'nombre' => 'Configurar Impresora BT',
        'descripcion' => 'Configurar impresora Bluetooth para POS',
        'ruta_app' => 'public/pos/bluetooth_printer.php',
        'icono' => 'bluetooth',
        'color' => 'blue',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'POS',
        'negocio' => 'Comercial',
        'orden' => 42
    ],
    'tracking_mobile' => [
        'codigo' => 'tracking_mobile',
        'nombre' => 'Tracking Movil',
        'descripcion' => 'Rastreo de ubicación en tiempo real',
        'ruta_app' => 'public/tracking/mobile.php',
        'icono' => 'map-marker-multiple',
        'color' => 'red',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'General',
        'negocio' => 'General',
        'orden' => 50
    ],
    'push_diagnostico' => [
        'codigo' => 'push_diagnostico',
        'nombre' => 'Diagnóstico Push',
        'descripcion' => 'Diagnóstico de notificaciones push',
        'ruta_app' => 'public/push/diagnostico.php',
        'icono' => 'bell-alert',
        'color' => 'orange',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'General',
        'negocio' => 'General',
        'orden' => 51
    ],
    'soporte_desktop' => [
        'codigo' => 'soporte_desktop_cliente',
        'nombre' => 'SistemaX Assist',
        'descripcion' => 'Asistencia remota nativa del equipo cliente con SistemaX Assist',
        'ruta_app' => 'public/helpwire/index.php',
        'icono' => 'computer-desktop',
        'color' => 'cyan',
        'precio_mensual' => 0,
        'obligatoria' => 1,
        'modulo' => 'Soporte',
        'negocio' => 'General',
        'orden' => 18
    ],
    'inventario_mobile' => [
        'codigo' => 'inventario_mobile',
        'nombre' => 'Inventario',
        'descripcion' => 'Control de inventario movil por sucursal con ajustes, traslados y conteo en vivo',
        'ruta_app' => 'public/inventario/index.php',
        'icono' => 'warehouse',
        'color' => 'indigo',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'Inventario',
        'negocio' => 'Comercial',
        'orden' => 19
    ],
    'transferencia_productos' => [
        'codigo' => 'transferencia_productos',
        'nombre' => 'Transferencia de Productos',
        'descripcion' => 'Traslados de stock entre sucursales con impresion, recepcion y seguimiento en tiempo real',
        'ruta_app' => 'public/inventario/transferencias.php',
        'icono' => 'transferencia-productos',
        'color' => 'sky',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'Inventario',
        'negocio' => 'Comercial',
        'orden' => 20
    ],
    'bug_center' => [
        'codigo' => 'bug_center',
        'nombre' => 'Centro de Bug',
        'descripcion' => 'Centro de reporte y gestión de bugs',
        'ruta_app' => 'public/bug_center/index.php',
        'icono' => 'bug',
        'color' => 'red',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'Soporte',
        'negocio' => 'General',
        'orden' => 52
    ],
    'apps_center' => [
        'codigo' => 'apps_center',
        'nombre' => 'Central de Apps',
        'descripcion' => 'Centro de gestión de aplicaciones',
        'ruta_app' => 'public/apps/central.php',
        'icono' => 'apps-grid',
        'color' => 'purple',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'Soporte',
        'negocio' => 'General',
        'orden' => 53
    ],
    'geolocation_config' => [
        'codigo' => 'geolocation_config',
        'nombre' => 'Configuración Geolocalización',
        'descripcion' => 'Configuración del servicio de geolocalización',
        'ruta_app' => 'public/config/geolocation.php',
        'icono' => 'map-marker-check',
        'color' => 'green',
        'precio_mensual' => 0,
        'obligatoria' => 0,
        'modulo' => 'Administración',
        'negocio' => 'General',
        'orden' => 54
    ]
];
