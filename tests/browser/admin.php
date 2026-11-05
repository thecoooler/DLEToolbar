<?php

// Browser fixture: serves the real embedded DLEToolbar settings form so the
// toolbar settings iframe loads a form instead of a 404 page.
// Run: php -S 127.0.0.1:8001 -t tests/browser

define('ENGINE_DIR', sys_get_temp_dir() . '/dletoolbar-browser-' . getmypid() . '/engine');
define('ROOT_DIR', dirname(__DIR__, 2));
define('DATALIFEENGINE', true);
define('LOGGED_IN', true);
define('PREFIX', 'dle');

class DLEPlugins
{
    public static function Check(string $path): string
    {
        $prefix = ENGINE_DIR . '/iCooLER/DLEToolbar/';
        if (strpos($path, $prefix) === 0) {
            return ROOT_DIR . '/src/' . substr($path, strlen($prefix));
        }

        return $path;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'iCooLER\\DLEToolbar\\';
    if (strpos($class, $prefix) === 0) {
        require ROOT_DIR . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function echoheader(string $title, string $subtitle): void
{
    echo '<header>' . $title . $subtitle . '</header>';
    echo <<<'HTML'
<script>
window.jQuery = window.$ = function () {
    const api = {
        chosen() { return api; },
        selectpicker() { return api; },
        on() { return api; },
        one() { return api; },
        find() { return api; },
        modal() { return api; },
        trigger() { return api; },
        destroy() { return api; },
    };
    return api;
};
window.Switchery = function () {};
window.Sortable = function () {};
</script>
HTML;
}

function echofooter(): void
{
    echo '</footer>';
}

$dle_login_hash = 'browser-test-user-hash';
$js_array = [];
session_save_path(sys_get_temp_dir());
session_start();
$config = [
    'http_home_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/',
    'admin_path' => 'admin.php',
    'skin' => 'Default',
    'version_id' => '18.0',
];
$member_id = ['user_group' => 1];
$user_group = [1 => ['group_name' => 'Администраторы', 'allow_admin' => 1]];
$db = new class {
    private int $read = 0;

    public function query($sql, $showError = true)
    {
        return true;
    }

    public function get_row($result = null)
    {
        $rows = [
            ['setting_key' => 'toolbar', 'setting_value' => json_encode([
                'items' => ['response', 'time', 'queries', 'skin', 'cache', 'clock', 'entry'],
                'enabled' => ['time', 'queries', 'skin'],
            ])],
        ];

        return $rows[$this->read++] ?? null;
    }

    public function free($result = null) {}

    public function safesql(string $value): string
    {
        return $value;
    }
};

require ROOT_DIR . '/src/admin.php';
