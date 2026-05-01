package pro.sistemax.main

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class MobileTrackingBootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        val action = intent?.action ?: return
        if (action != Intent.ACTION_BOOT_COMPLETED && action != Intent.ACTION_MY_PACKAGE_REPLACED) {
            return
        }
        val prefs = context.getSharedPreferences("sistemax_app", Context.MODE_PRIVATE)
        if (prefs.getString("tracking_agent_token", "").orEmpty().isNotBlank()) {
            NativeAlertsWorker.schedule(context)
        }
        if (prefs.getBoolean("tracking_enabled", false) && prefs.getString("tracking_agent_token", "").orEmpty().isNotBlank()) {
            MobileTrackingService.start(context)
        }
    }
}
