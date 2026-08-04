import Foundation

func initializeTesseract() {
    #if DEBUG
    TesseractInspector.register()
    #endif
}
