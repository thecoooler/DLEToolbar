<?php

use iCooLER\DLEToolbar\Components\Entry\Entry;
use iCooLER\DLEToolbar\Components\Entry\PageEntry;
use iCooLER\DLEToolbar\Components\Link\NewsFields;

use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['config']['allow_alt_url'] = 1;
    $GLOBALS['lang'] = ['xfield_xyes' => 'Да', 'xfield_xno' => 'Нет'];
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
    if ($allowed) {
        expect($panel)->toContain('href="https://example.test/admin.php?mod=editnews&amp;action=editnews&amp;id=42"');
    } else {
        expect($panel)->not->toContain('mod=editnews');
    }
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

it('does not expose profile columns joined into a news row', function (): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    $profileColumns = ['email', 'name', 'news_num', 'user_comm_num', 'user_group', 'lastdate', 'reg_date',
        'banned', 'allow_mail', 'info', 'signature', 'foto', 'fullname', 'land', 'favorites',
        'pm_all', 'pm_unread', 'time_limit', 'user_xfields'];
    $joined = [];
    foreach ($profileColumns as $column) {
        $joined[$column] = 'JOINED_' . $column . '_VALUE';
    }
    PageEntry::captureNews(entryNewsRow($joined));
    $panel = renderEntry()['panel'];
    foreach ($joined as $value) {
        expect($panel)->not->toContain($value);
    }
    expect($panel)->not->toContain('<code>user_id</code>')
        ->toContain('<dt>Просмотры</dt><dd>1 234</dd>')
        ->toContain('ID 5');
});

it('renders repeated dynamic news fields on separate lines', function (): void {
    require_once \DLEPlugins::Check(ENGINE_DIR . '/classes/xfields.class.php');
    $previous = \DLEXFields::$fields;
    try {
        $GLOBALS['config']['version_id'] = '21.0';
        $GLOBALS['dle_module'] = 'showfull';
        $GLOBALS['news_found'] = true;
        \DLEXFields::$fields = ['fields' => [
            'code' => ['name' => 'code', 'description' => 'Код', 'type' => 'text', 'allow_view_usergroups' => '', 'dynamic' => 1],
        ]];
        $row = entryNewsRow(['xfields' => 'code|first||code|second']);
        PageEntry::captureNews($row);
        NewsFields::capture($row['xfields']);
        expect(renderEntry()['panel'])->toContain('<dt>Код<code>code</code></dt><dd><details class="entry-spoiler">')
            ->toContain("first\nsecond")
            ->not->toContain("first\x01second");
    } finally {
        \DLEXFields::$fields = $previous;
    }
});

it('shows the viewed category with its parent, children and settings', function (): void {
    $GLOBALS['dle_module'] = 'cat';
    $GLOBALS['category_id'] = 7;
    $GLOBALS['cat_info'][7]['rating_type'] = 2;
    $GLOBALS['user_group'][1]['admin_categories'] = 1;
    $panel = renderEntry()['panel'];
    expect($panel)->toContain('<p class="entry-kind">Категория <span>ID 7</span></p>')
        ->toContain('<h2 id="dle-toolbar-entry-title">Драмы</h2>')
        ->toContain('data-tone="success">Активна')
        ->toContain('Кино &amp; сериалы <span class="entry-muted">ID 3</span>')
        ->toContain('<dt>Подкатегории</dt><dd>Мелодрамы</dd>')
        ->toContain('<dt>Сортировка</dt><dd>Рейтинг</dd>')
        ->toContain('<dt>Порядок сортировки</dt><dd><span class="entry-muted">по настройкам сайта</span></dd>')
        ->toContain('<dt>Тип рейтинга</dt><dd>«Нравится» или «Не нравится»</dd>')
        ->toContain('<dt>Микроразметка</dt><dd><code>Movie</code></dd>')
        ->toContain('<details class="entry-spoiler">')
        ->toContain('href="https://example.test/admin.php?mod=categories&amp;action=edit&amp;catid=7"');
    preg_match_all('/data-entry-tab="([a-z]+)"/', $panel, $tabs);
    expect($tabs[1])->toBe(['main', 'seo', 'templates', 'options', 'raw']);
});

it('shows the viewed profile without account secrets', function (): void {
    $GLOBALS['dle_module'] = 'userinfo';
    $GLOBALS['member_id']['user_id'] = 12;
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
});

it('shows private profile data only to the owner and to groups that edit users', function (string $version, int $viewer, bool $editor, bool $visible): void {
    require_once \DLEPlugins::Check(ENGINE_DIR . '/classes/xfields.class.php');
    $GLOBALS['dle_module'] = 'userinfo';
    $GLOBALS['config']['version_id'] = $version;
    $GLOBALS['member_id']['user_id'] = $viewer;
    $GLOBALS['user_group'][1]['admin_editusers'] = (int) $editor;
    PageEntry::captureUser(['user_id' => 12, 'name' => 'tester', 'user_group' => 3, 'banned' => '',
        'xfields' => 'city|PROFILE_VALUE', 'email' => 'private@example.test', 'allowed_ip' => '192.0.2.1',
        'logged_ip' => '192.0.2.2', 'favorites' => '41,42', 'pm_all' => 51, 'pm_unread' => 52,
        'other_column' => 'RAW_PROFILE_VALUE', 'password' => 'PASSWORD_HASH', 'hash' => 'SESSION_HASH',
        'twofactor_secret' => 'TOTP_SECRET', 'twofactor_auth' => 1, 'time_limit' => 1700000000]);
    $panel = renderEntry()['panel'];
    if ($visible) {
        expect($panel)->toContain('<dt><code>city</code></dt><dd>PROFILE_VALUE</dd>')
            ->toContain('private@example.test')->toContain('192.0.2.1')->toContain('192.0.2.2')
            ->toContain('<dt>В избранном</dt><dd>2</dd>')->toContain('<dt>Сообщений</dt><dd>51')
            ->toContain('RAW_PROFILE_VALUE')
            ->toContain('Двухфакторная авторизация')
            ->toContain('<span class="entry-muted">до ');
    } else {
        expect($panel)->not->toContain('data-entry-tab="fields"')
            ->not->toContain('data-entry-tab="raw"')
            ->not->toContain('PROFILE_VALUE')->not->toContain('RAW_PROFILE_VALUE')
            ->not->toContain('private@example.test')->not->toContain('192.0.2.1')->not->toContain('192.0.2.2')
            ->not->toContain('<dt>В избранном</dt>')->not->toContain('<dt>Сообщений</dt>')
            ->not->toContain('Двухфакторная авторизация')
            ->not->toContain('<span class="entry-muted">до ');
    }
    expect($panel)->not->toContain('PASSWORD_HASH')->not->toContain('SESSION_HASH')->not->toContain('TOTP_SECRET');
})->with([
    'legacy owner' => ['18.0', 12, false, true],
    'legacy user editor' => ['18.0', 1, true, true],
    'legacy other staff' => ['18.0', 1, false, false],
    'modern owner' => ['21.0', 12, false, true],
    'modern user editor' => ['21.0', 1, true, true],
    'modern other staff' => ['21.0', 1, false, false],
]);
