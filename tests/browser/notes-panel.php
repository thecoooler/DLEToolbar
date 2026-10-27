<?php

spl_autoload_register(static function (string $class): void {
    $prefix = 'iCooLER\\DLEToolbar\\';
    if (strpos($class, $prefix) === 0) {
        require dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$user_group = [1 => ['group_name' => 'Администраторы', 'allow_admin' => 1]];

$preferences = \iCooLER\DLEToolbar\Toolbar\ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['queries', 'notes']]]);
$ref = new ReflectionProperty($preferences, 'loaded');
$ref->setValue(null, $preferences);
$config = ['skin' => 'Default', 'http_home_url' => '/', 'version_id' => $_GET['dle_version'] ?? '18.0'];
$dle_login_hash = 'browser-test-token';
$db = new class {
    public int $query_num = 0;
    public array $query_list = [];
};
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Заметки: проверка интерфейса</title></head>
<body>
<button id="outside">Вне панели</button>
<?= (new \iCooLER\DLEToolbar\Toolbar\DLEToolbar())->render() ?>
</body></html>
