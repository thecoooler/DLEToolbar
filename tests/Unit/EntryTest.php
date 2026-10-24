<?php

use iCooLER\DLEToolbar\Components\Entry\Entry;
use iCooLER\DLEToolbar\Components\Entry\PageEntry;
use iCooLER\DLEToolbar\Components\Link\NewsFields;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['config']['allow_alt_url'] = 1;
    $GLOBALS['lang'] = ['xfield_xyes' => 'Да', 'xfield_xno' => 'Нет'];
    $GLOBALS['is_logged'] = true;
    $GLOBALS['member_id']['user_id'] = 1;
    $GLOBALS['xfields'] = [];
    NewsFields::capture('');
    $GLOBALS['cat_info'] = [
        3 => ['id' => 3, 'parentid' => 0, 'name' => 'Кино &amp; сериалы', 'alt_name' => 'kino'],
        7 => ['id' => 7, 'parentid' => 3, 'name' => 'Драмы', 'alt_name' => 'drama', 'posi' => 2, 'active' => 1,
            'descr' => 'Описание', 'fulldescr' => str_repeat('Длинное описание. ', 20), 'news_sort' => 'rating',
            'news_msort' => '', 'show_sub' => 0, 'disable_index' => 1, 'short_tpl' => 'shortstory-drama', 'schema_org' => 'Movie'],
        9 => ['id' => 9, 'parentid' => 7, 'name' => 'Мелодрамы', 'alt_name' => 'melodrama'],
    ];
});

/** @return array<string, mixed> */
function entryNewsRow(array $overrides = []): array
{
    return array_replace([
        'id' => 42, 'autor' => 'editor', 'user_id' => 5, 'date' => time() - 3600, 'title' => 'Новость &quot;<b>1</b>&quot;',
        'category' => '7,3', 'approve' => 1, 'allow_main' => 1, 'fixed' => 0, 'comm_num' => 4, 'news_read' => 1234,
        'rating' => 9, 'vote_num' => 3, 'editdate' => 0, 'editor' => '', 'reason' => '', 'tags' => 'один, два',
        'metatitle' => '', 'descr' => 'SEO', 'keywords' => '', 'access' => '4:3||5:1', 'xfields' => 'poster|a.jpg',
        'short_story' => 'SHORT_STORY_TEXT', 'full_story' => 'FULL_STORY_TEXT', 'need_pass' => 0,
    ], $overrides);
}

function renderEntry(): ?array
{
    return (new Entry())->render();
}

/** @param list<array{0: string, 1: int|string}> $fields */
function setLegacyProfileFields(array $fields): void
{
    @mkdir(ENGINE_DIR . '/data', 0777, true);
    $lines = [];
    foreach ($fields as [$name, $private]) {
        $lines[] = "{$name}|{$name}||text||{$private}";
    }
    file_put_contents(ENGINE_DIR . '/data/xprofile.txt', implode("\n", $lines));
}

it('is hidden outside news, category and profile pages', function (string $module): void {
    $GLOBALS['dle_module'] = $module;
    $GLOBALS['news_found'] = false;
    $GLOBALS['category_id'] = 'not detected';
    expect(renderEntry())->toBeNull();
})->with(['main', 'showfull', 'cat', 'userinfo', 'static']);

