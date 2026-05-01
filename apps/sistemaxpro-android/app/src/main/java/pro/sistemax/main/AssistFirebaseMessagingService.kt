package pro.sistemax.main

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

class AssistFirebaseMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        if (token.isBlank()) return
        val prefs = getSharedPreferences("sistemax_app", MODE_PRIVATE)
        prefs.edit()
            .putString("fcm_token", token)
            .putString("fcm_last_sync_status", "Nuevo token emitido")
            .putString("fcm_last_new_token_at", IsoClock.now())
            .apply()
        AssistFirebase.syncTokenIfPossible(this)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        val title = data["title"]?.takeIf { it.isNotBlank() }
            ?: message.notification?.title
            ?: "SistemaX"
        val body = data["body"]?.takeIf { it.isNotBlank() }
            ?: message.notification?.body
            ?: "Tenes una solicitud pendiente en autorizaciones."
        val url = data["url"] ?: "/public/pos/autorizaciones.php"
        val source = when {
            data.isNotEmpty() && message.notification != null -> "data+notification"
            data.isNotEmpty() -> "data"
            message.notification != null -> "notification"
            else -> "unknown"
        }
        val prefs = getSharedPreferences("sistemax_app", MODE_PRIVATE)
        prefs.edit()
            .putString("fcm_last_received_at", IsoClock.now())
            .putString("fcm_last_received_title", title)
            .putString("fcm_last_received_body", body)
            .putString("fcm_last_received_source", source)
            .putString("fcm_last_received_url", url)
            .apply()
        AssistFirebase.showRemoteNotification(this, title, body, url)
    }
}
