<?php

namespace iCooLER\DLEToolbar\ComponentFramework;

/** Maps DLE's executed plugin-cache files to their original source paths for display. */
final class SourcePath
{
    public static function display(string $file): string
    {
        // DLE distributions may execute nested evals; their frames still name the cached source.
        /** @var string $file */
        $file = preg_replace("/(?:\\(\\d+\\) : eval\\(\\)'d code)+$/", '', str_replace('\\', '/', $file));
        $cache = rtrim(str_replace('\\', '/', ENGINE_DIR), '/') . '/cache/system/plugins/';
        if (stripos($file, $cache) === 0) {
            $readPlugins = \Closure::bind(static function (): array {
                /** @var array<string, string|array<mixed>> $plugins */
                // @phpstan-ignore staticProperty.notFound (dleplugin stub omits DLEPlugins::$plugins)
                $plugins = \DLEPlugins::$plugins;
                return $plugins;
            }, null, \DLEPlugins::class);
            $original = array_search(substr($file, strlen($cache)), $readPlugins(), true);
            if ($original !== false) {
                $file = str_replace('\\', '/', (string) $original);
            }
        }

        $root = rtrim(str_replace('\\', '/', ROOT_DIR), '/') . '/';
        return stripos($file, $root) === 0 ? substr($file, strlen($root)) : $file;
    }
}