it('shows the viewed news with status, dates, fields and tabs', function (): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    $GLOBALS['xfields'] = [
        [0 => 'poster', 1 => 'Постер', 3 => 'text', 20 => ''],
        [0 => 'secret', 1 => 'Тайное', 3 => 'text', 20 => '2'],
        [0 => 'free', 1 => 'Бесплатно', 3 => 'yesorno', 20 => ''],
    ];
    $row = entryNewsRow(['date' => time() + 86400, 'approve' => 0,
        'xfields' => 'poster|a.jpg||secret|HIDDEN_NEWS_VALUE||free|1']);
    PageEntry::captureNews($row);
    NewsFields::capture($row['xfields']);
    $rendered = renderEntry();

    expect($rendered['control'])->toContain('data-entry-open')
        ->toContain('aria-label="Сведения о странице: Новость «Новость &quot;&lt;b&gt;1&lt;/b&gt;&quot;»"')
        ->and($rendered['panel'])->toContain('<h2 id="dle-toolbar-entry-title">Новость &quot;&lt;b&gt;1&lt;/b&gt;&quot;</h2>')
        ->not->toContain('<b>1</b>')
        ->toContain('data-tone="warning">На модерации')
        ->toContain('data-tone="info">Отложенная публикация')
        ->toContain('Драмы, Кино &amp; сериалы')
        ->toContain('href="https://example.test/user/editor/"')
        ->toContain('<dt>Постер<code>poster</code></dt><dd>a.jpg</dd>')
        ->toContain('<dt>Бесплатно<code>free</code></dt><dd>Да</dd>')
        ->not->toContain('Тайное')
        ->not->toContain('HIDDEN_NEWS_VALUE')
        ->not->toContain('<code>xfields</code>')
        ->toContain('Пользователи: Нет доступа<br>Гости: Только чтение')
        ->toContain('<dt>Дата редактирования</dt><dd><span class="entry-empty">—</span></dd>')
        ->toContain('1 234')
        ->not->toContain('SHORT_STORY_TEXT')
        ->not->toContain('FULL_STORY_TEXT');
    preg_match_all('/data-entry-tab="([a-z]+)"/', $rendered['panel'], $tabs);
    expect($tabs[1])->toBe(['main', 'fields', 'seo', 'options', 'raw'])
        ->and(substr_count($rendered['panel'], 'role="tabpanel"'))->toBe(5)
        ->and(substr_count($rendered['panel'], 'role="tab" aria-selected="true"'))->toBe(1)
        ->and(substr_count($rendered['panel'], 'role="tabpanel" aria-labelledby="dle-toolbar-entry-tab-main" tabindex="0">'))->toBe(1);
});

it('links to news editing only for groups allowed to edit news', function (bool $allowed): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    $GLOBALS['user_group'][1]['admin_editnews'] = $allowed ? 1 : 0;
    PageEntry::captureNews(entryNewsRow());
    $panel = renderEntry()['panel'];
    if ($allowed) expect($panel)->toContain('href="https://example.test/admin.php?mod=editnews&amp;action=editnews&amp;id=42"');
    else expect($panel)->not->toContain('mod=editnews');
})->with([true, false]);

it('omits the fields tab for news without visible fields', function (): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    PageEntry::captureNews(entryNewsRow(['tags' => ['один', 'два'], 'xfields' => 'secret|HIDDEN_NEWS_VALUE',
        'xfields_array' => ['secret' => 'HIDDEN_DECODED_NEWS_VALUE']]));
    $panel = renderEntry()['panel'];
    expect($panel)->not->toContain('data-entry-tab="fields"')
        ->not->toContain('HIDDEN_NEWS_VALUE')
        ->not->toContain('HIDDEN_DECODED_NEWS_VALUE')
        ->not->toContain('<code>xfields_array</code>')
        ->toContain('<dt>Теги</dt><dd>один, два</dd>');
});

it('shows the viewed category with its parent, children and settings', function (): void {
    $GLOBALS['dle_module'] = 'cat';
    $GLOBALS['category_id'] = 7;
    $GLOBALS['user_group'][1]['admin_categories'] = 1;
    $panel = renderEntry()['panel'];
    expect($panel)->toContain('<p class="entry-kind">Категория <span>ID 7</span></p>')
        ->toContain('<h2 id="dle-toolbar-entry-title">Драмы</h2>')
        ->toContain('data-tone="success">Активна')
        ->toContain('Кино &amp; сериалы <span class="entry-muted">ID 3</span>')
        ->toContain('<dt>Подкатегории</dt><dd>Мелодрамы</dd>')
        ->toContain('<dt>Сортировка</dt><dd>Рейтинг</dd>')
        ->toContain('<dt>Порядок сортировки</dt><dd><span class="entry-muted">по настройкам сайта</span></dd>')
        ->toContain('<dt>Микроразметка</dt><dd><code>Movie</code></dd>')
        ->toContain('<details class="entry-spoiler">')
        ->toContain('href="https://example.test/admin.php?mod=categories&amp;action=edit&amp;catid=7"');
    preg_match_all('/data-entry-tab="([a-z]+)"/', $panel, $tabs);
    expect($tabs[1])->toBe(['main', 'seo', 'templates', 'options', 'raw']);
});

