<?php

use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
});

it('applies declared defaults and fresh preferences through the registry render interface', function (): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    $GLOBALS['db']->query_list = [
        ['query' => 'SELECT fast_a', 'time' => .0002, 'num' => 1],
        ['query' => 'SELECT fast_b', 'time' => .0003, 'num' => 2],
        ['query' => 'SELECT boundary', 'time' => .020, 'num' => 3],
    ];
    $registry = new ComponentRegistry();
    $defaults = ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['queries', 'editpost']]]);
    $rendered = $registry->render($defaults);
    $defaultRender = $rendered;
    expect($rendered['controls'])->toContain('target="_blank" rel="noopener"')
        ->and($rendered['panels'])->toContain('data-fold')
        ->toContain('query-stats-alert">⚠ 1');

    $custom = ToolbarPreferences::fromRequest(['toolbar' => $defaults->toConfig()['toolbar'], 'components' => [
        'editpost' => ['target' => '_self'],
        'queries' => ['slow_threshold_ms' => '50', 'fold_fast' => '0'],
    ]], $defaults);
    $rendered = $registry->render($custom);
    expect($rendered['controls'])->toContain('target="_self"')->not->toContain('rel="noopener"')
        ->and($rendered['panels'])->not->toContain('data-fold')->not->toContain('query-stats-alert">⚠');

    expect($registry->render($defaults))->toBe($defaultRender)
        ->and($registry->render($defaults)['panels'])->toContain('data-fold');
    $GLOBALS['news_found'] = false;
    expect($registry->render(ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['editpost']]])))
        ->toBe(['controls' => '', 'hints' => '', 'styles' => '', 'panels' => '', 'scripts' => '']);
});

it('initializes cache behavior only when the control is visible', function (bool $visible): void {
    $registry = new ComponentRegistry();
    $preferences = ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => $visible ? ['cache'] : []]]);
    $previousCookie = $_COOKIE['dle_toolbar_clear_cache'] ?? null;
    $GLOBALS['config']['allow_cache'] = true;
    $GLOBALS['config']['cache_type'] = 0;

    try {
        $_COOKIE['dle_toolbar_clear_cache'] = 'test-user-hash';
        $registry->initialize($preferences);
        expect($GLOBALS['config']['allow_cache'])->toBe(!$visible);
    } finally {
        if ($previousCookie === null) unset($_COOKIE['dle_toolbar_clear_cache']);
        else $_COOKIE['dle_toolbar_clear_cache'] = $previousCookie;
    }
})->with(['visible' => true, 'hidden' => false]);
