package com.tesseract.collector

import android.content.Context
import android.content.pm.ApplicationInfo
import android.util.Log
import com.tesseract.collector.capture.NativeCommandBridge
import com.tesseract.collector.capture.TesseractInspector

/**
 * Build-registered init hook (`android.init_function` in nativephp.json).
 *
 * Invoked once from MainActivity.onCreate via the generated plugin bridge
 * registration, on the main thread, with the application Context. We do the
 * minimum here: prime the agent singleton so it is ready to accept a
 * `Tesseract.Connect` from PHP. The actual socket work happens off the main
 * thread inside the agent's worker.
 *
 * PHP ignition (the collector's service provider calling `Tesseract.Connect`)
 * is the authoritative start signal because it carries the desktop endpoint and
 * capabilities. This hook only guarantees the agent exists before that call and
 * lets us attach the capture observers as early as possible.
 */
fun initialize(context: Context) {
    if ((context.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) == 0) return

    try {
        NativeCommandBridge.applyPersistedMirrorOrientation(context)
        TesseractInspector.register()
        TesseractAgent.prime(context.applicationContext)
    } catch (t: Throwable) {
        Log.w("Tesseract", "init failed: ${t.message}")
    }
}
