package com.tesseract.collector.capture

import android.app.Activity
import android.app.Application
import android.content.ComponentCallbacks2
import android.content.Context
import android.content.res.Configuration
import android.os.Bundle
import android.view.OrientationEventListener
import com.nativephp.mobile.lifecycle.NativePHPLifecycle
import org.json.JSONObject

/**
 * Streams device/app lifecycle signals — rotation, foreground/background,
 * theme/locale/font-scale changes, and memory pressure — as
 * `native.device.event` envelopes.
 *
 * Two rotation signals on purpose:
 *  - `rotated` — the app's *configuration* actually changed orientation. Only
 *    fires when the activity allows rotation; NativePHP apps are portrait-locked
 *    by default (config `orientation.android`), so most apps never see this.
 *  - `orientation` — the *physical* device orientation changed (sensor-based,
 *    quantized to the four 90° buckets). Fires even when the UI is locked, so
 *    "I rotated the device and nothing happened" is still visible on the
 *    timeline. Payload detail carries the bucket plus degrees.
 */
object NativeDeviceBridge {

    @Volatile private var attached = false

    private var orientationListener: OrientationEventListener? = null
    @Volatile private var lastOrientationBucket: String? = null

    fun attach(
        context: Context,
        emit: (kind: String, stream: String, payload: JSONObject) -> Unit,
    ) {
        if (attached) {
            return
        }

        val app = context.applicationContext as? Application ?: return
        attached = true

        fun event(type: String, detail: String?, extra: Map<String, Any?> = emptyMap()) {
            val payload = JSONObject().put("type", type)
            if (detail != null) {
                payload.put("detail", detail)
            }
            for ((key, value) in extra) {
                if (value != null) {
                    payload.put(key, value)
                }
            }
            emit("native.device.event", "native", payload)
        }

        NativePHPLifecycle.on(NativePHPLifecycle.Events.ON_RESUME) {
            event("activity-resumed", null)
        }
        NativePHPLifecycle.on(NativePHPLifecycle.Events.ON_PAUSE) {
            event("activity-paused", null)
        }
        NativePHPLifecycle.on(NativePHPLifecycle.Events.ON_DESTROY) {
            event("activity-destroyed", null)
        }
        NativePHPLifecycle.on(NativePHPLifecycle.Events.ON_NEW_INTENT) { data ->
            event("deep-link-received", sanitizedDeepLink(data["url"]?.toString()))
        }
        NativePHPLifecycle.on(NativePHPLifecycle.Events.ON_PERMISSION_RESULT) { data ->
            event(
                "permission-result",
                data["permission"]?.toString(),
                mapOf(
                    "granted" to data["granted"],
                    "requestCode" to data["requestCode"],
                ),
            )
        }
        NativePHPLifecycle.on(NativePHPLifecycle.Events.DID_REGISTER_FOR_REMOTE_NOTIFICATIONS) {
            event("push-registration-succeeded", null)
        }
        NativePHPLifecycle.on(NativePHPLifecycle.Events.DID_FAIL_TO_REGISTER_FOR_REMOTE_NOTIFICATIONS) { data ->
            event("push-registration-failed", data["error"]?.toString())
        }
        NativePHPLifecycle.on(NativePHPLifecycle.Events.DID_RECEIVE_REMOTE_NOTIFICATION) { data ->
            event("push-received", data.keys.sorted().joinToString(", "))
        }

        app.registerComponentCallbacks(object : ComponentCallbacks2 {
            // Seed from the current configuration so the first callback diffs
            // against reality instead of reporting everything as changed.
            private var previous = Configuration(app.resources.configuration)

            override fun onConfigurationChanged(newConfig: Configuration) {
                val old = previous
                previous = Configuration(newConfig)

                if (newConfig.orientation != old.orientation) {
                    val orientation = if (newConfig.orientation == Configuration.ORIENTATION_LANDSCAPE) {
                        "landscape"
                    } else {
                        "portrait"
                    }
                    event("rotated", orientation)
                }

                val oldNight = old.uiMode and Configuration.UI_MODE_NIGHT_MASK
                val newNight = newConfig.uiMode and Configuration.UI_MODE_NIGHT_MASK
                if (newNight != oldNight) {
                    val theme = if (newNight == Configuration.UI_MODE_NIGHT_YES) "dark" else "light"
                    event("theme-changed", theme)
                }

                if (newConfig.fontScale != old.fontScale) {
                    event("font-scale-changed", "%.2f".format(newConfig.fontScale))
                }

                val oldLocale = old.locales.toLanguageTags()
                val newLocale = newConfig.locales.toLanguageTags()
                if (newLocale != oldLocale) {
                    event("locale-changed", newLocale)
                }
            }

            override fun onLowMemory() {
                event("memory-warning", "low")
            }

            override fun onTrimMemory(level: Int) {
                // Only the meaningful pressure levels — ignore the routine
                // "UI hidden"/background trims that fire on every backgrounding.
                if (level >= ComponentCallbacks2.TRIM_MEMORY_RUNNING_LOW &&
                    level != ComponentCallbacks2.TRIM_MEMORY_UI_HIDDEN
                ) {
                    event("memory-warning", "trim:$level")
                }
            }
        })

        // Physical orientation via the sensor pipeline: works on
        // orientation-locked apps where onConfigurationChanged never fires.
        val listener = object : OrientationEventListener(app) {
            override fun onOrientationChanged(degrees: Int) {
                if (degrees == ORIENTATION_UNKNOWN) {
                    return
                }

                val bucket = orientationBucket(degrees) ?: return
                if (bucket == lastOrientationBucket) {
                    return
                }

                val isFirst = lastOrientationBucket == null
                lastOrientationBucket = bucket

                // Don't announce the resting orientation at attach time; only
                // report actual changes.
                if (!isFirst) {
                    event("orientation", bucket, mapOf("degrees" to quantizedDegrees(degrees)))
                }
            }
        }

        if (listener.canDetectOrientation()) {
            listener.enable()
            orientationListener = listener
        }

        app.registerActivityLifecycleCallbacks(object : Application.ActivityLifecycleCallbacks {
            private var startedActivities = 0

            override fun onActivityStarted(activity: Activity) {
                if (startedActivities == 0) {
                    event("foregrounded", null)
                    orientationListener?.enable()
                }
                startedActivities++
            }

            override fun onActivityStopped(activity: Activity) {
                startedActivities = (startedActivities - 1).coerceAtLeast(0)
                if (startedActivities == 0) {
                    event("backgrounded", null)
                    orientationListener?.disable()
                }
            }

            override fun onActivityCreated(activity: Activity, savedInstanceState: Bundle?) {}
            override fun onActivityResumed(activity: Activity) {}
            override fun onActivityPaused(activity: Activity) {}
            override fun onActivitySaveInstanceState(activity: Activity, outState: Bundle) {}
            override fun onActivityDestroyed(activity: Activity) {}
        })
    }

    /**
     * Quantize a sensor angle to one of the four orientation buckets, with a
     * ±30° hysteresis band around the diagonals so the bucket doesn't flap
     * while the device is held at an angle. Angles are degrees clockwise from
     * the device's natural (portrait) orientation.
     */
    private fun orientationBucket(degrees: Int): String? = when (degrees) {
        in 330..359, in 0..30 -> "portrait"
        in 60..120 -> "landscape-right"
        in 150..210 -> "upside-down"
        in 240..300 -> "landscape-left"
        else -> null
    }

    private fun quantizedDegrees(degrees: Int): Int = when (degrees) {
        in 330..359, in 0..30 -> 0
        in 60..120 -> 90
        in 150..210 -> 180
        else -> 270
    }

    private fun sanitizedDeepLink(url: String?): String? = url
        ?.substringBefore('?')
        ?.substringBefore('#')
}
