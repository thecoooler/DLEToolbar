<?php

namespace iCooLER\DLEToolbar\Components\Queries;

use iCooLER\DLEToolbar\ComponentFramework\SourcePath;

/**
 * Takes the place of DLE's query_list array and adds the call site to each appended entry.
 * The db object itself stays untouched, so references to it and DLE's error page keep working.
 *
 * @implements \ArrayAccess<int, array{query: string, time: float, num: int, source?: string}|null>
 * @implements \IteratorAggregate<int, array{query: string, time: float, num: int, source?: string}>
 */
final class QueryLog implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var array<int, array{query: string, time: float, num: int, source?: string}> */
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
     * Existing entries are returned by reference so callers can change their fields in place.
     * Missing entries emit PHP's undefined-key warning without inserting a null into the log.
     * @param int $offset
     * @return array{query: string, time: float, num: int, source?: string}|null
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
        if ($offset === null) {
            $this->entries[] = $value + self::source();
        } else {
            $this->entries[$offset] = $value;
        }
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

    /** @return \ArrayIterator<int, array{query: string, time: float, num: int, source?: string}> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->entries);
    }

    /** @return array{source?: string} */
    private static function source(): array
    {
        // ENGINE_DIR mixes separators on Windows, and Windows paths ignore case, so compare normalized paths case-insensitively.
        $engine = str_replace('\\', '/', ENGINE_DIR);
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            if (!isset($frame['file']) || $frame['file'] === __FILE__) {
                continue;
            }

            // PHP supplies line together with file.
            /** @var array{function: string, file: string, line: int} $frame */
            $file = str_replace('\\', '/', $frame['file']);
            if (stripos($file, $engine . '/classes/mysql.php') === 0) {
                continue;
            }

            $file = SourcePath::display($file);
            return ['source' => $file . ':' . $frame['line']];
        }
        return [];
    }
}
