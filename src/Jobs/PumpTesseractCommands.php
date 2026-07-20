<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Tesseract\NativeCollector\Commands\CommandPump;
use Tesseract\NativeCollector\Telemetry\TelemetryForwarder;
use Throwable;

/**
 * Drives the command pump from the shell's queue worker — a PHP runtime that
 * runs fully off the UI render loop, so host commands are serviced even when
 * the app is idle.
 *
 * The job ticks the pump once, refreshes the single-chain marker, then
 * re-dispatches itself, so one job chain perpetually pumps. `bootChain()` starts
 * exactly one chain (guarded by the shared DB cache) and restarts it only if the
 * chain has stalled. This is what makes execution independent of rendering
 * without a fragile second PHP runtime in the plugin.
 */
class PumpTesseractCommands implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Heartbeat marker; refreshed each tick. A boot only (re)starts a chain when this has lapsed. */
    public const ALIVE_KEY = 'tesseract:pump:alive';

    /** Once-per-runtime guard so a launch clears a stale marker exactly once. */
    private static bool $chainRestarted = false;

    public int $tries = 1;

    public int $timeout = 20;

    public function handle(CommandPump $pump): void
    {
        // This job runs in the shell's dedicated queue-worker runtime, which
        // exists solely to pump. Mute telemetry for the whole runtime so the
        // queue bookkeeping (jobs table, database cache) and command execution
        // never surface as app queries/logs. Thread-bound, so the render
        // runtime's telemetry is unaffected.
        TelemetryForwarder::suppress();

        // Drain repeatedly within this worker slot instead of once-per-dispatch,
        // so round-trip latency is bounded by the tick interval (~100ms) rather
        // than the queue worker's ~1s inter-job cadence. Bounded window so the
        // worker still gets to breathe / process other jobs.
        $intervalUs = max((int) config('tesseract-native.pump.tick_interval_ms', 100), 20) * 1000;
        $deadline = microtime(true) + max((int) config('tesseract-native.pump.tick_window_ms', 3000), 100) / 1000;

        do {
            try {
                $pump->tick();
            } catch (Throwable) {
                //
            }

            Cache::put(self::ALIVE_KEY, 1, now()->addSeconds(10));

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep($intervalUs);
        } while (true);

        self::dispatch();
    }

    /**
     * Start the pump chain once. Safe to call on every dispatch — only the first
     * (or a restart after a stall) actually launches a chain.
     */
    public static function bootChain(): void
    {
        // The chain marker (cache) and job dispatch are collector plumbing.
        // Mute around them so they don't surface as app cache/queue queries —
        // this path runs on the render runtime, where telemetry is live.
        $wasSuppressed = TelemetryForwarder::isSuppressed();
        TelemetryForwarder::suppress();

        try {
            if (Cache::add(self::ALIVE_KEY, 1, now()->addSeconds(10))) {
                self::dispatch();
            }
        } catch (Throwable) {
            //
        } finally {
            if (! $wasSuppressed) {
                TelemetryForwarder::resume();
            }
        }
    }

    /**
     * Start a live chain for this app launch, clearing a stale heartbeat first.
     *
     * An unclean previous exit can leave [ALIVE_KEY] set while its chain is dead;
     * plain [bootChain] would then decline to start (marker present) and there is
     * no retry — so pull commands (SQL, storage) hang until the app is relaunched
     * again after the marker lapses. Forgetting it once per runtime guarantees a
     * fresh chain from launch. Guarded so it can never clear a *live* marker and
     * spawn duplicate perpetual chains.
     */
    public static function restartChain(): void
    {
        if (self::$chainRestarted) {
            self::bootChain();

            return;
        }

        self::$chainRestarted = true;

        $wasSuppressed = TelemetryForwarder::isSuppressed();
        TelemetryForwarder::suppress();

        try {
            Cache::forget(self::ALIVE_KEY);
        } catch (Throwable) {
            //
        } finally {
            if (! $wasSuppressed) {
                TelemetryForwarder::resume();
            }
        }

        self::bootChain();
    }
}
