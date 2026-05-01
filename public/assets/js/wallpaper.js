/**
 * Sistema de Wallpapers Dinámicos - Unsplash
 * Gestiona fondos de pantalla rotativos por día según tema dark/light
 */

// Configuración de Unsplash
const UNSPLASH_CONFIG = {
  collectionDark: "u1igKExyV9U", // Colección de imágenes oscuras
  collectionLight: "3eqsIw3rtvs", // Colección de imágenes claras
  clientId: "G2YBNixS3-GF5gu3e3_wWMfooMLglcUqivd1pErn8xI",
};

/**
 * Obtener imagen aleatoria de Unsplash
 */
async function obtenerImagenAleatoria(collectionIdO, collectionIdC, clientId) {
  try {
    // Verificar si ya hay imágenes guardadas del día
    const lastUpdate = localStorage.getItem("wallpaper_date");
    const today = new Date().toISOString().split("T")[0];
    const urlImagenO = localStorage.getItem("urlImagenOscuro");
    const urlImagenC = localStorage.getItem("urlImagenClaro");

    if (lastUpdate === today && urlImagenO && urlImagenC) {
      // Usar imágenes guardadas del día
      aplicarWallpaper(urlImagenO, urlImagenC);
      return;
    }

    // Obtener nuevas imágenes de Unsplash
    const [responseO, responseC] = await Promise.all([
      fetch(
        `https://api.unsplash.com/photos/random?collections=${collectionIdO}&client_id=${clientId}`,
      ),
      fetch(
        `https://api.unsplash.com/photos/random?collections=${collectionIdC}&client_id=${clientId}`,
      ),
    ]);

    if (!responseO.ok || !responseC.ok) {
      console.warn("Error obteniendo wallpapers de Unsplash");
      return;
    }

    const dataO = await responseO.json();
    const dataC = await responseC.json();

    const newUrlImagenO = dataO.urls.regular;
    const newUrlImagenC = dataC.urls.regular;

    // Guardar en localStorage
    localStorage.setItem("urlImagenOscuro", newUrlImagenO);
    localStorage.setItem("urlImagenClaro", newUrlImagenC);
    localStorage.setItem("wallpaper_date", today);

    aplicarWallpaper(newUrlImagenO, newUrlImagenC);
  } catch (error) {
    console.error("Error al obtener wallpapers:", error);
  }
}

/**
 * Aplicar wallpaper según tema actual
 */
function aplicarWallpaper(urlOscuro, urlClaro) {
  if (!urlOscuro || !urlClaro) return;

  const tema = localStorage.getItem("theme");
  const isDark =
    tema === "dark" ||
    (!tema && document.documentElement.classList.contains("dark")) ||
    (!tema && window.matchMedia("(prefers-color-scheme: dark)").matches);

  const urlToUse = isDark ? urlOscuro : urlClaro;
  document.body.style.backgroundImage = `url(${urlToUse})`;
}

/**
 * Inicializar sistema de wallpapers
 */
function initWallpapers() {
  // Primero intentar aplicar wallpaper guardado
  const urlOscuro = localStorage.getItem("urlImagenOscuro");
  const urlClaro = localStorage.getItem("urlImagenClaro");

  if (urlOscuro && urlClaro) {
    aplicarWallpaper(urlOscuro, urlClaro);
  }

  // Luego verificar/actualizar si es necesario
  obtenerImagenAleatoria(
    UNSPLASH_CONFIG.collectionDark,
    UNSPLASH_CONFIG.collectionLight,
    UNSPLASH_CONFIG.clientId,
  );
}

/**
 * Actualizar wallpaper cuando cambia el tema
 */
function onThemeChange() {
  const urlOscuro = localStorage.getItem("urlImagenOscuro");
  const urlClaro = localStorage.getItem("urlImagenClaro");

  if (urlOscuro && urlClaro) {
    aplicarWallpaper(urlOscuro, urlClaro);
  }
}

// Auto-inicializar cuando el DOM esté listo
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initWallpapers);
} else {
  initWallpapers();
}

// Exportar para uso externo
window.wallpaperManager = {
  init: initWallpapers,
  apply: aplicarWallpaper,
  refresh: obtenerImagenAleatoria,
  onThemeChange: onThemeChange,
};
