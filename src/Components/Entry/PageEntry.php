<?php

namespace iCooLER\DLEToolbar\Components\Entry;

/** Rows that DLE loads for the viewed news or profile; the toolbar reads them at output time. */
final class PageEntry
{
    /** @var array<string, scalar|array<array-key, string>|null>|null */
    private static ?array $news = null;

    /** @var array<string, scalar|null>|null */
    private static ?array $user = null;

    /** @param array<string, scalar|array<array-key, string>|null> $row The news row of show.full.php after the full story is compiled; tags may be split. */
    public static function captureNews(array $row): void
    {
        self::$news = $row;
    }

    /** @param array<string, scalar|null> $row The user row of profile.php. */
    public static function captureUser(array $row): void
    {
        self::$user = $row;
    }

    /** @return array<string, scalar|array<array-key, string>|null>|null */
    public static function news(): ?array
    {
        return self::$news;
    }

    /** @return array<string, scalar|null>|null */
    public static function user(): ?array
    {
        return self::$user;
    }

    /** Clears the current request's rows for tests. */
    public static function reset(): void
    {
        self::$news = self::$user = null;
    }
}
