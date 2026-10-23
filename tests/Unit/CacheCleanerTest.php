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

it('does not enter a system directory that links outside the cache', function (): void {
    rmdir($this->cache . '/system');
    symlink($this->outside, $this->cache . '/system');
    writeCacheCleanerFiles($this->cache, ['news.tmp' => 'cache']);

    (new CacheCleaner($this->cache))->clear();

    expect(is_link($this->cache . '/system'))->toBeTrue()
        ->and(is_file($this->outside . '/secret.php'))->toBeTrue()
        ->and(is_file($this->outside . '/config.php'))->toBeTrue()
        ->and(is_file($this->cache . '/news.tmp'))->toBeFalse();
});

it('removes linked entries themselves and keeps their targets outside the cache', function (): void {
    symlink($this->outside . '/secret.php', $this->cache . '/system/linked.php');
    symlink($this->outside . '/config.php', $this->cache . '/linked.tmp');
    symlink($this->outside . '/missing.php', $this->cache . '/system/dangling.php');
    symlink($this->outside, $this->cache . '/system/linked-dir');
    symlink($this->outside, $this->cache . '/linked-dir');
    writeCacheCleanerFiles($this->cache . '/system', ['news.php' => 'cache', 'cron.json' => '{}']);

    (new CacheCleaner($this->cache))->clear();

    expect(is_link($this->cache . '/system/linked.php'))->toBeFalse()
        ->and(is_link($this->cache . '/linked.tmp'))->toBeFalse()
        ->and(is_link($this->cache . '/system/dangling.php'))->toBeFalse()
        ->and(is_link($this->cache . '/system/linked-dir'))->toBeTrue()
        ->and(is_link($this->cache . '/linked-dir'))->toBeTrue()
        ->and(is_file($this->cache . '/system/news.php'))->toBeFalse()
        ->and(is_file($this->cache . '/system/cron.json'))->toBeTrue()
        ->and(is_file($this->outside . '/secret.php'))->toBeTrue()
        ->and(is_file($this->outside . '/config.php'))->toBeTrue();
});

it('clears a cache directory that is itself a link and a system link that stays inside it', function (): void {
    $linkedCache = $this->fixture . '/linked-cache';
    symlink($this->cache, $linkedCache);
    rmdir($this->cache . '/system');
    writeCacheCleanerFiles($this->cache . '/storage', ['news.php' => 'cache']);
    symlink($this->cache . '/storage', $this->cache . '/system');
    writeCacheCleanerFiles($this->cache, ['news.tmp' => 'cache', '.htaccess' => 'deny']);

    (new CacheCleaner($linkedCache))->clear();

    expect(is_file($this->cache . '/news.tmp'))->toBeFalse()
        ->and(is_file($this->cache . '/.htaccess'))->toBeTrue()
        ->and(is_file($this->cache . '/storage/news.php'))->toBeFalse()
        ->and(is_link($this->cache . '/system'))->toBeTrue();
});

it('removes nothing when the cache directory does not resolve', function (): void {
    $workingDirectory = getcwd();
    chdir($this->outside);
    try {
        (new CacheCleaner($this->fixture . '/missing'))->clear();
    } finally {
        chdir($workingDirectory);
    }

    expect(is_file($this->outside . '/secret.php'))->toBeTrue()
        ->and(is_file($this->outside . '/config.php'))->toBeTrue();
});
