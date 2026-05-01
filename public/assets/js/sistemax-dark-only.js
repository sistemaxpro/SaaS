(function () {
  'use strict';

  if (window.__SMX_DARK_ONLY__) return;
  window.__SMX_DARK_ONLY__ = true;

  function forceDark() {
    try {
      var el = document.documentElement;
      if (!el) return;
      el.classList.add('dark');
      el.setAttribute('data-bs-theme', 'dark');
      el.setAttribute('data-theme', 'dark');
      el.style.colorScheme = 'dark';
      localStorage.setItem('theme', 'dark');
    } catch (_) {}
  }

  forceDark();
  document.addEventListener('DOMContentLoaded', forceDark);
  window.addEventListener('focus', forceDark);
  window.addEventListener('storage', function (e) {
    if (!e || e.key === 'theme') forceDark();
  });
})();
