package com.tesseract.collector

import android.content.Context
import android.util.Log
import com.tesseract.collector.capture.NativeCaptureBridge
import com.tesseract.collector.capture.NativeCommandBridge
import com.tesseract.collector.capture.NativeDeviceBridge
import com.tesseract.collector.transport.DesktopTransport
import com.tesseract.collector.transport.InvalidSessionException
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.UUID
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.atomic.AtomicInteger
import java.util.concurrent.atomic.AtomicReference

/**
 * The native Tesseract agent.
 *
 * Owns everything network: the desktop session, the broadcast queue, the live
 * transport (WS primary, relay fallback), and command receipt. PHP never talks
 * to the desktop — it only hands envelopes to [ingest]; the agent stamps them
 * with session identity and a monotonic sequence, then ships them off the main
 * thread.
 *
 * Lifecycle: [prime] is called from the build-registered init hook so the
 * singleton exists early; [connect] (PHP ignition) supplies the desktop
 * endpoint and starts the worker + capture. Both are idempotent.
 */
object TesseractAgent {

    private val lock = Any()

    @Volatile private var appContext: Context? = null
    @Volatile private var started = false
    @Volatile private var connected = false

    private var transport: DesktopTransport? = null
    private var worker: Thread? = null
    private var pump: Thread? = null
    private var transportConfigSignature: String = ""

    // Bounded so a desktop that never comes up can't grow the queue without
    // limit; overflow drops the oldest envelopes (the newest state wins).
    private const val OUTBOUND_CAPACITY = 4000
    private const val COMMAND_BUFFER_CAPACITY = 1000
    private const val SEND_BATCH_LIMIT = 50

    // A refused batch is held and retried (the desktop hub may be mid-restart)
    // instead of dropped on the first failed send; after the retries are
    // exhausted it is dropped so a dead desktop can't wedge the worker.
    private const val SEND_RETRY_LIMIT = 3
    private const val SEND_RETRY_DELAY_MS = 500L

