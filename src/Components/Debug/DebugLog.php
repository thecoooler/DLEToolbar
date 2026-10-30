<?php

namespace iCooLER\DLEToolbar\Components\Debug;

use iCooLER\DLEToolbar\ComponentFramework\SourcePath;

/** One request-local journal for manual marks, DLE stages and included-file snapshots. */
final class DebugLog
{
    private static bool $active = false;
    private static bool $finished = false;
    private static ?float $origin = null;
    private static float $offset = 0;
    private static float $startedAt = 0;
    private static float $previousAt = 0;
    private static ?float $end = null;
    /** @var list<string> */
    private static array $before = [];
    /** @var list<string> */
    private static array $known = [];
    /** @var list<array{label: string, start: float, duration: float, files: list<string>}> */
    private static array $stages = [];
    /** @var list<array{message: string, file: string, line: int, time: float, kind: string}> */
    private static array $entries = [];

    /** May be called as soon as DLE's plugin autoloader is loaded, including before toolbar initialization. */
    public static function add(string $message): void
    {
        if (self::$finished) {
            return;
        }
        /** @var array{function: string, file: string, line: int} $caller */
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        self::append($message, $caller['file'], $caller['line'], 'log', self::elapsed());
    }

    /** Starts automatic measurements at a fixed point after all toolbar components have initialized. */
    public static function start(): void
    {
        if (self::$active || self::$finished) {
            return;
        }
        self::$known = self::$before = get_included_files();
        self::$startedAt = self::$previousAt = (float) hrtime(true);
        self::$active = true;
        self::append('Начало замеров этапов DLE', '', 0, 'start', self::timeAt(self::$startedAt));
    }

    public static function checkpoint(string $label): void
    {
        if (!self::$active || self::$finished) {
            return;
        }
        /** @var array{function: string, file: string, line: int} $caller */
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        self::closeStage($label, $caller['file'], $caller['line'], 'stage');
    }

    private static function closeStage(string $label, string $file, int $line, string $kind): void
    {
        $now = (float) hrtime(true);
        $files = get_included_files();
        self::$stages[] = [
            'label' => $label,
            'start' => (self::$previousAt - self::$startedAt) / 1e6,
            'duration' => ($now - self::$previousAt) / 1e6,
            'files' => array_values(array_diff($files, self::$known)),
        ];
        self::$known = $files;
        self::append($label, $file, $line, $kind, self::timeAt($now));
        // Manual marks do not change this boundary; engine.php keeps its full inclusion duration.
        self::$previousAt = (float) hrtime(true);
    }

    private static function append(string $message, string $file, int $line, string $kind, float $time): void
    {
        $file = SourcePath::display($file);
        self::$entries[] = ['message' => $message, 'file' => $file, 'line' => $line, 'time' => $time, 'kind' => $kind];
    }

    /** Milliseconds since request start; intervals use a monotonic clock. */
    public static function elapsed(): float
    {
        return self::timeAt((float) hrtime(true));
    }

    private static function timeAt(float $now): float
    {
        if (self::$origin === null) {
            self::$origin = $now;
            /** @var float $requestStart */
            $requestStart = $_SERVER['REQUEST_TIME_FLOAT'];
            self::$offset = (microtime(true) - $requestStart) * 1000;
        }
        return self::$offset + ($now - self::$origin) / 1e6;
    }

    public static function finish(): void
    {
        if (self::$finished) {
            return;
        }
        if (self::$active) {
            self::closeStage('Сборка main.tpl до вывода панели', '', 0, 'end');
        }
        self::$end = self::elapsed();
        self::$finished = true;
    }

    /** @return list<array{message: string, file: string, line: int, time: float, kind: string}> */
    public static function entries(): array
    {
        return self::$entries;
    }

    /** @return array{before: list<string>, stages: list<array{label: string, start: float, duration: float, files: list<string>}>, entries: list<array{message: string, file: string, line: int, time: float, kind: string}>, end: float} */
    public static function snapshot(): array
    {
        return [
            'before' => self::$active ? self::$before : get_included_files(),
            'stages' => self::$stages,
            'entries' => self::$entries,
            'end' => self::$end ?? self::elapsed(),
        ];
    }

    /** Clears state when several requests are simulated in one test process. */
    public static function reset(): void
    {
        self::$active = self::$finished = false;
        self::$origin = self::$end = null;
        self::$offset = self::$startedAt = self::$previousAt = 0;
        self::$before = self::$known = self::$stages = self::$entries = [];
    }
}
