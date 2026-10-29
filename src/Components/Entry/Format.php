<?php

namespace iCooLER\DLEToolbar\Components\Entry;

/** HTML for entry values. DLE stores text with HTML entities, so values are decoded before escaping. */
final class Format
{
    /** Longer values and multiline values are folded into a spoiler. */
    private const LONG_VALUE = 160;

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function empty(): string
    {
        return '<span class="entry-empty">—</span>';
    }

    /** @param scalar|null $value */
    public static function text($value): string
    {
        $value = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '') {
            return self::empty();
        }
        if (strlen($value) <= self::LONG_VALUE && strpos($value, "\n") === false) {
            return self::escape($value);
        }

        $escaped = self::escape($value);
        return '<details class="entry-spoiler"><summary><span class="entry-preview">' . $escaped . '</span></summary>'
            . '<div class="entry-long">' . $escaped . '</div></details>';
    }

    /** @param scalar|null $value */
    public static function code($value): string
    {
        $value = (string) $value;
        return $value === '' ? self::empty() : '<code>' . self::escape($value) . '</code>';
    }

    /** @param scalar|null $value Unix timestamp; zero means the event has not happened. */
    public static function date($value): string
    {
        return (int) $value > 0 ? self::escape(langdate('d.m.Y H:i', (int) $value)) : self::empty();
    }

    /** @param scalar|null $value */
    public static function flag($value): string
    {
        return (int) $value ? '<span class="entry-flag" data-on="true">Да</span>' : '<span class="entry-flag">Нет</span>';
    }

    /** @param scalar|null $value */
    public static function number($value): string
    {
        return self::escape(number_format((int) $value, 0, '.', ' '));
    }

    public static function link(string $url, string $text): string
    {
        return '<a href="' . self::escape($url) . '">' . self::escape($text) . '</a>';
    }

    public static function profileUrl(string $name): string
    {
        global $config;
        /** @var array{http_home_url: string, allow_alt_url: bool|int|string} $config */
        return $config['allow_alt_url']
            ? $config['http_home_url'] . 'user/' . urlencode($name) . '/'
            : $config['http_home_url'] . 'index.php?subaction=userinfo&user=' . urlencode($name);
    }

    public static function adminUrl(string $query): string
    {
        global $config;
        /** @var array{http_home_url: string, admin_path: string} $config */
        return $config['http_home_url'] . $config['admin_path'] . '?' . $query;
    }

    /** True when the current group may open the given admin section. */
    public static function allows(string $permission): bool
    {
        global $user_group, $member_id;
        /** @var array{user_group: int|string} $member_id */
        /** @var array<int, array<string, scalar|null>> $user_group */
        $group = $user_group[$member_id['user_group']];
        return !empty($group['allow_admin']) && !empty($group[$permission]);
    }

    /**
     * Rows for the columns that the installed DLE version has.
     * @param array<string, scalar|null> $row
     * @param list<array{0: string, 1: string, 2: callable(scalar|null): string}> $columns label, column, formatter
     * @return list<array{0: string, 1: string}>
     */
    public static function rows(array $row, array $columns): array
    {
        $rows = [];
        foreach ($columns as [$label, $column, $format]) {
            if (array_key_exists($column, $row)) {
                $rows[] = [self::escape($label), $format($row[$column])];
            }
        }
        return $rows;
    }

    /**
     * Every column as stored, for template work.
     * @param array<string, scalar|null> $row
     * @param list<string> $skip
     * @return list<array{0: string, 1: string}>
     */
    public static function raw(array $row, array $skip): array
    {
        $rows = [];
        foreach ($row as $column => $value) {
            if (in_array($column, $skip, true)) {
                continue;
            }
            $rows[] = ['<code>' . self::escape((string) $column) . '</code>', self::text($value)];
        }
        return $rows;
    }
}
