import Foundation

// Swift port of the Android `DesktopTransport`: HTTP relay to the Tesseract
// desktop hub over URLSession. On a simulator the host/ports are loopback; on
// a device the pairing file supplies a reachable relayUrl. Synchronous-style
// calls via semaphore — only ever invoked from the agent's background queues.
//
// NOT YET COMPILED — authored on a non-mac host; expect a first-build pass.
final class TesseractDesktopTransport {

    private let host: String
    private let relayPort: Int
    private let appName: String
    private let appUrl: String
    private let projectKey: String
    private let projectPath: String
    private let relayUrl: String
    private let capabilities: [Any]

    private let session: URLSession

    init(
        host: String,
        relayPort: Int,
        appName: String,
        appUrl: String,
        projectKey: String,
        projectPath: String,
        relayUrl: String,
        capabilities: [Any]
    ) {
        self.host = host
        self.relayPort = relayPort
        self.appName = appName
        self.appUrl = appUrl
        self.projectKey = projectKey
        self.projectPath = projectPath
        self.relayUrl = relayUrl
        self.capabilities = capabilities

        let configuration = URLSessionConfiguration.ephemeral
        configuration.timeoutIntervalForRequest = 5
        configuration.timeoutIntervalForResource = 8
        self.session = URLSession(configuration: configuration)
    }

    private func relayBase() -> String {
        let trimmed = relayUrl.trimmingCharacters(in: .whitespaces)
        if !trimmed.isEmpty {
            return trimmed.hasSuffix("/") ? String(trimmed.dropLast()) : trimmed
        }
        return "http://\(host):\(relayPort)"
    }

    /// Returns the session payload ({sessionId, token, wsUrl, ...}) or nil
    /// when the hub is unreachable.
    func openSession() -> [String: Any]? {
        let body: [String: Any] = [
            "projectKey": projectKey,
            "projectPath": projectPath,
            "appName": appName,
            "appUrl": appUrl.isEmpty ? "native://\(appName)" : appUrl,
            "relayUrl": relayBase(),
            "capabilities": capabilities,
            "runtime": "native",
        ]

        return postJson("\(relayBase())/api/transport/sessions", body: body)
    }

    /// Ship a batch of envelopes in one relay POST. The hub honors each
    /// envelope's own `source` field, so mixed batches are fine.
    func sendBatch(sessionId: String?, token: String?, envelopes: [[String: Any]]) -> Bool {
        guard let sessionId, !sessionId.isEmpty, !envelopes.isEmpty else {
            return false
        }

        let source = (envelopes.first?["source"] as? String) ?? "native"
        let body: [String: Any] = [
            "sessionId": sessionId,
            "token": token ?? "",
            "source": source,
            "envelopes": envelopes,
        ]

        return postJson("\(relayBase())/api/transport/ingest", body: body) != nil
    }

    func pollCommands(sessionId: String?, token: String?, captureId: String) -> [[String: Any]]? {
        guard let sessionId, !sessionId.isEmpty else {
            return nil
        }

        let body: [String: Any] = [
            "sessionId": sessionId,
            "token": token ?? "",
            "captureId": captureId,
            "maxCommands": 10,
        ]

        guard let response = postJson("\(relayBase())/api/transport/commands/poll", body: body) else {
            return nil
        }

        return (response["commands"] as? [[String: Any]]) ?? []
    }

    func respondCommand(
        sessionId: String?,
        token: String?,
        captureId: String,
        commandId: String,
        kind: String?,
        status: String,
        detail: [String: Any]?
    ) -> Bool {
        guard let sessionId, !sessionId.isEmpty else {
            return false
        }

        let body: [String: Any] = [
            "sessionId": sessionId,
            "token": token ?? "",
            "captureId": captureId,
            "commandId": commandId,
            "kind": kind ?? NSNull(),
            "status": status,
            "detail": detail ?? NSNull(),
        ]

        return postJson("\(relayBase())/api/transport/commands/respond", body: body) != nil
    }

    private func postJson(_ url: String, body: [String: Any]) -> [String: Any]? {
        guard let requestUrl = URL(string: url),
              let payload = try? JSONSerialization.data(withJSONObject: body) else {
            return nil
        }

        var request = URLRequest(url: requestUrl)
        request.httpMethod = "POST"
        request.httpBody = payload
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        let semaphore = DispatchSemaphore(value: 0)
        var result: [String: Any]?

        let task = session.dataTask(with: request) { data, response, error in
            defer { semaphore.signal() }

            if error != nil {
                return
            }

            guard let http = response as? HTTPURLResponse, (200...299).contains(http.statusCode) else {
                return
            }

            guard let data, !data.isEmpty else {
                result = [:]
                return
            }

            result = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:]
        }
        task.resume()
        _ = semaphore.wait(timeout: .now() + 10)

        return result
    }
}
