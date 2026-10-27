<?php

// Run: php -S 127.0.0.1:8001 -t tests/browser
// Then: node tests/browser/run.mjs (requires Playwright with Chromium installed)
// Open: http://127.0.0.1:8001/queries-panel.php
// The page reports PASS/FAIL and exposes window.queriesPanelTestResult.

define('ROOT_DIR', dirname(__DIR__, 2));
define('ENGINE_DIR', sys_get_temp_dir() . '/dletoolbar-browser-' . getmypid());

spl_autoload_register(static function (string $class): void {
    $prefix = 'iCooLER\\DLEToolbar\\';
    if (strpos($class, $prefix) === 0) {
        require dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function get_folder_list(string $path): array
{
    return ['Default' => ['name' => 'Default'], 'Studio' => ['name' => 'Studio']];
}

setcookie('dle_skin', 'Default', 0, '/', '', false, true);
$dle_login_hash = 'browser-test-user-hash';
$config = ['skin' => 'Default', 'version_id' => '18.0'];
$member_id = ['user_group' => 1];
$user_group = [1 => ['group_name' => 'Администраторы', 'allow_admin' => 1]];
$dle_module = 'main';
$news_found = false;
$Timer = new class {
    public function get(): float { return .125; }
};
define('PREFIX', 'dle');
$db = new class {
    public int $query_num = 9;
    public array $query_list = [
        ['query' => 'Connection with MySQL Server', 'time' => .001, 'num' => 0],
        ['query' => 'SELECT fast_a', 'time' => .0002, 'num' => 1, 'source' => 'engine/modules/shop/run.php:12'],
        ['query' => 'SELECT fast_b', 'time' => .0003, 'num' => 2, 'source' => 'engine/modules/shop/list.php:20'],
        ['query' => 'SELECT "<value>"' . "\nFROM dle_post", 'time' => .002, 'num' => 3, 'source' => 'engine/Vendor/Plugin/run.php:34'],
        ['query' => 'SELECT fast_c', 'time' => .0004, 'num' => 4],
        ['query' => 'SELECT fast_d', 'time' => .0005, 'num' => 5],
        ['query' => 'UPDATE slow', 'time' => .020, 'num' => 6],
    ];
    public function query($sql, $showError = true) { return true; }
    private bool $settingsRead = false;
    public function get_row($result = null) {
        if ($this->settingsRead) return null;
        $this->settingsRead = true;
        return ['setting_key' => 'toolbar', 'setting_value' => json_encode(['enabled' => ['time', 'queries', 'skin', 'files']])];
    }
    public function free($result = null) {}
};
http_response_code(200);
$toolbar = new \iCooLER\DLEToolbar\Toolbar\DLEToolbar();
$toolbar->init();
\iCooLER\DLEToolbar\Components\Files\FileTimeline::checkpoint('Подготовка DLE после проверки доступа');
\iCooLER\DLEToolbar\Components\Files\FileTimeline::checkpoint('engine/engine.php — подключение');
\iCooLER\DLEToolbar\Components\Files\FileTimeline::checkpoint('Блоки страницы после engine.php');
?>
<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>SQL panel browser regression</title></head>
<body>
<button id="outside" type="button">Вне панели</button>
<pre id="result">RUNNING</pre>
<?= $toolbar->render() ?>
<script src="queries-panel.js"></script>
</body>
</html>
