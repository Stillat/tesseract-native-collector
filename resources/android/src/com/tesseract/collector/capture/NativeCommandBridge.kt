package com.tesseract.collector.capture

import android.content.Context
import android.content.pm.ActivityInfo
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import com.nativephp.mobile.ui.MainActivity
import com.nativephp.mobile.ui.nativerender.NativeUIBridge
import org.json.JSONArray
import org.json.JSONObject

/** Applies desktop-originated commands through the runtime's generic APIs. */
object NativeCommandBridge {
    private const val MIRROR_ROTATION_SETTING = "tesseract_mirror_rotation"
    private val mainHandler = Handler(Looper.getMainLooper())

    /**
     * Reapply a device-wide mirror rotation before the first native frame. The
     * desktop stores the active Surface rotation in a temporary global setting
     * because Activity requested orientation is lost when Android recreates the
     * process. Without this, a portrait-manifest app cold-launched while the
     * mirrored device is landscape opens in fixed-orientation letterbox mode.
     */
    fun applyPersistedMirrorOrientation(context: Context) {
        val rotation = Settings.Global.getString(
            context.contentResolver,
            MIRROR_ROTATION_SETTING,
        )?.toIntOrNull() ?: return

        val requested = when (rotation) {
            0 -> ActivityInfo.SCREEN_ORIENTATION_PORTRAIT
            1 -> ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
            2 -> ActivityInfo.SCREEN_ORIENTATION_REVERSE_PORTRAIT
            3 -> ActivityInfo.SCREEN_ORIENTATION_REVERSE_LANDSCAPE
            else -> return
        }
        val activity = MainActivity.instance ?: return

        if (activity.requestedOrientation != requested) {
            activity.requestedOrientation = requested
        }
    }

    fun dispatch(payload: JSONObject): JSONObject {
        val sender = payload.optString("sender")
        val callbackId = payload.optInt("callbackId", 0)
        val nodeId = nodeIdBits(payload)
        if (callbackId <= 0) return result(false, "no callbackId")
        if (!TesseractInspector.hasNode(nodeId)) return result(false, "target node is not in the active tree")

        val action: (() -> Unit) = when (sender) {
            "press" -> { { NativeUIBridge.sendPressEvent(callbackId, nodeId) } }
            "longpress" -> { { NativeUIBridge.sendLongPressEvent(callbackId, nodeId) } }
            "text" -> { { NativeUIBridge.sendTextChangeEvent(callbackId, nodeId, payload.optString("value")) } }
            "submit" -> { { NativeUIBridge.sendSubmitEvent(callbackId, nodeId, payload.optString("value")) } }
            "select" -> { { NativeUIBridge.sendSelectChangeEvent(callbackId, nodeId, payload.optString("value")) } }
            "toggle" -> { { NativeUIBridge.sendToggleChangeEvent(callbackId, nodeId, payload.optBoolean("value")) } }
            "checkbox" -> { { NativeUIBridge.sendCheckboxChangeEvent(callbackId, nodeId, payload.optBoolean("value")) } }
            "slider" -> { { NativeUIBridge.sendSliderChangeEvent(callbackId, nodeId, payload.optDouble("value", 0.0).toFloat()) } }
            else -> return result(false, "unknown sender '$sender'")
        }

        return dispatchOnMain("dispatched $sender to node ${unsignedNodeId(nodeId)}", action)
    }

    fun highlight(payload: JSONObject): JSONObject {
        val nodeId = nodeIdBits(payload)
        if (!TesseractInspector.hasNode(nodeId)) return result(false, "target node is not in the active tree")
        return dispatchOnMain("highlighted node ${unsignedNodeId(nodeId)}") { TesseractInspector.highlight(nodeId) }
    }

    fun clearHighlight(): JSONObject =
        dispatchOnMain("cleared highlight") { TesseractInspector.clear() }

