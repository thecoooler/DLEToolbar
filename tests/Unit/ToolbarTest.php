<?php

use iCooLER\DLEToolbar\AdminUI\ToolbarSettingsPage;
use iCooLER\DLEToolbar\Components\Cache\CacheCleaner;
use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
});

it('normalizes component order and rejects unknown or duplicate IDs', function (): void {
    $preferences = ToolbarPreferences::fromRequest(['toolbar' => [
        'items' => ['queries', 'unknown', 'queries', ['time'], 'time'],
        'enabled' => ['time', 'queries', 'unknown', ['response']],
    ]]);

    expect($preferences->itemOrder())->toBe(['queries', 'time', 'response', 'skin', 'cache', 'editpost'])
        ->and($preferences->visibleItems())->toBe(['queries', 'time']);
});

it('distinguishes default visibility from explicitly disabling all components', function (): void {
    expect(ToolbarPreferences::fromConfig([])->visibleItems())->toBe(['response', 'time', 'queries', 'skin', 'cache', 'editpost'])
        ->and(ToolbarPreferences::fromRequest(['toolbar' => ['items' => ['response', 'time', 'queries']]])->visibleItems())->toBe([]);
});

it('defaults to administrators and normalizes multiple allowed groups', function (): void {
    expect(ToolbarPreferences::fromConfig([])->allowedGroups())->toBe([1])
        ->and(ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['time']]])->allowedGroups())->toBe([1])
        ->and(ToolbarPreferences::fromRequest(['access' => [
            'groups' => ['1', '2', 2, '3', 0, -1, 'invalid', '2.5', ['4'], true],
        ]])->allowedGroups())->toBe([1, 2, 3])
        ->and(ToolbarPreferences::fromRequest([])->allowedGroups())->toBe([])
        ->and(ToolbarPreferences::fromRequest(['access' => ['groups' => '1,2']])->allowedGroups())->toBe([]);
});

it('excludes users, guests and missing groups from toolbar access', function (): void {
    expect(ToolbarPreferences::fromRequest(['access' => [
        'groups' => ['1', '4', '5', 5, '7', '99999999999999999999'],
    ]])->allowedGroups())->toBe([1])
        ->and(ToolbarPreferences::fromConfig(['access' => ['groups' => [1, 4, 5]]])->allowedGroups())->toBe([1])
        ->and(array_keys(ToolbarPreferences::groups()))->toBe([1, 2, 3]);

    $html = (new ToolbarSettingsPage(ToolbarPreferences::fromConfig([]), '?mod=dletoolbar'))->render();
    expect($html)->toContain('<option value="3">Журналисты</option>')
        ->not->toContain('<option value="4"')
        ->not->toContain('<option value="5"');
});

it('persists selected groups and restricts toolbar and cache access to them', function (): void {
    $previousMember = $GLOBALS['member_id'];
    $previousSiteConfig = $GLOBALS['config'];
    $previousCookie = $_COOKIE['dle_toolbar_clear_cache'] ?? null;

    try {
        ToolbarPreferences::fromRequest([
            'access' => ['groups' => ['2', '3']],
            'toolbar' => ['enabled' => ['cache']],
        ])->save();
        expect(ToolbarPreferences::load()->allowedGroups())->toBe([2, 3]);
        $toolbar = new DLEToolbar();
        foreach ([1 => false, 2 => true, 3 => true, 4 => false, 5 => false] as $group => $allowed) {
            $GLOBALS['member_id'] = ['user_group' => (string) $group];
            expect($toolbar->isHasAccess())->toBe($allowed);
        }

        $GLOBALS['config']['allow_cache'] = true;
        $GLOBALS['config']['cache_type'] = 0;
        $_COOKIE['dle_toolbar_clear_cache'] = 'test-user-hash';
        $GLOBALS['member_id'] = ['user_group' => 1];
        $toolbar->init();
        expect($GLOBALS['config']['allow_cache'])->toBeTrue();
        $GLOBALS['member_id'] = ['user_group' => 2];
        $toolbar->init();
        expect($GLOBALS['config']['allow_cache'])->toBeFalse();

        ToolbarPreferences::fromRequest(['access' => ['groups' => []]])->save();
        expect(ToolbarPreferences::load()->allowedGroups())->toBe([])
            ->and($toolbar->isHasAccess())->toBeFalse();
        $GLOBALS['member_id'] = ['user_group' => 1];
        expect($toolbar->isHasAccess())->toBeFalse();
    } finally {
        $GLOBALS['member_id'] = $previousMember;
        $GLOBALS['config'] = $previousSiteConfig;
        if ($previousCookie === null) unset($_COOKIE['dle_toolbar_clear_cache']);
        else $_COOKIE['dle_toolbar_clear_cache'] = $previousCookie;
    }
});

