<?php

namespace iCooLER\DLEToolbar\Components\Cache;

final class CacheCleaner
{
    private string $directory;

    public function __construct(string $directory = ENGINE_DIR . '/cache')
    {
        $this->directory = $directory;
    }

    public function clear(): void
    {
        global $config;
        /** @var array{cache_type: mixed} $config */

        // A worker keeps resolved paths for realpath_cache_ttl seconds.
        clearstatcache(true);

        // Without a resolved root the confinement check would accept any path.
        $root = $this->resolve($this->directory);
        if ($root !== false) {
            $this->removeFiles($root, $root . '/system', ['.htaccess', 'cron.json', 'cron.php']);

            if ($config['cache_type']) {
                $this->removeFiles($root, $root, ['.htaccess']);
            }
        }

        clear_cache();
    }

    /**
     * Removes entries of a directory that resolves inside the cache root.
     * unlink() removes a symbolic link itself, never its target; links
     * to directories are skipped like real subdirectories.
     *
     * @param list<string> $excluded
     */
    private function removeFiles(string $root, string $directory, array $excluded): void
    {
        $directory = $this->resolve($directory);
        if ($directory === false
            || ($directory !== $root && strpos($directory, $root . DIRECTORY_SEPARATOR) !== 0)) {
            return;
        }

        foreach (new \DirectoryIterator($directory) as $entry) {
            $name = $entry->getFilename();
            if ($name !== '.' && $name !== '..' && !in_array($name, $excluded, true)
                && substr($name, -5) !== '.lock' && !$entry->isDir()) {
                unlink($entry->getPathname());
            }
        }
    }

    /**
     * On Windows realpath() expands directory links and junctions one level
     * at a time, so a chain of them is resolved until the path stops changing.
     *
     * @return string|false
     */
    private function resolve(string $path)
    {
        for ($level = 0; $level < 40; $level++) {
            $resolved = realpath($path);
            if ($resolved === false || $resolved === $path) {
                return $resolved;
            }
            $path = $resolved;
        }

        return false;
    }
}
