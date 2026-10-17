<?php

use iCooLER\DLEToolbar\AdminUI\ToolbarSettingsPage;
use iCooLER\DLEToolbar\Components\Link\Link;
use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
});

it('keeps link instances independent in order, visibility and parameters', function (): void {
    $a = 'custom_0000000000000001';
    $b = 'custom_0000000000000002';
    $preferences = ToolbarPreferences::fromRequest([
        'instances' => [$a => 'link', $b => 'link', 'response' => 'link', 'custom_0000000000000003' => 'unknown'],
        'toolbar' => ['items' => [$b, 'time', $a], 'enabled' => [$b, 'time']],
        'components' => [$a => ['url' => '/admin.php', 'scope' => 'all', 'icon' => 'settings'],
            $b => ['url' => 'https://example.test/stats?a=1&b=2', 'scope' => 'all', 'icon' => 'chart']],
    ]);
    expect($preferences->componentInstances())->toBe([$a => 'link', $b => 'link'])
        ->and($preferences->visibleItems())->toBe([$b, 'time'])
        ->and(array_slice($preferences->itemOrder(), 0, 3))->toBe([$b, 'time', $a])
        ->and($preferences->componentParameters($a))->toBe(['name' => '', 'url' => '/admin.php', 'target' => '_self', 'scope' => 'all', 'icon' => 'settings'])
        ->and($preferences->componentParameters($b))->toBe(['name' => '', 'url' => 'https://example.test/stats?a=1&b=2', 'target' => '_self', 'scope' => 'all', 'icon' => 'chart']);

    $components = [
        $a => new Link($preferences->componentParameters($a)),
        $b => new Link($preferences->componentParameters($b)),
    ];
    expect($components[$a]->render()['control'])->toContain('href="/admin.php"')
        ->and($components[$b]->render()['control'])->toContain('href="https://example.test/stats?a=1&amp;b=2"')
        ->not->toContain('href="/admin.php"');
});

it('can remove one or all link instances without restoring their previous parameters', function (): void {
    $a = 'custom_0000000000000001';
    $b = 'custom_0000000000000002';
    $current = ToolbarPreferences::fromConfig(['instances' => [$a => 'link', $b => 'link'],
        'components' => [$a => ['url' => '/a'], $b => ['url' => '/b']]]);
    $updated = ToolbarPreferences::fromRequest(['instances' => [$b => 'link'],
        'toolbar' => ['items' => [$a, $b], 'enabled' => [$a, $b]]], $current);
    expect($updated->componentInstances())->toBe([$b => 'link'])
        ->and($updated->visibleItems())->toBe([$b])
        ->and($updated->toConfig()['components'])->not->toHaveKey($a)
        ->and($updated->componentParameters($b)['url'])->toBe('/b');
    $empty = ToolbarPreferences::fromRequest(['instances' => ''], $updated);
    expect($empty->componentInstances())->toBe([])
        ->and($empty->toConfig()['components'])->not->toHaveKey($b)
        ->and($empty->itemOrder())->not->toContain($a, $b);
});

it('rejects unsupported or malformed link URLs and icon names', function (mixed $url): void {
    $id = 'custom_0000000000000001';
    $preferences = ToolbarPreferences::fromRequest(['instances' => [$id => 'link'],
        'components' => [$id => ['url' => $url, 'icon' => '<svg onload="alert(1)">']]]);
    expect($preferences->componentParameters($id))->toBe(['name' => '', 'url' => '', 'target' => '_self', 'scope' => 'all', 'icon' => 'link']);
    $link = new Link($preferences->componentParameters($id));
    expect($link->render())->toBeNull();
})->with(['javascript:alert(1)', '//example.test/', '/\\example.test/', "https://example.test/\npath", 'data:text/html,test', ['invalid']]);

it('escapes relative link attributes', function (): void {
    $id = 'custom_0000000000000001';
    $preferences = ToolbarPreferences::fromRequest(['instances' => [$id => 'link'],
        'components' => [$id => ['url' => '/news?value="quoted"&x=1', 'icon' => 'home']]]);
    $link = new Link($preferences->componentParameters($id));
    expect($link->render()['control'])->toContain('href="/news?value=&quot;quoted&quot;&amp;x=1"')
        ->toContain('aria-label="/news?value=&quot;quoted&quot;&amp;x=1"');
});

