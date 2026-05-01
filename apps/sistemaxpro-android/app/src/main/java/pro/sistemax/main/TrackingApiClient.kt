package pro.sistemax.main

import org.json.JSONArray
import org.json.JSONObject
import org.json.JSONTokener
import java.io.BufferedReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

data class TrackingRegistrationResult(
    val deviceId: Long,
    val agentToken: String,
    val expiresAt: String
)

data class AssistRegistrationResult(
    val deviceId: Long,
    val agentToken: String,
    val expiresAt: String
)

data class BootstrapIssueResult(
    val bootstrapToken: String,
    val expiresAt: String
)

data class NativeAlert(
    val id: Long,
    val type: String,
    val title: String,
    val body: String,
    val url: String,
    val createdAt: String
)

data class AssistPendingSession(
    val sessionId: Long,
    val requestId: Long,
    val mode: String,
    val status: String,
    val consentRequired: Boolean,
    val createdAt: String
)

class TrackingApiClient(private val apiUrl: String) {

    fun issueAssistBootstrap(setupToken: String): BootstrapIssueResult {
        val payload = JSONObject().apply {
            put("setup_token", setupToken)
        }
        val data = postJson("client_issue_agent_bootstrap", payload, null)
        return BootstrapIssueResult(
            bootstrapToken = data.optString("bootstrap_token"),
            expiresAt = data.optString("expires_at")
        )
    }

    fun issueTrackingBootstrap(setupToken: String): BootstrapIssueResult {
        val payload = JSONObject().apply {
            put("setup_token", setupToken)
        }
        val data = postJson("issue_bootstrap_token", payload, null)
        return BootstrapIssueResult(
            bootstrapToken = data.optString("bootstrap_token"),
            expiresAt = data.optString("expires_at")
        )
    }

    fun trackingRegisterBootstrap(
        bootstrapToken: String,
        deviceUuid: String,
        deviceName: String,
        markerLabel: String,
        iconType: String,
        iconColor: String,
        hostName: String,
        platform: String,
        platformVersion: String,
        architecture: String,
        appVersion: String,
        trackingEnabled: Boolean
    ): TrackingRegistrationResult {
        val payload = JSONObject().apply {
            put("bootstrap_token", bootstrapToken)
            put("device_uuid", deviceUuid)
            put("device_name", deviceName)
            put("marker_label", markerLabel)
            put("icon_type", iconType)
            put("icon_color", iconColor)
            put("host_name", hostName)
            put("platform", platform)
            put("platform_version", platformVersion)
            put("architecture", architecture)
            put("app_version", appVersion)
            put("tracking_enabled", trackingEnabled)
        }
        val data = postJson("agent_register_bootstrap", payload, null)
        return TrackingRegistrationResult(
            deviceId = data.optLong("device_id", 0L),
            agentToken = data.optString("agent_token"),
            expiresAt = data.optString("expires_at")
        )
    }

    fun registerBootstrap(
        bootstrapToken: String,
        deviceUuid: String,
        deviceName: String,
        hostName: String,
        platform: String,
        platformVersion: String,
        architecture: String,
        agentVersion: String
    ): AssistRegistrationResult {
        val payload = JSONObject().apply {
            put("bootstrap_token", bootstrapToken)
            put("device_uuid", deviceUuid)
            put("device_name", deviceName)
            put("host_name", hostName)
            put("platform", platform)
            put("platform_version", platformVersion)
            put("architecture", architecture)
            put("agent_version", agentVersion)
        }
        val data = postJson("agent_register_bootstrap", payload, null)
        return AssistRegistrationResult(
            deviceId = data.optLong("device_id", 0L),
            agentToken = data.optString("agent_token"),
            expiresAt = data.optString("expires_at")
        )
    }

    fun heartbeat(agentToken: String, status: String, ipLocal: String?, capabilities: JSONObject): JSONObject {
        val payload = JSONObject().apply {
            put("status", status)
            put("ip_local", ipLocal ?: "")
            put("capabilities", capabilities)
        }
        return postJson("agent_heartbeat", payload, agentToken)
    }

    fun trackingHeartbeat(
        agentToken: String,
        status: String,
        trackingEnabled: Boolean,
        markerLabel: String,
        iconType: String,
        iconColor: String,
        ipLocal: String?,
        batteryPct: Int?,
        networkType: String?,
        locationMode: String?
    ): JSONObject {
        val payload = JSONObject().apply {
            put("status", status)
            put("tracking_enabled", trackingEnabled)
            put("marker_label", markerLabel)
            put("icon_type", iconType)
            put("icon_color", iconColor)
            put("ip_local", ipLocal ?: "")
            put("battery_pct", batteryPct)
            put("network_type", networkType ?: "")
            put("location_mode", locationMode ?: "")
        }
        return postJson("agent_heartbeat", payload, agentToken)
    }

    fun trackingLocationPing(
        agentToken: String,
        lat: Double,
        lng: Double,
        accuracyM: Double?,
        speedMps: Double?,
        headingDeg: Double?,
        altitudeM: Double?,
        batteryPct: Int?,
        provider: String?,
        isMock: Boolean,
        capturedAt: String
    ): JSONObject {
        val payload = JSONObject().apply {
            put("lat", lat)
            put("lng", lng)
            put("accuracy_m", accuracyM)
            put("speed_mps", speedMps)
            put("heading_deg", headingDeg)
            put("altitude_m", altitudeM)
            put("battery_pct", batteryPct)
            put("provider", provider ?: "")
            put("is_mock", isMock)
            put("captured_at", capturedAt)
        }
        return postJson("agent_location_ping", payload, agentToken)
    }

