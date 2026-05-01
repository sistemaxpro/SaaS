<?php
/**
 * Página de Verificación de Nota de Venta (Interna) - FULL PAGE IMPACT
 * Replica de verificar.php adaptada para documentos internos
 */

$id_factura = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$factura = null;
$empresa = null;
$error = null;

if ($id_factura > 0) {
    $masterDb = 'serproc1';
    $dbHost = '168.231.95.50';
    $dbUser = 'sistemax';
    $dbPass = 'Armagedon123';
    
    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8");
        
        // Optimización: Si viene id_empresa, buscar directo sin iterar
        $id_empresa_get = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : 0;
        $sqlEmpresas = "SELECT id_empresa, empresa, ruc, dv, direccion, telefono, email, dbase FROM empresa WHERE activo = 1";
        
        if ($id_empresa_get > 0) {
            $sqlEmpresas .= " AND id_empresa = $id_empresa_get";
        }
        
        $stmtEmpresas = $pdo->query($sqlEmpresas);
        $empresas = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($empresas as $emp) {
            if (empty($emp['dbase'])) continue;
            $dbName = $emp['dbase'];
            try {
                $stmtFactura = $pdo->prepare("
                    SELECT fv.*, c.nombre AS cliente_nombre, c.numero AS cliente_ruc
                    FROM $dbName.factura_ventas fv
                    LEFT JOIN $dbName.clientes c ON c.id = fv.id_cliente
                    WHERE fv.id_factura = :id LIMIT 1
                ");
                $stmtFactura->execute([':id' => $id_factura]);
                $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);
                if ($factura) { $empresa = $emp; break; }
            } catch (Exception $e) { continue; }
        }
        if (!$factura) $error = "Nota no encontrada en nuestro sistema";
    } catch (Exception $e) { $error = "Error de conexión"; }
} else {
    $error = "ID de documento inválido";
}