it('uses each link name in the component list, modal and toolbar tooltip', function (): void {
    $id = 'custom_0000000000000001';
    $preferences = ToolbarPreferences::fromRequest(['instances' => [$id => 'link'],
        'components' => [$id => ['name' => '  Отчёт <MySQL> "1"  ', 'url' => '/reports', 'target' => '_blank']]]);
    expect($preferences->componentParameters($id)['name'])->toBe('Отчёт <MySQL> "1"');
    $html = (new ToolbarSettingsPage($preferences, '?mod=dletoolbar'))->render();
    expect($html)->toContain('class="text-semibold dlt-component-label">Отчёт &lt;MySQL&gt; &quot;1&quot;</div>')
        ->toContain('id="dlt-settings-title-' . $id . '">Отчёт &lt;MySQL&gt; &quot;1&quot;</span>')
        ->toContain('name="components[' . $id . '][name]"')
        ->toContain('<option value="_blank" selected>В новой вкладке</option>');
    $link = new Link($preferences->componentParameters($id));
    expect($link->render()['control'])->toContain('target="_blank" rel="noopener"')
        ->toContain('aria-label="Отчёт &lt;MySQL&gt; &quot;1&quot;"');
});

it('renders the link icon parameter as an icon list instead of a select', function (): void {
    $id = 'custom_0000000000000001';
    $preferences = ToolbarPreferences::fromRequest(['instances' => [$id => 'link'],
        'components' => [$id => ['url' => '/reports', 'icon' => 'home']]]);
    $html = (new ToolbarSettingsPage($preferences, '?mod=dletoolbar'))->render();
    expect($html)->toContain('class="dlt-icon-list" role="radiogroup" aria-label="Иконка"')
        ->toContain('<input type="radio" id="dlt-parameter-' . $id . '-icon-home" name="components[' . $id . '][icon]" value="home" checked>')
        ->toContain('<input type="radio" id="dlt-parameter-' . $id . '-icon-link" name="components[' . $id . '][icon]" value="link">')
        ->toContain('<svg viewBox="0 0 24 24" aria-hidden="true">')
        ->toContain('<span class="dlt-icon-label">Домой</span>')
        ->toContain('value="kinopoisk"')
        ->toContain('class="fill"')
        ->not->toContain('<select class="uniform form-control" id="dlt-parameter-' . $id . '-icon"');
});

it('renders brand icons with fill styling in the toolbar control', function (string $icon): void {
    $id = 'custom_0000000000000001';
    $preferences = ToolbarPreferences::fromRequest(['instances' => [$id => 'link'],
        'components' => [$id => ['url' => '/reports', 'icon' => $icon]]]);
    expect($preferences->componentParameters($id)['icon'])->toBe($icon);
    $link = new Link($preferences->componentParameters($id));
    expect($link->render()['control'])->toContain('<svg viewBox=')->toContain('class="fill"');
})->with(['google', 'yandex', 'shikimori', 'imdb']);

it('opens links in the selected tab and defaults unknown targets to the current tab', function (mixed $target, string $expected): void {
    $id = 'custom_0000000000000001';
    $preferences = ToolbarPreferences::fromRequest(['instances' => [$id => 'link'],
        'components' => [$id => ['url' => '/reports', 'target' => $target]]]);
    $link = new Link($preferences->componentParameters($id));
    $control = $link->render()['control'];
    expect($control)->toContain('target="' . $expected . '"');
    if ($expected === '_blank') expect($control)->toContain('rel="noopener"');
    else expect($control)->not->toContain('rel=');
})->with([['_self', '_self'], ['_blank', '_blank'], ['unsafe" onclick="alert(1)', '_self'], [['invalid'], '_self']]);

it('renders each saved link with its own settings modal and removal control', function (): void {
    $a = 'custom_0000000000000001';
    $b = 'custom_0000000000000002';
    $preferences = ToolbarPreferences::fromConfig(['instances' => [$a => 'link', $b => 'link'],
        'components' => [$a => ['url' => '/a'], $b => ['url' => '/b']]]);
    $html = (new ToolbarSettingsPage($preferences, '?mod=dletoolbar'))->render();
    expect($html)->toContain('data-add-component="link"')
        ->toContain('data-component-template="link"')
        ->toContain('name="instances[' . $a . ']" value="link"')
        ->toContain('name="instances[' . $b . ']" value="link"')
        ->toContain('data-target="#dlt-settings-' . $a . '"')
        ->toContain('data-target="#dlt-settings-' . $b . '"')
        ->toContain('data-remove-component="' . $a . '"')
        ->toContain('name="components[' . $b . '][url]" value="/b"');
});

