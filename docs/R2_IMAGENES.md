# R2 + CDN para imágenes de productos

## 1) Variables de entorno

Definir en el servidor web (Apache/Nginx + PHP-FPM):

```bash
R2_ENABLED=true
R2_ACCOUNT_ID=tu_account_id
R2_ACCESS_KEY_ID=tu_access_key
R2_SECRET_ACCESS_KEY=tu_secret_key
R2_BUCKET=products-images
R2_PUBLIC_BASE_URL=https://img.tudominio.com
# Opcional
# R2_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
# R2_MAX_IMAGE_MB=8
# R2_MAX_PER_PRODUCT=5
```

## 2) Endpoints ya adaptados

- `public/productos/api/imagen.php`
- `public/pos/api/guardar_imagen_producto.php`

Ambos:
- Suben nuevas imágenes a R2 cuando está configurado.
- Mantienen fallback local si R2 no está activo.
- Actualizan `tblproductos.foto_url`.
- Sincronizan `producto_imagenes` con `drive_file_id` tipo `r2:<key>`.

## 3) Migrar imágenes existentes (sin downtime)

```bash
# Simulación
php scripts/migrate_product_images_to_r2.php --id_empresa=169 --dry-run

# Migración real
php scripts/migrate_product_images_to_r2.php --id_empresa=169

# Migración parcial por cantidad de productos
php scripts/migrate_product_images_to_r2.php --id_empresa=169 --limit=500
```

## 4) Verificación rápida

1. Subir una imagen nueva desde Productos o POS.
2. Verificar que `foto_url` quede en `https://img.tudominio.com/...`.
3. Confirmar render en:
   - POS (`public/pos/index.php`)
   - Productos mobile (`public/productos/mobile.php`)

