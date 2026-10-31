<?php

namespace iCooLER\DLEToolbar\Components\Debug;

use iCooLER\DLEToolbar\ComponentFramework\SourcePath;

/** One request-local journal for manual marks, DLE milestones and included-file snapshots. */
final class DebugLog
{
    private static bool $active = false;
    private static bool $finished = false;
    private static ?float $origin = null;
    private static float $offset = 0;
    private static float $previousAt = 0;
    private static ?float $end = null;
    /** @var array<string, list<int>> */
    private static array $running = [];
    /** @var list<string> */
    private static array $known = [];
    /** @var list<array{message: string, file: string, line: int, cached: bool, time: float, gap: float, kind: string, duration: float|null, files: list<string>}> */
    private static array $entries = [];

    /** May be called as soon as DLE's plugin autoloader is loaded, including before toolbar initialization. */
    public static function add(string $message): void
    {
        if (self::$finished) {
            return;
        }
        /** @var array{function?: string, file?: string, line?: int} $caller */
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $file = $caller['file'] ?? '';
        $line = $caller['line'] ?? 0;
        self::append($message, $file, $line, 'log');
    }

    /** Starts automatic measurements after toolbar preferences have initialized. */
    public static function start(): void
    {
        if (self::$active || self::$finished) {
            return;
        }
        self::$active = true;
        self::$previousAt = (float) hrtime(true);
        self::append('Начало замеров', '', 0, 'stage');
    }

    public static function checkpoint(string $label): void
    {
        if (!self::$active || self::$finished) {
            return;
        }
        /** @var array{function?: string, file?: string, line?: int} $caller */
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        $file = $caller['file'] ?? '';
        $line = $caller['line'] ?? 0;
        self::append($label, $file, $line, 'stage');
    }

    /** Records the boundary inside an include expression, preserving its condition and variable scope. */
    public static function includePath(string $file, bool $early = false): string
    {
        self::before($file, $early);
        return $file;
    }

    public static function before(string $file, bool $early = false): void
    {
        if ((!self::$active && !$early) || self::$finished) {
            return;
        }
        self::append(SourcePath::display($file), $file, 0, 'before');
        self::$running[SourcePath::display($file)][] = count(self::$entries) - 1;
    }

    public static function after(string $file, bool $early = false, string $label = ''): void
    {
        $key = SourcePath::display($file);
        if ((!self::$active && !$early) || self::$finished || empty(self::$running[$key])) {
            return;
        }
        $before = array_pop(self::$running[$key]);
        self::append($label !== '' ? $label : $key, $file, 0, 'after');
        $index = count(self::$entries) - 1;
        $entry = self::$entries[$index];
        $entry['duration'] = $entry['time'] - self::$entries[$before]['time'];
        $entry['cached'] = self::$entries[$before]['cached'];
        self::$entries[$index] = $entry;
    }

    private static function append(string $message, string $file, int $line, string $kind): void
    {
        $now = (float) hrtime(true);
        $time = self::timeAt($now);
        $gap = self::$entries === []
            ? $time
            : ($now - self::$previousAt) / 1e6;
        self::$previousAt = $now;

        $currentFiles = get_included_files();
        $newFiles = array_values(array_diff($currentFiles, self::$known));
        self::$known = $currentFiles;

        $source = SourcePath::resolve($file);

        self::$entries[] = [
            'message' => $message,
            'file' => $source['path'],
            'line' => $line,
            'cached' => $source['cached'],
            'time' => $time,
            'gap' => $gap,
            'kind' => $kind,
            'duration' => null,
            'files' => $newFiles,
        ];
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
            /** @var float|int|string|null $requestStart */
            $requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
            $startFloat = is_numeric($requestStart) ? (float) $requestStart : microtime(true);
            self::$offset = (microtime(true) - $startFloat) * 1000;
        }
        return self::$offset + ($now - self::$origin) / 1e6;
    }

    public static function finish(): void
    {
        if (self::$finished) {
            return;
        }
        if (self::$active) {
            self::append('Вывод панели', '', 0, 'stage');
        }
        self::$end = self::elapsed();
        self::$finished = true;
    }

    /** @return list<array{message: string, file: string, line: int, cached: bool, time: float, gap: float, kind: string, duration: float|null, files: list<string>}> */
    public static function entries(): array
    {
        return self::$entries;
    }

    /** @return array{entries: list<array{message: string, file: string, line: int, cached: bool, time: float, gap: float, kind: string, duration: float|null, files: list<string>}>, totalFiles: int, end: float} */
    public static function snapshot(): array
    {
        return [
            'entries' => self::$entries,
            'totalFiles' => count(self::$known !== [] ? self::$known : get_included_files()),
            'end' => self::$end ?? self::elapsed(),
        ];
    }

    /** Clears state when several requests are simulated in one test process. */
    public static function reset(): void
    {
        self::$active = self::$finished = false;
        self::$origin = self::$end = null;
        self::$offset = self::$previousAt = 0;
        self::$known = self::$entries = self::$running = [];
    }
}
