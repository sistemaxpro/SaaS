# SistemaX PRO — Instrucciones para Asistente IA (vcorta)

> Este archivo contiene el contexto completo del proyecto para que el asistente IA
> pueda ayudar de forma precisa sin repetir preguntas ni cometer errores de arquitectura.

---

## 1. VISIÓN GENERAL

**SistemaX PRO** es un sistema de gestión comercial/ERP multi-empresa para Paraguay.
Incluye punto de venta (POS), facturación electrónica SIFEN, gestión de productos,
compras, cajas, taller mecánico, contactos, usuarios/roles y suscripciones SaaS.

- **Dominio:** `sistemax.pro`
- **Servidor:** Linux, nginx, PHP 8.x-fpm, MySQL/MariaDB
- **IP DB:** `168.231.95.50`
- **Ruta raíz:** `/var/www/html/sistemaxpro`
- **Zona horaria:** `America/Asuncion` (Paraguay)
- **Origen:** Migrado desde ScriptCase hacia código PHP manual

---

## 2. ARQUITECTURA

### Patrón
Monolito PHP modular con patrón **"PHP Pages + API endpoints"** (NO MVC clásico).
Cada módulo es un archivo PHP autocontenido (página + lógica + vista HTML) con
endpoints API separados en subcarpeta `api/`.

### Multi-Tenancy
Modelo **1 Master DB (`serproc1`) + 1 DB por empresa**:
- Master DB: contiene `empresa`, `sec_users`, `sec_groups`, `sec_groups_apps`, `habilitacion_sifen`
- Cada empresa tiene su `dbase` propio (ej: `empresa_169`) con tablas de negocio

### Flujo de Request
```
index.php → /public/index.php (landing)
Login → /public/login.php → Auth::login() → Session::setEmpresa() + Session::setUser()
Menú → /public/menu/menu.php (dashboard principal)
Módulo → /public/{módulo}/index.php (o mobile.php)
API → /public/{módulo}/api/{recurso}.php?action={acción}
```

### Bootstrap (cada página)
```php
require_once __DIR__ . '/../../config/bootstrap.php';  // Carga todo
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_name');
$permisos = Permission::getAppPermissions('app_name');
```

---

## 3. STACK TECNOLÓGICO

| Capa | Tecnología |
|------|-----------|
| **Backend** | PHP 8.x (str_contains, arrow functions, named args) |
| **DB** | MySQL/MariaDB vía PDO (prepared statements, utf8mb4_unicode_ci) |
| **Frontend JS** | Alpine.js 3.x (reactividad), AG Grid Enterprise v32.3.3 (grids) |
| **CSS** | Tailwind CSS (CDN cdn.tailwindcss.com), dark mode vía class strategy |
| **Fuentes** | Inter, Poppins, Font Awesome 6.5.1, Heroicons |
| **Build** | PostCSS + Tailwind (package.json), sin bundler JS |
| **Impresión** | Agente Go local (`sistemax-agent`) + ESC/POS + tickets HTML/PDF |
| **PWA** | Service Worker (cache: `sistemax-v2`) + manifest.json |
| **Almacenamiento** | Cloudflare R2 (imágenes), Google Drive (OAuth 2.0) |
| **IA** | OpenAI GPT, ElevenLabs (voz multilingual v2) |
| **FE Paraguay** | SDK php-sifen3 + certificados .p12 |

---

## 4. ESTRUCTURA DE CARPETAS

