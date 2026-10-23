<?php

use iCooLER\DLEToolbar\Components\Entry\Dashboard;
use iCooLER\DLEToolbar\Components\Entry\Entry;
use iCooLER\DLEToolbar\Components\Entry\PageEntry;
use iCooLER\DLEToolbar\Components\Link\NewsFields;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['config']['allow_alt_url'] = 1;
    $GLOBALS['lang'] = ['xfield_xyes' => 'Да', 'xfield_xno' => 'Нет'];
    $GLOBALS['xfields'] = [];
    NewsFields::capture('');
    $GLOBALS['cat_info'] = [\n        3 => ['id' => 3, 'parentid' => 0, 'name' => 'Кино &amp; сериалы', 'alt_name' => 'kino'],
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
    $row = entryNewsRow(['date' => time() + 86400, 'approve' => 0]);
    PageEntry::captureNews($row);
    NewsFields::capture('poster|a.jpg||secret|x||free|1');
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
        ->toContain('Пользователи: Нет доступа<br>Гости: Только чтение')
        ->toContain('<dt>Дата редактирования</dt><dd><span class="entry-empty">—</span></dd>')
        ->toContain('1 234')
        ->not->toContain('SHORT_STORY_TEXT')
        ->not->toContain('FULL_STORY_TEXT');
    preg_match_all('/data-entry-tab="([a-z]+)"/', $rendered['panel'], $tabs);
    expect($tabs[1])->toBe(['overview', 'main', 'fields', 'seo', 'options', 'raw'])
        ->and(substr_count($rendered['panel'], 'role="tabpanel"'))->toBe(6)
        ->and(substr_count($rendered['panel'], 'role="tab" aria-selected="true"'))->toBe(1)
        ->and(substr_count($rendered['panel'], 'role="tabpanel" aria-labelledby="dle-toolbar-entry-tab-overview" tabindex="0">'))->toBe(1);
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
    PageEntry::captureNews(entryNewsRow(['tags' => ['один', 'два']]));
    $panel = renderEntry()['panel'];
    expect($panel)->not->toContain('data-entry-tab="fields"')
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
    expect($tabs[1])->toBe(['overview', 'main', 'seo', 'templates', 'options', 'raw']);
});

it('shows the viewed profile without account secrets', function (): void {
    $GLOBALS['dle_module'] = 'userinfo';
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

it('summarizes news on the overview tab: author, age, edits and access limits', function (): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    PageEntry::captureNews(entryNewsRow([
        'date' => time() - 3 * 86400 - 600, 'editdate' => time() - 7200, 'editor' => 'moder', 'reason' => 'Исправлена <опечатка>',
        'need_pass' => 1, 'allowed_country' => 'RU', 'news_read' => 1234, 'comm_num' => 4,
    ]));
    $panel = renderEntry()['panel'];
    preg_match('/id="dle-toolbar-entry-tabpanel-overview".*?<\/div><\/div><\/div>/s', $panel, $overview);
    expect($overview[0])->toContain('<span class="entry-tile-label">Просмотры</span><span class="entry-tile-value">1 234</span>')
        ->toContain('<span class="entry-tile-value">3 дня назад</span>')
        ->toContain('data-tone="success"><div class="entry-card-head"><h3 class="entry-card-title">Публикация</h3><p class="entry-verdict">Опубликована</p>')
        ->toContain('<dt>Автор</dt><dd><a href="https://example.test/user/editor/">editor</a></dd>')
        ->toContain('· 3 дня назад</span>')
        ->toContain('<p class="entry-verdict">Редактировалась</p>')
        ->toContain('· 2 ч назад</span>')
        ->toContain('<dt>Кто</dt><dd>moder</dd>')
        ->toContain('<dt>Причина</dt><dd>Исправлена &lt;опечатка&gt;</dd>')
        ->toContain('<p class="entry-verdict">Ограничен</p>')
        ->toContain('<li>Нужен пароль</li>')
        ->toContain('<li>Группа Пользователи: Нет доступа</li>')
        ->toContain('<li>Только для стран: RU</li>');
});

it('reports an unedited, unrestricted news as such on the overview tab', function (): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    PageEntry::captureNews(entryNewsRow(['access' => '']));
    $panel = renderEntry()['panel'];
    expect($panel)->toContain('<p class="entry-verdict">Не редактировалась</p>')
        ->toContain('<p class="entry-verdict">Без ограничений</p>')
        ->not->toContain('<li>Нужен пароль</li>');
});

