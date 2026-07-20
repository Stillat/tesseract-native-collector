<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Commands;

use Tesseract\NativeCollector\NativeAgent;
use Tesseract\NativeCollector\Telemetry\TelemetryForwarder;
use Throwable;

/**
 * Drains the host -> target commands the agent has buffered, executes each in
 * this PHP runtime (where Laravel is properly initialized), and posts the result
 * back through the agent.
 *
 * The agent polls the desktop continuously off-thread; this runs on the PHP
 * dispatch path. A fully render-independent pump is blocked by the shell today
 * (a plugin cannot safely execute PHP off the render/queue runtimes — see
 * install notes), so execution rides the dispatch the app already produces while
 * the developer interacts with it. Every command is answered so the desktop's
 * pending request resolves rather than timing out.
 */
class CommandPump
{
    public function __construct(
        protected NativeAgent $agent,
        protected CommandExecutor $executor,
    ) {}

    public function tick(): void
    {
        if (! $this->agent->isAvailable()) {
            return;
        }

        foreach ($this->agent->takeCommands() as $command) {
            if (! is_array($command)) {
                continue;
            }

            $commandId = $command['commandId'] ?? null;
            $kind = is_string($command['kind'] ?? null) ? $command['kind'] : null;

            if (! is_string($commandId) || $commandId === '') {
                continue;
            }

            // A command's execution (SQL, Tinker, storage) is collector-driven
            // debugging, not app activity — mute telemetry so its own queries /
            // requests don't pollute the capture.
            $wasSuppressed = TelemetryForwarder::isSuppressed();
            TelemetryForwarder::suppress();

            try {
                $outcome = $this->executor->execute($command);
                $this->agent->respond($commandId, $kind, $outcome['status'], $outcome['detail']);
            } catch (Throwable $exception) {
                $this->agent->respond($commandId, $kind, 'error', [
                    'status' => 500,
                    'body' => ['message' => $exception->getMessage()],
                ]);
            } finally {
                if (! $wasSuppressed) {
                    TelemetryForwarder::resume();
                }
            }
        }
    }
}