```
/var/www/html/sistemaxpro/
├── config/                    # Configuración global
│   ├── bootstrap.php          # Inicializador (carga todo)
│   ├── database.php           # Clase Database (Master + Empresa)
│   ├── session.php            # Clase Session (multi-empresa)
│   ├── r2.php                 # Cloudflare R2 storage
│   ├── openai.php             # Config OpenAI
│   ├── elevenlabs.php         # Config ElevenLabs (voz IA)
│   └── google_drive.php       # Config Google Drive
├── src/                       # Clases Core del sistema
│   ├── Core/
│   │   ├── Auth.php           # Autenticación (login, remember, bcrypt+md5+plain)
│   │   ├── MultiTenant.php    # Multi-empresa (empresa actual, switch)
│   │   ├── Permission.php     # Permisos RBAC por app_name
│   │   ├── Security.php       # CSRF, rate limiting, IP blocking
│   │   └── Response.php       # Respuestas JSON estandarizadas
│   ├── Services/
│   │   ├── R2StorageService.php       # Upload imágenes a Cloudflare R2
│   │   └── ImageVariantService.php    # Variantes: thumb/small/medium/large WebP
│   └── Modules/               # Módulos backend (Empresas, Products, Sales, Users)
├── public/                    # Archivos accesibles via web
│   ├── login.php              # Login (detecta mobile)
│   ├── menu/menu.php          # Dashboard/menú principal (~5700 líneas)
│   ├── pos/                   # Punto de Venta
│   │   ├── index.php          # POS desktop (~9400+ líneas, Alpine.js)
│   │   ├── mobile.php         # POS mobile (cards HTML, sin AG Grid)
│   │   ├── api/               # APIs: venta, productos, direct_print, sifen, etc.
│   │   ├── ticket.php         # Ticket HTML (vista web/PDF)
│   │   ├── ticket_nota_comun.php          # Ticket ESC/POS nota de control
│   │   ├── ticket_factura_autoimpresa.php # Ticket ESC/POS autoimpresa
│   │   └── ticket_factura_electronica.php # Ticket ESC/POS electrónica
│   ├── ventas/                # Gestión de ventas (grid + mobile)
│   ├── productos/             # CRUD productos, stock, imágenes
│   ├── cajas/                 # Gestión cajas, timbrados
│   ├── compras/               # Registro de compras
│   ├── contactos/             # CRUD clientes
│   ├── usuarios/              # Gestión usuarios y permisos
│   ├── taller/                # Taller mecánico (órdenes, vehículos, servicios)
│   ├── empresa/               # Config empresa, FE dashboard, SIFEN
│   ├── sucursales/            # Gestión sucursales
│   ├── balanzas/              # Integración balanzas
│   ├── panel/                 # Panel de control
│   ├── cuentas/               # Cuentas contables
│   ├── shared/                # Utilidades compartidas (schema_module_compat.php)
│   ├── _lib/                  # Librerías: AG Grid, php-sifen3
│   ├── api/v1/                # API pública (auth, suscripciones)
│   ├── assets/                # CSS, JS, imágenes
│   ├── sw.js                  # Service Worker PWA
│   └── manifest.json          # PWA manifest
├── modelos/                   # Backend legacy (facturas, SIFEN, NC/ND, KuDE)
│   ├── facturas_sifen.php     # Emisión factura electrónica
│   ├── nc_sifen.php           # Nota de crédito electrónica
│   ├── nd_sifen.php           # Nota de débito electrónica
│   ├── emitir_nota.php        # Emisión nota de control
│   ├── kude_ticket.php        # KuDE (representación gráfica SIFEN)
│   ├── kude_a4.php            # KuDE A4
│   ├── anular.php             # Anulación de facturas
│   └── vehiculos.php          # CRUD vehículos
├── agent/                     # Agente de impresión Go multiplataforma
│   ├── main.go                # Server HTTP :17890, impresión CUPS
│   ├── go.mod                 # Go 1.23, golang.org/x/sys
│   └── {linux,macos,windows,android}/
├── cron/                      # Tareas programadas
│   ├── worker_sifen_queue.php # Cola SIFEN
│   ├── renovar_suscripciones.php
│   └── verificar_vencimientos.php
├── database/migrations/       # SQL migrations (001-010+)
├── scripts/                   # Scripts utilidad (build APK, migrate, etc.)
├── docs/                      # Documentación (SIFEN, Bancard, Android, etc.)
└── apps/                      # App Android (sistemaxpro-android/)
```

---

## 5. PATRONES DE CÓDIGO

### Patrón de Página (módulo v1)
```php
<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_xxx');
$permisos = Permission::getAppPermissions('app_grid_xxx');
$id_empresa = Session::getIdEmpresa();
$dbu = Session::getDbase();
$masterPdo = Database::getMasterConnection();
$pdo = Database::getSessionEmpresaConnection();
?>
<!DOCTYPE html>
<html lang="es" x-data="moduloApp()" x-init="init()" :class="{ 'dark': darkMode }">
<!-- Tailwind CDN + Alpine.js CDN + AG Grid local -->
<script>
window.__PERMISOS__ = <?= json_encode($permisos) ?>;
function moduloApp() {
    return {
        darkMode: localStorage.getItem('darkMode') === 'true',
        items: [],
        loading: false,
        init() { this.loadData(); },
        async loadData() { /* fetch API */ }
    };
}
</script>
```

### Patrón de API
```php
<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);
require_once __DIR__ . '/../../../config/bootstrap.php';
// Input
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
// Sesión
if (!Session::isLoggedIn()) { /* error 401 */ }
$pdo = Database::getSessionEmpresaConnection();
// Router
switch ($action) {
    case 'list': /* ... */ break;
    case 'save': /* ... */ break;
    default: http_response_code(400); echo json_encode(['success'=>false]);
}
```

