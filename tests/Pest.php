<?php

namespace iCooLER\DLEToolbar\Components\Skin;

function header(string $header, bool $replace = true, int $status = 0): void
{
    $GLOBALS['dletoolbar_skin_redirect'] = [$header, $status];
    throw new \RuntimeException('skin redirect');
}

/** @return array<string, array{name: string}> */
function get_folder_list(string $folder): array
{
    return $GLOBALS['dletoolbar_test_skins'];
}

namespace iCooLER\DLEToolbar;

require_once __DIR__ . '/Support/DleAdminFunctions.php';
require_once __DIR__ . '/Support/ToolbarTestDatabase.php';

define('NEWS_ID', 42);
// admin.php runs after DLE has authorized the admin panel.
define('LOGGED_IN', true);

function setupDleToolbarTestEnvironment(): void
{
    \iCooLER\DLEToolbar\Components\Files\FileTimeline::reset();
    $GLOBALS['dletoolbar_native_db'] ??= $GLOBALS['db'];
    $GLOBALS['dletoolbar_native_db']->query('DELETE FROM `' . PREFIX . '_dletoolbar_settings`');
    \iCooLER\DLEToolbar\Toolbar\ToolbarPreferences::resetRequestCache();
    $GLOBALS['config'] = [
        'http_home_url' => 'https://example.test/',
        'admin_path' => 'admin.php',
        'skin' => 'Default',
        'only_ssl' => false,
        'version_id' => '18.0',
    ];
    $GLOBALS['Timer'] = new class {
        public function get(): float { return .125; }
    };
    $GLOBALS['db'] = new ToolbarTestDatabase($GLOBALS['dletoolbar_native_db']);
    $GLOBALS['dle_module'] = 'main';
    $GLOBALS['news_found'] = false;
    $GLOBALS['member_id'] = ['user_group' => 1];
    $GLOBALS['user_group'] = [
        1 => ['group_name' => 'Администраторы', 'allow_admin' => 1, 'admin_editnews' => 1],
        2 => ['group_name' => 'Главные редакторы', 'allow_admin' => 1, 'admin_editnews' => 1],
        3 => ['group_name' => 'Журналисты', 'allow_admin' => 1, 'admin_editnews' => 0],
        4 => ['group_name' => 'Пользователи', 'allow_admin' => 0, 'admin_editnews' => 0],
        5 => ['group_name' => 'Гости', 'allow_admin' => 0, 'admin_editnews' => 0],
        6 => ['group_name' => 'VIP', 'allow_admin' => 0, 'admin_editnews' => 0],
    ];
    $GLOBALS['dle_login_hash'] = 'test-user-hash';
    $GLOBALS['dletoolbar_test_skins'] = ['Default' => ['name' => 'Default']];
    http_response_code(200);
}

afterEach(function (): void {
    $GLOBALS['dletoolbar_native_db']->query('DELETE FROM `' . PREFIX . '_dletoolbar_settings`');
    \iCooLER\DLEToolbar\Toolbar\ToolbarPreferences::resetRequestCache();
});
