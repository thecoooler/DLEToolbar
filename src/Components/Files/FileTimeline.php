<?php

namespace iCooLER\DLEToolbar\Components\Files;

/** Request-local checkpoints; durations belong to stages, never to individual files. */
final class FileTimeline
{
    private static ?self $current = null;
    private float $startedAt;
    private float $previousAt;
    private bool $finished = false;
    /** @var list<string> */
    private array $known;
    /** @var list<array{label: string, start: float, duration: float, files: list<string>}> */
    private array $stages = [];
    /** @var list<string> */
    private array $before;

    private function __construct()
    {
        $this->known = $this->before = get_included_files();
        $this->startedAt = $this->previousAt = (float) hrtime(true);
    }

    public static function start(): void
    {
        self::$current = new self();
    }

    public static function checkpoint(string $label): void
    {
        $current = self::$current;
        if ($current === null || $current->finished) {
            return;
        }

        $now = (float) hrtime(true);
        $files = get_included_files();
        $current->stages[] = [
            'label' => $label,
            'start' => ($current->previousAt - $current->startedAt) / 1e6,
            'duration' => ($now - $current->previousAt) / 1e6,
            'files' => array_values(array_diff($files, $current->known)),
        ];
        $current->known = $files;
        // Exclude the collector's own work from the next stage's duration.
        $current->previousAt = (float) hrtime(true);
    }

    public static function finish(): void
    {
        self::checkpoint('Сборка main.tpl до вывода панели');
        if (self::$current !== null) {
            self::$current->finished = true;
        }
    }

    /** @return array{before: list<string>, stages: list<array{label: string, start: float, duration: float, files: list<string>}>} */
    public static function snapshot(): array
    {
        return self::$current === null
            ? ['before' => get_included_files(), 'stages' => []]
            : ['before' => self::$current->before, 'stages' => self::$current->stages];
    }

    /** Clears request state when several requests are simulated in one test process. */
    public static function reset(): void
    {
        self::$current = null;
    }
}
