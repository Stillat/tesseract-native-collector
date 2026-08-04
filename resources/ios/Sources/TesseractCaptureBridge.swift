import Foundation

/// Tesseract-owned serialization of NativePHP's generic decoded-tree seam.
enum TesseractCaptureBridge {
    private final class PendingTree: @unchecked Sendable {
        let value: NativeUITree

        init(_ value: NativeUITree) { self.value = value }
    }

    private static let lock = NSLock()
    private static let queue = DispatchQueue(label: "tesseract-capture", qos: .utility)
    private static var treeSubscription: NativeTreeObserverRegistry.Subscription?
    private static var pendingTree: PendingTree?
    private static var treeDrainScheduled = false

    static func attach(emit: @escaping (_ kind: String, _ stream: String, _ payload: [String: Any]) -> Void) {
        lock.lock()
        guard treeSubscription == nil else { lock.unlock(); return }
        lock.unlock()

        let trees = NativeTreeObserverRegistry.shared.observe {
            enqueueLatestTree($0, emit: emit)
        }

        lock.lock()
        treeSubscription = trees
        lock.unlock()
    }

    static func detach() {
        lock.lock()
        let trees = treeSubscription
        treeSubscription = nil
        pendingTree = nil
        lock.unlock()

        if let trees { NativeTreeObserverRegistry.shared.unsubscribe(trees) }
        DispatchQueue.main.async { TesseractInspectorState.shared.updateKnownNodes([]) }
    }

    private static func enqueueLatestTree(
        _ tree: NativeUITree,
        emit: @escaping (_ kind: String, _ stream: String, _ payload: [String: Any]) -> Void
    ) {
        lock.lock()
        pendingTree = PendingTree(tree)
        let shouldSchedule = !treeDrainScheduled
        treeDrainScheduled = true
        lock.unlock()
        guard shouldSchedule else { return }

        queue.async {
            while true {
                lock.lock()
                guard let next = pendingTree else {
                    treeDrainScheduled = false
                    lock.unlock()
                    return
                }
                pendingTree = nil
                lock.unlock()

                let payload = serializeTree(next.value)
                DispatchQueue.main.async { TesseractInspectorState.shared.updateKnownNodes(from: payload) }
                emit("native.view.tree", "native", payload)
            }
        }
    }

    private static func serializeTree(_ tree: NativeUITree) -> [String: Any] {
        ["version": tree.version, "callback_count": tree.callbackCount, "root": serializeNode(tree.root)]
    }

    private static func serializeNode(_ node: NativeUINode) -> [String: Any] {
        var value: [String: Any] = [
            "id": node.id, "type": node.type, "on_press": node.onPress,
            "on_long_press": node.onLongPress, "props": serializeProps(node.props),
            "children": node.children.map(serializeNode),
        ]
        if let layout = node.layout { value["layout"] = serializeLayout(layout) }
        if let style = node.style { value["style"] = serializeStyle(style) }
        return value
    }

    private static func serializeLayout(_ layout: NodeLayout) -> [String: Any] {
        [
            "width": Double(layout.width), "width_mode": layout.widthMode,
            "height": Double(layout.height), "height_mode": layout.heightMode,
            "padding_top": Double(layout.paddingTop), "padding_right": Double(layout.paddingRight),
            "padding_bottom": Double(layout.paddingBottom), "padding_left": Double(layout.paddingLeft),
            "margin_top": Double(layout.marginTop), "margin_right": Double(layout.marginRight),
            "margin_bottom": Double(layout.marginBottom), "margin_left": Double(layout.marginLeft),
            "flex_grow": Double(layout.flexGrow), "flex_shrink": Double(layout.flexShrink),
            "flex_basis": Double(layout.flexBasis), "flex_direction": layout.flexDirection,
            "flex_wrap": layout.flexWrap, "align_self": layout.alignSelf,
            "align_items": layout.alignItems, "align_content": layout.alignContent,
            "justify_content": layout.justifyContent, "gap": Double(layout.gap),
            "row_gap": Double(layout.rowGap), "position_type": layout.positionType,
            "display": layout.display, "overflow": layout.overflow, "safe_area": layout.safeArea,
        ]
    }

    private static func serializeStyle(_ style: NodeStyle) -> [String: Any] {
        [
            "bg_color": style.bgColor, "border_radius": Double(style.borderRadius),
            "border_width": Double(style.borderWidth), "border_color": style.borderColor,
            "opacity": Double(style.opacity), "elevation": Double(style.elevation),
        ]
    }

    private static func serializeProps(_ props: GenericProps) -> [String: Any] {
        props.entries.filter { JSONSerialization.isValidJSONObject([$0.key: $0.value]) }
    }
}
