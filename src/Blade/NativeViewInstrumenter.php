<?php

namespace Tesseract\NativeCollector\Blade;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\TraversalOptions;
use Illuminate\Support\Facades\Blade;
use ReflectionClass;
use Throwable;

/**
 * Sidecars per-element source metadata onto native views at Blade compile
 * time, using the same Forte hook point the WebView collector uses
 * (`prepareStringsForCompilationUsing` — runs before component tags and
 * before NativePHP's NativeTagPrecompiler, so the raw `<native:*>` tags are
 * still intact and every node's offsets match the authored file).
 *
 * Each native element gains a `tesseract-meta` attribute carrying
 * base64-encoded JSON `{f: <project-relative view file>, l: <line>}`. The
 * NativePHP's generic capture seam maps it to `_dbg_rt_tesseract` node props.
 * Base64 keeps the value inert for the
 * precompiler's quote-delimited attribute regexes.
 *
 * Scope guard: ONLY templates under a `views/native/` directory are touched —
 * regular Blade views compile byte-identically. Any parse failure returns the
 * template unmodified, so instrumentation can never break a build.
 */
class NativeViewInstrumenter
{
    protected static bool $registered = false;

    public static function register(): void
    {
        if (static::$registered) {
            return;
        }

        static::$registered = true;

        static::syncCompiledViewCache();

        if (! class_exists(Document::class)) {
            return;
        }

        Blade::prepareStringsForCompilationUsing(
            static fn (string $template): string => (new static)->instrument($template)
        );
    }

    /**
     * Blade only recompiles a view when its SOURCE changes, so a compiled
     * native view outlives changes to the instrumentation that shaped it — a
     * template compiled while this instrumenter or the shell's precompiler
     * differed stays stale (or broken) until the source file itself is
     * touched. Flush the compiled-view cache whenever the instrumentation
     * fingerprint changes so every boot renders views compiled by the
     * current pipeline. Best-effort; a read-only cache is left alone.
     */
    public static function syncCompiledViewCache(): void
    {
        try {
            $compiled = config('view.compiled');

            if (! is_string($compiled) || $compiled === '' || ! is_dir($compiled)) {
                return;
            }

            $marker = $compiled.'/.tesseract-native-views';
            $fingerprint = static::instrumentationFingerprint();

            if (is_file($marker) && trim((string) @file_get_contents($marker)) === $fingerprint) {
                return;
            }

            foreach (glob($compiled.'/*.php') ?: [] as $file) {
                @unlink($file);
            }

            @file_put_contents($marker, $fingerprint);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Identity of everything that shapes compiled native-view output: whether
     * instrumentation can run at all, this instrumenter's source, and the
     * shell's tag precompiler.
     */
    protected static function instrumentationFingerprint(): string
    {
        $parts = [class_exists(Document::class) ? 'forte' : 'no-forte'];

        foreach ([__FILE__, static::classFile('Native\Mobile\Edge\NativeTagPrecompiler')] as $file) {
            $parts[] = $file !== null && is_file($file) ? (string) md5_file($file) : 'absent';
        }

        return md5(implode('|', $parts));
    }

    protected static function classFile(string $class): ?string
    {
        try {
            return class_exists($class) ? ((new ReflectionClass($class))->getFileName() ?: null) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function instrument(string $template): string
    {
        $path = $this->compilingPath();

        if ($path === null || ! $this->isNativeViewPath($path)) {
            return $template;
        }

        try {
            $document = Document::parse($template);
        } catch (Throwable) {
            return $template;
        }

        try {
            $relativePath = $this->relativePath($path);
            $tags = $this->nativeTagNames();

            $rewritten = $document->rewrite(function ($builder) use ($document, $relativePath, $tags): void {
                foreach ($document->allOfType(ElementNode::class, TraversalOptions::deep()) as $element) {
                    if ($element->isSynthetic() || ! $this->isNativeElement($element, $tags)) {
                        continue;
                    }

                    $builder->queueSetAttribute(
                        $element,
                        'tesseract-meta',
                        base64_encode((string) json_encode([
                            'f' => $relativePath,
                            'l' => $element->startLine(),
                        ])),
                    );
                }
            });

            return $rewritten->render();
        } catch (Throwable) {
            return $template;
        }
    }

    protected function compilingPath(): ?string
    {
        try {
            $path = Blade::getPath();
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? $path : null;
    }

    protected function isNativeViewPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        if (str_contains($normalized, '/vendor/')) {
            return false;
        }

        return str_contains($normalized, '/views/native/');
    }

    /**
     * @param  array<string, true>  $tags
     */
    protected function isNativeElement(ElementNode $element, array $tags): bool
    {
        try {
            $name = strtolower($element->tagNameText());
        } catch (Throwable) {
            return false;
        }

        if (str_starts_with($name, 'native:')) {
            return true;
        }

        return isset($tags[str_replace('-', '_', $name)]);
    }

    /**
     * The registered element short-form tags (`column`, `row`, `text`, …) the
     * NativeTagPrecompiler also rewrites, so bare tags get source metadata too.
     *
     * @return array<string, true>
     */
    protected function nativeTagNames(): array
    {
        /** @var class-string $registry */
        $registry = 'Native\Mobile\Edge\ElementRegistry';

        if (! class_exists($registry) || ! method_exists($registry, 'all')) {
            return [];
        }

        try {
            return array_fill_keys(array_map('strval', array_keys($registry::all())), true);
        } catch (Throwable) {
            return [];
        }
    }

    protected function relativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path());

        if ($base !== '' && str_starts_with($normalized, $base)) {
            return ltrim(substr($normalized, strlen($base)), '/');
        }

        return $normalized;
    }
}
