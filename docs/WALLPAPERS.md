# Sistema de Wallpapers Dinámicos - Video Pixabay

Sistema de fondos de pantalla con video dinámico integrado en las páginas de autenticación de \_\_v1.

## 🎬 Características

### Video Wallpaper Rotativo

- **Actualización Diaria**: Se obtiene un nuevo video de Pixabay cada día
- **Cache Local**: El video se guarda en localStorage para evitar llamadas repetidas a la API
- **Deprecación Automática**: El video del día anterior se elimina automáticamente
- **Fallback Graceful**: Si el video falla, se muestra el gradiente de fondo

### API de Pixabay

- **Endpoint**: `https://pixabay.com/api/videos/`
- **Calidad**: Videos en calidad medium (balance tamaño/calidad)
- **Filtros**: SafeSearch activado, mínimo 1280px de ancho

### Rotación de Temas de Video

Los videos rotan diariamente entre estas categorías:
1. `nature+landscape` - Paisajes naturales
2. `abstract+technology` - Tecnología abstracta
3. `ocean+waves` - Océano y olas
4. `night+sky+stars` - Cielo nocturno
5. `forest+trees` - Bosques
6. `city+lights+night` - Ciudad nocturna
7. `clouds+sky+timelapse` - Timelapse de nubes
8. `water+abstract` - Agua abstracta
9. `aurora+borealis` - Aurora boreal
10. `sunset+mountains` - Atardeceres

## 📁 Archivos Modificados

### 1. `/public/login.php`

**Cambios:**

- ✅ Video de fondo con autoplay, muted y loop
- ✅ Overlay degradado para contraste del contenido
- ✅ Sistema de cache en localStorage con fecha
- ✅ Fallback a gradiente si el video falla

**HTML agregado:**

```html
<video id="video-background" autoplay muted loop playsinline>
    <source src="" type="video/mp4">
</video>
<div id="video-overlay"></div>
```

**CSS agregado:**

```css
#video-background {
    position: fixed;
    top: 50%;
    left: 50%;
    min-width: 100%;
    min-height: 100%;
    transform: translate(-50%, -50%);
    z-index: -2;
    object-fit: cover;
    opacity: 0;
    transition: opacity 1s ease-in-out;
}

#video-background.loaded {
    opacity: 1;
}

#video-overlay {
    position: fixed;
    inset: 0;
    z-index: -1;
    background: linear-gradient(...);
}
```

**JavaScript:**

```javascript
async function obtenerVideoDelDia() {
    // Verifica cache del día
    // Obtiene nuevo video de Pixabay si es necesario
    // Aplica video como fondo
}
```

## 🔄 Sistema Deprecado (Unsplash)

El sistema anterior usaba imágenes estáticas de Unsplash:
- ~~Collection ID Oscuro: `u1igKExyV9U`~~
- ~~Collection ID Claro: `3eqsIw3rtvs`~~
- ~~API Key Unsplash~~

**Razón del cambio**: Videos proporcionan una experiencia más inmersiva y moderna.

## 🗄️ Estructura del Cache

```javascript
// localStorage key: 'pixabay_video_cache'
{
    "date": "2026-2-9",      // Fecha del video (YYYY-M-D)
    "videoUrl": "https://...", // URL del video MP4
    "query": "nature+landscape", // Query usado
    "videoId": 123456        // ID del video en Pixabay
}
```

## ⚠️ Consideraciones

1. **Autoplay**: Los navegadores modernos requieren `muted` para autoplay
2. **Rendimiento**: Videos en calidad medium (~720p) para balance
3. **Fallback**: Si falla la API o el video, se usa gradiente CSS
4. **Mobile**: `playsinline` para iOS, evita pantalla completa automática

```html
<link rel="stylesheet" href="assets/css/wallpaper.css" />
<body class="wallpaper-enabled"></body>
```

## 🔄 Flujo de Funcionamiento

### 1. Primera Carga del Día

```
Usuario accede → login.php
  ↓
Verifica localStorage.wallpaper_date
  ↓
No coincide con hoy → Llamada a Unsplash API
  ↓
Obtiene 2 imágenes (dark + light)
  ↓
Guarda en localStorage:
  - urlImagenOscuro
  - urlImagenClaro
  - wallpaper_date: 2026-02-03
  ↓
Aplica según tema actual
```

### 2. Cargas Posteriores del Mismo Día

```
Usuario accede → forgot-password.php
  ↓
Verifica localStorage.wallpaper_date
  ↓
Coincide con hoy → Usa cache
  ↓
Lee urlImagenOscuro y urlImagenClaro
  ↓
Aplica según tema actual (sin API call)
```

### 3. Cambio de Tema

```
Usuario cambia a Dark Mode
  ↓
Detecta cambio de tema
  ↓
Lee localStorage.urlImagenOscuro
  ↓
Aplica con transición suave
```

## 📦 LocalStorage Schema

```javascript
{
  "wallpaper_date": "2026-02-03",           // Fecha de última actualización
  "urlImagenOscuro": "https://...",          // URL imagen modo oscuro
  "urlImagenClaro": "https://...",           // URL imagen modo claro
  "theme": "dark"                            // Tema actual del usuario
}
```

## 🎯 Ventajas del Sistema

1. **Optimización**: Solo 1 llamada a la API por día
2. **Consistencia**: Mismo wallpaper en todas las páginas de auth
3. **Performance**: Cache local en localStorage
4. **UX**: Transiciones suaves entre temas
5. **Escalabilidad**: Fácil agregar más páginas

## 🔧 Configuración

### Cambiar Colecciones de Unsplash

Editar en `wallpaper.js`:

```javascript
const UNSPLASH_CONFIG = {
  collectionDark: "TU_COLECCION_OSCURA",
  collectionLight: "TU_COLECCION_CLARA",
  clientId: "TU_API_KEY",
};
```

### Desactivar Wallpapers

Opción 1 - Por página:

```javascript
// Comentar la llamada a initWallpapers()
```

Opción 2 - Forzar color sólido:

```css
body {
  background-image: none !important;
  background: linear-gradient(...);
}
```

## 🐛 Troubleshooting

### Wallpaper no se carga

1. Verificar consola del navegador para errores de API
2. Revisar que la API key de Unsplash sea válida
3. Verificar localStorage no esté lleno
4. Comprobar permisos CORS

### Wallpaper incorrecto para el tema

```javascript
// Forzar recarga
localStorage.removeItem("wallpaper_date");
location.reload();
```

### Rendimiento lento

- Las imágenes se cargan en background
- Usar `urls.regular` en vez de `urls.full` para menor tamaño
- Implementar lazy loading si es necesario

## 📊 Métricas de Unsplash API

- **Límite Free**: 50 requests/hora
- **Nuestro uso**: ~1 request/día por usuario
- **Colecciones**: Públicas, sin autenticación extra requerida

## 🚀 Próximas Mejoras

- [ ] Panel de admin para cambiar colecciones
- [ ] Guardar wallpapers en base de datos (tabla `apps`)
- [ ] Opción para que usuario suba su propio wallpaper
- [ ] Previsualización de wallpaper antes de aplicar
- [ ] Blur opcional para mejor contraste
- [ ] Múltiples colecciones rotativas

## 📝 Notas Técnicas

- Compatible con todos los navegadores modernos
- Fallback a gradiente sólido si API falla
- No bloquea renderizado de página
- CSS optimizado para performance
- JavaScript vanilla (sin dependencias)

---

**Implementado por:** GitHub Copilot  
**Fecha:** 3 de febrero de 2026  
**Basado en:** Sistema de sec_login/sec_login.php
