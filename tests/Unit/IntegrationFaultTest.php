<?php

use iCooLER\DLEToolbar\Components\Entry\PageEntry;
use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

require_once __DIR__ . '/../Support/RandomBytesFault.php';

/** @return list<string> the `replace` blocks of one patched DLE file, in manifest order */
function integrationPatches(string $file): array
{
    return (require __DIR__ . '/../Support/integration-patches.php')[$file];
}

/** Runs a patch exactly as DLE would, with the given local variables; returns them afterwards. */
function runIntegrationPatch(string $file, int $index, array $vars): array
{
    // require_once would skip main.php on a second run and hide the fault.
    $code = str_replace('require_once', 'require', integrationPatches($file)[$index]);
    return (static function (string $__code, array $__vars): array {
        extract($__vars);
        eval($__code);
        unset($__code, $__vars);
        return get_defined_vars();
    })($code, $vars);
}

/** Runs all patches in the order DLE reaches them during one request. */
function runRequest(array $vars, ?string $xfields = ''): array
{
    $logged = [];
    $previous = ini_set('error_log', $log = tempnam(sys_get_temp_dir(), 'dletoolbar'));
    try {
        $vars = runIntegrationPatch('engine/init.php', 0, $vars);
        $vars = runIntegrationPatch('engine/init.php', 1, $vars);
        $vars['row'] = ['xfields' => $xfields];
        $vars['GLOBALS_toolbar'] = $vars['DLEToolbar'];
        $GLOBALS['DLEToolbar'] = $vars['DLEToolbar'];
        $vars = runIntegrationPatch('engine/modules/show.full.php', 0, $vars);
        $vars['ajax'] = '';
        $vars = runIntegrationPatch('engine/modules/main.php', 0, $vars);
        $GLOBALS['tpl'] = (object) ['result' => ['main' => '<main>' . $vars['ajax'] . '</main>']];
        $vars = runIntegrationPatch('engine/modules/main.php', 1, $vars);
        $vars['log'] = file_get_contents($log);
        $vars['html'] = $GLOBALS['tpl']->result['main'];
        return $vars;
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
        unlink($log);
        unset($GLOBALS['DLEToolbar'], $GLOBALS['tpl'], $GLOBALS['dletoolbar_fail_random']);
    }
}

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['xfields'] = [];
    $GLOBALS['lang'] = ['xfield_xyes' => 'Да', 'xfield_xno' => 'Нет'];
});

it('extracts every integration patch from the patch list', function (): void {
    expect(integrationPatches('engine/init.php'))->toHaveCount(2)
        ->and(integrationPatches('engine/modules/show.full.php'))->toHaveCount(1)
        ->and(integrationPatches('engine/modules/profile.php'))->toHaveCount(1)
        ->and(integrationPatches('engine/modules/main.php'))->toHaveCount(2);
});

it('keeps the request alive on a healthy run', function (): void {
    $state = runRequest([]);
    expect($state['log'])->toBe('')
        ->and($state['html'])->toContain('data-dle-toolbar')
        ->and($state['log'])->toBe('');
});

it('survives a failure of the toolbar constructor', function (): void {
    $GLOBALS['dletoolbar_fail_random'] = true;
    $state = runRequest([]);
    expect($state['DLEToolbar'])->toBeNull()
        ->and($state['log'])->toContain('[DLEToolbar] bootstrap')
        ->and($state['html'])->toBe('<main></main>');
});

it('survives a settings table that cannot be read', function (): void {
    $GLOBALS['db']->failQuery = 'SELECT setting_key';
    $state = runRequest([]);
    expect($state['DLEToolbar'])->toBeNull()
        ->and($state['log'])->toContain('[DLEToolbar] initialization')
        ->and($state['html'])->toBe('<main></main>');
});

it('survives corrupted JSON in the settings table', function (): void {
    $GLOBALS['dletoolbar_native_db']->query('INSERT INTO `' . PREFIX . '_dletoolbar_settings` (setting_key, setting_value) VALUES (\'access\', \'{broken\')');
    $state = runRequest([]);
    expect($state['DLEToolbar'])->toBeNull()
        ->and($state['log'])->toContain('JsonException')
        ->and($state['html'])->toBe('<main></main>');
});

it('survives a failure while capturing news fields', function (): void {
    // DLE hands a NULL xfields column to the hook for news without additional fields.
    $state = runRequest([], null);
    expect($state['log'])->toContain('[DLEToolbar]');
});

it('captures the viewed news and profile rows for the entry panel', function (): void {
    $state = runRequest([]);
    expect(PageEntry::news())->toBe(['xfields' => ''])
        ->and($state['log'])->toBe('');
    // main.php releases the toolbar after output, so the profile hook gets its own instance.
    runIntegrationPatch('engine/modules/profile.php', 0, ['DLEToolbar' => new DLEToolbar(), 'row' => ['user_id' => 1, 'name' => 'admin']]);
    expect(PageEntry::user())->toBe(['user_id' => 1, 'name' => 'admin']);
});

it('survives a failure while capturing the profile row', function (): void {
    $previous = ini_set('error_log', $log = tempnam(sys_get_temp_dir(), 'dletoolbar'));
    try {
        // A profile hook can only fail inside the toolbar; a missing row stands in for that failure.
        runIntegrationPatch('engine/modules/profile.php', 0, ['DLEToolbar' => new DLEToolbar(), 'row' => null]);
        expect(file_get_contents($log))->toContain('[DLEToolbar] user profile')
            ->and(PageEntry::user())->toBeNull();
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
        unlink($log);
    }
});

it('captures the profile after DLE normalizes its ban', function (int $expiry, string $expected): void {
    // Native profile.php fragment before the banned-status branch (DLE 18–21).
    // The trial's profile.php is encoded, so keep this fragment alongside the hook fixture.
    $code = <<<'PHP'
if ($row['banned'] == 'yes' AND $banned_info['users_id'][$row['user_id']]['date'] AND $banned_info['users_id'][$row['user_id']]['date'] < $_TIME ) $row['banned'] = '';
PHP;
    $code .= "\n" . integrationPatches('engine/modules/profile.php')[0];
    $row = ['user_id' => 12, 'name' => 'tester', 'banned' => 'yes', 'xfields' => 'city|PUBLIC_CITY'];
    (static function (string $code, array $row, int $expiry): void {
        $DLEToolbar = new DLEToolbar();
        $_TIME = 1700000000;
        $banned_info = ['users_id' => [12 => ['date' => $expiry]]];
        eval($code);
    })($code, $row, $expiry);
    expect(PageEntry::user())->toBe(array_replace($row, ['banned' => $expected]));
})->with([
    'expired' => [1699999999, ''],
    'active' => [1700000001, 'yes'],
    'permanent' => [0, 'yes'],
    'expires now' => [1700000000, 'yes'],
]);

it('survives a failure while rendering a component', function (): void {
    $GLOBALS['Timer'] = null;
    $state = runRequest([]);
    expect($state['log'])->toContain('[DLEToolbar] output')
        ->and($state['html'])->not->toContain('data-dle-toolbar');
});

it('leaves no stale marker in the page when rendering fails', function (): void {
    $GLOBALS['Timer'] = null;
    $state = runRequest([]);
    expect($state['html'])->not->toContain('<!-- DLEToolbar');
});
