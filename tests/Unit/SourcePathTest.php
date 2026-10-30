<?php

use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\ComponentFramework\SourcePath;
use iCooLER\DLEToolbar\Components\Debug\DebugLog;
use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;

use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
});

it('resolves cache paths, eval frames and Windows separators using the current DLE plugin map', function (): void {
    $plugins = new ReflectionProperty(DLEPlugins::class, 'plugins');
    $plugins->setAccessible(true);
    $before = $plugins->getValue();
    $map = $before;
    $map['engine/modules/source.php'] = 'dletoolbar-source-test.php';
    $map[ROOT_DIR . '/engine/modules/other.php'] = 'dletoolbar-other-test.php';
    $plugins->setValue(null, $map);
    try {
        $cache = ENGINE_DIR . '/cache/system/plugins/';
        $windowsCache = str_replace('/', '\\', $cache);
        expect(SourcePath::display($cache . 'dletoolbar-source-test.php'))->toBe('engine/modules/source.php')
            ->and(SourcePath::display($windowsCache . 'dletoolbar-source-test.php'))->toBe('engine/modules/source.php')
            ->and(SourcePath::display($cache . "dletoolbar-source-test.php(4) : eval()'d code(9) : eval()'d code"))->toBe('engine/modules/source.php')
            ->and(SourcePath::display($cache . 'dletoolbar-other-test.php'))->toBe('engine/modules/other.php')
            ->and(SourcePath::display($cache . 'unknown.php'))->toBe('engine/cache/system/plugins/unknown.php')
            ->and(SourcePath::display(ROOT_DIR . '/engine/modules/plain.php'))->toBe('engine/modules/plain.php')
            ->and(SourcePath::display('/outside/site.php'))->toBe('/outside/site.php');
    } finally {
        $plugins->setValue(null, $before);
    }
});

it('displays original files and call sites while retaining physical files in the snapshot', function (): void {
    $plugins = new ReflectionProperty(DLEPlugins::class, 'plugins');
    $plugins->setAccessible(true);
    $before = $plugins->getValue();
    $suffix = bin2hex(random_bytes(8));
    $cache = ENGINE_DIR . '/cache/system/plugins/';
    $early = $cache . 'early-' . $suffix . '.php';
    $late = $cache . 'late-' . $suffix . '.php';
    $unknown = $cache . 'unknown-' . $suffix . '.php';
    $map = $before;
    $map['engine/modules/<early>.php'] = basename($early);
    $map['engine/modules/example/run.php'] = basename($late);
    $plugins->setValue(null, $map);
    file_put_contents($early, "<?php\n\\iCooLER\\DLEToolbar\\Components\\Debug\\DebugLog::add('Early mark');\n");
    file_put_contents($late, "<?php\n\\iCooLER\\DLEToolbar\\Components\\Debug\\DebugLog::add('Inside module');\n\\iCooLER\\DLEToolbar\\Components\\Debug\\DebugLog::checkpoint('Module loaded');\n");
    file_put_contents($unknown, '<?php return 1;');
    try {
        include $early;
        $preferences = ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['files']]]);
        $preferences->save();
        (new DLEToolbar())->init();
        include $late;
        include $unknown;
        DebugLog::finish();
        $snapshot = DebugLog::snapshot();
        $panel = (new ComponentRegistry())->render($preferences)['panels'];
        expect($snapshot['before'])->toContain($early)
            ->and($snapshot['stages'][0]['files'])->toContain($late)
            ->and($snapshot['stages'][1]['files'])->toContain($unknown)
            ->and($snapshot['entries'][0]['file'])->toBe('engine/modules/<early>.php')
            ->and($snapshot['entries'][0]['line'])->toBe(2)
            ->and($snapshot['entries'][2]['file'])->toBe('engine/modules/example/run.php')
            ->and($snapshot['entries'][2]['line'])->toBe(2)
            ->and($snapshot['entries'][3]['file'])->toBe('engine/modules/example/run.php')
            ->and($snapshot['entries'][3]['line'])->toBe(3)
            ->and($panel)->toContain('engine/modules/&lt;early&gt;.php', 'engine/modules/example/run.php:2', 'engine/modules/example/run.php:3', 'engine/cache/system/plugins/' . basename($unknown))
            ->not->toContain('engine/modules/<early>.php', basename($early), basename($late));
    } finally {
        $plugins->setValue(null, $before);
        unlink($early);
        unlink($late);
        unlink($unknown);
    }
});
