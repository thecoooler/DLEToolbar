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
        ->toContain('class="query-owners"')
        ->toMatch('/SELECT threshold.*?>1 мс</')
        ->toMatch('/query-row-slow[^>]*>.*?SELECT slow/s');
});

it('masks password and session hash values in SQL rows', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_list = [
        ['query' => "UPDATE dle_users SET password='\$2y\$10\$secret', lastdate='1', hash='abc123', logged_ip='::1' WHERE user_id='1'", 'time' => .002, 'num' => 1],
        ['query' => "UPDATE dle_users SET PASSWORD = 'a\\'b' WHERE name='admin'", 'time' => .002, 'num' => 2],
        ['query' => "SELECT * FROM dle_users WHERE user_hash='def456' AND u.password='ghi789'", 'time' => .002, 'num' => 3],
    ];

    $panel = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];
    expect($panel)->toContain('password=&#039;***&#039;, lastdate=&#039;1&#039;, hash=&#039;***&#039;, logged_ip=&#039;::1&#039;')
        ->toContain('PASSWORD = &#039;***&#039; WHERE name=&#039;admin&#039;')
        ->toContain('user_hash=&#039;***&#039; AND u.password=&#039;***&#039;')
        ->not->toContain('def456')
        ->not->toContain('ghi789')
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
        ['query' => 'SELECT fast_b', 'time' => .0002, 'num' => 2, 'source' => 'engine/modules/shop/list.php:90'],
        ['query' => 'SELECT plugin', 'time' => .002, 'num' => 3, 'source' => 'engine/Vendor/Plugin/run.php:34'],
        ['query' => 'SELECT core', 'time' => .002, 'num' => 3, 'source' => 'engine/classes/templates.class.php:56'],
        ['query' => 'SELECT escaped', 'time' => .002, 'num' => 4, 'source' => 'engine/modules/<shop>"/run.php:78'],
        ['query' => 'SELECT legacy', 'time' => .002, 'num' => 5],
        ['query' => 'SELECT empty', 'time' => .002, 'num' => 6, 'source' => ''],
    ];
    $rendered = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]));
    $panel = $rendered['panels'];
    expect($panel)->toContain('<span class="query-owner">shop</span><span class="query-source-file">engine/modules/shop/run.php:12</span>')
        ->toContain('<span class="query-owner">Plugin</span><span class="query-source-file">engine/Vendor/Plugin/run.php:34</span>')
        ->toContain('<span class="query-owner">DLE</span><span class="query-source-file">engine/classes/templates.class.php:56</span>')
        ->toContain('<span class="query-owner">&lt;shop&gt;&quot;</span><span class="query-source-file">engine/modules/&lt;shop&gt;&quot;/run.php:78</span>')
        ->not->toContain('<shop>')
        ->toContain('SELECT legacy')
        ->toContain('SELECT empty')
        ->toMatch('/data-fast-row.*?query-source.*?engine\/modules\/shop/s')
        ->and(substr_count($panel, '<span class="query-source">'))->toBe(7)
        ->and($rendered['scripts'])->toContain("querySelector('.query-text').textContent");
});

it('recognizes every native engine directory without inventing plugin labels', function (): void {
    setupDleToolbarTestEnvironment();
    $directories = ['ajax', 'api', 'cache', 'classes', 'data', 'editor', 'inc', 'skins'];
    foreach ($directories as $directory) {
        $GLOBALS['db']->query_list[] = ['query' => 'SELECT 1', 'time' => .002, 'num' => 1,
            'source' => 'engine/' . $directory . '/nested/run.php:1'];
    }
    foreach (['engine/modules/main.php:1', 'engine/modules/show.full.php:2', 'engine/init.php:3'] as $source) {
        $GLOBALS['db']->query_list[] = ['query' => 'SELECT 2', 'time' => .002, 'num' => 2, 'source' => $source];
    }
    $panel = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];
    expect(substr_count($panel, '<span class="query-owner">DLE</span>'))->toBe(11);
});