it('preserves additional config parameters without accepting arbitrary form fields', function (): void {
    $current = ToolbarPreferences::fromConfig([
        'access' => ['groups' => [1], 'future_access_option' => true],
        'toolbar' => ['future_toolbar_option' => 'bottom'],
        'future_section' => ['option' => 42],
    ]);
    $updated = ToolbarPreferences::fromRequest([
        'access' => ['groups' => ['2'], 'future_access_option' => false],
        'toolbar' => ['enabled' => ['time'], 'future_toolbar_option' => 'top'],
        'future_section' => ['option' => 0],
    ], $current);
    $values = $updated->toConfig();
    expect($updated->allowedGroups())->toBe([2])
        ->and($updated->visibleItems())->toBe(['time'])
        ->and($values['access']['future_access_option'])->toBeTrue()
        ->and($values['toolbar']['future_toolbar_option'])->toBe('bottom')
        ->and($values['future_section'])->toBe(['option' => 42]);
});

it('loads defaults when no preferences have been saved and ignores malformed request values', function (): void {
    expect(ToolbarPreferences::load()->visibleItems())
        ->toBe(['response', 'time', 'queries', 'skin', 'cache', 'editpost'])
        ->and(ToolbarPreferences::fromConfig(['toolbar' => ['items' => 'time', 'enabled' => null]])->itemOrder())
        ->toBe(['response', 'time', 'queries', 'skin', 'cache', 'editpost'])
        ->and(ToolbarPreferences::fromRequest(['toolbar' => 'invalid'])->visibleItems())
        ->toBe([]);
});

it('persists visibility and order and renders current DLE metrics', function (): void {
    $globals = [];
    foreach (['Timer', 'db', 'config'] as $key) {
        $globals[$key] = $GLOBALS[$key] ?? null;
    }
    $previousStatus = http_response_code();

    try {
        ToolbarPreferences::fromRequest(['toolbar' => [
            'items' => ['queries', 'response', 'time'],
            'enabled' => ['queries', 'response', 'time'],
        ]])->save();

        $GLOBALS['Timer'] = new class {
            public function get(): float { return .125; }
        };
        $GLOBALS['db'] = new \iCooLER\DLEToolbar\ToolbarTestDatabase($GLOBALS['dletoolbar_native_db'], [
            'query_num' => 17,
            'query_list' => [['query' => 'Connection with MySQL Server', 'time' => 0.001, 'num' => 0]],
            'MySQL_time_taken' => 0.024,
        ]);
        http_response_code(404);

        expect(ToolbarPreferences::load()->visibleItems())->toBe(['queries', 'response', 'time']);
        $html = (new DLEToolbar())->render();
        preg_match_all('/data-component="([^"]+)"/', $html, $matches);
        expect($matches[1])->toBe(['response', 'queries', 'time'])
            ->and($html)->toContain('Количество запросов MySQL: 17')
            ->toContain('HTTP-код ответа: 404')
            ->toContain('data-state="error"')
            ->toContain('Время генерации страницы: 125 мс, быстро');

        ToolbarPreferences::fromRequest(['toolbar' => ['items' => ['queries', 'response', 'time']]])->save();
        expect(ToolbarPreferences::load()->visibleItems())->toBe([])
            ->and((new DLEToolbar())->render())->toBe('');
    } finally {
        foreach ($globals as $key => $value) {
            $GLOBALS[$key] = $value;
        }
        http_response_code(is_int($previousStatus) ? $previousStatus : 200);
    }
});

it('renders saved settings with escaped form values and visibility switches', function (): void {
    $preferences = ToolbarPreferences::fromRequest(['toolbar' => [
        'items' => ['queries', 'response', 'time'],
        'enabled' => ['queries'],
    ], 'access' => ['groups' => ['2', '3']]]);
    $previousHash = $GLOBALS['dle_login_hash'];
    $GLOBALS['dle_login_hash'] = '<token>';

    try {
        $html = (new ToolbarSettingsPage($preferences, '?mod=dletoolbar&view="settings"', 'Сохранено <успешно>'))->render();
        preg_match('/<tbody id="dlt-component-list">(.*?)<\/tbody>/s', $html, $list);
        preg_match_all('/name="toolbar\[items\]\[\]" value="([^"]+)"/', $list[1], $matches);

        expect($matches[1])->toBe(['queries', 'response', 'time', 'skin', 'cache', 'editpost'])
            ->and($html)->toContain('action="?mod=dletoolbar&amp;view=&quot;settings&quot;"')
            ->toContain('name="user_hash" value="&lt;token&gt;"')
            ->toContain('Сохранено &lt;успешно&gt;')
            ->toContain('name="access[groups][]" class="dlt-groupselect" multiple')
            ->toContain('<option value="2" selected>Главные редакторы</option>')
            ->toContain('<option value="3" selected>Журналисты</option>')
            ->toContain('<option value="1">Администраторы</option>')
            ->toContain('class="switch" name="toolbar[enabled][]" value="queries" aria-label="Показывать: Количество запросов MySQL" checked')
            ->not->toContain('Кнопка перехода в админку');
    } finally {
        $GLOBALS['dle_login_hash'] = $previousHash;
    }
});

