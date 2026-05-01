# GUIA COMPLETA DE VIDEOS POR APP - SISTEMAX

## Objetivo
Documentar cada app del sistema con videos claros para usuarios principiantes, con formato estandar y facil de mantener.

## Estandar de Produccion (aplica a todos los videos)
1. Resolucion: 1920x1080.
2. Duracion recomendada: 6 a 12 minutos por app.
3. Estructura fija:
   - Intro (20-30s): para que sirve la app.
   - Flujo principal (60% del video): operacion normal.
   - Errores comunes (20%): que no hacer y como corregir.
   - Cierre (30-40s): resumen + siguiente paso.
4. Estilo narracion: frases cortas, sin tecnicismos.
5. Mostrar siempre:
   - Donde entrar desde el menu.
   - Que permisos necesita el usuario.
   - Como validar que la operacion quedo guardada.

## Plantilla de Guion (copiar para cada app)
### 1) Objetivo de la app
Que problema resuelve y quien la usa.

### 2) Requisitos previos
- Usuario y permisos.
- Datos minimos necesarios.

### 3) Flujo principal grabado
1. Abrir modulo.
2. Buscar/filtrar.
3. Crear/editar registro.
4. Confirmar/guardar.
5. Verificar resultado.

### 4) Errores comunes
- Error 1: causa + solucion.
- Error 2: causa + solucion.

### 5) Cierre
Resumen y modulo recomendado para continuar.

---

## APP 1: POS (`public/pos/index.php`)
### Objetivo
Registrar ventas rapidas, cobrar, emitir comprobante (Control o FE) e imprimir ticket.

### Escenas del video
1. Entrada al POS desde menu.
2. Seleccion de cliente.
3. Carga de productos (codigo y busqueda).
4. Modal "Total a pagar": tipo documento + forma de pago.
5. Confirmar venta.
6. Impresion ticket y reimpresion desde Mis Ventas.

### Narracion sugerida
"En este modulo vas a vender en pocos pasos. Primero elegi cliente, luego agregamos productos y finalmente cobramos en el modal de total. Antes de confirmar, revisa tipo de documento y forma de pago para evitar anulaciones."

### Errores a mostrar
- Documento incorrecto (Control vs FE).
- Monto cobrado distinto al total.
- Impresora no seleccionada en caja.

---

## APP 2: MIS VENTAS (`public/misventas/index.php`)
### Objetivo
Consultar solo ventas del usuario/caja actual y reimprimir comprobantes.

### Escenas del video
1. Abrir "Mis Ventas" desde POS.
2. Explicar filtros por fecha/cliente/documento.
3. Reimprimir ticket segun tipo (Control o FE).
4. Volver al POS con boton salir.

### Narracion sugerida
"Mis Ventas sirve para seguimiento y reimpresion segura. Todo queda filtrado por usuario y caja para evitar mezclar operaciones de otros cajeros."

### Errores a mostrar
- Filtro demasiado restrictivo (no trae facturas).
- Reimpresion en impresora no conectada.

---

## APP 3: VENTAS (`public/ventas/index.php`)
### Objetivo
Vista global de ventas para control administrativo.

### Escenas del video
1. Abrir modulo y explicar diferencia con Mis Ventas.
2. Usar filtros de fecha, cliente y estado.
3. Ver detalle de comprobante.
4. Exportar o imprimir si aplica.

### Narracion sugerida
"Este modulo es para supervision general. A diferencia de Mis Ventas, aqui se revisa el universo de ventas segun permisos."

### Errores a mostrar
- Interpretar mal anuladas vs vigentes.
- No aplicar rango de fechas al analizar.

---

## APP 4: CAJAS (`public/cajas/index.php`)
### Objetivo
Administrar cajas, asignar usuarios y controlar movimientos de caja.

### Escenas del video
1. Crear nueva caja.
2. Configurar numeracion y usuarios asignados.
3. Abrir kardex de caja.
4. Registrar entrada/salida/transferencia.

### Narracion sugerida
"Cajas centraliza el control de efectivo. Todo movimiento debe registrarse para cuadrar correctamente al cierre."

### Errores a mostrar
- Usuario sin caja asignada.
- Movimientos sin referencia.

---

## APP 5: PRODUCTOS (`public/productos/index.php`)
### Objetivo
Crear y mantener catalogo, precios y stock.

### Escenas del video
1. Alta de producto.
2. Configuracion de precio/iva/estado.
3. Filtros por grupo/marca/estado.
4. Ajuste o consulta de stock.

### Narracion sugerida
"Antes de vender, el producto debe estar bien configurado. Un precio o IVA incorrecto impacta directo en facturacion."

