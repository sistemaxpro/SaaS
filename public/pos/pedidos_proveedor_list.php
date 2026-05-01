<?php
$GLOBALS['SMX_DOC_LIST_CONTEXT'] = [
    'page_title' => 'Lista de Pedidos a Proveedores',
    'heading' => 'Pedidos a Proveedores',
    'document_label' => 'Pedido',
    'api_endpoint' => '/public/pos/api/pedidos_manage.php',
    'editor_path' => '/public/pos/pedidos_proveedor_desktop.php',
    'print_path' => '/public/pos/ticket_pedido.php',
];

require __DIR__ . '/documentos_list_base.php';
