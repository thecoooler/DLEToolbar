<?php

use iCooLER\DLEToolbar\Components\Queries\PluginMap;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    if (function_exists('set_vars')) {
        set_vars('dletoolbar_plugins', '');
    }
    \Closure::bind(function (): void {
        self::$inserts = [];
        self::$created = [];
        self::$ranges = [];
        self::$loaded = false;
    }, null, PluginMap::class)();
});

it('reports created files and resolves them without requiring a built file', function (): void {
    \Closure::bind(function (): void {
        self::$created = ['engine/modules/custom_plugin.php' => 'CustomPlugin'];
        self::$loaded = true;
    }, null, PluginMap::class)();

    expect(PluginMap::hasData())->toBeTrue()
        ->and(PluginMap::isCreated('engine/modules/custom_plugin.php'))->toBeTrue()
        ->and(PluginMap::isCreated('ENGINE/modules/custom_plugin.php'))->toBeTrue()
        ->and(PluginMap::isCreated('/engine\\modules\\custom_plugin.php'))->toBeTrue()
        ->and(PluginMap::isCreated('engine/modules/other.php'))->toBeFalse()
        ->and(PluginMap::resolve('engine/modules/custom_plugin.php', 42, null))->toBe('CustomPlugin')
        ->and(PluginMap::resolve('engine/modules/custom_plugin.php', 1, '/some/built/path.php'))->toBe('CustomPlugin')
        ->and(PluginMap::resolve('engine/modules/other.php', 10, null))->toBeNull();
});

it('resolves plugin names by matching inserted code ranges in built files', function (): void {
    $tempBuilt = tempnam(sys_get_temp_dir(), 'dlt_built_');
    assert($tempBuilt !== false);

    $code = "line 1\nline 2\n\$inserted = true;\nline 4\nline 5\n";
    file_put_contents($tempBuilt, $code);

    $insert = "\$inserted = true;";
    \Closure::bind(function () use ($insert): void {
        self::$inserts = [
            'engine/modules/main.php' => [
                ['plugin' => 'TestInsertPlugin', 'code' => $insert],
            ],
        ];
        self::$loaded = true;
    }, null, PluginMap::class)();

    try {
        expect(PluginMap::resolve('engine/modules/main.php', 3, $tempBuilt))->toBe('TestInsertPlugin')
            ->and(PluginMap::resolve('engine/modules/main.php', 1, $tempBuilt))->toBeNull()
            ->and(PluginMap::resolve('engine/modules/main.php', 5, $tempBuilt))->toBeNull()
            ->and(PluginMap::resolve('engine/modules/other.php', 3, $tempBuilt))->toBeNull()
            ->and(PluginMap::resolve('engine/modules/main.php', 3, null))->toBeNull();
    } finally {
        if (file_exists($tempBuilt)) unlink($tempBuilt);
    }
});

it('prefers narrower overlapping ranges when multiple plugins patch the same file', function (): void {
    $tempBuilt = tempnam(sys_get_temp_dir(), 'dlt_built_');
    assert($tempBuilt !== false);

    $code = "1\n2\nwide_start\nnarrow_target\nwide_end\n6\n7\n";
    file_put_contents($tempBuilt, $code);

    \Closure::bind(function (): void {
        self::$inserts = [
            'engine/modules/main.php' => [
                ['plugin' => 'WidePlugin', 'code' => "wide_start\nnarrow_target\nwide_end"],
                ['plugin' => 'NarrowPlugin', 'code' => "narrow_target"],
            ],
        ];
        self::$loaded = true;
    }, null, PluginMap::class)();

    try {
        expect(PluginMap::resolve('engine/modules/main.php', 3, $tempBuilt))->toBe('WidePlugin')
            ->and(PluginMap::resolve('engine/modules/main.php', 4, $tempBuilt))->toBe('NarrowPlugin')
            ->and(PluginMap::resolve('engine/modules/main.php', 5, $tempBuilt))->toBe('WidePlugin')
            ->and(PluginMap::resolve('engine/modules/main.php', 2, $tempBuilt))->toBeNull();
    } finally {
        if (file_exists($tempBuilt)) unlink($tempBuilt);
    }
});

it('loads plugin definitions from cache when valid', function (): void {
    if (!function_exists('set_vars')) {
        expect(true)->toBeTrue();
        return;
    }
    $cachedInserts = ['engine/modules/cached.php' => [['plugin' => 'CachedPlugin', 'code' => 'function cached_fn() {}']]];
    $cachedCreated = ['engine/modules/cached_created.php' => 'CachedCreated'];

    set_vars('dletoolbar_plugins', [
        'time' => time(),
        'inserts' => $cachedInserts,
        'created' => $cachedCreated,
    ]);

    PluginMap::load();

    expect(PluginMap::hasData())->toBeTrue()
        ->and(PluginMap::isCreated('engine/modules/cached_created.php'))->toBeTrue()
        ->and(PluginMap::resolve('engine/modules/cached_created.php', 1, null))->toBe('CachedCreated');
});

it('loads active plugins and files from the database and caches them', function (): void {
    $GLOBALS['dletoolbar_native_db']->query('CREATE TABLE IF NOT EXISTS `' . PREFIX . '_plugins` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(100) NOT NULL,
        `active` tinyint NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB');

    $GLOBALS['dletoolbar_native_db']->query('CREATE TABLE IF NOT EXISTS `' . PREFIX . '_plugins_files` (
        `id` int NOT NULL AUTO_INCREMENT,
        `plugin_id` int NOT NULL,
        `file` varchar(255) NOT NULL,
        `action` varchar(20) NOT NULL,
        `replacecode` mediumtext NOT NULL,
        `active` tinyint NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB');

    $GLOBALS['dletoolbar_native_db']->query('DELETE FROM `' . PREFIX . '_plugins_files`');
    $GLOBALS['dletoolbar_native_db']->query('DELETE FROM `' . PREFIX . '_plugins`');

    $GLOBALS['dletoolbar_native_db']->query("INSERT INTO `" . PREFIX . "_plugins` (`id`, `name`, `active`) VALUES (1, 'BannerPro', 1), (2, 'InactivePlugin', 0)");
    $GLOBALS['dletoolbar_native_db']->query("INSERT INTO `" . PREFIX . "_plugins_files` (`plugin_id`, `file`, `action`, `replacecode`, `active`) VALUES
        (1, 'engine/modules/banner.php', 'create', '', 1),
        (1, 'engine/modules/main.php', 'replace', '\$banner_active = true; // 12+ chars code', 1),
        (1, 'engine/modules/short.php', 'replace', 'short', 1),
        (2, 'engine/modules/inactive.php', 'create', '', 1)");

    PluginMap::load();

    expect(PluginMap::hasData())->toBeTrue()
        ->and(PluginMap::isCreated('engine/modules/banner.php'))->toBeTrue()
        ->and(PluginMap::isCreated('engine/modules/inactive.php'))->toBeFalse()
        ->and(PluginMap::resolve('engine/modules/banner.php', 5, null))->toBe('BannerPro');
});
