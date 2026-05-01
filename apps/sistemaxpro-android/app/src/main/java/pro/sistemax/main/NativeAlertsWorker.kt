package pro.sistemax.main

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import java.util.concurrent.TimeUnit

class NativeAlertsWorker(
    appContext: Context,
    params: WorkerParameters
) : CoroutineWorker(appContext, params) {

    companion object {
        private const val WORK_NAME = "sistemax_native_alerts"
        private const val CHANNEL_ID = "sistemax_authorizations"
        private const val PREFS_NAME = "sistemax_app"
        private const val NOTIFICATION_ID_BASE = 7600

        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<NativeAlertsWorker>(15, TimeUnit.MINUTES)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.UPDATE,
                request
            )
        }
    }

    override suspend fun doWork(): Result {
        val prefs = applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val apiUrl = prefs.getString("tracking_api_url", "").orEmpty()
        val token = prefs.getString("tracking_agent_token", "").orEmpty()
        if (apiUrl.isBlank() || token.isBlank()) {
            return Result.success()
        }

        return try {
            val sinceId = prefs.getLong("native_alert_last_id", 0L)
            val items = TrackingApiClient(apiUrl).pollAgentAlerts(token, sinceId, 10)
            if (items.isNotEmpty()) {
                val newestId = items.maxOf { it.id }
                prefs.edit().putLong("native_alert_last_id", newestId).apply()
                items.lastOrNull()?.let { showNotification(it) }
            }
            Result.success()
        } catch (_: Throwable) {
            Result.retry()
        }
    }

    private fun showNotification(alert: NativeAlert) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(applicationContext, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }

        createChannel()
        val intent = Intent(applicationContext, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra("open_url", alert.url)
            putExtra("open_section", "authorizations")
        }
        val pendingIntent = PendingIntent.getActivity(
            applicationContext,
            alert.id.toInt(),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val notification = NotificationCompat.Builder(applicationContext, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_dialog_alert)
            .setContentTitle(alert.title.ifBlank { "Autorizacion pendiente" })
            .setContentText(alert.body.ifBlank { "Tenes una solicitud pendiente en autorizaciones." })
            .setStyle(NotificationCompat.BigTextStyle().bigText(alert.body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setAutoCancel(true)
            .setDefaults(NotificationCompat.DEFAULT_ALL)
            .setContentIntent(pendingIntent)
            .build()

        NotificationManagerCompat.from(applicationContext)
            .notify(NOTIFICATION_ID_BASE + (alert.id % 1000).toInt(), notification)
    }

    private fun createChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = applicationContext.getSystemService(NotificationManager::class.java)
        val channel = NotificationChannel(
            CHANNEL_ID,
            "Autorizaciones SistemaX",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Solicitudes de autorizacion pendientes"
            enableVibration(true)
        }
        manager.createNotificationChannel(channel)
    }
}
