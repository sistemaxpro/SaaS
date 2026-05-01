<?php
$GLOBALS['SMX_DOC_LIST_CONTEXT'] = [
    'page_title' => 'Lista de Presupuestos',
    'heading' => 'Presupuestos a Clientes',
    'document_label' => 'Presupuesto',
    'api_endpoint' => '/public/pos/api/presupuestos_manage.php',
    'editor_path' => '/public/pos/presupuestos_desktop.php',
    'print_path' => '/public/pos/ticket_presupuesto.php',
];

require __DIR__ . '/documentos_list_base.php';