it('renders settings without a success notice', function (): void {
    $html = (new ToolbarSettingsPage(ToolbarPreferences::fromConfig([]), '?mod=dletoolbar'))->render();

    expect($html)->toContain('id="dlt-settings-form"')
        ->toContain('class="nav nav-tabs nav-tabs-solid"')
        ->toContain('href="#dlt-access-tab" data-toggle="tab"')
        ->toContain('href="#dlt-components-tab" data-toggle="tab"')
        ->toContain('class="tab-pane" id="dlt-access-tab"')
        ->toContain('class="tab-pane active" id="dlt-components-tab"')
        ->toContain('<option value="1" selected>Администраторы</option>')
        ->and($html)->not->toContain('role="status"');
});

it('renders installed templates with the current selection and keeps skin visibility configurable', function (): void {
    $previousSiteConfig = $GLOBALS['config'] ?? null;
    $previousSkins = $GLOBALS['dletoolbar_test_skins'] ?? null;
    $previousSkinCookie = $_COOKIE['dle_skin'] ?? null;

    try {
        unset($_COOKIE['dle_skin']);
        $GLOBALS['config']['skin'] = 'Default';
        $GLOBALS['dletoolbar_test_skins'] = [
            'Default' => ['name' => 'Стандартный <шаблон>'],
            'Studio' => ['name' => 'Studio & Co'],
        ];
        ToolbarPreferences::fromRequest(['toolbar' => [
            'items' => ['skin', 'response', 'time', 'queries'],
            'enabled' => ['skin'],
        ]])->save();

        $html = (new DLEToolbar())->render();
        preg_match_all('/data-component="([^"]+)"/', $html, $matches);
        expect($matches[1])->toBe(['skin'])
            ->and($html)->toContain('data-skin-value="Default" data-skin-default="true" type="button" role="menuitemradio" aria-checked="true"><span>Стандартный &lt;шаблон&gt;<span class="skin-option-meta">Default · По умолчанию</span></span>')
            ->toContain('data-skin-value="Studio" data-skin-default="false" type="button" role="menuitemradio" aria-checked="false"><span>Studio &amp; Co<span class="skin-option-meta">Studio</span></span>')
            ->toContain('data-skin-trigger')
            ->toContain('role="menu" aria-label="Выбрать шаблон" hidden')
            ->toContain('data-overridden="false"')
            ->not->toContain('class="skin-indicator"')
            ->not->toContain('title="Шаблон:');

        $_COOKIE['dle_skin'] = 'Studio';
        expect((new DLEToolbar())->render())->toContain('data-overridden="true"')
            ->toContain('class="skin-indicator"')
            ->toContain('aria-label="Выбрать шаблон (выбран нестандартный)"');

        $toolbar = new DLEToolbar();
        $GLOBALS['config']['skin'] = 'Studio';
        expect($toolbar->render())->toContain('data-skin-value="Default" data-skin-default="true"')
            ->toContain('data-skin-value="Studio" data-skin-default="false" type="button" role="menuitemradio" aria-checked="true"');

        $GLOBALS['config']['skin'] = 'Default';
        foreach (['default', 'Default', ''] as $cookie) {
            $_COOKIE['dle_skin'] = $cookie;
            expect((new DLEToolbar())->render())->not->toContain('class="skin-indicator"');
        }

        $GLOBALS['config']['skin'] = 'Studio';
        $toolbar = new DLEToolbar();
        $GLOBALS['config']['skin'] = 'Default';
        $_COOKIE['dle_skin'] = 'Default';
        expect($toolbar->render())->toContain('data-skin-value="Studio" data-skin-default="true"')
            ->toContain('class="skin-indicator"');
        $_COOKIE['dle_skin'] = 'Studio';
        expect($toolbar->render())->not->toContain('class="skin-indicator"');

        ToolbarPreferences::fromRequest(['toolbar' => [
            'items' => ['skin', 'response', 'time', 'queries'],
            'enabled' => ['time'],
        ]])->save();
        expect((new DLEToolbar())->render())->not->toContain('data-component="skin"')
            ->not->toContain('data-queries-open')
            ->not->toContain('data-query-panel')
            ->not->toContain('.query-panel {');
    } finally {
        $GLOBALS['config'] = $previousSiteConfig;
        $GLOBALS['dletoolbar_test_skins'] = $previousSkins;
        if ($previousSkinCookie === null) unset($_COOKIE['dle_skin']);
        else $_COOKIE['dle_skin'] = $previousSkinCookie;
    }
});