it('persists multiple links and renders only the enabled instance', function (): void {
    $a = 'custom_0000000000000001';
    $b = 'custom_0000000000000002';
    ToolbarPreferences::fromRequest(['instances' => [$a => 'link', $b => 'link'],
        'toolbar' => ['enabled' => [$b]],
        'components' => [$a => ['url' => '/a'], $b => ['url' => '/b', 'icon' => 'code']]])->save();
    ToolbarPreferences::resetRequestCache();
    expect(ToolbarPreferences::load()->componentInstances())->toBe([$a => 'link', $b => 'link']);
    $html = (new DLEToolbar())->render();
    expect($html)->toContain('href="/b"')->not->toContain('href="/a"');
    ToolbarPreferences::fromRequest(['instances' => '', 'toolbar' => ['enabled' => ['time']]], ToolbarPreferences::load())->save();
    ToolbarPreferences::resetRequestCache();
    expect(ToolbarPreferences::load()->componentInstances())->toBe([])
        ->and(ToolbarPreferences::load()->toConfig()['components'])->not->toHaveKeys([$a, $b]);
});

it('uses current link instances and parameters on every registry render', function (): void {
    $a = 'custom_0000000000000001';
    $b = 'custom_0000000000000002';
    $preferences = ToolbarPreferences::fromConfig([
        'instances' => [$a => 'link', $b => 'link'],
        'toolbar' => ['items' => [$b, $a], 'enabled' => [$a, $b]],
        'components' => [$a => ['url' => '/first'], $b => ['url' => '/second']],
    ]);
    $registry = new ComponentRegistry();
    $controls = $registry->render($preferences)['controls'];
    expect($controls)->toContain('href="/first"', 'href="/second"')
        ->and(strpos($controls, 'href="/second"'))->toBeLessThan(strpos($controls, 'href="/first"'));

    $updated = ToolbarPreferences::fromRequest([
        'instances' => [$b => 'link'],
        'toolbar' => ['items' => [$b], 'enabled' => [$b]],
        'components' => [$b => ['url' => '/updated', 'target' => '_blank']],
    ], $preferences);
    expect($registry->render($updated)['controls'])->toContain('href="/updated"', 'target="_blank"')
        ->not->toContain('href="/first"', 'href="/second"');
    expect($registry->render(ToolbarPreferences::fromRequest(['instances' => ''], $updated))['controls'])->toBe('');
});

