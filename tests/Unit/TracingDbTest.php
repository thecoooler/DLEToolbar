<?php

use iCooLER\DLEToolbar\Components\Queries\TracingDb;
use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;
use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db'] = $GLOBALS['dletoolbar_native_db'];
});

afterEach(function (): void {
    // Return ownership to the test runner's native db, including any private result state.
    $restore = Closure::bind(static function (db $tracing, db $native): void {
        foreach (get_object_vars($tracing) as $key => $value) $native->$key = $value;
        $tracing->db_id = null;
    }, null, db::class);
    if ($GLOBALS['db'] !== $GLOBALS['dletoolbar_native_db']) {
        $restore($GLOBALS['db'], $GLOBALS['dletoolbar_native_db']);
    }
    $GLOBALS['db'] = $GLOBALS['dletoolbar_native_db'];
});

it('transfers all native state and traces query, super_query and multi_query call sites', function (): void {
    $old = $GLOBALS['db'];
    $old->query('SELECT 41 AS value');
    $state = Closure::bind(static fn (db $database): array => get_object_vars($database), null, db::class);
    $before = $state($old);

    TracingDb::install();
    $tracing = $GLOBALS['db'];
    expect($tracing)->toBeInstanceOf(TracingDb::class)
        ->and($state($tracing))->toBe($before)
        ->and($state($old)['db_id'])->toBeNull()
        ->and($tracing->get_row()['value'])->toBe('41');
    // Exercise the actual old destructor, then use the same connection again.
    $old->__destruct();
    expect($state($tracing)['db_id']->thread_id)->toBe($before['db_id']->thread_id);

    $line = __LINE__ + 1;
    $result = $tracing->query('SELECT 42 AS value');
    expect($tracing->query_list[array_key_last($tracing->query_list)]['source'])->toBe(
        substr(str_replace('\\', '/', __FILE__), strlen(ROOT_DIR) + 1) . ':' . $line
    )->and($tracing->get_row($result)['value'])->toBe('42');

    $line = __LINE__ + 1;
    $row = $tracing->super_query('SELECT 43 AS value');
    expect($row['value'])->toBe('43')
        ->and($tracing->query_list[array_key_last($tracing->query_list)]['source'])->toBe(
            substr(str_replace('\\', '/', __FILE__), strlen(ROOT_DIR) + 1) . ':' . $line
        );

    $line = __LINE__ + 1;
    $tracing->multi_query('SELECT 44; SELECT 45');
    expect($tracing->query_list[array_key_last($tracing->query_list)]['source'])->toBe(
        substr(str_replace('\\', '/', __FILE__), strlen(ROOT_DIR) + 1) . ':' . $line
    )->and($tracing->query_num)->toBe($before['query_num'] + 3);

    $logged = $tracing->query_list;
    $tracing->query('SELECT 46', true, false);
    $tracing->multi_query('SELECT 47; SELECT 48', true, false);
    expect($tracing->query_list)->toBe($logged);
    TracingDb::install();
    expect($GLOBALS['db'])->toBe($tracing);
});

it('maps a plugin cache call site back to its original file', function (): void {
    $fixture = ENGINE_DIR . '/cache/system/plugins/dletoolbar-tracing-test.php';
    $plugins = new ReflectionProperty(DLEPlugins::class, 'plugins');
    $plugins->setAccessible(true);
    $before = $plugins->getValue();
    $map = $before;
    $map['engine/modules/tracing-test/run.php'] = basename($fixture);
    $plugins->setValue(null, $map);
    file_put_contents($fixture, "<?php\n\$GLOBALS['db']->super_query('SELECT 1 AS value');\n");
    try {
        TracingDb::install();
        require $fixture;
        $entry = $GLOBALS['db']->query_list[array_key_last($GLOBALS['db']->query_list)];
        expect($entry['source'])->toBe('engine/modules/tracing-test/run.php:2')
            ->and($entry['source_modified'])->toBeTrue();
    } finally {
        $plugins->setValue(null, $before);
        unlink($fixture);
    }
});

it('installs tracing only for an allowed visible SQL panel with the experimental option on DLE 14 or newer', function (): void {
    $native = $GLOBALS['db'];
    $toolbar = new DLEToolbar();
    $GLOBALS['member_id']['user_group'] = 5;
    $toolbar->init();
    expect($GLOBALS['db'])->toBe($native);

    $GLOBALS['member_id']['user_group'] = 1;
    ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['time']]])->save();
    $toolbar->init();
    expect($GLOBALS['db'])->toBe($native);

    // Source tracing is experimental and stays off until enabled in the SQL panel settings.
    ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['queries']]])->save();
    $toolbar->init();
    expect($GLOBALS['db'])->toBe($native);

    ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['queries']],
        'components' => ['queries' => ['trace_sources' => true]],
    ])->save();
    $GLOBALS['config']['version_id'] = '13.3';
    $toolbar->init();
    expect($GLOBALS['db'])->toBe($native);

    $GLOBALS['config']['version_id'] = '14.0';
    $toolbar->init();
    expect($GLOBALS['db'])->toBeInstanceOf(TracingDb::class);
});