### Errores a mostrar
- Producto sin precio de venta.
- Estado inactivo usado por error.

---

## APP 6: CONTACTOS (`public/contactos/index.php`)
### Objetivo
Gestionar clientes, proveedores y empleados.

### Escenas del video
1. Alta rapida de cliente.
2. Carga de RUC/CI y datos de contacto.
3. Cambio de tipo de contacto.
4. Busqueda y edicion.

### Narracion sugerida
"Un contacto bien cargado evita errores de facturacion y facilita cobranzas."

### Errores a mostrar
- Duplicar cliente por no buscar antes.
- Documento mal cargado.

---

## APP 7: COMPRAS (`public/compras/index.php`)
### Objetivo
Registrar compras de proveedores y controlar cuentas por pagar.

### Escenas del video
1. Nueva compra.
2. Cargar proveedor y nro de factura.
3. Agregar productos y costos.
4. Guardar y revisar pendiente.

### Narracion sugerida
"Compras alimenta costos y stock. Siempre valida proveedor, numero de factura y total antes de guardar."

### Errores a mostrar
- Guardar sin proveedor.
- Total y detalle no coinciden.

---

## APP 8: USUARIOS (`public/usuarios/index.php`)
### Objetivo
Administrar usuarios, grupos y permisos de apps.

### Escenas del video
1. Crear usuario nuevo.
2. Asignar grupo/perfil.
3. Activar o limitar accesos.
4. Validar desde login de prueba.

### Narracion sugerida
"Los permisos definen que ve y que puede ejecutar cada usuario. Configuralos por rol, no por improvisacion."

### Errores a mostrar
- Usuario activo sin permisos minimos.
- Privilegios excesivos para caja/ventas.

---

## APP 9: EMPRESA / CONFIGURACION (`public/empresa/index.php`)
### Objetivo
Configurar datos fiscales, FE y parametros base de la empresa.

### Escenas del video
1. Datos generales de empresa.
2. Tipo de factura y FE.
3. Timbrado y datos del emisor.
4. Guardar y validar en ticket.

### Narracion sugerida
"Esta app define la identidad fiscal del sistema. Un dato incorrecto aqui impacta en todos los comprobantes."

### Errores a mostrar
- RUC o razon social incorrecta.
- Datos FE incompletos.

---

## APP 10: PANEL (`public/panel/index.php`)
### Objetivo
Visualizar resumen de ventas y productos principales.

### Escenas del video
1. Abrir panel.
2. Revisar metricas clave.
3. Ver ultimas ventas.
4. Interpretar top productos.

### Narracion sugerida
"El panel sirve para lectura rapida del negocio: cuanto se vendio, que se vendio y a quien."

### Errores a mostrar
- Leer metricas sin filtrar periodo.
- Concluir sin revisar anulaciones.

---

## APP 11: CONSULTAS (`public/consultas/nota.php`)
### Objetivo
Consultas puntuales operativas/administrativas segun acceso.

### Escenas del video
1. Abrir consulta.
2. Completar parametros.
3. Ejecutar y leer resultado.
4. Exportar/registrar conclusion.

### Narracion sugerida
"Consultas permite responder preguntas rapidas del negocio sin tocar operacion de venta."

---

## APP 12: VCORTA (Agente IA en menu)
### Objetivo
Asistente para usuarios principiantes: guiar, consultar datos y abrir apps permitidas.

### Escenas del video
1. Abrir chat desde logo.
2. Preguntas frecuentes de operacion.
3. Ejemplo: "Cuanto vendimos hoy".
4. Ejemplo: "Abrir POS" con validacion de permisos.
5. Uso de microfono y voz.

### Narracion sugerida
"Vcorta acelera tareas frecuentes. Si el usuario tiene permiso, tambien puede abrir apps desde el chat."

### Errores a mostrar
- Consulta ambigua sin fechas.
- Intentar abrir app sin permiso.

---

## Calendario recomendado de grabacion
1. Dia 1: POS + Mis Ventas + Ventas.
2. Dia 2: Cajas + Productos + Contactos.
3. Dia 3: Compras + Usuarios + Empresa.
4. Dia 4: Panel + Consultas + Vcorta.

## Entregables por cada app
1. Video completo (6-12 min).
2. Video corto (1-2 min, solo flujo principal).
3. PDF de apoyo (capturas + pasos numerados).
4. Lista de errores frecuentes.

## Checklist final antes de publicar
1. Audio claro y sin ruido.
2. Zoom suficiente en botones clave.
3. Se muestra al menos un caso real completo.
4. Se muestra validacion final (guardado/impresion/reporte).
5. Nombre del archivo consistente: `APP_XX_NOMBRE_v1.mp4`.
