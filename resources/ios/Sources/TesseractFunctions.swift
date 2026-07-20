import Foundation

// The PHP-callable surface of the agent. Each class is registered in
// nativephp.json under `bridge_functions` and reached via
// `nativephp_call('Tesseract.<Method>', json)` — the Swift mirror of the
// Kotlin `TesseractFunctions`.
//
// NOT YET COMPILED — authored on a non-mac host; expect a first-build pass.
enum TesseractFunctions {

    /// PHP ignition: open the desktop session and start the transport. Idempotent.
    class Connect: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            TesseractAgent.shared.connect(config: parameters)

            return ["success": true, "connected": true]
        }
    }

    class Ingest: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let envelopes: [[String: Any]]

            if let list = parameters["envelopes"] as? [[String: Any]] {
                envelopes = list
            } else if let json = parameters["envelopes"] as? String,
                      let data = json.data(using: .utf8),
                      let parsed = (try? JSONSerialization.jsonObject(with: data)) as? [[String: Any]] {
                envelopes = parsed
            } else {
                envelopes = []
            }

            let accepted = TesseractAgent.shared.ingest(envelopes: envelopes)

            return ["success": true, "accepted": accepted]
        }
    }

    /// Health probe for `tesseract:doctor` and the desktop.
    class Status: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            var status = TesseractAgent.shared.status()
            status["success"] = true

            return status
        }
    }

    /// Hand PHP the buffered host -> target commands to execute. Returned as a
    /// JSON string so arbitrarily-nested command payloads survive the bridge.
    class TakeCommands: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let commands = TesseractAgent.shared.takeCommands()
            let data = (try? JSONSerialization.data(withJSONObject: commands)) ?? Data("[]".utf8)
            let json = String(data: data, encoding: .utf8) ?? "[]"

            return ["success": true, "commands": json]
        }
    }

    class Respond: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let commandId = (parameters["commandId"] as? String) ?? ""
            let kind = parameters["kind"] as? String
            let status = (parameters["status"] as? String) ?? "error"

            var detail: [String: Any]?
            if let raw = parameters["detail"] as? [String: Any] {
                detail = raw
            } else if let json = parameters["detail"] as? String,
                      !json.isEmpty,
                      let data = json.data(using: .utf8) {
                detail = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
            }

            let accepted = TesseractAgent.shared.respond(
                commandId: commandId,
                kind: kind,
                status: status,
                detail: detail
            )

            return ["success": true, "accepted": accepted]
        }
    }
}