    fun pollAgentAlerts(agentToken: String, sinceId: Long = 0L, limit: Int = 10): List<NativeAlert> {
        val payload = JSONObject().apply {
            put("since_id", sinceId)
            put("limit", limit.coerceIn(1, 20))
        }
        val data = postJson("agent_alerts_poll", payload, agentToken)
        val items = data.optJSONArray("items") ?: JSONArray()
        val alerts = mutableListOf<NativeAlert>()
        for (index in 0 until items.length()) {
            val row = items.optJSONObject(index) ?: continue
            alerts += NativeAlert(
                id = row.optLong("id", 0L),
                type = row.optString("type", "info"),
                title = row.optString("title", "Autorizacion pendiente"),
                body = row.optString("body", ""),
                url = row.optString("url", "/public/pos/autorizaciones.php"),
                createdAt = row.optString("created_at", "")
            )
        }
        return alerts
    }

    fun registerFcmToken(agentToken: String, fcmToken: String, platform: String = "android", appVersion: String = ""): JSONObject {
        val payload = JSONObject().apply {
            put("fcm_token", fcmToken)
            put("platform", platform)
            put("app_version", appVersion)
        }
        return postJson("agent_fcm_register", payload, agentToken)
    }

    fun pendingSessions(agentToken: String): List<AssistPendingSession> {
        val data = postJson("agent_pending_sessions", JSONObject(), agentToken)
        return jsonArrayToSessions(data)
    }

    fun sessionConsent(agentToken: String, sessionId: Long, decision: String): JSONObject {
        val payload = JSONObject().apply {
            put("session_id", sessionId)
            put("decision", decision)
        }
        return postJson("agent_session_consent", payload, agentToken)
    }

    fun sessionState(
        agentToken: String,
        sessionId: Long,
        status: String,
        transportType: String,
        relayUsed: Boolean
    ): JSONObject {
        val payload = JSONObject().apply {
            put("session_id", sessionId)
            put("status", status)
            put("transport_type", transportType)
            put("relay_used", relayUsed)
        }
        return postJson("agent_session_state", payload, agentToken)
    }

    fun pullSignals(agentToken: String, sessionId: Long): JSONArray {
        val payload = JSONObject().apply {
            put("session_id", sessionId)
        }
        val data = postJson("agent_pull_signals", payload, agentToken)
        return data.optJSONArray("items") ?: JSONArray()
    }

    fun pushSignal(agentToken: String, sessionId: Long, signalType: String, payload: JSONObject): JSONObject {
        val body = JSONObject().apply {
            put("session_id", sessionId)
            put("signal_type", signalType)
            put("payload", payload)
        }
        return postJson("agent_push_signal", body, agentToken)
    }

    fun pushEvent(
        agentToken: String,
        sessionId: Long,
        eventType: String,
        eventLevel: String = "info",
        payload: JSONObject? = null
    ): JSONObject {
        val body = JSONObject().apply {
            put("session_id", sessionId)
            put("event_type", eventType)
            put("event_level", eventLevel)
            if (payload != null) {
                put("payload", payload)
            }
        }
        return postJson("agent_push_event", body, agentToken)
    }

    private fun postJson(action: String, payload: JSONObject, bearerToken: String?): JSONObject {
        val url = URL(buildUrl(action))
        val connection = (url.openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = 20_000
            readTimeout = 20_000
            doOutput = true
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("Accept", "application/json")
            if (!bearerToken.isNullOrBlank()) {
                setRequestProperty("Authorization", "Bearer $bearerToken")
            }
        }

        try {
            OutputStreamWriter(connection.outputStream, Charsets.UTF_8).use { writer ->
                writer.write(payload.toString())
            }

            val code = connection.responseCode
            val stream = if (code in 200..299) connection.inputStream else connection.errorStream
            val raw = stream?.bufferedReader(Charsets.UTF_8)?.use(BufferedReader::readText).orEmpty()
            val json = parseResponseObject(raw)
            if (!json.optBoolean("ok", false) || code !in 200..299) {
                throw IllegalStateException(json.optString("error").ifBlank { "Error remoto HTTP $code" })
            }
            val data = json.opt("data")
            return when (data) {
                is JSONObject -> data
                is JSONArray -> JSONObject().put("items", data)
                else -> JSONObject()
            }
        } finally {
            connection.disconnect()
        }
    }

    private fun parseResponseObject(raw: String): JSONObject {
        if (raw.isBlank()) {
            return JSONObject()
        }
        val parsed = JSONTokener(raw.trim()).nextValue()
        return when (parsed) {
            is JSONObject -> parsed
            is JSONArray -> JSONObject().put("ok", true).put("data", JSONObject().put("items", parsed))
            is String -> JSONObject().put("ok", false).put("error", parsed)
            null -> JSONObject()
            else -> JSONObject().put("ok", false).put("error", parsed.toString())
        }
    }

    private fun jsonArrayToSessions(data: JSONObject): List<AssistPendingSession> {
        val items = data.optJSONArray("items") ?: JSONArray()
        val sessions = mutableListOf<AssistPendingSession>()
        for (index in 0 until items.length()) {
            val row = items.optJSONObject(index) ?: continue
            sessions += AssistPendingSession(
                sessionId = row.optLong("id", 0L),
                requestId = row.optLong("request_id", 0L),
                mode = row.optString("mode"),
                status = row.optString("status"),
                consentRequired = row.optInt("consent_required", 1) == 1,
                createdAt = row.optString("created_at")
            )
        }
        return sessions
    }

    private fun buildUrl(action: String): String {
        val trimmed = apiUrl.trim()
        val separator = if (trimmed.contains("?")) "&" else "?"
        return trimmed + separator + "action=" + action
    }
}