it('flags a news that waits for moderation or a future date as restricted', function (): void {
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    PageEntry::captureNews(entryNewsRow(['approve' => 0, 'access' => '', 'date' => time() + 2 * 86400 + 600]));
    $panel = renderEntry()['panel'];
    expect($panel)->toContain('<p class="entry-verdict">Отложена</p>')
        ->toContain('<li>Не опубликована: видна только автору и редакторам</li>')
        ->toContain('<li>Дата публикации ещё не наступила</li>')
        ->toContain('<span class="entry-tile-value">через 2 дня</span>');
});

it('shows the account state and restrictions of a profile on the overview tab', function (): void {
    $GLOBALS['dle_module'] = 'userinfo';
    PageEntry::captureUser([
        'user_id' => 12, 'name' => 'tester', 'email' => 'a@example.test', 'user_group' => 3, 'banned' => '',
        'restricted' => 3, 'restricted_date' => 0, 'reg_date' => time() - 11 * 86400, 'lastdate' => time() - 120,
        'news_num' => 2, 'comm_num' => 10, 'twofactor_auth' => 1, 'allowed_ip' => '10.0.0.1', 'xfields' => '',
    ]);
    $panel = renderEntry()['panel'];
    expect($panel)->toContain('<span class="entry-tile-value">2 мин назад</span>')
        ->toContain('· 11 дней назад</span>')
        ->toContain('<p class="entry-verdict">Активен</p>')
        ->toContain('<p class="entry-verdict">Ограничен</p>')
        ->toContain('<li>Ограничение: новости и комментарии без срока</li>')
        ->toContain('<li>Вход только с IP: 10.0.0.1</li>');
});

it('lists category display limits on the overview tab', function (): void {
    $GLOBALS['dle_module'] = 'cat';
    $GLOBALS['category_id'] = 7;
    $GLOBALS['cat_info'][7]['disable_main'] = 1;
    $GLOBALS['cat_info'][7]['disable_comments'] = 1;
    $panel = renderEntry()['panel'];
    expect($panel)->toContain('<span class="entry-tile-label">Подкатегорий</span><span class="entry-tile-value">1</span>')
        ->toContain('<p class="entry-verdict">Есть ограничения</p>')
        ->toContain('<li>Скрыта с главной</li>')
        ->toContain('<li>Комментарии отключены</li>');
});

it('words ages with Russian plural forms', function (int $seconds, string $expected): void {
    expect(\iCooLER\DLEToolbar\Components\Entry\Format::ago(time() - $seconds))->toBe($expected);
})->with([
    [10, 'только что'], [5 * 60, '5 мин назад'], [3 * 3600, '3 ч назад'], [86400 + 5, '1 день назад'],
    [2 * 86400 + 5, '2 дня назад'], [5 * 86400 + 5, '5 дней назад'], [11 * 86400 + 5, '11 дней назад'],
    [21 * 86400 + 5, '21 день назад'], [112 * 86400 + 5, '112 дней назад'],
]);

it('supports hero layout variant with badges, grid and collapsible details', function (): void {
    $tiles = [['Просмотры', '1 234'], ['Комментарии', '12']];
    $cards = [
        Dashboard::card('Публикация', 'Опубликована', 'success', [['Автор', 'admin']]),
        Dashboard::card('Редактирование', 'Редактировалась', 'warning', [['Кто', 'moder'], ['Причина', 'правка']]),
        Dashboard::card('Доступ', 'Ограничен', 'warning', [], ['Нужен пароль']),
    ];
    $html = Dashboard::renderLayout('b', $tiles, $cards);
    expect($html)->toContain('entry-dash--hero')
        ->toContain('class="entry-hero"')
        ->toContain('data-tone="success">Опубликована</span>')
        ->toContain('class="entry-hero-grid"')
        ->toContain('Просмотры')
        ->toContain('entry-collapse')
        ->toContain('Доступ: Ограничен');
});

it('supports timeline layout variant with stat strip and audit items', function (): void {
    $tiles = [['Просмотры', '1 234']];
    $cards = [
        Dashboard::card('Публикация', 'Опубликована', 'success', [['Автор', 'admin']]),
        Dashboard::card('Редактирование', 'Редактировалась', 'neutral', [['Кто', 'moder'], ['Причина', 'правка']]),
    ];
    $html = Dashboard::renderLayout('c', $tiles, $cards);
    expect($html)->toContain('entry-dash--timeline')
        ->toContain('class="entry-timeline-strip"')
        ->toContain('class="entry-timeline-item" data-tone="success"')
        ->toContain('class="entry-timeline-callout"')
        ->toContain('Причина правки:');
});
