<?php

namespace iCooLER\DLEToolbar\ComponentFramework;

/** Maps DLE's executed plugin-cache files to their original source paths and detects cache execution. */
final class SourcePath
{
    /** @return array{path: string, cached: bool} */
    public static function resolve(string $file): array
    {
        // DLE distributions may execute nested evals; their frames still name the cached source.
        $normalized = str_replace('\\', '/', $file);
        /** @var string $clean */
        $clean = (string) preg_replace("/(?:\\(\\d+\\) : eval\\(\\)'d code)+$/", '', $normalized);

        $cache = rtrim(str_replace('\\', '/', ENGINE_DIR), '/') . '/cache/system/plugins/';
        $isCached = stripos($clean, $cache) === 0;

        if ($isCached) {
            $readPlugins = \Closure::bind(static function (): array {
                /** @var array<string, string|array<mixed>> $plugins */
                // @phpstan-ignore staticProperty.notFound (dleplugin stub omits DLEPlugins::$plugins)
                $plugins = \DLEPlugins::$plugins;
                return $plugins;
            }, null, \DLEPlugins::class);
            $original = array_search(substr($clean, strlen($cache)), $readPlugins(), true);
            if ($original !== false) {
                $clean = str_replace('\\', '/', (string) $original);
            }
        }

        $root = rtrim(str_replace('\\', '/', ROOT_DIR), '/') . '/';
        $display = stripos($clean, $root) === 0 ? substr($clean, strlen($root)) : $clean;

        return ['path' => $display, 'cached' => $isCached];
    }

    public static function display(string $file): string
    {
        return self::resolve($file)['path'];
    }

    public static function isCached(string $file): bool
    {
        return self::resolve($file)['cached'];
    }
}
