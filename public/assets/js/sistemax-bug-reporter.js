(function () {
  'use strict';

  if (window.__SISTEMAX_BUG_REPORTER_INIT__) return;
  window.__SISTEMAX_BUG_REPORTER_INIT__ = true;

  var cfg = window.__SISTEMAX_BUG_CONFIG__ || {};
  var state = {
    open: false,
    lastText: '',
    lastPayload: null,
    retryFn: null,
    autoSendTimer: null,
    autoSendTickTimer: null,
    autoSendRemaining: 0,
    sending: false,
    lastSentIncident: '',
    followupTimer: null,
    detectToastTimer: null,
    inboxTimer: null,
    lastIgnoredFetchAt: 0,
    lastIgnoredFetchUrl: ''
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function pad2(n) { return String(n).padStart(2, '0'); }

  function incidentId() {
    var d = new Date();
    var stamp = d.getFullYear() + pad2(d.getMonth() + 1) + pad2(d.getDate()) +
      '-' + pad2(d.getHours()) + pad2(d.getMinutes()) + pad2(d.getSeconds());
    var rand = Math.floor(Math.random() * 9000 + 1000);
    return 'INC-' + stamp + '-' + rand;
  }

  function ensureUi() {
    if (document.getElementById('sxBugModal')) return;

    var wrap = document.createElement('div');
    wrap.id = 'sxBugModal';
    wrap.style.cssText = [
      'position:fixed',
      'right:14px',
      'bottom:14px',
      'z-index:2147483000',
      'display:none',
      'width:min(430px,calc(100vw - 20px))'
    ].join(';');

    wrap.innerHTML =
      '<div style="max-height:78vh;overflow:auto;background:#0f172a;border:1px solid rgba(248,113,113,.45);border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.5);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">' +
      '<div style="padding:14px 16px;border-bottom:1px solid rgba(248,113,113,.28);display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
      '<div>' +
      '<div style="font-size:15px;font-weight:700;color:#fecaca;">No se pudo completar</div>' +
      '<div style="font-size:12px;color:#fca5a5;">Enviando al Centro de Bug en <b id="sxBugCountdown">3s</b></div>' +
      '</div>' +
      '<button id="sxBugClose" style="background:#7f1d1d;color:#fecaca;border:0;border-radius:10px;width:32px;height:32px;cursor:pointer;">x</button>' +
      '</div>' +
      '<div style="padding:14px 16px;color:#e2e8f0;">' +
      '<div id="sxBugSummary" style="font-size:13px;font-weight:600;line-height:1.5;"></div>' +
      '<div style="margin-top:10px;font-size:12px;color:#cbd5e1;">' +
      '<div><b>Incidencia:</b> <span id="sxBugInc"></span></div>' +
      '<div><b>Empresa:</b> <span id="sxBugEmpresa"></span></div>' +
      '<div><b>Usuario:</b> <span id="sxBugUser"></span></div>' +
      '<div><b>App:</b> <span id="sxBugApp"></span></div>' +
      '<div><b>Fecha:</b> <span id="sxBugDate"></span></div>' +
      '</div>' +
      '<details style="margin-top:10px;border:1px solid rgba(148,163,184,.25);border-radius:10px;padding:8px 10px;">' +
      '<summary style="cursor:pointer;color:#93c5fd;font-size:12px;">Ver detalle tecnico</summary>' +
      '<pre id="sxBugDetail" style="white-space:pre-wrap;word-break:break-word;margin:8px 0 0 0;font-size:11px;line-height:1.45;color:#e2e8f0;"></pre>' +
      '</details>' +
      '</div>' +
      '<div style="padding:0 16px 16px;display:flex;flex-wrap:wrap;gap:8px;">' +
      '<button id="sxBugRetry" style="background:#2563eb;color:#fff;border:0;border-radius:10px;padding:9px 12px;cursor:pointer;font-size:12px;font-weight:600;">Reintentar</button>' +
      '<button id="sxBugCopy" style="background:#334155;color:#fff;border:0;border-radius:10px;padding:9px 12px;cursor:pointer;font-size:12px;font-weight:600;">Copiar detalle</button>' +
      '<button id="sxBugWa" style="background:#16a34a;color:#fff;border:0;border-radius:10px;padding:9px 12px;cursor:pointer;font-size:12px;font-weight:600;">Enviar ahora</button>' +
      '<button id="sxBugBoard" style="display:none;background:#7c3aed;color:#fff;border:0;border-radius:10px;padding:9px 12px;cursor:pointer;font-size:12px;font-weight:600;">Errores reportados</button>' +
      '</div>' +
      '</div>';

    document.body.appendChild(wrap);

    document.getElementById('sxBugClose').onclick = closeModal;
    document.getElementById('sxBugCopy').onclick = copyText;
    document.getElementById('sxBugWa').onclick = sendToCentral;
    document.getElementById('sxBugRetry').onclick = retryAction;
    var boardBtn = document.getElementById('sxBugBoard');
    if (boardBtn) {
      if (Number(cfg.id_empresa || 0) === 169) boardBtn.style.display = 'inline-block';
      boardBtn.onclick = function () { window.open('/public/devbugs/index.php', '_blank'); };
    }
  }

  function ensureFollowupToast() {
    if (document.getElementById('sxBugFollowup')) return;
    var toast = document.createElement('div');
    toast.id = 'sxBugFollowup';
    toast.style.cssText = [
      'position:fixed',
      'left:16px',
      'right:16px',
      'bottom:12px',
      'z-index:2147483001',
      'max-width:680px',
      'margin:0 auto',
      'background:rgba(2,132,199,.96)',
      'color:#ecfeff',
      'border:1px solid rgba(125,211,252,.55)',
      'border-radius:12px',
      'box-shadow:0 16px 40px rgba(0,0,0,.35)',
      'padding:10px 12px',
      'font:600 12px/1.35 Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif',
      'transform:translateY(120%)',
      'opacity:0',
      'transition:transform .35s ease, opacity .35s ease'
    ].join(';');
    toast.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">' +
      '<div id="sxBugFollowupText" style="font-weight:600;"></div>' +
      '<button id="sxBugFollowupOk" style="background:#0f172a;color:#e0f2fe;border:1px solid rgba(125,211,252,.45);border-radius:8px;padding:6px 10px;cursor:pointer;font-size:11px;font-weight:700;">Entendido</button>' +
      '</div>';
    document.body.appendChild(toast);
    var okBtn = document.getElementById('sxBugFollowupOk');
    if (okBtn) {
      okBtn.onclick = function () {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(120%)';
      };
    }
  }

  function ensureDetectToast() {
    if (document.getElementById('sxBugDetectToast')) return;
    var toast = document.createElement('div');
    toast.id = 'sxBugDetectToast';
    toast.style.cssText = [
      'position:fixed',
      'right:14px',
      'bottom:14px',
      'z-index:2147483002',
      'max-width:min(340px,calc(100vw - 24px))',
      'background:rgba(15,23,42,.96)',
      'color:#cbd5e1',
      'border:1px solid rgba(148,163,184,.35)',
      'border-radius:10px',
      'box-shadow:0 14px 35px rgba(0,0,0,.35)',
      'padding:9px 11px',
      'font:600 12px/1.35 Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif',
      'opacity:0',
      'transform:translateY(8px)',
      'transition:opacity .2s ease, transform .2s ease',
      'pointer-events:none'
    ].join(';');
    toast.innerHTML = '<span id="sxBugDetectToastText"></span>';
    document.body.appendChild(toast);
  }

  function showDetectToast(errorText) {
    ensureDetectToast();
    var box = document.getElementById('sxBugDetectToast');
    var txt = document.getElementById('sxBugDetectToastText');
    if (!box || !txt) return;
    txt.textContent = String(errorText || 'Incidencia detectada. Enviando al equipo...');
    box.style.opacity = '1';
    box.style.transform = 'translateY(0)';
    if (state.detectToastTimer) clearTimeout(state.detectToastTimer);
    state.detectToastTimer = setTimeout(function () {
      box.style.opacity = '0';
      box.style.transform = 'translateY(8px)';
      state.detectToastTimer = null;
    }, 3000);
  }

  function playNotifySound() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = new Ctx();
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = 880;
      gain.gain.value = 0.001;
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      gain.gain.exponentialRampToValueAtTime(0.08, ctx.currentTime + 0.03);
      gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
      osc.stop(ctx.currentTime + 0.38);
    } catch (_) {}
  }

  function showFollowupNotification() {
    ensureFollowupToast();
    var box = document.getElementById('sxBugFollowup');
    var txt = document.getElementById('sxBugFollowupText');
    if (!box || !txt) return;
    var inc = ((state.lastPayload && state.lastPayload.incident) ? state.lastPayload.incident : 'N/D');
    var emp = (cfg.empresa || 'N/D');
    txt.innerHTML =
      '<div style="display:flex;flex-direction:column;gap:2px;">' +
      '<div>👋 Tu reporte fue enviado al equipo de Desarrollo de SistemaX.</div>' +
      '<div>🛠️ Ya estamos revisando lo ocurrido.</div>' +
      '<div>🧾 Referencia: <b>' + esc(inc) + '</b></div>' +
      '<div>🏢 Empresa: <b>' + esc(emp) + '</b></div>' +
      '<div style="color:#dcfce7;font-weight:800;">🙏 ¡Gracias por avisarnos y por tu paciencia!</div>' +
      '<div style="margin-top:2px;color:#e0f2fe;font-style:italic;">Att. Team Developer</div>' +
      '</div>';
    playNotifySound();
    box.style.opacity = '1';
    box.style.transform = 'translateY(0)';
  }

  function showUserMessageNotification(title, message, incident) {
    ensureFollowupToast();
    var box = document.getElementById('sxBugFollowup');
    var txt = document.getElementById('sxBugFollowupText');
    if (!box || !txt) return;
    txt.innerHTML =
      '<div style="display:flex;flex-direction:column;gap:4px;">' +
      '<div style="font-weight:800;">' + esc(title || 'Mensaje del equipo de Desarrollo') + '</div>' +
      (incident ? '<div style="font-size:11px;opacity:.9;">Incidencia: ' + esc(incident) + '</div>' : '') +
      '<div style="white-space:pre-wrap;">' + esc(message || '') + '</div>' +
      '<div style="color:#dcfce7;font-weight:800;">Att. Team Developer</div>' +
      '</div>';
    playNotifySound();
    box.style.opacity = '1';
    box.style.transform = 'translateY(0)';
  }

  function closeModal() {
    var m = document.getElementById('sxBugModal');
    if (m) m.style.display = 'none';
    state.open = false;
  }

  function clearAutoSend() {
    if (state.autoSendTimer) clearTimeout(state.autoSendTimer);
    if (state.autoSendTickTimer) clearInterval(state.autoSendTickTimer);
    state.autoSendTimer = null;
    state.autoSendTickTimer = null;
    state.autoSendRemaining = 0;
  }

  function scheduleAutoSend() {
    clearAutoSend();
    state.autoSendRemaining = 3;
    var cd = document.getElementById('sxBugCountdown');
    if (cd) cd.textContent = state.autoSendRemaining + 's';
    state.autoSendTickTimer = setInterval(function () {
      state.autoSendRemaining = Math.max(0, state.autoSendRemaining - 1);
      var c = document.getElementById('sxBugCountdown');
      if (c) c.textContent = state.autoSendRemaining + 's';
    }, 1000);
    state.autoSendTimer = setTimeout(function () {
      sendToCentral();
    }, 3000);
  }

  function makeText(p) {
    var lines = [];
    lines.push('BUG SistemaX');
    lines.push('Incidencia: ' + p.incident);
    lines.push('Empresa: ' + (cfg.empresa || 'N/D') + ' (' + (cfg.id_empresa || 'N/D') + ')');
    lines.push('Usuario: ' + (cfg.usuario || 'N/D'));
    lines.push('App: ' + (p.app || location.pathname));
    lines.push('Error: ' + (p.error || 'Error no especificado'));
    if (p.status) lines.push('HTTP: ' + p.status);
    if (p.url) lines.push('URL origen: ' + p.url);
    lines.push('Pagina: ' + location.href);
    lines.push('Fecha: ' + p.dateIso);
    if (p.extra) lines.push('Extra: ' + p.extra);
    return lines.join('\n');
  }

  function renderModal(p) {
    ensureUi();
    var modal = document.getElementById('sxBugModal');
    if (!modal) return;

    document.getElementById('sxBugSummary').innerHTML = esc(p.error || 'Error no especificado');
    document.getElementById('sxBugInc').textContent = p.incident;
    document.getElementById('sxBugEmpresa').textContent = (cfg.empresa || 'N/D') + ' (' + (cfg.id_empresa || 'N/D') + ')';
    document.getElementById('sxBugUser').textContent = cfg.usuario || 'N/D';
    document.getElementById('sxBugApp').textContent = p.app || location.pathname;
    document.getElementById('sxBugDate').textContent = p.dateText;
    document.getElementById('sxBugDetail').textContent = state.lastText || '';

    var retryBtn = document.getElementById('sxBugRetry');
    if (retryBtn) retryBtn.style.display = state.retryFn ? 'inline-block' : 'none';
    var copyBtn = document.getElementById('sxBugCopy');
    if (copyBtn) copyBtn.textContent = 'Copiar detalle';

    modal.style.display = 'block';
    state.open = true;
    scheduleAutoSend();
  }

  function report(payload, retryFn) {
    try {
      var errorText = String((payload && payload.error) || '').toLowerCase();
      var offlineNetworkError =
        !navigator.onLine && (
          errorText.indexOf('failed to fetch') !== -1 ||
          errorText.indexOf('load failed') !== -1 ||
          errorText.indexOf('networkerror') !== -1 ||
          errorText.indexOf('fallo de conexion') !== -1 ||
          errorText.indexOf('fallo de conexión') !== -1
        );
      if (offlineNetworkError) {
        return;
      }
      var p = payload || {};
      p.incident = incidentId();
      p.dateIso = new Date().toISOString();
      p.dateText = new Date().toLocaleString();
      if (!p.app) p.app = location.pathname;
      state.retryFn = typeof retryFn === 'function' ? retryFn : null;
      state.lastPayload = p;
      state.lastText = makeText(p);
      state.lastSentIncident = '';
      showDetectToast((p.error || 'Incidencia detectada') + '. Enviado automáticamente.');
      sendToCentral();
    } catch (_) {}
  }

  async function copyText() {
    if (!state.lastText) return;
    var copyBtn = document.getElementById('sxBugCopy');
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(state.lastText);
      }
      if (copyBtn) {
        copyBtn.textContent = 'Copiado';
        setTimeout(function () {
          if (copyBtn) copyBtn.textContent = 'Copiar detalle';
        }, 1600);
      }
    } catch (_) {}
  }

  function saveReportServerSide() {
    if (!state.lastPayload || state.sending) return Promise.resolve(null);
    state.sending = true;
    try {
      return fetch('/public/devbugs/api/report.php', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          incident: state.lastPayload.incident || '',
          app: state.lastPayload.app || location.pathname,
          error: state.lastPayload.error || '',
          detail: state.lastText || '',
          url: state.lastPayload.url || location.href
        })
      }).finally(function () {
        state.sending = false;
      });
    } catch (_) {}
    state.sending = false;
    return Promise.resolve(null);
  }

  function sendToCentral() {
    if (!state.lastText || !state.lastPayload) return;
    if (state.lastSentIncident === String(state.lastPayload.incident || '')) return;
    state.lastSentIncident = String(state.lastPayload.incident || '');
    clearAutoSend();
    saveReportServerSide();
  }

  function notifyAfterSend() {}

  function retryAction() {
    if (!state.retryFn) return;
    closeModal();
    try { state.retryFn(); } catch (_) {}
  }

  window.SistemaXBugReporter = { report: report };
  window.addEventListener('sistemax_bug_report_sent', function () {});
  window.addEventListener('sistemax_bug_whatsapp_sent', function () {});

  window.addEventListener('error', function (e) {
    try {
      var tgt = e && e.target;
      if (tgt && tgt !== window) {
        var src = String(tgt.src || tgt.href || '').trim();
        // Evitar falsos positivos: recursos sin URL o que apuntan a la misma página actual.
        if (!src || src === location.href || src === location.origin + location.pathname || src === location.pathname) {
          return;
        }
        // Ignorar esquemas internos/extensiones del navegador.
        if (/^(about:|chrome-extension:|moz-extension:|data:|blob:)/i.test(src)) {
          return;
        }
        // Ignorar recursos de avatar/proxy externos — tienen fallback SVG propio.
        if (/pixabay_proxy|avatar\.php|\/avatar\//i.test(src)) {
          return;
        }
        report({
          app: location.pathname,
          error: 'Error al cargar recurso',
          url: src
        });
        return;
      }
      var msg = String((e && e.message) || '').trim().toLowerCase();
      var fn = String((e && e.filename) || '').trim();
      // "Script error." sin filename es error cross-origin opaco y no diagnosticable.
      if ((msg === 'script error.' || msg === 'script error') && !fn) {
        return;
      }
      report({
        app: location.pathname,
        error: (e && e.message) || 'JS Error',
        url: fn
      });
    } catch (_) {}
  }, true);

  window.addEventListener('unhandledrejection', function (e) {
    try {
      var reason = (e && e.reason) ? (e.reason.message || String(e.reason)) : 'Promise rejection';
      var low = String(reason || '').toLowerCase();
      var isGenericNetworkReject =
        low.indexOf('load failed') !== -1 ||
        low.indexOf('failed to fetch') !== -1 ||
        low.indexOf('networkerror') !== -1;
      var recentIgnoredFetch = (Date.now() - Number(state.lastIgnoredFetchAt || 0)) < 2500;
      if (isGenericNetworkReject && recentIgnoredFetch) {
        return;
      }
      report({ app: location.pathname, error: reason });
    } catch (_) {}
  });

  if (typeof window.fetch === 'function') {
    var _fetch = window.fetch.bind(window);
    function shouldSkipFetchReport(reqUrl) {
      var u = String(reqUrl || '');
      if (!u) return false;
      // Agent local (health/printers/print) puede fallar por permisos/CORS/HTTPS mixed content.
      // No debe reportarse como bug funcional del sistema.
      if (/^https?:\/\/(127\.0\.0\.1|localhost):17890\//i.test(u)) {
        return true;
      }
      // Evitar bucles de auto-reporte en el propio Centro de Bug.
      if (location.pathname === '/public/devbugs/index.php' && u.indexOf('/public/devbugs/api/') !== -1) {
        return true;
      }
      // Nunca auto-reportar errores de los endpoints de devbugs (evita loops recursivos).
      if (u.indexOf('/public/devbugs/api/') !== -1) {
        return true;
      }
      // Polling interno de notificaciones: no reportar cortes transitorios.
      if (u.indexOf('/public/devbugs/api/pull_notification.php') !== -1) {
        return true;
      }
      // Polling del menú (notificaciones operativas): puede fallar transitoriamente
      // por red/sesión sin implicar bug funcional de la app principal.
      if (u.indexOf('/public/menu/api/notificaciones.php') !== -1) {
        return true;
      }
      // Polling/chat embebido del menú: fallos de red transitorios no deben
      // abrir incidencias de la app principal.
      if (u.indexOf('/public/menu/api/chat.php') !== -1) {
        return true;
      }
      return false;
    }
    window.fetch = function () {
      var args = arguments;
      var reqUrl = '';
      try { reqUrl = String((args[0] && (args[0].url || args[0])) || ''); } catch (_) {}

      var retry = function () {
        return _fetch.apply(null, args);
      };

      return _fetch.apply(null, args).then(function (res) {
        try {
          if (!res.ok) {
            if (!shouldSkipFetchReport(reqUrl)) {
              report({
                app: location.pathname,
                error: 'La solicitud devolvio error HTTP',
                status: res.status + ' ' + res.statusText,
                url: res.url || reqUrl
              }, retry);
            }
          } else {
            var ctype = (res.headers && res.headers.get && res.headers.get('content-type')) || '';
            if (/\/api\//i.test(reqUrl) && ctype && ctype.indexOf('application/json') === -1) {
              if (!shouldSkipFetchReport(reqUrl)) {
                report({
                  app: location.pathname,
                  error: 'La API respondio en formato no JSON',
                  url: res.url || reqUrl
                }, retry);
              }
            }
          }
        } catch (_) {}
        return res;
      }).catch(function (err) {
        try {
          var msg = String((err && err.message) || '').toLowerCase();
          var offlineNetworkError =
            !navigator.onLine && (
              msg.indexOf('failed to fetch') !== -1 ||
              msg.indexOf('load failed') !== -1 ||
              msg.indexOf('networkerror') !== -1
            );
          var isAbortLike =
            (err && err.name === 'AbortError') ||
            msg.indexOf('aborted') !== -1 ||
            msg.indexOf('abort') !== -1 ||
            msg.indexOf('signal is aborted without reason') !== -1;
          if (isAbortLike || offlineNetworkError) {
            state.lastIgnoredFetchAt = Date.now();
            state.lastIgnoredFetchUrl = reqUrl;
          } else if (!shouldSkipFetchReport(reqUrl)) {
            report({
              app: location.pathname,
              error: (err && err.message) || 'Fallo de conexion',
              url: reqUrl
            }, retry);
          } else {
            state.lastIgnoredFetchAt = Date.now();
            state.lastIgnoredFetchUrl = reqUrl;
          }
        } catch (_) {}
        throw err;
      });
    };
  }

  function startInboxPolling() {}
})();
