<?php

namespace iCooLER\DLEToolbar\Components\Queries;

/**
 * Takes the place of DLE's query_list array and adds the call site to each appended entry.
 * The db object itself stays untouched, so references to it and DLE's error page keep working.
 *
 * @implements \ArrayAccess<int, array{query: string, time: float, num: int, source?: string, source_modified?: bool}>
 * @implements \IteratorAggregate<int, array{query: string, time: float, num: int, source?: string, source_modified?: bool}>
 */
final class QueryLog implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var array<int, array{query: string, time: float, num: int, source?: string, source_modified?: bool}> */
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
        /** @var array<int, array{query: string, time: float, num: int}> $entries */
        $entries = $db->query_list;
        // @phpstan-ignore assign.propertyType (DLE only appends, counts and iterates query_list)
        $db->query_list = new self($entries);
    }

    /** @param int $offset */
    public function offsetExists($offset): bool
    {
        return isset($this->entries[$offset]);
    }

    /**
     * @param int $offset
     * @return array{query: string, time: float, num: int, source?: string, source_modified?: bool}
     */
    public function offsetGet($offset): array
    {
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

    /** @return \ArrayIterator<int, array{query: string, time: float, num: int, source?: string, source_modified?: bool}> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->entries);
    }

    /** @return array{source?: string, source_modified?: bool} */
    private static function source(): array
    {
        // ENGINE_DIR mixes separators on Windows, and Windows paths ignore case, so compare normalized paths case-insensitively.
        $engine = str_replace('\\', '/', ENGINE_DIR);
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            if (!isset($frame['file']) || $frame['file'] === __FILE__) continue;

            // PHP supplies line together with file.
            /** @var array{function: string, file: string, line: int} $frame */
            // Encoded DLE distributions run their sources through eval; its line matches the source file.
            /** @var string $file */
            $file = preg_replace("/\\(\\d+\\) : eval\\(\\)'d code$/", '', str_replace('\\', '/', $frame['file']));
            if (stripos($file, $engine . '/classes/mysql.php') === 0) continue;

            $modified = false;
            $cache = $engine . '/cache/system/plugins/';
            if (stripos($file, $cache) === 0) {
                // DLE keeps this map private in both 14.x and 19.x.
                $readPlugins = \Closure::bind(static function (): array {
                    /** @var array<string, string|array<mixed>> $plugins */
                    // @phpstan-ignore staticProperty.notFound (dleplugin stub omits DLEPlugins::$plugins)
                    $plugins = \DLEPlugins::$plugins;
                    return $plugins;
                }, null, \DLEPlugins::class);
                $original = array_search(substr($file, strlen($cache)), $readPlugins(), true);
                if ($original !== false) {
                    $file = $original;
                    $modified = true;
                }
            }
            $root = str_replace('\\', '/', ROOT_DIR) . '/';
            if (stripos($file, $root) === 0) $file = substr($file, strlen($root));

            $source = ['source' => $file . ':' . $frame['line']];
            if ($modified) $source['source_modified'] = true;
            return $source;
        }
        return [];
    }
}
