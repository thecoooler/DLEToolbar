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

        $this->removeFiles($this->directory . '/system', ['.htaccess', 'cron.json', 'cron.php']);

        if ($config['cache_type']) {
            $this->removeFiles($this->directory, ['.htaccess']);
        }

        clear_cache();
    }

    /** @param list<string> $excluded */
    private function removeFiles(string $directory, array $excluded): void
    {
        // A directory linked outside the cache is not cleared. See AUDIT.md, D4.
        $resolved = realpath($directory);
        if ($resolved === false || strpos($resolved . DIRECTORY_SEPARATOR,
            realpath($this->directory) . DIRECTORY_SEPARATOR) !== 0) {
            return;
        }

        foreach (new \DirectoryIterator($directory) as $entry) {
            $name = $entry->getFilename();
            if ($name !== '.' && $name !== '..' && !in_array($name, $excluded, true)
                && substr($name, -5) !== '.lock' && $entry->isFile()) {
                unlink($entry->getPathname());
            }
        }
    }
}
