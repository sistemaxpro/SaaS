<?php

/**
 * Reimprimir Factura - Formulario Moderno
 * Alpine.js + Tailwind CSS
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/bootstrap.php';

$id_empresa = (int)($_SESSION['id_empresa'] ?? $_GET['id_empresa'] ?? 169);
$id_factura = (int)($_GET['id_factura'] ?? 0);

// Conexión a BD
$masterDb = defined('MASTER_DB') ? MASTER_DB : 'serproc1';

$factura = null;
$items = [];
$cliente = null;
$empresa = null;
$error = null;
$ancho_columna_ticket = 0;

try {
    $pdo = Database::getMasterConnection();

    // Obtener datos de empresa (sin ancho_columna_ticket que puede no existir)
    $stmtEmp = $pdo->prepare("SELECT empresa, ruc, timbrado, direccion, telefono, dbase FROM $masterDb.empresa WHERE id_empresa = :id");
    $stmtEmp->execute([':id' => $id_empresa]);
    $empresa = $stmtEmp->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        throw new Exception("Empresa no encontrada");
    }

    $dbName = $empresa['dbase'];

    // Intentar obtener ancho_columna_ticket (puede no existir)
    try {
        $stmtTicket = $pdo->prepare("SELECT ancho_columna_ticket FROM $masterDb.empresa WHERE id_empresa = :id");
        $stmtTicket->execute([':id' => $id_empresa]);
        $ancho_columna_ticket = (int)$stmtTicket->fetchColumn();
    } catch (Exception $e) {
        $ancho_columna_ticket = 0;
    }

    if ($id_factura > 0) {
        // Obtener factura
        $sql = "SELECT fv.*, 
                       s.sucursal as sucursal_nombre,
                       u.login as usuario_nombre,
                       CASE WHEN fv.cdc IS NOT NULL AND fv.cdc != '' THEN 1 ELSE 0 END as es_electronica
                FROM $dbName.factura_ventas fv
                LEFT JOIN $dbName.sucursales s ON s.id_sucursal = fv.id_sucursal
                LEFT JOIN $masterDb.sec_users u ON u.id_login = fv.id_login
                WHERE fv.id_factura = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id_factura]);
        $factura = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$factura) {
            throw new Exception("Factura no encontrada");
        }

        // Si sucursal_nombre está vacío, usar la variable global de sesión
        if (empty($factura['sucursal_nombre'])) {
            $factura['sucursal_nombre'] = $_SESSION['sucursal'] ?? '-';
        }

        // Obtener cliente - intentar con diferentes columnas de ID
        $cliente = null;
        if (!empty($factura['id_cliente'])) {
            try {
                // Primero intentar con id_cliente
                $stmtCli = $pdo->prepare("SELECT * FROM $dbName.clientes WHERE id_cliente = :id");
                $stmtCli->execute([':id' => $factura['id_cliente']]);
                $cliente = $stmtCli->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Si falla, intentar con id
                try {
                    $stmtCli = $pdo->prepare("SELECT * FROM $dbName.clientes WHERE id = :id");
                    $stmtCli->execute([':id' => $factura['id_cliente']]);
                    $cliente = $stmtCli->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e2) {
                    $cliente = null;
                }
            }
        }

        // Obtener items
        $sqlItems = "SELECT ep.*, 
                            p.desproducto as descripcion_producto,
                            p.iva as iva_producto
                     FROM $dbName.extracto_productos ep
                     LEFT JOIN $dbName.tblproductos p ON p.idproducto = ep.idproducto
                     WHERE ep.idfactura = :id
                     ORDER BY ep.id";
        $stmtItems = $pdo->prepare($sqlItems);
        $stmtItems->execute([':id' => $id_factura]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Corregir importe si es 0 o no existe (cantidad × precio) y actualizar en BD
        foreach ($items as $key => $item) {
            $cantidad = floatval($item['cantidad'] ?? 1);
            $precio = floatval($item['precio'] ?? 0);
            $importeActual = floatval($item['importe'] ?? 0);

            if ($importeActual == 0 && $precio > 0) {
                $importeCalculado = $cantidad * $precio;
                $items[$key]['importe'] = $importeCalculado;

                // Actualizar en la base de datos
                if (!empty($item['id'])) {
                    try {
                        $stmtUpdate = $pdo->prepare("UPDATE $dbName.extracto_productos SET importe = :importe WHERE id = :id");
                        $stmtUpdate->execute([':importe' => $importeCalculado, ':id' => $item['id']]);
                    } catch (Exception $e) {
                        // Ignorar error de actualización
                    }
                }
            }
        }

        // Recalcular IVA basándose en los items actuales
        // El IVA está INCLUIDO en el precio
        $iva5_recalc = 0;
        $iva10_recalc = 0;
        $total_gravado5 = 0;  // Total de items con IVA 5%
        $total_gravado10 = 0; // Total de items con IVA 10%
        $total_exenta = 0;    // Total de items exentos

        foreach ($items as $item) {
            // Mapear código de iva a porcentaje: 3=10%, 2=5%, 0=exenta
            $iva_codigo = intval($item['iva_producto'] ?? $item['iva'] ?? 0);
            $importe = floatval($item['importe'] ?? 0);

            if ($iva_codigo == 3) {
                // IVA 10% incluido
                $iva_item = round($importe / 11);
                $iva10_recalc += $iva_item;
                $total_gravado10 += $importe;
            } elseif ($iva_codigo == 2) {
                // IVA 5% incluido
                $iva_item = round($importe / 21);
                $iva5_recalc += $iva_item;
                $total_gravado5 += $importe;
            } else {
                // Exento
                $total_exenta += $importe;
            }
        }
        $factura['iva5'] = round($iva5_recalc, 0);
        $factura['iva10'] = round($iva10_recalc, 0);
        $factura['exenta'] = round($total_exenta, 0);
        $factura['total_gravado5'] = round($total_gravado5, 0);
        $factura['total_gravado10'] = round($total_gravado10, 0);

        // Subtotal = Total - IVA total
        $total_factura = floatval($factura['importe_gs'] ?? $factura['total'] ?? 0);
        $factura['subtotal'] = round($total_factura - $iva5_recalc - $iva10_recalc, 0);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Determinar modo de impresión por defecto
$modoTicket = $ancho_columna_ticket > 0;
?>
<!DOCTYPE html>
<html lang="es" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reimprimir Factura #<?php echo $id_factura; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <style>
        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: white !important;
            }
        }
    </style>
    <script>
        // Detectar si está en iframe
        const isInIframe = window.self !== window.top;
        if (isInIframe) {
            document.documentElement.classList.add('in-iframe');
        }
    </script>
    <style>
        .in-iframe .hide-in-iframe {
            display: none !important;
        }

        .in-iframe body {
            padding: 0 !important;
        }

        .in-iframe .p-4 {
            padding: 1rem !important;
        }

        .in-iframe .md\\:p-8 {
            padding: 1rem !important;
        }
    </style>
</head>

<body class="h-screen bg-slate-900 text-white font-sans overflow-hidden">
    <div x-data="reimprimirApp()" class="h-full p-3 flex flex-col">
        <!-- Header - Oculto en iframe -->
        <?php if ($error): ?>
            <!-- Error State -->
            <div class="max-w-5xl mx-auto h-full flex items-center justify-center">
                <div class="bg-slate-800 border border-red-600 rounded-xl p-8 text-center">
                    <div class="text-5xl mb-4">❌</div>
                    <h2 class="text-xl font-bold text-red-500 mb-2">Error</h2>
                    <p class="text-slate-400"><?php echo htmlspecialchars($error); ?></p>
                    <a href="facturas_sifen.php" class="hide-in-iframe inline-block mt-6 px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg transition">
                        Volver a Facturas
                    </a>
                </div>
            </div>
        <?php elseif (!$id_factura): ?>
            <!-- No ID State -->
            <div class="max-w-5xl mx-auto h-full flex items-center justify-center">
                <div class="bg-slate-800 border border-amber-600 rounded-xl p-8 text-center">
                    <div class="text-5xl mb-4">🔍</div>
                    <h2 class="text-xl font-bold text-amber-500 mb-2">Sin Factura Seleccionada</h2>
                    <p class="text-slate-400">Debe seleccionar una factura para reimprimir</p>
                    <a href="facturas_sifen.php" class="hide-in-iframe inline-block mt-6 px-6 py-2 bg-blue-600 hover:bg-blue-700 rounded-lg transition">
                        Ir a Facturas
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Main Content - Layout horizontal compacto -->
            <div class="flex-1 flex gap-4 overflow-hidden">
                <!-- Factura Card - Columna izquierda -->
                <div class="flex-1 bg-slate-800 rounded-xl border border-slate-700 overflow-hidden flex flex-col">
                    <!-- Factura Header -->
                    <div class="bg-blue-600 px-4 py-2 flex items-center justify-between">
                        <div>
                            <p class="text-blue-200 text-xs uppercase">Factura Nº</p>
                            <p class="text-xl font-bold font-mono text-white"><?php echo htmlspecialchars($factura['nro_factura'] ?? 'S/N'); ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-blue-200 text-xs">Timbrado</p>
                            <p class="font-mono text-sm text-white"><?php echo htmlspecialchars($factura['timbrado'] ?? '-'); ?></p>
                        </div>
                        <div class="text-right">
                            <span class="px-2 py-1 rounded text-xs font-bold <?php echo ($factura['es_electronica'] ?? 0) ? 'bg-green-500 text-white' : 'bg-slate-500 text-white'; ?>">
                                <?php echo ($factura['es_electronica'] ?? 0) ? '⚡ Electrónica' : '📄 Común'; ?>
                            </span>
                        </div>
                    </div>

                    <!-- Factura Body - Scrollable -->
                    <div class="flex-1 p-3 overflow-y-auto space-y-3">
                        <!-- Info Grid + Cliente en una fila -->
                        <div class="grid grid-cols-2 gap-3">
                            <!-- Info -->
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <p class="text-slate-500 text-xs">Fecha</p>
                                    <p class="font-medium"><?php echo date('d/m/Y', strtotime($factura['fecha'])); ?></p>
                                </div>
                                <div>
                                    <p class="text-slate-500 text-xs">Sucursal</p>
                                    <p class="font-medium truncate"><?php echo htmlspecialchars($factura['sucursal_nombre'] ?? '-'); ?></p>
                                </div>
                                <div>
                                    <p class="text-slate-500 text-xs">Condición</p>
                                    <p class="font-medium"><?php
                                                            $forma_pago = $factura['forma_pago'] ?? 'contado';
                                                            // Mapear valores numéricos antiguos a texto
                                                            if ($forma_pago == '2' || $forma_pago == '5') {
                                                                $forma_pago = 'credito';
                                                            } else {
                                                                $forma_pago = 'contado';
                                                            }
                                                            echo strtoupper($forma_pago);
                                                            ?></p>
                                </div>
                                <div>
                                    <p class="text-slate-500 text-xs">Usuario</p>
                                    <p class="font-medium truncate"><?php echo htmlspecialchars($factura['usuario_nombre'] ?? '-'); ?></p>
                                </div>
                            </div>
                            <!-- Cliente -->
                            <div class="bg-slate-900 rounded-lg p-2 flex items-center gap-2">
                                <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center text-lg font-bold flex-shrink-0">
                                    <?php echo strtoupper(substr($cliente['nombre'] ?? 'C', 0, 1)); ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="font-semibold truncate"><?php echo htmlspecialchars($cliente['nombre'] ?? 'Cliente General'); ?></p>
                                    <p class="text-slate-400 text-sm font-mono"><?php echo htmlspecialchars($cliente['ruc'] ?? $factura['ruc'] ?? '-'); ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Items + Totales lado a lado -->
                        <div class="grid grid-cols-3 gap-3">
                            <!-- Items Preview -->
                            <div class="col-span-2">
                                <p class="text-slate-500 text-xs uppercase mb-2">Items (<?php echo count($items); ?>)</p>
                                <div class="space-y-1 max-h-40 overflow-y-auto text-sm">
                                    <!-- Header -->
                                    <div class="flex items-center text-xs text-slate-500 border-b border-slate-600 pb-1">
                                        <div class="w-20">Código</div>
                                        <div class="flex-1">Descripción</div>
                                        <div class="w-12 text-right">Cant</div>
                                        <div class="w-16 text-right">IVA%</div>
                                        <div class="w-20 text-right">Precio</div>
                                        <div class="w-20 text-right">Importe</div>
                                    </div>
                                    <?php foreach (array_slice($items, 0, 5) as $item): ?>
                                        <?php
                                        $cantidad = floatval($item['cantidad'] ?? 1);
                                        $precio = floatval($item['precio'] ?? 0);
                                        $importe = isset($item['importe']) && $item['importe'] > 0
                                            ? floatval($item['importe'])
                                            : $cantidad * $precio;
                                        // Mapear código de iva a porcentaje: 3=10%, 2=5%, 0=exenta
                                        $iva_codigo = intval($item['iva_producto'] ?? $item['iva'] ?? 0);
                                        $iva_pct = ($iva_codigo == 3) ? 10 : (($iva_codigo == 2) ? 5 : 0);
                                        ?>
                                        <div class="flex items-center py-1 border-b border-slate-700/30">
                                            <div class="w-20 font-mono text-slate-400 text-xs"><?php echo htmlspecialchars($item['codigo'] ?? '-'); ?></div>
                                            <div class="flex-1 min-w-0">
                                                <p class="truncate"><?php echo htmlspecialchars($item['descripcion'] ?? $item['descripcion_producto'] ?? '-'); ?></p>
                                            </div>
                                            <div class="w-12 text-right font-mono text-slate-400"><?php echo number_format($cantidad, 0); ?></div>
                                            <div class="w-16 text-right font-mono text-yellow-400 font-semibold"><?php echo intval($iva_pct); ?>%</div>
                                            <div class="w-20 text-right font-mono text-slate-400"><?php echo number_format($precio, 0, ',', '.'); ?></div>
                                            <div class="w-20 text-right font-mono font-medium"><?php echo number_format($importe, 0, ',', '.'); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($items) > 5): ?>
                                        <p class="text-center text-slate-500 text-xs py-1">+ <?php echo count($items) - 5; ?> más</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Liquidación IVA -->
                            <div class="bg-slate-900 rounded-lg p-2 space-y-1 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-slate-400">Subtotal (sin IVA)</span>
                                    <span class="font-mono"><?php echo number_format($factura['subtotal'] ?? 0, 0, ',', '.'); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-400">Total Exenta</span>
                                    <span class="font-mono"><?php echo number_format($factura['exenta'] ?? 0, 0, ',', '.'); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-400">Total IVA 5%</span>
                                    <span class="font-mono"><?php echo number_format($factura['iva5'] ?? 0, 0, ',', '.'); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-400">Total IVA 10%</span>
                                    <span class="font-mono"><?php echo number_format($factura['iva10'] ?? 0, 0, ',', '.'); ?></span>
                                </div>
                                <div class="border-t border-slate-700 pt-1 flex justify-between">
                                    <span class="font-bold">TOTAL</span>
                                    <span class="font-bold text-blue-400 font-mono"><?php echo number_format($factura['importe_gs'] ?? $factura['total'] ?? 0, 0, ',', '.'); ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($factura['cdc'])): ?>
                            <!-- CDC - Click para copiar y verificar en SIFEN -->
                            <div @click="copiarYVerificarCDC()"
                                class="bg-green-900/50 border border-green-700 rounded-lg p-2 cursor-pointer hover:bg-green-800/50 transition group"
                                title="Click para copiar CDC y verificar en SIFEN">
                                <div class="flex items-center justify-between">
                                    <p class="text-green-400 text-xs uppercase">CDC</p>
                                    <span class="text-green-400 text-xs opacity-0 group-hover:opacity-100 transition flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                        </svg>
                                        Copiar y verificar
                                    </span>
                                </div>
                                <p class="font-mono text-xs break-all text-slate-300"><?php echo htmlspecialchars($factura['cdc']); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Acciones Panel - Columna derecha compacta -->
                <div class="w-72 flex flex-col gap-3 no-print">
                    <!-- Formato de Impresión -->
                    <div class="bg-slate-800 rounded-xl border border-slate-700 p-3">
                        <h3 class="font-semibold mb-2 flex items-center gap-2 text-sm">
                            <span>🖨️</span> Formato
                        </h3>
                        <div class="space-y-1">
                            <label class="flex items-center gap-2 p-2 rounded-lg cursor-pointer transition text-sm"
                                :class="formato === 'ticket' ? 'bg-blue-600 text-white' : 'bg-slate-700 hover:bg-slate-600'">
                                <input type="radio" x-model="formato" value="ticket" class="hidden">
                                <span>🧾</span>
                                <span class="font-medium">Ticket 80mm</span>
                            </label>
                            <label class="flex items-center gap-2 p-2 rounded-lg cursor-pointer transition text-sm"
                                :class="formato === 'a4' ? 'bg-blue-600 text-white' : 'bg-slate-700 hover:bg-slate-600'">
                                <input type="radio" x-model="formato" value="a4" class="hidden">
                                <span>📄</span>
                                <span class="font-medium">A4 / Carta</span>
                            </label>
                            <?php if (!empty($factura['cdc'])): ?>
                                <label class="flex items-center gap-2 p-2 rounded-lg cursor-pointer transition text-sm"
                                    :class="formato === 'kude' ? 'bg-blue-600 text-white' : 'bg-slate-700 hover:bg-slate-600'">
                                    <input type="radio" x-model="formato" value="kude" class="hidden">
                                    <span>⚡</span>
                                    <span class="font-medium">KUDE</span>
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Copias -->
                    <div class="bg-slate-800 rounded-xl border border-slate-700 p-3">
                        <h3 class="font-semibold mb-2 flex items-center gap-2 text-sm">
                            <span>📋</span> Copias
                        </h3>
                        <div class="flex items-center gap-3">
                            <button @click="copias = Math.max(1, copias - 1)"
                                class="w-8 h-8 rounded-lg bg-slate-700 hover:bg-slate-600 flex items-center justify-center text-lg transition">-</button>
                            <span class="text-2xl font-bold flex-1 text-center" x-text="copias"></span>
                            <button @click="copias = Math.min(5, copias + 1)"
                                class="w-8 h-8 rounded-lg bg-slate-700 hover:bg-slate-600 flex items-center justify-center text-lg transition">+</button>
                        </div>
                    </div>

                    <!-- Botón Imprimir -->
                    <button @click="imprimir()"
                        :disabled="imprimiendo"
                        class="w-full py-3 rounded-xl font-bold text-base transition-all transform active:scale-95 flex items-center justify-center gap-2"
                        :class="imprimiendo ? 'bg-slate-600 cursor-wait' : 'bg-blue-600 hover:bg-blue-700'">
                        <span x-show="!imprimiendo">🖨️</span>
                        <span x-show="imprimiendo" class="animate-spin">⏳</span>
                        <span x-text="imprimiendo ? 'Abriendo...' : 'Imprimir'"></span>
                    </button>

                    <!-- Acciones Secundarias -->
                    <div class="grid grid-cols-3 gap-2">
                        <button @click="enviarEmail()"
                            class="py-2 rounded-lg bg-slate-700 hover:bg-slate-600 transition flex flex-col items-center justify-center gap-1">
                            <span>📧</span>
                            <span class="text-xs">Email</span>
                        </button>
                        <button @click="enviarWhatsapp()"
                            class="py-2 rounded-lg bg-green-600 hover:bg-green-700 transition flex flex-col items-center justify-center gap-1">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                            </svg>
                            <span class="text-xs">WhatsApp</span>
                        </button>
                        <button @click="descargarPDF()"
                            class="py-2 rounded-lg bg-slate-700 hover:bg-slate-600 transition flex flex-col items-center justify-center gap-1">
                            <span>📥</span>
                            <span class="text-xs">PDF</span>
                        </button>
                    </div>

                    <!-- Botón Salir -->
                    <button @click="salir()"
                        class="w-full py-2 rounded-xl font-medium transition flex items-center justify-center gap-2 bg-slate-700 hover:bg-slate-600 border border-slate-600 text-sm">
                        <span>✕</span>
                        <span>Salir</span>
                    </button>

                    <!-- Info -->
                    <div class="text-center text-xs text-slate-500">
                        <p>ID: <?php echo $id_factura; ?><?php if (!empty($factura['cdc'])): ?> • <span class="text-green-400"><?php echo htmlspecialchars($factura['estado_sifen'] ?? 'Aprobado'); ?></span><?php endif; ?></p>
                    </div>
                </div>
            </div>

            <!-- Modal CDC Copiado -->
            <div x-show="mostrarModalCDC"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4"
                style="display: none;">
                <div x-show="mostrarModalCDC"
                    x-transition:enter="ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    @click.away="mostrarModalCDC = false"
                    class="bg-slate-800 rounded-xl border border-green-600 p-6 max-w-lg w-full shadow-2xl">

                    <!-- Icono -->
                    <div class="flex justify-center mb-4">
                        <div class="w-16 h-16 bg-green-600 rounded-full flex items-center justify-center">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                            </svg>
                        </div>
                    </div>

                    <!-- Título -->
                    <h3 class="text-xl font-bold text-center text-white mb-3">✅ CDC Copiado al Portapapeles</h3>

                    <!-- CDC Preview -->
                    <div class="bg-slate-900 rounded-lg p-3 mb-4">
                        <p class="text-slate-500 text-xs text-center mb-1">Código de Control del Documento:</p>
                        <p class="font-mono text-xs break-all text-green-400 text-center" x-text="cdc"></p>
                    </div>

                    <!-- Instrucciones paso a paso -->
                    <div class="bg-blue-900/30 border border-blue-700/50 rounded-lg p-4 mb-4">
                        <p class="text-blue-400 text-sm font-semibold mb-3 flex items-center gap-2">
                            <span>📋</span> Instrucciones para verificar en SIFEN:
                        </p>
                        <ol class="text-slate-300 text-sm space-y-2 list-decimal list-inside">
                            <li>Al pulsar <strong class="text-white">Aceptar</strong> se abrirá la página de e-Kuatia</li>
                            <li>En el campo <strong class="text-white">"CDC"</strong> pegue el código con <kbd class="bg-slate-700 px-1.5 py-0.5 rounded text-xs">Ctrl+V</kbd></li>
                            <li>Complete el <strong class="text-white">reCAPTCHA</strong> (verificación "No soy un robot")</li>
                            <li>Presione el botón <strong class="text-white">"Consultar"</strong></li>
                            <li>El sistema mostrará los datos de la Factura Electrónica</li>
                        </ol>
                    </div>

                    <!-- Nota -->
                    <p class="text-slate-500 text-xs text-center mb-4">
                        💡 Esta consulta verifica que el documento existe y es válido en el sistema SIFEN de la SET.
                    </p>

                    <!-- Botones -->
                    <div class="flex gap-3">
                        <button @click="mostrarModalCDC = false"
                            class="flex-1 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 transition font-medium">
                            Cancelar
                        </button>
                        <button @click="abrirConsultaSIFEN()"
                            class="flex-1 py-2 rounded-lg bg-green-600 hover:bg-green-700 transition font-medium flex items-center justify-center gap-2">
                            <span>Aceptar</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function reimprimirApp() {
            return {
                formato: '<?php echo $modoTicket ? 'ticket' : 'a4'; ?>',
                copias: 1,
                imprimiendo: false,
                idFactura: <?php echo $id_factura ?: 0; ?>,
                esElectronica: <?php echo ($factura['es_electronica'] ?? 0) ? 'true' : 'false'; ?>,
                cdc: '<?php echo addslashes($factura['cdc'] ?? ''); ?>',
                mostrarModalCDC: false,

                imprimir() {
                    this.imprimiendo = true;
                    let url = '';
                    const baseUrl = (window.location.port === '5555') ? 'http://168.231.95.50/scriptcase/app/smx/' : '';

                    switch (this.formato) {
                        case 'ticket':
                            if (this.esElectronica && this.cdc) {
                                url = `${baseUrl}kude_ticket.php?id=${this.idFactura}`;
                            } else {
                                url = `${baseUrl}nota_ticket.php?id=${this.idFactura}`;
                            }
                            break;
                        case 'a4':
                            if (this.esElectronica && this.cdc) {
                                url = `${baseUrl}kude_a4.php?id=${this.idFactura}`;
                            } else {
                                url = `${baseUrl}nota_a4.php?id=${this.idFactura}`;
                            }
                            break;
                        case 'kude':
                            url = `${baseUrl}kude_a4.php?id=${this.idFactura}`;
                            break;
                    }

                    // Abrir ventanas según copias
                    for (let i = 0; i < this.copias; i++) {
                        setTimeout(() => {
                            window.open(url, '_blank');
                        }, i * 300);
                    }

                    setTimeout(() => {
                        this.imprimiendo = false;
                    }, 1000);
                },

                enviarEmail() {
                    const baseUrl = (window.location.port === '5555') ? 'http://168.231.95.50/scriptcase/app/smx/' : '';
                    window.open(`${baseUrl}kude_email.php?id=${this.idFactura}`, '_blank');
                },

                descargarPDF() {
                    const baseUrl = (window.location.port === '5555') ? 'http://168.231.95.50/scriptcase/app/smx/' : '';
                    if (this.esElectronica && this.cdc) {
                        window.open(`${baseUrl}kude_a4.php?id=${this.idFactura}&download=1`, '_blank');
                    } else {
                        window.open(`${baseUrl}nota_a4.php?id=${this.idFactura}&download=1`, '_blank');
                    }
                },

                enviarWhatsapp() {
                    const telefono = '<?php echo preg_replace('/[^0-9]/', '', $cliente['telefono'] ?? ''); ?>';
                    const nroFactura = '<?php echo addslashes($factura['nro_factura'] ?? ''); ?>';
                    const total = '<?php echo number_format($factura['importe_gs'] ?? $factura['total'] ?? 0, 0, ',', '.'); ?>';
                    const empresa = '<?php echo addslashes($empresa['empresa'] ?? ''); ?>';

                    let mensaje = `Hola! Le enviamos su comprobante de *${empresa}*\n`;
                    mensaje += `📄 Factura: *${nroFactura}*\n`;
                    mensaje += `💰 Total: *${total} Gs*\n`;
                    <?php if (!empty($factura['cdc'])): ?>
                        mensaje += `✅ Documento Electrónico validado por SIFEN`;
                    <?php endif; ?>

                    const url = telefono ?
                        `https://wa.me/595${telefono.replace(/^0/, '')}?text=${encodeURIComponent(mensaje)}` :
                        `https://wa.me/?text=${encodeURIComponent(mensaje)}`;

                    window.open(url, '_blank');
                },

                copiarYVerificarCDC() {
                    if (!this.cdc) return;

                    // Copiar CDC al portapapeles
                    navigator.clipboard.writeText(this.cdc).then(() => {
                        // Mostrar modal de confirmación
                        this.mostrarModalCDC = true;
                    }).catch(err => {
                        console.error('Error al copiar:', err);
                        // Mostrar modal igual aunque falle la copia
                        this.mostrarModalCDC = true;
                    });
                },

                abrirConsultaSIFEN() {
                    this.mostrarModalCDC = false;
                    // Abrir página de consulta SIFEN (formulario donde se pega el CDC)
                    const urlConsulta = 'https://ekuatia.set.gov.py/consultas';
                    window.open(urlConsulta, '_blank');
                },

                salir() {
                    if (window.self !== window.top) {
                        // Estamos en iframe, cerrar modal
                        window.parent.postMessage({
                            action: 'close-modal'
                        }, '*');
                    } else {
                        // No estamos en iframe, volver a la lista
                        window.location.href = 'facturas_sifen.php';
                    }
                }
            }
        }
    </script>
</body>

</html>
