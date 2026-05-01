(function () {
  'use strict';

  if (window.SmxOfflineDb) return;

  var DB_NAME = 'sistemax_offline_v1';
  var STORE_NAME = 'kv';
  var cache = new Map();
  var dbRef = null;
  var LOCAL_MIGRATION_PREFIXES = [
    'sx_productos_offline:',
    'sx_productos_mobile_offline:',
    'sx_inventario_offline:',
    'sx_inventario_mobile_offline:',
    'smx:compras:',
    'smx:gastos:'
  ];

  function shouldMigrate(key) {
    key = String(key || '');
    return LOCAL_MIGRATION_PREFIXES.some(function (prefix) {
      return key.indexOf(prefix) === 0;
    });
  }

  function openDb() {
    return new Promise(function (resolve, reject) {
      if (!('indexedDB' in window)) {
        reject(new Error('IndexedDB no disponible'));
        return;
      }
      var req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          db.createObjectStore(STORE_NAME, { keyPath: 'key' });
        }
      };
      req.onsuccess = function () {
        dbRef = req.result;
        resolve(dbRef);
      };
      req.onerror = function () {
        reject(req.error || new Error('No se pudo abrir IndexedDB'));
      };
    });
  }

  function transaction(mode, handler) {
    return new Promise(function (resolve, reject) {
      if (!dbRef) {
        reject(new Error('IndexedDB no inicializada'));
        return;
      }
      var tx = dbRef.transaction(STORE_NAME, mode);
      var store = tx.objectStore(STORE_NAME);
      tx.oncomplete = function () { resolve(); };
      tx.onerror = function () { reject(tx.error || new Error('Transaccion IndexedDB fallida')); };
      tx.onabort = function () { reject(tx.error || new Error('Transaccion IndexedDB abortada')); };
      handler(store);
    });
  }

  function loadCache() {
    return transaction('readonly', function (store) {
      store.openCursor().onsuccess = function (event) {
        var cursor = event.target.result;
        if (!cursor) return;
        var row = cursor.value || {};
        if (row.key) cache.set(String(row.key), row.value);
        cursor.continue();
      };
    });
  }

  function persist(key, value) {
    if (!dbRef) return Promise.resolve();
    return transaction('readwrite', function (store) {
      store.put({ key: String(key), value: value, updated_at: Date.now() });
    });
  }

  function removePersisted(key) {
    if (!dbRef) return Promise.resolve();
    return transaction('readwrite', function (store) {
      store.delete(String(key));
    });
  }

  function migrateLocalStorage() {
    try {
      for (var i = 0; i < localStorage.length; i += 1) {
        var key = localStorage.key(i);
        if (!shouldMigrate(key) || cache.has(String(key))) continue;
        try {
          var raw = localStorage.getItem(key);
          if (raw == null) continue;
          cache.set(String(key), JSON.parse(raw));
        } catch (_) {}
      }
    } catch (_) {}
    var writes = [];
    cache.forEach(function (value, key) {
      writes.push(persist(key, value));
    });
    return Promise.all(writes).catch(function () {});
  }

  var ready = openDb()
    .then(function () { return loadCache(); })
    .then(function () { return migrateLocalStorage(); })
    .catch(function (err) {
      console.warn('[OfflineDB] fallback sin IndexedDB:', err);
    });

  window.SmxOfflineDb = {
    ready: ready,
    getSync: function (key, fallback) {
      key = String(key || '');
      if (cache.has(key)) return cache.get(key);
      try {
        var raw = localStorage.getItem(key);
        if (raw != null) {
          var parsed = JSON.parse(raw);
          cache.set(key, parsed);
          return parsed;
        }
      } catch (_) {}
      return fallback;
    },
    get: function (key, fallback) {
      return ready.then(function () {
        return window.SmxOfflineDb.getSync(key, fallback);
      });
    },
    set: function (key, value) {
      key = String(key || '');
      cache.set(key, value);
      return persist(key, value).catch(function () {
        try {
          localStorage.setItem(key, JSON.stringify(value));
        } catch (_) {}
      });
    },
    remove: function (key) {
      key = String(key || '');
      cache.delete(key);
      return removePersisted(key).catch(function () {
        try {
          localStorage.removeItem(key);
        } catch (_) {}
      });
    },
    entriesSync: function (prefix) {
      prefix = String(prefix || '');
      var rows = [];
      cache.forEach(function (value, key) {
        if (prefix && key.indexOf(prefix) !== 0) return;
        rows.push({
          key: key,
          value: value,
          size: (function () {
            try { return JSON.stringify(value).length; } catch (_) { return 0; }
          })()
        });
      });
      rows.sort(function (a, b) {
        return String(a.key).localeCompare(String(b.key));
      });
      return rows;
    },
    entries: function (prefix) {
      return ready.then(function () {
        return window.SmxOfflineDb.entriesSync(prefix);
      });
    },
    clearPrefix: function (prefix) {
      prefix = String(prefix || '');
      var keys = [];
      cache.forEach(function (_, key) {
        if (!prefix || key.indexOf(prefix) === 0) keys.push(key);
      });
      return Promise.all(keys.map(function (key) {
        return window.SmxOfflineDb.remove(key);
      }));
    }
  };
})();
