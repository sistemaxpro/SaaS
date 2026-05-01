(function () {
  'use strict';

  function getCajaContext() {
    var el = document.getElementById('cajaAppRoot');
    var idCaja = Number((el && el.dataset && el.dataset.idCaja) || 0) || 1;
    var idEmpresa = Number((el && el.dataset && el.dataset.idEmpresa) || 0) || 169;
    var printer = (el && el.dataset && el.dataset.printer) || '';
    var userRole = String((el && el.dataset && el.dataset.userRole) || '').toLowerCase();
    return { idCaja: idCaja, idEmpresa: idEmpresa, printer: printer, userRole: userRole };
  }

  function cajaNueva() {
    const today = new Date().toISOString().split('T')[0];
    return {
      idCaja: getCajaContext().idCaja,
      idEmpresa: getCajaContext().idEmpresa,
      printerName: getCajaContext().printer,
      userRole: getCajaContext().userRole,
      _smxPrinter: null,
      theme: 'dark',
      loading: false,
      saving: false,
      filterSearch: '',
      filterStatus: 'activos',
      paymentMethodFilter: '',
      filterPeriod: 'hoy',
      fechaDesde: today,
      fechaHasta: today,
      customDateFrom: today,
      customDateTo: today,
      periods: [
        { value: 'todo', label: 'Todo' },
        { value: 'hoy', label: 'Hoy' },
        { value: 'semana', label: 'Semana' },
        { value: 'mes', label: 'Mes' },
        { value: 'anio', label: 'Año' },
        { value: 'custom', label: 'Personalizado' },
      ],
      summary: {
        efectivo: 0,
        tarjeta: 0,
        transferencia: 0,
        qr_pix: 0,
        saldo: 0,
        facturas_pendientes_count: 0,
        facturas_pendientes_total: 0,
      },
      showPendientesModal: false,
      pendientesList: [],
      pendientesLoading: false,
      pendientesSearch: '',
      showDocumentosModal: false,
      documentosLoading: false,
      documentosSearch: '',
      documentosList: [],
      showCobroModal: false,
      cobroSaving: false,
      cobroDetalleLoading: false,
      cobroDetalle: { factura: null, items: [] },
      showCobroDocumentoModal: false,
      cobroDocumentoSaving: false,
      cobroDocumentoDetalleLoading: false,
      cobroDocumentoDetalle: null,
      cobroDocumentoForm: {
        id_documento: 0,
        id_factura: 0,
        cliente: '',
        concepto: '',
        cuota: '',
        total: 0,
        pagado: 0,
        pendiente: 0,
        monto: 0,
        efectivo_entrega: 0,
        metodo: 'EFECTIVO',
        referencia: '',
        card_financing_type: 'credito',
        card_processor: 'bancard',
        card_installments: 1,
        credit_installments: 1,
        credit_due_date: today,
        credit_notes: '',
      },
      cobroForm: {
        id_factura: 0,
        nro_factura: '',
        cliente: '',
        total: 0,
        pendiente: 0,
        monto: 0,
        efectivo_entrega: 0,
        efectivo_vuelto: 0,
        metodo: 'EFECTIVO',
        tipo_documento: 'FACTURA',
        tipo_cobro: 'TOTAL',
        referencia: '',
        card_financing_type: 'credito',
        card_processor: 'bancard',
        card_installments: 1,
        credit_installments: 1,
        credit_due_date: today,
        credit_notes: '',
        fecha: '',
      },
      qrInput: '',
      qrError: '',
      qrCameraActive: false,
      qrStream: null,
      qrDetectLoop: null,
      totalOperaciones: 0,
      operaciones: [],
      gridApi: null,
      gridColumnApi: null,
      gridReady: false,
      gridError: '',
      blockSize: 5,
      showModal: false,
      modalMode: 'entrada',
      formCurrency: 'Gs.',
      editingId: 0,
      editTipo: 'entrada',
      editOperacionId: null,
      editReferenciaId: null,
      editMedioCobro: 'EFECTIVO',
      modalMonto: '',
      modalConcepto: '',
      opForm: {
        tipo: 'entrada',
        referencia_tipo: 'caja',
        referencia_id: 0,
        referencia_texto: '',
        numero: '',
        importe: '',
        fecha_pago: today,
        beneficiario: '',
        concepto: '',
      },
      refOptions: [],
      refLoading: false,
      refActiveIndex: -1,
      toasts: [],
      toastSeq: 0,
      gridStateRestorePending: false,

      get modalTitle() {
        if (this.modalMode === 'open') return 'Apertura de Caja';
        if (this.modalMode === 'operacion') return 'Registro de Operacion';
        if (this.modalMode === 'salida') return 'Nueva Salida';
        if (this.modalMode === 'edit') return 'Editar Operacion';
        return 'Nueva Entrada';
      },

      get cobroValidationMessage() {
        if (!this.cobroForm.id_factura) return 'Escanee o cargue una factura pendiente.';
        if (!(Number(this.cobroForm.monto || 0) > 0) && !(Number(this.cobroForm.total || 0) > 0)) return 'No se pudo determinar monto de cobro.';
        if (String(this.cobroForm.metodo || '').toUpperCase() === 'EFECTIVO') {
          const entrega = Number(this.cobroForm.efectivo_entrega || 0);
          if (entrega <= 0) return 'Efectivo: ingrese monto de entrega.';
          if (entrega < Number(this.cobroMontoObjetivo || 0)) return 'Efectivo: la entrega no puede ser menor al total.';
        }
        const metodo = String(this.cobroForm.metodo || '').toUpperCase();
        if (!metodo) return 'Seleccione forma de pago.';

        if (metodo === 'TARJETA') {
          if (!String(this.cobroForm.referencia || '').trim()) return 'Tarjeta: ingrese voucher/NSU.';
          if (!(Number(this.cobroForm.card_installments || 0) >= 1)) return 'Tarjeta: cuotas inválidas.';
          if (!String(this.cobroForm.card_processor || '').trim()) return 'Tarjeta: seleccione procesador.';
          if (!String(this.cobroForm.card_financing_type || '').trim()) return 'Tarjeta: seleccione tipo.';
        } else if (metodo === 'TRANSFERENCIA') {
          if (!String(this.cobroForm.referencia || '').trim()) return 'Transferencia: ingrese referencia.';
        } else if (metodo === 'QR' || metodo === 'PIX') {
          if (!String(this.cobroForm.referencia || '').trim()) return 'PIX/QR: ingrese TXID o referencia.';
        }
        return '';
      },

      get canSubmitCobro() {
        return this.cobroValidationMessage === '';
      },

      get cobroMontoObjetivo() {
        const pendiente = Number(this.cobroForm.pendiente || 0);
        if (pendiente > 0) return pendiente;
        return Number(this.cobroForm.total || 0);
      },

      get cobroVuelto() {
        const entrega = Number(this.cobroForm.efectivo_entrega || 0);
        const total = Number(this.cobroMontoObjetivo || 0);
        return entrega - total;
      },

      get paymentMethodFilterLabel() {
        const labels = {
          EFECTIVO: 'Efectivo',
          TARJETA: 'Tarjeta',
          TRANSFERENCIA: 'Transferencia',
          QR: 'QR / PIX',
          PIX: 'QR / PIX',
        };
        return labels[String(this.paymentMethodFilter || '').toUpperCase()] || '';
      },

      get documentCobroMontoObjetivo() {
        const pendiente = Number(this.cobroDocumentoForm.pendiente || 0);
        if (pendiente > 0) return pendiente;
        return Number(this.cobroDocumentoForm.total || 0);
      },

      get documentCobroVuelto() {
        const entrega = Number(this.cobroDocumentoForm.efectivo_entrega || 0);
        const total = Number(this.documentCobroMontoObjetivo || 0);
        return entrega - total;
      },

      get documentCobroValidationMessage() {
        if (!this.cobroDocumentoForm.id_documento) return 'Seleccione un documento.';
        if (!(Number(this.documentCobroMontoObjetivo || 0) > 0)) return 'No se pudo determinar monto del documento.';
        const metodo = String(this.cobroDocumentoForm.metodo || '').toUpperCase();
        if (!metodo) return 'Seleccione forma de pago.';
        if (metodo === 'EFECTIVO') {
          const entrega = Number(this.cobroDocumentoForm.efectivo_entrega || 0);
          if (entrega <= 0) return 'Efectivo: ingrese monto de entrega.';
          if (entrega < Number(this.documentCobroMontoObjetivo || 0)) return 'Efectivo: la entrega no puede ser menor al total.';
        } else if (metodo === 'TARJETA') {
          if (!String(this.cobroDocumentoForm.referencia || '').trim()) return 'Tarjeta: ingrese voucher/NSU.';
        } else if (metodo === 'TRANSFERENCIA') {
          if (!String(this.cobroDocumentoForm.referencia || '').trim()) return 'Transferencia: ingrese referencia.';
        } else if (metodo === 'QR' || metodo === 'PIX') {
          if (!String(this.cobroDocumentoForm.referencia || '').trim()) return 'PIX/QR: ingrese TXID o referencia.';
        }
        return '';
      },

      get canSubmitCobroDocumento() {
        return this.documentCobroValidationMessage === '';
      },

      async init() {
        this.syncThemeFromStorage();
        window.addEventListener('storage', (e) => {
          if (!e || e.key === 'theme' || e.key === null) this.syncThemeFromStorage();
        });

        this.setPeriod('hoy', false);
        await this.loadFormMeta();

        await this.$nextTick();
        this.setupGrid();
        this.loadData();
      },

      syncThemeFromStorage() {
        const inherited = this.resolveInheritedTheme();
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        this.theme = inherited || (prefersDark ? 'dark' : 'light');
        try {
          localStorage.setItem('theme', this.theme);
        } catch (_) {}
        document.documentElement.classList.toggle('dark', this.theme === 'dark');
      },

      resolveInheritedTheme() {
        const normalize = (v) => (v === 'dark' || v === 'light' ? v : null);
        const fromCookie = () => {
          const m = document.cookie.match(/(?:^|;\s*)theme=(dark|light)(?:;|$)/i);
          return m ? normalize(String(m[1]).toLowerCase()) : null;
        };
        const fromDoc = (doc) => {
          if (!doc || !doc.documentElement) return null;
          const attr = normalize((doc.documentElement.getAttribute('data-bs-theme') || '').toLowerCase());
          if (attr) return attr;
          return doc.documentElement.classList.contains('dark') ? 'dark' : null;
        };
        const fromWinStorage = (w) => {
          try {
            return normalize((w.localStorage.getItem('theme') || '').toLowerCase());
          } catch (_) {
            return null;
          }
        };

        let t = fromWinStorage(window);
        if (t) return t;
        t = fromDoc(document);
        if (t) return t;
        t = fromCookie();
        if (t) return t;

        try {
          if (window.parent && window.parent !== window) {
            t = fromWinStorage(window.parent);
            if (t) return t;
            t = fromDoc(window.parent.document);
            if (t) return t;
          }
        } catch (_) {}

        try {
          if (window.opener) {
            t = fromWinStorage(window.opener);
            if (t) return t;
            t = fromDoc(window.opener.document);
            if (t) return t;
          }
        } catch (_) {}

        return null;
      },

      toggleTheme() {
        this.theme = 'dark';
        try {
          localStorage.setItem('theme', 'dark');
        } catch (_) {}
        document.documentElement.classList.add('dark');
        if (this.gridApi) {
          this.$nextTick(() => {
            this.gridApi.refreshCells({ force: true });
            this.gridApi.refreshHeader();
          });
        }
      },

      toast(message, type = 'success') {
        const id = ++this.toastSeq;
        this.toasts.push({ id, message, type, show: true });
        window.setTimeout(() => {
          this.toasts = Array.isArray(this.toasts)
            ? this.toasts.filter((t) => Number(t?.id || 0) !== id)
            : [];
        }, 3000);
      },

      async ensurePrinterClient() {
        if (!this._smxPrinter) {
          this._smxPrinter = new SmxPrinter({ agentTimeoutMs: 2000 });
        }
        await this._smxPrinter.connect();
        return this._smxPrinter;
      },

      async resolveEscposPrinter() {
        const client = await this.ensurePrinterClient();
        const configured = String(this.printerName || '').trim();

        if (configured) {
          const configuredMatches = await client.findPrinters(configured);
          if (Array.isArray(configuredMatches) && configuredMatches.length > 0) {
            return String(configuredMatches[0] || '').trim();
          }
        }

        const defaultPrinter = String(await client.getDefaultPrinter()).trim();
        if (defaultPrinter) return defaultPrinter;

        const allPrinters = await client.findPrinters();
        if (Array.isArray(allPrinters) && allPrinters.length > 0) {
          return String(allPrinters[0] || '').trim();
        }

        throw new Error('No hay impresoras disponibles');
      },

      getFacturaTicketEndpoint(factura = {}) {
        const tipoDocumento = String(factura?.tipo_documento ?? '').trim().toLowerCase();
        const cdc = String(factura?.cdc || '').trim();
        if (cdc.length > 10 || tipoDocumento === '3' || tipoDocumento === 'electro') {
          return 'ticket_factura_electronica.php';
        }
        if (tipoDocumento === '1' || tipoDocumento === 'auto') {
          return 'ticket_factura_autoimpresa.php';
        }
        return 'ticket_nota_comun.php';
      },

      async apiFetch(url, options = {}) {
        const headers = {
          'X-Requested-With': 'XMLHttpRequest',
          ...(options.headers || {}),
        };
        return fetch(url, { credentials: 'same-origin', ...options, headers });
      },

      dateRangeFor(period) {
        const d = new Date();
        const todayIso = d.toISOString().split('T')[0];
        const toIso = (x) => x.toISOString().split('T')[0];
        let from = new Date(d);
        let to = new Date(d);
        if (period === 'todo') from = new Date(d.getFullYear() - 3, 0, 1);
        if (period === 'semana') {
          const day = d.getDay();
          const diff = day === 0 ? 6 : day - 1;
          from.setDate(d.getDate() - diff);
        }
        if (period === 'mes') from = new Date(d.getFullYear(), d.getMonth(), 1);
        if (period === 'anio') from = new Date(d.getFullYear(), 0, 1);
        if (period === 'custom') {
          from = new Date(this.customDateFrom || todayIso);
          to = new Date(this.customDateTo || todayIso);
        }
        return { from: toIso(from), to: toIso(to) };
      },

      setPeriod(period, reload = true) {
        this.filterPeriod = period;
        const range = this.dateRangeFor(period);
        this.fechaDesde = range.from;
        this.fechaHasta = range.to;
        if (period !== 'custom') {
          this.customDateFrom = range.from;
          this.customDateTo = range.to;
        }
        if (reload) this.loadData();
      },

      applyCustomRange() {
        if (!this.customDateFrom || !this.customDateTo) return;
        if (this.customDateFrom > this.customDateTo) {
          const tmp = this.customDateFrom;
          this.customDateFrom = this.customDateTo;
          this.customDateTo = tmp;
        }
        this.setPeriod('custom');
      },

      clearFilters() {
        this.filterSearch = '';
        this.filterStatus = 'activos';
        this.paymentMethodFilter = '';
        this.setPeriod('hoy');
      },

      togglePaymentFilter(method) {
        const normalized = String(method || '').toUpperCase();
        this.paymentMethodFilter = this.paymentMethodFilter === normalized ? '' : normalized;
        this.loadData();
      },

      clearPaymentFilter() {
        if (!this.paymentMethodFilter) return;
        this.paymentMethodFilter = '';
        this.loadData();
      },

      async loadFormMeta() {
        try {
          const res = await this.apiFetch(`api/caja.php?action=form_meta&id_caja=${this.idCaja}&id_empresa=${this.idEmpresa}`, { cache: 'no-store' });
          const data = await res.json();
          if (data?.success && data?.currency) {
            this.formCurrency = String(data.currency);
          }
        } catch (_) {}
      },

      resetOperacionForm(tipo) {
        this.opForm = {
          tipo: (tipo === 'salida' ? 'salida' : 'entrada'),
          referencia_tipo: 'caja',
          referencia_id: 0,
          referencia_texto: '',
          numero: '',
          importe: '',
          fecha_pago: new Date().toISOString().split('T')[0],
          beneficiario: '',
          concepto: '',
        };
        this.refOptions = [];
        this.refActiveIndex = -1;
      },

      async searchReferenciaOptions() {
        const q = String(this.opForm.referencia_texto || '').trim();
        this.refLoading = true;
        try {
          const url = `api/caja.php?action=reference_search&id_empresa=${this.idEmpresa}&type=${encodeURIComponent(this.opForm.referencia_tipo)}&q=${encodeURIComponent(q)}&limit=20`;
          const res = await this.apiFetch(url, { cache: 'no-store' });
          const data = await res.json();
          this.refOptions = Array.isArray(data?.items) ? data.items : [];
          this.refActiveIndex = this.refOptions.length > 0 ? 0 : -1;
        } catch (_) {
          this.refOptions = [];
          this.refActiveIndex = -1;
        } finally {
          this.refLoading = false;
        }
      },

      async onReferenciaTipoChanged() {
        this.opForm.referencia_id = 0;
        this.opForm.referencia_texto = '';
        if (this.opForm.referencia_tipo !== 'banco') {
          this.opForm.numero = '';
          this.opForm.fecha_pago = '';
          this.opForm.beneficiario = '';
        }
        this.refOptions = [];
        this.refActiveIndex = -1;
        await this.searchReferenciaOptions();
      },

      selectReferencia(item) {
        this.opForm.referencia_id = Number(item?.id || 0);
        this.opForm.referencia_texto = String(item?.nombre || '');
        this.refOptions = [];
        this.refActiveIndex = -1;
      },

      onReferenciaKeydown(event) {
        if (!event) return;
        if (!Array.isArray(this.refOptions) || this.refOptions.length === 0) {
          if (event.key === 'Escape') {
            this.refOptions = [];
            this.refActiveIndex = -1;
          }
          return;
        }

        if (event.key === 'ArrowDown') {
          event.preventDefault();
          this.refActiveIndex = (this.refActiveIndex + 1) % this.refOptions.length;
          return;
        }
        if (event.key === 'ArrowUp') {
          event.preventDefault();
          this.refActiveIndex = (this.refActiveIndex - 1 + this.refOptions.length) % this.refOptions.length;
          return;
        }
        if (event.key === 'Enter') {
          event.preventDefault();
          const idx = this.refActiveIndex >= 0 ? this.refActiveIndex : 0;
          const item = this.refOptions[idx];
          if (item) this.selectReferencia(item);
          return;
        }
        if (event.key === 'Escape') {
          event.preventDefault();
          this.refOptions = [];
          this.refActiveIndex = -1;
        }
      },

      formatOperacionImporteInput(event) {
        const raw = event && event.target ? event.target.value : this.opForm.importe;
        const digits = String(raw || '').replace(/\D/g, '');
        const formatted = digits ? new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(Number(digits)) : '';
        this.opForm.importe = formatted;
        if (event && event.target) event.target.value = formatted;
      },

      focusNextField(event) {
        const formRoot = this.$refs && this.$refs.modalForm ? this.$refs.modalForm : null;
        if (!formRoot || !event || !event.target) return;

        const fields = Array.from(
          formRoot.querySelectorAll('input, select, textarea')
        ).filter((el) => {
          if (!el) return false;
          if (el.disabled) return false;
          if (el.readOnly) return false;
          if (el.type === 'hidden') return false;
          if (el.offsetParent === null) return false; // ocultos por x-show / css
          return true;
        });

        if (!fields.length) return;
        const currentIndex = fields.indexOf(event.target);
        if (currentIndex < 0) return;
        const nextIndex = (currentIndex + 1) % fields.length;
        const next = fields[nextIndex];
        if (next && typeof next.focus === 'function') {
          next.focus();
          if (typeof next.select === 'function' && next.tagName === 'INPUT') {
            next.select();
          }
        }
      },

      setupGrid() {
        if (this.gridReady) return;
        if (typeof agGrid === 'undefined' || !this.$refs.grid) {
          this.gridError = 'AG Grid no esta disponible en este navegador.';
          this.toast(this.gridError, 'error');
          return;
        }

        const self = this;
        const columnDefs = [
          { headerName: 'ID', field: 'id', width: 90, pinned: 'left' },
          {
            headerName: 'Fecha',
            field: 'fecha',
            minWidth: 190,
            valueFormatter: (p) => self.formatDate(p.value),
          },
          {
            headerName: 'Referencia',
            field: 'referencia_nombre',
            minWidth: 160,
            valueGetter: (p) => p.data?.referencia_nombre || p.data?.referencia || '-',
          },
          { headerName: 'Concepto', field: 'concepto', minWidth: 360, flex: 1 },
          {
            headerName: 'Entrada',
            field: 'credito',
            width: 140,
            type: 'rightAligned',
            valueGetter: (p) => Number((p.data && p.data.credito) || 0),
            cellStyle: { textAlign: 'right' },
            cellClass: 'cell-entrada',
            valueFormatter: (p) => self.formatMoneyCell(p.value, p.node),
          },
          {
            headerName: 'Salida',
            field: 'debito',
            width: 140,
            type: 'rightAligned',
            valueGetter: (p) => Number((p.data && p.data.debito) || 0),
            cellStyle: { textAlign: 'right' },
            cellClass: 'cell-salida',
            valueFormatter: (p) => self.formatMoneyCell(p.value, p.node),
          },
          {
            headerName: 'Saldo',
            field: 'saldo_acumulado',
            width: 150,
            type: 'rightAligned',
            valueGetter: (p) => Number((p.data && p.data.saldo_acumulado) || 0),
            cellStyle: { textAlign: 'right' },
            valueFormatter: (p) => self.formatMoneyCell(p.value, p.node),
          },
          {
            headerName: 'Estado',
            field: 'estado',
            width: 120,
            valueFormatter: (p) => (Number(p.value) === 1 ? 'Activo' : 'Anulado'),
          },
        ];

        const gridOptions = {
          columnDefs,
          localeText: window.SmxAgGridLocale?.getLocaleText?.() || {},
          defaultColDef: {
            sortable: true,
            resizable: true,
            filter: true,
            enableRowGroup: true,
          },
          rowGroupPanelShow: 'always',
          groupDisplayType: 'singleColumn',
          groupDefaultExpanded: 0,
          sideBar: {
            position: 'right',
            toolPanels: [
              {
                id: 'columns',
                labelDefault: 'Columnas',
                labelKey: 'columns',
                iconKey: 'columns',
                toolPanel: 'agColumnsToolPanel',
                toolPanelParams: {
                  suppressRowGroups: false,
                  suppressValues: true,
                  suppressPivots: true,
                  suppressPivotMode: true,
                },
              },
              {
                id: 'filters',
                labelDefault: 'Filtros',
                labelKey: 'filters',
                iconKey: 'filter',
                toolPanel: 'agFiltersToolPanel',
              },
            ],
            defaultToolPanel: '',
          },
          rowModelType: 'clientSide',
          rowSelection: 'single',
          getRowStyle: (params) => {
            if (params && params.node && params.node.rowPinned) {
              return {
                fontWeight: '700',
                background: self.theme === 'dark' ? '#1e293b' : '#e2e8f0',
              };
            }
            return null;
          },
          getContextMenuItems: (params) => {
            const row = params && params.node ? params.node.data : null;
            if (!row) {
              return ['copy', 'copyWithHeaders', 'separator', 'export'];
            }
            return [
              {
                name: 'Editar',
                icon: '<span style="display:inline-block;width:14px;text-align:center">✏️</span>',
                action: () => self.editOperacion(row),
              },
              {
                name: 'Imprimir',
                icon: '<span style="display:inline-block;width:14px;text-align:center">🖨️</span>',
                action: () => self.printOperacion(row),
              },
              {
                name: 'Anular',
                icon: '<span style="display:inline-block;width:14px;text-align:center">⛔</span>',
                disabled: Number(row.estado) !== 1,
                action: () => self.voidOperacion(row),
              },
              'separator',
              'copy',
              'copyWithHeaders',
              'separator',
              'export',
            ];
          },
          onGridReady: (params) => {
            self.gridApi = params.api || self.gridApi;
            self.gridColumnApi = params.columnApi || self.gridColumnApi;
            self.gridReady = true;
            self.restoreGridState();
          },
          onSortChanged: () => self.saveGridState(),
          onFilterChanged: () => self.saveGridState(),
          onColumnMoved: () => self.saveGridState(),
          onColumnPinned: () => self.saveGridState(),
          onColumnVisible: () => self.saveGridState(),
          onColumnResized: () => self.saveGridState(),
          onColumnRowGroupChanged: () => self.saveGridState(),
          onToolPanelVisibleChanged: () => self.saveGridState(),
          overlayNoRowsTemplate: '<span class="text-sm">Sin operaciones para los filtros actuales.</span>',
        };

        try {
          const api = agGrid.createGrid(this.$refs.grid, gridOptions);
          this.gridApi = api || this.gridApi;
          this.gridReady = true;
          this.gridError = '';
          this.refreshGridData();
        } catch (e) {
          this.gridError = 'No se pudo inicializar AG Grid.';
          this.toast(this.gridError, 'error');
        }
      },

      refreshGridData() {
        if (!this.gridApi) {
          this.gridError = 'Grid API no disponible.';
          return;
        }
        const rows = Array.isArray(this.operaciones) ? this.operaciones : [];
        if (typeof this.gridApi.setRowData === 'function') {
          this.gridApi.setRowData(rows);
        } else if (typeof this.gridApi.setGridOption === 'function') {
          this.gridApi.setGridOption('rowData', rows);
        }
        if (typeof this.gridApi.refreshClientSideRowModel === 'function') {
          this.gridApi.refreshClientSideRowModel('everything');
        }
        if (this.gridStateRestorePending) {
          this.restoreGridState();
        }
      },

      getGridStateKey() {
        return `pos:caja:gridstate:${this.idEmpresa}:${this.idCaja}`;
      },

      saveGridState() {
        if (!this.gridApi) return;
        try {
          const columnState = this.gridApi.getColumnState ? this.gridApi.getColumnState() : null;
          const filterModel = this.gridApi.getFilterModel ? this.gridApi.getFilterModel() : null;
          const sortModel = this.gridApi.getSortModel ? this.gridApi.getSortModel() : null;
          const openedToolPanel = this.gridApi.getOpenedToolPanel ? this.gridApi.getOpenedToolPanel() : null;
          const payload = {
            columnState,
            filterModel,
            sortModel,
            openedToolPanel,
          };
          sessionStorage.setItem(this.getGridStateKey(), JSON.stringify(payload));
        } catch (_) {}
      },

      restoreGridState() {
        if (!this.gridApi) {
          this.gridStateRestorePending = true;
          return;
        }
        this.gridStateRestorePending = false;
        try {
          const raw = sessionStorage.getItem(this.getGridStateKey());
          if (!raw) return;
          const state = JSON.parse(raw);
          if (state && Array.isArray(state.columnState) && this.gridApi.applyColumnState) {
            this.gridApi.applyColumnState({
              state: state.columnState,
              applyOrder: true,
            });
          }
          if (state && state.filterModel && this.gridApi.setFilterModel) {
            this.gridApi.setFilterModel(state.filterModel);
          }
          if (state && Array.isArray(state.sortModel) && this.gridApi.setSortModel) {
            this.gridApi.setSortModel(state.sortModel);
          }
          if (state && typeof state.openedToolPanel !== 'undefined' && this.gridApi.openToolPanel) {
            if (state.openedToolPanel) {
              this.gridApi.openToolPanel(state.openedToolPanel);
            } else if (this.gridApi.closeToolPanel) {
              this.gridApi.closeToolPanel();
            }
          }
        } catch (_) {}
      },

      async loadData() {
        this.loading = true;
        await Promise.all([this.loadSummary(), this.loadOperaciones()]);
        this.refreshGridData();
        this.loading = false;
      },

      async loadOperaciones() {
        try {
          const pageSize = 500;
          let offset = 0;
          let total = 0;
          const merged = [];
          do {
            const url = `api/caja.php?action=list&id_caja=${this.idCaja}&id_empresa=${this.idEmpresa}&fecha_desde=${this.fechaDesde}&fecha_hasta=${this.fechaHasta}&estado=${encodeURIComponent(this.filterStatus)}&q=${encodeURIComponent(this.filterSearch)}&medio_cobro=${encodeURIComponent(this.paymentMethodFilter)}&solo_mias=0&offset=${offset}&limit=${pageSize}&_ts=${Date.now()}`;
            const res = await this.apiFetch(url, { cache: 'no-store' });
            const data = await res.json();
            if (res.status === 401 || data?.error === 'Unauthorized') {
              this.toast('Sesion expirada. Vuelva a ingresar.', 'error');
              this.operaciones = [];
              this.totalOperaciones = 0;
              return;
            }
            const rows = Array.isArray(data?.operaciones) ? data.operaciones : [];
            total = Number(data?.totalRows || rows.length || 0);
            merged.push(...rows);
            offset += rows.length;
            if (rows.length === 0) break;
          } while (offset < total);

          this.operaciones = merged;
          this.totalOperaciones = merged.length;
        } catch (_) {
          this.operaciones = [];
          this.totalOperaciones = 0;
          this.toast('No se pudo cargar operaciones', 'error');
        }
      },

      async loadSummary() {
        try {
          const url = `api/caja.php?action=summary&id_caja=${this.idCaja}&id_empresa=${this.idEmpresa}&fecha_desde=${this.fechaDesde}&fecha_hasta=${this.fechaHasta}&estado=${encodeURIComponent(this.filterStatus)}&q=${encodeURIComponent(this.filterSearch)}&solo_mias=0&_ts=${Date.now()}`;
          const res = await this.apiFetch(url, { cache: 'no-store' });
          const data = await res.json();
          if (res.status === 401 || data?.error === 'Unauthorized') {
            this.toast('Sesion expirada. Vuelva a ingresar.', 'error');
            return;
          }
          if (data?.success) {
            this.summary = { ...this.summary, ...(data.summary || {}) };
          }
        } catch (_) {
          this.toast('No se pudo cargar resumen', 'error');
        }
      },

      async loadPendientes() {
        this.pendientesLoading = true;
        try {
          const url = `api/caja.php?action=facturas_pendientes_list&id_caja=${this.idCaja}&id_empresa=${this.idEmpresa}&limit=2000&q=${encodeURIComponent(this.pendientesSearch || '')}&_ts=${Date.now()}`;
          const res = await this.apiFetch(url, { cache: 'no-store' });
          const data = await res.json();
          if (data?.success) {
            this.pendientesList = data.facturas || [];
          }
        } catch (_) {
          this.toast('No se pudo cargar facturas pendientes', 'error');
        } finally {
          this.pendientesLoading = false;
        }
      },

      async loadPendingDocuments() {
        this.documentosLoading = true;
        try {
          const url = `api/caja.php?action=pending_documents&id_caja=${this.idCaja}&id_empresa=${this.idEmpresa}&query=${encodeURIComponent(this.documentosSearch || '')}&limit=200&_ts=${Date.now()}`;
          const res = await this.apiFetch(url, { cache: 'no-store' });
          const data = await res.json();
          if (data?.success) {
            this.documentosList = Array.isArray(data.documents) ? data.documents : [];
          } else {
            this.documentosList = [];
          }
        } catch (_) {
          this.documentosList = [];
          this.toast('No se pudo cargar documentos pendientes', 'error');
        } finally {
          this.documentosLoading = false;
        }
      },

      openDocumentosModal() {
        this.documentosSearch = '';
        this.documentosList = [];
        this.showDocumentosModal = true;
        this.loadPendingDocuments();
      },

      openCobroModal(f) {
        this.stopQrCamera();
        this.qrInput = '';
        this.qrError = '';
        this.cobroDetalle = { factura: null, items: [] };
        this.cobroForm = {
          id_factura: f.id_factura,
          nro_factura: f.nro_factura,
          cliente: f.cliente || '',
          total: Number(f.total || 0),
          pendiente: Number(f.saldo || 0),
          monto: Number(f.saldo || 0),
          efectivo_entrega: Number(f.saldo || 0),
          efectivo_vuelto: 0,
          metodo: 'EFECTIVO',
          tipo_documento: 'FACTURA',
          tipo_cobro: 'TOTAL',
          referencia: '',
          card_financing_type: 'credito',
          card_processor: 'bancard',
          card_installments: 1,
          credit_installments: 1,
          credit_due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
          credit_notes: '',
          fecha: f.fecha || ''
        };
        this.showCobroModal = true;
        this.loadCobroFacturaDetalle(f.id_factura);
      },

      openQrCobro() {
        this.resetCobroForm();
        this.showCobroModal = true;
        this.$nextTick(() => { this.$refs.qrInput?.focus(); });
      },

      resetCobroForm() {
        this.stopQrCamera();
        this.qrInput = '';
        this.qrError = '';
        this.cobroDetalle = { factura: null, items: [] };
        this.cobroForm = {
          id_factura: 0,
          nro_factura: '',
          cliente: '',
          total: 0,
          pendiente: 0,
          monto: 0,
          efectivo_entrega: 0,
          efectivo_vuelto: 0,
          metodo: 'EFECTIVO',
          tipo_documento: 'FACTURA',
          tipo_cobro: 'TOTAL',
          referencia: '',
          card_financing_type: 'credito',
          card_processor: 'bancard',
          card_installments: 1,
          credit_installments: 1,
          credit_due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
          credit_notes: '',
          fecha: '',
        };
        this.$nextTick(() => { this.$refs.qrInput?.focus(); });
      },

      closeCobroModal() {
        this.stopQrCamera();
        this.showCobroModal = false;
        this.cobroDetalleLoading = false;
      },

      resetCobroDocumentoForm() {
        this.cobroDocumentoDetalle = null;
        this.cobroDocumentoForm = {
          id_documento: 0,
          id_factura: 0,
          cliente: '',
          concepto: '',
          cuota: '',
          total: 0,
          pagado: 0,
          pendiente: 0,
          monto: 0,
          efectivo_entrega: 0,
          metodo: 'EFECTIVO',
          referencia: '',
          card_financing_type: 'credito',
          card_processor: 'bancard',
          card_installments: 1,
          credit_installments: 1,
          credit_due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
          credit_notes: '',
        };
      },

      async openCobroDocumentoModal(doc) {
        const idDocumento = Number((doc && (doc.id_documento || doc.numero || doc.id)) || 0);
        if (!idDocumento) {
          this.toast('Documento inválido', 'warning');
          return;
        }
        this.resetCobroDocumentoForm();
        this.cobroDocumentoDetalleLoading = true;
        this.showCobroDocumentoModal = true;
        try {
          const res = await this.apiFetch(`api/caja.php?action=document_detail&id=${idDocumento}&id_empresa=${this.idEmpresa}`, { cache: 'no-store' });
          const data = await res.json();
          if (!data?.success) throw new Error(data?.error || 'No se pudo cargar documento');
          const d = data.document || {};
          this.cobroDocumentoDetalle = d;
          const monto = Number(d.pendiente || d.total || 0);
          this.cobroDocumentoForm = {
            id_documento: Number(d.id_documento || idDocumento),
            id_factura: Number(d.id_factura || 0),
            cliente: String(d.cliente || ''),
            concepto: String(d.concepto || ''),
            cuota: String(d.cantidad_cuota || ''),
            total: Number(d.total || 0),
            pagado: Number(d.pagado || 0),
            pendiente: Number(d.pendiente || 0),
            monto,
            efectivo_entrega: monto,
            metodo: 'EFECTIVO',
            referencia: '',
            card_financing_type: 'credito',
            card_processor: 'bancard',
            card_installments: 1,
            credit_installments: 1,
            credit_due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
            credit_notes: '',
          };
        } catch (e) {
          this.toast(e.message || 'No se pudo cargar documento', 'error');
          this.showCobroDocumentoModal = false;
        } finally {
          this.cobroDocumentoDetalleLoading = false;
        }
      },

      closeCobroDocumentoModal() {
        this.showCobroDocumentoModal = false;
        this.cobroDocumentoDetalleLoading = false;
      },

      autoParseQr() {
        const v = (this.qrInput || '').trim();
        if (!v) return;
        if (v.startsWith('{') || v.includes('id_factura=') || v.includes('id=')) {
          this.parseQrInput();
        }
      },

      parseQrInput() {
        const v = (this.qrInput || '').trim();
        if (!v) return;
        this.qrError = '';
        try {
          const d = this.decodeQrFactura(v);
          if (!d.id) throw new Error('Datos incompletos');
          const pendiente = Number(d.saldo ?? d.total ?? 0);
          this.cobroForm = {
            id_factura: d.id,
            nro_factura: d.nro || '',
            cliente: d.cli || '',
            total: Number(d.total || 0),
            pendiente,
            monto: pendiente,
            efectivo_entrega: pendiente,
            efectivo_vuelto: 0,
            metodo: 'EFECTIVO',
            tipo_documento: (String(d.doc || 'FACTURA').toUpperCase() === 'NOTA') ? 'NOTA' : 'FACTURA',
            tipo_cobro: 'TOTAL',
            referencia: '',
            card_financing_type: 'credito',
            card_processor: 'bancard',
            card_installments: 1,
            credit_installments: 1,
            credit_due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
            credit_notes: '',
            fecha: d.f || ''
          };
          this.stopQrCamera();
          this.loadCobroFacturaDetalle(d.id);
        } catch (e) {
          this.qrError = 'QR inv\u00e1lido. Verifique el c\u00f3digo escaneado.';
        }
      },

      async loadCobroFacturaDetalle(idFactura) {
        const id = Number(idFactura || 0);
        if (!id) return;
        this.cobroDetalleLoading = true;
        try {
          const res = await this.apiFetch(`api/caja.php?action=invoice_detail&id=${id}&id_empresa=${this.idEmpresa}`, { cache: 'no-store' });
          const data = await res.json();
          if (!data?.success) {
            throw new Error(data?.error || 'No se pudo cargar detalle de factura');
          }
          const factura = data.factura || {};
          const items = Array.isArray(data.items) ? data.items : [];
          this.cobroDetalle = { factura, items };

          if (factura.id_factura) this.cobroForm.id_factura = Number(factura.id_factura || id);
          if (factura.nro_factura) this.cobroForm.nro_factura = String(factura.nro_factura);
          if (factura.cliente) this.cobroForm.cliente = String(factura.cliente);
          if (factura.total != null) this.cobroForm.total = Number(factura.total || 0);

          let saldo = Number(data.saldo_pendiente ?? factura.saldo ?? this.cobroForm.pendiente ?? 0);
          if (!(saldo > 0)) {
            saldo = Number(factura.total ?? this.cobroForm.total ?? 0);
          }
          this.cobroForm.pendiente = saldo;
          if (!this.cobroForm.monto || Number(this.cobroForm.monto) <= 0 || Number(this.cobroForm.monto) > saldo) {
            this.cobroForm.monto = saldo;
          }
          if (!(Number(this.cobroForm.efectivo_entrega || 0) > 0) || Number(this.cobroForm.efectivo_entrega || 0) < Number(this.cobroForm.monto || 0)) {
            this.cobroForm.efectivo_entrega = Number(this.cobroForm.monto || 0);
          }
          this.cobroForm.efectivo_vuelto = 0;
        } catch (e) {
          this.toast(e.message || 'No se pudo cargar detalle de factura', 'warning');
        } finally {
          this.cobroDetalleLoading = false;
        }
      },

      decodeQrFactura(raw) {
        const text = String(raw || '').trim();
        if (!text) return {};

        const normalize = (obj = {}) => ({
          id: Number(obj.id_factura ?? obj.id ?? 0),
          nro: String(obj.nro_factura ?? obj.nro ?? obj.factura ?? '').trim(),
          cli: String(obj.cliente ?? obj.cli ?? '').trim(),
          total: Number(obj.total ?? 0),
          saldo: Number(obj.saldo ?? obj.pendiente ?? obj.saldo_factura ?? 0),
          f: String(obj.fecha ?? obj.f ?? '').trim(),
          doc: String(obj.tipo_documento ?? obj.doc ?? 'FACTURA').trim().toUpperCase(),
        });

        if (text.startsWith('{')) {
          const parsed = JSON.parse(text);
          return normalize(parsed);
        }

        if (/^https?:\/\//i.test(text)) {
          const u = new URL(text);
          return normalize(Object.fromEntries(u.searchParams.entries()));
        }

        const pairs = {};
        text.split(/[;|,]/).forEach((part) => {
          const [k, ...rest] = part.split('=');
          if (!k || rest.length === 0) return;
          pairs[k.trim()] = rest.join('=').trim();
        });
        if (Object.keys(pairs).length > 0) {
          return normalize(pairs);
        }

        throw new Error('Formato QR no soportado');
      },

      async startQrCamera() {
        this.qrError = '';
        if (!navigator.mediaDevices?.getUserMedia) {
          this.qrError = 'C\u00e1mara no disponible en este dispositivo.';
          return;
        }
        try {
          const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
          this.qrStream = stream;
          this.qrCameraActive = true;
          await this.$nextTick();
          const video = this.$refs.qrVideo;
          if (video) {
            video.srcObject = stream;
            video.play();
          }
          if ('BarcodeDetector' in window) {
            const detector = new BarcodeDetector({ formats: ['qr_code'] });
            const detectLoop = () => {
              if (!this.qrCameraActive) return;
              detector.detect(video).then(codes => {
                if (codes.length > 0) {
                  this.qrInput = codes[0].rawValue;
                  this.parseQrInput();
                  return;
                }
                this.qrDetectLoop = requestAnimationFrame(detectLoop);
              }).catch(() => {
                this.qrDetectLoop = requestAnimationFrame(detectLoop);
              });
            };
            this.qrDetectLoop = requestAnimationFrame(detectLoop);
          } else {
            this.qrError = 'Detector QR no soportado. Use el campo de texto.';
          }
        } catch (e) {
          this.qrError = 'No se pudo acceder a la c\u00e1mara.';
          this.qrCameraActive = false;
        }
      },

      stopQrCamera() {
        if (this.qrDetectLoop) { cancelAnimationFrame(this.qrDetectLoop); this.qrDetectLoop = null; }
        if (this.qrStream) {
          this.qrStream.getTracks().forEach(t => t.stop());
          this.qrStream = null;
        }
        this.qrCameraActive = false;
      },

      async submitCobro() {
        if (!this.cobroForm.metodo) this.cobroForm.metodo = 'EFECTIVO';
        if (!this.cobroForm.tipo_documento) this.cobroForm.tipo_documento = 'FACTURA';
        this.cobroForm.tipo_cobro = 'TOTAL';
        const montoAuto = Number(this.cobroForm.pendiente || 0) > 0
          ? Number(this.cobroForm.pendiente || 0)
          : Number(this.cobroForm.total || 0);
        this.cobroForm.monto = montoAuto;
        const entrega = Number(this.cobroForm.efectivo_entrega || 0);
        this.cobroForm.efectivo_vuelto = entrega > montoAuto ? (entrega - montoAuto) : 0;

        const m = Number(this.cobroForm.monto);
        if (!this.cobroForm.id_factura) { this.toast('Debe cargar una factura desde QR', 'error'); return; }
        if (m <= 0) { this.toast('No se pudo determinar monto de la factura', 'error'); return; }
        if (!this.cobroForm.metodo) { this.toast('Debe seleccionar forma de pago', 'error'); return; }
        if (!this.canSubmitCobro) { this.toast(this.cobroValidationMessage || 'Complete los datos requeridos', 'error'); return; }
        this.cobroSaving = true;
        try {
          const res = await this.apiFetch('api/caja.php?action=cobro_factura_caja', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              id_factura: this.cobroForm.id_factura,
              monto: m,
              concepto: 'Cobro Fact. ' + this.cobroForm.nro_factura,
              payment_method: this.cobroForm.metodo,
              payment_ref: this.cobroForm.referencia,
              tipo_documento: this.cobroForm.tipo_documento,
              tipo_cobro: this.cobroForm.tipo_cobro,
              cliente: this.cobroForm.cliente,
              card_financing_type: this.cobroForm.card_financing_type,
              card_processor: this.cobroForm.card_processor,
              card_installments: this.cobroForm.card_installments,
              credit_installments: this.cobroForm.credit_installments,
              credit_due_date: this.cobroForm.credit_due_date,
              credit_notes: this.cobroForm.credit_notes,
              cash_received: Number(this.cobroForm.efectivo_entrega || 0),
              cash_change: Number(this.cobroVuelto || 0),
            })
          });
          const data = await res.json();
          if (data?.success) {
            this.toast(data.message || 'Cobro registrado', 'success');
            const facturaId = Number(this.cobroForm.id_factura || data.id_factura || 0);
            const facturaMeta = { ...(this.cobroDetalle?.factura || {}) };
            this.closeCobroModal();
            this.loadPendientes();
            this.loadData();
            await this.printFacturaCobrada(facturaId, facturaMeta);
          } else {
            this.toast(data?.error || 'Error al registrar cobro', 'error');
          }
        } catch (e) {
          this.toast('Error de conexión', 'error');
        } finally {
          this.cobroSaving = false;
        }
      },

      async submitCobroDocumento() {
        if (!this.canSubmitCobroDocumento) {
          this.toast(this.documentCobroValidationMessage || 'Complete los datos requeridos', 'error');
          return;
        }
        this.cobroDocumentoSaving = true;
        try {
          const monto = Number(this.documentCobroMontoObjetivo || 0);
          const res = await this.apiFetch('api/caja.php?action=cobro_documento', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              id_documento: this.cobroDocumentoForm.id_documento,
              monto,
              concepto: this.cobroDocumentoForm.concepto || ('Cobro Documento #' + this.cobroDocumentoForm.id_documento),
              payment_method: this.cobroDocumentoForm.metodo,
              payment_ref: this.cobroDocumentoForm.referencia,
              credit_installments: this.cobroDocumentoForm.credit_installments,
              credit_due_date: this.cobroDocumentoForm.credit_due_date,
              credit_notes: this.cobroDocumentoForm.credit_notes,
              cash_received: Number(this.cobroDocumentoForm.efectivo_entrega || 0),
              cash_change: Number(this.documentCobroVuelto || 0),
            })
          });
          const data = await res.json();
          if (data?.success) {
            this.toast(data.message || 'Cobro de documento registrado', 'success');
            const receiptUrl = data.receipt_url || '';
            const receiptId = Number(data.id_extracto_caja || 0);
            const docId = Number(data.id_documento || this.cobroDocumentoForm.id_documento || 0);
            this.closeCobroDocumentoModal();
            this.showDocumentosModal = false;
            await this.loadPendingDocuments();
            await this.loadData();
            await this.printCobroDocumentoReceipt(receiptId, docId, receiptUrl);
          } else {
            this.toast(data?.message || data?.error || 'No se pudo registrar cobro', 'error');
          }
        } catch (_) {
          this.toast('Error de conexión', 'error');
        } finally {
          this.cobroDocumentoSaving = false;
        }
      },

      async printCobroDocumentoReceipt(idCajaOp, idDocumento, receiptUrl = '') {
        if (!idCajaOp || !idDocumento) {
          if (receiptUrl) window.open(receiptUrl, '_blank', 'noopener');
          return;
        }
        try {
          const client = await this.ensurePrinterClient();
          const printer = await this.resolveEscposPrinter();
          const res = await fetch(`api/cobro_documento_print.php?id=${idCajaOp}&doc=${idDocumento}&id_empresa=${this.idEmpresa}&width=48`);
          const data = await res.json();
          if (!data.success || !data.data) throw new Error(data.message || 'Error generando recibo');
          await client.printRaw(printer, data.data);
          this.toast('Recibo de pago impreso', 'success');
        } catch (e) {
          if (receiptUrl) {
            window.open(receiptUrl, '_blank', 'noopener');
          } else {
            this.toast('No se pudo imprimir el recibo', 'warning');
          }
        }
      },

      async printFacturaCobrada(idFactura, factura = {}) {
        if (!idFactura) return;
        try {
          const client = await this.ensurePrinterClient();
          const printer = await this.resolveEscposPrinter();
          const endpoint = this.getFacturaTicketEndpoint(factura);
          const res = await fetch(`${endpoint}?id=${idFactura}&id_empresa=${this.idEmpresa}&width=48`);
          const data = await res.json();
          if (!data.success || !data.data) throw new Error(data.message || 'Error generando ticket');
          await client.printRaw(printer, data.data);
          this.toast('Ticket de venta impreso', 'success');
        } catch (e) {
          const url = `ticket.php?id=${idFactura}&id_empresa=${this.idEmpresa}&width=48&logo=0&autoprint=1`;
          window.open(url, '_blank', 'width=420,height=640');
        }
      },

      openModal(mode) {
        if (mode === 'entrada' || mode === 'salida') {
          this.modalMode = 'operacion';
          this.resetOperacionForm(mode);
          this.showModal = true;
          this.searchReferenciaOptions();
          return;
        }
        this.modalMode = mode;
        this.editingId = 0;
        this.editTipo = 'entrada';
        this.editOperacionId = null;
        this.editReferenciaId = null;
        this.editMedioCobro = 'EFECTIVO';
        this.modalMonto = '';
        this.modalConcepto = mode === 'open' ? 'APERTURA DE CAJA' : '';
        this.showModal = true;
      },

      formatMontoGs(value) {
        const digits = String(value == null ? '' : value).replace(/\D/g, '');
        if (!digits) return '';
        return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(Number(digits));
      },

      formatModalMontoInput(event) {
        const raw = event && event.target ? event.target.value : this.modalMonto;
        const formatted = this.formatMontoGs(raw);
        this.modalMonto = formatted;
        if (event && event.target) event.target.value = formatted;
      },

      formatCobroEfectivoEntregaInput(event) {
        const raw = event && event.target ? event.target.value : this.cobroForm.efectivo_entrega;
        const digits = String(raw == null ? '' : raw).replace(/\D/g, '');
        const numeric = digits ? Number(digits) : 0;
        this.cobroForm.efectivo_entrega = numeric;
        if (event && event.target) event.target.value = this.formatMontoGs(numeric);
      },

      formatCobroDocumentoEfectivoEntregaInput(event) {
        const raw = event && event.target ? event.target.value : this.cobroDocumentoForm.efectivo_entrega;
        const digits = String(raw == null ? '' : raw).replace(/\D/g, '');
        const numeric = digits ? Number(digits) : 0;
        this.cobroDocumentoForm.efectivo_entrega = numeric;
        if (event && event.target) event.target.value = this.formatMontoGs(numeric);
      },

      editOperacion(row) {
        this.modalMode = 'edit';
        this.editingId = Number(row.id || 0);
        this.editTipo = Number(row.credito || 0) > 0 ? 'entrada' : 'salida';
        this.editOperacionId = row.operacion ? Number(row.operacion) : null;
        this.editReferenciaId = row.referencia ? Number(row.referencia) : null;
        this.editMedioCobro = row.medio_cobro || 'EFECTIVO';
        this.modalMonto = this.formatMontoGs(Number(row.credito || row.debito || 0));
        this.modalConcepto = row.concepto || '';
        this.showModal = true;
      },

      async printPendiente(f) {
        const id = f.id_factura || f.id;
        if (!id) { this.toast('Sin ID de factura', 'warning'); return; }
        // Intentar impresión ESC/POS directa
        try {
          const client = await this.ensurePrinterClient();
          const printer = await this.resolveEscposPrinter();
          const res = await fetch(`ticket_comprobante_pendiente.php?id=${id}&id_empresa=${this.idEmpresa}&width=48`);
          const data = await res.json();
          if (!data.success || !data.data) throw new Error(data.message || 'Error generando ticket');
          await client.printRaw(printer, data.data);
          this.toast('Ticket pendiente impreso', 'success');
        } catch (e) {
          console.warn('ESC/POS falló, abriendo ticket web:', e.message);
          const url = `ticket.php?id=${id}&id_empresa=${this.idEmpresa}&width=48&logo=0&autoprint=1`;
          window.open(url, '_blank', 'width=420,height=640');
        }
      },

      printOperacion(row) {
        const html = `
            <html><head><title>Operacion Caja #${row.id}</title></head>
            <body style="font-family:Arial,sans-serif;padding:16px">
            <h3>Operacion de Caja #${row.id}</h3>
            <p><b>Fecha:</b> ${this.formatDate(row.fecha)}</p>
            <p><b>Concepto:</b> ${row.concepto || '-'}</p>
            <p><b>Entrada:</b> ${Number(row.credito || 0)}</p>
            <p><b>Salida:</b> ${Number(row.debito || 0)}</p>
            <p><b>Saldo:</b> ${Number(row.saldo_acumulado || 0)}</p>
            </body></html>`;
        const w = window.open('', '_blank', 'width=420,height=640');
        if (!w) {
          this.toast('No se pudo abrir ventana de impresion', 'warning');
          return;
        }
        w.document.open();
        w.document.write(html);
        w.document.close();
        w.focus();
        w.print();
      },

      async voidOperacion(row) {
        if (Number(row.estado) !== 1) return;
        if (!confirm(`Anular operacion #${row.id}?`)) return;
        try {
          const res = await this.apiFetch('api/caja.php?action=void', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: Number(row.id || 0), id_caja: this.idCaja, id_empresa: this.idEmpresa }),
          });
          const data = await res.json();
          if (data?.success) {
            if (data?.pending_approval) {
              this.toast(data?.message || 'Solicitud enviada para aprobación de administrador', 'warning');
            } else {
              this.toast(data?.message || 'Operacion anulada');
              await this.loadData();
            }
          } else {
            const msg = data?.error ? `${data?.message || 'No se pudo anular'}: ${data.error}` : (data?.message || 'No se pudo anular');
            this.toast(msg, 'error');
          }
        } catch (_) {
          this.toast('Error de conexion', 'error');
        }
      },

      async closeCajaQuick() {
        const saldoSistema = Number(this.summary && this.summary.saldo ? this.summary.saldo : 0);
        const defaultContado = String(Math.max(0, Math.round(saldoSistema)));
        const supervisorDefault = 'Supervisor';

        const supervisor = (prompt('Supervisor que recibe el cierre:', supervisorDefault) || '').trim();
        if (!supervisor) {
          this.toast('Debe indicar el supervisor', 'warning');
          return;
        }

        const contadoInput = prompt('Efectivo contado:', defaultContado);
        if (contadoInput === null) return;
        const efectivoContado = Number(String(contadoInput).replace(/\./g, '').replace(',', '.'));
        if (!Number.isFinite(efectivoContado) || efectivoContado < 0) {
          this.toast('Efectivo contado invalido', 'warning');
          return;
        }

        const entregadoInput = prompt('Monto entregado al supervisor:', String(Math.max(0, Math.round(efectivoContado))));
        if (entregadoInput === null) return;
        const montoEntregado = Number(String(entregadoInput).replace(/\./g, '').replace(',', '.'));
        if (!Number.isFinite(montoEntregado) || montoEntregado < 0) {
          this.toast('Monto entregado invalido', 'warning');
          return;
        }

        if (!confirm('Confirma cierre de caja?')) return;

        try {
          const res = await this.apiFetch('api/caja.php?action=close', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              id_caja: this.idCaja,
              id_empresa: this.idEmpresa,
              efectivo_contado: efectivoContado,
              monto_entregado_supervisor: montoEntregado,
              supervisor_nombre: supervisor,
              observacion: '',
              valores: [],
            }),
          });
          const data = await res.json();
          if (res.status === 401 || data?.error === 'Unauthorized') {
            this.toast('Sesion expirada. Vuelva a ingresar.', 'error');
            return;
          }
          if (data?.success) {
            this.toast('Caja cerrada correctamente');
            await this.loadData();
          } else {
            this.toast(data?.message || data?.error || 'No se pudo cerrar caja', 'error');
          }
        } catch (_) {
          this.toast('Error de conexion', 'error');
        }
      },

      async saveModal() {
        if (this.modalMode === 'operacion') {
          const importe = Number(String(this.opForm.importe || '').replace(/\D/g, ''));
          if (!importe || importe <= 0) {
            this.toast('El importe debe ser mayor a 0', 'warning');
            return;
          }
          if (!this.opForm.referencia_id) {
            this.toast('Seleccione una referencia valida', 'warning');
            return;
          }
          if (!String(this.opForm.concepto || '').trim()) {
            this.toast('Ingrese el concepto', 'warning');
            return;
          }

          this.saving = true;
          try {
            const payload = {
              tipo: this.opForm.tipo,
              referencia_tipo: this.opForm.referencia_tipo,
              referencia_id: this.opForm.referencia_id,
              numero: this.opForm.numero || '',
              importe,
              fecha_pago: this.opForm.fecha_pago || '',
              beneficiario: this.opForm.referencia_tipo === 'banco' ? (this.opForm.beneficiario || '') : '',
              concepto: this.opForm.concepto || '',
              id_caja: this.idCaja,
              id_empresa: this.idEmpresa,
            };
            const res = await this.apiFetch('api/caja.php?action=operation_form_save', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (res.status === 401 || data?.error === 'Unauthorized') {
              this.toast('Sesion expirada. Vuelva a ingresar.', 'error');
              return;
            }
            if (data?.success) {
              this.toast(data?.message || 'Operacion registrada correctamente');
              if (data?.warning) this.toast(String(data.warning), 'warning');
              this.showModal = false;
              await this.loadData();
            } else {
              this.toast(data?.message || data?.error || 'No se pudo guardar', 'error');
            }
          } catch (_) {
            this.toast('Error de conexion', 'error');
          } finally {
            this.saving = false;
          }
          return;
        }

        const monto = Number(String(this.modalMonto || '').replace(/\D/g, ''));
        if (!monto || monto <= 0) {
          this.toast('Monto invalido', 'warning');
          return;
        }
        if (this.modalMode !== 'open' && !String(this.modalConcepto || '').trim()) {
          this.toast('Ingrese concepto', 'warning');
          return;
        }

        this.saving = true;
        try {
          let action = 'insert';
          let payload = {};
          if (this.modalMode === 'open') {
            action = 'open';
            payload = { monto, id_caja: this.idCaja, id_empresa: this.idEmpresa };
          } else if (this.modalMode === 'edit') {
            action = 'update';
            payload = {
              id: this.editingId,
              tipo: this.editTipo,
              monto,
              concepto: this.modalConcepto.trim(),
              operacion: this.editOperacionId,
              referencia: this.editReferenciaId,
              medio_cobro: this.editMedioCobro,
              id_caja: this.idCaja,
              id_empresa: this.idEmpresa,
            };
          } else {
            payload = {
              tipo: this.modalMode,
              monto,
              concepto: this.modalConcepto.trim(),
              id_caja: this.idCaja,
              id_empresa: this.idEmpresa,
              payment_method: 'efectivo',
            };
          }
          const res = await this.apiFetch(`api/caja.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          });
          const data = await res.json();
          if (res.status === 401 || data?.error === 'Unauthorized') {
            this.toast('Sesion expirada. Vuelva a ingresar.', 'error');
            return;
          }
          if (data?.success) {
            this.toast(action === 'open' ? 'Caja abierta correctamente' : action === 'update' ? 'Operacion actualizada' : 'Operacion guardada');
            this.showModal = false;
            await this.loadData();
          } else {
            const msg = data?.message || data?.error || 'No se pudo guardar';
            const type = (data?.code === 'CAJA_ALREADY_OPEN') ? 'warning' : 'error';
            this.toast(msg, type);
          }
        } catch (_) {
          this.toast('Error de conexion', 'error');
        } finally {
          this.saving = false;
        }
      },

      exportCsv() {
        if (!this.gridApi) {
          this.toast('La grilla no esta lista', 'warning');
          return;
        }
        this.gridApi.exportDataAsCsv({
          fileName: `caja_${this.idCaja}_${new Date().toISOString().slice(0, 10)}.csv`,
        });
      },

      formatDate(v) {
        if (!v) return '-';
        try {
          return new Date(v).toLocaleString('es-PY');
        } catch (_) {
          return String(v);
        }
      },

      money(v) {
        return new Intl.NumberFormat('es-PY').format(Number(v || 0));
      },

      formatMoneyCell(value, node) {
        const n = Number(value || 0);
        const isGroupRow = !!(node && (node.group || node.footer || node.rowPinned));
        if (!isGroupRow && n <= 0) return '';
        return this.money(n);
      },
    };
  }

  window.cajaNueva = cajaNueva;
})();
