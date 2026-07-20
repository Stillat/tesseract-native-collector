package com.tesseract.collector.capture

import com.nativephp.mobile.ui.nativerender.NativeElementObservationRegistry
import org.json.JSONObject

object NativeCaptureBridge {
    private var treeSubscription: NativeElementObservationRegistry.Subscription? = null
    private var eventSubscription: NativeElementObservationRegistry.Subscription? = null

    @Synchronized
    fun attach(emit: (kind: String, stream: String, payload: JSONObject) -> Unit) {
        if (treeSubscription != null || eventSubscription != null) return

        treeSubscription = NativeElementObservationRegistry.observeTrees { json ->
            val payload = JSONObject(json)
            TesseractInspector.updateKnownNodes(payload)
            emit("native.view.tree", "native", payload)
        }
        eventSubscription = NativeElementObservationRegistry.observeEvents { json ->
            emit("native.interaction.recorded", "native", JSONObject(json))
        }
    }

    @Synchronized
    fun detach() {
        treeSubscription?.let(NativeElementObservationRegistry::unsubscribe)
        eventSubscription?.let(NativeElementObservationRegistry::unsubscribe)
        treeSubscription = null
        eventSubscription = null
        TesseractInspector.updateKnownNodes(emptySet())
    }
}