it('labels single-file modules and plugins by their file or directory name', function (): void {
    setupDleToolbarTestEnvironment();
    $sources = [
        'engine/modules/mymodule.php:5' => 'mymodule',
        'engine/modules/my.module.php:6' => 'my.module',
        'engine/Vendor/run.php:7' => 'Vendor',
        'engine/Vendor/Plugin/run.php:8' => 'Plugin',
    ];
    foreach (array_keys($sources) as $source) {
        $GLOBALS['db']->query_list[] = ['query' => 'SELECT 1', 'time' => .002, 'num' => 1, 'source' => $source];
    }
    $panel = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];
    foreach ($sources as $source => $label) {
        expect($panel)->toContain('<span class="query-owner">' . $label . '</span><span class="query-source-file">' . $source . '</span>');
    }
});

it('filters SQL rows by owner with per-owner count and total time', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_list = [
        ['query' => 'SELECT before_toolbar', 'time' => .003, 'num' => 1],
        ['query' => 'SELECT core_fast_a', 'time' => .0001, 'num' => 2, 'source' => 'engine/init.php:10'],
        ['query' => 'SELECT core_fast_b', 'time' => .0002, 'num' => 3, 'source' => 'engine/modules/main.php:20'],
        ['query' => 'SELECT shop_fast', 'time' => .0003, 'num' => 4, 'source' => 'engine/modules/shop/run.php:30'],
        ['query' => 'SELECT shop', 'time' => .005, 'num' => 5, 'source' => 'engine/modules/shop/run.php:31'],
        ['query' => 'SELECT plugin', 'time' => .010, 'num' => 6, 'source' => 'engine/Vendor/Plugin/run.php:40'],
        ['query' => 'SELECT core', 'time' => .0027, 'num' => 7, 'source' => 'engine/classes/templates.class.php:50'],
    ];
    $rendered = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]));
    $panel = $rendered['panels'];
    preg_match_all('/<button class="query-owner-chip"[^>]*>.*?<\/button>/s', $panel, $filters);

    expect($filters[0])->toBe([
        '<button class="query-owner-chip" type="button" data-owner-filter="o2" style="--owner: #3f9c8f" aria-pressed="false" title="Показать только запросы: Plugin">'
            . '<span class="query-owner-dot" aria-hidden="true"></span>Plugin <b>1</b><span class="query-owner-time">10 мс</span></button>',
        '<button class="query-owner-chip" type="button" data-owner-filter="o0" aria-pressed="false" title="Показать только запросы: DLE">'
            . '<span class="query-owner-dot" aria-hidden="true"></span>DLE <b>4</b><span class="query-owner-time">6 мс</span></button>',
        '<button class="query-owner-chip" type="button" data-owner-filter="o1" style="--owner: #5b7bb8" aria-pressed="false" title="Показать только запросы: shop">'
            . '<span class="query-owner-dot" aria-hidden="true"></span>shop <b>2</b><span class="query-owner-time">5 мс</span></button>',
    ])
        ->and($panel)->toContain('<div class="query-group" data-query-group>')
        ->toContain('data-owner="o1"')
        ->toContain('style="--owner: #5b7bb8"')
        ->and(substr_count($panel, 'data-fold '))->toBe(1)
        ->and($rendered['scripts'])->toContain("const applyOwnerFilter = () => {");
});

it('escapes owner names in filters and row attributes', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_list = [
        ['query' => 'SELECT 1', 'time' => .002, 'num' => 1, 'source' => 'engine/modules/"><i>/run.php:1'],
    ];
    $panel = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];
    expect($panel)->toContain('title="Показать только запросы: &quot;&gt;&lt;i&gt;"')
        ->toContain('&quot;&gt;&lt;i&gt; <b>1</b>')
        ->toContain('data-owner="o0"')
        ->not->toContain('"><i>');
});
