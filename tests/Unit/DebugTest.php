<?php

use iCooLER\DLEToolbar\Components\Debug\DebugLog;
use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    DebugLog::reset();
});

afterEach(function (): void {
    DebugLog::reset();
});

it('captures the actual caller without retaining arguments and preserves checkpoint order', function (): void {
    $line = __LINE__ + 1;
    DebugLog::add('Before rendering');
    DebugLog::add('After rendering');
    $entries = DebugLog::entries();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]['file'])->toEndWith('tests/Unit/DebugTest.php')
        ->and($entries[0]['line'])->toBe($line)
        ->and($entries[0]['message'])->toBe('Before rendering')
        ->and($entries[1]['message'])->toBe('After rendering')
        ->and($entries[1]['time'])->toBeGreaterThanOrEqual($entries[0]['time'])
        ->and(DebugLog::elapsed())->toBeGreaterThanOrEqual($entries[1]['time']);
});

it('renders escaped checkpoints, request edges and a timeline only for a visible component', function (): void {
    $start = $_SERVER['REQUEST_TIME_FLOAT'];
    try {
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 1;
        DebugLog::add('<script>alert("log")</script>');
        DebugLog::add('Second checkpoint');
        $registry = new ComponentRegistry();
        $rendered = $registry->render(ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['debug']]]));
        expect($rendered['controls'])->toContain('data-component="debug"', 'Отладочный лог: 2')
            ->and($rendered['panels'])->toContain('&lt;script&gt;alert(&quot;log&quot;)&lt;/script&gt;', 'Вывод тулбара', 'Конец измерения', 'debug-slow', 'Самый долгий интервал', 'debug-track', 'DebugTest.php:')
            ->not->toContain('<script>', 'INF', 'NAN')
            ->and(substr_count($rendered['panels'], '<li class="debug-row'))->toBe(3)
            ->and(substr_count($rendered['panels'], 'Самый долгий интервал'))->toBe(1)
            ->and(DebugLog::entries())->toHaveCount(2)
            ->and($registry->render(ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => []]]))['panels'])->toBe('');
    } finally {
        $_SERVER['REQUEST_TIME_FLOAT'] = $start;
    }
});

it('renders an empty log without fabricated checkpoints and clears collected request state', function (): void {
    $registry = new ComponentRegistry();
    $preferences = ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['debug']]]);
    $panel = $registry->render($preferences)['panels'];
    expect($panel)->toContain('Записей нет.')->not->toContain('debug-track', 'Самый долгий интервал');
    DebugLog::add('Test');
    DebugLog::reset();
    expect(DebugLog::entries())->toBe([])
        ->and($registry->render($preferences)['controls'])->toContain('Отладочный лог: 0');
});
