/**
 * USB Printer Manager para POS Mobile
 * Usa Web USB API para comunicación directa con impresoras térmicas
 */

class USBPrinterManager {
    constructor() {
        this.device = null;
        this.interface = null;
        this.endpoint = null;
        this.isConnected = false;
        
        // Vendors conocidos de impresoras térmicas
        this.knownVendors = [
            { vendorId: 0x0416, name: 'Winbond' },
            { vendorId: 0x0483, name: 'STMicroelectronics' },
            { vendorId: 0x04B8, name: 'Epson' },
            { vendorId: 0x0519, name: 'Star Micronics' },
            { vendorId: 0x0525, name: 'Netchip' },
            { vendorId: 0x067B, name: 'Prolific' },
            { vendorId: 0x0FE6, name: 'ICS' },
            { vendorId: 0x1504, name: 'SNBC' },
            { vendorId: 0x154F, name: 'SNBC BTP' },
            { vendorId: 0x1A86, name: 'QinHeng CH340' },
            { vendorId: 0x1CBE, name: 'Luminary Micro' },
            { vendorId: 0x1FC9, name: 'NXP' },
            { vendorId: 0x20D1, name: 'Simtech' },
            { vendorId: 0x28E9, name: 'GD32' },
            { vendorId: 0x2730, name: 'Citizen' },
            { vendorId: 0x28E9, name: 'GigaDevice' },
            { vendorId: 0x0DD4, name: 'Custom' },
            { vendorId: 0x0FE6, name: 'Kontron' },
            { vendorId: 0x0745, name: 'Syntech' },
            { vendorId: 0x6868, name: 'Generic POS' }
        ];
        
        // Configuración de impresora
        this.config = {
            paperWidth: 58, // mm (58 o 80)
            charsPerLine: 32, // caracteres (32 para 58mm, 48 para 80mm)
            encoding: 'cp437'
        };
    }
    
    /**
     * Verifica si Web USB está soportado
     */
    isSupported() {
        return 'usb' in navigator;
    }
    
    /**
     * Solicita permiso y conecta a una impresora USB
     */
    async connect() {
        if (!this.isSupported()) {
            throw new Error('Web USB no está soportado en este navegador');
        }
        
        try {
            // Permitir CUALQUIER dispositivo USB - sin filtros restrictivos
            // El usuario seleccionará manualmente su impresora
            this.device = await navigator.usb.requestDevice({ 
                filters: [] // Array vacío = mostrar TODOS los dispositivos USB
            });
            
            console.log('📠 Dispositivo seleccionado:', this.device);
            
            await this.device.open();
            
            // Seleccionar configuración
            if (this.device.configuration === null) {
                await this.device.selectConfiguration(1);
            }
            
            // Buscar interfaz de impresora (generalmente clase 7 = Printer)
            let interfaceNumber = 0;
            let endpointNumber = 1;
            
            for (const iface of this.device.configuration.interfaces) {
                for (const alt of iface.alternates) {
                    // Buscar interfaz de impresora o bulk transfer
                    if (alt.interfaceClass === 7 || alt.interfaceClass === 255) {
                        interfaceNumber = iface.interfaceNumber;
                        
                        // Buscar endpoint OUT
                        for (const ep of alt.endpoints) {
                            if (ep.direction === 'out') {
                                endpointNumber = ep.endpointNumber;
                                break;
                            }
                        }
                        break;
                    }
                }
            }
            
            this.interface = interfaceNumber;
            this.endpoint = endpointNumber;
            
            await this.device.claimInterface(this.interface);
            
            this.isConnected = true;
            console.log('✅ Impresora conectada:', {
                name: this.device.productName,
                vendor: this.device.manufacturerName,
                interface: this.interface,
                endpoint: this.endpoint
            });
            
            return {
                success: true,
                name: this.device.productName || 'Impresora USB',
                vendor: this.device.manufacturerName || 'Desconocido'
            };
            
        } catch (error) {
            console.error('❌ Error conectando impresora:', error);
            this.isConnected = false;
            throw error;
        }
    }
    