it('consumes a temporary cookie and redirects so DLE applies the persisted skin', function (): void {
    $previousCookies = $_COOKIE;
    $previousServer = $_SERVER;
    try {
        $GLOBALS['config']['skin'] = 'Air';
        $GLOBALS['dletoolbar_test_skins'] = ['Air' => ['name' => 'Air'], 'Chronicle' => ['name' => 'Chronicle']];
        $toolbar = new DLEToolbar();
        $_COOKIE['dle_skin'] = 'Air';
        $_COOKIE['dle_toolbar_skin'] = json_encode(['skin' => 'Chronicle', 'token' => $GLOBALS['dle_login_hash']]);
        $_SERVER['REQUEST_URI'] = '/news/?page=2';
        expect(fn () => $toolbar->init())->toThrow(RuntimeException::class, 'skin redirect');
        expect($GLOBALS['dletoolbar_skin_redirect'])->toBe(['Location: /news/?page=2', 303]);
        expect($_COOKIE['dle_skin'])->toBe('Chronicle')
            ->and(isset($_COOKIE['dle_toolbar_skin']))->toBeFalse()
            ->and($GLOBALS['config']['skin'])->toBe('Air');
        // On the redirected request DLE reads the newly persisted cookie.
        $GLOBALS['config']['skin'] = $_COOKIE['dle_skin'];
        expect($toolbar->render())->toContain('data-overridden="true"')
            ->toContain('role="menuitemradio" aria-checked="true"><span>Chronicle');

        $GLOBALS['config']['skin'] = 'Air';
        $toolbar = new DLEToolbar();
        $_COOKIE['dle_toolbar_skin'] = json_encode(['skin' => 'Air', 'token' => $GLOBALS['dle_login_hash']]);
        expect(fn () => $toolbar->init())->toThrow(RuntimeException::class, 'skin redirect');
        expect(isset($_COOKIE['dle_skin']))->toBeFalse()
            ->and(isset($_COOKIE['dle_toolbar_skin']))->toBeFalse()
            ->and($toolbar->render())->toContain('data-overridden="false"')
            ->not->toContain('class="skin-indicator"');
    } finally {
        $_COOKIE = $previousCookies;
        $_SERVER = $previousServer;
        unset($GLOBALS['dletoolbar_skin_redirect']);
    }
});

it('rejects invalid skin cookies, requires toolbar access and ignores hidden skin control', function (): void {
    $previousCookies = $_COOKIE;
    try {
        foreach ([
            'broken json',
            ['skin' => 'Air'],
            json_encode(['skin' => 'Air', 'token' => 'wrong']),
            json_encode(['skin' => ['Air'], 'token' => $GLOBALS['dle_login_hash']]),
            json_encode(['skin' => '../Air', 'token' => $GLOBALS['dle_login_hash']]),
            json_encode(['skin' => 'missing-template', 'token' => $GLOBALS['dle_login_hash']]),
        ] as $cookie) {
            $_COOKIE['dle_skin'] = 'Chronicle';
            $_COOKIE['dle_toolbar_skin'] = $cookie;
            (new DLEToolbar())->init();
            expect($_COOKIE['dle_skin'])->toBe('Chronicle')
                ->and(isset($_COOKIE['dle_toolbar_skin']))->toBeFalse();
        }
        $GLOBALS['member_id']['user_group'] = 5;
        $_COOKIE['dle_toolbar_skin'] = json_encode(['skin' => 'Air', 'token' => $GLOBALS['dle_login_hash']]);
        (new DLEToolbar())->init();
        expect($_COOKIE['dle_skin'])->toBe('Chronicle')
            ->and(isset($_COOKIE['dle_toolbar_skin']))->toBeTrue();

        $GLOBALS['member_id']['user_group'] = 1;
        ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['time']]])->save();
        $_COOKIE['dle_toolbar_skin'] = json_encode(['skin' => 'Air', 'token' => $GLOBALS['dle_login_hash']]);
        (new DLEToolbar())->init();
        expect($_COOKIE['dle_skin'])->toBe('Chronicle')
            ->and(isset($_COOKIE['dle_toolbar_skin']))->toBeTrue();
    } finally {
        $_COOKIE = $previousCookies;
    }
});

it('selects templates with numeric folder names', function (): void {
    $previousSiteConfig = $GLOBALS['config'] ?? null;
    $previousSkins = $GLOBALS['dletoolbar_test_skins'] ?? null;
    $previousSkinCookie = $_COOKIE['dle_skin'] ?? null;

    try {
        unset($_COOKIE['dle_skin']);
        $GLOBALS['config']['skin'] = '2024';
        $GLOBALS['dletoolbar_test_skins'] = ['2024' => ['name' => 'Год'], 'Default' => ['name' => 'Default']];
        ToolbarPreferences::fromRequest(['toolbar' => ['items' => ['skin'], 'enabled' => ['skin']]])->save();
        $toolbar = new DLEToolbar();

        expect($toolbar->render())->toContain('data-skin-value="2024" data-skin-default="true" type="button" role="menuitemradio" aria-checked="true"><span>Год<span class="skin-option-meta">2024 · По умолчанию</span>')
            ->toContain('data-skin-value="Default" data-skin-default="false" type="button" role="menuitemradio" aria-checked="false"><span>Default<span class="skin-option-meta">Default</span></span>');

        $GLOBALS['dletoolbar_test_skins'] = ['2024' => ['name' => 'Default'], 'Default' => ['name' => 'Default']];
        expect($toolbar->render())->toContain('<span>Default<span class="skin-option-meta">2024 · По умолчанию</span>')
            ->toContain('data-skin-value="Default" data-skin-default="false" type="button" role="menuitemradio" aria-checked="false"><span>Default<span class="skin-option-meta">Default</span>');

        $GLOBALS['config']['skin'] = 'Default';
        $_COOKIE['dle_skin'] = 'Default';
        expect($toolbar->render())->toContain('data-skin-value="2024" data-skin-default="true" type="button" role="menuitemradio" aria-checked="false"')
            ->toContain('data-skin-value="Default" data-skin-default="false" type="button" role="menuitemradio" aria-checked="true"')
            ->toContain('class="skin-indicator"');
    } finally {
        $GLOBALS['config'] = $previousSiteConfig;
        $GLOBALS['dletoolbar_test_skins'] = $previousSkins;
        if ($previousSkinCookie === null) unset($_COOKIE['dle_skin']);
        else $_COOKIE['dle_skin'] = $previousSkinCookie;
    }
});

