import Foundation
import SwiftUI

final class TesseractInspectorState: ObservableObject {
    static let shared = TesseractInspectorState()

    @Published private(set) var highlightedNodeId = 0
    @Published private(set) var scrollTargetNodeId = 0
    @Published private(set) var scrollRequestId = 0

    private var knownNodeIds: Set<Int> = []
    private var instrumentationKeys: [Int: String] = [:]
    private var highlightClearItem: DispatchWorkItem?

    private init() {}

    func hasNode(_ nodeId: Int) -> Bool { nodeId != 0 && knownNodeIds.contains(nodeId) }

    func instrumentationKey(for nodeId: Int) -> String? { instrumentationKeys[nodeId] }

    func highlight(_ nodeId: Int) {
        highlightClearItem?.cancel()
        highlightedNodeId = nodeId
        guard nodeId != 0 else { return }

        let item = DispatchWorkItem { [weak self] in
            guard self?.highlightedNodeId == nodeId else { return }
            self?.highlightedNodeId = 0
        }
        highlightClearItem = item
        DispatchQueue.main.asyncAfter(deadline: .now() + 4, execute: item)
    }

    func clearHighlight() { highlight(0) }

    func requestScroll(to nodeId: Int) {
        scrollTargetNodeId = nodeId
        scrollRequestId &+= 1
        highlight(nodeId)
        NativeUIBridge.shared.objectWillChange.send()
    }

    func finishScroll(_ requestId: Int) {
        guard scrollRequestId == requestId else { return }
        scrollTargetNodeId = 0
        scrollRequestId = 0
        NativeUIBridge.shared.objectWillChange.send()
    }

    func updateKnownNodes(from payload: [String: Any]) {
        var ids: Set<Int> = []
        var keys: [Int: String] = [:]
        collectNodeIds(payload["root"] as? [String: Any], into: &ids, keys: &keys)
        instrumentationKeys = keys
        updateKnownNodes(ids)
    }

    func updateKnownNodes(_ ids: Set<Int>) {
        knownNodeIds = ids
        if highlightedNodeId != 0 && !ids.contains(highlightedNodeId) {
            clearHighlight()
            scrollTargetNodeId = 0
            scrollRequestId = 0
            NativeUIBridge.shared.objectWillChange.send()
        }
    }

    private func collectNodeIds(
        _ node: [String: Any]?,
        into ids: inout Set<Int>,
        keys: inout [Int: String]
    ) {
        guard let node else { return }
        let id = (node["id"] as? Int) ?? (node["id"] as? NSNumber)?.intValue
        if let id {
            ids.insert(id)
            if let key = (node["props"] as? [String: Any])?["_dbg_key"] as? String, !key.isEmpty {
                keys[id] = key
            }
        }
        for child in (node["children"] as? [[String: Any]]) ?? [] {
            collectNodeIds(child, into: &ids, keys: &keys)
        }
    }
}

enum TesseractInspector {
    static func register() {
        NativeNodeDecoratorRegistry.shared.register("tesseract.inspector") { node, content in
            AnyView(content.modifier(TesseractNodeInspectorModifier(nodeId: node.id)))
        }
        NativeRootHostRegistry.shared.register("tesseract.inspector") { _, content in
            let state = TesseractInspectorState.shared
            guard state.scrollRequestId != 0 else { return content }
            return AnyView(TesseractScrollHost(content: content))
        }
    }
}

private struct TesseractNodeInspectorModifier: ViewModifier {
    let nodeId: Int
    @ObservedObject private var state = TesseractInspectorState.shared

    func body(content: Content) -> some View {
        content
            .modifier(TesseractConditionalNodeId(nodeId: nodeId, enabled: state.scrollRequestId != 0))
            .overlay {
                if state.highlightedNodeId == nodeId && nodeId != 0 {
                    RoundedRectangle(cornerRadius: 3)
                        .stroke(Color(red: 0.298, green: 0.604, blue: 1), lineWidth: 2)
                }
            }
    }
}

private struct TesseractConditionalNodeId: ViewModifier {
    let nodeId: Int
    let enabled: Bool

    @ViewBuilder
    func body(content: Content) -> some View {
        if enabled { content.id(nodeId) } else { content }
    }
}

private struct TesseractScrollHost: View {
    let content: AnyView
    @ObservedObject private var state = TesseractInspectorState.shared

    var body: some View {
        ScrollViewReader { proxy in
            content.onReceive(state.$scrollRequestId) { requestId in
                let target = state.scrollTargetNodeId
                guard requestId != 0, target != 0 else { return }
                DispatchQueue.main.async {
                    proxy.scrollTo(target, anchor: .center)
                    DispatchQueue.main.async { state.finishScroll(requestId) }
                }
            }
        }
    }
}
