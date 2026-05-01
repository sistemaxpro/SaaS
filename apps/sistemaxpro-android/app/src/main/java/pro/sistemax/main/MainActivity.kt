package pro.sistemax.main

import android.Manifest
import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.media.projection.MediaProjectionManager
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Looper
import android.provider.Settings
import android.util.Base64
import android.util.DisplayMetrics
import android.view.MotionEvent
import android.view.View
import android.webkit.JavascriptInterface
import android.webkit.GeolocationPermissions
import android.webkit.PermissionRequest
import android.webkit.ValueCallback
import android.webkit.CookieManager
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import pro.sistemax.main.databinding.ActivityMainBinding
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.Locale
import java.util.UUID
import kotlin.math.abs

class MainActivity : AppCompatActivity() {

    companion object {
        private const val PREFS_APP = "sistemax_app"
        private const val PREF_TRACKING_LOCATION_PROMPTED = "tracking_location_prompted_v1"
        private const val PREF_TRACKING_BACKGROUND_PROMPTED = "tracking_background_prompted_v1"
        private const val PREF_LAST_SUCCESSFUL_URL = "last_successful_url"
        private const val DEFAULT_ASSIST_API_URL = "https://sistemax.pro/public/api/assist.php"
        private const val DEFAULT_TRACKING_API_URL = "https://sistemax.pro/public/api/mobile_tracking.php"
        private const val DEFAULT_TRACKING_ICON_TYPE = "moto"
        private const val DEFAULT_TRACKING_ICON_COLOR = "#06b6d4"
    }

    private lateinit var binding: ActivityMainBinding
    private lateinit var assistWebRtcManager: AssistWebRtcManager
    private val startUrl = BuildConfig.START_URL
    private var appVersionName = "0.0.0"
    private var appVersionCode = 0L
    private var pullStartY = 0f
    private var pullStartX = 0f
    private var blockPullDown = false
    @Volatile private var autoSetupRunning = false
    @Volatile private var assistSyncBusy = false
    private var assistSyncJob: Job? = null
    private var lastAssistPromptAt = 0L
    private lateinit var printerManager: BluetoothPrinterManager
    @Volatile private var showingOfflineFallback = false

    /* ── File chooser (para <input type="file"> / tomar foto) ── */
    private var fileUploadCallback: ValueCallback<Array<Uri>>? = null