it('lists template folders by directory name before DLE 14.3', function (): void {
    $previousSiteConfig = $GLOBALS['config'] ?? null;
    $previousSkins = $GLOBALS['dletoolbar_test_skins'] ?? null;

    try {
        $GLOBALS['config']['version_id'] = '14.2';
        $GLOBALS['dletoolbar_test_skins'] = ['Native' => ['name' => 'get_folder_list() result']];
        ToolbarPreferences::fromRequest(['toolbar' => ['items' => ['skin'], 'enabled' => ['skin']]])->save();
        $folders = array_values(array_filter(
            scandir(ROOT_DIR . '/templates'),
            static fn (string $name): bool => $name !== '.' && $name !== '..' && is_dir(ROOT_DIR . '/templates/' . $name),
        ));

        $html = (new DLEToolbar())->render();
        preg_match_all('/data-skin-value="([^"]+)"[^>]*><span>([^<]+)/', $html, $matches);
        expect($folders)->not->toBeEmpty()
            ->and($matches[1])->toBe($folders)
            ->and($matches[2])->toBe($folders);

        $GLOBALS['config']['version_id'] = '14.3';
        expect((new DLEToolbar())->render())->toContain('data-skin-value="Native"');
    } finally {
        $GLOBALS['config'] = $previousSiteConfig;
        $GLOBALS['dletoolbar_test_skins'] = $previousSkins;
    }
});

it('offers and grants the toolbar only to groups allowed into the admin panel', function (): void {
    expect(array_keys(ToolbarPreferences::groups()))->not->toContain(6)
        ->and(ToolbarPreferences::fromRequest(['access' => ['groups' => ['1', '6']]])->allowedGroups())->toBe([1]);

    $html = (new ToolbarSettingsPage(ToolbarPreferences::fromConfig([]), '?mod=dletoolbar'))->render();
    expect($html)->not->toContain('<option value="6"');

    ToolbarPreferences::fromRequest(['access' => ['groups' => ['3']]])->save();
    $GLOBALS['member_id'] = ['user_group' => 3];
    $toolbar = new DLEToolbar();
    expect($toolbar->isHasAccess())->toBeTrue();

    $GLOBALS['user_group'][3]['allow_admin'] = 0;
    expect($toolbar->isHasAccess())->toBeFalse();
});

it('allows only the administrator group to access the toolbar', function (): void {
    $previous = $GLOBALS['member_id'] ?? null;
    try {
        $toolbar = new DLEToolbar();
        $GLOBALS['member_id'] = ['user_group' => '1'];
        expect($toolbar->isHasAccess())->toBeTrue();
        $GLOBALS['member_id'] = ['user_group' => 2];
        expect($toolbar->isHasAccess())->toBeFalse();
        $GLOBALS['member_id'] = ['user_group' => 5];
        expect($toolbar->isHasAccess())->toBeFalse();
    } finally {
        $GLOBALS['member_id'] = $previous;
    }
});

it('renders each response state', function (): void {
    $previousSiteConfig = $GLOBALS['config'];

    try {
        ToolbarPreferences::fromRequest(['toolbar' => [
            'items' => ['response'],
            'enabled' => ['response'],
        ]])->save();

        http_response_code(200);
        expect((new DLEToolbar())->render())->toBe('');

        foreach ([201 => 'success', 302 => 'redirect', 404 => 'error', 418 => 'error'] as $status => $state) {
            http_response_code($status);
            $html = (new DLEToolbar())->render();
            expect($html)->toContain('data-state="' . $state . '"')
                ->toContain('class="status-hint" data-component="response"')
                ->toContain('class="dock" hidden')
                ->not->toContain('class="control metric response"');
        }

    } finally {
        $GLOBALS['config'] = $previousSiteConfig;
        http_response_code(200);
    }
});

