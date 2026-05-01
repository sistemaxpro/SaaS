<?php
/**
 * Página de Verificación de Documento Electrónico - FULL PAGE IMPACT
 * Con Tailwind CSS y efectos especiales
 */

$cdc = isset($_GET['cdc']) ? preg_replace('/[^0-9]/', '', $_GET['cdc']) : '';
$cdcValido = strlen($cdc) === 44;
$factura = null;
$empresa = null;
$error = null;

if ($cdcValido) {
    $masterDb = 'serproc1';
    $dbHost = '168.231.95.50';
    $dbUser = 'sistemax';
    $dbPass = 'Armagedon123';
    
    try {
        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8");
        
        $stmtEmpresas = $pdo->query("SELECT id_empresa, empresa, ruc, dv, direccion, telefono, email, dbase FROM empresa WHERE activo = 1");
        $empresas = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($empresas as $emp) {
            if (empty($emp['dbase'])) continue;
            $dbName = $emp['dbase'];
            try {
                $stmtFactura = $pdo->prepare("
                    SELECT fv.*, c.nombre AS cliente_nombre, c.numero AS cliente_ruc
                    FROM $dbName.factura_ventas fv
                    LEFT JOIN $dbName.clientes c ON c.id = fv.id_cliente
                    WHERE fv.cdc = :cdc LIMIT 1
                ");
                $stmtFactura->execute([':cdc' => $cdc]);
                $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);
                if ($factura) { $empresa = $emp; break; }
            } catch (Exception $e) { continue; }
        }
        if (!$factura) $error = "Documento no encontrado en nuestro sistema";
    } catch (Exception $e) { $error = "Error de conexión"; }
}

function formatMoney($amount) { return number_format((float)$amount, 0, ',', '.'); }