it('shows the viewed profile without account secrets', function (): void {
    $GLOBALS['dle_module'] = 'userinfo';
    setLegacyProfileFields([['city', 0], ['site', 0]]);
    try {
        PageEntry::captureUser([
            'user_id' => 12, 'name' => 'tester', 'email' => 'tester@example.test', 'user_group' => 3, 'banned' => 'yes',
            'restricted' => 2, 'reg_date' => 1700000000, 'lastdate' => 1700003600, 'news_num' => 2, 'comm_num' => 10,
            'info' => "Строка 1\nСтрока 2", 'favorites' => '1,2,3', 'xfields' => 'city|Москва||site|', 'time_limit' => '',
            'password' => 'PASSWORD_HASH', 'hash' => 'SESSION_HASH', 'twofactor_secret' => 'TOTP_SECRET', 'logged_ip' => '127.0.0.1',
        ]);
        $panel = renderEntry()['panel'];
        expect($panel)->toContain('<p class="entry-kind">Пользователь <span>ID 12</span></p>')
            ->toContain('<dt>Группа</dt><dd>Журналисты</dd>')
            ->toContain('data-tone="danger">Заблокирован')
            ->toContain('<dt>Ограничение</dt><dd>Комментарии</dd>')
            ->toContain('<dt><code>city</code></dt><dd>Москва</dd>')
            ->toContain('<dt>В избранном</dt><dd>3</dd>')
            ->toContain('<details class="entry-spoiler">')
            ->not->toContain('PASSWORD_HASH')
            ->not->toContain('SESSION_HASH')
            ->not->toContain('TOTP_SECRET')
            ->not->toContain('mod=editusers');
    } finally {
        @unlink(ENGINE_DIR . '/data/xprofile.txt');
    }
});

it('applies native profile field privacy across DLE versions', function (string $version, int $viewer, int $group, bool $editor, bool $loggedIn, bool $visible): void {
    require_once \DLEPlugins::Check(ENGINE_DIR . '/classes/userxfields.class.php');
    $previous = \DLEUserXFields::$fields;
    setLegacyProfileFields([['city', 0], ['private', 1]]);
    try {
        $GLOBALS['dle_module'] = 'userinfo';
        $GLOBALS['config']['version_id'] = $version;
        $GLOBALS['is_logged'] = $loggedIn;
        $GLOBALS['member_id'] = ['user_id' => $viewer, 'user_group' => $group];
        $GLOBALS['user_group'][$group]['admin_editusers'] = (int) $editor;
        // Global $xfields can be overwritten by other modules (e.g. topnews); profile definitions must come from xprofile.txt.
        $GLOBALS['xfields'] = [[0 => 'news_field', 5 => '0']];
        \DLEUserXFields::$fields = ['fields' => [
            'city' => ['name' => 'city', 'private' => 0],
            'private' => ['name' => 'private', 'private' => 1],
        ]];
        PageEntry::captureUser(['user_id' => 12, 'name' => 'tester', 'user_group' => 3, 'banned' => '',
            'xfields' => 'city|PUBLIC_CITY||private|PRIVATE_PROFILE_VALUE||deleted|UNDEFINED_PROFILE_VALUE']);
        $panel = renderEntry()['panel'];
        expect($panel)->toContain('PUBLIC_CITY')
            ->not->toContain('UNDEFINED_PROFILE_VALUE')
            ->not->toContain('<code>xfields</code>')
            ->not->toContain('news_field');
        if ($visible) expect($panel)->toContain('PRIVATE_PROFILE_VALUE');
        else expect($panel)->not->toContain('PRIVATE_PROFILE_VALUE');
    } finally {
        \DLEUserXFields::$fields = $previous;
        @unlink(ENGINE_DIR . '/data/xprofile.txt');
    }
})->with([
    'legacy other profile' => ['18.0', 9, 3, false, true, false],
    'legacy owner' => ['18.0', 12, 3, false, true, true],
    'legacy admin' => ['18.0', 1, 1, true, true, true],
    'legacy editor' => ['18.0', 9, 2, true, true, false],
    'legacy guest' => ['18.0', 12, 1, true, false, false],
    'modern other profile' => ['21.0', 9, 3, false, true, false],
    'modern owner' => ['21.0', 12, 3, false, true, true],
    'modern admin' => ['21.0', 1, 1, true, true, true],
    'modern editor' => ['21.0', 9, 2, true, true, true],
    'modern guest' => ['21.0', 12, 1, true, false, false],
]);

