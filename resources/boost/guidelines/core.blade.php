# Tesseract Native

Tesseract Native streams a NativePHP mobile app's Laravel telemetry and native UI tree to the Tesseract desktop debugger. When Tesseract MCP is available, use it before guessing from code alone for native render errors, PHP exceptions, logs, queries, screen navigations, and UI interactions.

## MCP Usage

Three tools, used in this order:

- **`tesseract-debug`** — first call for any "red screen / it froze / the app closed / a tap did nothing" report. Returns the latest crash candidate (a native render error or PHP exception, if any), surrounding context, and diagnostic patterns. Empty session returns an empty-shape envelope, never an error.
- **`tesseract-search`** — narrow follow-up queries with cursor pagination. Pick `kind: errors|logs|requests|queries|activity|sessions`. Native has no HTTP cycle: `requests` are the stand-in records for navigations, interactions (press/toggle), and component mount/unmount.
- **`tesseract-detail`** — full record for a single error or request id, plus surrounding context.

Inspecting the app (no crash needed):

- **`tesseract-screens`** — lists the registered screens (routes) and their backing NativeComponent classes. Read in-process from the shell router, so it works without a live device or the desktop. Filter with `query`.
- **`tesseract-view-tree`** — the current screen's forwarded component tree (navigations + interactions + lifecycle) from desktop history, timeline-ordered. A read of captured history, not a live device pull.

All responses are byte-bounded (16KB default). When data is dropped, `meta.truncated[]` names the field path and a concrete `next` tool call to recover it. Every tool is read-only. There is no navigate/set-scope write tool: driving the device needs the desktop command channel, which is not exposed to MCP clients.
