<?php

namespace iCooLER\DLEToolbar\Components\Queries;

/**
 * Takes the place of DLE's query_list array and adds the call site to each appended entry.
 * The db object itself stays untouched, so references to it and DLE's error page keep working.
 *
 * @implements \ArrayAccess<int, array{query: string, time: float, num: int, source?: string, plugin?: string}|null>
 * @implements \IteratorAggregate<int, array{query: string, time: float, num: int, source?: string, plugin?: string}>
 */
final class QueryLog implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var array<int, array{query: string, time: float, num: int, source?: string, plugin?: string}> */
    private array $entries;

    /** @param array<int, array{query: string, time: float, num: int}> $entries */
    private function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    public static function install(): void
    {
        global $db;
        /** @var \db $db */
        // @phpstan-ignore instanceof.alwaysFalse
        if ($db->query_list instanceof self) {
            PluginMap::load();
            return;
        }
        /** @var array<int, array{query: string, time: float, num: int}> $entries */
        $entries = (array) $db->query_list;
        // @phpstan-ignore assign.propertyType (DLE only appends, counts and iterates query_list)
        $db->query_list = new self($entries);
        PluginMap::load();
    }

    /** @param int $offset */
    public function offsetExists($offset): bool
    {
        return isset($this->entries[$offset]);
    }

    /**
     * Existing entries are returned by reference so callers can change their fields in place.
     * Missing entries emit PHP's undefined-key warning without inserting a null into the log.
     * @param int $offset
     * @return array{query: string, time: float, num: int, source?: string, plugin?: string}|null
     */
    public function &offsetGet($offset): ?array
    {
        if (!isset($this->entries[$offset])) {
            /** @var null $missing PHP returns null after reporting the undefined key. */
            $missing = $this->entries[$offset];
            return $missing;
        }
        return $this->entries[$offset];
    }

    /**
     * @param int|null $offset
     * @param array{query: string, time: float, num: int} $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($offset === null) $this->entries[] = $value + self::source();
        else $this->entries[$offset] = $value;
    }

    /** @param int $offset */
    public function offsetUnset($offset): void
    {
        unset($this->entries[$offset]);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return \ArrayIterator<int, array{query: string, time: float, num: int, source?: string, plugin?: string}> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->entries);
    }

    /** @return array{source?: string, plugin?: string} */
    private static function source(): array
    {
        $engine = str_replace('\\', '/', ENGINE_DIR);
        $root = str_replace('\\', '/', ROOT_DIR) . '/';
        $cache = $engine . '/cache/system/plugins/';
        $first = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            if (!isset($frame['file'], $frame['line']) || $frame['file'] === __FILE__) continue;

            /** @var array{function: string, file: string, line: int} $frame */
            /** @var string $file */
            $file = (string) preg_replace("/(?:\\(\\d+\\) : eval\\(\\)'d code)+$/", '', str_replace('\\', '/', $frame['file']));
            if (stripos($file, $engine . '/classes/mysql.php') === 0) continue;

            $built = null;
            if (stripos($file, $cache) === 0) {
                $built = $file;
                $original = self::originalOf($file, substr($file, strlen($cache)));
                if ($original !== null) $file = $original;
            }
            if (stripos($file, $root) === 0) $file = substr($file, strlen($root));
            if ($built !== null && PluginMap::isCreated($file)) $built = null;

            $source = $file . ':' . (int) $frame['line'] . ($built !== null ? ' (копия с плагинами)' : '');
            $plugin = PluginMap::resolve($file, (int) $frame['line'], $built);
            if ($plugin !== null) return ['source' => $source, 'plugin' => $plugin];
            if ($first === null) $first = $source;
        }
        return $first === null ? [] : ['source' => $first];
    }

    private static function originalOf(string $built, string $name): ?string
    {
        foreach (self::pluginFiles() as $original => $target) {
            if (!is_string($target)) continue;
            $target = str_replace('\\', '/', $target);
            if ($target === $name || $target === $built || basename($target) === $name) {
                return str_replace('\\', '/', (string) $original);
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function pluginFiles(): array
    {
        static $read = null;
        if ($read === null) {
            $read = \Closure::bind(static function (): array {
                /** @var array<string, mixed> $plugins */
                // @phpstan-ignore staticProperty.notFound
                $plugins = is_array(\DLEPlugins::$plugins ?? null) ? \DLEPlugins::$plugins : [];
                return $plugins;
            }, null, \DLEPlugins::class);
        }
        return $read();
    }
}