    private val fileChooserLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val cb = fileUploadCallback
        fileUploadCallback = null
        if (result.resultCode == RESULT_OK) {
            val uris = mutableListOf<Uri>()
            result.data?.data?.let { uris.add(it) }
            result.data?.clipData?.let { clip ->
                for (i in 0 until clip.itemCount) uris.add(clip.getItemAt(i).uri)
            }
            cb?.onReceiveValue(if (uris.isNotEmpty()) uris.toTypedArray() else null)
        } else {
            cb?.onReceiveValue(null)
        }
    }

    private val screenCaptureLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode != RESULT_OK || result.data == null) {
            Toast.makeText(this, "Captura cancelada", Toast.LENGTH_SHORT).show()
            return@registerForActivityResult
        }
        AssistMediaProjectionStore.save(result.resultCode, result.data!!)
        syncAssistSessions(true)
    }

    /* ── Permisos runtime genéricos ── */
    private var pendingPermissionRequest: PermissionRequest? = null
    private var pendingGeoCallback: GeolocationPermissions.Callback? = null
    private var pendingGeoOrigin: String? = null

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { results ->
        /* WebView permission request (cámara / micrófono) */
        pendingPermissionRequest?.let { req ->
            val granted = mutableListOf<String>()
            if (results[Manifest.permission.CAMERA] == true)
                granted.add(PermissionRequest.RESOURCE_VIDEO_CAPTURE)
            if (results[Manifest.permission.RECORD_AUDIO] == true)
                granted.add(PermissionRequest.RESOURCE_AUDIO_CAPTURE)
            if (granted.isNotEmpty()) req.grant(granted.toTypedArray()) else req.deny()
            pendingPermissionRequest = null
        }

        /* Geolocation callback */
        pendingGeoCallback?.let { cb ->
            val fine = results[Manifest.permission.ACCESS_FINE_LOCATION] == true
            val coarse = results[Manifest.permission.ACCESS_COARSE_LOCATION] == true
            cb.invoke(pendingGeoOrigin, fine || coarse, false)
            pendingGeoCallback = null
            pendingGeoOrigin = null
        }
    }

    /* ── Permiso de notificaciones (Android 13+) ── */
    private val notifPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { /* no-op, solo necesitamos que aparezca el dialog */ }

    private val initialLocationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { results ->
        val fine = results[Manifest.permission.ACCESS_FINE_LOCATION] == true
        val coarse = results[Manifest.permission.ACCESS_COARSE_LOCATION] == true
        markLocationPrompted()
        if (fine || coarse) {
            maybeRequestBackgroundLocationPermission()
        }
    }

    private val backgroundLocationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) {
        markBackgroundLocationPrompted()
    }

    private val bluetoothPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { /* no-op */ }

    /* ═══════════════════════════════════════════════ */
    /*                    LIFECYCLE                    */
    /* ═══════════════════════════════════════════════ */

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        printerManager = BluetoothPrinterManager(this)
        assistWebRtcManager = AssistWebRtcManager(
            context = this,
            scope = lifecycleScope,
            apiUrlProvider = { assistApiUrl() },
            agentTokenProvider = { appPrefs().getString("assist_agent_token", "").orEmpty() },
            onStatus = { message -> appPrefs().edit().putString("assist_last_status", message).apply() }
        )
        AssistFirebase.initializeIfPossible(this)
        requestNotificationPermission()
        requestInitialTrackingPermissionsIfNeeded()
        requestInitialBluetoothPermissionsIfNeeded()
        val packageInfo = packageManager.getPackageInfo(packageName, 0)
        appVersionName = packageInfo.versionName ?: "0.0.0"
        @Suppress("DEPRECATION")
        appVersionCode = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            packageInfo.longVersionCode
        } else {
            packageInfo.versionCode.toLong()
        }
        configureWebView()
        clearLegacyWebCacheIfNeeded()
        binding.tvVersion.text = "v$appVersionName ($appVersionCode)"
        handleSetupIntent(intent)
        AssistFirebase.syncTokenIfPossible(this)
        ensureTrackingServicesIfReady()

        if (savedInstanceState == null) {
            binding.webView.loadUrl(resolveLaunchUrl(intent))
        } else {
            binding.webView.restoreState(savedInstanceState)
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleSetupIntent(intent)
        val launchUrl = resolveLaunchUrl(intent)
        if (::binding.isInitialized) {
            binding.webView.loadUrl(launchUrl)
        }
    }

    override fun onResume() {
        super.onResume()
        AssistFirebase.syncTokenIfPossible(this)
        ensureTrackingServicesIfReady()
    }

    override fun onPause() {
        assistSyncJob?.cancel()
        assistSyncJob = null
        super.onPause()
    }

    override fun onDestroy() {
        assistSyncJob?.cancel()
        super.onDestroy()
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        if (binding.webView.canGoBack()) binding.webView.goBack()
        else super.onBackPressed()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        binding.webView.saveState(outState)
    }

    /* ═══════════════════════════════════════════════ */
    /*               WEBVIEW CONFIG                   */
    /* ═══════════════════════════════════════════════ */

    @SuppressLint("SetJavaScriptEnabled")
    private fun configureWebView() {
        binding.webView.overScrollMode = View.OVER_SCROLL_NEVER
        binding.webView.isVerticalScrollBarEnabled = true
        binding.webView.setOnTouchListener { v, event ->
            val web = v as? WebView ?: return@setOnTouchListener false
            when (event.actionMasked) {
                MotionEvent.ACTION_DOWN -> {
                    pullStartY = event.y
                    pullStartX = event.x
                    blockPullDown = false
                }
                MotionEvent.ACTION_MOVE -> {
                    val dy = event.y - pullStartY
                    val dx = event.x - pullStartX
                    val atTop = !web.canScrollVertically(-1) || web.scrollY <= 0
                    if (!blockPullDown && atTop && dy > 18f && abs(dy) > (abs(dx) * 1.2f)) {
                        // Freno suave: solo bloquea tirón hacia abajo desde el tope.
                        blockPullDown = true
                    }
                    if (blockPullDown) return@setOnTouchListener true
                }
                MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> {
                    blockPullDown = false
                }
            }
            false
        }

        with(binding.webView.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            setSupportZoom(false)
            builtInZoomControls = false
            displayZoomControls = false
            allowFileAccess = true
            allowContentAccess = true
            mediaPlaybackRequiresUserGesture = false
            javaScriptCanOpenWindowsAutomatically = true
            setGeolocationEnabled(true)
            userAgentString = "$userAgentString SistemaxProAndroid/$appVersionName"
        }

        // Bridge JS para abrir enlaces en navegador externo desde la web app.
        binding.webView.addJavascriptInterface(AndroidBridge(), "Android")

        binding.webView.webViewClient = object : WebViewClient() {
            @Deprecated("Deprecated in Java")
            override fun shouldOverrideUrlLoading(view: WebView?, url: String?): Boolean {
                val raw = url ?: return false
                val uri = try { Uri.parse(raw) } catch (_: Exception) { return false }
                return handleUrlOverride(view, uri)
            }
            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                val uri = request?.url ?: return false
                return handleUrlOverride(view, uri)
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                val current = url?.trim().orEmpty()
                if (!showingOfflineFallback && isRememberableWebUrl(current)) {
                    appPrefs().edit().putString(PREF_LAST_SUCCESSFUL_URL, current).apply()
                }
            }

            @Deprecated("Deprecated in Java")
            override fun onReceivedError(
                view: WebView?,
                errorCode: Int,
                description: String?,
                failingUrl: String?
            ) {
                super.onReceivedError(view, errorCode, description, failingUrl)
                if (view == null) return
                if (failingUrl.isNullOrBlank()) return
                if (!view.url.isNullOrBlank() && view.url != failingUrl) return
                if (!isOfflineErrorCode(errorCode)) return
                showOfflineFallback(view, failingUrl)
            }

            override fun onReceivedError(
                view: WebView?,
                request: WebResourceRequest?,
                error: android.webkit.WebResourceError?
            ) {
                super.onReceivedError(view, request, error)
                if (view == null) return
                if (request?.isForMainFrame != true) return
                val code = error?.errorCode ?: return
                if (!isOfflineErrorCode(code)) return
                showOfflineFallback(view, request.url?.toString())
            }
        }

        binding.webView.webChromeClient = object : WebChromeClient() {

            /* ── Progreso ── */
            override fun onProgressChanged(view: WebView?, newProgress: Int) {
                binding.progress.visibility = if (newProgress in 1..99) View.VISIBLE else View.GONE
                binding.progress.progress = newProgress
            }

            /* ── Permisos de cámara / micrófono solicitados por JS ── */
            override fun onPermissionRequest(request: PermissionRequest?) {
                request ?: return
                runOnUiThread {
                    val resources = request.resources ?: emptyArray()
                    val needsCamera = resources.contains(PermissionRequest.RESOURCE_VIDEO_CAPTURE)
                    val needsAudio = resources.contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE)

                    val permsNeeded = mutableListOf<String>()
                    if (needsCamera && !hasPerm(Manifest.permission.CAMERA))
                        permsNeeded.add(Manifest.permission.CAMERA)
                    if (needsAudio && !hasPerm(Manifest.permission.RECORD_AUDIO))
                        permsNeeded.add(Manifest.permission.RECORD_AUDIO)

                    if (permsNeeded.isEmpty()) {
                        val granted = mutableListOf<String>()
                        if (needsCamera) granted.add(PermissionRequest.RESOURCE_VIDEO_CAPTURE)
                        if (needsAudio) granted.add(PermissionRequest.RESOURCE_AUDIO_CAPTURE)
                        if (granted.isNotEmpty()) request.grant(granted.toTypedArray()) else request.deny()
                    } else {
                        pendingPermissionRequest?.deny()
                        pendingPermissionRequest = request
                        permissionLauncher.launch(permsNeeded.toTypedArray())
                    }
                }
            }

            override fun onPermissionRequestCanceled(request: PermissionRequest?) {
                if (pendingPermissionRequest === request) pendingPermissionRequest = null
            }

            /* ── Geolocalización solicitada por JS ── */
            override fun onGeolocationPermissionsShowPrompt(
                origin: String?,
                callback: GeolocationPermissions.Callback?
            ) {
                if (callback == null) return
                val hasFine = hasPerm(Manifest.permission.ACCESS_FINE_LOCATION)
                val hasCoarse = hasPerm(Manifest.permission.ACCESS_COARSE_LOCATION)

                if (hasFine || hasCoarse) {
                    callback.invoke(origin, true, false)
                } else {
                    pendingGeoCallback = callback
                    pendingGeoOrigin = origin
                    permissionLauncher.launch(
                        arrayOf(
                            Manifest.permission.ACCESS_FINE_LOCATION,
                            Manifest.permission.ACCESS_COARSE_LOCATION
                        )
                    )
                }
            }

            /* ── File chooser (<input type="file">) ── */
            override fun onShowFileChooser(
                webView: WebView?,
                filePathCallback: ValueCallback<Array<Uri>>?,
                fileChooserParams: FileChooserParams?
            ): Boolean {
                fileUploadCallback?.onReceiveValue(null)
                fileUploadCallback = filePathCallback

                try {
                    val intent = fileChooserParams?.createIntent()
                    if (intent != null) {
                        fileChooserLauncher.launch(intent)
                        return true
                    }
                } catch (_: Exception) { }

                fileUploadCallback = null
                return false
            }
        }
    }

    private fun clearLegacyWebCacheIfNeeded() {
        val prefs = appPrefs()
        val lastVersion = prefs.getLong("last_version_code", 0L)
        if (lastVersion < appVersionCode) {
            CookieManager.getInstance().removeAllCookies(null)
            CookieManager.getInstance().flush()
            binding.webView.clearCache(true)
            binding.webView.clearHistory()
            prefs.edit().putLong("last_version_code", appVersionCode).apply()
        }
    }

    private fun appPrefs() = getSharedPreferences(PREFS_APP, MODE_PRIVATE)

    private fun resolveLaunchUrl(intent: Intent?): String {
        val extraOpenUrl = intent?.getStringExtra("open_url")?.trim().orEmpty()
        if (extraOpenUrl.isNotBlank()) {
            return resolveOfflineAwareLaunchUrl(sanitizeLaunchUrl(extraOpenUrl))
        }
        val data = intent?.data ?: return startUrl
        val scheme = (data.scheme ?: "").lowercase()
        val host = (data.host ?: "").lowercase()
        if (scheme != "sistemaxpro" || (host != "open" && host != "setup")) {
            return resolveOfflineAwareLaunchUrl(startUrl)
        }

        if (host == "setup") {
            val target = data.getQueryParameter("url")?.trim().orEmpty()
            return resolveOfflineAwareLaunchUrl(if (target.isNotEmpty()) sanitizeLaunchUrl(target) else startUrl)
        }

        val target = data.getQueryParameter("url")?.trim().orEmpty()
        return resolveOfflineAwareLaunchUrl(sanitizeLaunchUrl(target))
    }

    private fun sanitizeLaunchUrl(target: String): String {
        val raw = target.trim()
        if (raw.isEmpty()) return startUrl
        if (raw.startsWith("/")) {
            return "https://sistemax.pro$raw"
        }

        val parsed = try { Uri.parse(raw) } catch (_: Exception) { null } ?: return startUrl

        val parsedScheme = (parsed.scheme ?: "").lowercase()
        return if (parsedScheme == "http" || parsedScheme == "https") {
            parsed.toString()
        } else {
            startUrl
        }
    }

    private fun resolveOfflineAwareLaunchUrl(defaultUrl: String): String {
        if (isNetworkAvailable()) return defaultUrl
        val remembered = appPrefs().getString(PREF_LAST_SUCCESSFUL_URL, "").orEmpty().trim()
        return if (isRememberableWebUrl(remembered)) remembered else defaultUrl
    }

    private fun handleSetupIntent(intent: Intent?) {
        val data = intent?.data ?: return
        val scheme = (data.scheme ?: "").lowercase(Locale.ROOT)
        val host = (data.host ?: "").lowercase(Locale.ROOT)
        if (scheme != "sistemaxpro" || host != "setup") {
            return
        }

        val setupToken = data.getQueryParameter("setup_token")?.trim().orEmpty()
        if (setupToken.isBlank()) {
            return
        }

        val prefs = appPrefs()
        val trackingApi = data.getQueryParameter("tracking_api_url")?.trim().orEmpty()
        val deviceName = data.getQueryParameter("tracking_device_name")?.trim().orEmpty()
        val markerLabel = data.getQueryParameter("tracking_marker_label")?.trim().orEmpty()
        val iconType = data.getQueryParameter("tracking_icon_type")?.trim().orEmpty()
        val iconColor = data.getQueryParameter("tracking_icon_color")?.trim().orEmpty()

        prefs.edit().apply {
            if (trackingApi.isNotBlank()) putString("tracking_api_url", trackingApi)
            if (deviceName.isNotBlank()) putString("tracking_device_name", deviceName)
            if (markerLabel.isNotBlank()) putString("tracking_marker_label", markerLabel)
            if (iconType.isNotBlank()) putString("tracking_icon_type", iconType)
            if (iconColor.isNotBlank()) putString("tracking_icon_color", iconColor.lowercase(Locale.ROOT))
        }.apply()

        autoBootstrapTracking(setupToken)
    }

    private fun autoBootstrapTracking(setupToken: String) {
        if (autoSetupRunning) return
        autoSetupRunning = true
        lifecycleScope.launch {
            try {
                val apiUrl = trackingApiUrl()
                val issue = withContext(Dispatchers.IO) {
                    TrackingApiClient(apiUrl).issueTrackingBootstrap(setupToken)
                }
                if (issue.bootstrapToken.isBlank()) {
                    throw IllegalStateException("No se pudo obtener bootstrap de tracking")
                }
                val result = withContext(Dispatchers.IO) {
                    TrackingApiClient(apiUrl).trackingRegisterBootstrap(
                        bootstrapToken = issue.bootstrapToken,
                        deviceUuid = loadTrackingDeviceUuid(),
                        deviceName = loadTrackingDeviceName(),
                        markerLabel = loadTrackingMarkerLabel(),
                        iconType = selectedTrackingIconType(),
                        iconColor = selectedTrackingIconColor(),
                        hostName = Build.MODEL ?: "Android",
                        platform = "android",
                        platformVersion = "Android ${Build.VERSION.RELEASE ?: "?"}",
                        architecture = Build.SUPPORTED_ABIS.firstOrNull().orEmpty(),
                        appVersion = appVersionName,
                        trackingEnabled = appPrefs().getBoolean("tracking_enabled", false)
                    )
                }

                appPrefs().edit()
                    .putLong("tracking_device_id", result.deviceId)
                    .putString("tracking_agent_token", result.agentToken)
                    .putString("tracking_token_expires_at", result.expiresAt)
                    .putString("tracking_api_url", apiUrl)
                    .apply()

                AssistFirebase.syncTokenIfPossible(this@MainActivity)
                NativeAlertsWorker.schedule(this@MainActivity)
                ensureTrackingServicesIfReady()
                Toast.makeText(
                    this@MainActivity,
                    "SistemaX Pro quedó configurado para tracking, notificaciones e impresión",
                    Toast.LENGTH_SHORT
                ).show()
            } catch (t: Throwable) {
                Toast.makeText(
                    this@MainActivity,
                    "No se pudo configurar tracking: ${t.message ?: "desconocido"}",
                    Toast.LENGTH_LONG
                ).show()
            } finally {
                autoSetupRunning = false
            }
        }
    }

    private fun markLocationPrompted() {
        appPrefs().edit().putBoolean(PREF_TRACKING_LOCATION_PROMPTED, true).apply()
    }

    private fun markBackgroundLocationPrompted() {
        appPrefs().edit().putBoolean(PREF_TRACKING_BACKGROUND_PROMPTED, true).apply()
    }

    private fun requestInitialTrackingPermissionsIfNeeded() {
        val prefs = appPrefs()
        val hasForegroundLocation =
            hasPerm(Manifest.permission.ACCESS_FINE_LOCATION) ||
            hasPerm(Manifest.permission.ACCESS_COARSE_LOCATION)

        if (!hasForegroundLocation && !prefs.getBoolean(PREF_TRACKING_LOCATION_PROMPTED, false)) {
            initialLocationPermissionLauncher.launch(
                arrayOf(
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION
                )
            )
            return
        }

        if (hasForegroundLocation) {
            maybeRequestBackgroundLocationPermission()
        }
    }

    private fun maybeRequestBackgroundLocationPermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            markBackgroundLocationPrompted()
            ensureTrackingServicesIfReady()
            return
        }
        if (hasPerm(Manifest.permission.ACCESS_BACKGROUND_LOCATION)) {
            markBackgroundLocationPrompted()
            ensureTrackingServicesIfReady()
            return
        }
        if (appPrefs().getBoolean(PREF_TRACKING_BACKGROUND_PROMPTED, false)) {
            return
        }

        AlertDialog.Builder(this)
            .setTitle("Permitir tracking en segundo plano")
            .setMessage("Para que la geolocalizacion siga funcionando aunque la app no este abierta, Android debe permitir ubicacion en segundo plano.")
            .setNegativeButton("Ahora no") { _, _ ->
                markBackgroundLocationPrompted()
            }
            .setPositiveButton("Continuar") { _, _ ->
                markBackgroundLocationPrompted()
                backgroundLocationPermissionLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            }
            .show()
    }

    /* ═══════════════════════════════════════════════ */
    /*               URL OVERRIDES                    */
    /* ═══════════════════════════════════════════════ */

    private fun handleUrlOverride(view: WebView?, uri: Uri): Boolean {
        val scheme = (uri.scheme ?: "").lowercase()
        val url = uri.toString()

        if (scheme == "http" || scheme == "https") {
            // Forzar salida a navegador externo para búsquedas de Google Imágenes.
            val host = (uri.host ?: "").lowercase()
            val path = (uri.path ?: "").lowercase()
            val tbm = (uri.getQueryParameter("tbm") ?: "").lowercase()
            val isGoogleImageSearch = host.contains("google.") &&
                (tbm == "isch" || path.contains("/search") || path.contains("/imgres"))
            if (isGoogleImageSearch) {
                return openExternalUrl(url)
            }

            if (uri.getQueryParameter("external") == "1") {
                return openExternalUrl(url)
            }
            return false
        }

        return try {
            if (scheme == "intent") {
                startActivity(Intent.parseUri(url, Intent.URI_INTENT_SCHEME))
            } else {
                startActivity(Intent(Intent.ACTION_VIEW, uri))
            }
            true
        } catch (_: ActivityNotFoundException) {
            Toast.makeText(this, "No se encontró app para abrir este enlace", Toast.LENGTH_SHORT).show()
            true
        } catch (_: Exception) {
            Toast.makeText(this, "No se pudo abrir el enlace", Toast.LENGTH_SHORT).show()
            true
        }
    }

    /* ═══════════════════════════════════════════════ */
    /*                 HELPERS                         */
    /* ═══════════════════════════════════════════════ */

    private fun hasPerm(perm: String): Boolean =
        ContextCompat.checkSelfPermission(this, perm) == PackageManager.PERMISSION_GRANTED

    private fun openExternalUrl(rawUrl: String?): Boolean {
        val target = rawUrl?.trim().orEmpty()
        if (target.isEmpty()) return false
        val uri = try {
            Uri.parse(target)
        } catch (_: Exception) {
            return false
        }

        return try {
            val intent = Intent(Intent.ACTION_VIEW, uri).apply {
                addCategory(Intent.CATEGORY_BROWSABLE)
            }
            val chooser = Intent.createChooser(intent, "Abrir con")
            startActivity(chooser)
            true
        } catch (_: ActivityNotFoundException) {
            Toast.makeText(this, "No se pudo abrir en navegador externo", Toast.LENGTH_SHORT).show()
            false
        } catch (_: Exception) {
            false
        }
    }

    private fun openExternalUrlSync(rawUrl: String?): Boolean {
        if (Looper.myLooper() == Looper.getMainLooper()) {
            return openExternalUrl(rawUrl)
        }
        val latch = CountDownLatch(1)
        var result = false
        runOnUiThread {
            result = openExternalUrl(rawUrl)
            latch.countDown()
        }
        try {
            latch.await(2, TimeUnit.SECONDS)
        } catch (_: InterruptedException) {
            Thread.currentThread().interrupt()
        }
        return result
    }

    private fun isNetworkAvailable(): Boolean {
        return try {
            val cm = getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return false
            val network = cm.activeNetwork ?: return false
            val caps = cm.getNetworkCapabilities(network) ?: return false
            caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        } catch (_: Exception) {
            false
        }
    }

    private fun isOfflineErrorCode(code: Int): Boolean {
        return code == WebViewClient.ERROR_HOST_LOOKUP ||
            code == WebViewClient.ERROR_CONNECT ||
            code == WebViewClient.ERROR_TIMEOUT ||
            code == WebViewClient.ERROR_UNKNOWN ||
            code == WebViewClient.ERROR_FAILED_SSL_HANDSHAKE
    }

    private fun isRememberableWebUrl(url: String): Boolean {
        if (url.isBlank()) return false
        val uri = try { Uri.parse(url) } catch (_: Exception) { return false }
        val host = (uri.host ?: "").lowercase()
        val scheme = (uri.scheme ?: "").lowercase()
        if (scheme != "http" && scheme != "https") return false
        if (!host.contains("sistemax.pro")) return false
        val path = uri.path.orEmpty()
        if (path.contains("/logout")) return false
        return path.startsWith("/public/")
    }

    private fun showOfflineFallback(webView: WebView, failingUrl: String?) {
        showingOfflineFallback = true
        val remembered = appPrefs().getString(PREF_LAST_SUCCESSFUL_URL, "").orEmpty().trim()
        val failingEscaped = JSONObject.quote(failingUrl.orEmpty())
        val hasRemembered = isRememberableWebUrl(remembered)
        val openLastButton = if (hasRemembered) {
            """<button class="secondary" onclick="Android.openLastSuccessfulUrl()">Abrir última pantalla</button>"""
        } else {
            ""
        }
        val html = """
            <!doctype html>
            <html lang="es">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>Sin conexión</title>
              <style>
                body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}
                .card{width:min(100%,420px);background:#111827;border:1px solid rgba(148,163,184,.22);border-radius:18px;padding:22px;box-shadow:0 20px 50px rgba(0,0,0,.35)}
                h1{margin:0 0 10px;font-size:24px}
                p{margin:0 0 10px;line-height:1.45;color:#cbd5e1}
                .muted{font-size:12px;color:#94a3b8;word-break:break-word}
                .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
                button{border:0;border-radius:12px;padding:12px 14px;font-weight:700;cursor:pointer}
                .primary{background:#2563eb;color:#fff}
                .secondary{background:#1f2937;color:#e5e7eb;border:1px solid rgba(148,163,184,.2)}
              </style>
            </head>
            <body>
              <div class="card">
                <h1>Sin conexión</h1>
                <p>No hay internet disponible para abrir la pantalla solicitada.</p>
                <p class="muted">URL: <span id="failing"></span></p>
                <div class="actions">
                  <button class="primary" onclick="window.location.reload()">Reintentar</button>
                  $openLastButton
                </div>
              </div>
              <script>document.getElementById('failing').textContent = $failingEscaped;</script>
            </body>
            </html>
        """.trimIndent()
        webView.loadDataWithBaseURL("https://sistemax.pro/public/offline-native", html, "text/html", "utf-8", null)
    }

    inner class AndroidBridge {
        @JavascriptInterface
        fun openExternalUrlResult(url: String?): Boolean {
            return openExternalUrlSync(url)
        }

        @JavascriptInterface
        fun openExternalResult(url: String?): Boolean {
            return openExternalUrlSync(url)
        }

        @JavascriptInterface
        fun openExternalUrl(url: String?) {
            runOnUiThread { openExternalUrl(url) }
        }

        @JavascriptInterface
        fun openExternal(url: String?) {
            runOnUiThread { openExternalUrl(url) }
        }

        @JavascriptInterface
        fun openLastSuccessfulUrl() {
            val remembered = appPrefs().getString(PREF_LAST_SUCCESSFUL_URL, "").orEmpty().trim()
            runOnUiThread {
                showingOfflineFallback = false
                if (isRememberableWebUrl(remembered)) {
                    binding.webView.loadUrl(remembered)
                } else {
                    Toast.makeText(this@MainActivity, "No hay una pantalla previa guardada", Toast.LENGTH_SHORT).show()
                }
            }
        }

        @JavascriptInterface
        fun printerHealthJson(): String = this@MainActivity.printerHealthJson()

        @JavascriptInterface
        fun printerListJson(): String = this@MainActivity.printerListJson()

        @JavascriptInterface
        fun printerDetailsJson(): String = this@MainActivity.printerDetailsJson()

        @JavascriptInterface
        fun getDefaultPrinter(): String = this@MainActivity.defaultPrinterName()

        @JavascriptInterface
        fun printRawJson(printerName: String?, base64Data: String?): String {
            return this@MainActivity.printRawJson(printerName, base64Data)
        }
    }

    private fun requestNotificationPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (!hasPerm(Manifest.permission.POST_NOTIFICATIONS)) {
                notifPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
            }
        }
    }

    private fun requestInitialBluetoothPermissionsIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) {
            return
        }
        val required = arrayOf(
            Manifest.permission.BLUETOOTH_CONNECT,
            Manifest.permission.BLUETOOTH_SCAN
        )
        val missing = required.filterNot(::hasPerm)
        if (missing.isNotEmpty()) {
            bluetoothPermissionLauncher.launch(required)
        }
    }

    private fun assistApiUrl(): String {
        return appPrefs().getString("assist_api_url", DEFAULT_ASSIST_API_URL).orEmpty()
            .ifBlank { DEFAULT_ASSIST_API_URL }
    }

    private fun loadAssistDeviceUuid(): String {
        val prefs = appPrefs()
        val current = prefs.getString("assist_device_uuid", null)
        if (!current.isNullOrBlank()) return current
        val created = UUID.randomUUID().toString()
        prefs.edit().putString("assist_device_uuid", created).apply()
        return created
    }

    private fun loadAssistDeviceName(): String {
        val prefs = appPrefs()
        val saved = prefs.getString("assist_device_name", null)
        if (!saved.isNullOrBlank()) return saved
        val generated = listOfNotNull(Build.MODEL, Build.DEVICE).joinToString(" / ").ifBlank { "Tablet Android" }
        prefs.edit().putString("assist_device_name", generated).apply()
        return generated
    }

    private fun trackingApiUrl(): String {
        return appPrefs().getString("tracking_api_url", DEFAULT_TRACKING_API_URL).orEmpty()
            .ifBlank { DEFAULT_TRACKING_API_URL }
    }

    private fun loadTrackingDeviceUuid(): String {
        val prefs = appPrefs()
        val current = prefs.getString("tracking_device_uuid", null)
        if (!current.isNullOrBlank()) return current
        val created = UUID.randomUUID().toString()
        prefs.edit().putString("tracking_device_uuid", created).apply()
        return created
    }

    private fun loadTrackingDeviceName(): String {
        val prefs = appPrefs()
        val saved = prefs.getString("tracking_device_name", null)
        if (!saved.isNullOrBlank()) return saved
        val generated = listOfNotNull(Build.MODEL, "GPS").joinToString(" / ").ifBlank { "Android Tracking" }
        prefs.edit().putString("tracking_device_name", generated).apply()
        return generated
    }

    private fun loadTrackingMarkerLabel(): String {
        return appPrefs().getString("tracking_marker_label", "")?.trim().orEmpty()
    }

    private fun selectedTrackingIconType(): String {
        return appPrefs().getString("tracking_icon_type", DEFAULT_TRACKING_ICON_TYPE).orEmpty()
            .ifBlank { DEFAULT_TRACKING_ICON_TYPE }
    }

    private fun selectedTrackingIconColor(): String {
        return appPrefs().getString("tracking_icon_color", DEFAULT_TRACKING_ICON_COLOR).orEmpty()
            .ifBlank { DEFAULT_TRACKING_ICON_COLOR }
    }

    private fun ensureTrackingServicesIfReady() {
        val prefs = appPrefs()
        val token = prefs.getString("tracking_agent_token", "").orEmpty()
        if (token.isBlank()) {
            return
        }
        NativeAlertsWorker.schedule(this)
        if (hasPerm(Manifest.permission.ACCESS_FINE_LOCATION) || hasPerm(Manifest.permission.ACCESS_COARSE_LOCATION)) {
            MobileTrackingService.start(this)
            lifecycleScope.launch(Dispatchers.IO) {
                runCatching {
                    TrackingApiClient(trackingApiUrl()).trackingHeartbeat(
                        agentToken = token,
                        status = "online",
                        trackingEnabled = true,
                        markerLabel = loadTrackingMarkerLabel(),
                        iconType = selectedTrackingIconType(),
                        iconColor = selectedTrackingIconColor(),
                        ipLocal = null,
                        batteryPct = null,
                        networkType = null,
                        locationMode = if (hasPerm(Manifest.permission.ACCESS_BACKGROUND_LOCATION) || Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) "background_granted" else "foreground_only"
                    )
                }
            }
        }
    }

    private fun ensureAssistServicesIfReady() = Unit

    private fun startAssistSyncLoop() = Unit

    private fun syncAssistSessions(verbose: Boolean) {
        if (verbose) {
            Toast.makeText(this@MainActivity, "Asistencia remota deprecada en SistemaX Pro", Toast.LENGTH_SHORT).show()
        }
    }

    private suspend fun consumeAssistSignals(sessionId: Long, signals: JSONArray) {
        if (sessionId <= 0L || signals.length() == 0) return
        for (index in 0 until signals.length()) {
            val signal = signals.optJSONObject(index) ?: continue
            when (signal.optString("signal_type")) {
                "offer" -> {
                    assistWebRtcManager.handleOffer(sessionId, signal.optJSONObject("payload") ?: JSONObject())
                    withContext(Dispatchers.IO) {
                        TrackingApiClient(assistApiUrl()).sessionState(
                            appPrefs().getString("assist_agent_token", "").orEmpty(),
                            sessionId,
                            "active",
                            "webrtc",
                            false
                        )
                    }
                }
                "ice-candidate" -> assistWebRtcManager.addIceCandidate(signal.optJSONObject("payload") ?: JSONObject())
            }
        }
    }

    private fun maybeRequestAssistScreenCapture(sessionId: Long) {
        val now = System.currentTimeMillis()
        if (now - lastAssistPromptAt < 15_000L) {
            return
        }
        lastAssistPromptAt = now
        Toast.makeText(
            this,
            "Soporte solicitó acceso remoto. Aceptá compartir pantalla para continuar.",
            Toast.LENGTH_LONG
        ).show()
        requestScreenCapture()
    }

    private fun requestScreenCapture() {
        val manager = getSystemService(MediaProjectionManager::class.java)
        screenCaptureLauncher.launch(manager.createScreenCaptureIntent())
    }

    private fun buildAssistCapabilities(): JSONObject {
        return JSONObject().apply {
            put("can_screen_capture", true)
            put("can_input_control", false)
            put("can_file_transfer", false)
            put("can_clipboard_sync", false)
            put("can_audio_stream", false)
            put("can_unattended", false)
            put("requires_local_consent", true)
            put("permissions_screen", false)
            put("permissions_accessibility", false)
            put("permissions_input_monitoring", false)
            put("accessibility_service_live", false)
            put("screen_share", true)
            put("webrtc", false)
            put("notifications", true)
            put("tracking", appPrefs().getString("tracking_agent_token", "").orEmpty().isNotBlank())
            put("printer_bridge", true)
            put("platform", "android")
        }
    }

    private fun hasBluetoothPrinterPermissions(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) {
            return true
        }
        return hasPerm(Manifest.permission.BLUETOOTH_CONNECT) && hasPerm(Manifest.permission.BLUETOOTH_SCAN)
    }

    private fun ensureDefaultPrinter() {
        val prefs = appPrefs()
        val currentMac = prefs.getString("printer_mac", "").orEmpty()
        if (currentMac.isNotBlank()) {
            return
        }
        val printers = printerManager.listPairedPrinters()
        if (printers.size == 1) {
            rememberPrinter(printers.first())
        }
    }

    private fun rememberPrinter(printer: BluetoothPrinterManager.PairedPrinter) {
        appPrefs().edit()
            .putString("printer_name", printer.name)
            .putString("printer_mac", printer.address)
            .apply()
    }

    private fun decodeBase64Safe(raw: String): ByteArray? {
        val normalized = raw.trim().replace(" ", "+")
        if (normalized.length > 600_000) return null
        return try {
            Base64.decode(normalized, Base64.DEFAULT)
        } catch (_: IllegalArgumentException) {
            try {
                Base64.decode(normalized, Base64.URL_SAFE or Base64.NO_WRAP)
            } catch (_: IllegalArgumentException) {
                null
            }
        }
    }

    private fun printerHealthJson(): String {
        val ok = hasBluetoothPrinterPermissions()
        val data = JSONObject().apply {
            put("provider", "android")
            put("bluetooth_permissions", ok)
            put("saved_printer", appPrefs().getString("printer_name", "").orEmpty())
        }
        return JSONObject().put("ok", ok).put("data", data).toString()
    }

    private fun printerListJson(): String {
        if (!hasBluetoothPrinterPermissions()) {
            runOnUiThread { requestInitialBluetoothPermissionsIfNeeded() }
            return JSONObject().put("ok", false).put("error", "Concedé permisos Bluetooth para imprimir").toString()
        }
        ensureDefaultPrinter()
        val items = JSONArray()
        printerManager.listPairedPrinters().forEach { printer ->
            items.put(printer.name)
        }
        return JSONObject().put("ok", true).put("data", items).toString()
    }

    private fun printerDetailsJson(): String {
        if (!hasBluetoothPrinterPermissions()) {
            runOnUiThread { requestInitialBluetoothPermissionsIfNeeded() }
            return JSONObject().put("ok", false).put("error", "Concedé permisos Bluetooth para imprimir").toString()
        }
        ensureDefaultPrinter()
        val items = JSONArray()
        printerManager.listPairedPrinters().forEach { printer ->
            items.put(
                JSONObject()
                    .put("name", printer.name)
                    .put("address", printer.address)
                    .put("provider", "android_bluetooth")
            )
        }
        return JSONObject().put("ok", true).put("data", items).toString()
    }

    private fun defaultPrinterName(): String {
        if (!hasBluetoothPrinterPermissions()) {
            runOnUiThread { requestInitialBluetoothPermissionsIfNeeded() }
            return ""
        }
        ensureDefaultPrinter()
        val prefs = appPrefs()
        val name = prefs.getString("printer_name", "").orEmpty()
        if (name.isNotBlank()) {
            return name
        }
        val first = printerManager.listPairedPrinters().firstOrNull() ?: return ""
        rememberPrinter(first)
        return first.name
    }

    private fun printRawJson(printerName: String?, base64Data: String?): String {
        if (!hasBluetoothPrinterPermissions()) {
            runOnUiThread { requestInitialBluetoothPermissionsIfNeeded() }
            return JSONObject().put("ok", false).put("error", "Concedé permisos Bluetooth para imprimir").toString()
        }
        val payload = decodeBase64Safe(base64Data.orEmpty())
            ?: return JSONObject().put("ok", false).put("error", "Contenido de impresion invalido").toString()
        val printer = printerManager.findPrinterByNameOrAddress(printerName.orEmpty())
            ?: printerManager.listPairedPrinters().firstOrNull()
            ?: return JSONObject().put("ok", false).put("error", "No hay impresoras Bluetooth emparejadas").toString()
        rememberPrinter(printer)
        val result = printerManager.print(printer.address, payload)
        return if (result.isSuccess) {
            JSONObject()
                .put("ok", true)
                .put("data", JSONObject().put("printer", printer.name).put("address", printer.address))
                .toString()
        } else {
            JSONObject()
                .put("ok", false)
                .put("error", result.exceptionOrNull()?.message ?: "Error de impresion")
                .toString()
        }
    }

    private data class AssistSyncResult(
        val activeSessionId: Long,
        val pendingConsentId: Long,
        val signals: JSONArray
    )
}
