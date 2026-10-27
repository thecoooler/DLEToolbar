<?php

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
        $vars = runIntegrationPatch('engine/init.php', 2, $vars);
        $vars['row'] = ['xfields' => $xfields];
        $vars['GLOBALS_toolbar'] = $vars['DLEToolbar'];
        $GLOBALS['DLEToolbar'] = $vars['DLEToolbar'];
        $vars = runIntegrationPatch('engine/modules/show.full.php', 0, $vars);
        $vars = runIntegrationPatch('engine/init.php', 3, $vars);
        $vars = runIntegrationPatch('engine/init.php', 4, $vars);
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
    expect(integrationPatches('engine/init.php'))->toHaveCount(5)
        ->and(integrationPatches('engine/modules/show.full.php'))->toHaveCount(1)
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

/** Renders the admin page as DLE would include it; returns its output and the PHP log. */
function runAdminPage(string $method = 'GET', array $post = []): array
{
    $globals = [$_SERVER['REQUEST_METHOD'] ?? null, $_POST, $_SESSION ?? null];
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post;
    $_SESSION = [];
    $previous = ini_set('error_log', $log = tempnam(sys_get_temp_dir(), 'dletoolbar'));
    ob_start();
    try {
        require ENGINE_DIR . '/inc/dletoolbar.php';
        return ['html' => ob_get_contents(), 'log' => file_get_contents($log)];
    } finally {
        ob_end_clean();
        ini_set('error_log', $previous === false ? '' : $previous);
        unlink($log);
        [$_SERVER['REQUEST_METHOD'], $_POST, $_SESSION] = $globals;
    }
}

it('shows the cause of an admin page failure under a collapsed spoiler', function (): void {
    $GLOBALS['dletoolbar_native_db']->query('INSERT INTO `' . PREFIX . '_dletoolbar_settings` (setting_key, setting_value) VALUES (\'access\', \'{broken\')');
    $page = runAdminPage();
    expect(http_response_code())->toBe(500)
        ->and($page['log'])->toContain('[DLEToolbar] admin: JsonException')
        ->and($page['html'])->toContain('Не удалось загрузить или сохранить настройки панели.')
        ->and($page['html'])->toContain('<details class="mt-10"><summary>Подробности</summary>')
        ->and($page['html'])->not->toContain('<details open')
        ->and($page['html'])->toContain('JsonException: Syntax error')
        ->and($page['html'])->toContain('ToolbarPreferences.php:')
        ->and($page['html'])->toContain('#0 ')
        ->and($page['html'])->not->toContain(ROOT_DIR . DIRECTORY_SEPARATOR);
});

it('lists the previous exceptions of an admin page failure and escapes them', function (): void {
    $GLOBALS['db']->failQuery = 'SELECT setting_key';
    $GLOBALS['db']->failWith = new \RuntimeException('Outer <b>failure</b>', 0, new \LogicException('Inner "cause"'));
    $page = runAdminPage();
    expect($page['html'])->toContain('RuntimeException: Outer &lt;b&gt;failure&lt;/b&gt;')
        ->and($page['html'])->toContain("Предыдущее исключение:\nLogicException: Inner &quot;cause&quot;")
        ->and($page['html'])->not->toContain('<b>failure</b>')
        ->and(strpos($page['html'], 'RuntimeException'))->toBeLessThan(strpos($page['html'], 'LogicException'))
        ->and($page['log'])->toContain('Outer <b>failure</b>');
});

it('keeps the admin failure details when a message is not valid UTF-8', function (): void {
    $GLOBALS['db']->failQuery = 'SELECT setting_key';
    $GLOBALS['db']->failWith = new \RuntimeException("Broken \xFF byte");
    $page = runAdminPage();
    expect($page['html'])->toContain("RuntimeException: Broken \u{FFFD} byte")
        ->and($page['html'])->toContain('#0 ');
});

it('shows the cause of a failed settings save', function (): void {
    $GLOBALS['db']->failQuery = 'INSERT';
    $page = runAdminPage('POST', ['user_hash' => 'test-user-hash']);
    expect(http_response_code())->toBe(500)
        ->and($page['html'])->toContain('RuntimeException: Failed to save toolbar settings.');
});
