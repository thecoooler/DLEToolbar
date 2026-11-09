<?php

use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\Components\Debug\DebugLog;
use iCooLER\DLEToolbar\Components\Files\Files;
use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;

use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    DebugLog::reset();
});

afterEach(function (): void {
    DebugLog::reset();
});

it('records sequential milestones and attributes each new file only to its first milestone', function (): void {
    $directory = sys_get_temp_dir() . '/dletoolbar-files-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $child = $directory . '/child.php';
    $parent = $directory . '/parent.php';
    file_put_contents($child, '<?php $executions++;');
    file_put_contents($parent, '<?php include __DIR__ . "/child.php";');
    $executions = 0;
    try {
        DebugLog::start();
        include $parent;
        DebugLog::checkpoint('Подключение parent.php');
        include $parent;
        DebugLog::checkpoint('Повторное подключение');
        DebugLog::finish();
        $snapshot = DebugLog::snapshot();
        expect($executions)->toBe(2)
            ->and($snapshot['entries'][0]['message'])->toBe('Начало замеров')
            ->and($snapshot['entries'][1]['files'])->toBe([$parent, $child])
            ->and($snapshot['entries'][2]['files'])->toBe([])
            ->and($snapshot['entries'][3]['message'])->toBe('Вывод панели');
        $end = 0.0;
        foreach ($snapshot['entries'] as $entry) {
            expect($entry['time'])->toBeGreaterThanOrEqual($end)
                ->and($entry['gap'])->toBeGreaterThanOrEqual(0.0);
            $end = $entry['time'];
        }
        DebugLog::finish();
        DebugLog::checkpoint('После вывода');
        expect(DebugLog::snapshot())->toBe($snapshot);
    } finally {
        unlink($parent);
        unlink($child);
        rmdir($directory);
    }
});

it('starts collecting only for a visible component and an authorized toolbar', function (bool $visible, int $group): void {
    $preferences = ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => $visible ? ['files'] : []]]);
    $preferences->save();
    $GLOBALS['member_id']['user_group'] = $group;
    $toolbar = new DLEToolbar();
    $toolbar->init();
    DebugLog::checkpoint('Контрольная точка');
    $entries = DebugLog::snapshot()['entries'];
    expect(count($entries))->toBe($visible && $group === 1 ? 2 : 0);
})->with([[true, 1], [false, 1], [true, 4], [true, 2]]);

it('renders escaped executed paths and cache badges in the unified timeline', function (): void {
    $file = sys_get_temp_dir() . '/dletoolbar-<file>"-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, '<?php return 1;');
    try {
        $registry = new ComponentRegistry();
        $preferences = ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['files']]]);
        $preferences->save();
        (new DLEToolbar())->init();
        include $file;
        DebugLog::checkpoint('Этап <test>');
        DebugLog::finish();
        $snapshot = DebugLog::snapshot();
        $count = $snapshot['totalFiles'];
        $rendered = $registry->render($preferences);
        expect($rendered['controls'])->toContain('data-component="files"')
            ->toContain('<span class="metric-value">' . $count . '</span>')
            ->and($rendered['panels'])->toContain('Лог и файлы')
            ->toContain('Этап &lt;test&gt;')
            ->toContain(htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            ->not->toContain($file)
            ->toContain('не из кэша')
            ->toContain('files-details', 'files-toggle')
            ->and($rendered['scripts'])->toContain('createPopup(');
        expect((new Files($preferences->componentParameters('files')))->render()['panel'])->toBe($rendered['panels']);
    } finally {
        unlink($file);
    }
});

it('closes main.php before rendering the toolbar and keeps saved visibility unchanged', function (): void {
    ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['files']]])->save();
    $toolbar = new DLEToolbar();
    $toolbar->init();
    $html = $toolbar->render();
    expect(DebugLog::snapshot()['entries'])->toHaveCount(2)
        ->and($html)->toContain('Вывод панели')
        ->and(ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['time']]])->visibleItems())->toBe(['time']);
});

it('starts automatic collection after request components for either toolbar order', function (array $order): void {
    ToolbarPreferences::fromConfig([
        'toolbar' => ['items' => $order, 'enabled' => ['files', 'queries']],
        'components' => ['queries' => ['trace_sources' => true]],
    ])->save();
    $previousDb = $GLOBALS['db'];
    $GLOBALS['db'] = $GLOBALS['dletoolbar_native_db'];
    try {
        $toolbar = new DLEToolbar();
        $toolbar->init();
        $snapshot = DebugLog::snapshot();
        expect($snapshot['entries'])->toHaveCount(1)
            ->and($snapshot['entries'][0]['message'])->toBe('Начало замеров')
            ->and(implode('\n', $snapshot['entries'][0]['files']))->toContain('Components/Queries/QueryLog.php')
            ->and($GLOBALS['db']->query_list)->toBeInstanceOf(\iCooLER\DLEToolbar\Components\Queries\QueryLog::class);
    } finally {
        $GLOBALS['dletoolbar_native_db']->query_list = [];
        $GLOBALS['db'] = $previousDb;
    }
})->with([[['files', 'queries']], [['queries', 'files']]]);
