<?php
/**
 * KUDE A5 - Formato compacto según Manual SIFEN v150
 * Kuatia Documento Electrónico - Representación gráfica del DE
 * Diseño moderno con Tailwind CSS y Alpine.js
 */

$id_factura = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$id_empresa = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : 169;

if (!$id_factura) {
    die('Error: ID de factura requerido');
}

// Incluir configuración de base de datos
require_once __DIR__ . '/config/db_config.php';

function fetchSerialesPorFacturaKude(PDO $pdo, int $idFactura): array
{
    if ($idFactura <= 0) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT idproducto, GROUP_CONCAT(serie ORDER BY serie SEPARATOR ' | ') AS seriales
            FROM producto_series
            WHERE id_factura = :id
            GROUP BY idproducto
        ");
        $stmt->execute([':id' => $idFactura]);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $idproducto = (int)($row['idproducto'] ?? 0);
            if ($idproducto > 0) {
                $map[$idproducto] = trim((string)($row['seriales'] ?? ''));
            }
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

try {
    // Conectar a la base de datos de la empresa
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $empresa = $conn['config'];
    
    // Obtener factura
    $stmtFactura = $pdo->prepare("
        SELECT fv.*, 
               c.nombre AS cliente_nombre, 
               c.numero AS cliente_ruc,
               c.direccion AS cliente_direccion,
               c.email AS cliente_email,
               c.telefono AS cliente_telefono
        FROM factura_ventas fv
        LEFT JOIN clientes c ON c.id = fv.id_cliente
        WHERE fv.id_factura = :id
    ");
    $stmtFactura->execute([':id' => $id_factura]);
    $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);
    
    if (!$factura) {
        throw new Exception("Factura no encontrada");
    }
    
    $cdc = $factura['cdc'] ?? '';
    if (empty($cdc)) {
        throw new Exception("Esta factura no tiene CDC (no es electrónica)");
    }
    
    // Obtener items
    $stmtItems = $pdo->prepare("
        SELECT ep.*, p.desproducto AS producto_nombre
        FROM extracto_productos ep
        LEFT JOIN tblproductos p ON p.idproducto = ep.idproducto
        WHERE ep.idfactura = :id AND ep.salida > 0
        ORDER BY ep.id
    ");
    $stmtItems->execute([':id' => $id_factura]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $serialesMap = fetchSerialesPorFacturaKude($pdo, $id_factura);
    if (!empty($serialesMap)) {
        foreach ($items as &$item) {
            $idproducto = (int)($item['idproducto'] ?? 0);
            $item['seriales'] = $idproducto > 0 ? (string)($serialesMap[$idproducto] ?? '') : '';
        }
        unset($item);
    }
    
    // URL QR oficial SIFEN
    $qrUrl = !empty($factura['qr_sifen']) ? $factura['qr_sifen'] : 'https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=' . $cdc;
    
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

// Helpers
function formatMoney($amount) {
    return number_format((float)$amount, 0, ',', '.');
}

function parseCDC($cdc) {
    if (strlen($cdc) < 44) return [];
    $tiposDe = [
        '01' => 'Factura Electrónica',
        '02' => 'Factura Electrónica de Exportación', 
        '03' => 'Factura Electrónica de Importación',
        '04' => 'Autofactura Electrónica',
        '05' => 'Nota de Crédito Electrónica',
        '06' => 'Nota de Débito Electrónica',
        '07' => 'Nota de Remisión Electrónica'
    ];
    return [
        'ruc' => substr($cdc, 0, 8),
        'dv' => substr($cdc, 8, 1),
        'tipo_doc' => substr($cdc, 9, 2),
        'establecimiento' => substr($cdc, 11, 3),
        'punto' => substr($cdc, 14, 3),
        'numero' => substr($cdc, 17, 7),
        'tipo_nombre' => $tiposDe[substr($cdc, 9, 2)] ?? 'Documento Electrónico'
    ];
}

$cdcData = parseCDC($cdc);

// Calcular totales por IVA
$totalExenta = 0;
$totalIva5 = 0;
$totalIva10 = 0;

foreach ($items as $item) {
    $subtotal = ($item['salida'] ?? 1) * ($item['precio'] ?? 0);
    $tasaIva = $item['tasa_iva'] ?? 10;
    
    if (isset($item['tipo_iva'])) {
        if ($item['tipo_iva'] == 1) $tasaIva = 0;
        elseif ($item['tipo_iva'] == 2) $tasaIva = 5;
        elseif ($item['tipo_iva'] == 3) $tasaIva = 10;
    }
    
    if ($tasaIva == 0) $totalExenta += $subtotal;
    elseif ($tasaIva == 5) $totalIva5 += $subtotal;
    else $totalIva10 += $subtotal;
}

$totalGeneral = $totalExenta + $totalIva5 + $totalIva10;
$liqIva5 = $totalIva5 / 21;
$liqIva10 = $totalIva10 / 11;
$totalLiqIva = $liqIva5 + $liqIva10;

$condicionVenta = ($factura['forma_pago'] ?? $factura['tipo_venta'] ?? 1) == 1 ? 'CONTADO' : 'CRÉDITO';
?>
<!DOCTYPE html>
<html lang="es" x-data="kudeApp()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KUDE - <?= htmlspecialchars($factura['nro_factura'] ?? $cdc) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    
    <style>
        [x-cloak] { display: none !important; }
        
        @page {
            size: 148mm 210mm; /* A5 */
            margin: 8mm;
        }
        
        @media print {
            body { background: white !important; }
            .no-print { display: none !important; }
            .page { 
                box-shadow: none !important; 
                margin: 0 !important;
                padding: 6mm !important;
            }
        }
        
        .page {
            width: 148mm;
            min-height: 210mm;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen font-sans" x-data="kudeApp()">
    
    <!-- Botones de acción (no se imprimen) -->
    <div class="no-print fixed top-4 right-4 flex gap-2 z-50">
        <button @click="window.print()" 
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow-lg flex items-center gap-2 transition-all hover:-translate-y-0.5">
            <i class="fas fa-print"></i> Imprimir
        </button>
        <button @click="showEmailModal = true" 
                class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium shadow-lg flex items-center gap-2 transition-all hover:-translate-y-0.5">
            <i class="fas fa-envelope"></i> Email
        </button>
        <button @click="showWhatsAppModal = true"
                class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg font-medium shadow-lg flex items-center gap-2 transition-all hover:-translate-y-0.5">
            <i class="fab fa-whatsapp"></i> WhatsApp
        </button>
    </div>

    <!-- Documento KUDE A5 -->
    <div class="page bg-white mx-auto my-8 shadow-2xl p-6 print:my-0 print:shadow-none">
        
        <!-- Header -->
        <div class="flex justify-between items-start border-b-2 border-blue-900 pb-3 mb-4">
            <div class="flex-1">
                <h1 class="text-lg font-bold text-blue-900 leading-tight"><?= htmlspecialchars($empresa['empresa'] ?? 'EMPRESA') ?></h1>
                <p class="text-xs text-gray-600 mt-1">
                    <span class="font-semibold">RUC:</span> <?= htmlspecialchars($empresa['ruc'] ?? '') ?>-<?= htmlspecialchars($empresa['dv'] ?? '') ?>
                </p>
                <p class="text-xs text-gray-500"><?= htmlspecialchars($empresa['direccion'] ?? '') ?></p>
                <p class="text-xs text-gray-500">Tel: <?= htmlspecialchars($empresa['telefono'] ?? '') ?></p>
            </div>
            <div class="text-right">
                <div class="inline-block bg-gradient-to-r from-blue-900 to-blue-700 text-white px-3 py-1 rounded text-xs font-bold mb-1">
                    <?= strtoupper($cdcData['tipo_nombre'] ?? 'DOCUMENTO ELECTRÓNICO') ?>
                </div>
                <div class="text-xl font-bold text-blue-900"><?= htmlspecialchars($factura['nro_factura'] ?? '') ?></div>
                <div class="text-xs text-gray-500">Timbrado: <?= htmlspecialchars($factura['timbrado'] ?? '') ?></div>
            </div>
        </div>
        
        <!-- Datos del documento y receptor -->
        <div class="grid grid-cols-2 gap-3 mb-4">
            <!-- Documento -->
            <div class="bg-gray-50 rounded-lg p-3">
                <h3 class="text-xs font-bold text-blue-900 mb-2 flex items-center gap-1">
                    <i class="fas fa-file-invoice text-blue-600"></i> Documento
                </h3>
                <div class="space-y-1 text-xs">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Fecha:</span>
                        <span class="font-medium"><?= date('d/m/Y H:i', strtotime($factura['fecha'])) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Condición:</span>
                        <span class="font-medium"><?= $condicionVenta ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Moneda:</span>
                        <span class="font-medium">PYG</span>
                    </div>
                </div>
            </div>
            
            <!-- Receptor -->
            <div class="bg-gray-50 rounded-lg p-3">
                <h3 class="text-xs font-bold text-blue-900 mb-2 flex items-center gap-1">
                    <i class="fas fa-user text-blue-600"></i> Receptor
                </h3>
                <div class="space-y-1 text-xs">
                    <div>
                        <span class="text-gray-500">Nombre:</span>
                        <span class="font-medium block truncate"><?= htmlspecialchars($factura['cliente_nombre'] ?? 'Sin nombre') ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">RUC/CI:</span>
                        <span class="font-medium"><?= htmlspecialchars($factura['cliente_ruc'] ?? $factura['ruc'] ?? '-') ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Detalle de items -->
        <div class="mb-4">
            <table class="w-full text-xs">
                <thead>
                    <tr class="bg-blue-900 text-white">
                        <th class="px-2 py-1.5 text-left rounded-tl-lg">Descripción</th>
                        <th class="px-2 py-1.5 text-center w-12">Cant.</th>
                        <th class="px-2 py-1.5 text-right w-20">P.Unit.</th>
                        <th class="px-2 py-1.5 text-center w-12">IVA</th>
                        <th class="px-2 py-1.5 text-right w-24 rounded-tr-lg">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $item): 
                            $cantidad = $item['salida'] ?? 1;
                            $subtotal = $cantidad * ($item['precio'] ?? 0);
                            $tasaIva = $item['tasa_iva'] ?? 10;
                            if (isset($item['tipo_iva'])) {
                                if ($item['tipo_iva'] == 1) $tasaIva = 0;
                                elseif ($item['tipo_iva'] == 2) $tasaIva = 5;
                                elseif ($item['tipo_iva'] == 3) $tasaIva = 10;
                            }
                        ?>
                        <tr class="hover:bg-blue-50/50">
                            <td class="px-2 py-1.5 max-w-[120px]">
                                <div class="truncate"><?= htmlspecialchars($item['producto_nombre'] ?? $item['descripcion'] ?? 'Producto') ?></div>
                                <?php if (!empty($item['seriales'])): ?>
                                    <div class="text-[10px] text-blue-700">IMEI/Serie: <?= htmlspecialchars($item['seriales']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-2 py-1.5 text-center"><?= number_format($cantidad, 0) ?></td>
                            <td class="px-2 py-1.5 text-right"><?= formatMoney($item['precio'] ?? 0) ?></td>
                            <td class="px-2 py-1.5 text-center text-gray-500"><?= $tasaIva == 0 ? 'Ex' : $tasaIva . '%' ?></td>
                            <td class="px-2 py-1.5 text-right font-medium"><?= formatMoney($subtotal) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-2 py-4 text-center text-gray-400">Sin detalle disponible</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Totales y QR -->
        <div class="flex gap-3 mb-4">
            <!-- QR -->
            <div class="flex-shrink-0">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode($qrUrl) ?>" 
                     alt="QR SIFEN" 
                     class="w-24 h-24 rounded-lg border border-gray-200">
            </div>
            
            <!-- Totales -->
            <div class="flex-1 bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg p-3">
                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Exenta:</span>
                        <span><?= formatMoney($totalExenta) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Liq. IVA 5%:</span>
                        <span><?= formatMoney($liqIva5) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">IVA 5%:</span>
                        <span><?= formatMoney($totalIva5) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Liq. IVA 10%:</span>
                        <span><?= formatMoney($liqIva10) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">IVA 10%:</span>
                        <span><?= formatMoney($totalIva10) ?></span>
                    </div>
                    <div class="flex justify-between border-t border-gray-300 pt-1">
                        <span class="text-gray-500">Total Liq.:</span>
                        <span class="font-medium"><?= formatMoney($totalLiqIva) ?></span>
                    </div>
                </div>
                
                <!-- Total General -->
                <div class="mt-2 pt-2 border-t-2 border-blue-900 flex justify-between items-center">
                    <span class="text-sm font-bold text-blue-900">TOTAL:</span>
                    <span class="text-lg font-bold text-blue-900"><?= formatMoney($totalGeneral) ?> Gs</span>
                </div>
            </div>
        </div>
        
        <!-- CDC -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-2 mb-3">
            <p class="text-xs text-blue-800 font-medium mb-1">Código de Control del Documento Electrónico (CDC)</p>
            <p class="text-xs font-mono text-blue-900 break-all leading-relaxed"><?= htmlspecialchars($cdc) ?></p>
        </div>
        
        <!-- Footer -->
        <div class="text-center border-t border-gray-200 pt-2">
            <p class="text-[10px] text-gray-500">Este documento es la representación gráfica de un Documento Tributario Electrónico (KuDE)</p>
            <p class="text-[10px] text-gray-500">Consulte la validez en: <span class="font-semibold text-blue-700">ekuatia.set.gov.py/consultas</span></p>
            <p class="text-[9px] text-gray-400 mt-1">Generado: <?= date('d/m/Y H:i:s') ?> | SistemaX</p>
        </div>
    </div>

    <!-- Modal de Email -->
    <div x-show="showEmailModal" 
         x-cloak
         class="no-print fixed inset-0 z-[100] flex items-center justify-center"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        
        <!-- Overlay -->
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showEmailModal = false"></div>
        
        <!-- Modal Content -->
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95">
            
            <!-- Header -->
            <div class="bg-gradient-to-r from-emerald-600 to-emerald-700 px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-envelope text-white text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white">Enviar KUDE por Email</h3>
                        <p class="text-emerald-100 text-sm">Factura <?= htmlspecialchars($factura['nro_factura'] ?? '') ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Body -->
            <div class="p-6">
                <form @submit.prevent="enviarEmail()">
                    <!-- Campo Email -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-at text-gray-400 mr-1"></i>
                            Email del destinatario
                        </label>
                        <input type="email" 
                               x-model="emailDestino"
                               x-ref="emailInput"
                               @input="validarEmail()"
                               placeholder="ejemplo@correo.com"
                               class="w-full px-4 py-3 border-2 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none transition-colors"
                               :class="emailError ? 'border-red-300 focus:border-red-500 bg-red-50' : 'border-gray-200 focus:border-emerald-500'"
                               required>
                        
                        <!-- Error Message -->
                        <p x-show="emailError" 
                           x-text="emailError"
                           class="mt-2 text-sm text-red-600 flex items-center gap-1">
                            <i class="fas fa-exclamation-circle"></i>
                        </p>
                    </div>
                    
                    <!-- Info -->
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 mb-4">
                        <p class="text-xs text-blue-700">
                            <i class="fas fa-info-circle mr-1"></i>
                            Se abrirá su cliente de correo con el enlace del KUDE para enviar.
                        </p>
                    </div>
                    
                    <!-- Botón copiar enlace -->
                    <button type="button"
                            @click="copiarEnlace()"
                            class="w-full mb-3 px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-medium transition-colors flex items-center justify-center gap-2">
                        <i class="fas fa-link"></i>
                        Copiar enlace del KUDE
                    </button>
                    
                    <!-- Buttons -->
                    <div class="flex gap-3">
                        <button type="button"
                                @click="showEmailModal = false"
                                class="flex-1 px-4 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-medium transition-colors">
                            <i class="fas fa-times mr-2"></i>
                            Cancelar
                        </button>
                        <button type="submit"
                                :disabled="!emailValido"
                                class="flex-1 px-4 py-3 bg-emerald-600 hover:bg-emerald-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white rounded-xl font-medium transition-colors flex items-center justify-center gap-2">
                            <i class="fas fa-envelope-open-text mr-2"></i>
                            Abrir en correo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de WhatsApp -->
    <div x-show="showWhatsAppModal" 
         x-cloak
         class="no-print fixed inset-0 z-[100] flex items-center justify-center"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        
        <!-- Overlay -->
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showWhatsAppModal = false"></div>
        
        <!-- Modal Content -->
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95">
            
            <!-- Header -->
            <div class="bg-gradient-to-r from-green-500 to-green-600 px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center">
                        <i class="fab fa-whatsapp text-white text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white">Enviar KUDE por WhatsApp</h3>
                        <p class="text-green-100 text-sm">Factura <?= htmlspecialchars($factura['nro_factura'] ?? '') ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Body -->
            <div class="p-6">
                <form @submit.prevent="enviarWhatsApp()">
                    <!-- Código de país -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-globe text-gray-400 mr-1"></i>
                            País
                        </label>
                        <div class="relative">
                            <select x-model="codigoPais"
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-gray-900 focus:outline-none focus:border-green-500 appearance-none cursor-pointer bg-white">
                                <template x-for="pais in paises" :key="pais.codigo">
                                    <option :value="pais.codigo" x-text="pais.bandera + ' ' + pais.nombre + ' (+' + pais.codigo + ')'"></option>
                                </template>
                            </select>
                            <div class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none">
                                <i class="fas fa-chevron-down text-gray-400"></i>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Número de teléfono -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-phone text-gray-400 mr-1"></i>
                            Número de teléfono
                        </label>
                        <div class="flex gap-2">
                            <div class="flex items-center px-3 py-3 bg-gray-100 border-2 border-gray-200 rounded-xl text-gray-600 font-medium min-w-[80px] justify-center">
                                <span x-text="'+' + codigoPais"></span>
                            </div>
                            <input type="tel" 
                                   x-model="telefonoDestino"
                                   @input="validarTelefono()"
                                   placeholder="981 234 567"
                                   class="flex-1 px-4 py-3 border-2 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none transition-colors"
                                   :class="telefonoError ? 'border-red-300 focus:border-red-500 bg-red-50' : 'border-gray-200 focus:border-green-500'"
                                   required>
                        </div>
                        
                        <!-- Error Message -->
                        <p x-show="telefonoError" 
                           x-text="telefonoError"
                           class="mt-2 text-sm text-red-600 flex items-center gap-1">
                            <i class="fas fa-exclamation-circle"></i>
                        </p>
                    </div>
                    
                    <!-- Preview del mensaje -->
                    <div class="bg-green-50 border border-green-200 rounded-xl p-3 mb-4">
                        <p class="text-xs text-green-800 font-medium mb-2">
                            <i class="fas fa-eye mr-1"></i> Vista previa del mensaje:
                        </p>
                        <div class="text-xs text-green-700 bg-white rounded-lg p-2 max-h-24 overflow-y-auto">
                            📄 *KUDE - Factura Electrónica*<br>
                            • Factura: <?= htmlspecialchars($factura['nro_factura'] ?? '') ?><br>
                            • Total: <?= number_format((float)($factura['total'] ?? 0), 0, ',', '.') ?> Gs<br>
                            🔗 Ver documento...
                        </div>
                    </div>
                    
                    <!-- Buttons -->
                    <div class="flex gap-3">
                        <button type="button"
                                @click="showWhatsAppModal = false"
                                class="flex-1 px-4 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-medium transition-colors">
                            <i class="fas fa-times mr-2"></i>
                            Cancelar
                        </button>
                        <button type="submit"
                                :disabled="!telefonoValido"
                                class="flex-1 px-4 py-3 bg-green-500 hover:bg-green-600 disabled:bg-gray-300 disabled:cursor-not-allowed text-white rounded-xl font-medium transition-colors flex items-center justify-center gap-2">
                            <i class="fab fa-whatsapp mr-2"></i>
                            Enviar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div x-show="showToast" 
         x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         class="no-print fixed bottom-4 right-4 z-[110]">
        <div class="px-6 py-4 rounded-xl shadow-lg flex items-center gap-3"
             :class="toastType === 'success' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'">
            <i class="fas" :class="toastType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'"></i>
            <span x-text="toastMessage"></span>
        </div>
    </div>

    <script>
        function kudeApp() {
            return {
                showEmailModal: false,
                emailDestino: '<?= htmlspecialchars($factura['cliente_email'] ?? '') ?>',
                emailError: '',
                emailValido: false,
                enviando: false,
                showToast: false,
                toastMessage: '',
                toastType: 'success',
                
                // WhatsApp
                showWhatsAppModal: false,
                telefonoDestino: '',
                telefonoError: '',
                telefonoValido: false,
                codigoPais: '595',
                paises: [
                    { codigo: '595', nombre: 'Paraguay', bandera: '🇵🇾' },
                    { codigo: '54', nombre: 'Argentina', bandera: '🇦🇷' },
                    { codigo: '55', nombre: 'Brasil', bandera: '🇧🇷' },
                    { codigo: '56', nombre: 'Chile', bandera: '🇨🇱' },
                    { codigo: '591', nombre: 'Bolivia', bandera: '🇧🇴' },
                    { codigo: '598', nombre: 'Uruguay', bandera: '🇺🇾' },
                    { codigo: '51', nombre: 'Perú', bandera: '🇵🇪' },
                    { codigo: '593', nombre: 'Ecuador', bandera: '🇪🇨' },
                    { codigo: '57', nombre: 'Colombia', bandera: '🇨🇴' },
                    { codigo: '58', nombre: 'Venezuela', bandera: '🇻🇪' },
                    { codigo: '52', nombre: 'México', bandera: '🇲🇽' },
                    { codigo: '1', nombre: 'Estados Unidos', bandera: '🇺🇸' },
                    { codigo: '34', nombre: 'España', bandera: '🇪🇸' },
                ],
                
                init() {
                    this.validarEmail();
                },
                
                validarEmail() {
                    const email = this.emailDestino.trim();
                    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    
                    if (!email) {
                        this.emailError = '';
                        this.emailValido = false;
                    } else if (!regex.test(email)) {
                        this.emailError = 'Ingrese un email válido';
                        this.emailValido = false;
                    } else {
                        this.emailError = '';
                        this.emailValido = true;
                    }
                },
                
                validarTelefono() {
                    // Limpiar solo números
                    let tel = this.telefonoDestino.replace(/\D/g, '');
                    
                    if (!tel) {
                        this.telefonoError = '';
                        this.telefonoValido = false;
                    } else if (tel.length < 6) {
                        this.telefonoError = 'El número es muy corto';
                        this.telefonoValido = false;
                    } else if (tel.length > 15) {
                        this.telefonoError = 'El número es muy largo';
                        this.telefonoValido = false;
                    } else {
                        this.telefonoError = '';
                        this.telefonoValido = true;
                    }
                },
                
                enviarWhatsApp() {
                    if (!this.telefonoValido) return;
                    
                    // Limpiar el número
                    const numeroLimpio = this.telefonoDestino.replace(/\D/g, '');
                    const numeroCompleto = this.codigoPais + numeroLimpio;
                    
                    const kudeUrl = window.location.href;
                    const cdc = '<?= htmlspecialchars($cdc) ?>';
                    const verificarUrl = 'https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=' + cdc;
                    
                    const mensaje = `*KUDE - Factura Electrónica*\n\n` +
                        `*Detalles:*\n` +
                        `- Factura: <?= htmlspecialchars($factura['nro_factura'] ?? '') ?>\n` +
                        `- Fecha: <?= date('d/m/Y', strtotime($factura['fecha'])) ?>\n` +
                        `- Total: *<?= number_format((float)($factura['total'] ?? 0), 0, ',', '.') ?> Gs*\n\n` +
                        `*Ver KUDE:*\n${kudeUrl}\n\n` +
                        `---\n\n` +
                        `*Verificar en SET:*\n` +
                        `1. Ingrese a: https://ekuatia.set.gov.py/consultas\n` +
                        `2. Copie el CDC:\n${cdc}\n` +
                        `3. Pegue el CDC en el campo de consulta\n` +
                        `4. Complete la verificacion de robot\n` +
                        `5. Presione Consultar\n\n` +
                        `_<?= htmlspecialchars($empresa['empresa'] ?? '') ?>_`;
                    
                    // Abrir WhatsApp
                    const whatsappUrl = `https://wa.me/${numeroCompleto}?text=${encodeURIComponent(mensaje)}`;
                    window.open(whatsappUrl, '_blank');
                    
                    this.showWhatsAppModal = false;
                    this.mostrarToast('Se abrió WhatsApp', 'success');
                },
                
                async enviarEmail() {
                    if (!this.emailValido || this.enviando) return;
                    
                    // Generar enlace mailto con los datos pre-cargados
                    const asunto = encodeURIComponent('KUDE - Factura <?= htmlspecialchars($factura['nro_factura'] ?? '') ?> - <?= htmlspecialchars($empresa['empresa'] ?? '') ?>');
                    const kudeUrl = window.location.href;
                    const cdc = '<?= htmlspecialchars($cdc) ?>';
                    const verificarUrl = 'https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=' + cdc;
                    
                    const cuerpo = encodeURIComponent(
                        `Estimado/a Cliente,\n\n` +
                        `Le enviamos el comprobante electrónico de su compra.\n\n` +
                        `📄 DETALLES:\n` +
                        `• Factura: <?= htmlspecialchars($factura['nro_factura'] ?? '') ?>\n` +
                        `• Fecha: <?= date('d/m/Y', strtotime($factura['fecha'])) ?>\n` +
                        `• Total: <?= number_format((float)($factura['total'] ?? 0), 0, ',', '.') ?> Gs\n\n` +
                        `🔗 Ver KUDE:\n${kudeUrl}\n\n` +
                        `✅ Verificar en SET:\n${verificarUrl}\n\n` +
                        `CDC: ${cdc}\n\n` +
                        `---\n` +
                        `<?= htmlspecialchars($empresa['empresa'] ?? '') ?>\n` +
                        `Este es un documento tributario electrónico válido.`
                    );
                    
                    // Abrir cliente de correo
                    window.location.href = `mailto:${this.emailDestino}?subject=${asunto}&body=${cuerpo}`;
                    
                    this.showEmailModal = false;
                    this.mostrarToast('Se abrió su cliente de correo', 'success');
                },
                
                async copiarEnlace() {
                    try {
                        await navigator.clipboard.writeText(window.location.href);
                        this.mostrarToast('Enlace copiado al portapapeles', 'success');
                    } catch (err) {
                        // Fallback para navegadores antiguos
                        const input = document.createElement('input');
                        input.value = window.location.href;
                        document.body.appendChild(input);
                        input.select();
                        document.execCommand('copy');
                        document.body.removeChild(input);
                        this.mostrarToast('Enlace copiado al portapapeles', 'success');
                    }
                },
                
                mostrarToast(mensaje, tipo = 'success') {
                    this.toastMessage = mensaje;
                    this.toastType = tipo;
                    this.showToast = true;
                    setTimeout(() => this.showToast = false, 4000);
                }
            }
        }
        
        // Auto-print si viene con parámetro
        if (window.location.search.includes('autoprint=1')) {
            window.onload = () => setTimeout(() => window.print(), 500);
        }
    </script>
</body>
</html>