it('renders the SQL panel with chronological fast folds, escaping and an empty state', function (): void {
    $previousDb = $GLOBALS['db'];

    try {
        ToolbarPreferences::fromRequest(['toolbar' => [
            'items' => ['queries'],
            'enabled' => ['queries'],
        ]])->save();
        $GLOBALS['db'] = new \iCooLER\DLEToolbar\ToolbarTestDatabase($GLOBALS['dletoolbar_native_db'], [
            'query_num' => 4,
            'query_list' => [
                ['query' => 'Connection with MySQL Server', 'time' => .001, 'num' => 0],
                ['query' => 'SELECT fast_a', 'time' => .0002, 'num' => 1],
                ['query' => 'SELECT fast_b', 'time' => .0003, 'num' => 2],
                ['query' => "SELECT \"<value>\"\nFROM dle_post", 'time' => .0025, 'num' => 3],
                ['query' => 'UPDATE heavy', 'time' => .0543, 'num' => 4],
            ],
            'MySQL_time_taken' => .0573,
        ]);

        $html = (new DLEToolbar())->render();
        expect($html)->toContain('data-query-panel')
            ->toContain('id="dle-toolbar-queries-panel"')
            ->toContain('aria-controls="dle-toolbar-queries-panel"')
            ->toContain('aria-expanded="false"')
            ->not->toContain('data-query-modal')
            ->not->toContain('aria-modal="true"')
            ->toMatch('/data-query-panel.*<div class="dock"/s')
            ->toContain('№1–2')
            ->toContain('2 быстрых</span>')
            ->toContain('data-fast-row hidden')
            ->toContain('SELECT fast_a')
            ->not->toContain('&lt;1 мс')
            ->toContain('<span class="query-num">#3</span>')
            ->toContain('SELECT &quot;&lt;value&gt;&quot;')
            ->toContain("<span class=\"query-sql\"><span class=\"query-text\">SELECT &quot;&lt;value&gt;&quot;\nFROM dle_post</span></span>")
            ->toContain('class="query-copy" data-copy-query type="button" title="Скопировать SQL-запрос" aria-label="Скопировать SQL-запрос"')
            ->toContain('data-copy-status role="status" aria-live="polite"')
            ->toContain('>3 мс<')
            ->toContain('<span class="query-num">#4</span>')
            ->toContain('query-row-slow')
            ->toContain('>54 мс<')
            ->toContain('<b>4 запроса</b> · 57 мс')
            ->toContain('query-stats-alert')
            ->not->toContain('2 быстр.</span>')
            ->not->toContain('data-expand-all')
            ->not->toContain('Развернуть все')
            ->not->toContain('Connection with MySQL Server');

        $GLOBALS['db']->query_list = [];
        expect((new DLEToolbar())->render())->toContain('SQL-запросов на этой странице нет.');

        foreach ([1 => 'запрос', 2 => 'запроса', 10 => 'запросов', 11 => 'запросов', 14 => 'запросов', 21 => 'запрос', 22 => 'запроса', 111 => 'запросов'] as $count => $word) {
            $GLOBALS['db']->query_list = array_fill(0, $count, ['query' => 'SELECT 1', 'time' => .001, 'num' => 1]);
            expect((new DLEToolbar())->render())->toContain('<b>' . $count . ' ' . $word . '</b>');
        }
    } finally {
        $GLOBALS['db'] = $previousDb;
    }
});

it('clears DLE cache files while retaining system directories and protected files', function (): void {
    $cacheRoot = ENGINE_DIR . '/cache';
    $systemRoot = $cacheRoot . '/system';
    $previousConfig = $GLOBALS['config'];
    $previousFastCache = $GLOBALS['dlefastcache'] ?? null;
    $paths = [
        $systemRoot . '/CSS/nested/styles.cache',
        $systemRoot . '/HTML/page.cache',
        $systemRoot . '/URI/route.cache',
        $systemRoot . '/temporary.cache',
        $systemRoot . '/.htaccess',
        $systemRoot . '/cron.json',
        $systemRoot . '/cron.php',
        $cacheRoot . '/coverage-cache-fixture.tmp',
        $cacheRoot . '/.htaccess',
        $systemRoot . '/plugins/coverage-plugin-cache-fixture.php',
        $systemRoot . '/coverage-newsletter.lock',
        $systemRoot . '/coverage-worker.lock',
        $systemRoot . '/coverage-newsletter.lock.tmp',
    ];

    try {
        foreach ($paths as $path) {
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
            file_put_contents($path, 'fixture');
        }
        $lock = fopen($systemRoot . '/coverage-newsletter.lock', 'c');
        expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();
        $GLOBALS['config']['cache_type'] = 1;
        $GLOBALS['dlefastcache'] = (object) ['connection' => 0];

        (new CacheCleaner())->clear();

        foreach (array_slice($paths, 0, 3) as $path) expect(is_file($path))->toBeTrue();
        foreach (array_slice($paths, 4, 3) as $path) expect(is_file($path))->toBeTrue();
        expect(is_file($systemRoot . '/temporary.cache'))->toBeFalse()
            ->and(is_file($cacheRoot . '/coverage-cache-fixture.tmp'))->toBeFalse()
            ->and(is_file($cacheRoot . '/.htaccess'))->toBeTrue()
            ->and(is_file($systemRoot . '/plugins/coverage-plugin-cache-fixture.php'))->toBeTrue()
            ->and(is_file($systemRoot . '/coverage-newsletter.lock'))->toBeTrue()
            ->and(is_file($systemRoot . '/coverage-worker.lock'))->toBeTrue()
            ->and(is_file($systemRoot . '/coverage-newsletter.lock.tmp'))->toBeFalse();

        $secondLock = fopen($systemRoot . '/coverage-newsletter.lock', 'c');
        try {
            expect(flock($secondLock, LOCK_EX | LOCK_NB))->toBeFalse();
        } finally {
            fclose($secondLock);
        }

        $systemBackup = $systemRoot . '-coverage-backup';
        rename($systemRoot, $systemBackup);
        try {
            $GLOBALS['config']['cache_type'] = 0;
            (new CacheCleaner())->clear();
        } finally {
            rename($systemBackup, $systemRoot);
        }
    } finally {
        if (isset($lock)) fclose($lock);
        foreach (array_merge(array_slice($paths, 0, 3), array_slice($paths, 9)) as $path) {
            if (is_file($path)) unlink($path);
        }
        $GLOBALS['config'] = $previousConfig;
        if ($previousFastCache === null) unset($GLOBALS['dlefastcache']);
        else $GLOBALS['dlefastcache'] = $previousFastCache;
    }
});

