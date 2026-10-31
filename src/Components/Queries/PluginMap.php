<?php

namespace iCooLER\DLEToolbar\Components\Queries;

class PluginMap
{
    /** @var array<string, list<array{plugin: string, code: string}>> */
    private static array $inserts = [];
    /** @var array<string, string> */
    private static array $created = [];
    /** @var array<string, list<array{0: int, 1: int, 2: string}>> */
    private static array $ranges = [];
    private static bool $loaded = false;
    private const CACHE = 'dletoolbar_plugins';
    private const TTL = 600;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        /** @var mixed $cached */
        $cached = get_vars(self::CACHE);
        if (is_array($cached) && ($cached['time'] ?? 0) > time() - self::TTL && is_array($cached['inserts'] ?? null) && is_array($cached['created'] ?? null)) {
            /** @var array<string, list<array{plugin: string, code: string}>> $inserts */
            $inserts = $cached['inserts'];
            /** @var array<string, string> $created */
            $created = $cached['created'];
            self::$inserts = $inserts;
            self::$created = $created;
            return;
        }
        global $db;
        /** @var \db $db */
        $result = $db->query('SELECT p.name, f.file, f.action, IF(f.action = \'create\', \'\', f.replacecode) AS code'
            . ' FROM `' . PREFIX . '_plugins_files` f INNER JOIN `' . PREFIX . '_plugins` p ON p.id = f.plugin_id'
            . ' WHERE f.active = 1 AND p.active = 1', false);
        if ($result === false) {
            return;
        }
        while ($row = $db->get_row($result)) {
            $file = self::key((string) $row['file']);
            $name = trim((string) $row['name']);
            if ($file === '' || $name === '') {
                continue;
            }
            if ($row['action'] === 'create') {
                self::$created[$file] = $name;
                continue;
            }
            $code = trim(str_replace("\r", '', (string) $row['code']));
            if (strlen($code) >= 12) {
                self::$inserts[$file][] = ['plugin' => $name, 'code' => $code];
            }
        }
        $db->free($result);
        set_vars(self::CACHE, ['time' => time(), 'inserts' => self::$inserts, 'created' => self::$created]);
    }

    public static function isCreated(string $file): bool
    {
        return isset(self::$created[self::key($file)]);
    }

    public static function hasData(): bool
    {
        return self::$inserts !== [] || self::$created !== [];
    }

    public static function resolve(string $file, int $line, ?string $built): ?string
    {
        $key = self::key($file);
        if (isset(self::$created[$key])) {
            return self::$created[$key];
        }
        if ($built === null || !isset(self::$inserts[$key])) {
            return null;
        }
        if (!isset(self::$ranges[$built])) {
            self::$ranges[$built] = self::ranges($built, self::$inserts[$key]);
        }
        foreach (self::$ranges[$built] as [$from, $to, $plugin]) {
            if ($line >= $from && $line <= $to) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * @param list<array{plugin: string, code: string}> $inserts
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private static function ranges(string $built, array $inserts): array
    {
        $content = is_file($built) ? (string) file_get_contents($built) : '';
        $content = str_replace("\r", '', $content);
        if ($content === '') {
            return [];
        }
        $ranges = [];
        foreach ($inserts as $insert) {
            $offset = 0;
            $lines = substr_count($insert['code'], "\n");
            while (($position = strpos($content, $insert['code'], $offset)) !== false) {
                $from = substr_count($content, "\n", 0, $position) + 1;
                $ranges[] = [$from, $from + $lines, $insert['plugin']];
                $offset = $position + strlen($insert['code']);
            }
        }
        usort($ranges, static fn(array $a, array $b): int => ($a[1] - $a[0]) <=> ($b[1] - $b[0]));
        return $ranges;
    }

    private static function key(string $file): string
    {
        return strtolower(ltrim(str_replace('\\', '/', trim($file)), '/'));
    }
}