    fun scrollIntoView(payload: JSONObject): JSONObject {
        val nodeId = nodeIdBits(payload)
        if (!TesseractInspector.hasNode(nodeId)) return result(false, "target node is not in the active tree")
        return dispatchOnMain("scrolled node ${unsignedNodeId(nodeId)} into view") {
            TesseractInspector.scrollIntoView(nodeId)
        }
    }

    /**
     * Rotate the app at runtime by overriding the Activity's requested
     * orientation. Runtime `setRequestedOrientation` supersedes the manifest, so
     * no manifest changes are needed. Returns failure when no foreground
     * MainActivity is available (backgrounded / cold-boot path).
     */
    fun rotate(payload: JSONObject): JSONObject {
        val orientation = payload.optString("orientation")
        val requested = when (orientation) {
            "portrait" -> ActivityInfo.SCREEN_ORIENTATION_PORTRAIT
            // Android names these from the display's natural rotation, while
            // the workbench names them from the user's physical turn. On a
            // portrait-native device, a left turn is ROTATION_270 (reverse
            // landscape) and a right turn is ROTATION_90 (landscape).
            "landscape-left" -> ActivityInfo.SCREEN_ORIENTATION_REVERSE_LANDSCAPE
            "landscape-right" -> ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
            "portrait-upside-down" -> ActivityInfo.SCREEN_ORIENTATION_REVERSE_PORTRAIT
            else -> return result(false, "unknown orientation '$orientation'")
        }

        val activity = MainActivity.instance
            ?: return result(false, "no foreground activity")

        return dispatchOnMain("rotated to $orientation") {
            activity.requestedOrientation = requested
        }
    }

    fun navigate(payload: JSONObject): JSONObject {
        val uri = payload.optString("uri")
        if (uri.isEmpty()) return result(false, "no uri")
        return sendNativeEvent("__tesseract:navigate", JSONObject().put("uri", uri), "navigate $uri")
    }

    fun setStyle(payload: JSONObject): JSONObject {
        val inner = JSONObject()
        if (payload.optBoolean("reset", false)) {
            inner.put("reset", true)
        } else {
            val nodeId = nodeIdBits(payload)
            if (!TesseractInspector.hasNode(nodeId)) return result(false, "target node is not in the active tree")
            inner.put("nodeId", unsignedNodeId(nodeId))
            if (payload.has("classes") && !payload.isNull("classes")) inner.put("classes", payload.optString("classes"))
        }
        return sendNativeEvent("__tesseract:set-style", inner, "set style")
    }

    fun setScope(payload: JSONObject): JSONObject {
        val property = payload.optString("property")
        if (property.isEmpty()) return result(false, "no property")
        val inner = JSONObject().put("property", property)
        if (payload.has("value")) inner.put("value", payload.get("value"))
        return sendNativeEvent("__tesseract:set-scope", inner, "set scope $property")
    }

    fun call(payload: JSONObject): JSONObject {
        val method = payload.optString("method")
        if (method.isEmpty()) return result(false, "no method")
        val inner = JSONObject()
            .put("method", method)
            .put("args", payload.optJSONArray("args") ?: JSONArray())
        return sendNativeEvent("__tesseract:call", inner, "call $method")
    }

    private fun sendNativeEvent(name: String, payload: JSONObject, message: String): JSONObject =
        dispatchOnMain(message) { NativeUIBridge.sendNativeEvent(name, payload.toString()) }

    private fun dispatchOnMain(message: String, action: () -> Unit): JSONObject {
        return try {
            val accepted = if (Looper.myLooper() == Looper.getMainLooper()) {
                action()
                true
            } else {
                mainHandler.post { action() }
            }
            result(accepted, if (accepted) message else "main thread rejected command")
        } catch (t: Throwable) {
            result(false, t.message ?: "dispatch failed")
        }
    }

    private fun nodeIdBits(payload: JSONObject): Int =
        (payload.optLong("targetNodeId", 0L) and 0xffffffffL).toInt()

    private fun unsignedNodeId(nodeId: Int): Long = nodeId.toLong() and 0xffffffffL

    private fun result(ok: Boolean, message: String): JSONObject =
        JSONObject().put("ok", ok).put("message", message)
}
