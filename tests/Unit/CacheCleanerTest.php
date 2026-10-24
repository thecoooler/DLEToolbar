<?php

use iCooLER\DLEToolbar\Components\Cache\CacheCleaner;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

function removeCacheCleanerFixture(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') {
            removeCacheCleanerFixture($path . '/' . $name);
        }
    }
    rmdir($path);
}

/** @param array<string, string> $files */
function writeCacheCleanerFiles(string $directory, array $files): void
{
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    foreach ($files as $name => $contents) {
        file_put_contents($directory . '/' . $name, $contents);
    }
}

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    $this->fixture = sys_get_temp_dir() . '/dletoolbar-cache-' . bin2hex(random_bytes(6));
    $this->cache = $this->fixture . '/cache';
    $this->outside = $this->fixture . '/outside';
    mkdir($this->cache . '/system', 0777, true);
    writeCacheCleanerFiles($this->outside, ['secret.php' => 'secret', 'config.php' => 'config']);
    $this->previousFastCache = $GLOBALS['dlefastcache'] ?? null;
    $GLOBALS['dlefastcache'] = (object) ['connection' => 0];
    $GLOBALS['config']['cache_type'] = 1;
});

afterEach(function (): void {
    removeCacheCleanerFixture($this->fixture);
    if ($this->previousFastCache === null) unset($GLOBALS['dlefastcache']);
    else $GLOBALS['dlefastcache'] = $this->previousFastCache;
});

it('does not clear a system directory that links outside the cache', function (): void {
    rmdir($this->cache . '/system');
    symlink($this->outside, $this->cache . '/system');
    writeCacheCleanerFiles($this->cache, ['news.tmp' => 'cache']);

    (new CacheCleaner($this->cache))->clear();

    expect(is_file($this->outside . '/secret.php'))->toBeTrue()
        ->and(is_file($this->outside . '/config.php'))->toBeTrue()
        ->and(is_file($this->cache . '/news.tmp'))->toBeFalse();
});

it('removes links to files themselves and keeps their targets', function (): void {
    symlink($this->outside . '/secret.php', $this->cache . '/system/linked.php');
    symlink($this->outside, $this->cache . '/system/linked-dir');
    writeCacheCleanerFiles($this->cache . '/system', ['news.php' => 'cache']);

    (new CacheCleaner($this->cache))->clear();

    expect(is_link($this->cache . '/system/linked.php'))->toBeFalse()
        ->and(is_link($this->cache . '/system/linked-dir'))->toBeTrue()
        ->and(is_file($this->cache . '/system/news.php'))->toBeFalse()
        ->and(is_file($this->outside . '/secret.php'))->toBeTrue();
});

it('clears a cache directory that is itself a link', function (): void {
    $linkedCache = $this->fixture . '/linked-cache';
    symlink($this->cache, $linkedCache);
    writeCacheCleanerFiles($this->cache . '/system', ['news.php' => 'cache']);
    writeCacheCleanerFiles($this->cache, ['news.tmp' => 'cache']);

    (new CacheCleaner($linkedCache))->clear();

    expect(is_file($this->cache . '/system/news.php'))->toBeFalse()
        ->and(is_file($this->cache . '/news.tmp'))->toBeFalse();
});