### Convenciones Alpine.js
- Función global: `function moduloApp() { return { ... } }` (camelCase)
- Init: `x-data="moduloApp()" x-init="init()"`
- Dark mode: `localStorage.getItem('darkMode') === 'true'` + clase `dark` en `<html>`
- FOUC: `[x-cloak] { display: none !important; }`
- Permisos frontend: `window.__PERMISOS__` inyectado por PHP

### Long-press (POS)
Sistema implementado para fijar método de pago/tipo documento por defecto:
- `startLongPress(type, id)` / `endLongPress()` / `wasLongPress()` con timer 600ms
- `_lpTimer` y `_lpFired` como flags internos
- Vibración en mobile, badge "⭐ FIJADO" visual
- Persiste en `localStorage` (`pos_defaultPayment`, `pos_defaultDocType`)

---

## 6. SISTEMA DE IMPRESIÓN

### 3 Capas
1. **Agente Go local** (`sistemax-agent` v0.1.4): HTTP en `127.0.0.1:17890`
   - `/health`, `/printers`, `/print`, `/raw-print`
   - Recibe ESC/POS base64, envía raw a CUPS
   - CORS con `Access-Control-Allow-Private-Network: true`
2. **Impresión directa CUPS**: `public/pos/api/direct_print.php`
   - `lp -d {printer} -o raw` para enviar ESC/POS
3. **Ticket HTML/PDF**: `public/pos/ticket.php` (vista web, `?pdf=1`, `?autoprint=1`)

### Archivos de Ticket
- `ticket.php` — HTML renderizado (soporta todos los tipos de documento)
- `ticket_nota_comun.php` — ESC/POS binario para notas de control
- `ticket_factura_autoimpresa.php` — ESC/POS para facturas autoimpresas
- `ticket_factura_electronica.php` — ESC/POS para facturas electrónicas

### Funciones comunes en tickets
- `normalizePaymentMethod($raw)` — normaliza forma de pago (efectivo, tarjeta, transferencia, pix, credito, pendiente)
- `paymentLabel($raw)` / `paymentMethodLabel($raw)` — etiqueta de display
- `getCardPaymentData()` / `getPixPaymentData()` / `getEfectivoPaymentData()` — datos de pago desde `factura_ventas_pagos`
- Banner `⚠ FACTURA PENDIENTE DE PAGO ⚠` cuando forma_pago = 'pendiente'

---

## 7. FACTURACIÓN ELECTRÓNICA SIFEN (Paraguay)

### Flujo
1. Config en `serproc1.habilitacion_sifen` (RUC, DV, certificado .p12, ambiente TEST/PROD)
2. SDK `php-sifen3-custom` en `public/_lib/`
3. Construir XML del Documento Electrónico (DE)
4. Firmar con OpenSSL (SHA256, soporte legacy RC2)
5. Enviar a SIFEN SET (test) o PROD
6. Extraer CDC (44 dígitos) del response
7. Guardar en `factura_ventas.cdc`, `factura_ventas.estado_sifen`

### Tipo de documento
- `0` = Nota de control (sin validez fiscal)
- `1` = Factura autografiada/autoimpresa
- `3` = Factura electrónica

### Cola asíncrona
- `cron/worker_sifen_queue.php` procesa facturas pendientes
- `public/pos/api/sifen_queue.php` encola emisiones

---

## 8. SISTEMA DE PERMISOS Y ROLES

### Tablas (en serproc1)
- `sec_users`: usuarios (login, pswd, priv_admin, id_empresa, id_grupo)
- `sec_groups`: grupos/roles
- `sec_groups_apps`: permisos por (id_grupo + group_id + app_name) → priv_access/insert/update/delete/export/print
- `sec_users_groups`: relación usuario ↔ grupo

### Lógica
- Admin (`priv_admin = 'Y'`): TODOS los permisos automáticamente
- Backend: `Permission::requireAccess('app_name')`, `Permission::requirePermission('app_name', 'priv_delete')`
- Frontend: `window.__PERMISOS__` objeto JSON con permisos del usuario actual

### App names de permisos
| app_name | Módulo |
|----------|--------|
| `app_grid_factura_venta_global` | Ventas |
| `app_grid_mercaderias` | Productos |
| `app_grid_caja` | Cajas |
| `app_grid_factura_compras` | Compras |
| `app_grid_clientes` | Contactos |
| `app_grid_taller_mantenimiento` | Taller |
| `usuarios` | Usuarios |

