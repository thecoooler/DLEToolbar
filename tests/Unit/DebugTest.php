<?php

use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\Components\Debug\DebugLog;
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

it('renders escaped checkpoints, cache badges and a timeline only for a visible component', function (): void {
    $start = $_SERVER['REQUEST_TIME_FLOAT'];
    try {
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 1;
        DebugLog::add('<script>alert("log")</script>');
        DebugLog::add('Second checkpoint');
        $registry = new ComponentRegistry();
        $rendered = $registry->render(ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['files']]]));
        expect($rendered['controls'])->toContain('data-component="files"', 'Лог и файлы: 2 отметок')
            ->and($rendered['panels'])->toContain('&lt;script&gt;alert(&quot;log&quot;)&lt;/script&gt;', 'files-slow', 'Самый долгий интервал', 'files-rail-dot', 'не из кэша')
            ->not->toContain('<script>', 'INF', 'NAN', 'DebugTest.php:')
            ->and(substr_count($rendered['panels'], '<li class="files-event'))->toBe(2)
            ->and(substr_count($rendered['panels'], 'Самый долгий интервал'))->toBe(1)
            ->and(DebugLog::entries())->toHaveCount(2)
            ->and($registry->render(ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => []]]))['panels'])->toBe('');
    } finally {
        $_SERVER['REQUEST_TIME_FLOAT'] = $start;
    }
});

it('renders an empty log without fabricated checkpoints and clears collected request state', function (): void {
    $registry = new ComponentRegistry();
    $preferences = ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['files']]]);
    $panel = $registry->render($preferences)['panels'];
    expect($panel)->toContain('Отметок нет.')->not->toContain('files-track', 'Самый долгий интервал');
    DebugLog::add('Test');
    DebugLog::reset();
    expect(DebugLog::entries())->toBe([])
        ->and($registry->render($preferences)['controls'])->toContain('Лог и файлы: 0 отметок');
});

it('keeps early marks and records milestones with file deltas', function (): void {
    DebugLog::add('Before access check');
    $early = DebugLog::entries()[0];
    DebugLog::start();
    DebugLog::start();
    DebugLog::add('Inside engine.php');
    DebugLog::checkpoint('engine/engine.php');
    $snapshot = DebugLog::snapshot();
    expect($snapshot['entries'])->toHaveCount(4)
        ->and($snapshot['entries'][0])->toBe($early)
        ->and(array_column($snapshot['entries'], 'kind'))->toBe(['log', 'stage', 'log', 'stage'])
        ->and($snapshot['entries'][3]['message'])->toBe('engine/engine.php');
    DebugLog::finish();
    $frozen = DebugLog::snapshot();
    DebugLog::add('Too late');
    DebugLog::start();
    DebugLog::finish();
    expect(DebugLog::snapshot())->toBe($frozen);
});

it('normalizes the slow interval threshold and applies it to the shared timeline', function (): void {
    DebugLog::add('A');
    $registry = new ComponentRegistry();
    $config = ['toolbar' => ['enabled' => ['files']], 'components' => ['files' => ['slow_threshold_ms' => 0.1]]];
    $fast = $registry->render(ToolbarPreferences::fromConfig($config));
    $config['components']['files']['slow_threshold_ms'] = 1000000000;
    $slow = $registry->render(ToolbarPreferences::fromConfig($config));
    expect($fast['panels'])->toContain('files-slow')
        ->and($slow['panels'])->not->toContain('class="files-event files-slow"')
        ->and(ToolbarPreferences::fromConfig(['components' => ['files' => ['slow_threshold_ms' => -1]]])->componentParameters('files')['slow_threshold_ms'])->toBe(50.0);
});

it('measures paired inclusions across manual marks and nested calls to the same file', function (): void {
    $file = ROOT_DIR . '/engine/engine.php';
    DebugLog::before($file);
    DebugLog::after($file);
    expect(DebugLog::entries())->toBe([]);
    DebugLog::start();
    DebugLog::after($file);
    DebugLog::before($file);
    DebugLog::add('Inside');
    DebugLog::before($file);
    DebugLog::after($file);
    DebugLog::after($file);
    $entries = DebugLog::entries();
    expect(array_column($entries, 'kind'))->toBe(['stage', 'before', 'log', 'before', 'after', 'after'])
        ->and($entries[4]['duration'])->toBe($entries[4]['time'] - $entries[3]['time'])
        ->and($entries[5]['duration'])->toBe($entries[5]['time'] - $entries[1]['time'])
        ->and($entries[5]['duration'])->toBeGreaterThanOrEqual($entries[4]['duration']);
    DebugLog::finish();
    $snapshot = DebugLog::snapshot();
    DebugLog::before($file);
    DebugLog::after($file);
    expect(DebugLog::snapshot())->toBe($snapshot);
});
