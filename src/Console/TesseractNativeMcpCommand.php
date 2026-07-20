<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Console;

use Illuminate\Console\Command;
use Laravel\Boost\Mcp\Boost;

class TesseractNativeMcpCommand extends Command
{
    protected $signature = 'tesseract-native:mcp {--json : Print only the .mcp.json server entry}';

    protected $description = 'Print MCP client configuration (Claude Code .mcp.json entry + stdio command) for the Tesseract native tools in this project.';

    public function handle(): int
    {
        $handle = trim((string) config('tesseract-native.mcp.handle', 'tesseract-native'));
        $boostActive = class_exists(Boost::class)
            && (bool) config('tesseract-native.boost.enabled', true)
            && $this->boostMcpCommandAvailable();

        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $cwd = base_path();

        [$serverName, $args] = $boostActive
            ? ['laravel-boost', [$artisan, 'boost:mcp']]
            : [$handle, [$artisan, 'mcp:start', $handle]];

        $entry = [
            'mcpServers' => [
                $serverName => [
                    'command' => $php,
                    'args' => $args,
                    'cwd' => $cwd,
                ],
            ],
        ];

        if ($this->option('json')) {
            $this->line($this->encode($entry));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('Tesseract Native MCP configuration');

        if ($boostActive) {
            $this->components->twoColumnDetail('Delivery', '<fg=green>via laravel-boost</> <fg=gray>tools ride the laravel-boost MCP server</>');
            $this->line('  The native Tesseract tools are merged into the <fg=cyan>laravel-boost</> MCP server, so');
            $this->line('  a client already configured for laravel-boost needs no extra entry. If you have not');
            $this->line('  added it yet, use the entry below.');
        } else {
            $this->components->twoColumnDetail('Delivery', '<fg=green>standalone</> <fg=gray>own MCP handle "'.$handle.'"</>');
        }

        $this->newLine();
        $this->line('<fg=yellow>Claude Code .mcp.json entry</> <fg=gray>(merge into the project\'s .mcp.json):</>');
        $this->newLine();
        $this->line($this->encode($entry));

        $this->newLine();
        $this->line('<fg=yellow>Generic stdio command</> <fg=gray>(run from '.$cwd.'):</>');
        $this->line('  <fg=cyan>'.$this->commandLine($php, $args).'</>');

        $this->newLine();
        $this->line('<fg=gray>The native tools read device sessions back from the Tesseract Desktop loopback.</>');
        $this->line('<fg=gray>Keep Tesseract Desktop running and the app paired so history is available.</>');

        if ($boostActive) {
            $this->newLine();
            $this->line('<fg=gray>To publish a dedicated "'.$handle.'" server instead of riding laravel-boost, set</>');
            $this->line('<fg=gray>TESSERACT_NATIVE_BOOST_ENABLED=false and re-run this command.</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function encode(array $entry): string
    {
        return (string) json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<string>  $args
     */
    protected function commandLine(string $php, array $args): string
    {
        return implode(' ', array_map(
            $this->quoteShellArgument(...),
            [$php, ...$args],
        ));
    }

    protected function quoteShellArgument(string $argument): string
    {
        if ($argument !== '' && preg_match('/[\s"\']/', $argument) !== 1) {
            return $argument;
        }

        return '"'.str_replace('"', '\\"', $argument).'"';
    }

    protected function boostMcpCommandAvailable(): bool
    {
        return $this->getApplication()?->has('boost:mcp') === true;
    }
}
