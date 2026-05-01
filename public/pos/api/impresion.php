<?php
// impresion.php - App de Impresión/Confirmación de Venta
session_start();
$id_empresa = $_SESSION['id_empresa'] ?? 169;
?>
<!DOCTYPE html>
<html lang="es" x-data="impresionApp()" x-init="init()" :class="isDarkMode ? 'dark' : ''">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impresión de Venta</title>
    <link rel="stylesheet" href="../../assets/tailwind.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { primary: '#3b82f6', success: '#22c55e', danger: '#ef4444' }
                }
            }
        }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 dark:bg-slate-900 text-slate-900 dark:text-white min-h-screen flex items-center justify-center p-4">

    <!-- Card Principal -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-md overflow-hidden flex flex-col max-h-[90vh]">
        
        <!-- Header -->
        <div class="bg-blue-600 p-6 flex flex-col items-center justify-center text-center relative overflow-hidden">
            <div class="bg-white/20 absolute -top-10 -right-10 w-32 h-32 rounded-full blur-2xl"></div>
            <div class="bg-white/10 absolute -bottom-10 -left-10 w-24 h-24 rounded-full blur-xl"></div>
            
            <div class="bg-white text-blue-600 rounded-full w-16 h-16 flex items-center justify-center mb-3 shadow-lg">
                <span class="text-3xl">✅</span>
            </div>
            <h1 class="text-white text-2xl font-black tracking-tight" x-text="loading ? 'Cargando...' : 'Venta Exitosa'"></h1>
            <p class="text-blue-100 font-medium mt-1" x-show="venta">
                Factura <span class="font-bold bg-blue-700/50 px-2 py-0.5 rounded text-white" x-text="venta?.nro_factura"></span>
            </p>
        </div>

        <!-- Contenido (Scrollable) -->
        <div class="flex-1 overflow-y-auto p-6 space-y-6 custom-scroll">
            
            <!-- Loading -->
            <div x-show="loading" class="flex justify-center py-10">
                <div class="w-10 h-10 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin"></div>
            </div>

            <!-- Error -->
            <div x-show="error" x-cloak class="bg-red-50 dark:bg-red-900/20 text-red-600 p-4 rounded-xl text-center">
                <p x-text="errorMsg"></p>
                <button @click="window.location.href='../index.php'" class="mt-2 text-sm underline font-bold">Volver al POS</button>
            </div>

            <!-- Detalle Venta -->
            <div x-show="venta && !loading" x-cloak class="space-y-6">
                
                <!-- Cliente -->
                <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-700/50 rounded-xl border border-slate-100 dark:border-slate-700">
                    <div class="w-10 h-10 bg-slate-200 dark:bg-slate-600 rounded-full flex items-center justify-center text-xl">👤</div>
                    <div>
                        <p class="text-xs text-slate-400 font-bold uppercase tracking-wider">Cliente</p>
                        <p class="font-bold truncate max-w-[200px]" x-text="venta.cliente || 'Consumidor Final'"></p>
                    </div>
                </div>

                <!-- Lista Items (Resumen) -->
                <div>
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Resumen</h3>
                    <div class="space-y-2">
                        <template x-for="item in venta.items" :key="item.codigo">
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-slate-600 dark:text-slate-300">
                                    <span class="font-bold text-slate-800 dark:text-slate-200" x-text="parseFloat(item.cantidad)"></span> x <span x-text="item.descripcion"></span>
                                </span>
                                <span class="font-medium" x-text="formatCurrency(item.importe)"></span>
                            </div>
                        </template>
                    </div>
                    <div class="border-t border-dashed border-slate-300 dark:border-slate-600 my-4"></div>
                    <div class="flex justify-between items-center text-xl font-black text-slate-900 dark:text-white">
                        <span>TOTAL</span>
                        <span x-text="formatCurrency(venta.total)"></span>
                    </div>
                </div>

                <!-- Bloque SIFEN (Electrónica) -->
                <div x-show="venta.cdc" x-cloak class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-2xl border border-blue-100 dark:border-blue-800 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-bold text-blue-600 dark:text-blue-400 uppercase tracking-widest">Factura Electrónica</h3>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-tighter" 
                              :class="venta.estado_sifen === 'Aprobado' ? 'bg-green-500 text-white' : 'bg-orange-500 text-white'"
                              x-text="venta.estado_sifen"></span>
                    </div>
                    
                    <div class="space-y-1">
                        <p class="text-[10px] text-blue-400 font-bold uppercase tracking-tight">CDC (Código de Control)</p>
                        <p class="text-[10px] font-mono break-all text-blue-800 dark:text-blue-200 leading-tight" x-text="venta.cdc"></p>
                    </div>

                    <div x-show="venta.qr_sifen" class="flex flex-col items-center justify-center pt-2 gap-2">
                        <div class="bg-white p-2 rounded-xl shadow-inner">
                            <img :src="'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(venta.qr_sifen)" 
                                 class="w-32 h-32" alt="QR SIFEN">
                        </div>
                        <p class="text-[9px] text-blue-500 font-medium text-center">Escanee para verificar en el portal de la SET</p>
                    </div>

                    <div x-show="venta.estado_sifen !== 'Aprobado' && venta.mensaje_sifen" class="mt-2 p-2 bg-orange-100 dark:bg-orange-900/30 rounded-lg">
                        <p class="text-[10px] text-orange-700 dark:text-orange-300 font-medium" x-text="venta.mensaje_sifen"></p>
                    </div>
                </div>

            </div>
        </div>

        <!-- Footer Actions -->
        <div class="p-4 bg-slate-50 dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 grid grid-cols-2 gap-3">
            
            <button @click="print()" class="col-span-2 bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-xl font-bold text-lg shadow-lg shadow-blue-500/30 flex items-center justify-center gap-2 transition-all">
                <span>🖨️</span> Imprimir Ticket
            </button>

            <button @click="sendWhatsApp()" class="bg-green-500 hover:bg-green-600 text-white py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all">
                <span>📱</span> WhatsApp
            </button>
            
            <button class="bg-orange-500 hover:bg-orange-600 text-white py-3 rounded-xl font-bold flex items-center justify-center gap-2 transition-all opacity-50 cursor-not-allowed" title="Próximamente">
                <span>📧</span> Email
            </button>

            <button @click="exit()" class="col-span-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-200 py-3 rounded-xl font-bold transition-all">
                Nueva Venta (Salir)
            </button>

        </div>

    </div>

    <script>
        function impresionApp() {
            return {
                loading: true,
                error: false,
                errorMsg: '',
                venta: null,
                idFactura: new URLSearchParams(window.location.search).get('id'),
                idEmpresa: <?php echo $id_empresa; ?>,
                isDarkMode: localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches),

                async init() {
                    if (!this.idFactura) {
                        this.showError('No se especificó ID de venta');
                        return;
                    }
                    await this.loadVenta();
                },

                async loadVenta() {
                    try {
                        const res = await fetch(`venta.php?id=${this.idFactura}&id_empresa=${this.idEmpresa}`);
                        const data = await res.json();
                        
                        if (data.success) {
                            this.venta = data.venta;
                        } else {
                            this.showError(data.message || 'Error al cargar venta');
                        }
                    } catch (e) {
                        this.showError('Error de conexión');
                        console.error(e);
                    } finally {
                        this.loading = false;
                    }
                },

                showError(msg) {
                    this.error = true;
                    this.errorMsg = msg;
                    this.loading = false;
                },

                formatCurrency(value) {
                    return new Intl.NumberFormat('es-PY', { style: 'currency', currency: 'PYG', maximumFractionDigits: 0 }).format(value);
                },

                print() {
                    // Detectar si es Factura Electrónica (tiene CDC) o Nota simple
                    const isElectronica = this.venta.cdc && this.venta.cdc.length > 20;
                    const script = isElectronica ? '../../kude_ticket.php' : '../../nota_ticket.php';
                    
                    const url = `${script}?id=${this.idFactura}&autoprint=1`;
                    window.open(url, '_blank');
                },

                sendWhatsApp() {
                    if (!this.venta) return;
                    const phone = prompt('Ingrese número para WhatsApp (Ej: 0981...)', ''); 
                    if (!phone) return;
                    
                    const text = `Hola! Gracias por tu compra en SistemaX.\nFactura: ${this.venta.nro_factura}\nTotal: ${this.formatCurrency(this.venta.total)}\nGracias por preferirnos!`;
                    const url = `https://wa.me/595${phone.substring(1)}?text=${encodeURIComponent(text)}`;
                    window.open(url, '_blank');
                },

                exit() {
                    window.location.href = '../index.php';
                }
            }
        }
    </script>
</body>
</html>
