# Xcode Setup

## 1. Preparar el archivo Firebase iOS

1. En Firebase Console, agrega una app iOS con bundle id:
   - `pro.sistemax.assist.ios`
2. Descarga `GoogleService-Info.plist`
3. Guardalo como:
   - `agent/ios/SistemaXAssist/Resources/GoogleService-Info.plist`

Si todavia no lo descargaste, usa como referencia:
- [GoogleService-Info.example.plist](/var/www/html/sistemaxpro-dev/agent/ios/SistemaXAssist/Resources/GoogleService-Info.example.plist)

## 2. Generar el proyecto

```bash
cd agent/ios
xcodegen generate
open SistemaXAssist.xcodeproj
```

## 3. Signing

En Xcode:

1. selecciona el target `SistemaXAssist`
2. `Signing & Capabilities`
3. elige tu `Team`
4. confirma el `Bundle Identifier`:
   - `pro.sistemax.assist.ios`

## 4. Capabilities

### Si estas usando `Personal Team`

- no agregues `Push Notifications`
- no agregues `Background Modes > Remote notifications`
- compila solo para probar UI, registro y diagnostico local

### Si despues activas cuenta paga Apple Developer

En `Signing & Capabilities`, agrega:

1. `Push Notifications`
2. `Background Modes`
   - activar `Remote notifications`

El entitlement base queda vacio y compatible con `Personal Team` en:
- [SistemaXAssist.entitlements](/var/www/html/sistemaxpro-dev/agent/ios/SistemaXAssist/Resources/SistemaXAssist.entitlements)

## 5. Firebase Messaging

En Xcode:

1. `File > Packages`
2. verifica que carguen:
   - `FirebaseCore`
   - `FirebaseMessaging`

Eso ya esta definido en:
- [project.yml](/var/www/html/sistemaxpro-dev/agent/ios/project.yml)

## 6. Probar en iPhone real

1. conecta un iPhone real
2. ejecuta la app
3. concede notificaciones
4. registra bootstrap
5. revisa la pantalla `Diagnostico`

## 7. Qué debería quedar funcionando

- registro del iPhone contra `mobile_tracking.php`
- registro del dispositivo iPhone contra `mobile_tracking.php`
- diagnostico local
- base lista para sumar push real mas adelante

## 8. Nota importante

El simulador de iPhone no sirve para validar push real igual que un dispositivo físico. La prueba correcta es en iPhone real.