---

## 9. VARIABLES DE SESIÓN CLAVE

```php
$_SESSION['id_login']           // int    — ID del usuario logueado
$_SESSION['id_empresa']         // int    — ID de empresa activa
$_SESSION['usuario']            // string — login username
$_SESSION['usr_priv_admin']     // 'Y'|'N' — es admin (normalizado)
$_SESSION['group_id']           // int    — ID del grupo/rol
$_SESSION['group_name']         // string — nombre del rol
$_SESSION['user_name']          // string — nombre completo
$_SESSION['user_email']         // string — email
$_SESSION['dbu']                // string — nombre DB empresa (ej: 'empresa_169')
$_SESSION['server']             // string — host DB (forzado '168.231.95.50')
$_SESSION['user']               // string — usuario DB
$_SESSION['password']           // string — password DB
$_SESSION['id_caja_def']        // int    — caja por defecto
$_SESSION['id_sucursal']        // int    — sucursal activa
$_SESSION['sucursal']           // string — nombre sucursal
$_SESSION['caja']               // string — nombre caja
$_SESSION['session_persistent'] // bool   — si usó "recordarme"
$_SESSION['modulo']             // int    — módulo seleccionado
```

---

## 10. BASE DE DATOS

### Master DB: `serproc1`
| Tabla | Propósito |
|-------|-----------|
| `empresa` | Empresas: id_empresa, dbase, server, ruc, fe, timbrado, active |
| `sec_users` | Usuarios: id_login, login, pswd, priv_admin, id_empresa, caja_def, precio_def, cobro_df, ancho_papel |
| `sec_groups` | Grupos/roles |
| `sec_groups_apps` | Permisos por app/grupo/empresa |
| `sec_users_groups` | Relación usuario↔grupo |
| `habilitacion_sifen` | Config SIFEN por empresa |
| `habilitacion_sifen_actividades` | Actividades económicas |
| `sec_access_logs` | Log de accesos |
| `sec_login_attempts` | Rate limiting login |

### DB por Empresa (ej: `empresa_169`)
| Tabla | Propósito |
|-------|-----------|
| `factura_ventas` | Facturas header (id_factura, tipo_documento, total, cdc, estado_sifen) |
| `extracto_productos` | Detalle de factura / movimientos stock |
| `tblproductos` | Catálogo (idproducto, cve_producto, desproducto, precio_venta, saldo) |
| `clientes` | Contactos/clientes |
| `cajas` | Cajas registradoras (factura_1..3, timbrado) |
| `sucursal` | Sucursales (SUC, NOMBRE) |
| `monedas` | Monedas activas |
| `mercaderia_precio` | Precios por tipo/lista |
| `codigo_barra` | Códigos de barra |
| `fe` | Auxiliar facturación electrónica |
| `de_nc` / `de_nc_items` | Notas de crédito electrónicas |
| `producto_imagenes` | Imágenes productos (R2 / Google Drive) |
| `factura_ventas_pagos` | Detalle pagos (tarjeta, pix, efectivo) |
| `taller_ordenes` | Órdenes de trabajo taller |
| `taller_vehiculos` | Vehículos |
| `taller_servicios_catalogo` | Catálogo servicios taller |
| `cuentas` | Cuentas contables |
| `cobros_varios` | Cobros varios |

---

## 11. INTEGRACIONES EXTERNAS

| Servicio | Archivo Config | Uso |
|----------|---------------|-----|
| **Cloudflare R2** | `config/r2.php` (env vars) | Imágenes productos: WebP, variantes thumb/small/medium/large |
| **Google Drive** | `config/google_drive.php` (OAuth 2.0) | Almacenamiento alternativo imágenes |
| **OpenAI GPT** | `config/openai.php` | IA integrada |
| **ElevenLabs** | `config/elevenlabs.php` | Voz IA (eleven_multilingual_v2) |
| **SIFEN Paraguay** | SDK php-sifen3 + cert .p12 | Facturación electrónica nacional |
| **Bancard TEF PY** | docs/BANCARD_TEF_PY_FLOW.md | Pagos con tarjeta |
| **CUPS** | Sistema operativo | Impresión ESC/POS directa |

---

## 12. PWA / MOBILE

### Detección Mobile
Cada módulo detecta User-Agent y redirige:
- `pos/index.php` → `pos/mobile.php`
- `ventas/index.php` → `ventas/mobile.php`
- `cajas/index.php` → `cajas/mobile.php`
- Cookie `{modulo}_desktop` para forzar vista desktop con `?desktop`

