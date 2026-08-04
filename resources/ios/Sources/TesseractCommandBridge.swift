import Foundation
import UIKit

/// Applies desktop-originated commands through the runtime's generic APIs.
enum TesseractCommandBridge {
    static func execute(kind: String, payload: [String: Any]) -> [String: Any] {
        switch kind {
        case "native.highlight":
            let nodeId = intValue(payload["targetNodeId"])
            guard TesseractInspectorState.shared.hasNode(nodeId) else { return result(false, "target node is not in the active tree") }
            return dispatch("highlighted node") { TesseractInspectorState.shared.highlight(nodeId) }

        case "native.clear-highlight":
            return dispatch("cleared highlight") { TesseractInspectorState.shared.clearHighlight() }

        case "native.scroll-into-view":
            let nodeId = intValue(payload["targetNodeId"])
            guard TesseractInspectorState.shared.hasNode(nodeId) else { return result(false, "target node is not in the active tree") }
            return dispatch("scrolled node into view") { TesseractInspectorState.shared.requestScroll(to: nodeId) }

        case "native.navigate":
            let uri = (payload["uri"] as? String) ?? ""
            guard !uri.isEmpty else { return result(false, "no uri") }
            return sendNativeEvent(name: "tesseract:navigate", payload: ["uri": uri], message: "navigate \(uri)")

        case "native.set-scope":
            let property = (payload["property"] as? String) ?? ""
            guard !property.isEmpty else { return result(false, "no property") }
            var inner: [String: Any] = ["property": property]
            if let value = payload["value"], !(value is NSNull) { inner["value"] = value }
            return sendNativeEvent(name: "tesseract:set-scope", payload: inner, message: "set scope \(property)")

        case "native.call":
            let method = (payload["method"] as? String) ?? ""
            guard !method.isEmpty else { return result(false, "no method") }
            return sendNativeEvent(
                name: "tesseract:call",
                payload: ["method": method, "args": (payload["args"] as? [Any]) ?? []],
                message: "call \(method)"
            )

        case "native.set-style":
            var inner: [String: Any] = [:]
            if (payload["reset"] as? Bool) == true {
                inner["reset"] = true
            } else {
                let nodeId = intValue(payload["targetNodeId"])
                guard TesseractInspectorState.shared.hasNode(nodeId) else { return result(false, "target node is not in the active tree") }
                guard let key = TesseractInspectorState.shared.instrumentationKey(for: nodeId) else { return result(false, "target node is not instrumented") }
                inner["nodeId"] = nodeId
                inner["key"] = key
                if let classes = payload["classes"] as? String { inner["classes"] = classes }
            }
            return sendNativeEvent(name: "tesseract:set-style", payload: inner, message: "set style")

        case "native.dispatch-event":
            return dispatchEvent(payload)

        case "native.rotate":
            return rotate(payload)

        default:
            return result(false, "unknown command \(kind)")
        }
    }

    /// Rotate the app at runtime via public window-scene geometry APIs (no
    /// Simulator.app menu / focus steal). Widens the app's supported
    /// orientations through the plugin's Info.plist merge so the request is
    /// honored. Fire-and-forget on the main thread, matching the other cases.
    private static func rotate(_ payload: [String: Any]) -> [String: Any] {
        let orientation = (payload["orientation"] as? String) ?? ""
        let mask: UIInterfaceOrientationMask
        switch orientation {
        case "portrait": mask = .portrait
        case "portrait-upside-down": mask = .portraitUpsideDown
        case "landscape-left": mask = .landscapeLeft
        case "landscape-right": mask = .landscapeRight
        default: return result(false, "unknown orientation \(orientation)")
        }

        return dispatch("rotated to \(orientation)") {
            guard let scene = UIApplication.shared.connectedScenes
                .compactMap({ $0 as? UIWindowScene })
                .first(where: { $0.activationState == .foregroundActive })
                ?? UIApplication.shared.connectedScenes
                .compactMap({ $0 as? UIWindowScene })
                .first
            else { return }

            let root = scene.windows.first(where: { $0.isKeyWindow })?.rootViewController
            root?.setNeedsUpdateOfSupportedInterfaceOrientations()

            // `requestGeometryUpdate` / `setNeedsUpdateOfSupportedInterfaceOrientations`
            // are iOS 16+; the plugin targets iOS 18.2 (see nativephp.json), so no
            // availability guard is required.
            scene.requestGeometryUpdate(.iOS(interfaceOrientations: mask)) { _ in }
        }
    }