it('omits the profile fields tab when all stored fields are private', function (): void {
    $GLOBALS['dle_module'] = 'userinfo';
    $GLOBALS['member_id'] = ['user_id' => 9, 'user_group' => 3];
    setLegacyProfileFields([['private', 1]]);
    try {
        PageEntry::captureUser(['user_id' => 12, 'name' => 'tester', 'user_group' => 3, 'banned' => '',
            'xfields' => 'private|PRIVATE_PROFILE_VALUE']);
        expect(renderEntry()['panel'])->not->toContain('data-entry-tab="fields"')
            ->not->toContain('PRIVATE_PROFILE_VALUE');
    } finally {
        @unlink(ENGINE_DIR . '/data/xprofile.txt');
    }
});

it('reads profile definitions from xprofile.txt even when global xfields is overwritten', function (): void {
    $GLOBALS['dle_module'] = 'userinfo';
    $GLOBALS['config']['version_id'] = '18.0';
    $GLOBALS['is_logged'] = false;
    // Simulate topnews.php overwriting $xfields global with news fields.
    $GLOBALS['xfields'] = [[0 => 'news_field', 5 => '0']];
    setLegacyProfileFields([['city', 0], ['private', 1]]);
    try {
        PageEntry::captureUser(['user_id' => 12, 'name' => 'tester', 'user_group' => 3, 'banned' => '',
            'xfields' => 'city|Moscow||private|Secret']);
        $panel = renderEntry()['panel'];
        expect($panel)->toContain('Moscow')
            ->not->toContain('Secret')
            ->not->toContain('news_field');
    } finally {
        @unlink(ENGINE_DIR . '/data/xprofile.txt');
    }
});

it('normalizes expired user bans to unbanned', function (string $banned, int $until, string $tone, string $text): void {
    $GLOBALS['dle_module'] = 'userinfo';
    $GLOBALS['config']['version_id'] = '21.0';
    $bannedInfo = ['users_id' => [12 => ['date' => $until]]];
    $row = ['user_id' => 12, 'name' => 'tester', 'user_group' => 3, 'banned' => $banned, 'restricted' => 0];
    if ($row['banned'] === 'yes' && !empty($bannedInfo['users_id'][$row['user_id']]['date']) && $bannedInfo['users_id'][$row['user_id']]['date'] < time()) {
        $row['banned'] = '';
    }
    PageEntry::captureUser($row);
    $panel = renderEntry()['panel'];
    if ($tone !== '') {
        expect($panel)->toContain('data-tone="' . $tone . '">' . $text);
    } else {
        expect($panel)->not->toContain('Заблокирован')
            ->not->toContain('data-tone="danger"');
    }
})->with([
    'expired ban' => ['yes', time() - 3600, '', ''],
    'active ban' => ['yes', time() + 3600, 'danger', 'Заблокирован'],
    'permanent ban' => ['yes', 0, 'danger', 'Заблокирован'],
    'expiring now' => ['yes', time(), 'danger', 'Заблокирован'],
]);

it('words ages with Russian plural forms', function (int $seconds, string $expected): void {
    expect(\iCooLER\DLEToolbar\Components\Entry\Format::ago(time() - $seconds))->toBe($expected);
})->with([
    [10, 'только что'], [5 * 60, '5 мин назад'], [3 * 3600, '3 ч назад'], [86400 + 5, '1 день назад'],
    [2 * 86400 + 5, '2 дня назад'], [5 * 86400 + 5, '5 дней назад'], [11 * 86400 + 5, '11 дней назад'],
    [21 * 86400 + 5, '21 день назад'], [112 * 86400 + 5, '112 дней назад'],
]);
