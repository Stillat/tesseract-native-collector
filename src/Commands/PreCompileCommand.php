<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Commands;

use Illuminate\Support\Facades\File;
use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

class PreCompileCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:tesseract:pre-compile';

    protected $description = 'Injects Tesseract Android startup integration before native compilation';

    public function handle(): int
    {
        if (! $this->isAndroid()) {
            return self::SUCCESS;
        }

        $mainActivityPath = $this->buildPath().'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';

        if (! File::isFile($mainActivityPath)) {
            $this->components->error("NativePHP MainActivity was not found at {$mainActivityPath}.");

            return self::FAILURE;
        }

        $source = File::get($mainActivityPath);
        $marker = 'TESSERACT_MIRROR_ORIENTATION_BOOTSTRAP';

        if (str_contains($source, $marker)) {
            return self::SUCCESS;
        }

        $newline = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $needle = implode($newline, [
            '    override fun onCreate(savedInstanceState: Bundle?) {',
            '        super.onCreate(savedInstanceState)',
        ]);
        $replacement = implode($newline, [
            '    override fun onCreate(savedInstanceState: Bundle?) {',
            '        // TESSERACT_MIRROR_ORIENTATION_BOOTSTRAP',
            '        when (',
            '            android.provider.Settings.Global.getString(',
            '                contentResolver,',
            '                "tesseract_mirror_rotation",',
            '            )?.toIntOrNull()',
            '        ) {',
            '            0 -> requestedOrientation = android.content.pm.ActivityInfo.SCREEN_ORIENTATION_PORTRAIT',
            '            1 -> requestedOrientation = android.content.pm.ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE',
            '            2 -> requestedOrientation = android.content.pm.ActivityInfo.SCREEN_ORIENTATION_REVERSE_PORTRAIT',
            '            3 -> requestedOrientation = android.content.pm.ActivityInfo.SCREEN_ORIENTATION_REVERSE_LANDSCAPE',
            '        }',
            '',
            '        super.onCreate(savedInstanceState)',
        ]);

        $updated = str_replace($needle, $replacement, $source, $replacementCount);

        if ($replacementCount !== 1) {
            $this->components->error('NativePHP MainActivity onCreate signature was not recognized.');

            return self::FAILURE;
        }

        File::put($mainActivityPath, $updated);

        return self::SUCCESS;
    }
}
