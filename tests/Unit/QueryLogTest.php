<?php

use iCooLER\DLEToolbar\Components\Queries\QueryLog;
use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db'] = $GLOBALS['dletoolbar_native_db'];
});

afterEach(function (): void {
    // The test runner keeps one native db for every test, so give it back a plain log.
    $GLOBALS['db'] = $GLOBALS['dletoolbar_native_db'];
    $GLOBALS['db']->query_list = [];
});

function lastQuerySource(): array
{
    $entries = iterator_to_array($GLOBALS['db']->query_list);
    return $entries[array_key_last($entries)];
}

function testFileSource(int $line): string
{
    return substr(str_replace('\\', '/', __FILE__), strlen(ROOT_DIR) + 1) . ':' . $line;
}

it('keeps the db object and earlier entries while tracing query, super_query and multi_query call sites', function (): void {
    $db = $GLOBALS['db'];
    $db->query_list = [];
    $db->query('SELECT 41 AS value');
    $earlier = $db->query_list;
    $count = $db->query_num;

    QueryLog::install();
    expect($GLOBALS['db'])->toBe($db)
        ->and($db->query_list)->toBeInstanceOf(QueryLog::class)
        ->and(iterator_to_array($db->query_list))->toBe($earlier)
        ->and($db->get_row()['value'])->toBe('41');

    $line = __LINE__ + 1;
    $result = $db->query('SELECT 42 AS value');
    expect(lastQuerySource()['source'])->toBe(testFileSource($line))
        ->and(lastQuerySource()['num'])->toBe(1)
        ->and($db->get_row($result)['value'])->toBe('42');

    $line = __LINE__ + 1;
    $row = $db->super_query('SELECT 43 AS value');
    expect($row['value'])->toBe('43')
        ->and(lastQuerySource()['source'])->toBe(testFileSource($line));

    $line = __LINE__ + 1;
    $db->multi_query('SELECT 44; SELECT 45');
    expect(lastQuerySource()['source'])->toBe(testFileSource($line))
        ->and($db->query_num)->toBe($count + 3)
        ->and(count($db->query_list))->toBe(4);

    $logged = iterator_to_array($db->query_list);
    $db->query('SELECT 46', true, false);
    $db->multi_query('SELECT 47; SELECT 48', true, false);
    expect(iterator_to_array($db->query_list))->toBe($logged);
});

it('lets callers change entry fields in place like the original array', function (): void {
    $GLOBALS['db']->query_list = [['query' => 'SELECT secret', 'time' => .001, 'num' => 0]];
    QueryLog::install();
    $GLOBALS['db']->query_list[0]['query'] = 'SELECT redacted';
    expect($GLOBALS['db']->query_list[0]['query'])->toBe('SELECT redacted');
});

it('reports the source line of eval-encoded files without the eval suffix', function (): void {
    QueryLog::install();
    eval("\n\$GLOBALS['db']->query('SELECT 1');");
    expect(lastQuerySource()['source'])->toBe(testFileSource(2));
});

it('reports the source line of files encoded with nested evals', function (): void {
    QueryLog::install();
    eval('eval("\n\\$GLOBALS[\'db\']->query(\'SELECT 1\');");');
    expect(lastQuerySource()['source'])->toBe(testFileSource(2));
});

it('maps a plugin cache call site back to its original file', function (string $code): void {
    $fixture = ENGINE_DIR . '/cache/system/plugins/dletoolbar-tracing-test.php';
    $plugins = new ReflectionProperty(DLEPlugins::class, 'plugins');
    $plugins->setAccessible(true);
    $before = $plugins->getValue();
    $map = $before;
    $map['engine/modules/tracing-test/run.php'] = basename($fixture);
    $plugins->setValue(null, $map);
    file_put_contents($fixture, "<?php\n" . $code . "\n");
    try {
        QueryLog::install();
        require $fixture;
        expect(lastQuerySource()['source'])->toBe('engine/modules/tracing-test/run.php:2');
    } finally {
        $plugins->setValue(null, $before);
        unlink($fixture);
    }
})->with([
    'plain' => ["\$GLOBALS['db']->super_query('SELECT 1 AS value');"],
    'nested evals' => [<<<'PHP'
        eval('eval("\\n\\$GLOBALS[\'db\']->super_query(\'SELECT 1 AS value\');");');
        PHP],
]);

it('installs tracing only for an allowed visible SQL panel with the experimental option on DLE 14 or newer', function (): void {
    $db = $GLOBALS['db'];
    $db->query_list = [];
    $toolbar = new DLEToolbar();
    $GLOBALS['member_id']['user_group'] = 5;
    $toolbar->init();
    expect($db->query_list)->toBeArray();

    $GLOBALS['member_id']['user_group'] = 1;
    ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['time']]])->save();
    $toolbar->init();
    expect($db->query_list)->toBeArray();

    // Source tracing is experimental and stays off until enabled in the SQL panel settings.
    ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['queries']]])->save();
    $toolbar->init();
    expect($db->query_list)->toBeArray();

    ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
        'components' => ['queries' => ['trace_sources' => true]],
    ])->save();
    $GLOBALS['config']['version_id'] = '13.3';
    $toolbar->init();
    expect($db->query_list)->toBeArray();

    $GLOBALS['config']['version_id'] = '14.0';
    $toolbar->init();
    expect($db->query_list)->toBeInstanceOf(QueryLog::class)
        ->and($GLOBALS['db'])->toBe($db);
});
