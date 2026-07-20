import Foundation
import UIKit

// Streams device/app lifecycle signals — orientation, foreground/background,
// memory pressure, locale and font-scale changes — as `native.device.event`
// envelopes, mirroring the Android `NativeDeviceBridge`.
//
// Like Android, physical orientation ("orientation") is reported separately
// from configuration rotation ("rotated"): NativePHP apps are commonly
// portrait-locked, where only the physical signal ever fires.
//
// NOT YET COMPILED — authored on a non-mac host; expect a first-build pass.
enum TesseractDeviceBridge {

    private static var attached = false
    private static var lastOrientationBucket: String?

    static func attach(emit: @escaping (_ kind: String, _ stream: String, _ payload: [String: Any]) -> Void) {
        guard !attached else {
            return
        }
        attached = true

        func event(_ type: String, _ detail: String?, extra: [String: Any] = [:]) {
            var payload: [String: Any] = ["type": type]
            if let detail {
                payload["detail"] = detail
            }
            for (key, value) in extra {
                payload[key] = value
            }
            emit("native.device.event", "native", payload)
        }

        let center = NotificationCenter.default

        DispatchQueue.main.async {
            UIDevice.current.beginGeneratingDeviceOrientationNotifications()
            lastOrientationBucket = orientationBucket(UIDevice.current.orientation)
        }

        center.addObserver(
            forName: UIDevice.orientationDidChangeNotification,
            object: nil,
            queue: .main
        ) { _ in
            guard let bucket = orientationBucket(UIDevice.current.orientation) else {
                return
            }
            if bucket == lastOrientationBucket {
                return
            }
            let isFirst = lastOrientationBucket == nil
            lastOrientationBucket = bucket
            if !isFirst {
                event("orientation", bucket, extra: ["degrees": orientationDegrees(UIDevice.current.orientation)])
            }
        }

        center.addObserver(
            forName: UIApplication.didEnterBackgroundNotification,
            object: nil,
            queue: .main
        ) { _ in
            event("backgrounded", nil)
        }

        center.addObserver(
            forName: UIApplication.willEnterForegroundNotification,
            object: nil,
            queue: .main
        ) { _ in
            event("foregrounded", nil)
        }

        center.addObserver(
            forName: UIApplication.didReceiveMemoryWarningNotification,
            object: nil,
            queue: .main
        ) { _ in
            event("memory-warning", "low")
        }

        center.addObserver(
            forName: NSLocale.currentLocaleDidChangeNotification,
            object: nil,
            queue: .main
        ) { _ in
            event("locale-changed", Locale.current.identifier)
        }

        center.addObserver(
            forName: UIContentSizeCategory.didChangeNotification,
            object: nil,
            queue: .main
        ) { _ in
            event("font-scale-changed", UIApplication.shared.preferredContentSizeCategory.rawValue)
        }

        center.addObserver(
            forName: .didRegisterForRemoteNotifications,
            object: nil,
            queue: .main
        ) { _ in
            event("push-registration-succeeded", nil)
        }

        center.addObserver(
            forName: .didFailToRegisterForRemoteNotifications,
            object: nil,
            queue: .main
        ) { notification in
            let error = notification.userInfo?["error"] as? Error
            event("push-registration-failed", error?.localizedDescription)
        }

        center.addObserver(
            forName: .didReceiveRemoteNotification,
            object: nil,
            queue: .main
        ) { notification in
            let payload = notification.userInfo?["payload"] as? [AnyHashable: Any]
            let keys = payload?.keys.map { String(describing: $0) }.sorted().joined(separator: ", ")
            event("push-received", keys?.isEmpty == false ? keys : nil)
        }
    }

    private static func orientationBucket(_ orientation: UIDeviceOrientation) -> String? {
        switch orientation {
        case .portrait:
            return "portrait"
        case .portraitUpsideDown:
            return "upside-down"
        case .landscapeLeft:
            return "landscape-left"
        case .landscapeRight:
            return "landscape-right"
        default:
            // faceUp / faceDown / unknown carry no rotation signal.
            return nil
        }
    }

    private static func orientationDegrees(_ orientation: UIDeviceOrientation) -> Int {
        switch orientation {
        case .portrait: return 0
        case .landscapeRight: return 90
        case .portraitUpsideDown: return 180
        case .landscapeLeft: return 270
        default: return 0
        }
    }
}
