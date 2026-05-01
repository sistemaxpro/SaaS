    function productosApp() {
        return {
            // Config
            idEmpresa: window.__PRODUCTOS_CONFIG__.idEmpresa,
            isDark: document.documentElement.classList.contains('dark'),
            themeMode: (document.documentElement.classList.contains('dark') || document.documentElement.getAttribute('data-bs-theme') === 'dark' || localStorage.getItem('theme') === 'dark') ? 'dark' : 'light',
            permisos: window.__PERMISOS__ || {},
            API: '/public/productos/api',
            _catalogosPromise: null,
            _catalogosLoaded: false,
            _catalogosLoading: false,
            _driveConfigChecked: false,
            _countInflight: false,
            _hydrationPendingIds: {},
            _hydrationInflight: false,
            _hydrationTimer: null,
            _suppressRowClickEditUntil: 0,
            
            // Data
            productos: [],
            stats: { total: 0, activos: 0, stockBajo: 0, sinStock: 0 },
            
            // Catálogos cacheados
            catGrupos: [],
            catMarcas: [],
            catModelos: [],
            catColores: [],
            catMedidas: [],
            catReferencias: [],
            catMarcasCodConversion: [],
            catMarcasAplicacion: [],
            catAniosAplicacion: [],
            catModelosAplicacion: [],
            catMotoresAplicacion: [],
            catCodigosMotorAplicacion: [],
            autoCatalogSeedTried: false,
            catTiposPrecio: [],
            sucursalesList: [],
            
            unidadesMedida: ['UNIDAD','CAJA','LITRO','BOLSA','PACK','METRO','KILO','TONELADA','DOCENA','COMBO'],
            
            // Filtros
            searchQuery: '',
            filtroGrupo: '',
            filtroMarca: '',
            filtroEstado: '1',
            sortBy: 'desproducto',
            sortDir: 'ASC',
            quickFilterText: '',
            
            // Paginación
            currentPage: 1,
            perPage: 25,
            perPageOptions: [10, 25, 50, 100, 200],
            totalRecords: 0,
            totalPages: 1,
            
            // UI
            loading: true,
            saving: false,
            showForm: false,
            showStock: false,
            showCatalogos: false,
            showImport: false,
            imgDriveConfigured: false,
            imgList: [],
            imgUploading: false,
            imgError: '',
            pendingImages: [],
            showProductCameraModal: false,
            productCameraError: '',
            productCameraStream: null,
            
            // Form
            formTab: 'general',
            formTabs: [
                { key: 'general', label: 'General', icon: 'fas fa-info-circle' },
                { key: 'imagen', label: 'Imagen', icon: 'fas fa-image' },
                { key: 'aplicaciones', label: 'Aplicaciones', icon: 'fas fa-car' },
                { key: 'precios', label: 'Precios', icon: 'fas fa-tags' },
                { key: 'codigos', label: 'Códigos', icon: 'fas fa-barcode' },
                { key: 'stock', label: 'Stock', icon: 'fas fa-warehouse' },
                { key: 'extras', label: 'Extras', icon: 'fas fa-cog' },
            ],
            form: {},
            stockSucursales: [],
            
            // Stock modal
            stockProductoId: null,
            stockProductoNombre: '',
            stockDetalle: [],
            stockMovimientos: [],
            ajuste: { id_sucursal: '', cantidad: 0, motivo: '' },
            
            // Catálogos modal
            catTab: 'grupos',
            catTabs: [
                { key: 'grupos', label: 'Grupos' },
                { key: 'marcas', label: 'Marcas' },
                { key: 'modelos', label: 'Modelos' },
                { key: 'colores', label: 'Colores' },
                { key: 'medidas', label: 'Medidas' },
                { key: 'referencias', label: 'Referencias' },
                { key: 'marcas_cod_conversion', label: 'Equivalentes' },
                { key: 'marcas_aplicacion', label: 'Marca aplicacion' },
                { key: 'anios_aplicacion', label: 'Anos aplicacion' },
                { key: 'modelos_aplicacion', label: 'Modelos aplicacion' },
                { key: 'motores_aplicacion', label: 'Motores aplicacion' },
                { key: 'codigos_motor_aplicacion', label: 'Codigos motor' },
            ],
            catItems: [],
            catNuevoNombre: '',
            catEditId: null,
            catEditNombre: '',
            
            // Importar
            importFile: null,
            importing: false,
            importResult: null,
            
            // Toast
            toast: { show: false, message: '', type: 'success' },
            cellMenu: { show: false, x: 0, y: 0, title: '', subtitle: '', items: [] },

            getCatalogoField(tabla) {
                const map = {
                    grupos: 'grupo',
                    marcas: 'marca',
                    modelos: 'modelo',
                    colores: 'color',
                    medidas: 'medida',
                    referencias: 'referencia',
                    marcas_cod_conversion: 'nombre',
                    marcas_aplicacion: 'nombre',
                    anios_aplicacion: 'nombre',
                    modelos_aplicacion: 'nombre',
                    motores_aplicacion: 'nombre',
                    codigos_motor_aplicacion: 'nombre'
                };
                return map[tabla] || 'nombre';
            },
            getCatalogoArray(tabla) {
                const map = {
                    grupos: this.catGrupos,
                    marcas: this.catMarcas,
                    modelos: this.catModelos,
                    colores: this.catColores,
                    medidas: this.catMedidas,
                    referencias: this.catReferencias,
                    marcas_cod_conversion: this.catMarcasCodConversion,
                    marcas_aplicacion: this.catMarcasAplicacion,
                    anios_aplicacion: this.catAniosAplicacion,
                    modelos_aplicacion: this.catModelosAplicacion,
                    motores_aplicacion: this.catMotoresAplicacion,
                    codigos_motor_aplicacion: this.catCodigosMotorAplicacion
                };
                return Array.isArray(map[tabla]) ? map[tabla] : [];
            },
            setCatalogoArray(tabla, data) {
                const d = Array.isArray(data) ? data : [];
                if (tabla === 'grupos') this.catGrupos = d;
                else if (tabla === 'marcas') this.catMarcas = d;
                else if (tabla === 'modelos') this.catModelos = d;
                else if (tabla === 'colores') this.catColores = d;
                else if (tabla === 'medidas') this.catMedidas = d;
                else if (tabla === 'referencias') this.catReferencias = d;
                else if (tabla === 'marcas_cod_conversion') this.catMarcasCodConversion = d;
                else if (tabla === 'marcas_aplicacion') this.catMarcasAplicacion = d;
                else if (tabla === 'anios_aplicacion') this.catAniosAplicacion = d;
                else if (tabla === 'modelos_aplicacion') this.catModelosAplicacion = d;
                else if (tabla === 'motores_aplicacion') this.catMotoresAplicacion = d;
                else if (tabla === 'codigos_motor_aplicacion') this.catCodigosMotorAplicacion = d;
            },
            normalizeCatalogItems(tabla, items) {
                const field = this.getCatalogoField(tabla);
                return (Array.isArray(items) ? items : []).map((it, idx) => {
                    const id = it?.id ?? it?.ID ?? it?.Id ?? idx + 1;
                    const nombre = it?.[field] ?? it?.nombre ?? '';
                    return { ...it, id, nombre, [field]: nombre };
                });
            },
            openCatalogos() {
                this.showCatalogos = true;
                this.switchCatTab('grupos');
                this.loadCatalogos();
            },
            openCatalogTab(tabla) {
                this.showCatalogos = true;
                this.switchCatTab(tabla);
                this.loadCatalogos();
            },
            switchCatTab(tabla) {
                this.catTab = tabla;
                this.catItems = this.normalizeCatalogItems(tabla, this.getCatalogoArray(tabla));
                this.loadCatalogo(tabla);
            },
            
            get margenPorcentaje() {
                if (!this.form.precio_compra || this.form.precio_compra <= 0) return 0;
                return ((this.form.precio_venta - this.form.precio_compra) / this.form.precio_compra) * 100;
            },
            
            init() {
                this.resetForm();
                this.isDark = document.documentElement.classList.contains('dark');
                this.themeMode = this.isDark ? 'dark' : 'light';
                setTimeout(() => this.loadCatalogos(), 1200);
                setTimeout(() => this.checkDriveConfig(), 1500);

                setTimeout(() => { if (window.__ensureFontAwesome) window.__ensureFontAwesome(); }, 1200);
                void this.loadProductos();
            },

            async checkDriveConfig() {
                if (this._driveConfigChecked) return;
                this._driveConfigChecked = true;
                try {
                    const res = await fetch(`${this.API}/imagen.php?action=check&id_empresa=${this.idEmpresa}`);
                    const data = await res.json();
                    this.imgDriveConfigured = !!data.configured;
                } catch (_) {
                    this.imgDriveConfigured = false;
                }
            },
            applyCatalogosBootstrap(data) {
                const payload = data && typeof data === 'object' ? data : {};
                this.catGrupos = this.normalizeCatalogItems('grupos', payload.grupos || []);
                this.catMarcas = this.normalizeCatalogItems('marcas', payload.marcas || []);
                this.catModelos = this.normalizeCatalogItems('modelos', payload.modelos || []);
                this.catColores = this.normalizeCatalogItems('colores', payload.colores || []);
                this.catMedidas = this.normalizeCatalogItems('medidas', payload.medidas || []);
                this.catReferencias = this.normalizeCatalogItems('referencias', payload.referencias || []);
                this.catMarcasCodConversion = this.normalizeCatalogItems('marcas_cod_conversion', payload.marcas_cod_conversion || []);
                this.catMarcasAplicacion = this.normalizeCatalogItems('marcas_aplicacion', payload.marcas_aplicacion || []);
                this.catAniosAplicacion = this.normalizeCatalogItems('anios_aplicacion', payload.anios_aplicacion || []);
                this.catModelosAplicacion = this.normalizeCatalogItems('modelos_aplicacion', payload.modelos_aplicacion || []);
                this.catMotoresAplicacion = this.normalizeCatalogItems('motores_aplicacion', payload.motores_aplicacion || []);
                this.catCodigosMotorAplicacion = this.normalizeCatalogItems('codigos_motor_aplicacion', payload.codigos_motor_aplicacion || []);
                this.sucursalesList = Array.isArray(payload.sucursales) ? payload.sucursales : [];
                this.catTiposPrecio = (Array.isArray(payload.tipos_precio) ? payload.tipos_precio : []).map((tp) => ({
                    ...tp,
                    id: String(tp?.id ?? ''),
                    tipo: String(tp?.tipo || '').trim(),
                }));
                this.syncPreciosTipos();
            },
            ensureTiposPrecioOptions(rows) {
                const current = Array.isArray(this.catTiposPrecio) ? [...this.catTiposPrecio] : [];
                const seen = new Set(current.map((tp) => String(tp?.id ?? '').trim()).filter(Boolean));
                (Array.isArray(rows) ? rows : []).forEach((pr) => {
                    const tipoId = String(pr?.tipo ?? '').trim();
                    if (!tipoId || seen.has(tipoId)) return;
                    current.push({
                        id: tipoId,
                        tipo: String(pr?.tipo_nombre || `Tipo ${tipoId}`).trim(),
                        porcentaje: parseFloat(pr?.tipo_porcentaje_default ?? pr?.porcentaje ?? 0) || 0,
                        moneda: String(pr?.tipo_moneda || pr?.moneda || 'PYG').trim() || 'PYG',
                        descuento: parseFloat(pr?.descuento ?? 0) || 0,
                    });
                    seen.add(tipoId);
                });
                this.catTiposPrecio = current;
                this.syncPreciosTipos();
            },
            
            // ========== CARGAR CATÁLOGOS ==========
            async loadCatalogos() {
                if (this._catalogosPromise) return this._catalogosPromise;
                this._catalogosLoading = true;
                this._catalogosPromise = (async () => {
                    try {
                        const res = await fetch(`${this.API}/catalogos.php?action=bootstrap&id_empresa=${this.idEmpresa}`);
                        const data = await res.json();
                        if (data?.success) {
                            this.applyCatalogosBootstrap(data.data || {});
                        }

                        const totalCatalogos =
                            this.catGrupos.length +
                            this.catMarcas.length +
                            this.catModelos.length +
                            this.catColores.length +
                            this.catMedidas.length +
                            this.catReferencias.length +
                            this.catMarcasCodConversion.length +
                            this.catMarcasAplicacion.length +
                            this.catAniosAplicacion.length +
                            this.catModelosAplicacion.length +
                            this.catMotoresAplicacion.length +
                            this.catCodigosMotorAplicacion.length;
                        if (!this.autoCatalogSeedTried && totalCatalogos === 0) {
                            this.autoCatalogSeedTried = true;
                            await this.cargarCatalogosMock(true);
                        }
                        this._catalogosLoaded = true;
                    } catch (error) {
                        console.error('Error cargando catálogos:', error);
                    } finally {
                        this._catalogosLoading = false;
                        if (!this._catalogosLoaded) {
                            this._catalogosPromise = null;
                        }
                    }
                })();
                return this._catalogosPromise;
            },
            
            // ========== CARGAR PRODUCTOS ==========
            async loadProductos() {
                this.loading = true;
                try {
                    const params = new URLSearchParams({
                        id_empresa: this.idEmpresa,
                        page: this.currentPage,
                        per_page: this.perPage,
                        fast: '1',
                        with_stats: '0',
                        defer_hydration: '1',
                        search: this.searchQuery,
                        grupo: this.filtroGrupo,
                        marca: this.filtroMarca,
                        estado: this.filtroEstado,
                        sort_by: this.sortBy,
                        sort_dir: this.sortDir
                    });
                    
                    const res = await fetch(`${this.API}/list.php?${params}`);
                    const data = await res.json();
                    
                    if (data.success) {
                        this.productos = data.data || [];
                        this.totalRecords = data.pagination?.total || 0;
                        this.totalPages = data.pagination?.total_pages || 1;
                        this.stats = data.stats || this.stats;
                    }
                } catch (error) {
                    console.error('Error cargando productos:', error);
                }
                this.loading = false;
            },
            
            // ========== SORT ==========
            setSort(col) {
                if (this.sortBy === col) {
                    this.sortDir = this.sortDir === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    this.sortBy = col;
                    this.sortDir = 'ASC';
                }
                this.loadProductos();
            },
            sortIcon(col) {
                if (this.sortBy !== col) return 'fa-sort text-gray-300 dark:text-gray-600';
                return this.sortDir === 'ASC' ? 'fa-sort-up text-cyan-500' : 'fa-sort-down text-cyan-500';
            },
            
            // ========== FORMULARIO ==========
            resetForm() {
                this.form = {
                    idproducto: null,
                    cve_producto: '',
                    desproducto: '',
                    referencia: 0,
                    grupo: 0,
                    marca: 0,
                    modelo: 0,
                    color: 0,
                    unidad_medida: '',
                    equivalencia: '',
                    iva: '1',
                    impuesto: 10,
                    codigo_barra: '',
                    precio_compra: 0,
                    precio_venta: 0,
                    stock_minimo: 1,
                    stock_maximo: 5,
                    saldo_actual: 0,
                    stock_inicial: 0,
                    id_sucursal: '',
                    controla_stock: 1,
                    edita_precio: 1,
                    vende_sin_stock: -1,
                    usaserial: -1,
                    descripcion_larga: '',
                    ncm: '',
                    origen: '',
                    peso: 0,
                    ancho: 0,
                    alto: 0,
                    largo: 0,
                    foto_url: '',
                    catalogo_url: '',
                    obs: '',
                    publicar_web: 0,
                    aplicaciones_modo: 'agrupado',
                    precios: [],
                    codigos_barra_extra: [],
                    equivalentes: [],
                    aplicaciones: [],
                };
                this.stockSucursales = [];
                this.imgList = [];
                this.imgError = '';
                if (Array.isArray(this.pendingImages)) {
                    this.pendingImages.forEach((it) => {
                        if (it?.preview && String(it.preview).startsWith('blob:')) {
                            try { URL.revokeObjectURL(it.preview); } catch (_) {}
                        }
                    });
                }
                this.pendingImages = [];
                this.formTab = 'general';
            },
            
            nuevoProducto() {
                this.resetForm();
                this.checkDriveConfig();
                this.loadCatalogos();
                this.showForm = true;
            },

            closeForm() {
                this.closeProductCamera();
                this.showForm = false;
            },
            
            async editarProducto(id) {
                this.resetForm();
                this.formTab = 'general';
                this.showForm = true;
                this.checkDriveConfig();
                await this.loadCatalogos();
                
                try {
                    const res = await fetch(`${this.API}/detalle.php?action=detalle&id=${id}&id_empresa=${this.idEmpresa}`);
                    const data = await res.json();
                    
                    if (data.success && data.data) {
                        this.ensureTiposPrecioOptions(data.data.precios || []);
                        const p = data.data.producto;
                        const codigosDb = (data.data.codigos_barra || [])
                            .map(cb => String(cb.codigo_barra || '').trim())
                            .filter(Boolean);
                        const codigoPrincipal = String(p.codigo_barra || codigosDb[0] || '').trim();
                        const codigosExtra = codigosDb
                            .filter(cb => cb !== codigoPrincipal)
                            .map(cb => ({ codigo: cb }));

                        this.form = {
                            idproducto: p.idproducto,
                            cve_producto: p.cve_producto || '',
                            desproducto: p.desproducto || '',
                            referencia: (/^\d+$/.test(String(p.referencia || '')) ? parseInt(p.referencia, 10) : 0),
                            grupo: p.grupo || 0,
                            marca: p.marca || 0,
                            modelo: p.modelo || 0,
                            color: p.color || 0,
                            unidad_medida: p.unidad_medida || '',
                            equivalencia: p.equivalencia || '',
                            iva: String(p.iva || 1),
                            impuesto: p.impuesto || 10,
                            codigo_barra: codigoPrincipal,
                            precio_compra: parseFloat(p.precio_compra) || 0,
                            precio_venta: parseFloat(p.precio_venta) || 0,
                            stock_minimo: parseFloat(p.stock_minimo) || 0,
                            stock_maximo: parseFloat(p.stock_maximo) || 0,
                            saldo_actual: parseFloat(p.saldo) || 0,
                            stock_inicial: 0,
                            id_sucursal: '',
                            controla_stock: parseInt(p.controla_stock) || -1,
                            edita_precio: parseInt(p.edita_precio) || 1,
                            vende_sin_stock: parseInt(p.vende_sin_stock) || -1,
                            usaserial: parseInt(p.usaserial) || -1,
                            descripcion_larga: p.descripcion_larga || '',
                            ncm: p.ncm || '',
                            origen: p.origen || '',
                            peso: parseFloat(p.peso) || 0,
                            ancho: parseFloat(p.ancho) || 0,
                            alto: parseFloat(p.alto) || 0,
                            largo: parseFloat(p.largo) || 0,
                            foto_url: p.foto_url || '',
                            catalogo_url: '',
                            obs: p.obs || '',
                            publicar_web: parseInt(p.publicar_web) || 0,
                            aplicaciones_modo: 'agrupado',
                            precios: (data.data.precios || []).map(pr => ({
                                tipo: String(pr?.tipo ?? ''),
                                tipo_nombre: String(pr?.tipo_nombre || '').trim(),
                                costo: parseFloat(pr.costo) || 0,
                                porcentaje: parseFloat(pr.porcentaje) || 0,
                                precio: parseFloat(pr.precio) || 0,
                                moneda: pr.moneda || 'PYG',
                            })),
                            codigos_barra_extra: codigosExtra,
                            equivalentes: this.normalizeEquivalencias(((data.data.equivalentes || []).length ? data.data.equivalentes : (data.data.aplicaciones || [])).filter(aplic =>
                                String(aplic?.marca_cod_conversion || '').trim() !== '' ||
                                String(aplic?.conversion || '').trim() !== ''
                            )),
                            aplicaciones: this.normalizeAplicaciones(((data.data.aplicaciones_detalle || []).length ? data.data.aplicaciones_detalle : (data.data.aplicaciones || [])).filter(aplic =>
                                String(aplic?.marca_aplicacion || '').trim() !== '' ||
                                String(aplic?.vehiculo_marca || '').trim() !== '' ||
                                String(aplic?.vehiculo_modelo || '').trim() !== '' ||
                                String(aplic?.anio || '').trim() !== '' ||
                                String(aplic?.motor || '').trim() !== '' ||
                                String(aplic?.codigo_motor || '').trim() !== ''
                            )),
                        };
                        this.syncPreciosTipos();
                        this.stockSucursales = data.data.stock_sucursal || [];
                        await this.loadImgList();
                    }
                } catch (error) {
                    this.showToast('Error al cargar producto', 'error');
                }
            },
            
            async guardarProducto() {
                if (!this.form.desproducto?.trim()) {
                    this.showToast('La descripción es obligatoria', 'error');
                    this.formTab = 'general';
                    return;
                }
                
                this.saving = true;
                try {
                    // Mapear IVA a impuesto
                    const ivaMap = { 1: 10, 2: 5, 3: 0 };
                    this.form.impuesto = ivaMap[this.form.iva] ?? 10;
                    
                    // Merge codigos de barra
                    const codigosBarra = [];
                    const principal = String(this.form.codigo_barra || '').trim();
                    if (principal) codigosBarra.push(principal);
                    this.form.codigos_barra_extra.forEach(cb => {
                        const codigo = String(cb.codigo || '').trim();
                        if (codigo) codigosBarra.push(codigo);
                    });
                    const codigosBarraUnicos = [...new Set(codigosBarra)];
                    this.form.codigo_barra = codigosBarraUnicos[0] || '';
                    
                    const payload = {
                        action: this.form.idproducto ? 'update' : 'create',
                        ...this.form,
                        id_empresa: this.idEmpresa,
                        precios: this.buildPreciosPayload(),
                        codigos_barra: codigosBarraUnicos,
                        equivalentes: this.normalizeEquivalencias(this.form.equivalentes),
                        aplicaciones: this.buildAplicacionesPayload(),
                    };
                    if (!this.form.idproducto && (parseFloat(this.form.stock_inicial) || 0) > 0) {
                        payload.stock_inicial = parseFloat(this.form.stock_inicial) || 0;
                        payload.id_sucursal_stock = parseInt(this.form.id_sucursal || 0) || 1;
                    }
                    
                    const res = await fetch(`${this.API}/guardar.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    
                    if (data.success) {
                        const savedId = Number(data.idproducto || this.form.idproducto || 0);
                        if (savedId > 0 && Array.isArray(this.pendingImages) && this.pendingImages.length > 0) {
                            const pendings = [...this.pendingImages];
                            let okCount = 0;
                            for (const item of pendings) {
                                let upOk = false;
                                if ((item?.type || 'file') === 'url') {
                                    upOk = await this.uploadImageUrl(savedId, item?.url || '');
                                } else {
                                    upOk = await this.uploadImageFile(savedId, item?.file || null);
                                }
                                if (upOk) okCount++;
                            }
                            if (okCount > 0) {
                                pendings.forEach((it) => {
                                    if (it?.preview && String(it.preview).startsWith('blob:')) {
                                        try { URL.revokeObjectURL(it.preview); } catch (_) {}
                                    }
                                });
                                this.pendingImages = [];
                                await this.loadImgList();
                            }
                        }
                        this.showToast(data.message || 'Producto guardado correctamente', 'success');
                        this.showForm = false;
                        this.loadProductos();
                        this.loadCatalogos();
                    } else {
                        this.showToast(data.error || 'Error al guardar', 'error');
                    }
                } catch (error) {
                    this.showToast('Error de conexión', 'error');
                }
                this.saving = false;
            },

            buildProductoPayload() {
                const codigosBarra = [];
                const principal = String(this.form.codigo_barra || '').trim();
                if (principal) codigosBarra.push(principal);
                this.form.codigos_barra_extra.forEach(cb => {
                    const codigo = String(cb.codigo || '').trim();
                    if (codigo) codigosBarra.push(codigo);
                });
                const codigosBarraUnicos = [...new Set(codigosBarra)];
                this.form.codigo_barra = codigosBarraUnicos[0] || '';

                const ivaMap = { 1: 10, 2: 5, 3: 0 };
                this.form.impuesto = ivaMap[this.form.iva] ?? 10;

                const payload = {
                    action: this.form.idproducto ? 'update' : 'create',
                    ...this.form,
                    id_empresa: this.idEmpresa,
                    codigos_barra: codigosBarraUnicos,
                    equivalentes: this.normalizeEquivalencias(this.form.equivalentes),
                    aplicaciones: this.buildAplicacionesPayload(),
                };

                if (!this.form.idproducto && (parseFloat(this.form.stock_inicial) || 0) > 0) {
                    payload.stock_inicial = parseFloat(this.form.stock_inicial) || 0;
                    payload.id_sucursal_stock = parseInt(this.form.id_sucursal || 0) || 1;
                }
                return payload;
            },

            async saveAplicacionesInline() {
                if (!this.form.desproducto?.trim()) {
                    this.showToast('Guardá al menos la descripción del producto antes de persistir aplicaciones', 'error');
                    this.formTab = 'general';
                    return;
                }

                this.saving = true;
                const prevTab = this.formTab;
                try {
                    const payload = this.buildProductoPayload();
                    const res = await fetch(`${this.API}/guardar.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();

                    if (!data.success) {
                        this.showToast(data.error || 'No se pudieron guardar las aplicaciones', 'error');
                        return;
                    }

                    const savedId = Number(data.idproducto || this.form.idproducto || 0);
                    if (!this.form.idproducto && savedId > 0) {
                        await this.editarProducto(savedId);
                        this.formTab = prevTab;
                    } else if (savedId > 0) {
                        this.form.idproducto = savedId;
                    }
                    this.showToast(data.message || 'Aplicaciones guardadas correctamente', 'success');
                    this.loadProductos();
                } catch (_) {
                    this.showToast('Error de conexión', 'error');
                }
                this.saving = false;
            },

            async onSelectProductImage(event, source = 'galeria') {
                const files = Array.from(event?.target?.files || []);
                if (event?.target) event.target.value = '';
                if (!files.length) return;
                if (!this.imgDriveConfigured) {
                    this.showToast('R2 no está configurado', 'error');
                    return;
                }
                if (Number(this.form.idproducto || 0) > 0) {
                    for (const file of files) {
                        await this.uploadImageFile(Number(this.form.idproducto), file);
                    }
                    await this.loadImgList();
                    return;
                }
                for (const file of files) {
                    const preview = URL.createObjectURL(file);
                    this.pendingImages.push({
                        type: 'file',
                        source,
                        file,
                        preview,
                        name: file.name || 'imagen',
                        size: Number(file.size || 0),
                    });
                }
                this.showToast('Foto(s) agregada(s), se subirán al guardar', 'success');
            },

            async openProductCamera() {
                if (!this.imgDriveConfigured) {
                    this.showToast('R2 no está configurado', 'error');
                    return;
                }
                this.productCameraError = '';
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    const fallback = document.getElementById('desktopProductImageCamera');
                    if (fallback) fallback.click();
                    return;
                }
                try {
                    this.closeProductCamera();
                    const stream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: { ideal: 'environment' },
                            width: { ideal: 1920 },
                            height: { ideal: 1080 },
                        },
                        audio: false
                    });
                    this.productCameraStream = stream;
                    this.showProductCameraModal = true;
                    await this.$nextTick();
                    const video = this.$refs.desktopProductCameraVideo;
                    if (!video) throw new Error('Video no disponible');
                    video.srcObject = stream;
                    await video.play();
                } catch (e) {
                    this.productCameraError = e?.message || 'No se pudo abrir la cámara';
                    const fallback = document.getElementById('desktopProductImageCamera');
                    if (fallback) fallback.click();
                }
            },

            closeProductCamera() {
                try {
                    if (this.productCameraStream) {
                        this.productCameraStream.getTracks().forEach(t => t.stop());
                    }
                    const video = this.$refs.desktopProductCameraVideo;
                    if (video) video.srcObject = null;
                } catch (_) {}
                this.productCameraStream = null;
                this.showProductCameraModal = false;
            },

            async captureProductCameraPhoto() {
                const video = this.$refs.desktopProductCameraVideo;
                if (!video || video.readyState < 2) {
                    this.showToast('La cámara aún no está lista', 'error');
                    return;
                }
                const w = video.videoWidth || 0;
                const h = video.videoHeight || 0;
                if (w <= 0 || h <= 0) {
                    this.showToast('No se pudo capturar la imagen', 'error');
                    return;
                }
                const canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    this.showToast('No se pudo procesar la imagen', 'error');
                    return;
                }
                ctx.drawImage(video, 0, 0, w, h);

                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
                if (!blob) {
                    this.showToast('No se pudo generar la foto', 'error');
                    return;
                }
                const file = new File([blob], `camera_${Date.now()}.jpg`, { type: 'image/jpeg' });
                const idProducto = Number(this.form.idproducto || 0);

                if (idProducto > 0) {
                    const ok = await this.uploadImageFile(idProducto, file);
                    if (ok) await this.loadImgList();
                } else {
                    const preview = URL.createObjectURL(file);
                    this.pendingImages.push({
                        type: 'file',
                        source: 'camera',
                        file,
                        preview,
                        name: file.name,
                        size: Number(file.size || 0),
                    });
                    this.showToast('Foto capturada, se subirá al guardar', 'success');
                }
                this.closeProductCamera();
            },

            async addImageFromProvider(provider = 'google') {
                const providerKey = String(provider || 'google').toLowerCase();
                const providers = {
                    google: {
                        label: 'Google',
                        buildUrl: (q) => `https://www.google.com/search?tbm=isch&q=${q}`,
                    },
                    bing: {
                        label: 'Bing',
                        buildUrl: (q) => `https://www.bing.com/images/search?q=${q}`,
                    },
                    pexels: {
                        label: 'Pexels',
                        buildUrl: (q) => `https://www.pexels.com/search/${q}/`,
                    },
                    pixabay: {
                        label: 'Pixabay',
                        buildUrl: (q) => `https://pixabay.com/images/search/${q}/`,
                    },
                    amazon: {
                        label: 'Amazon',
                        buildUrl: (q) => `https://www.amazon.com.br/s?k=${q}`,
                    },
                    mercadolibre: {
                        label: 'Mercado Libre',
                        buildUrl: (q) => `https://listado.mercadolibre.com.py/${q}`,
                    },
                    shopee: {
                        label: 'Shopee',
                        buildUrl: (q) => `https://shopee.com.br/search?keyword=${q}`,
                    },
                    autodoc: {
                        label: 'AUTODOC',
                        buildUrl: (q) => `https://www.google.com/search?tbm=isch&q=site%3Aautodoc.+${q}`,
                    },
                };
                const providerCfg = providers[providerKey] || providers.google;
                const searchSeed = this.buildCatalogSearchSeed();
                const q = encodeURIComponent(searchSeed);
                const popup = window.open(providerCfg.buildUrl(q), '_blank', 'noopener,noreferrer');
                if (!popup) {
                    this.showToast(`No se pudo abrir ${providerCfg.label}`, 'error');
                }
                const imageUrl = (window.prompt(`${providerCfg.label} se abrió con el nombre del producto. Pegá aquí la URL de la imagen elegida:`) || '').trim();
                if (!imageUrl) return;
                if (!this.imgDriveConfigured) {
                    this.showToast('R2 no está configurado para guardar la imagen', 'error');
                    return;
                }
                if (!/^https?:\/\//i.test(imageUrl)) {
                    this.showToast('URL inválida', 'error');
                    return;
                }

                const idProducto = Number(this.form.idproducto || 0);
                if (idProducto > 0) {
                    const ok = await this.uploadImageUrl(idProducto, imageUrl);
                    if (ok) await this.loadImgList();
                    return;
                }

                this.pendingImages.push({
                    type: 'url',
                    source: providerCfg.label.toLowerCase(),
                    url: imageUrl,
                    preview: imageUrl,
                    name: `${providerCfg.label} Image`,
                    size: 0,
                });
                this.showToast('Imagen URL agregada, se subirá al guardar', 'success');
            },

            buildCatalogSearchSeed() {
                return String(this.form.desproducto || this.form.cve_producto || 'autopartes').trim() || 'autopartes';
            },

            openCatalogoUrl() {
                const raw = String(this.form.catalogo_url || '').trim();
                if (!raw) {
                    this.showToast('Ingresá una URL de catálogo', 'error');
                    return;
                }
                const url = /^https?:\/\//i.test(raw) ? raw : `https://${raw}`;
                try {
                    const parsed = new URL(url);
                    const popup = window.open(parsed.toString(), '_blank', 'noopener,noreferrer');
                    if (!popup) {
                        this.showToast('No se pudo abrir la URL', 'error');
                    }
                } catch (_) {
                    this.showToast('URL inválida', 'error');
                }
            },

            async onPasteProductImage(event) {
                if (!this.showForm || !this.imgDriveConfigured) return;
                const clip = event?.clipboardData;
                if (!clip) return;

                const items = Array.from(clip.items || []);
                if (!items.length) return;

                const imageFiles = [];
                for (const item of items) {
                    if (item.kind === 'file' && String(item.type || '').startsWith('image/')) {
                        const f = item.getAsFile();
                        if (f) imageFiles.push(f);
                    }
                }

                if (imageFiles.length > 0) {
                    event.preventDefault();
                    const idProducto = Number(this.form.idproducto || 0);
                    if (idProducto > 0) {
                        for (const file of imageFiles) {
                            await this.uploadImageFile(idProducto, file);
                        }
                        await this.loadImgList();
                    } else {
                        for (const file of imageFiles) {
                            const preview = URL.createObjectURL(file);
                            this.pendingImages.push({
                                type: 'file',
                                source: 'paste',
                                file,
                                preview,
                                name: file.name || 'pegado',
                                size: Number(file.size || 0),
                            });
                        }
                        this.showToast('Imagen pegada, se subirá al guardar', 'success');
                    }
                    return;
                }

                const txt = (clip.getData('text/plain') || '').trim();
                if (/^https?:\/\//i.test(txt)) {
                    event.preventDefault();
                    const idProducto = Number(this.form.idproducto || 0);
                    if (idProducto > 0) {
                        const ok = await this.uploadImageUrl(idProducto, txt);
                        if (ok) await this.loadImgList();
                    } else {
                        this.pendingImages.push({
                            type: 'url',
                            source: 'paste-url',
                            url: txt,
                            preview: txt,
                            name: 'URL pegada',
                            size: 0,
                        });
                        this.showToast('URL de imagen pegada, se subirá al guardar', 'success');
                    }
                }
            },

            removePendingImage(idx) {
                const i = Number(idx);
                if (!Number.isInteger(i) || i < 0 || i >= this.pendingImages.length) return;
                const item = this.pendingImages[i];
                if (item?.preview && String(item.preview).startsWith('blob:')) {
                    try { URL.revokeObjectURL(item.preview); } catch (_) {}
                }
                this.pendingImages.splice(i, 1);
            },

            async uploadImageFile(idProducto, file) {
                if (!idProducto || !file) return false;
                this.imgUploading = true;
                this.imgError = '';
                try {
                    const formData = new FormData();
                    formData.append('action', 'upload');
                    formData.append('idproducto', String(idProducto));
                    formData.append('id_empresa', String(this.idEmpresa));
                    formData.append('imagen', file, file.name || `producto_${idProducto}.jpg`);
                    const res = await fetch(`${this.API}/imagen.php`, { method: 'POST', body: formData });
                    const data = await res.json();
                    if (!data.success) {
                        this.imgError = data.error || 'No se pudo subir imagen';
                        this.showToast(this.imgError, 'error');
                        return false;
                    }
                    const d = data.data || {};
                    this.form.foto_url = d.small_url || d.medium_url || d.url || this.form.foto_url;
                    return true;
                } catch (_) {
                    this.imgError = 'Error de conexión al subir imagen';
                    this.showToast(this.imgError, 'error');
                    return false;
                } finally {
                    this.imgUploading = false;
                }
            },

            async uploadImageUrl(idProducto, imageUrl) {
                if (!idProducto || !imageUrl) return false;
                this.imgUploading = true;
                this.imgError = '';
                try {
                    const res = await fetch(`${this.API}/imagen.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'upload_url',
                            idproducto: Number(idProducto),
                            id_empresa: this.idEmpresa,
                            image_url: String(imageUrl || '').trim(),
                        })
                    });
                    const data = await res.json();
                    if (!data.success) {
                        this.imgError = data.error || 'No se pudo subir imagen por URL';
                        this.showToast(this.imgError, 'error');
                        return false;
                    }
                    const d = data.data || {};
                    this.form.foto_url = d.small_url || d.medium_url || d.url || this.form.foto_url;
                    return true;
                } catch (_) {
                    this.imgError = 'Error de conexión al subir imagen por URL';
                    this.showToast(this.imgError, 'error');
                    return false;
                } finally {
                    this.imgUploading = false;
                }
            },

            async loadImgList() {
                const idProducto = Number(this.form.idproducto || 0);
                if (!idProducto || !this.imgDriveConfigured) {
                    this.imgList = [];
                    return;
                }
                try {
                    const res = await fetch(`${this.API}/imagen.php?action=list&idproducto=${idProducto}&id_empresa=${this.idEmpresa}`);
                    const data = await res.json();
                    this.imgList = data.success ? (data.data || []) : [];
                    if (this.imgList.length > 0) {
                        const p = this.imgList.find(i => Number(i.principal) === 1) || this.imgList[0];
                        this.form.foto_url = p?.small_url || p?.medium_url || p?.url || this.form.foto_url;
                    }
                } catch (_) {
                    this.imgList = [];
                }
            },

            getImgFileId(img) {
                return String(img?.file_id || img?.drive_file_id || '').trim();
            },

            async setImgPrincipal(img) {
                const idProducto = Number(this.form.idproducto || 0);
                const fileId = this.getImgFileId(img);
                if (!idProducto || !fileId) return;
                try {
                    const res = await fetch(`${this.API}/imagen.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'set_principal',
                            idproducto: idProducto,
                            id_empresa: this.idEmpresa,
                            file_id: fileId
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.loadImgList();
                        this.showToast('Imagen principal actualizada', 'success');
                    } else {
                        this.showToast(data.error || 'No se pudo actualizar principal', 'error');
                    }
                } catch (_) {
                    this.showToast('Error de conexión', 'error');
                }
            },

            async eliminarImg(img) {
                const idProducto = Number(this.form.idproducto || 0);
                const fileId = this.getImgFileId(img);
                if (!idProducto || !fileId) return;
                if (!confirm('¿Eliminar esta imagen?')) return;
                try {
                    const res = await fetch(`${this.API}/imagen.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'delete',
                            idproducto: idProducto,
                            id_empresa: this.idEmpresa,
                            file_id: fileId
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.loadImgList();
                        this.showToast('Imagen eliminada', 'success');
                    } else {
                        this.showToast(data.error || 'No se pudo eliminar imagen', 'error');
                    }
                } catch (_) {
                    this.showToast('Error de conexión', 'error');
                }
            },
            
            agregarPrecio() {
                this.form.precios.push({ tipo: '', tipo_nombre: '', costo: this.form.precio_compra || 0, porcentaje: 0, precio: 0, moneda: 'PYG' });
            },
            
            agregarCodigoBarra() {
                this.form.codigos_barra_extra.push({ codigo: '' });
            },

            newAplicacionGroupToken() {
                return `appgrp_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
            },

            agregarAplicacion() {
                const groupToken = this.newAplicacionGroupToken();
                this.form.aplicaciones.push({
                    conversion: '',
                    marca_aplicacion: '',
                    vehiculo_marca: '',
                    vehiculo_modelo: '',
                    anio: '',
                    motor: '',
                    codigo_motor: '',
                    __is_app_group: true,
                    __group_token: groupToken,
                });
                this.form.aplicaciones.push({
                    marca_aplicacion: '',
                    vehiculo_marca: '',
                    vehiculo_modelo: '',
                    anio: '',
                    motor: '',
                    codigo_motor: '',
                    __is_new_app: true,
                    __is_app_group: false,
                    __group_token: groupToken,
                });
            },

            agregarCodigoConversion() {
                this.form.equivalentes.push({
                    marca_cod_conversion: '',
                    conversion: '',
                });
            },

            agregarCodigoConversionEnGrupo(group) {
                this.form.equivalentes.push({
                    marca_cod_conversion: String(group?.marca_cod_conversion || '').trim(),
                    conversion: '',
                });
            },

            eliminarEquivalenciaFila(idx) {
                const pos = Number(idx);
                if (!Number.isInteger(pos) || pos < 0 || pos >= this.form.equivalentes.length) return;
                this.form.equivalentes.splice(pos, 1);
            },

            eliminarEquivalenciaGrupo(group) {
                const indexes = Array.isArray(group?.indexes) ? group.indexes
                    .map((idx) => Number(idx))
                    .filter((idx) => Number.isInteger(idx) && idx >= 0 && idx < this.form.equivalentes.length)
                    .sort((a, b) => b - a) : [];
                indexes.forEach((idx) => {
                    this.form.equivalentes.splice(idx, 1);
                });
            },

            eliminarAplicacionFila(idx) {
                const pos = Number(idx);
                if (!Number.isInteger(pos) || pos < 0 || pos >= this.form.aplicaciones.length) return;
                this.form.aplicaciones.splice(pos, 1);
            },

            eliminarAplicacionGrupo(group) {
                const indexes = Array.isArray(group?.indexes) ? group.indexes
                    .map((idx) => Number(idx))
                    .filter((idx) => Number.isInteger(idx) && idx >= 0 && idx < this.form.aplicaciones.length)
                    .sort((a, b) => b - a) : [];
                indexes.forEach((idx) => {
                    this.form.aplicaciones.splice(idx, 1);
                });
            },

            aplicacionTieneDetalle(row) {
                if (row?.__is_app_group) return false;
                if (row?.__is_new_app) return true;
                return [
                    row?.vehiculo_marca,
                    row?.vehiculo_modelo,
                    row?.anio,
                    row?.motor,
                    row?.codigo_motor
                ].some((value) => String(value || '').trim() !== '');
            },

            normalizeEquivalencias(rows) {
                return (Array.isArray(rows) ? rows : [])
                    .map((row, idx) => ({
                        marca_cod_conversion: String(row?.marca_cod_conversion || '').trim(),
                        conversion: String(row?.conversion || '').trim(),
                        orden: idx + 1,
                    }))
                    .filter(row =>
                        row.marca_cod_conversion !== '' ||
                        row.conversion !== ''
                    );
            },

            normalizeAplicaciones(rows) {
                return (Array.isArray(rows) ? rows : [])
                    .map((row, idx) => ({
                        marca_aplicacion: String(row?.marca_aplicacion || '').trim(),
                        vehiculo_marca: String(row?.vehiculo_marca || '').trim(),
                        vehiculo_modelo: String(row?.vehiculo_modelo || '').trim(),
                        anio: String(row?.anio || '').trim(),
                        motor: String(row?.motor || '').trim(),
                        codigo_motor: String(row?.codigo_motor || '').trim(),
                        orden: idx + 1,
                    }))
                    .filter(row =>
                        row.marca_aplicacion !== '' ||
                        row.vehiculo_marca !== '' ||
                        row.vehiculo_modelo !== '' ||
                        row.anio !== '' ||
                        row.motor !== '' ||
                        row.codigo_motor !== ''
                    );
            },

            precioTipoNombre(tipoId) {
                const key = String(tipoId || '').trim();
                if (!key) return '';
                const found = (Array.isArray(this.catTiposPrecio) ? this.catTiposPrecio : []).find((tp) => String(tp?.id ?? '').trim() === key);
                return String(found?.tipo || '').trim();
            },

            findTipoPrecioByName(nombre) {
                const needle = String(nombre || '').trim().toLowerCase();
                if (!needle) return null;
                return (Array.isArray(this.catTiposPrecio) ? this.catTiposPrecio : []).find((tp) => String(tp?.tipo || '').trim().toLowerCase() === needle) || null;
            },

            syncPrecioTipo(precio) {
                if (!precio || typeof precio !== 'object') return;
                const tipoId = String(precio?.tipo || '').trim();
                const tipoNombre = String(precio?.tipo_nombre || '').trim();
                if (tipoNombre) {
                    const found = this.findTipoPrecioByName(tipoNombre);
                    if (found) {
                        precio.tipo = String(found.id ?? '').trim();
                        precio.tipo_nombre = String(found.tipo || tipoNombre).trim();
                        if (!String(precio.moneda || '').trim()) {
                            precio.moneda = String(found.moneda || 'PYG').trim() || 'PYG';
                        }
                        return;
                    }
                }
                if (tipoId) {
                    const label = this.precioTipoNombre(tipoId);
                    if (label) {
                        precio.tipo_nombre = label;
                        return;
                    }
                }
                if (!tipoNombre) {
                    precio.tipo = '';
                }
            },

            syncPreciosTipos() {
                (Array.isArray(this.form?.precios) ? this.form.precios : []).forEach((precio) => this.syncPrecioTipo(precio));
            },

            buildPreciosPayload() {
                const out = [];
                (Array.isArray(this.form?.precios) ? this.form.precios : []).forEach((precio) => {
                    this.syncPrecioTipo(precio);
                    const tipo = parseInt(precio?.tipo || 0, 10) || 0;
                    if (tipo <= 0) return;
                    out.push({
                        tipo,
                        tipo_nombre: String(precio?.tipo_nombre || '').trim(),
                        costo: parseFloat(precio?.costo) || 0,
                        porcentaje: parseFloat(precio?.porcentaje) || 0,
                        precio: parseFloat(precio?.precio) || 0,
                        moneda: String(precio?.moneda || 'PYG').trim() || 'PYG',
                    });
                });
                return out;
            },

            sucursalActiva(sucursal) {
                if (!sucursal || typeof sucursal !== 'object') return true;
                if (Object.prototype.hasOwnProperty.call(sucursal, 'activo')) {
                    return Number(sucursal.activo) === 1;
                }
                if (Object.prototype.hasOwnProperty.call(sucursal, 'estado')) {
                    return Number(sucursal.estado) === 1;
                }
                return true;
            },

            get stockSucursalesActivas() {
                const stockMap = new Map();
                (Array.isArray(this.stockSucursales) ? this.stockSucursales : []).forEach((item) => {
                    const id = String(item?.id_sucursal ?? '').trim();
                    if (!id) return;
                    stockMap.set(id, {
                        id_sucursal: item.id_sucursal,
                        sucursal: String(item?.sucursal || `Sucursal ${id}`).trim(),
                        stock: Number(item?.stock || 0),
                    });
                });

                const activas = [];
                (Array.isArray(this.sucursalesList) ? this.sucursalesList : []).forEach((sucursal) => {
                    if (!this.sucursalActiva(sucursal)) return;
                    const id = String(sucursal?.id_sucursal ?? '').trim();
                    if (!id) return;
                    const current = stockMap.get(id);
                    activas.push(current || {
                        id_sucursal: sucursal.id_sucursal,
                        sucursal: String(sucursal?.sucursal || `Sucursal ${id}`).trim(),
                        stock: 0,
                    });
                    stockMap.delete(id);
                });

                stockMap.forEach((item) => {
                    activas.push(item);
                });

                activas.sort((a, b) => Number(a?.id_sucursal || 0) - Number(b?.id_sucursal || 0));
                return activas;
            },

            get equivalenciasAgrupadas() {
                const groups = new Map();
                (Array.isArray(this.form.equivalentes) ? this.form.equivalentes : []).forEach((row, idx) => {
                    const marcaCod = String(row?.marca_cod_conversion || '').trim();
                    const key = marcaCod || `eq-${idx}`;
                    if (!groups.has(key)) {
                        groups.set(key, {
                            key,
                            marca_cod_conversion: marcaCod,
                            indexes: [],
                            conversionRows: [],
                        });
                    }
                    const group = groups.get(key);
                    group.indexes.push(idx);
                    group.conversionRows.push({ ...row, _idx: idx });
                });
                return Array.from(groups.values());
            },

            get aplicacionesAgrupadas() {
                const groups = new Map();
                (Array.isArray(this.form.aplicaciones) ? this.form.aplicaciones : []).forEach((row, idx) => {
                    const marcaAplic = String(row?.marca_aplicacion || row?.vehiculo_marca || '').trim();
                    const groupToken = String(row?.__group_token || '').trim();
                    const key = `${marcaAplic}||${groupToken}`;
                    if (!groups.has(key)) {
                        groups.set(key, {
                            key,
                            group_token: groupToken,
                            marca_aplicacion: marcaAplic,
                            indexes: [],
                            rows: [],
                            appRows: [],
                            hasAppGroupHeader: false,
                            showAppGroup: false,
                        });
                    }
                    const group = groups.get(key);
                    group.indexes.push(idx);
                    group.rows.push({ ...row, _idx: idx });
                    if (row?.__is_app_group) {
                        group.hasAppGroupHeader = true;
                    } else if (this.aplicacionTieneDetalle(row)) {
                        group.appRows.push({ ...row, _idx: idx });
                    }
                });
                groups.forEach((group) => {
                    const resumen = group.appRows
                        .map((row) => {
                            const parts = [
                                String(row.vehiculo_marca || '').trim(),
                                String(row.vehiculo_modelo || '').trim(),
                                String(row.anio || '').trim(),
                                String(row.motor || '').trim(),
                                String(row.codigo_motor || '').trim(),
                            ].filter(Boolean);
                            return parts.join(' / ');
                        })
                        .filter(Boolean);
                    group.detalle_resumen = resumen.join(' | ');
                    group.showAppGroup = group.hasAppGroupHeader || group.appRows.length > 0;
                });
                return Array.from(groups.values());
            },

            setEquivalenciaGroupField(group, field, value) {
                const val = String(value || '');
                (Array.isArray(group?.indexes) ? group.indexes : []).forEach((idx) => {
                    if (this.form.equivalentes[idx]) {
                        this.form.equivalentes[idx][field] = val;
                    }
                });
            },

            setAplicacionGroupField(group, field, value) {
                const val = String(value || '');
                (Array.isArray(group?.indexes) ? group.indexes : []).forEach((idx) => {
                    if (this.form.aplicaciones[idx]) {
                        this.form.aplicaciones[idx][field] = val;
                        if (field === 'marca_aplicacion' && !String(this.form.aplicaciones[idx].vehiculo_marca || '').trim()) {
                            this.form.aplicaciones[idx].vehiculo_marca = val;
                        }
                    }
                });
            },

            getAplicacionGroupRows(group) {
                const indexes = Array.isArray(group?.indexes) ? group.indexes : [];
                return indexes
                    .map((idx) => Number(idx))
                    .filter((idx) => Number.isInteger(idx) && idx >= 0 && idx < this.form.aplicaciones.length)
                    .map((idx) => ({ ...(this.form.aplicaciones[idx] || {}), _idx: idx }))
                    .filter((row) => !row?.__is_app_group && this.aplicacionTieneDetalle(row));
            },

            get aplicacionesRenderRows() {
                const rows = [];
                this.aplicacionesAgrupadas.forEach((group) => {
                    rows.push({ key: `group-${group.key}`, type: 'group', group });
                    this.getAplicacionGroupRows(group).forEach((row) => {
                        rows.push({ key: `item-${group.key}-${row._idx}`, type: 'item', group, row });
                    });
                });
                return rows;
            },

            agregarAplicacionEnGrupo(group) {
                const nextOrden = (Array.isArray(this.form.aplicaciones) ? this.form.aplicaciones.length : 0) + 1;
                const insertAt = Array.isArray(group?.indexes) && group.indexes.length > 0
                    ? (Math.max(...group.indexes.map((idx) => Number(idx)).filter((idx) => Number.isInteger(idx))) + 1)
                    : this.form.aplicaciones.length;
                this.form.aplicaciones.splice(insertAt, 0, {
                    marca_aplicacion: String(group?.marca_aplicacion || '').trim(),
                    vehiculo_marca: '',
                    vehiculo_modelo: '',
                    anio: '',
                    motor: '',
                    codigo_motor: '',
                    orden: nextOrden,
                    __is_new_app: true,
                    __is_app_group: false,
                    __group_token: String(group?.group_token || ''),
                });
            },

            buildAplicacionesPayload() {
                return this.normalizeAplicaciones(this.form.aplicaciones).map((row, idx) => ({
                    marca_aplicacion: row.marca_aplicacion,
                    vehiculo_marca: row.vehiculo_marca,
                    vehiculo_modelo: row.vehiculo_modelo,
                    anio: row.anio,
                    motor: row.motor,
                    codigo_motor: row.codigo_motor,
                    orden: idx + 1,
                }));
            },

            getCatalogoOptions(tabla) {
                return this.getCatalogoArray(tabla);
            },

            async ensureCatalogValue(tabla, value) {
                const nombre = String(value || '').trim();
                if (!nombre) return;
                const current = this.getCatalogoArray(tabla);
                const exists = (Array.isArray(current) ? current : []).some((item) => String(item?.nombre || '').trim().toLowerCase() === nombre.toLowerCase());
                if (exists) return;
                try {
                    const res = await fetch(`${this.API}/catalogos.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'create',
                            tabla,
                            nombre,
                            id_empresa: this.idEmpresa
                        })
                    });
                    const data = await res.json();
                    if (data?.success) {
                        await this.loadCatalogo(tabla);
                    }
                } catch (_) {}
            },
            
            duplicarProducto(p) {
                this.resetForm();
                this.form.desproducto = p.desproducto + ' (copia)';
                this.form.precio_compra = parseFloat(p.precio_compra) || 0;
                this.form.precio_venta = parseFloat(p.precio_venta) || 0;
                this.form.grupo = p.grupo || 0;
                this.form.marca = p.marca || 0;
                this.form.iva = String(p.iva || 1);
                this.showForm = true;
            },
            
            async verDetalle(id) {
                await this.editarProducto(id);
            },
            
            // ========== ELIMINAR / ACTIVAR ==========
            async desactivarProducto(p) {
                if (!confirm(`¿Marcar como descontinuado "${p.desproducto}"?`)) return;
                try {
                    const res = await fetch(`${this.API}/eliminar.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'soft_delete', idproducto: p.idproducto, id_empresa: this.idEmpresa })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Producto descontinuado', 'success');
                        this.loadProductos();
                    } else {
                        this.showToast(data.error || 'Error', 'error');
                    }
                } catch (e) {
                    this.showToast('Error de conexión', 'error');
                }
            },
            
            async activarProducto(p) {
                try {
                    const res = await fetch(`${this.API}/eliminar.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'restore', idproducto: p.idproducto, id_empresa: this.idEmpresa })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Producto activado', 'success');
                        this.loadProductos();
                    } else {
                        this.showToast(data.error || 'Error', 'error');
                    }
                } catch (e) {
                    this.showToast('Error de conexión', 'error');
                }
            },
            
            // ========== STOCK ==========
            async verStock(p) {
                this.stockProductoId = p.idproducto;
                this.stockProductoNombre = p.desproducto;
                this.stockDetalle = [];
                this.stockMovimientos = [];
                this.ajuste = { id_sucursal: '', cantidad: 0, motivo: '' };
                this.showStock = true;
                
                try {
                    const [stockRes, movRes] = await Promise.all([
                        fetch(`${this.API}/stock.php?action=stock&id=${p.idproducto}&id_empresa=${this.idEmpresa}`).then(r => r.json()),
                        fetch(`${this.API}/stock.php?action=movimientos&id=${p.idproducto}&id_empresa=${this.idEmpresa}&limit=20`).then(r => r.json()),
                    ]);
                    this.stockDetalle = stockRes?.data || [];
                    this.stockMovimientos = movRes?.data || [];
                } catch (error) {
                    console.error('Error cargando stock:', error);
                }
            },
            
            async guardarAjuste() {
                if (!this.ajuste.id_sucursal || !this.ajuste.cantidad) return;
                
                try {
                    const res = await fetch(`${this.API}/stock.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'ajuste',
                            id_producto: this.stockProductoId,
                            id_empresa: this.idEmpresa,
                            id_sucursal: this.ajuste.id_sucursal,
                            cantidad: this.ajuste.cantidad,
                            motivo: this.ajuste.motivo || 'Ajuste manual'
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Ajuste aplicado correctamente', 'success');
                        this.ajuste = { id_sucursal: '', cantidad: 0, motivo: '' };
                        // Recargar stock
                        await this.verStock({ idproducto: this.stockProductoId, desproducto: this.stockProductoNombre });
                        this.loadProductos();
                    } else {
                        this.showToast(data.error || 'Error al aplicar ajuste', 'error');
                    }
                } catch (e) {
                    this.showToast('Error de conexión', 'error');
                }
            },
            
            // ========== CATÁLOGOS ==========
            catFieldName() {
                const map = {
                    grupos: 'grupo',
                    marcas: 'marca',
                    modelos: 'modelo',
                    colores: 'color',
                    medidas: 'medida',
                    referencias: 'referencia',
                    marcas_cod_conversion: 'nombre',
                    marcas_aplicacion: 'nombre',
                    anios_aplicacion: 'nombre',
                    modelos_aplicacion: 'nombre',
                    motores_aplicacion: 'nombre',
                    codigos_motor_aplicacion: 'nombre'
                };
                return map[this.catTab] || 'nombre';
            },
            catTabLabel() {
                const map = {
                    grupos: 'grupo',
                    marcas: 'marca',
                    modelos: 'modelo',
                    colores: 'color',
                    medidas: 'medida',
                    referencias: 'referencia',
                    marcas_cod_conversion: 'equivalente',
                    marcas_aplicacion: 'marca aplicacion',
                    anios_aplicacion: 'ano',
                    modelos_aplicacion: 'modelo aplicacion',
                    motores_aplicacion: 'motor',
                    codigos_motor_aplicacion: 'cod. motor'
                };
                return map[this.catTab] || 'item';
            },
            
            async loadCatalogo(tabla) {
                tabla = tabla || this.catTab;
                try {
                    const res = await fetch(`${this.API}/catalogos.php?action=list&tabla=${tabla}&id_empresa=${this.idEmpresa}`);
                    const data = await res.json();
                    if (data?.success) {
                        const normalized = this.normalizeCatalogItems(tabla, data.data || []);
                        this.catItems = normalized;
                        this.setCatalogoArray(tabla, normalized);
                    } else {
                        this.catItems = this.normalizeCatalogItems(tabla, this.getCatalogoArray(tabla));
                    }
                } catch (e) {
                    console.error(e);
                    this.catItems = this.normalizeCatalogItems(tabla, this.getCatalogoArray(tabla));
                }
                this.catEditId = null;
                this.catNuevoNombre = '';
            },
            
            async crearCatalogo() {
                if (!this.catNuevoNombre.trim()) return;
                try {
                    const res = await fetch(`${this.API}/catalogos.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'create',
                            tabla: this.catTab,
                            nombre: this.catNuevoNombre.trim(),
                            id_empresa: this.idEmpresa
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.catNuevoNombre = '';
                        this.loadCatalogo();
                        this.loadCatalogos(); // refrescar filtros
                        this.showToast('Creado correctamente', 'success');
                    } else {
                        this.showToast(data.error || 'Error al crear', 'error');
                    }
                } catch (e) {
                    this.showToast('Error de conexión', 'error');
                }
            },
            
            async updateCatalogo(id) {
                if (!this.catEditNombre.trim()) return;
                try {
                    const res = await fetch(`${this.API}/catalogos.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'update',
                            tabla: this.catTab,
                            id: id,
                            nombre: this.catEditNombre.trim(),
                            id_empresa: this.idEmpresa
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.catEditId = null;
                        this.loadCatalogo();
                        this.loadCatalogos();
                        this.showToast('Actualizado correctamente', 'success');
                    } else {
                        this.showToast(data.error || 'Error', 'error');
                    }
                } catch (e) {
                    this.showToast('Error de conexión', 'error');
                }
            },
            
            async eliminarCatalogo(id) {
                if (!confirm('¿Eliminar este elemento del catálogo?')) return;
                try {
                    const res = await fetch(`${this.API}/catalogos.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'delete',
                            tabla: this.catTab,
                            id: id,
                            id_empresa: this.idEmpresa
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.loadCatalogo();
                        this.loadCatalogos();
                        this.showToast('Eliminado correctamente', 'success');
                    } else {
                        this.showToast(data.error || 'Error al eliminar', 'error');
                    }
                } catch (e) {
                    this.showToast('Error de conexión', 'error');
                }
            },

            async cargarCatalogosMock(silent = false) {
                if (!silent && !confirm('¿Cargar datos base en todos los catálogos?\nNo se duplicarán registros existentes.')) return;
                try {
                    const res = await fetch(`${this.API}/catalogos.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'seed_defaults',
                            id_empresa: this.idEmpresa
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.loadCatalogos();
                        await this.loadCatalogo(this.catTab);
                        if (!silent) this.showToast(data.message || 'Datos base cargados correctamente', 'success');
                    } else {
                        if (!silent) this.showToast(data.error || 'No se pudieron cargar los datos base', 'error');
                    }
                } catch (e) {
                    if (!silent) this.showToast('Error de conexión', 'error');
                }
            },
            
            // ========== IMPORTAR ==========
            handleImportFile(event) {
                this.importFile = event.target.files[0] || null;
                this.importResult = null;
            },
            handleImportDrop(event) {
                event.currentTarget.classList.remove('border-cyan-500', 'bg-cyan-50', 'dark:bg-cyan-900/10');
                this.importFile = event.dataTransfer.files[0] || null;
                this.importResult = null;
            },
            async ejecutarImport() {
                if (!this.importFile) return;
                this.importing = true;
                this.importResult = null;
                
                try {
                    const fd = new FormData();
                    fd.append('file', this.importFile);
                    fd.append('id_empresa', this.idEmpresa);
                    
                    const res = await fetch(`${this.API}/importar.php`, { method: 'POST', body: fd });
                    this.importResult = await res.json();
                    
                    if (this.importResult.success) {
                        this.loadProductos();
                        this.loadCatalogos();
                    }
                } catch (error) {
                    this.importResult = { success: false, error: 'Error de conexión' };
                }
                this.importing = false;
            },
            
            // ========== HELPERS ==========
            clearFilters() {
                this.searchQuery = '';
                this.filtroGrupo = '';
                this.filtroMarca = '';
                this.filtroEstado = '1';
                this.currentPage = 1;
                this.loadProductos();
            },
            
            prevPage() { if (this.currentPage > 1) { this.currentPage--; this.loadProductos(); } },
            nextPage() { if (this.currentPage < this.totalPages) { this.currentPage++; this.loadProductos(); } },
            setPerPage(value) {
                const next = Math.max(1, Number(value) || 25);
                if (this.perPage === next) return;
                this.perPage = next;
                this.currentPage = 1;
                this.loadProductos();
            },
            
            toggleTheme() {
                this.isDark = !this.isDark;
                document.documentElement.classList.toggle('dark', this.isDark);
                localStorage.theme = this.isDark ? 'dark' : 'light';
                this.themeMode = this.isDark ? 'dark' : 'light';
            },
            capitalizeText(value) {
                const text = String(value ?? '').trim().toLowerCase();
                if (!text) return '-';
                return text.charAt(0).toUpperCase() + text.slice(1);
            },
            
            formatMoney(amount) {
                return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(amount || 0);
            },
            formatNumber(n) {
                return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 2 }).format(n || 0);
            },
            formatDate(d) {
                if (!d) return '-';
                return new Date(d).toLocaleDateString('es-PY');
            },
            escapeHtml(value) {
                return String(value ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            },
            renderGridPreciosCell(row) {
                const precios = this.getPreciosOrdenados(row);
                if (!precios.length) {
                    const base = Number(row?.precio_venta || 0);
                    return `<span data-cell-menu="precio" class="prod-cell-trigger" style="font-weight:700;text-align:right;display:block;">₲ ${this.formatMoney(base)}</span>`;
                }
                const top = Number(precios[0]?.precio || 0);
                return `<span data-cell-menu="precio" class="prod-cell-trigger" style="font-weight:700;text-align:right;display:block;">₲ ${this.formatMoney(top)}</span>`;
            },
            getPreciosOrdenados(row) {
                const lista = [];
                const precios = Array.isArray(row?.precios) ? row.precios : [];
                for (const pr of precios) {
                    const val = Number(pr?.precio || 0);
                    if (val > 0) {
                        lista.push({
                            tipo: pr?.tipo ?? null,
                            tipo_nombre: pr?.tipo_nombre || '',
                            precio: val
                        });
                    }
                }
                lista.sort((a, b) => Number(b.precio || 0) - Number(a.precio || 0));
                return lista;
            },
            precioMaximo(row) {
                const ordenados = this.getPreciosOrdenados(row);
                return Number(ordenados[0]?.precio || 0);
            },
            renderGridStockCell(row) {
                const s = Number(this.stockSesion(row));
                const min = Number(row?.stock_minimo || 0);
                let color = '#16a34a';
                if (s <= 0) color = '#dc2626';
                else if (min > 0 && s <= min) color = '#d97706';
                return `<span data-cell-menu="stock" class="prod-cell-trigger" style="font-weight:700;text-align:right;display:block;color:${color};">${this.formatNumber(s)}</span>`;
            },
            stockSesion(row) {
                if (typeof row?.stock_sesion !== 'undefined' && row?.stock_sesion !== null) {
                    return Number(row.stock_sesion || 0);
                }
                const suc = Array.isArray(row?.stock_sucursales) ? row.stock_sucursales : [];
                if (suc.length > 0) return Number(suc[0]?.stock || 0);
                return Number(row?.saldo || 0);
            },
            onGridCellClicked(params) {
                const col = String(params?.colDef?.field || '');
                if (col !== 'precio_venta' && col !== 'saldo') return;
                this._suppressRowClickEditUntil = Date.now() + 280;
                this.openCellMenu(params, col === 'precio_venta' ? 'precio' : 'stock');
            },
            openCellMenu(params, kind) {
                const row = params?.data || {};
                const ev = params?.event;
                const x0 = Number(ev?.clientX || 0);
                const y0 = Number(ev?.clientY || 0);
                const menuW = Math.min(360, window.innerWidth - 24);
                const x = Math.max(12, Math.min(x0 - 12, window.innerWidth - menuW - 12));
                const y = Math.max(12, Math.min(y0 + 10, window.innerHeight - 320));
                const items = [];

                if (kind === 'precio') {
                    const precios = this.getPreciosOrdenados(row);
                    for (let i = 1; i < precios.length; i++) {
                        const pr = precios[i];
                        items.push({
                            label: pr?.tipo_nombre || `Tipo ${pr?.tipo ?? ''}`,
                            value: `₲ ${this.formatMoney(Number(pr?.precio || 0))}`
                        });
                    }
                    this.cellMenu = {
                        show: true,
                        x, y,
                        title: 'Precios asignados',
                        subtitle: `Principal: ₲ ${this.formatMoney(Number(precios[0]?.precio || 0))}`,
                        items
                    };
                    return;
                }

                const suc = Array.isArray(row?.stock_sucursales) ? row.stock_sucursales : [];
                const principal = suc[0] || null;
                for (let i = 1; i < suc.length; i++) {
                    const it = suc[i];
                    const val = Number(it?.stock || 0);
                    items.push({
                        label: it?.sucursal || `Suc. ${it?.id_sucursal ?? ''}`,
                        value: this.formatNumber(val),
                        valueClass: val <= 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white'
                    });
                }
                this.cellMenu = {
                    show: true,
                    x, y,
                    title: 'Stock por sucursal',
                    subtitle: `${principal?.sucursal || 'Sucursal sesión'}: ${this.formatNumber(Number(principal?.stock || 0))}`,
                    items
                };
            },
            closeCellMenu() {
                this.cellMenu.show = false;
            },
            
            stockClass(p) {
                const s = parseFloat(p.saldo) || 0;
                const min = parseFloat(p.stock_minimo) || 0;
                if (s <= 0) return 'text-red-600 dark:text-red-400';
                if (min > 0 && s <= min) return 'text-amber-600 dark:text-amber-400';
                return 'text-green-600 dark:text-green-400';
            },
            
            showToast(message, type = 'success') {
                this.toast = { show: true, message, type };
                setTimeout(() => { this.toast.show = false; }, 3500);
            }
        }
    }
