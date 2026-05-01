package pro.sistemax.main

import android.accessibilityservice.AccessibilityService
import android.accessibilityservice.GestureDescription
import android.content.Context
import android.graphics.Path
import android.provider.Settings
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo
import org.json.JSONObject
import java.lang.ref.WeakReference

class AssistAccessibilityService : AccessibilityService() {

    companion object {
        private var serviceRef: WeakReference<AssistAccessibilityService>? = null

        fun isEnabled(context: Context): Boolean {
            val enabled = Settings.Secure.getString(
                context.contentResolver,
                Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES
            ).orEmpty()
            val expected = "${context.packageName}/${AssistAccessibilityService::class.java.name}"
            return enabled.split(':').any { it.equals(expected, ignoreCase = true) }
        }

        fun hasLiveService(): Boolean = serviceRef?.get() != null

        fun executeCommand(command: JSONObject): JSONObject {
            val service = serviceRef?.get()
                ?: throw IllegalStateException("Servicio de accesibilidad no disponible")
            return service.executeInternal(command)
        }
    }

    override fun onServiceConnected() {
        super.onServiceConnected()
        serviceRef = WeakReference(this)
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent?) = Unit

    override fun onInterrupt() = Unit

    override fun onDestroy() {
        super.onDestroy()
        if (serviceRef?.get() === this) {
            serviceRef = null
        }
    }

    private fun executeInternal(command: JSONObject): JSONObject {
        return when (command.optString("action").trim().lowercase()) {
            "back" -> runGlobalAction("back", GLOBAL_ACTION_BACK)
            "home" -> runGlobalAction("home", GLOBAL_ACTION_HOME)
            "recents" -> runGlobalAction("recents", GLOBAL_ACTION_RECENTS)
            "notifications" -> runGlobalAction("notifications", GLOBAL_ACTION_NOTIFICATIONS)
            "quick_settings" -> runGlobalAction("quick_settings", GLOBAL_ACTION_QUICK_SETTINGS)
            "tap" -> dispatchTap(command)
            "long_press" -> dispatchTap(command, 650L)
            "swipe" -> dispatchSwipe(command)
            "click_focused" -> clickFocusedNode()
            else -> throw IllegalArgumentException("Acción de control no soportada")
        }
    }

    private fun runGlobalAction(action: String, globalAction: Int): JSONObject {
        return JSONObject()
            .put("ok", performGlobalAction(globalAction))
            .put("action", action)
    }

    private fun clickFocusedNode(): JSONObject {
        val root = rootInActiveWindow ?: throw IllegalStateException("No hay ventana activa")
        val focused = root.findFocus(AccessibilityNodeInfo.FOCUS_INPUT)
            ?: root.findFocus(AccessibilityNodeInfo.FOCUS_ACCESSIBILITY)
            ?: throw IllegalStateException("No hay elemento enfocado")
        return JSONObject()
            .put("ok", focused.performAction(AccessibilityNodeInfo.ACTION_CLICK))
            .put("action", "click_focused")
    }

    private fun dispatchTap(command: JSONObject, defaultDurationMs: Long = 80L): JSONObject {
        val x = command.optDouble("x", Double.NaN)
        val y = command.optDouble("y", Double.NaN)
        if (!x.isFinite() || !y.isFinite()) {
            throw IllegalArgumentException("x e y son requeridos")
        }
        val duration = command.optLong("duration_ms", defaultDurationMs).coerceIn(40L, 5_000L)
        val path = Path().apply { moveTo(x.toFloat(), y.toFloat()) }
        return dispatchGesturePayload(if (duration >= 500L) "long_press" else "tap", path, duration)
    }

    private fun dispatchSwipe(command: JSONObject): JSONObject {
        val startX = command.optDouble("start_x", Double.NaN)
        val startY = command.optDouble("start_y", Double.NaN)
        val endX = command.optDouble("end_x", Double.NaN)
        val endY = command.optDouble("end_y", Double.NaN)
        if (!startX.isFinite() || !startY.isFinite() || !endX.isFinite() || !endY.isFinite()) {
            throw IllegalArgumentException("start_x, start_y, end_x y end_y son requeridos")
        }
        val duration = command.optLong("duration_ms", 260L).coerceIn(80L, 10_000L)
        val path = Path().apply {
            moveTo(startX.toFloat(), startY.toFloat())
            lineTo(endX.toFloat(), endY.toFloat())
        }
        return dispatchGesturePayload("swipe", path, duration)
    }

    private fun dispatchGesturePayload(action: String, path: Path, duration: Long): JSONObject {
        val gesture = GestureDescription.Builder()
            .addStroke(GestureDescription.StrokeDescription(path, 0L, duration))
            .build()
        return JSONObject()
            .put("ok", dispatchGesture(gesture, null, null))
            .put("action", action)
            .put("duration_ms", duration)
    }
}
