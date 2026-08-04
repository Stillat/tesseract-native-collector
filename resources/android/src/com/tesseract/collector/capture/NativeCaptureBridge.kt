package com.tesseract.collector.capture

import com.nativephp.mobile.ui.nativerender.GenericProps
import com.nativephp.mobile.ui.nativerender.NativeTreeObserverRegistry
import com.nativephp.mobile.ui.nativerender.NativeUINode
import com.nativephp.mobile.ui.nativerender.NativeUITree
import com.nativephp.mobile.ui.nativerender.NodeLayout
import com.nativephp.mobile.ui.nativerender.NodeStyle
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.Executors

/** Tesseract-owned serialization of NativePHP's generic decoded-tree seam. */
object NativeCaptureBridge {
    private var treeSubscription: NativeTreeObserverRegistry.Subscription? = null
    private val queue = Executors.newSingleThreadExecutor { runnable ->
        Thread(runnable, "tesseract-capture").apply { isDaemon = true }
    }
    private val pendingLock = Any()
    private var pendingTree: NativeUITree? = null
    private var treeDrainScheduled = false
    private var emitter: ((kind: String, stream: String, payload: JSONObject) -> Unit)? = null

    @Synchronized
    fun attach(emit: (kind: String, stream: String, payload: JSONObject) -> Unit) {
        if (treeSubscription != null) return

        emitter = emit
        treeSubscription = NativeTreeObserverRegistry.observe(::enqueueLatestTree)
    }

    @Synchronized
    fun detach() {
        treeSubscription?.let(NativeTreeObserverRegistry::unsubscribe)
        treeSubscription = null
        synchronized(pendingLock) {
            emitter = null
            pendingTree = null
        }
        TesseractInspector.updateKnownNodes(emptySet())
    }

    private fun enqueueLatestTree(tree: NativeUITree) {
        val shouldSchedule = synchronized(pendingLock) {
            pendingTree = tree
            if (treeDrainScheduled) {
                false
            } else {
                treeDrainScheduled = true
                true
            }
        }
        if (shouldSchedule) queue.execute(::drainLatestTrees)
    }

    private fun drainLatestTrees() {
        while (true) {
            val tree = synchronized(pendingLock) {
                val next = pendingTree
                pendingTree = null
                if (next == null) treeDrainScheduled = false
                next
            } ?: return

            val payload = serializeTree(tree)
            val currentEmitter = synchronized(pendingLock) { emitter }
            if (currentEmitter != null) {
                TesseractInspector.updateKnownNodes(payload)
                currentEmitter("native.view.tree", "native", payload)
            }
        }
    }

    private fun serializeTree(tree: NativeUITree): JSONObject = JSONObject()
        .put("version", tree.version)
        .put("callback_count", tree.callbackCount)
        .put("root", serializeNode(tree.root))

    private fun serializeNode(node: NativeUINode): JSONObject {
        val value = JSONObject()
            .put("id", node.id.toLong() and 0xffffffffL)
            .put("type", node.type)
            .put("on_press", node.onPress)
            .put("on_long_press", node.onLongPress)
            .put("props", serializeProps(node.props))

        node.layout?.let { value.put("layout", serializeLayout(it)) }
        node.style?.let { value.put("style", serializeStyle(it)) }
        value.put("children", JSONArray().also { children ->
            node.children.forEach { children.put(serializeNode(it)) }
        })
        return value
    }

    private fun serializeLayout(layout: NodeLayout): JSONObject = JSONObject()
        .put("width", layout.width.toDouble()).put("width_mode", layout.widthMode)
        .put("height", layout.height.toDouble()).put("height_mode", layout.heightMode)
        .put("padding_top", layout.paddingTop.toDouble()).put("padding_right", layout.paddingRight.toDouble())
        .put("padding_bottom", layout.paddingBottom.toDouble()).put("padding_left", layout.paddingLeft.toDouble())
        .put("margin_top", layout.marginTop.toDouble()).put("margin_right", layout.marginRight.toDouble())
        .put("margin_bottom", layout.marginBottom.toDouble()).put("margin_left", layout.marginLeft.toDouble())
        .put("flex_grow", layout.flexGrow.toDouble()).put("flex_shrink", layout.flexShrink.toDouble())
        .put("flex_basis", layout.flexBasis.toDouble()).put("flex_direction", layout.flexDirection)
        .put("flex_wrap", layout.flexWrap).put("align_self", layout.alignSelf)
        .put("align_items", layout.alignItems).put("align_content", layout.alignContent)
        .put("justify_content", layout.justifyContent).put("gap", layout.gap.toDouble())
        .put("row_gap", layout.rowGap.toDouble()).put("position_type", layout.positionType)
        .put("display", layout.display).put("overflow", layout.overflow).put("safe_area", layout.safeArea)

    private fun serializeStyle(style: NodeStyle): JSONObject = JSONObject()
        .put("bg_color", style.bgColor).put("border_radius", style.borderRadius.toDouble())
        .put("border_width", style.borderWidth.toDouble()).put("border_color", style.borderColor)
        .put("opacity", style.opacity.toDouble()).put("elevation", style.elevation.toDouble())

    private fun serializeProps(props: GenericProps): JSONObject = JSONObject().also { value ->
        props.entries.forEach { (key, item) ->
            runCatching { value.put(key, if (item is List<*>) JSONArray(item) else item) }
        }
    }
}
