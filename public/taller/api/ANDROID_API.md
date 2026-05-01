# Taller Android API

API pública para la app Android de seguimiento de taller.

Base URL:

```text
https://tu-dominio/public/taller/api/mobile.php
```

## 1. Consultar estado por chapa

Request:

```http
GET /public/taller/api/mobile.php?action=status&id_empresa=169&chapa=ABC123
```

Response:

```json
{
  "success": true,
  "data": {
    "vehiculo": {
      "id_vehiculo": 15,
      "chapa": "ABC123",
      "marca": "Toyota",
      "modelo": "Vitz",
      "anio": "2017",
      "color": "Plata",
      "cliente": "Juan Perez",
      "telefono_cliente": "0981123456"
    },
    "ot_actual": {
      "id_orden": 32,
      "nro_ot": "OT-20260330-0001",
      "fecha": "2026-03-30",
      "hora_ingreso": "08:30",
      "estado": "en_proceso",
      "prioridad": "media",
      "problema": "Ruido en freno delantero",
      "diagnostico": "Cambio de pastillas y rectificado",
      "total": 350000,
      "fecha_entrega_estimada": "2026-03-30 11:30:00",
      "share_url": "https://tu-dominio/public/taller/ot_public.php?id_empresa=169&token=..."
    },
    "eventos": [
      {
        "id_evento": 120,
        "tipo": "estado",
        "titulo": "Estado de OT actualizado",
        "mensaje": "La orden OT-20260330-0001 ahora está en estado en_proceso",
        "estado": "en_proceso",
        "created_at": "2026-03-30 15:02:10"
      }
    ]
  }
}
```

## 2. Consultar eventos nuevos

Request:

```http
GET /public/taller/api/mobile.php?action=events&id_empresa=169&chapa=ABC123&since_id=120
```

Response:

```json
{
  "success": true,
  "data": {
    "vehiculo": {
      "id_vehiculo": 15,
      "chapa": "ABC123"
    },
    "eventos": [
      {
        "id_evento": 121,
        "id_orden": 32,
        "tipo": "manual",
        "titulo": "Actualización del taller",
        "mensaje": "Ya iniciamos el desmontaje.",
        "estado": "en_proceso",
        "created_at": "2026-03-30 15:10:00"
      }
    ]
  }
}
```

## 3. Registrar dispositivo Android

Request:

```http
POST /public/taller/api/mobile.php
Content-Type: application/json
```

```json
{
  "action": "register_device",
  "id_empresa": 169,
  "chapa": "ABC123",
  "device_uuid": "ab12cd34-ef56-7890",
  "platform": "android",
  "device_name": "Samsung A55",
  "push_token": "fcm-token-opcional"
}
```

Response:

```json
{
  "success": true,
  "message": "Dispositivo registrado",
  "data": {
    "chapa": "ABC123",
    "id_vehiculo": 15
  }
}
```

## 4. Notificación manual desde taller

Uso backend:

```http
POST /public/taller/api/ordenes.php
Content-Type: application/json
```

```json
{
  "action": "notify",
  "id_empresa": 169,
  "id_orden": 32,
  "titulo": "Actualización del taller",
  "mensaje": "Tu vehículo ya está en alineación."
}
```

Esto genera un evento visible para Android en `action=events`.

## Modelo Kotlin

```kotlin
data class MobileApiResponse<T>(
    val success: Boolean,
    val data: T?,
    val error: String? = null
)

data class VehicleStatusPayload(
    val vehiculo: VehicleDto,
    val ot_actual: OrderDto?,
    val eventos: List<EventDto>
)

data class EventsPayload(
    val vehiculo: VehicleShortDto,
    val eventos: List<EventDto>
)

data class VehicleDto(
    val id_vehiculo: Int,
    val chapa: String,
    val marca: String?,
    val modelo: String?,
    val anio: String?,
    val color: String?,
    val cliente: String?,
    val telefono_cliente: String?
)

data class VehicleShortDto(
    val id_vehiculo: Int,
    val chapa: String
)

data class OrderDto(
    val id_orden: Int,
    val nro_ot: String,
    val fecha: String,
    val hora_ingreso: String?,
    val estado: String,
    val prioridad: String?,
    val problema: String?,
    val diagnostico: String?,
    val total: Double,
    val fecha_entrega_estimada: String?,
    val share_url: String?
)

data class EventDto(
    val id_evento: Int,
    val id_orden: Int? = null,
    val tipo: String,
    val titulo: String,
    val mensaje: String?,
    val estado: String?,
    val created_at: String
)

data class RegisterDeviceRequest(
    val action: String = "register_device",
    val id_empresa: Int,
    val chapa: String,
    val device_uuid: String,
    val platform: String = "android",
    val device_name: String,
    val push_token: String?
)
```

## Retrofit

```kotlin
interface TallerMobileApi {
    @GET("public/taller/api/mobile.php")
    suspend fun getStatus(
        @Query("action") action: String = "status",
        @Query("id_empresa") idEmpresa: Int,
        @Query("chapa") chapa: String
    ): MobileApiResponse<VehicleStatusPayload>

    @GET("public/taller/api/mobile.php")
    suspend fun getEvents(
        @Query("action") action: String = "events",
        @Query("id_empresa") idEmpresa: Int,
        @Query("chapa") chapa: String,
        @Query("since_id") sinceId: Int
    ): MobileApiResponse<EventsPayload>

    @POST("public/taller/api/mobile.php")
    suspend fun registerDevice(
        @Body body: RegisterDeviceRequest
    ): MobileApiResponse<Map<String, Any>>
}
```

## Polling recomendado

```kotlin
class TallerRepository(
    private val api: TallerMobileApi
) {
    suspend fun pollEvents(
        idEmpresa: Int,
        chapa: String,
        initialSinceId: Int = 0,
        onEvents: (List<EventDto>) -> Unit
    ) {
        var sinceId = initialSinceId
        while (true) {
            try {
                val res = api.getEvents(idEmpresa = idEmpresa, chapa = chapa, sinceId = sinceId)
                val eventos = res.data?.eventos.orEmpty()
                if (eventos.isNotEmpty()) {
                    sinceId = eventos.maxOf { it.id_evento }
                    onEvents(eventos)
                }
            } catch (_: Exception) {
            }
            kotlinx.coroutines.delay(15_000)
        }
    }
}
```

## Flujo recomendado en Android

1. El usuario instala la app.
2. Ingresa su `chapa`.
3. La app llama a `status`.
4. Guarda el mayor `id_evento`.
5. Registra el dispositivo con `register_device`.
6. Inicia polling cada `10-15s` con `events`.
7. Si llegan eventos nuevos, actualiza pantalla y dispara notificación local.

## Notificación local en Android

Cuando `events` devuelve nuevos registros:

- mostrar `titulo`
- mostrar `mensaje`
- usar `estado` para colorear UI
- opcionalmente abrir `share_url` de la OT si existe

## Observación

Hoy el backend guarda `push_token`, pero no envía FCM todavía. El canal en vivo actual es:

- polling de `events`
- más disparo de notificación local del lado Android

Si después querés push real, el siguiente paso es integrar Firebase Cloud Messaging usando los `push_token` ya registrados.