it('rejects invalid admin links atomically and renders all submitted settings', function ($url, string $message): void {
    $a = 'custom_0000000000000001';
    $b = 'custom_0000000000000002';
    $removed = 'custom_0000000000000003';
    $old = ToolbarPreferences::fromConfig(['instances' => [$a => 'link', $removed => 'link'],
        'components' => [$a => ['url' => '/old'], $removed => ['url' => '/removed']]]);
    $old->save();
    $GLOBALS['db']->queries = [];
    $previousPost = $_POST;
    $previousSession = $_SESSION;
    $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $previousScripts = $GLOBALS['js_array'] ?? [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['user_hash' => $GLOBALS['dle_login_hash'],
        'instances' => [$a => 'link', $b => 'link'],
        'access' => ['groups' => [2, 3]],
        'toolbar' => ['items' => [$b, 'queries', $a], 'enabled' => [$b, 'queries']],
        'components' => [$a => ['name' => 'Отчёт <SQL>', 'url' => $url, 'target' => '_blank', 'scope' => 'news', 'icon' => 'chart'],
            $b => ['name' => '', 'url' => '', 'icon' => 'home'],
            'queries' => ['slow_threshold_ms' => '7.5', 'fold_fast' => '0']]];
    $_SESSION['dletoolbar_settings_notice'] = 'Старое уведомление';
    $GLOBALS['js_array'] = [];
    ob_start();
    try {
        include ENGINE_DIR . '/inc/dletoolbar.php';
        $html = (string) ob_get_contents();
        expect(http_response_code())->toBe(422)
            ->and($GLOBALS['db']->queries)->toBe([])
            ->and(isset($_SESSION['dletoolbar_settings_notice']))->toBeFalse()
            ->and($html)->toContain('class="alert alert-danger"', 'Настройки не сохранены.',
                'Отчёт &lt;SQL&gt;: ' . $message, 'Кастомная ссылка: Заполните поле «Ссылка».',
                'class="form-group has-error"', 'aria-invalid="true"',
                'aria-describedby="dlt-parameter-' . $a . '-url-error"',
                'class="help-block" id="dlt-parameter-' . $a . '-url-error"',
                'name="components[' . $a . '][url]" aria-invalid="true"',
                'value="' . htmlspecialchars(is_string($url) ? $url : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" required',
                '<option value="2" selected>', '<option value="3" selected>',
                '<option value="_blank" selected>', '<option value="news" selected>',
                'name="components[queries][slow_threshold_ms]" value="7.5"',
                'name="components[' . $a . '][icon]" value="chart" checked',
                'name="components[' . $b . '][icon]" value="home" checked')
            ->not->toContain('alert-success', 'Старое уведомление', 'data-component-id="' . $removed . '"');
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $order = $xpath->query('//tbody[@id="dlt-component-list"]/tr/@data-id');
        expect([$order[0]->value, $order[1]->value, $order[2]->value])->toBe([$b, 'queries', $a]);
        expect($xpath->query('//input[@name="toolbar[enabled][]" and @value="' . $a . '" and @checked]')->length)->toBe(0)
            ->and($xpath->query('//input[@name="toolbar[enabled][]" and @value="' . $b . '" and @checked]')->length)->toBe(1)
            ->and($xpath->query('//input[@name="components[queries][fold_fast]" and @type="checkbox" and @checked]')->length)->toBe(0);
        ToolbarPreferences::resetRequestCache();
        expect(ToolbarPreferences::load()->toConfig())->toBe($old->toConfig());
    } finally {
        ob_end_clean();
        $_POST = $previousPost;
        $_SESSION = $previousSession;
        $GLOBALS['js_array'] = $previousScripts;
        if ($previousMethod === null) unset($_SERVER['REQUEST_METHOD']);
        else $_SERVER['REQUEST_METHOD'] = $previousMethod;
        http_response_code(200);
    }
})->with([
    ['  example.com/page?x="<&  ', 'Недопустимое значение поля «Ссылка».'],
    ['', 'Заполните поле «Ссылка».'],
    ['   ', 'Заполните поле «Ссылка».'],
    [['invalid'], 'Заполните поле «Ссылка».'],
]);

it('keeps legacy empty links free of server errors and requires URLs in creation templates', function (): void {
    $id = 'custom_0000000000000001';
    $preferences = ToolbarPreferences::fromConfig(['instances' => [$id => 'link'], 'components' => [$id => ['url' => 'example.com']]]);
    $html = (new ToolbarSettingsPage($preferences, '?mod=dletoolbar'))->render();
    expect($preferences->parameterErrors())->toBe([])
        ->and($preferences->componentParameters($id)['url'])->toBe('')
        ->and($html)->toContain('name="components[' . $id . '][url]" value="" required',
            'name="components[__COMPONENT_ID__][url]" value="" required')
        ->not->toContain('aria-invalid="true"', 'alert alert-danger');
});

it('accepts valid link URLs and field templates', function (string $url): void {
    $id = 'custom_0000000000000001';
    $preferences = ToolbarPreferences::fromRequest(['instances' => [$id => 'link'],
        'components' => [$id => ['url' => $url]]]);
    expect($preferences->parameterErrors())->toBe([]);
    $preferences->save();
    ToolbarPreferences::resetRequestCache();
    expect(ToolbarPreferences::load()->componentParameters($id)['url'])->toBe($url);
})->with(['/reports', 'https://example.com/page', '[xfvalue_url]',
    '[xfgiven_slug]https://example.com/[xfvalue_slug][/xfgiven_slug]']);

it('validates the saved values of links that the request leaves unchanged', function (): void {
    $valid = 'custom_0000000000000001';
    $legacy = 'custom_0000000000000002';
    $current = ToolbarPreferences::fromConfig(['instances' => [$valid => 'link', $legacy => 'link'],
        'components' => [$valid => ['url' => '/kept']]]);

    $errors = ToolbarPreferences::fromRequest(['instances' => [$valid => 'link', $legacy => 'link']], $current)->parameterErrors();
    expect(array_keys($errors))->toBe([$legacy])
        ->and($errors[$legacy]['url'])->toBe(['message' => 'Заполните поле «Ссылка».', 'value' => '']);
});
