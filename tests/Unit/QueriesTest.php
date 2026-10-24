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
        ->not->toContain('class="query-filters"')
        ->not->toContain(' query-tinted"')
        ->not->toContain('style="--query-hue')
        ->toMatch('/SELECT threshold<\/span><\/span>\s*<span class="query-time">1 мс<\/span>/')
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
        ->toContain('PASSWORD=&#039;***&#039; WHERE name=&#039;admin&#039;')
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
    expect($panel)->toContain('<span class="query-source-label">shop</span> · engine/modules/shop/run.php:12')
        ->toContain('<span class="query-source-label">Plugin</span> · engine/Vendor/Plugin/run.php:34')
        ->toContain('<span class="query-source-label">DLE</span> · engine/classes/templates.class.php:56')
        ->toContain('<span class="query-source-label">&lt;shop&gt;&quot;</span> · engine/modules/&lt;shop&gt;&quot;/run.php:78')
        ->not->toContain('<shop>')
        ->toContain('<span class="query-text">SELECT legacy</span></span>')
        ->toContain('<span class="query-text">SELECT empty</span></span>')
        ->toMatch('/data-fast-row hidden>.*?query-source.*?engine\/modules\/shop/s')
        ->and(substr_count($panel, '<span class="query-source">'))->toBe(5)
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
    expect(substr_count($panel, '<span class="query-source-label">DLE</span>'))->toBe(11);
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
        expect($panel)->toContain('<span class="query-source-label">' . $label . '</span> · ' . $source);
    }
});

it('filters SQL rows by initiator with per-initiator count and total time', function (): void {
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
    preg_match_all('/<button class="query-filter[" ][^>]*>.*?<\/button>/s', $panel, $filters);

    expect($filters[0])->toBe([
        '<button class="query-filter" type="button" data-query-filter-all aria-pressed="true" title="7 запросов, 21 мс">'
            . '<span class="query-filter-name">Все</span><span class="query-filter-stats">7 · 21 мс</span></button>',
        '<button class="query-filter query-tinted" type="button" data-query-filter="DLE" style="--query-hue: 255" aria-pressed="false" title="3 запроса, 3 мс">'
            . '<span class="query-filter-name">DLE</span><span class="query-filter-stats">3 · 3 мс</span></button>',
        '<button class="query-filter query-tinted" type="button" data-query-filter="Plugin" style="--query-hue: 145" aria-pressed="false" title="1 запрос, 10 мс">'
            . '<span class="query-filter-name">Plugin</span><span class="query-filter-stats">1 · 10 мс</span></button>',
        '<button class="query-filter query-tinted" type="button" data-query-filter="shop" style="--query-hue: 310" aria-pressed="false" title="2 запроса, 5 мс">'
            . '<span class="query-filter-name">shop</span><span class="query-filter-stats">2 · 5 мс</span></button>',
        '<button class="query-filter" type="button" data-query-filter="" aria-pressed="false" title="1 запрос, 3 мс">'
            . '<span class="query-filter-name">Без источника</span><span class="query-filter-stats">1 · 3 мс</span></button>',
    ])
        ->and($panel)->toContain('<div class="query-group query-tinted" data-query-group data-initiator="DLE" style="--query-hue: 255">')
        ->toContain('№2–3 · <span data-fold-verb></span>2 быстрых · DLE</span>')
        ->toMatch('/<div class="query-row query-row-plain query-tinted" data-initiator="shop" style="--query-hue: 310">.*?SELECT shop_fast/s')
        ->toMatch('/<div class="query-row query-row-plain query-tinted" data-initiator="Plugin" style="--query-hue: 145">.*?SELECT plugin/s')
        ->toMatch('/<div class="query-row query-row-plain" data-initiator="">.*?SELECT before_toolbar/s')
        ->and(substr_count($panel, 'data-fold '))->toBe(1)
        ->and($rendered['scripts'])->toContain("querySelectorAll('.query-list > [data-initiator]')");
});

it('gives every initiator its own hue away from other hues and the slow-query red', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_list = [['query' => 'SELECT core', 'time' => .002, 'num' => 1, 'source' => 'engine/init.php:1']];
    for ($index = 1; $index <= 14; $index++) {
        $GLOBALS['db']->query_list[] = ['query' => 'SELECT ' . $index, 'time' => .002 + $index / 1000, 'num' => $index + 1,
            'source' => 'engine/modules/module' . $index . '/run.php:1'];
    }
    $panel = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];
    preg_match_all('/data-query-filter="([^"]*)" style="--query-hue: (\d+)"/', $panel, $filters);
    $hues = array_map('intval', $filters[2]);
    $distances = [];
    foreach ($hues as $index => $hue) {
        foreach (array_slice($hues, $index + 1) as $other) $distances[] = min(abs($hue - $other), 360 - abs($hue - $other));
    }

    expect($filters[1])->toHaveCount(15)
        ->and(array_slice($filters[1], 0, 3))->toBe(['DLE', 'module14', 'module13'])
        ->and($hues)->toBe([255, 145, 310, 75, 195, 345, 110, 225, 282, 170, 92, 127, 327, 210, 240])
        ->and(min($distances))->toBe(15)
        ->and($panel)->toContain('<div class="query-row query-row-plain query-tinted" data-initiator="module14" style="--query-hue: 145">');
});

it('tints slow rows and numeric initiator names without losing the slow marking', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_list = [
        ['query' => 'SELECT core', 'time' => .002, 'num' => 1, 'source' => 'engine/init.php:1'],
        ['query' => 'SELECT numeric', 'time' => .030, 'num' => 2, 'source' => 'engine/modules/123/run.php:1'],
    ];
    $panel = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];
    expect($panel)->toContain('<button class="query-filter query-tinted" type="button" data-query-filter="123" style="--query-hue: 145"')
        ->toMatch('/<div class="query-row query-row-slow query-tinted" data-initiator="123" style="--query-hue: 145">.*?SELECT numeric/s');
});

it('keeps assigning distinct hues outside the slow red for more than 15 initiators', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_list = [['query' => 'SELECT core', 'time' => .002, 'num' => 1, 'source' => 'engine/init.php:1']];
    for ($index = 1; $index <= 24; $index++) {
        $GLOBALS['db']->query_list[] = [
            'query' => 'SELECT ' . $index, 'time' => $index * .001, 'num' => $index + 1,
            'source' => 'engine/modules/module' . $index . '/run.php:1',
        ];
    }
    $panel = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];
    preg_match_all('/data-query-filter="([^"]*)" style="--query-hue: (\d+)"/', $panel, $filters);
    $hues = array_map('intval', $filters[2]);

    expect($filters[1])->toHaveCount(25)
        ->and(array_unique($hues))->toHaveCount(25)
        ->and(array_filter($hues, static fn (int $hue): bool => $hue < 75 || $hue > 345))->toBe([]);
});

it('escapes initiator names in filters and row attributes', function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query_list = [
        ['query' => 'SELECT 1', 'time' => .002, 'num' => 1, 'source' => 'engine/modules/"><i>/run.php:1'],
    ];
    $panel = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'];
    expect($panel)->toContain('data-query-filter="&quot;&gt;&lt;i&gt;"')
        ->toContain('data-initiator="&quot;&gt;&lt;i&gt;"')
        ->not->toContain('"><i>');
});
