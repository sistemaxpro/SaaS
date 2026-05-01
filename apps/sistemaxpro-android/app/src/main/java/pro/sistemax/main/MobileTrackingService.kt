package pro.sistemax.main

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

class MobileTrackingService : Service() {

    companion object {
        private const val CHANNEL_ID = "mobile_tracking"
        private const val NOTIFICATION_ID = 4201
        const val ACTION_START = "pro.sistemax.main.action.START_TRACKING"
        const val ACTION_STOP = "pro.sistemax.main.action.STOP_TRACKING"

        fun start(context: Context) {
            val intent = Intent(context, MobileTrackingService::class.java).apply { action = ACTION_START }
            ContextCompat.startForegroundService(context, intent)
        }

        fun stop(context: Context) {
            val intent = Intent(context, MobileTrackingService::class.java).apply { action = ACTION_STOP }
            context.startService(intent)
        }
    }

    private val prefs by lazy { getSharedPreferences("sistemax_app", Context.MODE_PRIVATE) }
    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private var locationCallback: LocationCallback? = null

    override fun onCreate() {
        super.onCreate()
        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                stopTracking()
                stopSelf()
            }
            else -> startTracking()
        }
        return START_STICKY
    }

    override fun onDestroy() {
        stopTracking()
        super.onDestroy()
    }

    private fun startTracking() {
        if (!hasLocationPermission()) {
            prefs.edit().putBoolean("tracking_enabled", false).apply()
            stopSelf()
            return
        }

        try {
            createNotificationChannel()
            startForeground(NOTIFICATION_ID, buildNotification())
            prefs.edit()
                .putBoolean("tracking_enabled", true)
                .putString("tracking_last_error", "")
                .apply()
        } catch (t: Throwable) {
            prefs.edit()
                .putBoolean("tracking_enabled", false)
                .putString("tracking_last_error", "Foreground tracking: ${t.message ?: "error"}")
                .apply()
            stopSelf()
            return
        }

        if (locationCallback != null) {
            return
        }

        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 60_000L)
            .setMinUpdateIntervalMillis(30_000L)
            .setWaitForAccurateLocation(false)
            .build()

        locationCallback = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                val location = result.lastLocation ?: return
                prefs.edit()
                    .putString("tracking_last_fix_at", isoNow())
                    .putString("tracking_last_lat", location.latitude.toString())
                    .putString("tracking_last_lng", location.longitude.toString())
                    .putFloat("tracking_last_accuracy", location.accuracy)
                    .apply()

                val apiUrl = prefs.getString("tracking_api_url", "").orEmpty()
                val token = prefs.getString("tracking_agent_token", "").orEmpty()
                if (apiUrl.isBlank() || token.isBlank()) {
                    return
                }

                Thread {
                    runCatching {
                        TrackingApiClient(apiUrl).trackingLocationPing(
                            agentToken = token,
                            lat = location.latitude,
                            lng = location.longitude,
                            accuracyM = location.accuracy.toDouble(),
                            speedMps = if (location.hasSpeed()) location.speed.toDouble() else null,
                            headingDeg = if (location.hasBearing()) location.bearing.toDouble() else null,
                            altitudeM = if (location.hasAltitude()) location.altitude else null,
                            batteryPct = prefs.getInt("tracking_battery_pct", -1).takeIf { it >= 0 },
                            provider = location.provider ?: "fused",
                            isMock = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) location.isMock else false,
                            capturedAt = isoNow()
                        )
                    }
                }.start()
            }
        }

        try {
            fusedLocationClient.requestLocationUpdates(request, locationCallback as LocationCallback, mainLooper)
        } catch (t: Throwable) {
            prefs.edit()
                .putBoolean("tracking_enabled", false)
                .putString("tracking_last_error", "Location updates: ${t.message ?: "error"}")
                .apply()
            locationCallback = null
            stopSelf()
        }
    }

    private fun stopTracking() {
        locationCallback?.let { fusedLocationClient.removeLocationUpdates(it) }
        locationCallback = null
        prefs.edit().putBoolean("tracking_enabled", false).apply()
    }

    private fun hasLocationPermission(): Boolean {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
    }

    private fun buildNotification(): Notification {
        val activityIntent = Intent(this, MainActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            activityIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setContentTitle("SistemaX Tracking")
            .setContentText(getString(R.string.tracking_notification_text))
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .build()
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = getSystemService(NotificationManager::class.java)
        val channel = NotificationChannel(
            CHANNEL_ID,
            getString(R.string.tracking_notification_channel),
            NotificationManager.IMPORTANCE_LOW
        )
        manager.createNotificationChannel(channel)
    }

    private fun isoNow(): String {
        return SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("UTC")
        }.format(Date())
    }
}
