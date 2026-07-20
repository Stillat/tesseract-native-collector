---
name: tesseract-native-debugging
description: Use this skill when debugging a NativePHP mobile app with Tesseract MCP history. Trigger for native red screens, frozen screens, the app closing, unresponsive taps, failing screen navigations, PHP exceptions, and slow interaction handlers.
---

# Tesseract Native Debugging

## Start With Evidence

Use Tesseract MCP before changing code when the user describes a recent runtime problem on the mobile app. Native history can include shell render errors, PHP exceptions, Laravel logs, database queries, screen navigations, dispatched UI interactions, and component lifecycle.

Use the tools in this order:

- **`tesseract-debug`** — first call for "red screen", "it froze", "the app closed", "the button does nothing", or "what just happened". Read `crash`, `diagnostics.patterns`, `triage.hints`, and `meta.truncated`.
- **`tesseract-search`** — narrow follow-up queries. Pick `kind: errors|logs|requests|queries|activity|sessions`, apply filters or a time window, and page with `cursor` / `nextCursor`.
- **`tesseract-detail`** — full record for one error or request. Pass `kind: error|request` and the id returned by `tesseract-debug` or `tesseract-search`.

To inspect the app itself (not a failure):

- **`tesseract-screens`** — the app's registered screens (routes) and their NativeComponent classes. Read in-process from the shell router, so it works with no device connected. Use it to map a route to its component before reading that screen's history.
- **`tesseract-view-tree`** — the current screen's forwarded component tree (navigations, interactions, lifecycle) from history, timeline-ordered. It reads captured desktop history, not a live device pull; expand any entry with `tesseract-detail kind: "request"`.

All responses are byte-bounded. When data is dropped, `meta.truncated[]` gives a concrete next call — prefer it over retrying the same broad request. Every tool is read-only; there is no navigate/set-scope write tool (driving the device needs the desktop command channel, which MCP clients cannot reach).

## How Native Maps Onto History

Native has no HTTP request cycle, so three native concepts are captured as stand-in **requests**:

- **Navigations** (`nav-*`): a screen changed. The record carries the from/to route and the landing scope. It is the correlation anchor for logs, queries, and events that follow.
- **Interactions** (`int-*`): a dispatched UI event (press/toggle). The record carries the handler method and the scope delta it produced. Queries and logs a handler emits correlate to its interaction id, not the surrounding navigation.
- **Component lifecycle** (`life-*`): a component mounted or unmounted.

Errors split by runtime: `native` is the shell render/dispatch layer (the on-device red screen the run loop would otherwise swallow); `php` is the in-process Laravel runtime.

## Crash Triage

Users say "crash" for a real exception, a red screen, a frozen UI, or a tap that did nothing. Do not anchor on the word before reading the evidence.

Read `tesseract-debug` in this order:

1. `diagnostics.patterns[].verification` — confirmed, demoted, or inconclusive.
2. `crash` — non-null means Tesseract found an error candidate.
3. `diagnostics.patterns[]` — likely non-crash behavior such as interaction flood or log burst.
4. `triage.hints[]` — ordered suggestions.
5. `meta.truncated[]` — exact follow-up calls for omitted data.

When `crash` and a confirmed pattern both exist, treat the pattern as the upstream cause until proven otherwise. Repeated taps on one control can dispatch duplicate handlers and then a render error; the fix is usually input gating or an idempotent handler, not the top stack frame.

## Timeframes

The first `tesseract-debug` resolves to the most recent native session — correct for "just now", often wrong for an earlier run. A relaunch opens a fresh, empty session; use `meta.recentSessions[]` or `tesseract-search kind: "sessions"` to pivot to the run that actually holds the failure, matching by timeframe first, not error count.

## Reporting Back

Separate facts from hypotheses. Cite runtime, error type, message, top frame, the screen (navigation) it happened on, and the interaction immediately before it. If `crash` is null, lead with that. If no history exists, ask the user to reproduce with Tesseract Desktop running and the device connected — Tesseract only sees sessions captured while it is running.
