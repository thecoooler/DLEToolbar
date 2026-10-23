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
        ->toMatch('/SELECT threshold<\/span><\/span>\s*<span class="query-time">1 мс<\/span>/')
        ->toMatch('/query-row-slow[^>]*>.*?SELECT slow/s');
});

/** Renders the given SQL as panel rows and returns the panel text with HTML entities decoded. */
function maskedQueriesPanel(array $queries): string
{
    setupDleToolbarTestEnvironment();
    foreach ($queries as $number => $query) {
        $GLOBALS['db']->query_list[] = ['query' => $query, 'time' => .002, 'num' => $number];
    }
    return html_entity_decode((new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
    ]))['panels'], ENT_QUOTES, 'UTF-8');
}

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

it('masks secret positions of INSERT value tuples', function (): void {
    $panel = maskedQueriesPanel([
        "INSERT INTO dle_users (name, password, email, reg_date, lastdate, user_group, info, signature, favorites, xfields, hash, logged_ip)"
            . " VALUES ('admin', '\$2y\$10\$regpass', 'a@example.com', '1700000000', '1700000000', '4', '', '', '', '', 'reghash', '::1')",
        "REPLACE INTO dle_tokens (`id`, `user_id`, `token`) values (1, 2, 'tok1'), (3, MD5('x,y'), \"tok2\")"
            . " ON DUPLICATE KEY UPDATE `token` = VALUES(`token`), hash = 'duphash'",
        "INSERT INTO dle_logs (name, value) VALUES ('password', 'visible')",
    ]);
    expect($panel)->toContain("VALUES ('admin', '***', 'a@example.com', '1700000000', '1700000000', '4', '', '', '', '', '***', '::1')")
        ->toContain("values (1, 2, '***'), (3, MD5('x,y'), \"***\") ON DUPLICATE KEY UPDATE `token` = VALUES(`token`), hash = '***'")
        ->toContain("VALUES ('password', 'visible')")
        ->not->toContain('regpass')
        ->not->toContain('reghash')
        ->not->toContain('tok1')
        ->not->toContain('tok2')
        ->not->toContain('duphash');
});

it('masks secrets in comparisons, lists, patterns and function arguments', function (): void {
    $panel = maskedQueriesPanel([
        "SELECT user_id FROM dle_users WHERE hash IN ('zzh1', \"zzh2\") AND password NOT IN ('zzp1') AND `password` LIKE '%zzp2%'",
        "SELECT 1 FROM dle_users WHERE password NOT LIKE 'zzp3' AND hash != 'zzh3' AND hash <> 'zzh4' AND hash <=> 'zzh5'",
        "UPDATE dle_users SET password = MD5(CONCAT('zzp4', 'zzp5')), hash=SHA1('zzh6') WHERE name = 'admin'",
        "SELECT 1 FROM dle_users WHERE password = BINARY 'zzp6' AND pin = -42 AND pin = 0x2a AND hash = _utf8mb4'zzh7' AND name='it''s'",
    ]);
    expect($panel)->toContain("hash IN ('***', \"***\") AND password NOT IN ('***') AND `password` LIKE '***'")
        ->toContain("password NOT LIKE '***' AND hash != '***' AND hash <> '***' AND hash <=> '***'")
        ->toContain("password = MD5(CONCAT('***', '***')), hash=SHA1('***') WHERE name = 'admin'")
        ->toContain("password = BINARY '***' AND pin = -'***' AND pin = '***' AND hash = _utf8mb4'***' AND name='it''s'")
        ->not->toMatch('/zz[ph]\d/')
        ->not->toContain('42');
});

