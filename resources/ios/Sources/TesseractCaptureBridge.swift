import Foundation

enum TesseractCaptureBridge {
    private static let lock = NSLock()
    private static let queue = DispatchQueue(label: "tesseract-capture", qos: .utility)
    private static var treeSubscription: NativeElementObservationRegistry.Subscription?
    private static var eventSubscription: NativeElementObservationRegistry.Subscription?
    private static var pendingTreeJSON: String?
    private static var treeDrainScheduled = false

    static func attach(emit: @escaping (_ kind: String, _ stream: String, _ payload: [String: Any]) -> Void) {
        lock.lock()
        guard treeSubscription == nil && eventSubscription == nil else {
            lock.unlock()
            return
        }
        lock.unlock()

        let trees = NativeElementObservationRegistry.shared.observeTrees { json in
            enqueueLatestTree(json, emit: emit)
        }
        let events = NativeElementObservationRegistry.shared.observeEvents { json in
            queue.async {
                if let payload = decode(json) {
                    emit("native.interaction.recorded", "native", payload)
                }
            }
        }

        lock.lock()
        treeSubscription = trees
        eventSubscription = events
        lock.unlock()
    }

    static func detach() {
        lock.lock()
        let trees = treeSubscription
        let events = eventSubscription
        treeSubscription = nil
        eventSubscription = nil
        pendingTreeJSON = nil
        lock.unlock()

        if let trees { NativeElementObservationRegistry.shared.unsubscribe(trees) }
        if let events { NativeElementObservationRegistry.shared.unsubscribe(events) }
        DispatchQueue.main.async { TesseractInspectorState.shared.updateKnownNodes([]) }
    }

    private static func enqueueLatestTree(
        _ json: String,
        emit: @escaping (_ kind: String, _ stream: String, _ payload: [String: Any]) -> Void
    ) {
        lock.lock()
        pendingTreeJSON = json
        let shouldSchedule = !treeDrainScheduled
        treeDrainScheduled = true
        lock.unlock()
        guard shouldSchedule else { return }

        queue.async {
            while true {
                lock.lock()
                guard let next = pendingTreeJSON else {
                    treeDrainScheduled = false
                    lock.unlock()
                    return
                }
                pendingTreeJSON = nil
                lock.unlock()

                if let payload = decode(next) {
                    DispatchQueue.main.async { TesseractInspectorState.shared.updateKnownNodes(from: payload) }
                    emit("native.view.tree", "native", payload)
                }
            }
        }
    }

    private static func decode(_ json: String) -> [String: Any]? {
        guard let data = json.data(using: .utf8) else { return nil }
        return (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
    }
}
