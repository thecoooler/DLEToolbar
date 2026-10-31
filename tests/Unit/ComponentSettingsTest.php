<?php

use iCooLER\DLEToolbar\AdminUI\ToolbarSettingsPage;
use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;
use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;

use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
});

it('supplies component parameter defaults for existing configuration files', function (): void {
    $preferences = ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['queries', 'editpost']]]);
    expect($preferences->componentParameters('editpost'))->toBe(['target' => '_blank'])
        ->and($preferences->componentParameters('clock'))->toBe(['show_seconds' => false])
        ->and($preferences->componentParameters('queries'))->toBe(['slow_threshold_ms' => 20.0, 'fold_fast' => true, 'trace_sources' => false]);

    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    expect((new ComponentRegistry())->render($preferences)['controls'])->toContain('target="_blank" rel="noopener"');
});

it('normalizes component parameters and rejects unregistered fields', function (): void {
    $preferences = ToolbarPreferences::fromRequest(['components' => [
        'editpost' => ['target' => '_blank', 'extra' => 'ignored'],
        'queries' => ['slow_threshold_ms' => '50.5', 'fold_fast' => '0'],
        'clock' => ['show_seconds' => '0'],
        'unknown' => ['extra' => 'ignored'],
    ]]);
    expect($preferences->componentParameters('editpost'))->toBe(['target' => '_blank'])
        ->and($preferences->componentParameters('queries'))->toBe(['slow_threshold_ms' => 50.5, 'fold_fast' => false, 'trace_sources' => false])
        ->and($preferences->componentParameters('clock'))->toBe(['show_seconds' => false])
        ->and($preferences->toConfig()['components'])->not->toHaveKey('unknown');

    $updated = ToolbarPreferences::fromRequest(['components' => ['queries' => ['fold_fast' => '1']]], $preferences);
    expect($updated->componentParameters('editpost'))->toBe(['target' => '_blank'])
        ->and($updated->componentParameters('clock'))->toBe(['show_seconds' => false])
        ->and($updated->componentParameters('queries'))->toBe(['slow_threshold_ms' => 50.5, 'fold_fast' => true, 'trace_sources' => false]);

    $padded = ToolbarPreferences::fromRequest(['components' => ['queries' => ['slow_threshold_ms' => ' 25 ']]]);
    expect($padded->componentParameters('queries')['slow_threshold_ms'])->toBe(25.0);
});

it('uses defaults for invalid component parameters', function (mixed $threshold, mixed $fold, mixed $target): void {
    $preferences = ToolbarPreferences::fromRequest(['components' => [
        'queries' => ['slow_threshold_ms' => $threshold, 'fold_fast' => $fold],
        'editpost' => ['target' => $target],
    ]]);
    expect($preferences->componentParameters('queries'))->toBe(['slow_threshold_ms' => 20.0, 'fold_fast' => true, 'trace_sources' => false])
        ->and($preferences->componentParameters('editpost'))->toBe(['target' => '_blank']);
})->with([
    'negative number and unknown choices' => [-1, 'yes', 'window'],
    'zero and arrays' => [0, [], ['_blank']],
    'non-numeric strings' => ['invalid', 'false', '" onclick="alert(1)'],
    'non-finite number' => [INF, 42, 42],
    'overflow' => ['1e999', null, null],
]);

it('renders each declared parameter type with the stored values', function (): void {
    $preferences = ToolbarPreferences::fromConfig(['components' => [
        'editpost' => ['target' => '_blank'],
        'queries' => ['slow_threshold_ms' => 50.5, 'fold_fast' => false],
        'clock' => ['show_seconds' => false],
    ]]);
    $html = (new ToolbarSettingsPage($preferences, '?mod=dletoolbar'))->render();
    expect($html)->toContain('name="components[editpost][target]"')
        ->toContain('<option value="_blank" selected>В новой вкладке</option>')
        ->toContain('name="components[queries][slow_threshold_ms]" value="50.5" min="0.1"')
        ->toContain('type="hidden" name="components[queries][fold_fast]" value="0"')
        ->toContain('name="components[queries][fold_fast]" value="1" aria-label="Сворачивать быстрые запросы">')
        ->toContain('name="components[queries][trace_sources]" value="1" aria-label="Показывать источник запросов (экспериментально)">')
        ->toContain('data-target="#dlt-settings-clock"')
        ->toContain('type="hidden" name="components[clock][show_seconds]" value="0"')
        ->toContain('name="components[clock][show_seconds]" value="1" aria-label="Показывать секунды">')
        ->toContain('Порог долгого запроса, мс');

    $parameter = Parameter::select('<label>', 'safe', ['safe' => '<option>']);
    expect($parameter->renderControl('field"name', 'id"value', 'safe'))
        ->toContain('name="field&quot;name"')
        ->toContain('id="id&quot;value"')
        ->toContain('&lt;option&gt;');
});

