# Plan: Deprecar Totalmente Queries de Imágenes en Búsquedas POS

## Objetivo
Eliminar `attachProductImages()` de endpoints de búsqueda/dropdown para eliminar cientos de ms de latencia. Imágenes se cargan **solo en endpoints de lectura detallada** (cuando usuario selecciona producto específico).

---

## Cambios Precisos

### 1. Remover `attachProductImages()` de caso `popular` (línea 768)

**Ubicación**: `/var/www/html/sistemaxpro-dev/public/pos/api/productos.php`

**Antes**:
```php
            $attachProductImages($popular, 'id', true);

            $payload = ['productos' => $popular];
```

**Después**:
```php
            $payload = ['productos' => $popular];
```

**Contexto**:
```php
            if (empty($popular)) {
                $rows = $fetchSimpleProducts($pdo, $dbName, $limitPopular, '', 1, true, true);
                $enrichPopularAvailability($rows, 'id');
                $payload = ['productos' => $filterPopularVisibleProducts($rows)];
                if ($useFastCache) {
                    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
                }
                echo json_encode($payload);
                break;
            }

            // REMOVER: $attachProductImages($popular, 'id', true);

            $payload = ['productos' => $popular];
            if ($useFastCache) {
                @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
            echo json_encode($payload);
```

---

### 2. Remover `attachProductImages()` de caso `popular_simple` (línea 823)

**Ubicación**: `/var/www/html/sistemaxpro-dev/public/pos/api/productos.php`

**Antes**:
```php
                $enrichPopularAvailability($rows, 'id');
                $rows = $filterPopularVisibleProducts($rows);
                $attachProductImages($rows, 'id', true);
                echo json_encode(['productos' => $rows]);
```

**Después**:
```php
                $enrichPopularAvailability($rows, 'id');
                $rows = $filterPopularVisibleProducts($rows);
                echo json_encode(['productos' => $rows]);
```

---

### 3. Cambiar parámetro en caso `popular_mobile` (línea 830)

**Ubicación**: `/var/www/html/sistemaxpro-dev/public/pos/api/productos.php`

**Antes**:
```php
        case 'popular_mobile':
            // Endpoint dedicado para móvil: consulta mínima y robusta, sin joins pesados.
            $rows = $fetchSimpleProducts($pdo, $dbName, 24, '', 1, true, true);
```

**Después**:
```php
        case 'popular_mobile':
            // Endpoint dedicado para móvil: consulta mínima y robusta, sin joins pesados.
            $rows = $fetchSimpleProducts($pdo, $dbName, 24, '', 1, false, true);
```

**Explicación**: Cambiar 4º parámetro `attachImages` de `true` a `false` para NO hidratar imágenes en lista inicial.

---

### 4. Remover `attachProductImages()` de caso `search` (línea ~1189)

**Ubicación**: `/var/www/html/sistemaxpro-dev/public/pos/api/productos.php`

**Antes**:
```php
            $stmtDetails = $pdo->prepare($sqlDetails);
            $stmtDetails->execute($paramsDetails);
            $productos = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

            // Agregar imágenes a los productos
            $attachProductImages($productos, 'id');

            $payload = ['productos' => $productos];
```

**Después**:
```php
            $stmtDetails = $pdo->prepare($sqlDetails);
            $stmtDetails->execute($paramsDetails);
            $productos = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

            $payload = ['productos' => $productos];
```

---

## Endpoints NO Modificados (conservan imágenes)

Estos endpoints cargan imágenes porque retornan un **producto único** o requieren hidraciones detalladas:

- ✅ `case 'barcode'` (línea 1216) - usuario escanea/selecciona código
- ✅ `case 'by_id'` (línea 1269) - usuario abre producto específico
- ✅ `case 'hydrate'` (línea 1358) - cliente pide detalles (imágenes, variantes)

---

## Impacto Esperado

| Métrica | Antes | Después | Mejora |
|---------|-------|---------|--------|
| `search_dropdown` keystroke | 500-1000ms | 50-100ms | **-80-90%** ⚡ |
| Primer `search` | 600-1200ms | 100-200ms | **-80-85%** ⚡ |
| `popular` inicial | 400-800ms | 50-100ms | **-80-88%** ⚡ |
| Payload (sin imágenes) | +3-5MB | -30% | **-3-5MB** 🎯 |
| UX Responsividad | Lag 600-1000ms | <100ms | **Fluido** ✅ |

---

## Validación Post-Deploy

1. **Test búsqueda**: escribir `"cola"` en dropdown → debe ser <150ms
2. **Test popular**: cargar pantalla POS → debe ser <200ms
3. **Test detalle**: clickear producto → imágenes se cargan (endpoint `by_id`)
4. **Test barcode**: escanear código → retorna producto + imagen
5. **Dev tools**: Network tab → todas las imágenes en respuestas son `null/omitidas` en bulk queries

---

## Rollback Plan

Si algo falla:
1. Restaurar las 4 líneas de `attachProductImages()` en posiciones originales
2. Cambiar parámetro `popular_mobile` de `false` a `true`
3. Tested en dev antes de re-deploy

---

## Notas

- **Seguridad**: Sin cambios de lógica, solo optimización de I/O
- **Compatibilidad**: Frontend no requiere cambios (ya ignora imágenes `null`)
- **Caching**: Sin impacto en caché (mismas claves, menos payload)
- **Future**: Considerar lazy-load de imágenes en segunda request si frontend lo solicita
