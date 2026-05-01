(function () {
  'use strict';

  if (window.__SISTEMAX_OFFLINE_SHELL_INIT__) return;
  window.__SISTEMAX_OFFLINE_SHELL_INIT__ = true;

  var cfg = window.__SISTEMAX_OFFLINE_CONFIG__ || {};
  var BADGE_ID = 'internetStatusBadge';
  var STYLE_ID = 'smxInternetStatusBadgeStyle';
  var swRegistration = null;
  var WIFI_ICON = '<span class="smx-internet-status-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M2.3 8.7a15.4 15.4 0 0 1 19.4 0l-1.8 2.1a12.5 12.5 0 0 0-15.8 0L2.3 8.7Zm3.7 4.2a9.7 9.7 0 0 1 12 0l-1.8 2.1a6.8 6.8 0 0 0-8.4 0L6 12.9Zm3.7 4.1a4.1 4.1 0 0 1 4.6 0L12 19.2l-2.3-2.2Zm1.1 3.2a1.2 1.2 0 1 1 2.4 0 1.2 1.2 0 0 1-2.4 0Z" fill="currentColor"/></svg></span>';
  var WIFI_OFF_ICON = '<span class="smx-internet-status-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M2.3 8.7a15.4 15.4 0 0 1 14.7-2.5l-2.2 2.2a12.4 12.4 0 0 0-10.7 1.3L2.3 8.7Zm17.4 0 2 2.1-3 3a9.6 9.6 0 0 0-1.9-2.9l2.9-2.2ZM6 12.9a9.6 9.6 0 0 1 4.6-2.2l-2 2a6.8 6.8 0 0 0-.8.2L6 12.9Zm6 6.3-2.3-2.2a4 4 0 0 1 1.3-.3l1-1a4.2 4.2 0 0 1 2.3.6l-2.3 2.9Zm7.7 2.2L3.1 4.8l1.4-1.4 16.6 16.6-1.4 1.4Z" fill="currentColor"/></svg></span>';

  function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = [
      '#' + BADGE_ID + '{position:fixed;left:12px;bottom:max(12px, env(safe-area-inset-bottom));z-index:100000;display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;color:rgba(229,231,235,.48);user-select:none;pointer-events:none;}',
      '#' + BADGE_ID + ' .smx-internet-status-icon{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;flex:0 0 18px;}',
      '#' + BADGE_ID + ' .smx-internet-status-icon svg{display:block;width:18px;height:18px;}',
      '#' + BADGE_ID + '.is-online{color:rgba(34,197,94,.58);}',
      '#' + BADGE_ID + '.is-offline{color:rgba(156,163,175,.38);}'
    ].join('');
    document.head.appendChild(style);
  }

  function ensureBadge() {
    var badge = document.getElementById(BADGE_ID);
    if (badge) return badge;
    ensureStyle();
    badge = document.createElement('div');
    badge.id = BADGE_ID;
    badge.className = 'is-offline';
    badge.setAttribute('aria-live', 'polite');
    badge.innerHTML = WIFI_OFF_ICON;
    document.body.appendChild(badge);
    return badge;
  }

  function updateBadge() {
    if (!document.body) return;
    var badge = ensureBadge();
    var online = !!navigator.onLine;
    badge.innerHTML = online ? WIFI_ICON : WIFI_OFF_ICON;
    badge.classList.toggle('is-online', online);
    badge.classList.toggle('is-offline', !online);
    badge.setAttribute('title', online ? 'Conexion disponible' : 'Sin conexion a internet');
  }

  function installOfflineFetchGuard() {
    window.addEventListener('unhandledrejection', function (event) {
      var reason = event && event.reason;
      var message = '';
      if (typeof reason === 'string') {
        message = reason;
      } else if (reason && typeof reason.message === 'string') {
        message = reason.message;
      }
      if (!navigator.onLine && /failed to fetch/i.test(message)) {
        event.preventDefault();
      }
    });
  }

  function uniqueUrls(urls) {
    var out = [];
    var seen = {};
    (urls || []).forEach(function (url) {
      url = String(url || '').trim();
      if (!url || seen[url]) return;
      seen[url] = true;
      out.push(url);
    });
    return out;
  }

  function buildPrecacheUrls() {
    var urls = [];
    urls.push('/public/login.php');
    urls.push('/public/offline.html');
    urls.push('/public/manifest.json');
    urls.push('/public/menu/menu.php');
    if (window.location && window.location.pathname) {
      urls.push(window.location.pathname + (window.location.search || ''));
    }
    if (Array.isArray(cfg.precacheUrls)) {
      urls = urls.concat(cfg.precacheUrls);
    }
    return uniqueUrls(urls);
  }

  function normalizePrecacheUrls(urls) {
    var out = [];
    (urls || []).forEach(function (input) {
      try {
        var raw = String(input || '').trim();
        if (!raw) return;
        var url = new URL(raw, window.location.origin);
        if (url.origin !== window.location.origin) return;
        if (!url.pathname.startsWith('/public/')) return;
        out.push(url.pathname + url.search);
      } catch (_) {}
    });
    return uniqueUrls(out);
  }

  async function precacheUrls(urls) {
    try {
      var normalized = normalizePrecacheUrls(urls);
      if (!normalized.length) return false;
      var reg = swRegistration || await registerServiceWorker();
      if (!reg) return false;
      var payload = { type: 'PRECACHE_URLS', urls: normalized };
      if (reg.active) {
        reg.active.postMessage(payload);
        return true;
      }
      if (navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage(payload);
        return true;
      }
    } catch (_) {}
    return false;
  }

  async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return null;
    try {
      var reg = await navigator.serviceWorker.register('/public/sw.js', { scope: '/public/' });
      await navigator.serviceWorker.ready;
      var activeReg = reg || await navigator.serviceWorker.ready;
      swRegistration = activeReg || reg || null;
      var payload = { type: 'PRECACHE_URLS', urls: buildPrecacheUrls() };
      if (activeReg && activeReg.active) {
        activeReg.active.postMessage(payload);
      } else if (navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage(payload);
      }
      return activeReg;
    } catch (err) {
      console.warn('[PWA] No se pudo registrar SW global:', err);
      return null;
    }
  }

  function init() {
    updateBadge();
    installOfflineFetchGuard();
    window.SmxOfflineShell = window.SmxOfflineShell || {};
    window.SmxOfflineShell.precacheUrls = precacheUrls;
    window.addEventListener('online', updateBadge);
    window.addEventListener('offline', updateBadge);
    registerServiceWorker();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