it('uses the configured tab for news editing', function (string $target): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    $registry = new ComponentRegistry();
    $preferences = ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['editpost']],
        'components' => ['editpost' => ['target' => $target]],
    ]);
    $html = $registry->render($preferences)['controls'];
    expect($html)->toContain('target="' . $target . '"')
        ->toContain('id=42');
    if ($target === '_blank') {
        expect($html)->toContain('rel="noopener"');
    } else {
        expect($html)->not->toContain('rel="noopener"');
    }
})->with(['_self', '_blank']);

it('uses the slow query threshold at its exact boundary and can disable fast folding', function (): void {
    $GLOBALS['db']->query_list = [
        ['query' => 'SELECT fast_a', 'time' => .0002, 'num' => 1],
        ['query' => 'SELECT fast_b', 'time' => .0003, 'num' => 2],
        ['query' => 'SELECT below', 'time' => .049999, 'num' => 3],
        ['query' => 'SELECT boundary', 'time' => .050, 'num' => 4],
    ];
    $registry = new ComponentRegistry();
    $preferences = ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
        'components' => ['queries' => ['slow_threshold_ms' => 50.0, 'fold_fast' => false]],
    ]);
    $html = $registry->render($preferences)['panels'];
    expect($html)->not->toContain('data-fold')
        ->not->toContain('data-fast-row hidden')
        ->toContain('query-stats-alert">⚠ 1')
        ->toMatch('/query-row-plain[^>]*>[^\n]*SELECT below/')
        ->toMatch('/query-row-slow[^>]*>[^\n]*SELECT boundary/')
        ->toContain('query-time">&lt;1 мс');

    $preferences = ToolbarPreferences::fromRequest([
        'toolbar' => $preferences->toConfig()['toolbar'],
        'components' => ['queries' => ['fold_fast' => '1']],
    ], $preferences);
    expect($registry->render($preferences)['panels'])->toContain('data-fold');
});

it('keeps slow queries visible when the threshold is below one millisecond', function (): void {
    $GLOBALS['db']->query_list = [
        ['query' => 'SELECT a', 'time' => .0002, 'num' => 1],
        ['query' => 'SELECT b', 'time' => .0003, 'num' => 2],
        ['query' => 'SELECT c', 'time' => .0005, 'num' => 3],
        ['query' => 'SELECT d', 'time' => .0006, 'num' => 4],
    ];
    $preferences = ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
        'components' => ['queries' => ['slow_threshold_ms' => 0.5, 'fold_fast' => true]],
    ]);
    $html = (new ComponentRegistry())->render($preferences)['panels'];
    expect(substr_count($html, 'data-fast-row hidden'))->toBe(2)
        ->and($html)->toContain('query-stats-alert">⚠ 2')
        ->toMatch('/query-row-slow[^>]*>[^\n]*SELECT c/')
        ->toMatch('/query-row-slow[^>]*>[^\n]*SELECT d/');
});

it('persists component parameters and applies them when assembling the toolbar', function (): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    $GLOBALS['db']->query_list = [
        ['query' => 'SELECT a', 'time' => .001, 'num' => 1],
        ['query' => 'SELECT b', 'time' => .002, 'num' => 2],
    ];
    ToolbarPreferences::fromRequest([
        'toolbar' => ['enabled' => ['queries', 'editpost', 'clock']],
        'components' => [
            'editpost' => ['target' => '_blank'],
            'queries' => ['slow_threshold_ms' => '2', 'fold_fast' => '0'],
            'clock' => ['show_seconds' => '0'],
        ],
    ])->save();
    ToolbarPreferences::resetRequestCache();
    $preferences = ToolbarPreferences::load();
    expect($preferences->componentParameters('editpost'))->toBe(['target' => '_blank'])
        ->and($preferences->componentParameters('clock'))->toBe(['show_seconds' => false])
        ->and($preferences->componentParameters('queries'))->toBe(['slow_threshold_ms' => 2.0, 'fold_fast' => false, 'trace_sources' => false]);
    $html = (new DLEToolbar())->render();
    expect($html)->toContain('target="_blank" rel="noopener"')
        ->toContain('data-clock data-show-seconds="false"')
        ->toContain('query-stats-alert">⚠ 1');
});