    private static func dispatchEvent(_ payload: [String: Any]) -> [String: Any] {
        let sender = (payload["sender"] as? String) ?? ""
        let callbackId = intValue(payload["callbackId"])
        let nodeId = intValue(payload["targetNodeId"])
        guard callbackId > 0 else { return result(false, "no callbackId") }
        guard TesseractInspectorState.shared.hasNode(nodeId) else { return result(false, "target node is not in the active tree") }
        guard ["press", "longpress", "text", "submit", "select", "toggle", "checkbox", "slider"].contains(sender) else {
            return result(false, "unknown sender \(sender)")
        }

        return dispatch("dispatched \(sender)") {
            switch sender {
            case "press": NativeElementBridge.sendPressEvent(callbackId, nodeId: nodeId)
            case "longpress": NativeElementBridge.sendLongPressEvent(callbackId, nodeId: nodeId)
            case "text": NativeElementBridge.sendTextChangeEvent(callbackId, nodeId: nodeId, text: stringValue(payload["value"]))
            case "submit": NativeElementBridge.sendSubmitEvent(callbackId, nodeId: nodeId, text: stringValue(payload["value"]))
            case "select": NativeElementBridge.sendSelectChangeEvent(callbackId, nodeId: nodeId, value: stringValue(payload["value"]))
            case "toggle": NativeElementBridge.sendToggleChangeEvent(callbackId, nodeId: nodeId, value: boolValue(payload["value"]))
            case "checkbox": NativeElementBridge.sendCheckboxChangeEvent(callbackId, nodeId: nodeId, value: boolValue(payload["value"]))
            case "slider": NativeElementBridge.sendSliderChangeEvent(callbackId, nodeId: nodeId, value: floatValue(payload["value"]))
            default: break
            }
        }
    }

    private static func sendNativeEvent(name: String, payload: [String: Any], message: String) -> [String: Any] {
        guard let data = try? JSONSerialization.data(withJSONObject: payload),
              let json = String(data: data, encoding: .utf8) else { return result(false, "unencodable payload") }
        return dispatch(message) { NativeElementBridge.sendNativeEvent(eventName: name, payloadJson: json) }
    }

    private static func dispatch(_ message: String, action: @escaping () -> Void) -> [String: Any] {
        if Thread.isMainThread { action() } else { DispatchQueue.main.async(execute: action) }
        return result(true, message)
    }

    private static func intValue(_ value: Any?) -> Int {
        if let value = value as? Int { return value }
        if let value = value as? NSNumber { return value.intValue }
        if let value = value as? String, let parsed = Int(value) { return parsed }
        return 0
    }

    private static func stringValue(_ value: Any?) -> String {
        if let value = value as? String { return value }
        if let value = value as? NSNumber { return value.stringValue }
        return ""
    }

    private static func boolValue(_ value: Any?) -> Bool {
        if let value = value as? Bool { return value }
        if let value = value as? NSNumber { return value.boolValue }
        if let value = value as? String { return value == "true" || value == "1" }
        return false
    }

    private static func floatValue(_ value: Any?) -> Float {
        if let value = value as? NSNumber { return value.floatValue }
        if let value = value as? String, let parsed = Float(value) { return parsed }
        return 0
    }

    private static func result(_ ok: Bool, _ message: String) -> [String: Any] {
        ["ok": ok, "message": message]
    }
}
