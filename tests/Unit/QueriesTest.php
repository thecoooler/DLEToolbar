<?php

use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

it('keeps the DLE counter distinct from filtered SQL rows and respects time thresholds', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_num = 9;
    $GLOBALS['db']->query_list = [
        ['query' => 'Connection with MySQL Server', 'time' => .100, 'num' => 0],
        ['query' => 'SELECT fast', 'time' => .000999, 'num' => 1],
        ['query' => 'SELECT threshold', 'time' => .001, 'num' => 2],
        ['query' => 'SELECT below_slow', 'time' => .019999, 'num' => 3],
        ['query' => 'SELECT slow', 'time' => .020, 'num' => 4],
    ];

    $rendered = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]));
    expect($rendered['controls'])->toContain('Количество запросов MySQL: 9')
        ->and($rendered['panels'])->toContain('<b>4 запроса</b> · 42 мс')
        ->toContain('query-stats-alert">⚠ 1')
        ->not->toContain('Connection with MySQL Server')
        ->not->toContain('data-fold')
        ->toMatch('/SELECT threshold<\/span><\/span>\s*<span class="query-time">1 мс<\/span>/')
        ->toMatch('/query-row-slow[^>]*>.*?SELECT slow/s');
});

it('masks password and session hash values in SQL rows', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_list = [
        ['query' => "UPDATE dle_users SET password='\$2y\$10\$secret', lastdate='1', hash='abc123', logged_ip='::1' WHERE user_id='1'", 'time' => .002, 'num' => 1],
        ['query' => "UPDATE dle_users SET PASSWORD = 'a\\'b' WHERE name='admin'", 'time' => .002, 'num' => 2],
    ];

    $panel = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];
    expect($panel)->toContain('password=&#039;***&#039;, lastdate=&#039;1&#039;, hash=&#039;***&#039;, logged_ip=&#039;::1&#039;')
        ->toContain('PASSWORD=&#039;***&#039; WHERE name=&#039;admin&#039;')
        ->not->toContain('secret')
        ->not->toContain('abc123');
});

it('explains that DLE 13.x keeps no SQL list instead of reporting an empty page', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_num = 7;
    $GLOBALS['db']->query_list = [];
    $render = fn (): string => (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];

    foreach (['13.0', '13.3'] as $version) {
        $GLOBALS['config']['version_id'] = $version;
        expect($render())->toContain('Список SQL-запросов не показывается в DLE 13.0–13.3: эти версии его не сохраняют. Всего запросов: 7.')
            ->not->toContain('SQL-запросов на этой странице нет.')
            ->not->toContain('class="query-stats"');
    }

    $GLOBALS['config']['version_id'] = '14.0';
    expect($render())->toContain('SQL-запросов на этой странице нет.')
        ->not->toContain('DLE 13.0–13.3');
});

it('renders escaped call sites and path labels in ordinary and folded SQL rows', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_list = [
        ['query' => 'SELECT fast_a', 'time' => .0001, 'num' => 1, 'source' => 'engine/modules/shop/run.php:12'],
        ['query' => 'SELECT fast_b', 'time' => .0002, 'num' => 2, 'source' => 'engine/Vendor/Plugin/run.php:34', 'source_modified' => true],
        ['query' => 'SELECT core', 'time' => .002, 'num' => 3, 'source' => 'engine/classes/templates.class.php:56'],
        ['query' => 'SELECT escaped', 'time' => .002, 'num' => 4, 'source' => 'engine/modules/<shop>"/run.php:78'],
        ['query' => 'SELECT legacy', 'time' => .002, 'num' => 5],
        ['query' => 'SELECT empty', 'time' => .002, 'num' => 6, 'source' => ''],
    ];
    $rendered = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]));
    $panel = $rendered['panels'];
    expect($panel)->toContain('engine/modules/shop/run.php:12 · <span class="query-source-label">shop</span>')
        ->toContain('engine/Vendor/Plugin/run.php:34 · <span class="query-source-label">Plugin</span> · изменён плагинами')
        ->toContain('engine/classes/templates.class.php:56 · <span class="query-source-label">DLE</span>')
        ->toContain('engine/modules/&lt;shop&gt;&quot;/run.php:78 · <span class="query-source-label">&lt;shop&gt;&quot;</span>')
        ->not->toContain('<shop>')
        ->toContain('<span class="query-text">SELECT legacy</span></span>')
        ->toContain('<span class="query-text">SELECT empty</span></span>')
        ->toMatch('/data-fast-row hidden>.*?query-source.*?engine\/modules\/shop/s')
        ->and(substr_count($panel, '<span class="query-source">'))->toBe(4)
        ->and($rendered['scripts'])->toContain("querySelector('.query-text').textContent");
});

it('recognizes every native engine directory without inventing plugin labels', function (): void {
    setupDleToolbarTestEnvironment();
    $directories = ['ajax', 'api', 'cache', 'classes', 'data', 'editor', 'inc', 'skins'];
    foreach ($directories as $directory) {
        $GLOBALS['db']->query_list[] = ['query' => 'SELECT 1', 'time' => .002, 'num' => 1,
            'source' => 'engine/' . $directory . '/nested/run.php:1'];
    }
    $GLOBALS['db']->query_list[] = ['query' => 'SELECT 2', 'time' => .002, 'num' => 2,
        'source' => 'engine/modules/main.php:1'];
    $panel = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];
    expect(substr_count($panel, '<span class="query-source-label">DLE</span>'))->toBe(9);
});
