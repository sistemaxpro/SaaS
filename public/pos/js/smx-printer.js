(function (global) {
  'use strict';

  function SmxPrinter(options) {
    this.options = Object.assign(
      {
        strategy: 'agent-only',
        agentBaseUrl: 'http://127.0.0.1:17890',
        agentTimeoutMs: 1800
      },
      options || {}
    );

    this.provider = 'none';
    this.lastError = '';
    this._activeBaseUrl = String(this.options.agentBaseUrl || 'http://127.0.0.1:17890');
    this._androidBridgeAvailable = this._hasAndroidBridge();
  }

  SmxPrinter.prototype._hasAndroidBridge = function () {
    var bridge = global.Android;
    if (!bridge) return false;
    if (typeof bridge.printerHealthJson !== 'function') return false;
    if (typeof bridge.printerListJson !== 'function') return false;
    if (typeof bridge.printRawJson !== 'function') return false;
    return true;
  };

  SmxPrinter.prototype._getAndroidBridge = function () {
    if (!this._hasAndroidBridge()) {
      throw new Error('Bridge Android no disponible');
    }
    return global.Android;
  };

  SmxPrinter.prototype._parseBridgeJson = function (raw) {
    if (!raw) throw new Error('Bridge Android sin respuesta');
    var parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
    if (!parsed || parsed.ok !== true) {
      throw new Error((parsed && parsed.error) || 'Bridge Android no disponible');
    }
    return parsed.data;
  };

  SmxPrinter.prototype._tryAndroidBridgeConnect = function () {
    var bridge = this._getAndroidBridge();
    var data = this._parseBridgeJson(bridge.printerHealthJson());
    this.provider = 'android';
    this.lastError = '';
    return !!data;
  };

  SmxPrinter.prototype._getCandidateBaseUrls = function () {
    var primary = String(this.options.agentBaseUrl || 'http://127.0.0.1:17890').trim();
    var extra = [
      primary,
      'http://localhost:17890',
      'http://127.0.0.1:17890'
    ];
    var out = [];
    var seen = {};
    for (var i = 0; i < extra.length; i++) {
      var b = String(extra[i] || '').trim().replace(/\/+$/, '');
      if (!b || seen[b]) continue;
      seen[b] = true;
      out.push(b);
    }
    return out;
  };

  SmxPrinter.prototype._withTimeout = async function (url, init) {
    var controller = new AbortController();
    var timeout = setTimeout(function () {
      controller.abort();
    }, this.options.agentTimeoutMs);

    try {
      var req = Object.assign({}, init || {}, { signal: controller.signal });
      return await fetch(url, req);
    } finally {
      clearTimeout(timeout);
    }
  };

  SmxPrinter.prototype._fetchJsonAtBase = async function (baseUrl, path, init) {
    var res = await this._withTimeout(String(baseUrl || '').replace(/\/+$/, '') + path, init);
    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }
    return await res.json();
  };

  SmxPrinter.prototype._fetchJson = async function (path, init) {
    return this._fetchJsonAtBase(this._activeBaseUrl, path, init);
  };

  SmxPrinter.prototype._tryAgentConnect = async function () {
    var candidates = this._getCandidateBaseUrls();
    var lastErr = null;

    for (var i = 0; i < candidates.length; i++) {
      var base = candidates[i];
      try {
        var health = await this._fetchJsonAtBase(base, '/health', { cache: 'no-store' });
        if (health && health.ok === true) {
          this._activeBaseUrl = base;
          this.provider = 'agent';
          this.lastError = '';
          return true;
        }
      } catch (e) {
        lastErr = e;
      }
    }

    throw new Error(lastErr && lastErr.message ? lastErr.message : 'Agente no disponible');
  };

  SmxPrinter.prototype.connect = async function () {
    var strategy = this.options.strategy;
    if (this._androidBridgeAvailable || this._hasAndroidBridge()) {
      this._androidBridgeAvailable = true;
      return this._tryAndroidBridgeConnect();
    }
    if (strategy === 'agent-only') {
      return this._tryAgentConnect();
    }
    return this._tryAgentConnect();
  };

  SmxPrinter.prototype.disconnect = async function () {};

  SmxPrinter.prototype.isActive = function () {
    if (this.provider === 'android') return true;
    if (this.provider === 'agent') return true;
    return false;
  };

  SmxPrinter.prototype.getProviderLabel = function () {
    if (this.provider === 'android') return 'Android';
    if (this.provider === 'agent') return 'Agent';
    return 'N/A';
  };

  SmxPrinter.prototype.findPrinters = async function (needle) {
    if (this.provider === 'android') {
      var bridge = this._getAndroidBridge();
      var androidList = this._parseBridgeJson(bridge.printerListJson());
      var aList = Array.isArray(androidList) ? androidList : [];
      if (!needle) return aList;
      var aLower = String(needle).toLowerCase();
      return aList.filter(function (p) {
        return String(p).toLowerCase().indexOf(aLower) !== -1;
      });
    }

    if (this.provider === 'agent') {
      var data = await this._fetchJson('/printers', { cache: 'no-store' });
      var list = Array.isArray(data.data) ? data.data : [];
      if (!needle) return list;
      var lower = String(needle).toLowerCase();
      return list.filter(function (p) {
        return String(p).toLowerCase().indexOf(lower) !== -1;
      });
    }

    throw new Error('Sin proveedor de impresion activo');
  };

  SmxPrinter.prototype.getDefaultPrinter = async function () {
    if (this.provider === 'android') {
      return String(this._getAndroidBridge().getDefaultPrinter() || '').trim();
    }
    if (this.provider !== 'agent') {
      throw new Error('Sin proveedor de impresion activo');
    }

    // Nuevo endpoint del agent (si está disponible)
    try {
      var def = await this._fetchJson('/printers/default', { cache: 'no-store' });
      if (def && def.ok === true && def.data && def.data.name) {
        return String(def.data.name).trim();
      }
    } catch (e) {
      // Compatibilidad con agentes anteriores: fallback por listado.
    }

    var list = await this.findPrinters();
    if (!Array.isArray(list) || list.length === 0) {
      throw new Error('No hay impresoras disponibles en el sistema');
    }
    return String(list[0] || '').trim();
  };

  SmxPrinter.prototype.getPrinterDetails = async function (needle) {
    if (this.provider === 'android') {
      var bridge = this._getAndroidBridge();
      var androidDetails = this._parseBridgeJson(bridge.printerDetailsJson());
      var aDetails = Array.isArray(androidDetails) ? androidDetails : [];
      if (!needle) return aDetails;
      var aLower = String(needle).toLowerCase();
      return aDetails.filter(function (item) {
        return String((item && item.name) || '').toLowerCase().indexOf(aLower) !== -1;
      });
    }

    if (this.provider !== 'agent') {
      throw new Error('Sin proveedor de impresion activo');
    }

    var data = await this._fetchJson('/printers/details', { cache: 'no-store' });
    var list = Array.isArray(data.data) ? data.data : [];
    if (!needle) return list;
    var lower = String(needle).toLowerCase();
    return list.filter(function (item) {
      return String((item && item.name) || '').toLowerCase().indexOf(lower) !== -1;
    });
  };

  SmxPrinter.prototype.printRaw = async function (printerName, base64Data) {
    if (!printerName) throw new Error('Impresora requerida');
    if (!base64Data) throw new Error('Contenido de impresion vacio');

    if (this.provider === 'android') {
      var bridge = this._getAndroidBridge();
      var androidResult = JSON.parse(bridge.printRawJson(printerName, base64Data));
      if (!androidResult || androidResult.ok !== true) {
        throw new Error((androidResult && androidResult.error) || 'Error enviando impresion a Android');
      }
      return androidResult;
    }

    if (this.provider === 'agent') {
      var payload = {
        printer: printerName,
        data: base64Data,
        copies: 1
      };

      var result = await this._fetchJson('/print/raw', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      if (!result || result.ok !== true) {
        throw new Error((result && result.error) || 'Error enviando impresion al agente');
      }
      return result;
    }

    throw new Error('Sin proveedor de impresion activo');
  };

  global.SmxPrinter = SmxPrinter;
})(window);