    /**
     * Desconecta la impresora
     */
    async disconnect() {
        if (this.device && this.isConnected) {
            try {
                await this.device.releaseInterface(this.interface);
                await this.device.close();
            } catch (e) {
                console.warn('Error al desconectar:', e);
            }
            this.isConnected = false;
            this.device = null;
        }
    }
    
    /**
     * Envía datos binarios a la impresora
     */
    async sendRaw(data) {
        if (!this.isConnected || !this.device) {
            throw new Error('Impresora no conectada');
        }
        
        try {
            // Convertir a Uint8Array si es necesario
            let buffer;
            if (typeof data === 'string') {
                buffer = new TextEncoder().encode(data);
            } else if (data instanceof ArrayBuffer) {
                buffer = new Uint8Array(data);
            } else if (data instanceof Uint8Array) {
                buffer = data;
            } else {
                throw new Error('Formato de datos no soportado');
            }
            
            // Enviar en chunks de 64 bytes (común para USB)
            const chunkSize = 64;
            for (let i = 0; i < buffer.length; i += chunkSize) {
                const chunk = buffer.slice(i, i + chunkSize);
                await this.device.transferOut(this.endpoint, chunk);
            }
            
            console.log('📤 Datos enviados:', buffer.length, 'bytes');
            return true;
            
        } catch (error) {
            console.error('❌ Error enviando datos:', error);
            throw error;
        }
    }
    
    /**
     * Imprime un ticket usando el API del servidor
     */
    async printTicket(idFactura, idEmpresa) {
        try {
            // Obtener datos ESC/POS del servidor
            const response = await fetch(`/public/pos/api/print_ticket.php?id=${idFactura}&id_empresa=${idEmpresa}&format=escpos&width=${this.config.charsPerLine}`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.message || 'Error generando ticket');
            }
            
            // Decodificar base64 y enviar
            const binaryData = atob(data.data);
            const bytes = new Uint8Array(binaryData.length);
            for (let i = 0; i < binaryData.length; i++) {
                bytes[i] = binaryData.charCodeAt(i);
            }
            
            await this.sendRaw(bytes);
            
            return { success: true, message: 'Ticket impreso correctamente' };
            
        } catch (error) {
            console.error('❌ Error imprimiendo ticket:', error);
            throw error;
        }
    }
    
    /**
     * Imprime texto simple
     */
    async printText(text) {
        const ESC = '\x1B';
        const GS = '\x1D';
        
        const commands = 
            ESC + '@' +           // Inicializar
            ESC + 'a\x01' +       // Centrar
            text + '\n' +
            ESC + 'd\x03' +       // Feed
            GS + 'V\x00';         // Cortar
        
        await this.sendRaw(commands);
    }
    
    /**
     * Prueba de impresión
     */
    async printTest() {
        const ESC = '\x1B';
        const GS = '\x1D';
        
        const testTicket = 
            ESC + '@' +                    // Init
            ESC + 'a\x01' +                // Center
            ESC + 'E\x01' +                // Bold on
            'PRUEBA DE IMPRESION\n' +
            ESC + 'E\x00' +                // Bold off
            '------------------------\n' +
            'SistemaX POS Mobile\n' +
            'Impresora USB OK\n' +
            '------------------------\n' +
            new Date().toLocaleString('es-PY') + '\n' +
            '\n\n' +
            ESC + 'd\x03' +                // Feed
            GS + 'V\x00';                  // Cut
        
        await this.sendRaw(testTicket);
        return { success: true, message: 'Prueba enviada' };
    }
    
    /**
     * Abre el cajón de dinero (si está conectado)
     */
    async openCashDrawer() {
        const ESC = '\x1B';
        // Comando estándar para abrir cajón (pin 2)
        const openDrawer = ESC + 'p\x00\x19\xFA';
        await this.sendRaw(openDrawer);
    }
    
    /**
     * Configura el ancho del papel
     */
    setPaperWidth(mm) {
        this.config.paperWidth = mm;
        this.config.charsPerLine = mm === 80 ? 48 : 32;
    }
}

// Instancia global
window.USBPrinter = new USBPrinterManager();

// Exportar para módulos
if (typeof module !== 'undefined' && module.exports) {
    module.exports = USBPrinterManager;
}
