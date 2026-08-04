<?php

declare(strict_types=1);
use Tesseract\NativeCollector\Jobs\PumpTesseractCommands;

return [
    // Effective only when Laravel and the native application are in debug mode.
    'enabled' => env('TESSERACT_NATIVE_ENABLED', true),

    'enabled_during_tests' => env('TESSERACT_NATIVE_ENABLED_DURING_TESTS', false),

    'transport' => [
        'host' => env('TESSERACT_NATIVE_HOST', '127.0.0.1'),
        'relay_port' => (int) env('TESSERACT_NATIVE_RELAY_PORT', 61230),
    ],

    'desktop_loopback_url' => env('TESSERACT_NATIVE_DESKTOP_LOOPBACK_URL'),

    'agent_control' => [
        'base_url' => env('TESSERACT_AGENT_BASE_URL'),
        'token' => env('TESSERACT_AGENT_TOKEN'),
        'config_path' => env('TESSERACT_AGENT_CONFIG'),
        'timeout_ms' => (int) env('TESSERACT_AGENT_TIMEOUT_MS', 1000),
    ],

    'connect_timeout_ms' => (int) env('TESSERACT_NATIVE_CONNECT_TIMEOUT_MS', 750),

    'mcp' => [
        'enabled' => env('TESSERACT_NATIVE_MCP_ENABLED', true),
        'handle' => env('TESSERACT_NATIVE_MCP_HANDLE', 'tesseract-native'),
    ],

    'boost' => [
        'enabled' => env('TESSERACT_NATIVE_BOOST_ENABLED', true),
    ],

    'telemetry' => [
        'logs' => env('TESSERACT_NATIVE_FORWARD_LOGS', true),
        'queries' => env('TESSERACT_NATIVE_FORWARD_QUERIES', true),
        'exceptions' => env('TESSERACT_NATIVE_FORWARD_EXCEPTIONS', true),
        'network' => env('TESSERACT_NATIVE_FORWARD_NETWORK', true),
        'dumps' => env('TESSERACT_NATIVE_FORWARD_DUMPS', true),
        'components' => env('TESSERACT_NATIVE_FORWARD_COMPONENTS', true),
        'navigation' => env('TESSERACT_NATIVE_FORWARD_NAVIGATION', true),
        'interactions' => env('TESSERACT_NATIVE_FORWARD_INTERACTIONS', true),
        'component_lifecycle' => env('TESSERACT_NATIVE_FORWARD_LIFECYCLE', true),
        'native_events' => env('TESSERACT_NATIVE_FORWARD_NATIVE_EVENTS', true),
        'routes' => env('TESSERACT_NATIVE_FORWARD_ROUTES', true),
        'events' => env('TESSERACT_NATIVE_FORWARD_EVENTS', true),
    ],

    'instrumentation' => [
        'native_views' => env('TESSERACT_NATIVE_INSTRUMENT_VIEWS', true),
    ],

    'observability' => [
        'native_events' => [
            'max_entries' => (int) env('TESSERACT_NATIVE_EVENT_MAX_ENTRIES', 100),
            'max_bytes' => (int) env('TESSERACT_NATIVE_EVENT_MAX_BYTES', 32768),
            'max_value_bytes' => (int) env('TESSERACT_NATIVE_EVENT_MAX_VALUE_BYTES', 2048),
            'max_depth' => (int) env('TESSERACT_NATIVE_EVENT_MAX_DEPTH', 4),
        ],
        'mail' => [
            'capture' => env('TESSERACT_NATIVE_MAIL_CAPTURE', 'preview'),
            'preview_max_bytes' => (int) env('TESSERACT_NATIVE_MAIL_PREVIEW_MAX_BYTES', 32768),
            'raw_max_bytes' => (int) env('TESSERACT_NATIVE_MAIL_RAW_MAX_BYTES', 131072),
            'attachment_max_bytes' => (int) env('TESSERACT_NATIVE_MAIL_ATTACHMENT_MAX_BYTES', 65536),
            'max_attachments' => (int) env('TESSERACT_NATIVE_MAIL_MAX_ATTACHMENTS', 10),
        ],
    ],

    'pump' => [
        'tick_window_ms' => (int) env('TESSERACT_NATIVE_PUMP_WINDOW_MS', 3000),
        'tick_interval_ms' => (int) env('TESSERACT_NATIVE_PUMP_INTERVAL_MS', 100),

        'suppress_query_tables' => [
            'jobs',
            'job_batches',
            'failed_jobs',
            'cache',
            'cache_locks',
            'migrations',
            'sqlite_master',
        ],

        'suppress_job_classes' => [
            PumpTesseractCommands::class,
        ],

        'suppress_cache_keys' => [
            'tesseract:pump:alive',
            'illuminate:queue:restart',
        ],
    ],

    'capabilities' => [
        'laravel-core',
        'native-runtime',
        'server-log',
        'server-query',
        'server-exception',
        'server-http-proxy',
        'server-sql',
        'server-tinker',
        'server-source',
        'server-storage',
    ],
];
