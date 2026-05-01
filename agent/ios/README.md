# SistemaX Assist iPhone

Esqueleto inicial de `SistemaX Assist iPhone` para registro, diagnostico y futura notificacion de autorizaciones.

## Alcance de esta fase

- Registro del dispositivo iPhone usando `bootstrap token`
- Registro del token push iOS/FCM contra `mobile_tracking.php`
- Diagnostico local de permisos, token y ultimo registro
- Base SwiftUI para despues sumar tracking u otras pantallas

## Estructura

- `project.yml`: proyecto XcodeGen
- `SistemaXAssist/`: codigo SwiftUI
- `SistemaXAssist/Resources/SistemaXAssist.entitlements`: base vacia compatible con `Personal Team`

## Requisitos

- macOS con Xcode 16+
- `xcodegen`
- una app iOS en Firebase si despues vas a activar push real
- `GoogleService-Info.plist` real si despues vas a activar Firebase Messaging

## Bundle ID sugerido

- `pro.sistemax.assist.ios`

## Generar el proyecto

```bash
cd agent/ios
xcodegen generate
open SistemaXAssist.xcodeproj
```

## Archivos a completar

- colocar el Firebase iOS real en:
  - `agent/ios/SistemaXAssist/Resources/GoogleService-Info.plist`
- referencia de ejemplo:
  - `agent/ios/SistemaXAssist/Resources/GoogleService-Info.example.plist`
- guia detallada:
  - `agent/ios/XCODE_SETUP.md`

## Configuracion en Xcode

1. Abrir el target `SistemaXAssist`
2. En `Signing & Capabilities`:
   - configurar Team
3. Verificar que el bundle id coincida con el registrado en Firebase si mas adelante activas push real
4. Con `Personal Team`, compila primero sin `Push Notifications`
5. Agregar el `GoogleService-Info.plist` real cuando vayas a activar Firebase Messaging

## Endpoints usados

- `POST /public/api/mobile_tracking.php?action=ios_issue_bootstrap_token`
- `POST /public/api/mobile_tracking.php?action=ios_register_bootstrap`
- `POST /public/api/mobile_tracking.php?action=ios_push_register`
- `GET /public/api/mobile_tracking.php?action=ios_status`

## Flujo esperado

1. El usuario obtiene `Bootstrap token iPhone` desde la web
2. Abre `SistemaX Assist iPhone`
3. Completa `API URL`, `Bootstrap token` y `Nombre del equipo`
4. Toca `Registrar iPhone`
5. Puede probar permisos locales, registro contra backend y diagnostico
6. Cuando tengas cuenta paga Apple, activas push real y registro FCM/APNs

## Nota

Este es un esqueleto inicial. Con `Personal Team` sirve para probar UI, registro y diagnostico. Para push remoto real hace falta cuenta paga de Apple Developer.
