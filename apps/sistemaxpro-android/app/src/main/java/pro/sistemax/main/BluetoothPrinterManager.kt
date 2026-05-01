package pro.sistemax.main

import android.Manifest
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothSocket
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import java.io.IOException
import java.io.OutputStream
import java.lang.reflect.Method
import java.util.UUID

class BluetoothPrinterManager(private val context: Context) {

    data class PairedPrinter(val name: String, val address: String)

    private val sppUuid: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")
    private val writeChunkSize = 256
    private val writePauseMs = 32L
    private val postConnectPauseMs = 350L

    fun listPairedPrinters(): List<PairedPrinter> {
        val adapter = BluetoothAdapter.getDefaultAdapter() ?: return emptyList()
        if (!hasConnectPermission()) return emptyList()
        val bonded = adapter.bondedDevices ?: emptySet()
        return bonded
            .map { PairedPrinter(it.name ?: "Bluetooth Printer", it.address) }
            .sortedBy { it.name.lowercase() }
    }

    fun findPrinterByNameOrAddress(needle: String): PairedPrinter? {
        val normalized = needle.trim()
        if (normalized.isBlank()) return null
        return listPairedPrinters().firstOrNull {
            it.address.equals(normalized, ignoreCase = true) ||
                it.name.equals(normalized, ignoreCase = true)
        }
    }

    fun print(macAddress: String, payload: ByteArray): Result<Unit> {
        val adapter = BluetoothAdapter.getDefaultAdapter()
            ?: return Result.failure(IllegalStateException("Bluetooth no disponible"))

        if (!hasConnectPermission()) {
            return Result.failure(SecurityException("Permiso BLUETOOTH_CONNECT no otorgado"))
        }

        val device = try {
            adapter.getRemoteDevice(macAddress)
        } catch (e: IllegalArgumentException) {
            return Result.failure(IllegalArgumentException("MAC de impresora invalida"))
        }

        if (adapter.isDiscovering) {
            adapter.cancelDiscovery()
        }

        val attempts = buildSocketFactories(device)
        var lastError: Exception? = null

        for (factory in attempts) {
            var socket: BluetoothSocket? = null
            try {
                socket = factory()
                socket.connect()
                Thread.sleep(postConnectPauseMs)
                val out = socket.outputStream
                writePayload(out, payload)
                return Result.success(Unit)
            } catch (e: Exception) {
                lastError = e
            } finally {
                try {
                    socket?.close()
                } catch (_: IOException) {
                }
            }
        }

        return Result.failure(lastError ?: IOException("No se pudo conectar con la impresora Bluetooth"))
    }

    private fun buildSocketFactories(device: BluetoothDevice): List<() -> BluetoothSocket> {
        val factories = mutableListOf<() -> BluetoothSocket>()
        factories += { device.createRfcommSocketToServiceRecord(sppUuid) }
        factories += { device.createInsecureRfcommSocketToServiceRecord(sppUuid) }
        createReflectionFactory(device)?.let { factories += it }
        return factories
    }

    private fun createReflectionFactory(device: BluetoothDevice): (() -> BluetoothSocket)? {
        return try {
            val method: Method = device.javaClass.getMethod("createRfcommSocket", Int::class.javaPrimitiveType)
            ({ method.invoke(device, 1) as BluetoothSocket })
        } catch (_: Exception) {
            null
        }
    }

    private fun writePayload(out: OutputStream, payload: ByteArray) {
        if (payload.isEmpty()) {
            out.flush()
            return
        }

        var offset = 0
        while (offset < payload.size) {
            val end = minOf(offset + writeChunkSize, payload.size)
            out.write(payload, offset, end - offset)
            out.flush()
            offset = end
            if (offset < payload.size) {
                Thread.sleep(writePauseMs)
            }
        }
    }

    private fun hasConnectPermission(): Boolean {
        return if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.S) {
            ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED
        } else {
            true
        }
    }
}
