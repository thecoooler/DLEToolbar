<?php

namespace iCooLER\DLEToolbar\Components\Debug;

/** Request-local checkpoints; available as soon as DLE's plugin autoloader is loaded. */
final class DebugLog
{
    /** @var list<array{message: string, file: string, line: int, time: float}> */
    private static array $entries = [];
    private static ?float $origin = null;
    private static float $offset = 0;

    public static function add(string $message): void
    {
        $time = self::elapsed();
        /** @var array{function: string, file: string, line: int} $caller */
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $file = str_replace('\\', '/', $caller['file']);
        $root = rtrim(str_replace('\\', '/', ROOT_DIR), '/') . '/';
        if (strpos($file, $root) === 0) $file = substr($file, strlen($root));

        self::$entries[] = ['message' => $message, 'file' => $file, 'line' => $caller['line'], 'time' => $time];
    }

    /** Milliseconds since request start, using a monotonic clock for checkpoint intervals. */
    public static function elapsed(): float
    {
        $now = (float) hrtime(true);
        if (self::$origin === null) {
            self::$origin = $now;
            /** @var float $requestStart */
            $requestStart = $_SERVER['REQUEST_TIME_FLOAT'];
            self::$offset = (microtime(true) - $requestStart) * 1000;
        }
        return self::$offset + ($now - self::$origin) / 1000000;
    }

    /** @return list<array{message: string, file: string, line: int, time: float}> */
    public static function entries(): array
    {
        return self::$entries;
    }

    /** Clears collected checkpoints and clock state. */
    public static function reset(): void
    {
        self::$entries = [];
        self::$origin = null;
        self::$offset = 0;
    }
}