it('masks two-factor codes, password recovery ids and API keys', function (): void {
    $panel = maskedQueriesPanel([
        "INSERT INTO dle_twofactor (user_id, pin, attempt, date) VALUES ('1', '123456', '0', '1700000000')",
        "SELECT * FROM dle_twofactor WHERE user_id='1' AND pin=654321",
        "INSERT INTO dle_lostdb (lostname, lostid) values ('1', 'zz1')",
        "DELETE FROM dle_lostdb WHERE lostid = \"zz2\"",
        "UPDATE dle_users SET twofactor_secret='zz1', otp_code='zz2' WHERE user_id='1'",
        "UPDATE dle_api SET api_key='zz1', apikey='zz2', access_token=\"zz3\", client_secret = 'zz4', salt='zz5', auth_key='zz6', oauth_token='zz7', user_pass='zz8'",
    ]);
    expect($panel)->toContain("VALUES ('1', '***', '0', '1700000000')")
        ->toContain("user_id='1' AND pin='***'")
        ->toContain("values ('1', '***')")
        ->toContain('lostid = "***"')
        ->toContain("twofactor_secret='***', otp_code='***'")
        ->toContain("api_key='***', apikey='***', access_token=\"***\", client_secret = '***', salt='***', auth_key='***', oauth_token='***', user_pass='***'")
        ->not->toContain('123456')
        ->not->toContain('654321')
        ->not->toMatch('/zz\d/');
});

it('keeps values of columns that only resemble secret names', function (): void {
    $queries = [
        "SELECT * FROM dle_post WHERE author='admin' AND ping='pong' AND pinned=1 AND keywords='kw' AND hashtags='tag'",
        "INSERT INTO dle_toolbar_settings (setting_key, setting_value) VALUES ('queries', '{\"a\":1}')",
        "SELECT name, password, hash FROM dle_users WHERE user_id = 7 ORDER BY hash",
        "SELECT * FROM dle_post WHERE title = 'password = \\'x\\'' AND passport = 'pp'",
    ];
    $panel = maskedQueriesPanel($queries);
    foreach ($queries as $query) {
        expect($panel)->toContain($query);
    }
});

it('masks queries with long literals such as news texts', function (): void {
    $story = str_repeat('Текст с \\\'кавычками\\\' и \\\\ обратной чертой. ', 40000);
    $panel = maskedQueriesPanel(["INSERT INTO dle_post (title, full_story, hash) VALUES ('t', '$story', 'zzlong')"]);
    expect($panel)->toContain("VALUES ('t', '$story', '***')")
        ->not->toContain('zzlong');
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
    preg_match_all('/<button class="query-filter"[^>]*>.*?<\/button>/s', $panel, $filters);

    expect($filters[0])->toBe([
        '<button class="query-filter" type="button" data-query-filter-all aria-pressed="true" title="7 запросов, 21 мс">'
            . '<span class="query-filter-name">Все</span><span class="query-filter-stats">7 · 21 мс</span></button>',
        '<button class="query-filter" type="button" data-query-filter="DLE" aria-pressed="false" title="3 запроса, 3 мс">'
            . '<span class="query-filter-name">DLE</span><span class="query-filter-stats">3 · 3 мс</span></button>',
        '<button class="query-filter" type="button" data-query-filter="Plugin" aria-pressed="false" title="1 запрос, 10 мс">'
            . '<span class="query-filter-name">Plugin</span><span class="query-filter-stats">1 · 10 мс</span></button>',
        '<button class="query-filter" type="button" data-query-filter="shop" aria-pressed="false" title="2 запроса, 5 мс">'
            . '<span class="query-filter-name">shop</span><span class="query-filter-stats">2 · 5 мс</span></button>',
        '<button class="query-filter" type="button" data-query-filter="" aria-pressed="false" title="1 запрос, 3 мс">'
            . '<span class="query-filter-name">Без источника</span><span class="query-filter-stats">1 · 3 мс</span></button>',
    ])
        ->and($panel)->toContain('<div class="query-group" data-query-group data-initiator="DLE">')
        ->toContain('№2–3 · <span data-fold-verb></span>2 быстрых · DLE</span>')
        ->toMatch('/<div class="query-row query-row-plain" data-initiator="shop">.*?SELECT shop_fast/s')
        ->toMatch('/<div class="query-row query-row-plain" data-initiator="">.*?SELECT before_toolbar/s')
        ->and(substr_count($panel, 'data-fold '))->toBe(1)
        ->and($rendered['scripts'])->toContain("querySelectorAll('.query-list > [data-initiator]')");
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
