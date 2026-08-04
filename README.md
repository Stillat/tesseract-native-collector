# Tesseract Native Collector

Tesseract Native Collector is a NativePHP Mobile plugin that connects a device
application to the Tesseract desktop debugger. It forwards Laravel telemetry,
captures the native UI tree, and carries debugger commands between the device
and desktop.

> [!WARNING]
> This is a development plugin. It can expose logs, queries, storage files,
> media, source details, and Tinker execution to a paired Tesseract desktop
> session. Runtime collection is hard-disabled unless Laravel debug mode and
> the platform's native debug build flag are both enabled.

## Requirements

- PHP 8.3 or newer
- Laravel 12.41.1 or newer, or Laravel 13
- Laravel MCP 0.8.2 or any 0.9 release
- NativePHP Mobile 4.0 or newer
- Android SDK 33 or newer, or iOS 18.2 or newer
- A running Tesseract desktop debugger

## Installation

Install the package as a development dependency:

```bash
composer require --dev stillat/tesseract-native-collector
```

NativePHP requires plugins to be explicitly registered. If the application's
native plugin provider has not been published yet, run:

```bash
php artisan vendor:publish --tag=nativephp-plugins-provider
```

Then register the plugin:

```bash
php artisan native:plugin:register stillat/tesseract-native-collector
php artisan native:plugin:validate
```

Native code and manifest changes require a native rebuild. During development,
force a clean native project when necessary:

```bash
php artisan native:install --force
```

To publish the Laravel configuration:

```bash
php artisan vendor:publish --tag=tesseract-native-config
```

## PHP usage

The service provider starts the paired agent automatically when the NativePHP
runtime boots. The facade can also access the bridge directly:

```php
use Tesseract\NativeCollector\Facades\Tesseract;

if (Tesseract::isAvailable()) {
    Tesseract::connect([
        'projectKey' => 'example-app',
        'projectPath' => base_path(),
        'host' => '127.0.0.1',
        'relayPort' => 61230,
    ]);

    Tesseract::ingest([
        ['kind' => 'log', 'payload' => ['message' => 'Hello from the device']],
    ]);

    $status = Tesseract::status();
}
```

Available native bridge functions are:

| Function                 | Purpose                                        |
| ------------------------ | ---------------------------------------------- |
| `Tesseract.Connect`      | Opens or refreshes the paired desktop session. |
| `Tesseract.Ingest`       | Enqueues telemetry envelopes for the desktop.  |
| `Tesseract.Status`       | Returns agent transport and session health.    |
| `Tesseract.TakeCommands` | Returns buffered desktop commands.             |
| `Tesseract.Respond`      | Sends a command result back to the desktop.    |

## JavaScript usage

The package includes `resources/js/tesseract.js` for Inertia, Vue, React, or
vanilla JavaScript applications. Alias that file in the application's Vite
configuration, then call the same native bridge:

```js
import { ingest, status } from '@stillat/tesseract-native-collector';

await ingest([{ kind: 'interaction', payload: { action: 'save' } }]);
const agentStatus = await status();
```

For example, the alias target is
`vendor/stillat/tesseract-native-collector/resources/js/tesseract.js`. The client posts
to NativePHP's local `/_native/api/call` bridge endpoint; it does not connect to
the desktop directly.

## Native requirements

The plugin declares only `android.permission.INTERNET`. iOS enables
local-network transport through
`NSAppTransportSecurity.NSAllowsLocalNetworking`. Neither platform adds a
third-party native dependency.

## Configuration

The most commonly used environment variables are:

| Variable                                | Default     | Purpose                                              |
| --------------------------------------- | ----------- | ---------------------------------------------------- |
| `TESSERACT_NATIVE_ENABLED`              | `true`      | Master switch inside debug builds.                   |
| `TESSERACT_NATIVE_ENABLED_DURING_TESTS` | `false`     | Opts tests into device collection.                   |
| `TESSERACT_NATIVE_HOST`                 | `127.0.0.1` | Paired desktop host fallback.                        |
| `TESSERACT_NATIVE_RELAY_PORT`           | `61230`     | Desktop relay port fallback.                         |
| `TESSERACT_NATIVE_MCP_ENABLED`          | `true`      | Enables local MCP registration.                      |
| `TESSERACT_NATIVE_BOOST_ENABLED`        | `true`      | Enables optional Laravel Boost integration.          |
| `TESSERACT_NATIVE_INSTRUMENT_VIEWS`     | `true`      | Enables native view instrumentation.                 |

See [`config/tesseract-native.php`](config/tesseract-native.php) for all
telemetry categories, capture limits, queue-pump timings, and capabilities.

`TESSERACT_NATIVE_ENABLED` cannot override the safety gate: with
`APP_DEBUG=false`, the PHP observer, view instrumentation, transport, and
command handlers remain inactive. Android additionally requires a debuggable
application build and iOS requires `DEBUG`. Keeping the package as a
development dependency is still recommended so release artifacts omit it.

## Development

```bash
composer install
npm install
composer test
composer format:check
npm test
```

The JavaScript bridge is shipped directly as plain ESM from
`resources/js/tesseract.js`; this package intentionally has no bundling step.

Before a release, also run `php artisan native:plugin:validate` from a NativePHP
Mobile test application and exercise a clean build on physical Android and iOS
devices.

## Support and security

Please report suspected security vulnerabilities privately to
[support@stillat.com](mailto:support@stillat.com) instead of opening a public issue.

## License

Tesseract Native Collector is open-sourced software licensed under the
[MIT license](LICENSE.md).
