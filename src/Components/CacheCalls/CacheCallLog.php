<?php

namespace iCooLER\DLEToolbar\Components\CacheCalls;

use iCooLER\DLEToolbar\ComponentFramework\SourcePath;
use iCooLER\DLEToolbar\Components\Debug\DebugLog;

/**
 * Request-local journal of the dle_cache() and create_cache() calls made on the page.
 *
 * DLE places the wrapper functions from dleplugin.yml around the original functions,
 * so an entry exists only while the toolbar component is visible.
 */
final class CacheCallLog
{
    private static bool $active = false;

    /** @var list<array{operation: string, prefix: string, key: string, member: bool, status: string, backend: string, size: int|null, at: float, duration: float, source: string}> */
    private static array $entries = [];

    public static function start(): void
    {
        self::$active = true;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Runs a dle_cache() read and records whether it returned a cached value.
     *
     * @param int|string|false|null $cacheId
     * @param callable(): mixed $read
     * @return mixed
     */
    public static function read(string $prefix, $cacheId, bool $memberPrefix, callable $read)
    {
        $key = self::key($prefix, $cacheId, $memberPrefix);
        $source = self::source();
        $backend = self::backend();
        $enabled = self::enabled();
        // DLE removes an expired file while answering, so its earlier presence separates expiry from a plain miss.
        $existed = $enabled && $backend === 'files' && is_file(self::cacheFile($key));

        $started = hrtime(true);
        $result = $read();
        $duration = (hrtime(true) - $started) / 1e6;

        if (!$enabled) {
            $status = 'disabled';
        } elseif ($result !== false) {
            $status = 'hit';
        } elseif ($existed) {
            $status = 'expired';
        } else {
            $status = 'miss';
        }

        self::append(
            'read',
            $prefix,
            $key,
            $memberPrefix,
            $status,
            $backend,
            is_string($result) ? strlen($result) : null,
            $duration,
            $source,
        );

        return $result;
    }

    /**
     * Runs a create_cache() write and records whether DLE accepted it.
     *
     * @param string|false|null $cacheText
     * @param int|string|false|null $cacheId
     * @param callable(): mixed $write
     * @return mixed
     */
    public static function write(string $prefix, $cacheText, $cacheId, bool $memberPrefix, callable $write)
    {
        $key = self::key($prefix, $cacheId, $memberPrefix);
        $source = self::source();
        $backend = self::backend();
        $enabled = self::enabled();

        $started = hrtime(true);
        $result = $write();
        $duration = (hrtime(true) - $started) / 1e6;

        self::append(
            'write',
            $prefix,
            $key,
            $memberPrefix,
            $enabled ? 'written' : 'disabled',
            $backend,
            $cacheText === false ? 0 : strlen((string) $cacheText),
            $duration,
            $source,
        );

        return $result;
    }

    /** @return list<array{operation: string, prefix: string, key: string, member: bool, status: string, backend: string, size: int|null, at: float, duration: float, source: string}> */
    public static function entries(): array
    {
        return self::$entries;
    }

    /** Clears state when several requests are simulated in one test process. */
    public static function reset(): void
    {
        self::$active = false;
        self::$entries = [];
    }

    private static function append(
        string $operation,
        string $prefix,
        string $key,
        bool $memberPrefix,
        string $status,
        string $backend,
        ?int $size,
        float $duration,
        string $source
    ): void {
        self::$entries[] = [
            'operation' => $operation,
            'prefix' => $prefix,
            'key' => $key,
            'member' => $memberPrefix,
            'status' => $status,
            'backend' => $backend,
            'size' => $size,
            'at' => DebugLog::elapsed(),
            'duration' => $duration,
            'source' => $source,
        ];
    }

    private static function enabled(): bool
    {
        global $config;
        /** @var array{allow_cache: mixed} $config */

        return (bool) $config['allow_cache'];
    }

    private static function backend(): string
    {
        global $config, $dlefastcache;
        /** @var array{cache_type: int|string} $config */
        /** @var object{connection: int}|null $dlefastcache */

        if ($config['cache_type'] && isset($dlefastcache) && $dlefastcache->connection > 0) {
            return (string) $config['cache_type'] === '2' ? 'redis' : 'memcache';
        }

        return 'files';
    }

    /** @param int|string|false|null $cacheId */
    private static function key(string $prefix, $cacheId, bool $memberPrefix): string
    {
        if (!$cacheId) {
            return $prefix;
        }

        // DLE hashes the id and optionally adds the member group exactly the same way.
        $key = $prefix . '_' . md5((string) $cacheId);
        if ($memberPrefix) {
            global $is_logged, $member_id;
            /** @var array{user_group: int|string} $member_id */
            $key .= '_' . ($is_logged ? (string) $member_id['user_group'] : '0');
        }

        return $key;
    }

    private static function cacheFile(string $key): string
    {
        return ENGINE_DIR . '/cache/' . $key . '.tmp';
    }

    /** Returns the module or plugin that called the cache function, never the wrapper itself. */
    private static function source(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $frame) {
            if (($frame['class'] ?? '') === self::class) {
                continue;
            }
            if (!isset($frame['file'])) {
                continue;
            }
            return SourcePath::display($frame['file']) . ':' . ($frame['line'] ?? 0);
        }

        return '';
    }
}