function formatMoney($amount) { return number_format((float)$amount, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Nota de Venta | SistemaX</title>
    <meta name="description" content="Verificación de documentos internos SistemaX">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 'inter': ['Inter', 'sans-serif'] },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'float-slow': 'float 8s ease-in-out infinite',
                        'pulse-slow': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'gradient': 'gradient 8s ease infinite',
                        'glow': 'glow 2s ease-in-out infinite alternate',
                        'slide-up': 'slideUp 0.5s ease-out',
                        'fade-in': 'fadeIn 0.8s ease-out',
                        'bounce-slow': 'bounce 3s infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-20px)' },
                        },
                        gradient: {
                            '0%, 100%': { backgroundPosition: '0% 50%' },
                            '50%': { backgroundPosition: '100% 50%' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(20px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .bg-animated {
            background: linear-gradient(-45deg, #1e3a8a, #1e40af, #172554, #1e3a8a);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        .text-glow { text-shadow: 0 0 40px rgba(59, 130, 246, 0.5), 0 0 80px rgba(59, 130, 246, 0.3); }
        .glass { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .glass-white { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); }
        .grid-bg {
            background-image: 
                linear-gradient(rgba(59, 130, 246, 0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(59, 130, 246, 0.05) 1px, transparent 1px);
            background-size: 50px 50px;
        }
        .hover-lift { transition: transform 0.3s, box-shadow 0.3s; }
        .hover-lift:hover { transform: translateY(-5px); box-shadow: 0 20px 40px -10px rgba(0,0,0,0.3); }
        @keyframes countUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .stat-number { animation: countUp 0.5s ease-out forwards; }
    </style>
</head>
<body class="bg-animated min-h-screen font-inter overflow-x-hidden">
    
    <div class="fixed inset-0 grid-bg pointer-events-none"></div>
    
    <!-- Navbar -->
    <nav class="fixed top-0 left-0 right-0 z-50 px-4 py-4">
        <div class="max-w-6xl mx-auto flex justify-between items-center">
            <a href="https://sistemax.com.py" target="_blank" class="flex items-center gap-3 group">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 flex items-center justify-center text-white font-black text-xl shadow-lg group-hover:scale-110 transition-transform">
                    S<span class="text-blue-300">X</span>
                </div>
                <div>
                    <div class="text-white font-bold text-xl tracking-tight">Sistema<span class="text-blue-400">X</span></div>
                    <div class="text-blue-300/60 text-xs">Gestión Comercial</div>
                </div>
            </a>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="relative z-10 pt-24 pb-16 px-4">
        <div class="max-w-5xl mx-auto">
            
            <!-- Hero Section -->
            <div class="text-center mb-12 animate-fade-in">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-blue-500/20 border border-blue-500/30 text-blue-300 text-sm font-medium mb-6">
                    <span class="w-2 h-2 bg-blue-400 rounded-full animate-pulse"></span>
                    Documento Interno Verificado
                </div>
                <h1 class="text-3xl md:text-5xl font-black text-white mb-4 text-glow">
                    Verificación de<br>
                    <span class="bg-gradient-to-r from-blue-400 via-indigo-400 to-cyan-400 bg-clip-text text-transparent">
                        Nota de Venta
                    </span>
                </h1>
                <p class="text-lg text-blue-200/70 max-w-2xl mx-auto">
                    Detalle del comprobante registrado en SistemaX
                </p>
            </div>
            
            <!-- Card de Verificación -->
            <div class="glass rounded-3xl overflow-hidden shadow-2xl shadow-blue-500/10 mb-16 animate-slide-up hover-lift">
                
                <!-- Header del Card -->
                <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-600 p-8 text-center relative overflow-hidden">
                    <div class="relative">
                        <div class="text-6xl mb-4">📑</div>
                        <h2 class="text-2xl font-bold text-white">Estado de la Nota</h2>
                    </div>
                </div>
                
                <!-- Contenido del Card -->
                <div class="glass-white p-8">
                    
                    <?php if ($error): ?>
                        <div class="flex items-center gap-4 p-6 rounded-2xl bg-amber-50 border border-amber-200 mb-6">
                            <div class="text-5xl">⚠️</div>
                            <div>
                                <h3 class="font-bold text-amber-800 text-lg"><?php echo htmlspecialchars($error); ?></h3>
                                <p class="text-amber-600">Verifique el ID del documento</p>
                            </div>
                        </div>
                        
                    <?php elseif ($factura): ?>
                        <!-- Documento Encontrado - Success -->
                        <div class="flex items-center gap-4 p-6 rounded-2xl bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200 mb-6 animate-slide-up">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-3xl shadow-lg shadow-emerald-500/30">
                                ✅
                            </div>
                            <div>
                                <h3 class="font-bold text-emerald-800 text-xl">Nota Válida</h3>
                                <p class="text-emerald-600">Documento interno registrado correctamente</p>
                            </div>
                        </div>
                        
                        <?php if ($empresa): ?>
                        <!-- Emisor -->
                        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl p-5 mb-6 border-l-4 border-blue-500">
                            <div class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-1">Emisor</div>
                            <div class="font-bold text-slate-800 text-lg"><?php echo htmlspecialchars($empresa['empresa']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Grid de información -->
                        <div class="grid md:grid-cols-2 gap-4 mb-6">
                            <div class="bg-slate-50 rounded-xl p-4">
                                <div class="text-xs text-slate-500 mb-1">Tipo de Documento</div>
                                <div class="font-semibold text-slate-800">Nota de Venta (Interna)</div>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4">
                                <div class="text-xs text-slate-500 mb-1">Número / ID</div>
                                <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($factura['nro_factura'] ?? $factura['id_factura']); ?></div>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4">
                                <div class="text-xs text-slate-500 mb-1">Fecha de Emisión</div>
                                <div class="font-semibold text-slate-800"><?php echo date('d/m/Y H:i', strtotime($factura['fecha'])); ?></div>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4">
                                <div class="text-xs text-slate-500 mb-1">Cliente</div>
                                <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($factura['cliente_nombre'] ?? 'Consumidor Final'); ?></div>
                            </div>
                        </div>
                        
                        <!-- Total destacado -->
                        <div class="bg-gradient-to-r from-emerald-500 to-teal-500 rounded-2xl p-6 text-center text-white mb-6">
                            <div class="text-sm opacity-80 mb-1">Total del Documento</div>
                            <div class="text-4xl font-black"><?php echo formatMoney($factura['total'] ?? 0); ?> <span class="text-2xl">Gs</span></div>
                        </div>
                        
                        <!-- Botón Imprimir Copia -->
                        <div class="mb-6">
                            <a href="nota_ticket.php?id=<?php echo $id_factura; ?>&autoprint=1" target="_blank" 
                               class="inline-flex items-center justify-center gap-2 px-6 py-4 bg-slate-800 text-white font-semibold rounded-xl hover:bg-slate-700 hover:shadow-lg transition-all hover:-translate-y-0.5 w-full">
                                <span class="text-xl">🖨️</span>
                                <span>Imprimir Copia de Nota</span>
                            </a>
                        </div>
                        
                        <!-- Aviso Uso Interno -->
                        <div class="bg-slate-100 rounded-xl p-4 text-center text-slate-500 text-xs">
                            Este es un comprobante de uso interno y no posee validez fiscal como factura.
                        </div>
                        
                    <?php endif; ?>
                    
                </div>
            </div>
            
            <!-- Sección Promocional SistemaX (Replica de verificar.php) -->
            <div class="glass rounded-3xl p-8 md:p-12 text-center relative overflow-hidden mb-12">
                <!-- Efectos decorativos -->
                <div class="absolute top-0 left-0 w-40 h-40 bg-blue-500 rounded-full filter blur-3xl opacity-20 -translate-x-1/2 -translate-y-1/2"></div>
                <div class="absolute bottom-0 right-0 w-60 h-60 bg-indigo-500 rounded-full filter blur-3xl opacity-20 translate-x-1/2 translate-y-1/2"></div>
                
                <div class="relative">
                    <!-- Badge -->
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-sm font-semibold mb-6 animate-bounce-slow">
                        <span>✨</span> La Solución #1 en Paraguay
                    </div>
                    
                    <!-- Título -->
                    <h2 class="text-3xl md:text-5xl font-black text-white mb-4">
                        Potencia tu negocio con<br>
                        <span class="bg-gradient-to-r from-blue-400 via-indigo-400 to-cyan-400 bg-clip-text text-transparent">
                            SistemaX
                        </span>
                    </h2>
                    
                    <p class="text-blue-200/80 text-lg max-w-2xl mx-auto mb-10">
                        Sistema integral de gestión comercial con facturación electrónica SIFEN, 
                        inventario, ventas, compras, cuentas a cobrar y mucho más.
                    </p>
                    
                    <!-- Features Grid -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10">
                        <div class="glass rounded-2xl p-5 hover-lift">
                            <div class="text-3xl mb-2">⚡</div>
                            <div class="font-bold text-white">SIFEN</div>
                            <div class="text-blue-300/60 text-sm">100% Integrado</div>
                        </div>
                        <div class="glass rounded-2xl p-5 hover-lift">
                            <div class="text-3xl mb-2">🏢</div>
                            <div class="font-bold text-white">Multi-Empresa</div>
                            <div class="text-blue-300/60 text-sm">Sin límites</div>
                        </div>
                        <div class="glass rounded-2xl p-5 hover-lift">
                            <div class="text-3xl mb-2">☁️</div>
                            <div class="font-bold text-white">En la Nube</div>
                            <div class="text-blue-300/60 text-sm">Acceso 24/7</div>
                        </div>
                        <div class="glass rounded-2xl p-5 hover-lift">
                            <div class="text-3xl mb-2">🎧</div>
                            <div class="font-bold text-white">Soporte</div>
                            <div class="text-blue-300/60 text-sm">WhatsApp directo</div>
                        </div>
                    </div>
                    
                    <!-- Stats -->
                    <div class="flex flex-wrap justify-center gap-8 md:gap-16 mb-10">
                        <div class="text-center">
                            <div class="text-4xl md:text-5xl font-black text-white stat-number">500+</div>
                            <div class="text-blue-300/60">Empresas</div>
                        </div>
                        <div class="text-center">
                            <div class="text-4xl md:text-5xl font-black text-white stat-number">1M+</div>
                            <div class="text-blue-300/60">Facturas/mes</div>
                        </div>
                        <div class="text-center">
                            <div class="text-4xl md:text-5xl font-black text-white stat-number">99.9%</div>
                            <div class="text-blue-300/60">Uptime</div>
                        </div>
                    </div>
                    
                    <!-- CTA Buttons -->
                    <div class="flex flex-col md:flex-row gap-4 justify-center">
                        <a href="https://sistemax.com.py" target="_blank" 
                           class="inline-flex items-center justify-center gap-3 px-8 py-4 bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-bold text-lg rounded-2xl hover:shadow-2xl hover:shadow-blue-500/40 transition-all hover:-translate-y-1">
                            <span>🚀 Comenzar Ahora</span>
                        </a>
                        <a href="https://wa.me/595983657691?text=Hola,%20quiero%20información%20sobre%20SistemaX" target="_blank"
                           class="inline-flex items-center justify-center gap-3 px-8 py-4 bg-white/10 hover:bg-white/20 text-white font-bold text-lg rounded-2xl border border-white/20 transition-all hover:-translate-y-1">
                            <svg class="w-6 h-6 text-green-400" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                            </svg>
                            <span>Contactar por WhatsApp</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Branding -->
            <div class="text-center">
                <p class="text-blue-200/50 text-sm">
                    © <?php echo date('Y'); ?> SistemaX. Gestión Inteligente.
                </p>
            </div>
            
        </div>
    </main>
</body>
</html>
