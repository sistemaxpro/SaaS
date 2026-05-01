package pro.sistemax.main

import android.content.Intent

object AssistMediaProjectionStore {
    var resultCode: Int = 0
    var resultData: Intent? = null

    fun save(code: Int, data: Intent) {
        resultCode = code
        resultData = Intent(data)
    }

    fun clear() {
        resultCode = 0
        resultData = null
    }

    fun isReady(): Boolean = resultCode != 0 && resultData != null
}