it('clears the connected DLE fast cache even when caching is disabled for the request', function (): void {
    $previousConfig = $GLOBALS['config'];
    $previousFastCache = $GLOBALS['dlefastcache'] ?? null;
    $fastCache = new class {
        public $connection = 1;
        public $cleared = false;

        public function clear($areas = false): void {
            $this->cleared = $areas === false;
        }
    };

    try {
        $GLOBALS['config']['cache_type'] = 1;
        $GLOBALS['config']['allow_cache'] = false;
        $GLOBALS['dlefastcache'] = $fastCache;

        (new CacheCleaner())->clear();

        expect($fastCache->cleared)->toBeTrue();
    } finally {
        $GLOBALS['config'] = $previousConfig;
        if ($previousFastCache === null) unset($GLOBALS['dlefastcache']);
        else $GLOBALS['dlefastcache'] = $previousFastCache;
    }
});

it('handles cache reset cookies only for administrators with the login hash', function (): void {
    $toolbar = new DLEToolbar();
    $cachedFile = ENGINE_DIR . '/cache/coverage-cookie-cache-fixture.tmp';
    $previousCookie = $_COOKIE['dle_toolbar_clear_cache'] ?? null;
    $previousConfig = $GLOBALS['config'];
    $previousMemberId = $GLOBALS['member_id'];
    $GLOBALS['config']['allow_cache'] = true;
    $GLOBALS['config']['cache_type'] = 0;

    try {
        file_put_contents($cachedFile, 'stale cache');
        unset($_COOKIE['dle_toolbar_clear_cache']);
        $toolbar->init();
        expect(is_file($cachedFile))->toBeTrue();

        $_COOKIE['dle_toolbar_clear_cache'] = 'test-user-hash';
        $GLOBALS['member_id'] = ['user_group' => 2];
        $toolbar->init();
        expect($GLOBALS['config']['allow_cache'])->toBeTrue()
            ->and(is_file($cachedFile))->toBeTrue();

        $GLOBALS['member_id'] = ['user_group' => 1];
        foreach (['1', 'other-hash', ['test-user-hash']] as $invalidToken) {
            $_COOKIE['dle_toolbar_clear_cache'] = $invalidToken;
            $toolbar->init();
            expect($GLOBALS['config']['allow_cache'])->toBeTrue()
                ->and(is_file($cachedFile))->toBeTrue();
        }

        $_COOKIE['dle_toolbar_clear_cache'] = 'test-user-hash';
        $toolbar->init();
        expect($GLOBALS['config']['allow_cache'])->toBeFalse()
            ->and(is_file($cachedFile))->toBeFalse();
    } finally {
        if (is_file($cachedFile)) unlink($cachedFile);
        if ($previousCookie === null) unset($_COOKIE['dle_toolbar_clear_cache']);
        else $_COOKIE['dle_toolbar_clear_cache'] = $previousCookie;
        $GLOBALS['config'] = $previousConfig;
        $GLOBALS['member_id'] = $previousMemberId;
    }
});

it('injects the rendered toolbar into the main template result', function (): void {
    $previousTemplate = $GLOBALS['tpl'] ?? null;
    $previousToolbar = $GLOBALS['DLEToolbar'] ?? null;
    $GLOBALS['DLEToolbar'] = new DLEToolbar();
    $GLOBALS['tpl'] = (object) ['result' => ['main' => '<article><!-- DLEToolbar --></article><main>' . $GLOBALS['DLEToolbar']->marker() . '</main>']];

    try {
        expect($GLOBALS['DLEToolbar']->marker())->not->toBe((new DLEToolbar())->marker());
        include ENGINE_DIR . '/iCooLER/DLEToolbar/main.php';
        expect($GLOBALS['tpl']->result['main'])->toContain('<article><!-- DLEToolbar --></article><main><div data-dle-toolbar')
            ->and(substr_count($GLOBALS['tpl']->result['main'], 'data-dle-toolbar'))->toBe(1);
        expect($GLOBALS['DLEToolbar'])->toBeInstanceOf(DLEToolbar::class);
    } finally {
        if ($previousTemplate === null) unset($GLOBALS['tpl']);
        else $GLOBALS['tpl'] = $previousTemplate;
        if ($previousToolbar === null) unset($GLOBALS['DLEToolbar']);
        else $GLOBALS['DLEToolbar'] = $previousToolbar;
    }
});

