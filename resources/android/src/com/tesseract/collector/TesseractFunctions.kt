package com.tesseract.collector

import android.content.Context
import android.content.pm.ApplicationInfo
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import org.json.JSONArray
import org.json.JSONObject

/**
 * The PHP-callable surface of the agent. Each class is registered in
 * nativephp.json under `bridge_functions` and reached via
 * `nativephp_call('Tesseract.<Method>', json)`.
 *
 * All three take a Context (declared `android_params: ["context"]`) so they can
 * run from the background/cold-boot path without a foreground Activity.
 */
object TesseractFunctions {

    /** PHP ignition: open the desktop session and start the transport. Idempotent. */
    class Connect(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (!context.isTesseractDebuggable()) return unavailable()
            TesseractAgent.connect(context.applicationContext, JSONObject(parameters))

            return BridgeResponse.success(mapOf("connected" to true))
        }
    }

    class Ingest(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (!context.isTesseractDebuggable()) return unavailable()
            val envelopes = parameters["envelopes"]
            val array = when (envelopes) {
                is JSONArray -> envelopes
                is List<*> -> JSONArray(envelopes)
                is String -> JSONArray(envelopes)
                else -> JSONArray()
            }

            val accepted = TesseractAgent.ingest(array)

            return BridgeResponse.success(mapOf("accepted" to accepted))
        }
    }

    /** Health probe for `tesseract:doctor` and the desktop. */
    class Status(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (!context.isTesseractDebuggable()) return unavailable()
            return BridgeResponse.success(TesseractAgent.status().toMap())
        }
    }

    /**
     * Hand PHP the buffered host -> target commands to execute. Returned as a
     * JSON string so arbitrarily-nested command payloads survive the bridge.
     */
    class TakeCommands(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (!context.isTesseractDebuggable()) return unavailable()
            return BridgeResponse.success(mapOf("commands" to TesseractAgent.takeCommands().toString()))
        }
    }

    class Respond(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            if (!context.isTesseractDebuggable()) return unavailable()
            val commandId = parameters["commandId"] as? String ?: ""
            val kind = parameters["kind"] as? String
            val status = parameters["status"] as? String ?: "error"
            val detail = when (val raw = parameters["detail"]) {
                is JSONObject -> raw
                is Map<*, *> -> JSONObject(raw)
                is String -> if (raw.isNotBlank()) JSONObject(raw) else null
                else -> null
            }

            val accepted = TesseractAgent.respond(commandId, kind, status, detail)

            return BridgeResponse.success(mapOf("accepted" to accepted))
        }
    }
}

private fun Context.isTesseractDebuggable(): Boolean =
    (applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0

private fun unavailable(): Map<String, Any> =
    BridgeResponse.success(mapOf("available" to false))

private fun JSONObject.toMap(): Map<String, Any> {
    val map = HashMap<String, Any>()
    for (key in keys()) {
        map[key] = get(key)
    }
    return map
}
