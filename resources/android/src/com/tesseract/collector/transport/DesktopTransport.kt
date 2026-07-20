package com.tesseract.collector.transport

import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

class InvalidSessionException(url: String) : RuntimeException("Desktop transport rejected session for $url")

/**
 * Owns the wire to the Tesseract desktop hub.
 *
 * Session, telemetry, and command traffic use the desktop HTTP relay. On an
 * emulator the relay can be reached through an `adb reverse` tunnel; physical
 * devices use the reachable relay URL supplied by the pairing file.
 */
class DesktopTransport(
    private val host: String,
    private val relayPort: Int,
    private val appName: String,
    private val appUrl: String,
    private val projectKey: String,
    private val projectPath: String,
    private val relayUrl: String,
    private val capabilities: JSONArray,
) {
    private fun relayBase(): String =
        if (relayUrl.isNotBlank()) relayUrl.trimEnd('/') else "http://$host:$relayPort"

    fun mode(): String = "relay"

    /**
     * Open the desktop session. The desktop matches `projectKey` against the
     * project's id to flip the launch out of "launching", so it must be the
     * pairing's project_id (not a derived hash). Returns the session payload
     * ({sessionId, token, wsUrl, ...}) or null when the hub is unreachable.
     */
    fun openSession(): JSONObject? {
        val body = JSONObject()
            .put("projectKey", projectKey)
            .put("projectPath", projectPath)
            .put("appName", appName)
            .put("appUrl", if (appUrl.isNotBlank()) appUrl else "native://$appName")
            .put("relayUrl", relayBase())
            .put("capabilities", capabilities)
            .put("runtime", "native")

        Log.i("Tesseract", "opening session at ${relayBase()}/api/transport/sessions projectKey=$projectKey")

        val response = postJson("${relayBase()}/api/transport/sessions", body)

        if (response == null) {
            Log.w("Tesseract", "session open POST to ${relayBase()} returned null")
        }

        return response
    }

    /**
     * Ship a batch of envelopes in one relay POST. All envelopes in a batch
     * share the ingest `source` (they always do in practice — the agent stamps
     * them); the hub also honors each envelope's own `source` field.
     */
    fun sendBatch(sessionId: String?, token: String?, envelopes: JSONArray): Boolean {
        if (sessionId.isNullOrEmpty() || envelopes.length() == 0) {
            return false
        }

        val source = envelopes.optJSONObject(0)?.optString("source", "native") ?: "native"

        val body = JSONObject()
            .put("sessionId", sessionId)
            .put("token", token ?: "")
            .put("source", source)
            .put("envelopes", envelopes)

        val response = postJson("${relayBase()}/api/transport/ingest", body)
        return response != null
    }

    /** Returns the `commands` array (possibly empty), or null on transport error. */
    fun pollCommands(sessionId: String?, token: String?, captureId: String): JSONArray? {
        if (sessionId.isNullOrEmpty()) {
            return null
        }

        val body = JSONObject()
            .put("sessionId", sessionId)
            .put("token", token ?: "")
            .put("captureId", captureId)
            .put("maxCommands", 10)

        val response = postJson("${relayBase()}/api/transport/commands/poll", body) ?: return null

        return response.optJSONArray("commands") ?: JSONArray()
    }

    fun respondCommand(
        sessionId: String?,
        token: String?,
        captureId: String,
        commandId: String,
        kind: String?,
        status: String,
        detail: JSONObject?,
    ): Boolean {
        if (sessionId.isNullOrEmpty()) {
            return false
        }

        val body = JSONObject()
            .put("sessionId", sessionId)
            .put("token", token ?: "")
            .put("captureId", captureId)
            .put("commandId", commandId)
            .put("kind", kind ?: JSONObject.NULL)
            .put("status", status)
            .put("detail", detail ?: JSONObject.NULL)

        return postJson("${relayBase()}/api/transport/commands/respond", body) != null
    }

    private fun postJson(url: String, body: JSONObject): JSONObject? {
        var connection: HttpURLConnection? = null
        return try {
            connection = (URL(url).openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                connectTimeout = 3000
                readTimeout = 5000
                doOutput = true
                setRequestProperty("Content-Type", "application/json")
                setRequestProperty("Accept", "application/json")
            }

            OutputStreamWriter(connection.outputStream).use { it.write(body.toString()) }

            val code = connection.responseCode
            if (code !in 200..299) {
                Log.w("Tesseract", "POST $url -> HTTP $code")
                if (code == 401) {
                    throw InvalidSessionException(url)
                }

                return null
            }

            val text = connection.inputStream.bufferedReader().use { it.readText() }
            if (text.isBlank()) JSONObject() else JSONObject(text)
        } catch (t: Throwable) {
            if (t is InvalidSessionException) {
                throw t
            }

            Log.w("Tesseract", "POST $url failed: ${t.javaClass.simpleName}: ${t.message}")
            null
        } finally {
            connection?.disconnect()
        }
    }
}
