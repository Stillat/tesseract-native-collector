import Foundation
import UIKit

// Swift port of the Android `TesseractAgent`. Owns everything network: the
// desktop session, the bounded broadcast queue, the HTTP relay transport, and
// command receipt. PHP never talks to the desktop — it only hands envelopes to
// `ingest`; the agent stamps them with identity and a monotonic sequence, then
// ships them off the main thread.
//
// NOT YET COMPILED — authored on a non-mac host. Treat as a reviewed draft:
// the logic mirrors the proven Kotlin agent, but expect a first-build pass.
final class TesseractAgent {

    static let shared = TesseractAgent()

    private init() {}

    private let lock = NSLock()
    private let outboundLock = NSLock()

    private var started = false
    private var connected = false

    private var transport: TesseractDesktopTransport?

    private var outbound: [[String: Any]] = []
    private var commandBuffer: [[String: Any]] = []

    private var seq: Int = 0
    private var sessionId: String?
    private var token: String?
    private let captureId = UUID().uuidString

    private let workerQueue = DispatchQueue(label: "tesseract-agent", qos: .utility)
    private let pumpQueue = DispatchQueue(label: "tesseract-command-pump", qos: .utility)
    private var pumpTimer: DispatchSourceTimer?

    private static let outboundCapacity = 4000
    private static let commandBufferCapacity = 1000
    private static let sendBatchLimit = 50
    private static let sendRetryLimit = 3
    private static let sendRetryDelay = 0.5
    private static let isoFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter
    }()
    private static let isoFormatterLock = NSLock()

    func connect(config: [String: Any]) {
        lock.lock()
        defer { lock.unlock() }

        if started {
            return
        }
        started = true

        transport = TesseractDesktopTransport(
            host: (config["host"] as? String) ?? "127.0.0.1",
            relayPort: (config["relayPort"] as? Int) ?? 61230,
            appName: (config["appName"] as? String) ?? "Laravel",
            appUrl: (config["appUrl"] as? String) ?? "",
            projectKey: (config["projectKey"] as? String) ?? "",
            projectPath: (config["projectPath"] as? String) ?? "",
            relayUrl: (config["relayUrl"] as? String) ?? "",
            capabilities: (config["capabilities"] as? [Any]) ?? []
        )

        startWorker()
        startCommandPump()

        TesseractCaptureBridge.attach { [weak self] kind, stream, payload in
            self?.emit(kind: kind, stream: stream, payload: payload)
        }
        TesseractDeviceBridge.attach { [weak self] kind, stream, payload in
            self?.emit(kind: kind, stream: stream, payload: payload)
        }
    }

    func ingest(envelopes: [[String: Any]]) -> Bool {
        var count = 0
        for envelope in envelopes {
            enqueue(stamp(envelope))
            count += 1
        }
        return count > 0
    }

    func takeCommands() -> [[String: Any]] {
        outboundLock.lock()
        defer { outboundLock.unlock() }
        let commands = commandBuffer
        commandBuffer.removeAll()
        return commands
    }

    func respond(commandId: String, kind: String?, status: String, detail: [String: Any]?) -> Bool {
        guard let transport, let id = sessionId else {
            return false
        }
        return transport.respondCommand(
            sessionId: id,
            token: token,
            captureId: captureId,
            commandId: commandId,
            kind: kind,
            status: status,
            detail: detail
        )
    }

    func status() -> [String: Any] {
        outboundLock.lock()
        let queued = outbound.count
        outboundLock.unlock()

        return [
            "started": started,
            "connected": connected,
            "sessionId": sessionId ?? NSNull(),
            "queued": queued,
            "transport": transport != nil ? "relay" : "none",
        ]
    }

    func emit(kind: String, stream: String, payload: [String: Any]) {
        var envelope: [String: Any] = [
            "source": "native",
            "stream": stream,
            "kind": kind,
            "payload": payload,
        ]
        envelope = stamp(envelope)
        enqueue(envelope, coalescingKind: kind == "native.view.tree" ? kind : nil)
    }

    /// Stamp identity + ordering at enqueue time. The sessionId is deliberately
    /// NOT set here — envelopes can queue before the session opens; the worker
    /// restamps the live sessionId right before send.
    private func stamp(_ envelope: [String: Any]) -> [String: Any] {
        var stamped = envelope
        outboundLock.lock()
        seq += 1
        stamped["seq"] = seq
        outboundLock.unlock()
        stamped["version"] = 1
        stamped["captureId"] = captureId
        if stamped["sentAt"] == nil {
            stamped["sentAt"] = Self.isoNow()
        }
        return stamped
    }

    private func enqueue(_ envelope: [String: Any], coalescingKind: String? = nil) {
        outboundLock.lock()

        if let coalescingKind,
           let index = outbound.lastIndex(where: { ($0["kind"] as? String) == coalescingKind }) {
            // Replace a queued tree in place: once a newer frame exists the
            // older full snapshot has no live-inspector value and can be large.
            outbound[index] = envelope
            outboundLock.unlock()
            return
        }

        outbound.append(envelope)
        if outbound.count > Self.outboundCapacity {
            outbound.removeFirst(outbound.count - Self.outboundCapacity)
        }
        outboundLock.unlock()
        signalWorker()
    }

    private let workerSemaphore = DispatchSemaphore(value: 0)

    private func signalWorker() {
        workerSemaphore.signal()
    }

    private func ensureSession(maxAttempts: Int) -> Bool {
        if sessionId != nil {
            return true
        }

        var attempt = 0
        while started && sessionId == nil && attempt < maxAttempts {
            attempt += 1
            if let session = transport?.openSession(),
               let id = session["sessionId"] as? String, !id.isEmpty {
                sessionId = id
                token = session["token"] as? String
                connected = true
                NSLog("Tesseract: session opened \(id) capture=\(captureId)")
                return true
            }

            if attempt < maxAttempts {
                Thread.sleep(forTimeInterval: 2.0)
            }
        }

        return sessionId != nil
    }

    private func startWorker() {
        workerQueue.async { [weak self] in
            guard let self else { return }

            // Open the session with retry: at app boot the desktop hub may not
            // be ready for a beat. Envelopes accumulate until the session is
            // live, then drain.
            _ = self.ensureSession(maxAttempts: 30)

            var pendingBatch: [[String: Any]]?
            var pendingFailures = 0

            while self.started {
                if pendingBatch == nil {
                    _ = self.workerSemaphore.wait(timeout: .now() + 2.0)
                }

                // Retry session discovery each pass so a desktop launched
                // after app boot is still picked up.
                if self.sessionId == nil && !self.ensureSession(maxAttempts: 1) {
                    continue
                }

                let batch: [[String: Any]]
                if let pendingBatch {
                    batch = pendingBatch
                } else {
                    self.outboundLock.lock()
                    batch = Array(self.outbound.prefix(Self.sendBatchLimit))
                    self.outbound.removeFirst(min(batch.count, self.outbound.count))
                    self.outboundLock.unlock()
                }

                if batch.isEmpty {
                    continue
                }

                let id = self.sessionId
                let stampedBatch = batch.map { envelope -> [String: Any] in
                    var restamped = envelope
                    restamped["sessionId"] = id ?? ""
                    return restamped
                }

                let ok = self.transport?.sendBatch(
                    sessionId: id,
                    token: self.token,
                    envelopes: stampedBatch
                ) ?? false
                self.connected = ok

                if ok {
                    pendingBatch = nil
                    pendingFailures = 0
                } else {
                    pendingFailures += 1
                    if pendingFailures <= Self.sendRetryLimit {
                        pendingBatch = batch
                        Thread.sleep(forTimeInterval: Self.sendRetryDelay)
                    } else {
                        NSLog("Tesseract: dropping \(batch.count) envelopes after \(pendingFailures) failed sends")
                        pendingBatch = nil
                        pendingFailures = 0
                        self.sessionId = nil
                        self.token = nil
                    }
                }

                self.outboundLock.lock()
                let remaining = self.outbound.count
                self.outboundLock.unlock()
                if remaining > 0 {
                    self.workerSemaphore.signal()
                }
            }
        }
    }

    /// The command pump: polls the desktop for host commands. `native.*`
    /// commands are serviced directly (they drive shell hooks); the rest are
    /// buffered for PHP to drain via `takeCommands`.
    private func startCommandPump() {
        let timer = DispatchSource.makeTimerSource(queue: pumpQueue)
        timer.schedule(deadline: .now() + 1.0, repeating: .milliseconds(300))
        timer.setEventHandler { [weak self] in
            guard let self, self.started, let id = self.sessionId else { return }

            guard let commands = self.transport?.pollCommands(
                sessionId: id,
                token: self.token,
                captureId: self.captureId
            ) else { return }

            for command in commands {
                let kind = (command["kind"] as? String) ?? ""
                if kind.hasPrefix("native.") {
                    let commandId = (command["commandId"] as? String) ?? ""
                    let payload = (command["payload"] as? [String: Any]) ?? [:]
                    let outcome = TesseractCommandBridge.execute(kind: kind, payload: payload)
                    _ = self.respond(
                        commandId: commandId,
                        kind: kind,
                        status: (outcome["ok"] as? Bool) == true ? "ok" : "error",
                        detail: outcome
                    )
                } else {
                    self.outboundLock.lock()
                    self.commandBuffer.append(command)
                    if self.commandBuffer.count > Self.commandBufferCapacity {
                        self.commandBuffer.removeFirst(self.commandBuffer.count - Self.commandBufferCapacity)
                    }
                    self.outboundLock.unlock()
                }
            }
        }
        timer.resume()
        pumpTimer = timer
    }

    private static func isoNow() -> String {
        isoFormatterLock.lock()
        defer { isoFormatterLock.unlock() }

        return isoFormatter.string(from: Date())
    }
}