function parseCDC($cdc) {
    if (strlen($cdc) < 44) return [];
    $tiposDe = ['01'=>'Factura Electrónica','02'=>'Factura de Exportación','03'=>'Factura de Importación',
                '04'=>'Autofactura','05'=>'Nota de Crédito','06'=>'Nota de Débito','07'=>'Nota de Remisión'];
    $tipoDoc = substr($cdc, 9, 2);
    $fechaStr = substr($cdc, 25, 8);
    $fecha = DateTime::createFromFormat('Ymd', $fechaStr);
    return [
        'tipo_doc' => $tiposDe[$tipoDoc] ?? 'Documento Electrónico',
        'fecha' => $fecha ? $fecha->format('d/m/Y') : $fechaStr
    ];
}
$cdcData = $cdcValido ? parseCDC($cdc) : [];
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Documento Electrónico | SistemaX</title>
    <meta name="description" content="Verificación de documentos electrónicos SIFEN Paraguay - Powered by SistemaX">
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
                        glow: {
                            '0%': { boxShadow: '0 0 20px rgba(139, 92, 246, 0.3)' },
                            '100%': { boxShadow: '0 0 40px rgba(139, 92, 246, 0.6)' },
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
        
        /* Fondo animado con partículas */
        .bg-animated {
            background: linear-gradient(-45deg, #0f0c29, #302b63, #24243e, #0f0c29);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
        }
        
        /* Partículas flotantes */
        .particles {
            position: fixed;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }
        
        .particle {
            position: absolute;
            width: 10px;
            height: 10px;
            background: rgba(139, 92, 246, 0.3);
            border-radius: 50%;
            animation: float 8s ease-in-out infinite;
        }
        
        /* Efecto de resplandor en texto */
        .text-glow {
            text-shadow: 0 0 40px rgba(139, 92, 246, 0.5), 0 0 80px rgba(139, 92, 246, 0.3);
        }
        
        /* Glassmorphism */
        .glass {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .glass-white {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
        }
        
        /* Botón con efecto neón */
        .btn-neon {
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
        }
        
        .btn-neon::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, #ff00ff, #00ffff, #ff00ff, #00ffff);
            background-size: 400%;
            z-index: -1;
            filter: blur(5px);
            animation: gradient 3s linear infinite;
            opacity: 0;
            transition: opacity 0.3s;
            border-radius: inherit;
        }
        
        .btn-neon:hover::before {
            opacity: 1;
        }
        
        /* Líneas de grid en el fondo */
        .grid-bg {
            background-image: 
                linear-gradient(rgba(139, 92, 246, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(139, 92, 246, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
        }
        
        /* Orbs animados */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.5;
        }
        
        /* Cursor personalizado para hover */
        .hover-lift {
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .hover-lift:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.3);
        }
        
        /* Numero animado */
        @keyframes countUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .stat-number {
            animation: countUp 0.5s ease-out forwards;
        }
        
        /* Scroll suave */
        html { scroll-behavior: smooth; }
        
        /* Toast notification */
        .toast {
            transform: translateX(-50%) translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
        }
        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
    </style>
</head>
<body class="bg-animated min-h-screen font-inter overflow-x-hidden">
    
    <!-- Orbs de fondo animados -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="orb w-96 h-96 bg-violet-600 top-0 -left-48 animate-pulse-slow"></div>
        <div class="orb w-80 h-80 bg-fuchsia-600 bottom-0 right-0 animate-float"></div>
        <div class="orb w-64 h-64 bg-cyan-500 top-1/2 left-1/2 animate-float-slow"></div>
    </div>
    
    <!-- Grid de fondo -->
    <div class="fixed inset-0 grid-bg pointer-events-none"></div>
    
    <!-- Navbar -->
    <nav class="fixed top-0 left-0 right-0 z-50 px-4 py-4">
        <div class="max-w-6xl mx-auto flex justify-between items-center">
            <a href="https://sistemax.com.py" target="_blank" class="flex items-center gap-3 group">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-violet-600 to-fuchsia-600 flex items-center justify-center text-white font-black text-xl shadow-lg shadow-violet-500/30 group-hover:scale-110 transition-transform">
                    S<span class="text-cyan-300">X</span>
                </div>
                <div>
                    <div class="text-white font-bold text-xl tracking-tight">Sistema<span class="text-violet-400">X</span></div>
                    <div class="text-violet-300/60 text-xs">Facturación Electrónica</div>
                </div>
            </a>
            <a href="https://sistemax.com.py" target="_blank" 
               class="hidden md:flex items-center gap-2 px-6 py-3 rounded-full bg-white/10 hover:bg-white/20 text-white font-semibold text-sm backdrop-blur-sm border border-white/10 transition-all hover:scale-105">
                <span>Conocer SistemaX</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </a>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="relative z-10 pt-24 pb-16 px-4">
        <div class="max-w-5xl mx-auto">
            
            <!-- Hero Section -->
            <div class="text-center mb-12 animate-fade-in">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-violet-500/20 border border-violet-500/30 text-violet-300 text-sm font-medium mb-6">
                    <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                    Sistema SIFEN Verificado
                </div>
                <h1 class="text-4xl md:text-6xl font-black text-white mb-4 text-glow">
                    Verificación de<br>
                    <span class="bg-gradient-to-r from-violet-400 via-fuchsia-400 to-cyan-400 bg-clip-text text-transparent">
                        Documento Electrónico
                    </span>
                </h1>
                <p class="text-lg text-violet-200/70 max-w-2xl mx-auto">
                    Compruebe la autenticidad de su comprobante electrónico de forma rápida y segura
                </p>
            </div>
            
            <!-- Card de Verificación -->
            <div class="glass rounded-3xl overflow-hidden shadow-2xl shadow-violet-500/10 mb-16 animate-slide-up hover-lift">
                
                <!-- Header del Card -->
                <div class="bg-gradient-to-r from-violet-600 via-fuchsia-600 to-violet-600 p-8 text-center relative overflow-hidden">
                    <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\"60\" height=\"60\" viewBox=\"0 0 60 60\" xmlns=\"http://www.w3.org/2000/svg\"%3E%3Cg fill=\"none\" fill-rule=\"evenodd\"%3E%3Cg fill=\"%23ffffff\" fill-opacity=\"0.05\"%3E%3Cpath d=\"M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')]"></div>
                    <div class="relative">
                        <div class="text-6xl mb-4">🔐</div>
                        <h2 class="text-2xl font-bold text-white">Estado del Documento</h2>
                    </div>
                </div>
                
                <!-- Contenido del Card -->
                <div class="glass-white p-8">
                    
                    <?php if (!$cdcValido): ?>
                        <!-- CDC Inválido -->
                        <div class="flex items-center gap-4 p-6 rounded-2xl bg-red-50 border border-red-200 mb-6">
                            <div class="text-5xl">❌</div>
                            <div>
                                <h3 class="font-bold text-red-800 text-lg">CDC Inválido</h3>
                                <p class="text-red-600">El código de control debe tener exactamente 44 dígitos</p>
                            </div>
                        </div>
                        
                    <?php elseif ($error): ?>
                        <!-- Documento no encontrado -->
                        <div class="flex items-center gap-4 p-6 rounded-2xl bg-amber-50 border border-amber-200 mb-6">
                            <div class="text-5xl">⚠️</div>
                            <div>
                                <h3 class="font-bold text-amber-800 text-lg"><?php echo htmlspecialchars($error); ?></h3>
                                <p class="text-amber-600">Puede verificar directamente en SIFEN</p>
                            </div>
                        </div>
                        
                        <!-- CDC Box -->
                        <div class="bg-gradient-to-br from-slate-50 to-slate-100 rounded-2xl p-6 border-2 border-dashed border-slate-300 text-center mb-6">
                            <div class="text-xs font-bold text-violet-600 uppercase tracking-wider mb-3">Código de Control (CDC)</div>
                            <div class="font-mono text-xs md:text-sm bg-white rounded-xl p-4 border border-slate-200 break-all mb-4 select-all" id="cdcValue">
                                <?php echo htmlspecialchars($cdc); ?>
                            </div>
                            <button onclick="copiarCDC()" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white font-semibold rounded-xl hover:shadow-lg hover:shadow-violet-500/30 transition-all hover:-translate-y-0.5">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                                Copiar CDC
                            </button>
                        </div>
                        
                    <?php elseif ($factura): ?>
                        <!-- Documento Encontrado - Success -->
                        <div class="flex items-center gap-4 p-6 rounded-2xl bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200 mb-6 animate-slide-up">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-3xl shadow-lg shadow-emerald-500/30">
                                ✅
                            </div>
                            <div>
                                <h3 class="font-bold text-emerald-800 text-xl">Documento Verificado</h3>
                                <p class="text-emerald-600">Registrado correctamente en el sistema</p>
                            </div>
                        </div>
                        
                        <?php if ($empresa): ?>
                        <!-- Emisor -->
                        <div class="bg-gradient-to-r from-violet-50 to-fuchsia-50 rounded-2xl p-5 mb-6 border-l-4 border-violet-500">
                            <div class="text-xs font-bold text-violet-600 uppercase tracking-wider mb-1">Emisor</div>
                            <div class="font-bold text-slate-800 text-lg"><?php echo htmlspecialchars($empresa['empresa']); ?></div>
                            <div class="text-slate-500 text-sm">RUC: <?php echo htmlspecialchars($empresa['ruc'] . '-' . $empresa['dv']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Grid de información -->
                        <div class="grid md:grid-cols-2 gap-4 mb-6">
                            <div class="bg-slate-50 rounded-xl p-4">
                                <div class="text-xs text-slate-500 mb-1">Tipo de Documento</div>
                                <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($cdcData['tipo_doc'] ?? 'Factura'); ?></div>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4">
                                <div class="text-xs text-slate-500 mb-1">Número</div>
                                <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($factura['nro_factura'] ?? ''); ?></div>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4">
                                <div class="text-xs text-slate-500 mb-1">Fecha de Emisión</div>
                                <div class="font-semibold text-slate-800"><?php echo date('d/m/Y', strtotime($factura['fecha'])); ?></div>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4">
                                <div class="text-xs text-slate-500 mb-1">Cliente</div>
                                <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($factura['cliente_nombre'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                        
                        <!-- Total destacado -->
                        <div class="bg-gradient-to-r from-emerald-500 to-teal-500 rounded-2xl p-6 text-center text-white mb-6">
                            <div class="text-sm opacity-80 mb-1">Total del Documento</div>
                            <div class="text-4xl font-black"><?php echo formatMoney($factura['total'] ?? 0); ?> <span class="text-2xl">Gs</span></div>
                        </div>
                        
                        <!-- CDC Box -->
                        <div class="bg-gradient-to-br from-slate-50 to-slate-100 rounded-2xl p-6 border-2 border-dashed border-slate-300 text-center mb-6">
                            <div class="text-xs font-bold text-violet-600 uppercase tracking-wider mb-3">Código de Control (CDC)</div>
                            <div class="font-mono text-xs bg-white rounded-xl p-4 border border-slate-200 break-all mb-4 select-all" id="cdcValue">
                                <?php echo htmlspecialchars($cdc); ?>
                            </div>
                            <button onclick="copiarCDC()" class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white font-semibold rounded-xl hover:shadow-lg hover:shadow-violet-500/30 transition-all hover:-translate-y-0.5">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                                Copiar CDC
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Verificación SIFEN -->
                    <div class="bg-gradient-to-r from-amber-50 to-orange-50 rounded-2xl p-6 border border-amber-200">
                        <div class="flex items-start gap-4">
                            <div class="text-4xl">🏛️</div>
                            <div class="flex-1">
                                <h4 class="font-bold text-amber-800 mb-2">Verificación Oficial en SIFEN</h4>
                                <p class="text-amber-700 text-sm mb-4">Para validar legalmente este documento en el sistema de la SET:</p>
                                <ol class="text-amber-700 text-sm mb-4 space-y-1">
                                    <li>1. Copie el CDC con el botón de arriba</li>
                                    <li>2. Ingrese al portal de SIFEN</li>
                                    <li>3. Pegue el CDC y complete el captcha</li>
                                </ol>
                                <a href="https://ekuatia.set.gov.py/consultas/" target="_blank" 
                                   class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-white font-semibold rounded-xl hover:shadow-lg transition-all hover:-translate-y-0.5 w-full justify-center md:w-auto">
                                    <span>Ir a SIFEN</span>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sección Promocional SistemaX -->
            <div class="glass rounded-3xl p-8 md:p-12 text-center relative overflow-hidden">
                <!-- Efectos decorativos -->
                <div class="absolute top-0 left-0 w-40 h-40 bg-violet-500 rounded-full filter blur-3xl opacity-20 -translate-x-1/2 -translate-y-1/2"></div>
                <div class="absolute bottom-0 right-0 w-60 h-60 bg-fuchsia-500 rounded-full filter blur-3xl opacity-20 translate-x-1/2 translate-y-1/2"></div>
                
                <div class="relative">
                    <!-- Badge -->
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white text-sm font-semibold mb-6 animate-bounce-slow">
                        <span>✨</span> La Solución #1 en Paraguay
                    </div>
                    
                    <!-- Título -->
                    <h2 class="text-3xl md:text-5xl font-black text-white mb-4">
                        Potencia tu negocio con<br>
                        <span class="bg-gradient-to-r from-violet-400 via-fuchsia-400 to-cyan-400 bg-clip-text text-transparent">
                            SistemaX
                        </span>
                    </h2>
                    
                    <p class="text-violet-200/80 text-lg max-w-2xl mx-auto mb-10">
                        Sistema integral de gestión comercial con facturación electrónica SIFEN, 
                        inventario, ventas, compras, cuentas a cobrar y mucho más.
                    </p>
                    
                    <!-- Features Grid -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10">
                        <div class="glass rounded-2xl p-5 hover-lift">
                            <div class="text-3xl mb-2">⚡</div>
                            <div class="font-bold text-white">SIFEN</div>
                            <div class="text-violet-300/60 text-sm">100% Integrado</div>
                        </div>
                        <div class="glass rounded-2xl p-5 hover-lift">
                            <div class="text-3xl mb-2">🏢</div>
                            <div class="font-bold text-white">Multi-Empresa</div>
                            <div class="text-violet-300/60 text-sm">Sin límites</div>
                        </div>
                        <div class="glass rounded-2xl p-5 hover-lift">
                            <div class="text-3xl mb-2">☁️</div>
                            <div class="font-bold text-white">En la Nube</div>
                            <div class="text-violet-300/60 text-sm">Acceso 24/7</div>
                        </div>
                        <div class="glass rounded-2xl p-5 hover-lift">
                            <div class="text-3xl mb-2">🎧</div>
                            <div class="font-bold text-white">Soporte</div>
                            <div class="text-violet-300/60 text-sm">WhatsApp directo</div>
                        </div>
                    </div>
                    
                    <!-- Stats -->
                    <div class="flex flex-wrap justify-center gap-8 md:gap-16 mb-10">
                        <div class="text-center">
                            <div class="text-4xl md:text-5xl font-black text-white stat-number">500+</div>
                            <div class="text-violet-300/60">Empresas</div>
                        </div>
                        <div class="text-center">
                            <div class="text-4xl md:text-5xl font-black text-white stat-number">1M+</div>
                            <div class="text-violet-300/60">Facturas/mes</div>
                        </div>
                        <div class="text-center">
                            <div class="text-4xl md:text-5xl font-black text-white stat-number">99.9%</div>
                            <div class="text-violet-300/60">Uptime</div>
                        </div>
                    </div>
                    
                    <!-- CTA Buttons -->
                    <div class="flex flex-col md:flex-row gap-4 justify-center">
                        <a href="https://sistemax.com.py" target="_blank" 
                           class="btn-neon inline-flex items-center justify-center gap-3 px-8 py-4 bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white font-bold text-lg rounded-2xl hover:shadow-2xl hover:shadow-violet-500/40 transition-all hover:-translate-y-1">
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
            
        </div>
    </main>
    
    <!-- Footer -->
    <footer class="relative z-10 text-center py-8 border-t border-white/10">
        <div class="flex items-center justify-center gap-3 mb-4">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-600 to-fuchsia-600 flex items-center justify-center text-white font-bold text-sm">SX</div>
            <span class="text-white font-semibold">SistemaX</span>
        </div>
        <p class="text-violet-300/50 text-sm">
            © <?php echo date('Y'); ?> SistemaX. Todos los derechos reservados.<br>
            <a href="https://sistemax.com.py" target="_blank" class="text-violet-400 hover:text-violet-300">sistemax.com.py</a>
        </p>
    </footer>
    
    <!-- Toast -->
    <div class="fixed bottom-8 left-1/2 toast bg-slate-900 text-white px-6 py-3 rounded-full shadow-2xl flex items-center gap-3 z-50" id="toast">
        <span class="text-emerald-400">✓</span> CDC copiado al portapapeles
    </div>
    
    <script>
        function copiarCDC() {
            const cdc = document.getElementById('cdcValue').textContent.trim();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(cdc).then(mostrarToast);
            } else {
                const ta = document.createElement('textarea');
                ta.value = cdc;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                mostrarToast();
            }
        }
        
        function mostrarToast() {
            const toast = document.getElementById('toast');
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2500);
        }
        
        // Animación de números al hacer scroll
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-slide-up');
                }
            });
        }, { threshold: 0.1 });
        
        document.querySelectorAll('.stat-number').forEach(el => observer.observe(el));
    </script>
</body>
</html>
