<?php

namespace iCooLER\DLEToolbar\Components\Queries;

/** Adds call sites to DLE's query log without changing its connection or query semantics. */
final class TracingDb extends \db
{
    /** @var array<int, array{query: string, time: float, num: int, source?: string, source_modified?: bool}> */
    public $query_list = [];

    public static function install(): void
    {
        global $db;
        /** @var \db $db */
        if ($db instanceof self) return;

        $tracing = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        // Run in db's scope so private connection, result and error fields are transferred too.
        $transfer = \Closure::bind(static function (\db $previous, \db $replacement): void {
            foreach (get_object_vars($previous) as $key => $value) {
                $replacement->$key = $value;
            }
            // The previous object's destructor must not close the transferred connection.
            // @phpstan-ignore property.notFound (dleplugin stub omits db private fields)
            $previous->db_id = null;
        }, null, \db::class);
        $transfer($db, $tracing);
        $db = $tracing;
    }

    /**
     * @param string $query
     * @param bool $show_error
     * @param bool $log_query
     * @return \mysqli_result|bool
     */
    public function query($query, $show_error=true, $log_query=true)
    {
        $count = count($this->query_list);
        /** @var \mysqli_result|bool $result */
        $result = parent::query($query, $show_error, $log_query);
        if ($log_query && count($this->query_list) > $count) $this->recordSource();
        return $result;
    }

    /**
     * @param string $query
     * @param bool $show_error
     * @param bool $log_query
     * @return null
     */
    public function multi_query($query, $show_error=true, $log_query=true)
    {
        $count = count($this->query_list);
        /** @var null $result */
        // @phpstan-ignore staticMethod.notFound (dleplugin stub omits db::multi_query)
        $result = parent::multi_query($query, $show_error, $log_query);
        if ($log_query && count($this->query_list) > $count) $this->recordSource();
        return $result;
    }

    private function recordSource(): void
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
            if (!isset($frame['file']) || $frame['file'] === __FILE__) continue;

            // PHP supplies line together with file; encoded DLE distributions add eval suffixes above.
            /** @var array{function: string, file: string, line: int} $frame */

            // ENGINE_DIR mixes separators on Windows, so compare normalized paths.
            $file = str_replace('\\', '/', $frame['file']);
            $engine = str_replace('\\', '/', ENGINE_DIR);
            if (strpos($file, $engine . '/classes/mysql.php') === 0) continue;

            $modified = false;
            $cache = $engine . '/cache/system/plugins/';
            if (strpos($file, $cache) === 0) {
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
            if (strpos($file, $root) === 0) $file = substr($file, strlen($root));
            /** @var int $index The caller has just appended a logged query. */
            $index = array_key_last($this->query_list);
            $entry = $this->query_list[$index];
            $entry['source'] = $file . ':' . $frame['line'];
            if ($modified) $entry['source_modified'] = true;
            $this->query_list[$index] = $entry;
            return;
        }
    }
}
