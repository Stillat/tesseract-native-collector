package com.tesseract.collector.capture

import android.os.Handler
import android.os.Looper
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.nativephp.mobile.ui.nativerender.NativeNodeDecoratorRegistry
import com.nativephp.mobile.ui.nativerender.NativeUINode
import org.json.JSONArray
import org.json.JSONObject

object TesseractInspector {
    private val highlightedNode = mutableIntStateOf(0)
    private val scrollTargetNode = mutableIntStateOf(0)
    private val scrollRequestId = mutableIntStateOf(0)
    private val handler = Handler(Looper.getMainLooper())
    private val clearHighlight = Runnable { highlightedNode.intValue = 0 }

    @Volatile private var knownNodeIds: Set<Int> = emptySet()

    fun register() {
        NativeNodeDecoratorRegistry.register("tesseract.inspector") { node, current ->
            decorate(node, current)
        }
    }

    fun hasNode(nodeId: Int): Boolean = nodeId != 0 && knownNodeIds.contains(nodeId)

    fun highlight(nodeId: Int) {
        highlightedNode.intValue = nodeId
        handler.removeCallbacks(clearHighlight)
        if (nodeId != 0) handler.postDelayed(clearHighlight, 4_000)
    }

    fun clear() {
        handler.removeCallbacks(clearHighlight)
        highlightedNode.intValue = 0
    }

    fun scrollIntoView(nodeId: Int) {
        scrollTargetNode.intValue = nodeId
        scrollRequestId.intValue += 1
        highlight(nodeId)
    }

    fun updateKnownNodes(payload: JSONObject) {
        val ids = mutableSetOf<Int>()
        collectNodeIds(payload.optJSONObject("root"), ids)
        updateKnownNodes(ids)
    }

    fun updateKnownNodes(ids: Set<Int>) {
        knownNodeIds = ids
        handler.post {
            if (highlightedNode.intValue != 0 && !ids.contains(highlightedNode.intValue)) {
                clear()
                scrollTargetNode.intValue = 0
                scrollRequestId.intValue = 0
            }
        }
    }

    private fun collectNodeIds(node: JSONObject?, ids: MutableSet<Int>) {
        if (node == null) return
        ids.add((node.optLong("id", 0L) and 0xffffffffL).toInt())
        val children: JSONArray = node.optJSONArray("children") ?: return
        for (index in 0 until children.length()) collectNodeIds(children.optJSONObject(index), ids)
    }

    @OptIn(ExperimentalFoundationApi::class)
    @Composable
    private fun decorate(node: NativeUINode, current: Modifier): Modifier {
        var modifier = current
        val radius = node.style?.borderRadius ?: 0f

        if (highlightedNode.intValue == node.id) {
            modifier = modifier
                .background(Color(0x334C9AFF), RoundedCornerShape(radius.dp))
                .border(3.dp, Color(0xFF2F80FF), RoundedCornerShape(radius.dp))
        }

        val requestId = scrollRequestId.intValue
        if (requestId != 0 && scrollTargetNode.intValue == node.id) {
            val requester = remember(node.id) { BringIntoViewRequester() }
            modifier = modifier.bringIntoViewRequester(requester)
            LaunchedEffect(requestId) { runCatching { requester.bringIntoView() } }
        }

        return modifier
    }
}
