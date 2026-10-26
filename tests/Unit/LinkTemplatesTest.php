<?php

use iCooLER\DLEToolbar\Components\Link\Link;
use iCooLER\DLEToolbar\Components\Link\NewsFields;
use iCooLER\DLEToolbar\Components\Link\UrlTemplate;

use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
});

it('restricts links to the selected page scope', function (string $scope, string $module, bool $found, bool $visible): void {
    $GLOBALS['dle_module'] = $module;
    $GLOBALS['news_found'] = $found;
    $link = new Link(['name' => '', 'target' => '_self', 'url' => '/destination', 'icon' => 'link', 'scope' => $scope]);
    expect($link->render() !== null)->toBe($visible);
})->with([
    ['main', 'main', false, true], ['main', 'cat', false, false],
    ['category', 'cat', false, true], ['category', 'showfull', true, false],
    ['news', 'showfull', true, true], ['news', 'showfull', false, false],
    ['news', 'main', false, false], ['all', 'search', false, true],
]);

it('preserves valid field templates when normalizing settings', function (string $template): void {
    expect(UrlTemplate::normalize($template))->toBe($template);
})->with([
    '/search?q=[xfvalue_code]',
    'https://example.test/[xfvalue_code]',
    '[xfvalue_url]',
    '[xfgiven_url][xfvalue_url][/xfgiven_url]',
    '/search?[xfgiven_code]code=[xfvalue_code][/xfgiven_code]',
]);

it('rejects malformed or unsafe templates', function (string $template): void {
    expect(UrlTemplate::normalize($template))->toBe('');
})->with([
    'value', '[xfgiven_code]value[/xfgiven_code]',
    'javascript:[xfvalue_code]', '//[xfvalue_host]',
    '/[xfgiven_code]test', '/[/xfgiven_code]',
    '/[xfgiven_code]test[/xfgiven_other]', '/[xfvalue_]',
    '/[xfgiven_code][xfgiven_other]test[/xfgiven_code][/xfgiven_other]',
]);

it('encodes embedded fields and decodes stored HTML entities', function (): void {
    expect(UrlTemplate::resolve('/search?q=[xfvalue_code]', true, [
        'code' => ['value' => 'Тест &amp; a/b?x="y"', 'given' => true],
    ]))->toBe('/search?q=' . rawurlencode('Тест & a/b?x="y"'));
});

it('hides links with malformed saved conditions even when their fields are available', function (string $template): void {
    expect(UrlTemplate::resolve($template, true, [
        'url' => ['value' => 'https://example.test/', 'given' => true],
    ]))->toBe('');
})->with([
    'unclosed condition' => ['[xfgiven_url][xfvalue_url]'],
    'mismatched condition' => ['[xfgiven_url][xfvalue_url][/xfgiven_other]'],
]);

it('resolves nested given blocks and omits empty optional fields', function (): void {
    $fields = ['code' => ['value' => 'abc', 'given' => true], 'other' => ['value' => '', 'given' => false]];
    expect(UrlTemplate::resolve('/search?[xfgiven_code]q=[xfvalue_code][xfgiven_other]&extra=[xfvalue_other][/xfgiven_other][/xfgiven_code]', true, $fields))
        ->toBe('/search?q=abc')
        ->and(UrlTemplate::resolve('[xfgiven_other]/[xfvalue_other][/xfgiven_other]', true, $fields))->toBe('');
});

it('resolves repeated and case-insensitive nested conditions', function (): void {
    $fields = ['code' => ['value' => 'abc', 'given' => true]];
    expect(UrlTemplate::resolve('[XFGIVEN_CODE]/[xfgiven_code][XFVALUE_CODE][/xfgiven_code][/XFGIVEN_CODE]', true, $fields))
        ->toBe('/abc')
        ->and(UrlTemplate::resolve('/[xfgiven_code][xfvalue_code][/xfgiven_code]/[xfgiven_code][xfvalue_code][/xfgiven_code]', true, $fields))->toBe('/abc/abc');
});

it('validates full URLs supplied by fields after substitution', function (): void {
    $template = '[xfgiven_url][xfvalue_url][/xfgiven_url]';
    expect(UrlTemplate::resolve($template, true, ['url' => ['value' => 'https://example.test/?a=1&amp;b=2', 'given' => true]]))
        ->toBe('https://example.test/?a=1&b=2')
        ->and(UrlTemplate::resolve($template, true, ['url' => ['value' => 'javascript:alert(1)', 'given' => true]]))->toBe('');
});

it('hides links with missing fields or outside a full news page', function (): void {
    $fields = ['code' => ['value' => 'abc', 'given' => true]];
    expect(UrlTemplate::resolve('/[xfvalue_missing]', true, $fields))->toBe('')
        ->and(UrlTemplate::resolve('/[xfvalue_code]', false, $fields))->toBe('')
        ->and(UrlTemplate::resolve('/plain', false, $fields))->toBe('/plain');
});