### AG Grid en Mobile
- NO se carga si `window.innerWidth < 768` (ahorra ~2.8 MB)
- Se reemplaza con cards HTML nativas + Alpine.js `x-for`

### Service Worker
- Cache: `sistemax-v2`
- Cache-first para assets, network-first para el resto
- Offline: `public/offline.html`

---

## 13. MÓDULOS POS — DETALLE

### Formas de Pago
`efectivo`, `tarjeta`, `transferencia`, `pix`, `credito`, `pendiente`

### Dos Modales de Checkout
1. **Unified Modal** (`showCheckoutModal`): Multi-pago, distribución entre métodos
2. **Simple Modal** (`showSimplePaymentModal`): Pago directo con un solo método

### Tipos de Documento
- Nota Común (sin validez fiscal)
- Factura Electrónica (SIFEN)
- Factura Autografiada/Autoimpresa

### Long-press para Fijar Default
- 600ms hold → fija método de pago o tipo de documento como default
- Persiste en `localStorage`: `pos_defaultPayment`, `pos_defaultDocType`
- Badge visual "⭐ FIJADO"

### Banner Pendiente
Cuando forma_pago = 'pendiente':
- Ticket HTML: `⚠ FACTURA PENDIENTE DE PAGO ⚠` (borde sólido, centrado)
- Ticket ESC/POS: `** FACTURA PENDIENTE DE PAGO **` (negrita, centrado, doble línea ===)

---

## 14. SCHEMA AUTO-COMPAT

`public/shared/schema_module_compat.php` → `sxEnsureModuleSchemaCompat()`
- Agrega columnas faltantes de forma idempotente (ALTER TABLE ... ADD COLUMN IF NOT EXISTS)
- Auto-crea tablas (`cuentas`, `producto_imagenes`) e índices
- Try/catch silencioso para no romper el flujo
- Se ejecuta 1 vez por sesión/módulo

### Auto-optimización de índices (POS)
- Crea índices en `factura_ventas`, `extracto_productos`, etc.
- Flag sesión: `$_SESSION['pos_idx_opt_{dbName}_{date}']`
- Ejecuta 1 vez por día por empresa

---

## 15. CONTRASEÑAS LEGACY

El sistema soporta 3 formatos por compatibilidad con ScriptCase:
1. `password_verify()` (bcrypt) — preferido
2. `md5()` — legacy
3. Texto plano — fallback ScriptCase antiguo

---

## 16. CONVENCIONES DE NAMING

| Elemento | Convención | Ejemplo |
|----------|-----------|---------|
| Tablas DB | snake_case | `factura_ventas`, `tblproductos` |
| Funciones Alpine | camelCase | `ventasApp()`, `productosApp()` |
| Permisos | prefijo `app_grid_` | `app_grid_factura_venta_global` |
| APIs | `?action=nombre` | `?action=list`, `?action=save` |
| Colores módulo | POS=Blue, Productos=Cyan, Cajas=Green, Taller=Orange | — |
| Archivos mobile | `mobile.php` en cada módulo | `pos/mobile.php` |
| Archivos API | `api/{recurso}.php` | `pos/api/venta.php` |

---

## 17. REGLAS PARA EL ASISTENTE

1. **NUNCA** expongas credenciales en el código frontend (aunque existan en config/)
2. **Siempre** usa prepared statements PDO (nunca concatenación SQL directa)
3. **Siempre** valida sesión con `Session::requireLogin()` o `Session::isLoggedIn()`
4. **Siempre** verifica permisos con `Permission::requireAccess()` antes de acciones CRUD
5. **Respeta** el patrón Alpine.js existente: `x-data="moduloApp()"` + `x-init="init()"`
6. **Dark mode**: siempre incluir variantes `dark:` de Tailwind
7. **Mobile**: considerar que existe vista separada `mobile.php` — cambios pueden requerir editar ambas
8. **Tickets**: los cambios deben reflejarse en los 4 archivos de ticket (HTML + 3 ESC/POS)
9. **SIFEN**: nunca modificar la lógica de firma XML sin entender OpenSSL + certificados .p12
10. **Schema compat**: si agregas columnas nuevas, agrégalas también en `schema_module_compat.php`
11. **Idioma**: toda la UI es en español (Paraguay)
12. **Moneda**: Guaraníes (Gs), sin decimales en la mayoría de casos
13. **AG Grid**: Enterprise v32.3.3 con licencia, archivos locales en `_lib/ag-grid/`
14. **Impresión**: siempre considerar el ancho de papel (32 o 42 columnas ESC/POS)
