package pro.sistemax.main

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import androidx.appcompat.app.AppCompatActivity
import pro.sistemax.main.databinding.ActivityAssistSetupBinding

class AssistSetupActivity : AppCompatActivity() {

    private lateinit var binding: ActivityAssistSetupBinding
    private var pauseMode = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityAssistSetupBinding.inflate(layoutInflater)
        setContentView(binding.root)
        pauseMode = (intent?.data?.getQueryParameter("mode") ?: "").trim().equals("pause", ignoreCase = true)

        binding.btnBackToApp.setOnClickListener {
            startActivity(Intent(this, MainActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            })
            finish()
        }

        binding.btnOpenAccessibility.setOnClickListener {
            startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS))
        }

        binding.btnPauseAssistForPayments.setOnClickListener {
            startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS))
        }

        binding.btnOpenAppSettings.setOnClickListener {
            startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                data = Uri.fromParts("package", packageName, null)
            })
        }

        binding.btnRefreshStatus.setOnClickListener {
            renderStatus()
        }

        renderStatus()
    }

    override fun onResume() {
        super.onResume()
        renderStatus()
    }

    private fun renderStatus() {
        val prefs = getSharedPreferences("sistemax_app", MODE_PRIVATE)
        val accessibilityEnabled = AssistAccessibilityService.isEnabled(this)
        val serviceLive = AssistAccessibilityService.hasLiveService()
        val screenCaptureReady = AssistMediaProjectionStore.isReady()
        val assistRegistered = prefs.getString("assist_agent_token", "").orEmpty().isNotBlank()
        val deviceName = prefs.getString("assist_device_name", "").orEmpty().ifBlank { "Android Assist" }

        binding.tvDeviceName.text = deviceName
        binding.tvAssistRegistered.text = if (assistRegistered) "Registrado" else "Pendiente"
        binding.tvAccessibilityStatus.text = if (accessibilityEnabled) "Activo" else "Pendiente"
        binding.tvAccessibilityLive.text = if (serviceLive) "Conectado" else "Sin conectar"
        binding.tvScreenCaptureStatus.text = if (screenCaptureReady) "Listo" else "Se pedirá al iniciar sesión remota"
        binding.tvOverallMessage.text = if (pauseMode) {
            "Si Pay Protect o una app bancaria se bloquea, desactivá Accesibilidad de SistemaX Pro temporalmente y luego volvé a abrir esa app."
        } else if (accessibilityEnabled) {
            "Assist ya puede recibir comandos básicos de control remoto."
        } else {
            "Activá Accesibilidad para habilitar control remoto asistido."
        }
        binding.tvPaymentWarning.text = if (accessibilityEnabled) {
            "Modo recomendado para apps de pago: tocar 'Pausar Assist para pagos', desactivar SistemaX Pro en Accesibilidad y volver a la app bancaria."
        } else {
            "Accesibilidad ya está desactivada. Las apps de pago no deberían detectar control remoto activo."
        }
    }
}
