<?php

use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;

use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
});

it('round trips all sections through MySQL with types, Unicode and stable row IDs', function (): void {
    $preferences = ToolbarPreferences::fromConfig([
        'access' => ['groups' => [2, 3]],
        'toolbar' => ['enabled' => ['queries']],
        'components' => ['queries' => ['slow_threshold_ms' => 2.5, 'fold_fast' => false]],
        'custom' => ['text' => "Привет ' мир \\ \n", 'flag' => true, 'empty' => [], 'null' => null],
    ]);
    $preferences->save();
    ToolbarPreferences::resetRequestCache();
    expect(ToolbarPreferences::load()->toConfig())->toEqual($preferences->toConfig());

    $native = $GLOBALS['dletoolbar_native_db'];
    $before = $native->super_query('SELECT id, setting_key, updated_at FROM `' . PREFIX . '_dletoolbar_settings` ORDER BY id', true);
    ToolbarPreferences::fromRequest(['access' => ['groups' => [1]]], $preferences)->save();
    $after = $native->super_query('SELECT id, setting_key, updated_at FROM `' . PREFIX . '_dletoolbar_settings` ORDER BY id', true);
    expect(array_column($after, 'id'))->toBe(array_column($before, 'id'))
        ->and(array_column($after, 'setting_key'))->toBe(array_column($before, 'setting_key'))
        ->and(array_column($after, 'updated_at'))->not->toContain(null, '');
});

it('loads only once and holds a request snapshot until the next request', function (): void {
    ToolbarPreferences::fromConfig(['access' => ['groups' => [2]]])->save();
    ToolbarPreferences::resetRequestCache();
    $GLOBALS['db']->queries = [];
    $snapshot = ToolbarPreferences::load();
    $GLOBALS['dletoolbar_native_db']->query('UPDATE `' . PREFIX . '_dletoolbar_settings` SET setting_value = \'{"groups":[3]}\' WHERE setting_key = \'access\'');
    expect(ToolbarPreferences::load())->toBe($snapshot)
        ->and(ToolbarPreferences::load()->allowedGroups())->toBe([2])
        ->and($GLOBALS['db']->queries)->toHaveCount(1);
    ToolbarPreferences::resetRequestCache();
    expect(ToolbarPreferences::load()->allowedGroups())->toBe([3]);
});

it('skips the settings query for users, guests and missing groups', function (): void {
    ToolbarPreferences::fromConfig(['access' => ['groups' => [1, 2]]])->save();
    ToolbarPreferences::resetRequestCache();
    $GLOBALS['db']->queries = [];
    $toolbar = new DLEToolbar();

    foreach ([4, '5', 0, 7] as $group) {
        $GLOBALS['member_id'] = ['user_group' => $group];
        $toolbar->init();
        expect($toolbar->isHasAccess())->toBeFalse();
    }
    expect($GLOBALS['db']->queries)->toBe([]);

    $GLOBALS['member_id'] = ['user_group' => '2'];
    expect($toolbar->isHasAccess())->toBeTrue()
        ->and($GLOBALS['db']->queries)->toHaveCount(1);
});

it('keeps stored settings and the request snapshot when the write fails', function (): void {
    $old = ToolbarPreferences::fromConfig(['access' => ['groups' => [2]], 'toolbar' => ['enabled' => ['time']]]);
    $old->save();
    $new = ToolbarPreferences::fromRequest(['access' => ['groups' => [3]], 'toolbar' => ['enabled' => ['queries']]], $old);
    $GLOBALS['db']->failQuery = 'INSERT';
    expect(fn() => $new->save())->toThrow(RuntimeException::class, 'Failed to save toolbar settings.')
        ->and(ToolbarPreferences::load())->toBe($old);
    ToolbarPreferences::resetRequestCache();
    expect(ToolbarPreferences::load()->toConfig())->toBe($old->toConfig());
});

it('shows a failed save in the admin page without a success notice and keeps stored values', function (): void {
    $this->expectErrorLog();
    $old = ToolbarPreferences::fromConfig(['access' => ['groups' => [2]]]);
    $old->save();
    $GLOBALS['db']->failQuery = 'INSERT';
    $previousPost = $_POST;
    $previousSession = $_SESSION;
    $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['user_hash' => $GLOBALS['dle_login_hash'], 'access' => ['groups' => [3]], 'toolbar' => ['enabled' => ['time']]];
    $_SESSION['dletoolbar_settings_notice'] = 'Старое уведомление';
    $GLOBALS['js_array'] = [];
    ob_start();
    try {
        include ENGINE_DIR . '/inc/dletoolbar.php';
        $html = ob_get_contents();
        expect(http_response_code())->toBe(500)
            ->and($html)->toContain('Не удалось загрузить или сохранить настройки панели.')
            ->not->toContain('Настройки панели сохранены.', 'Старое уведомление')
            ->and(isset($_SESSION['dletoolbar_settings_notice']))->toBeFalse();
        ToolbarPreferences::resetRequestCache();
        expect(ToolbarPreferences::load()->toConfig())->toBe($old->toConfig());
    } finally {
        ob_end_clean();
        $_POST = $previousPost;
        $_SESSION = $previousSession;
        if ($previousMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $previousMethod;
        }
        http_response_code(200);
    }
});

it('throws when the settings query fails', function (): void {
    $GLOBALS['db']->failQuery = 'SELECT';
    expect(fn() => ToolbarPreferences::load())->toThrow(RuntimeException::class, 'Failed to load toolbar settings.');
});

it('throws when stored JSON is invalid', function (): void {
    ToolbarPreferences::fromConfig([])->save();
    $GLOBALS['dletoolbar_native_db']->query('UPDATE `' . PREFIX . '_dletoolbar_settings` SET setting_value = \'broken\' WHERE setting_key = \'access\'');
    ToolbarPreferences::resetRequestCache();
    expect(fn() => ToolbarPreferences::load())->toThrow(JsonException::class);
});
