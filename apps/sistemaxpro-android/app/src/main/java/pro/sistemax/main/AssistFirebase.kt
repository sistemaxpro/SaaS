package pro.sistemax.main

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

object AssistFirebase {

    private const val CHANNEL_ID = "sistemax_authorizations"

    fun initializeIfPossible(context: Context): Boolean {
        if (BuildConfig.SX_FIREBASE_APP_ID.isBlank()
            || BuildConfig.SX_FIREBASE_API_KEY.isBlank()
            || BuildConfig.SX_FIREBASE_PROJECT_ID.isBlank()
            || BuildConfig.SX_FIREBASE_SENDER_ID.isBlank()
        ) {
            return false
        }
        if (FirebaseApp.getApps(context).isNotEmpty()) {
            return true
        }
        val options = FirebaseOptions.Builder()
            .setApplicationId(BuildConfig.SX_FIREBASE_APP_ID)
            .setApiKey(BuildConfig.SX_FIREBASE_API_KEY)
            .setProjectId(BuildConfig.SX_FIREBASE_PROJECT_ID)
            .setGcmSenderId(BuildConfig.SX_FIREBASE_SENDER_ID)
            .apply {
                if (BuildConfig.SX_FIREBASE_STORAGE_BUCKET.isNotBlank()) {
                    setStorageBucket(BuildConfig.SX_FIREBASE_STORAGE_BUCKET)
                }
            }
            .build()
        FirebaseApp.initializeApp(context, options)
        ensureAuthorizationChannel(context)
        return true
    }

    fun syncTokenIfPossible(context: Context) {
        val prefs = context.getSharedPreferences("sistemax_app", Context.MODE_PRIVATE)
        if (!initializeIfPossible(context)) {
            prefs.edit()
                .putBoolean("fcm_configured", false)
                .putString("fcm_last_sync_status", "Firebase no configurado")
                .apply()
            return
        }
        prefs.edit().putBoolean("fcm_configured", true).apply()
        val trackingApiUrl = prefs.getString("tracking_api_url", "").orEmpty()
        val trackingAgentToken = prefs.getString("tracking_agent_token", "").orEmpty()
        if (trackingApiUrl.isBlank() || trackingAgentToken.isBlank()) {
            prefs.edit().putString("fcm_last_sync_status", "Falta registrar tracking").apply()
            return
        }
        FirebaseMessaging.getInstance().token
            .addOnSuccessListener { token ->
                if (token.isNullOrBlank()) return@addOnSuccessListener
                prefs.edit()
                    .putString("fcm_token", token)
                    .putString("fcm_last_sync_status", "Token obtenido")
                    .apply()
                CoroutineScope(Dispatchers.IO).launch {
                    val result = runCatching {
                        TrackingApiClient(trackingApiUrl).registerFcmToken(
                            agentToken = trackingAgentToken,
                            fcmToken = token,
                            platform = "android",
                            appVersion = BuildConfig.VERSION_NAME
                        )
                    }
                    withContext(Dispatchers.Main) {
                        val tokenId = result.getOrNull()?.optLong("token_id", 0L) ?: 0L
                        val activeTokens = result.getOrNull()?.optInt("active_tokens", 0) ?: 0
                        prefs.edit()
                            .putString("fcm_last_sync_at", IsoClock.now())
                            .putLong("fcm_server_token_id", tokenId)
                            .putInt("fcm_server_active_tokens", activeTokens)
                            .putString("fcm_last_sync_status", if (result.isSuccess) "Registrado en servidor" else "Error registro FCM: ${result.exceptionOrNull()?.message ?: "desconocido"}")
                            .apply()
                    }
                }
            }
            .addOnFailureListener { error ->
                prefs.edit()
                    .putString("fcm_last_sync_status", "Error token FCM: ${error.message ?: "desconocido"}")
                    .apply()
            }
    }

    fun showRemoteNotification(context: Context, title: String, body: String, url: String) {
        ensureAuthorizationChannel(context)
        val prefs = context.getSharedPreferences("sistemax_app", Context.MODE_PRIVATE)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            prefs.edit()
                .putString("fcm_last_notification_attempt_at", IsoClock.now())
                .putString("fcm_last_notification_result", "Bloqueada por permiso POST_NOTIFICATIONS")
                .apply()
            return
        }
        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra("open_url", url)
            putExtra("open_section", "authorizations")
        }
        val pendingIntent = android.app.PendingIntent.getActivity(
            context,
            (System.currentTimeMillis() % Int.MAX_VALUE).toInt(),
            intent,
            android.app.PendingIntent.FLAG_UPDATE_CURRENT or android.app.PendingIntent.FLAG_IMMUTABLE
        )
        val notification = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_dialog_alert)
            .setContentTitle(if (title.isBlank()) "Autorizacion pendiente" else title)
            .setContentText(if (body.isBlank()) "Tenes una solicitud pendiente en autorizaciones." else body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setDefaults(NotificationCompat.DEFAULT_ALL)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .build()
        NotificationManagerCompat.from(context).notify(((System.currentTimeMillis() / 1000L) % Int.MAX_VALUE).toInt(), notification)
        prefs.edit()
            .putString("fcm_last_notification_attempt_at", IsoClock.now())
            .putString("fcm_last_notification_result", "NotificationManager.notify ejecutado")
            .apply()
    }

    fun ensureAuthorizationChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        val current = manager.getNotificationChannel(CHANNEL_ID)
        if (current != null) {
            return
        }
        val channel = NotificationChannel(
            CHANNEL_ID,
            "Autorizaciones SistemaX",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Solicitudes de autorizacion pendientes"
            enableVibration(true)
            setSound(android.provider.Settings.System.DEFAULT_NOTIFICATION_URI, null)
        }
        manager.createNotificationChannel(channel)
    }
}