it('renders the admin settings page and consumes its saved notice', function (string $version, array $scripts, bool $inlineSortable): void {
    $GLOBALS['config']['version_id'] = $version;
    $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $previousHash = $GLOBALS['dle_login_hash'] ?? null;
    $previousJsArray = $GLOBALS['js_array'] ?? [];
    $previousNotice = $_SESSION['dletoolbar_settings_notice'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $GLOBALS['dle_login_hash'] = 'test-user-hash';
    $GLOBALS['js_array'] = [];
    $_SESSION['dletoolbar_settings_notice'] = 'Настройки панели сохранены.';
    ob_start();
    $bufferOpen = true;

    try {
        ToolbarPreferences::fromRequest(['access' => ['groups' => ['2']]])->save();
        include ENGINE_DIR . '/inc/dletoolbar.php';
        $html = (string) ob_get_clean();
        $bufferOpen = false;

        expect($html)->toContain('id="dlt-settings-form"')
            ->toContain('Настройки панели сохранены.')
            ->toContain('<option value="2" selected>Главные редакторы</option>')
            ->and(str_contains($html, '/*! Sortable 1.7.0'))->toBe($inlineSortable)
            ->and($GLOBALS['js_array'])->toBe($scripts)
            ->and(isset($_SESSION['dletoolbar_settings_notice']))->toBeFalse();
    } finally {
        if ($bufferOpen) ob_end_clean();
        if ($previousMethod === null) unset($_SERVER['REQUEST_METHOD']);
        else $_SERVER['REQUEST_METHOD'] = $previousMethod;
        if ($previousHash === null) unset($GLOBALS['dle_login_hash']);
        else $GLOBALS['dle_login_hash'] = $previousHash;
        $GLOBALS['js_array'] = $previousJsArray;
        if ($previousNotice === null) unset($_SESSION['dletoolbar_settings_notice']);
        else $_SESSION['dletoolbar_settings_notice'] = $previousNotice;
    }
})->with([
    ['13.0', [], true],
    ['13.1', ['engine/classes/js/sortable.js'], false],
    ['18.0', ['engine/classes/js/sortable.js'], false],
    ['19.0', ['public/js/sortable.js'], false],
]);

it('links the current full news to the configured admin and respects component settings', function (): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    $GLOBALS['config']['admin_path'] = 'editor.php';
    // Slug URLs may have no numeric newsid; DLE supplies the resolved NEWS_ID.
    $GLOBALS['newsid'] = 0;

    try {
        ToolbarPreferences::fromRequest(['toolbar' => [
            'items' => ['editpost', 'time'], 'enabled' => ['editpost', 'time'],
        ]])->save();
        $html = (new DLEToolbar())->render();
        preg_match_all('/data-component="([^"]+)"/', $html, $matches);
        expect($matches[1])->toBe(['editpost', 'time'])
            ->and($html)->toContain('href="https://example.test/editor.php?mod=editnews&amp;action=editnews&amp;id=42"')
            ->toContain('aria-label="Редактировать новость"');

        $GLOBALS['config']['admin_path'] = 'editor".php';
        expect((new DLEToolbar())->render())->toContain('editor&quot;.php?mod=editnews');

        ToolbarPreferences::fromRequest(['toolbar' => ['enabled' => ['time']]])->save();
        expect((new DLEToolbar())->render())->not->toContain('data-component="editpost"');
    } finally {
        unset($GLOBALS['newsid']);
    }
});

it('hides the edit control outside a found news page or without admin editing access', function (string $module, bool $found, int $admin, int $edit): void {
    $GLOBALS['dle_module'] = $module;
    $GLOBALS['news_found'] = $found;
    $GLOBALS['user_group'][1]['allow_admin'] = $admin;
    $GLOBALS['user_group'][1]['admin_editnews'] = $edit;

    ToolbarPreferences::fromRequest(['toolbar' => ['enabled' => ['editpost']]])->save();
    expect((new DLEToolbar())->render())->toBe('');
})->with([
    'main page' => ['main', false, 1, 1],
    'category page' => ['cat', false, 1, 1],
    'missing or inaccessible news' => ['showfull', false, 1, 1],
    'no admin access' => ['showfull', true, 0, 1],
    'no news editing section' => ['showfull', true, 1, 0],
]);

it('includes only enabled components and their assets in the toolbar shell', function (): void {
    ToolbarPreferences::fromRequest(['toolbar' => ['enabled' => ['time']]])->save();
    $html = (new DLEToolbar())->render();
    expect($html)->toContain('data-component="time"')
        ->toContain('.time .speed-bar {')
        ->not->toContain('data-component="response"')
        ->not->toContain('.response {')
        ->not->toContain('.skin-picker {')
        ->not->toContain('const skinTrigger')
        ->not->toContain('const clearCacheButton')
        ->not->toContain('data-queries-panel');
});

it('rejects direct requests to files with executable code', function (string $file): void {
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg(ENGINE_DIR . '/' . $file) . ' 2>&1');
    expect($output)->toBe('Hacking attempt!');
})->with(['inc/dletoolbar.php', 'iCooLER/DLEToolbar/main.php', 'iCooLER/DLEToolbar/Views/settings.php', 'iCooLER/DLEToolbar/Views/component-row.php', 'iCooLER/DLEToolbar/Views/component-modal.php', 'iCooLER/DLEToolbar/Views/settings-script.php', 'iCooLER/DLEToolbar/Views/sortable.php']);
