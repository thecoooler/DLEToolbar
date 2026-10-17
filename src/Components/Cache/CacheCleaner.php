<?php

namespace iCooLER\DLEToolbar\Components\Cache;

final class CacheCleaner
{
    public function clear(): void
    {
        global $config;
        /** @var array{cache_type: mixed} $config */

        $this->removeFiles(ENGINE_DIR . '/cache/system', ['.htaccess', 'cron.json', 'cron.php']);

        if ($config['cache_type']) {
            $this->removeFiles(ENGINE_DIR . '/cache', ['.htaccess']);
        }

        clear_cache();
    }

    /** @param list<string> $excluded */
    private function removeFiles(string $directory, array $excluded): void
    {
        if (!is_dir($directory)) {
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