    private val outbound = LinkedBlockingQueue<JSONObject>(OUTBOUND_CAPACITY)
    private val commandBuffer = LinkedBlockingQueue<JSONObject>(COMMAND_BUFFER_CAPACITY)
    private val seq = AtomicInteger(0)
    private val pendingTreeEnvelope = AtomicReference<JSONObject?>(null)
    private val isoFormatter = object : ThreadLocal<SimpleDateFormat>() {
        override fun initialValue(): SimpleDateFormat {
            return SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSS'Z'", Locale.US).apply {
                timeZone = TimeZone.getTimeZone("UTC")
            }
        }
    }

    @Volatile private var sessionId: String? = null
    @Volatile private var token: String? = null
    @Volatile private var captureId: String = UUID.randomUUID().toString()

    fun prime(context: Context) {
        appContext = context
    }

    fun connect(context: Context, config: JSONObject) {
        val nextTransport = DesktopTransport(
            host = config.optString("host", "127.0.0.1"),
            relayPort = config.optInt("relayPort", 61230),
            appName = config.optString("appName", "Laravel"),
            appUrl = config.optString("appUrl", ""),
            projectKey = config.optString("projectKey", ""),
            projectPath = config.optString("projectPath", ""),
            relayUrl = config.optString("relayUrl", ""),
            capabilities = config.optJSONArray("capabilities") ?: JSONArray(),
        )
        val nextSignature = transportSignatureFor(config)

        synchronized(lock) {
            appContext = context
            if (started) {
                if (nextSignature != transportConfigSignature) {
                    transport = nextTransport
                    transportConfigSignature = nextSignature
                    sessionId = null
                    token = null
                    connected = false
                    Log.w("Tesseract", "desktop transport config changed; reopening session")
                }

                return
            }
            started = true
            transport = nextTransport
            transportConfigSignature = nextSignature

            startWorker()
            startCommandPump()

            NativeCaptureBridge.attach(::emit)
            NativeDeviceBridge.attach(context, ::emit)
        }
    }

    private fun transportSignatureFor(config: JSONObject): String {
        return listOf(
            config.optString("host", "127.0.0.1"),
            config.optInt("relayPort", 61230).toString(),
            config.optString("appName", "Laravel"),
            config.optString("appUrl", ""),
            config.optString("projectKey", ""),
            config.optString("projectPath", ""),
            config.optString("relayUrl", ""),
            config.optJSONArray("capabilities")?.toString() ?: "[]",
        ).joinToString("|")
    }

    /**
     * Drain the buffered host -> target commands for PHP to execute and respond
     * to. Returned as a JSON string so nested command payloads survive the
     * bridge intact.
     */
    fun takeCommands(): JSONArray {
        val commands = JSONArray()
        while (true) {
            val command = commandBuffer.poll() ?: break
            commands.put(command)
        }
        return commands
    }

    /** Keep host commands bounded while PHP is unavailable; newest wins. */
    private fun enqueueCommand(command: JSONObject) {
        while (!commandBuffer.offer(command)) {
            if (commandBuffer.poll() == null) {
                commandBuffer.offer(command)
                return
            }
        }
    }

    fun respond(commandId: String, kind: String?, status: String, detail: JSONObject?): Boolean {
        val ok = transport?.respondCommand(sessionId, token, captureId, commandId, kind, status, detail) ?: false
        Log.i("Tesseract", "respond id=$commandId kind=$kind status=$status ok=$ok")
        return ok
    }

    fun ingest(envelopes: JSONArray): Boolean {
        var count = 0
        for (i in 0 until envelopes.length()) {
            val envelope = envelopes.optJSONObject(i) ?: continue
            enqueue(stamp(envelope))
            count++
        }
        return count > 0
    }

    fun emit(kind: String, stream: String, payload: JSONObject) {
        val envelope = JSONObject()
            .put("source", "native")
            .put("stream", stream)
            .put("kind", kind)
            .put("payload", payload)
        stamp(envelope)

        if (kind == "native.view.tree") {
            // A stale tree is useless once a newer frame exists. Keep at most
            // one queued tree so an offline desktop cannot retain thousands of
            // large UI snapshots inside the otherwise count-bounded queue.
            pendingTreeEnvelope.getAndSet(envelope)?.let { outbound.remove(it) }
        }

        enqueue(envelope)
    }

    private fun enqueue(envelope: JSONObject) {
        while (!outbound.offer(envelope)) {
            val evicted = outbound.poll()
            if (evicted == null) {
                // The queue drained between the failed offer and the poll —
                // there's room again, so retry the offer once before giving up.
                outbound.offer(envelope)
                return
            }
            pendingTreeEnvelope.compareAndSet(evicted, null)
        }
    }

    fun status(): JSONObject {
        return JSONObject()
            .put("started", started)
            .put("connected", connected)
            .put("sessionId", sessionId ?: JSONObject.NULL)
            .put("queued", outbound.size)
            .put("transport", transport?.mode() ?: "none")
    }

    private fun resetSession(reason: String) {
        synchronized(lock) {
            sessionId = null
            token = null
            connected = false
        }

        Log.w("Tesseract", "desktop session reset: $reason")
    }

    private fun ensureSession(maxAttempts: Int = 30): Boolean {
        if (sessionId != null) {
            return true
        }

        var attempt = 0

        while (started && sessionId == null && attempt < maxAttempts) {
            attempt++

            try {
                val session = transport?.openSession()
                val id = session?.optString("sessionId") ?: ""

                if (id.isNotEmpty()) {
                    synchronized(lock) {
                        sessionId = id
                        token = session?.optString("token")
                        connected = true
                    }

                    Log.i("Tesseract", "session opened: $id capture=$captureId via ${transport?.mode()}")

                    return true
                }

                Log.w("Tesseract", "session open attempt $attempt got no session; retrying")
                Thread.sleep(2000)
            } catch (t: Throwable) {
                Log.w("Tesseract", "session open attempt $attempt error: ${t.message}")
                Thread.sleep(2000)
            }
        }

        if (sessionId == null) {
            Log.w("Tesseract", "gave up opening a session after $attempt attempts")
        }

        return sessionId != null
    }

    /**
     * Stamp identity + ordering at enqueue time. The sessionId is deliberately
     * NOT set here: envelopes can be queued before the session opens, and a
     * baked-in "" would survive to the wire. The worker restamps the live
     * sessionId right before send.
     */
    private fun stamp(envelope: JSONObject): JSONObject {
        envelope.put("version", 1)
        envelope.put("captureId", captureId)
        envelope.put("seq", seq.incrementAndGet())
        if (!envelope.has("sentAt")) {
            envelope.put("sentAt", isoNow())
        }
        return envelope
    }

    private fun startWorker() {
        val thread = Thread({
            // Open the session with retry: at app boot the desktop hub / adb
            // reverse tunnel may not be ready for a beat. Envelopes accumulate
            // in the queue until the session is live, then drain.
            ensureSession()

            // A batch whose send failed is held here and retried before new
            // envelopes are drained, so a transient desktop hiccup doesn't
            // silently drop it. New envelopes keep queueing meanwhile.
            var pending: MutableList<JSONObject>? = null
            var pendingFailures = 0

            fun holdOrDrop(batch: MutableList<JSONObject>?) {
                pendingFailures++
                if (batch == null || pendingFailures > SEND_RETRY_LIMIT) {
                    if (batch != null) {
                        Log.w(
                            "Tesseract",
                            "dropping batch of ${batch.size} envelopes after $pendingFailures consecutive failed sends",
                        )
                        for (envelope in batch) {
                            pendingTreeEnvelope.compareAndSet(envelope, null)
                        }
                    }
                    pending = null
                    pendingFailures = 0
                } else {
                    pending = batch
                }
            }

            while (started) {
                var batch = pending
                try {
                    if (batch == null) {
                        batch = ArrayList(SEND_BATCH_LIMIT)
                        batch.add(outbound.take())
                        outbound.drainTo(batch, SEND_BATCH_LIMIT - 1)
                    }

                    if (!ensureSession()) {
                        pending = batch
                        Thread.sleep(SEND_RETRY_DELAY_MS)
                        continue
                    }

                    val id = sessionId
                    val envelopes = JSONArray()
                    for (envelope in batch) {
                        envelope.put("sessionId", id ?: "")
                        envelopes.put(envelope)
                    }

                    val ok = transport?.sendBatch(id, token, envelopes) ?: false
                    connected = ok

                    if (ok) {
                        for (envelope in batch) {
                            pendingTreeEnvelope.compareAndSet(envelope, null)
                        }
                        pending = null
                        pendingFailures = 0
                    } else {
                        holdOrDrop(batch)
                        if (pending == null) {
                            for (envelope in batch) {
                                pendingTreeEnvelope.compareAndSet(envelope, null)
                            }
                        }
                        if (pending != null) {
                            Thread.sleep(SEND_RETRY_DELAY_MS)
                        }
                    }
                } catch (ie: InterruptedException) {
                    break
                } catch (stale: InvalidSessionException) {
                    resetSession("desktop rejected telemetry session")
                    pending = batch
                    Thread.sleep(SEND_RETRY_DELAY_MS)
                } catch (t: Throwable) {
                    Log.w("Tesseract", "send failed: ${t.message}")
                    holdOrDrop(batch)
                }
            }
        }, "tesseract-agent")
        thread.isDaemon = true
        worker = thread
        thread.start()
    }

    /**
     * The command pump thread: polls the desktop for host commands and buffers
     * them. Polling off-thread keeps the capture "commandable" on the desktop
     * (each poll marks activity) and decouples fetch from PHP execution. PHP
     * drains the buffer via [takeCommands], executes in its own properly
     * initialized runtime, and answers via [respond].
     *
     * Executing PHP from this thread directly is NOT possible in the current
     * shell: a plugin-created PHPBridge re-runs php_embed_init and crashes the
     * process (TSRM double-init), and the worker runtime is owned by the shell's
     * queue worker. A fully render-independent pump needs the shell to expose a
     * thread-safe plugin execution entry (see install notes).
     */
    private fun startCommandPump() {
        val thread = Thread({
            while (started) {
                try {
                    val id = sessionId
                    if (id == null) {
                        ensureSession(maxAttempts = 1)
                        Thread.sleep(300)
                        continue
                    }

                    val commands = transport?.pollCommands(id, token, captureId)
                    if (commands != null && commands.length() > 0) {
                        for (i in 0 until commands.length()) {
                            commands.optJSONObject(i)?.let { command ->
                                val commandKind = command.optString("kind")
                                if (commandKind.startsWith("native.")) {
                                    // Native commands are serviced by the agent
                                    // (they drive native render bridges), not by PHP.
                                    val commandId = command.optString("commandId")
                                    val payload = command.optJSONObject("payload") ?: JSONObject()
                                    val outcome = when (commandKind) {
                                        "native.highlight" -> NativeCommandBridge.highlight(payload)
                                        "native.clear-highlight" -> NativeCommandBridge.clearHighlight()
                                        "native.scroll-into-view" -> NativeCommandBridge.scrollIntoView(payload)
                                        "native.navigate" -> NativeCommandBridge.navigate(payload)
                                        "native.rotate" -> NativeCommandBridge.rotate(payload)
                                        "native.set-scope" -> NativeCommandBridge.setScope(payload)
                                        "native.set-style" -> NativeCommandBridge.setStyle(payload)
                                        "native.call" -> NativeCommandBridge.call(payload)
                                        else -> NativeCommandBridge.dispatch(payload)
                                    }
                                    respond(
                                        commandId,
                                        commandKind,
                                        if (outcome.optBoolean("ok")) "ok" else "error",
                                        outcome,
                                    )
                                    Log.i("Tesseract", "native command $commandKind -> ${outcome.optString("message")}")
                                } else {
                                    Log.i("Tesseract", "buffered command kind=$commandKind id=${command.optString("commandId")}")
                                    enqueueCommand(command)
                                }
                            }
                        }
                    } else {
                        Thread.sleep(250)
                    }
                } catch (ie: InterruptedException) {
                    break
                } catch (stale: InvalidSessionException) {
                    resetSession("desktop rejected command session")
                    ensureSession(maxAttempts = 3)
                    Thread.sleep(300)
                } catch (t: Throwable) {
                    Log.w("Tesseract", "command pump error: ${t.message}")
                    Thread.sleep(1500)
                }
            }
        }, "tesseract-command-pump")
        thread.isDaemon = true
        pump = thread
        thread.start()
    }

    private fun isoNow(): String {
        return isoFormatter.get()!!.format(Date())
    }
}