it('captures only permitted fields and uses native yes-or-no given semantics', function (): void {
    $GLOBALS['lang'] = ['xfield_xyes' => 'Да', 'xfield_xno' => 'Нет'];
    $GLOBALS['xfields'] = [
        [0 => 'code', 3 => 'text', 20 => ''],
        [0 => 'private', 3 => 'text', 20 => '2,3'],
        [0 => 'enabled', 3 => 'yesorno', 20 => '1,2'],
        [0 => 'disabled', 3 => 'yesorno', 20 => ''],
        [0 => 'zero', 3 => 'text', 20 => ''],
    ];
    NewsFields::capture('code|abc||private|secret||enabled|1||disabled|0||zero|0');
    expect(NewsFields::values())->not->toHaveKey('private')
        ->and(NewsFields::values()['enabled'])->toBe(['value' => 'Да', 'given' => true])
        ->and(NewsFields::values()['disabled'])->toBe(['value' => 'Нет', 'given' => false])
        ->and(NewsFields::values()['zero'])->toBe(['value' => '0', 'given' => true])
        ->and(UrlTemplate::resolve('[xfgiven_zero]/[xfvalue_zero][/xfgiven_zero]', true, NewsFields::values()))->toBe('/0')
        ->and(UrlTemplate::resolve('[xfgiven_disabled]/disabled[/xfgiven_disabled]', true, NewsFields::values()))->toBe('');
    $GLOBALS['dle_module'] = 'showfull';
    $GLOBALS['news_found'] = true;
    $link = new Link(['name' => '', 'target' => '_self', 'url' => '/[xfvalue_code]', 'icon' => 'link', 'scope' => 'news']);
    expect($link->render()['control'])->toContain('href="/abc"');
    NewsFields::capture('code| a&#124;b ');
    expect($link->render()['control'])->toContain('href="/%20a%7Cb%20"');
    NewsFields::capture('');
    expect($link->render())->toBeNull();
    $GLOBALS['xfields'] = [];
    NewsFields::capture('');
});

it('does not interpret field values as another template', function (): void {
    $url = 'https://example.test/?q=[xfvalue_other]';
    expect(UrlTemplate::resolve('[xfvalue_url]', true, [
        'url' => ['value' => $url, 'given' => true],
    ]))->toBe($url);
});

it('uses DLE 19+ field definitions, permissions and zero-value semantics', function (): void {
    require_once \DLEPlugins::Check(ENGINE_DIR . '/classes/xfields.class.php');
    $previous = \DLEXFields::$fields;
    try {
        $GLOBALS['config']['version_id'] = '21.0';
        $GLOBALS['lang'] = ['xfield_xyes' => 'Да', 'xfield_xno' => 'Нет'];
        \DLEXFields::$fields = ['fields' => [
            'code' => ['name' => 'code', 'type' => 'text', 'allow_view_usergroups' => ''],
            'private' => ['name' => 'private', 'type' => 'text', 'allow_view_usergroups' => '2,3'],
            'zero' => ['name' => 'zero', 'type' => 'text', 'allow_view_usergroups' => ''],
            'enabled' => ['name' => 'enabled', 'type' => 'yesorno', 'allow_view_usergroups' => '1,2'],
        ]];
        NewsFields::capture('code|abc||private|secret||zero|0||enabled|1');
        expect(NewsFields::values())->not->toHaveKey('private')
            ->and(NewsFields::values()['zero'])->toBe(['value' => '0', 'given' => false])
            ->and(NewsFields::values()['enabled'])->toBe(['value' => 'Да', 'given' => true])
            ->and(UrlTemplate::resolve('[xfgiven_zero]/[xfvalue_zero][/xfgiven_zero]', true, NewsFields::values()))->toBe('')
            ->and(UrlTemplate::resolve('/[xfvalue_code]', true, NewsFields::values()))->toBe('/abc');
        \DLEXFields::$fields = ['fields' => []];
        NewsFields::capture('');
        expect(NewsFields::values())->toBe([]);
    } finally {
        \DLEXFields::$fields = $previous;
    }
});

it('uses native given semantics for dynamic fields in DLE 21', function (string $raw, string $type, bool $given): void {
    require_once \DLEPlugins::Check(ENGINE_DIR . '/classes/xfields.class.php');
    $previous = \DLEXFields::$fields;
    try {
        $GLOBALS['config']['version_id'] = '21.0';
        \DLEXFields::$fields = ['fields' => [
            'code' => ['name' => 'code', 'type' => $type, 'allow_view_usergroups' => '', 'dynamic' => 1],
        ]];
        $data = \DLEXFields::xfieldsdataload($raw);
        NewsFields::capture($raw);
        expect(NewsFields::values()['code'])->toBe(['value' => $data['code'] ?? '', 'given' => $given])
            ->and(UrlTemplate::resolve('[xfgiven_code]/filled[/xfgiven_code]', true, NewsFields::values()))
            ->toBe($given ? '/filled' : '');
    } finally {
        \DLEXFields::$fields = $previous;
    }
})->with([
    'missing field' => ['', 'text', false],
    'empty repetitions' => ['code| ||code|', 'text', false],
    'whitespace repetitions' => ["code| \t ||code| \n ", 'text', false],
    'empty HTML' => ['code|<p><br></p>', 'textarea', false],
    'empty HTML repetitions' => ['code| <p><br></p> ||code|<p><br></p>', 'textarea', false],
    'text after an empty item' => ['code| ||code|abc', 'text', true],
    'text before an empty item' => ['code|abc||code|', 'text', true],
    'nonempty HTML' => ['code|<p><br></p>||code|<p>abc</p>', 'textarea', true],
    'single zero' => ['code|0', 'text', false],
    'zero among repetitions' => ['code| ||code|0', 'text', true],
]);
