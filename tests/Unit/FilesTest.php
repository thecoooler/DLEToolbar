<?php

use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\Components\Files\Files;
use iCooLER\DLEToolbar\Components\Files\FileTimeline;
use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
});

it('records sequential stages and attributes each new file only to its first stage', function (): void {
    $directory = sys_get_temp_dir() . '/dletoolbar-files-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $child = $directory . '/child.php';
    $parent = $directory . '/parent.php';
    file_put_contents($child, '<?php $executions++;');
    file_put_contents($parent, '<?php include __DIR__ . "/child.php";');
    $executions = 0;
    try {
        FileTimeline::start();
        $before = FileTimeline::snapshot()['before'];
        include $parent;
        FileTimeline::checkpoint('Подключение parent.php');
        include $parent;
        FileTimeline::checkpoint('Повторное подключение');
        FileTimeline::finish();
        $snapshot = FileTimeline::snapshot();
        expect($executions)->toBe(2)
            ->and($snapshot['before'])->toBe($before)
            ->and($snapshot['stages'][0]['files'])->toBe([$parent, $child])
            ->and($snapshot['stages'][1]['files'])->toBe([])
            ->and($snapshot['stages'][2]['label'])->toBe('Сборка main.tpl до вывода панели');
        $end = 0.0;
        foreach ($snapshot['stages'] as $stage) {
            expect($stage['start'])->toBeGreaterThanOrEqual($end)
                ->and($stage['duration'])->toBeGreaterThanOrEqual(0.0);
            $end = $stage['start'] + $stage['duration'];
        }
        FileTimeline::finish();
        FileTimeline::checkpoint('После вывода');
        expect(FileTimeline::snapshot())->toBe($snapshot);
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
    FileTimeline::checkpoint('Контрольная точка');
    $stages = FileTimeline::snapshot()['stages'];
    expect(count($stages))->toBe($visible && $group === 1 ? 1 : 0);
})->with([[true, 1], [false, 1], [true, 4], [true, 2]]);

it('renders escaped executed paths and stage timings without inventing individual file durations', function (): void {
    $file = sys_get_temp_dir() . '/dletoolbar-<file>"-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, '<?php return 1;');
    try {
        $registry = new ComponentRegistry();
        $preferences = ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['files']]]);
        $registry->initialize($preferences);
        include $file;
        FileTimeline::checkpoint('Этап <test>');
        FileTimeline::finish();
        $snapshot = FileTimeline::snapshot();
        $count = count($snapshot['before']) + array_sum(array_map(static fn (array $stage): int => count($stage['files']), $snapshot['stages']));
        $rendered = $registry->render($preferences);
        expect($rendered['controls'])->toContain('data-component="files"')
            ->toContain('<span class="metric-value">' . $count . '</span>')
            ->and($rendered['panels'])->toContain('До начала замеров')
            ->toContain('Этап &lt;test&gt;')
            ->toContain(htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            ->not->toContain($file)
            ->toContain('Время измерено для этапа целиком')
            ->toContain('Новых файлов нет.')
            ->and($rendered['scripts'])->toContain('createPopup(');
        expect((new Files())->render()['panel'])->toBe($rendered['panels']);
    } finally {
        unlink($file);
    }
});

it('closes the last stage before rendering the toolbar and keeps saved visibility unchanged', function (): void {
    ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['files']]])->save();
    $toolbar = new DLEToolbar();
    $toolbar->init();
    $html = $toolbar->render();
    expect(FileTimeline::snapshot()['stages'])->toHaveCount(1)
        ->and($html)->toContain('Сборка main.tpl до вывода панели')
        ->and(ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['time']]])->visibleItems())->toBe(['time']);
});
