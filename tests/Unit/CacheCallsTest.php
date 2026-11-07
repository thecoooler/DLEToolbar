<?php

use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\Components\CacheCalls\CacheCallLog;
use iCooLER\DLEToolbar\Components\Debug\DebugLog;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;

use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    DebugLog::reset();
    CacheCallLog::reset();
    $GLOBALS['config']['allow_cache'] = true;
    $GLOBALS['config']['cache_type'] = 0;
    $GLOBALS['config']['clear_cache'] = 0;
    $GLOBALS['_TIME'] = time();
    $GLOBALS['is_logged'] = false;
    $GLOBALS['member_id'] = ['user_group' => 1];
    unset($GLOBALS['dlefastcache']);
});

afterEach(function (): void {
    CacheCallLog::reset();
    DebugLog::reset();
    unset($GLOBALS['dlefastcache']);
});

function cacheCallsTestRead(string $prefix, $cacheId, bool $memberPrefix, callable $read)
{
    return CacheCallLog::read($prefix, $cacheId, $memberPrefix, $read);
}

function cacheCallsTestSource(int $line): string
{
    return substr(str_replace('\\', '/', __FILE__), strlen(ROOT_DIR) + 1) . ':' . $line;
}

function cacheCallsTestWrite(string $prefix, $cacheText, $cacheId, bool $memberPrefix, callable $write)
{
    return CacheCallLog::write($prefix, $cacheText, $cacheId, $memberPrefix, $write);
}

it('distinguishes a cache hit, a miss and an expired entry and keeps the call site', function (): void {
    $prefix = 'dletoolbar_cachecalls_' . bin2hex(random_bytes(4));
    $id = 'news-42';
    $key = $prefix . '_' . md5($id);
    $file = ENGINE_DIR . '/cache/' . $key . '.tmp';
    file_put_contents($file, 'cached');

    try {
        $line = __LINE__ + 1;
        expect(cacheCallsTestRead($prefix, $id, false, static fn(): string => 'cached'))->toBe('cached');
        expect(cacheCallsTestRead($prefix . '_missing', false, false, static fn() => false))->toBeFalse();
        expect(cacheCallsTestRead($prefix, $id, false, static fn() => false))->toBeFalse();

        $entries = CacheCallLog::entries();
        expect($entries)->toHaveCount(3)
            ->and($entries[0]['operation'])->toBe('read')
            ->and($entries[0]['key'])->toBe($key)
            ->and($entries[0]['status'])->toBe('hit')
            ->and($entries[0]['size'])->toBe(6)
            ->and($entries[0]['backend'])->toBe('files')
            ->and($entries[0]['member'])->toBeFalse()
            ->and($entries[0]['at'])->toBeGreaterThanOrEqual(0.0)
            ->and($entries[0]['duration'])->toBeGreaterThanOrEqual(0.0)
            ->and($entries[0]['source'])->toBe(cacheCallsTestSource($line))
            ->and($entries[1]['key'])->toBe($prefix . '_missing')
            ->and($entries[1]['status'])->toBe('miss')
            ->and($entries[1]['size'])->toBeNull()
            ->and($entries[2]['status'])->toBe('expired');
    } finally {
        unlink($file);
    }
});

it('builds the key from the prefix, hashed id and member group like DLE', function (): void {
    $prefix = 'dletoolbar_cachecalls_key';
    cacheCallsTestRead($prefix, false, false, static fn() => false);
    cacheCallsTestRead($prefix, '42', false, static fn() => false);
    $GLOBALS['is_logged'] = true;
    $GLOBALS['member_id'] = ['user_group' => 3];
    cacheCallsTestRead($prefix, '42', true, static fn() => false);
    $GLOBALS['is_logged'] = false;
    cacheCallsTestRead($prefix, '42', true, static fn() => false);

    expect(array_column(CacheCallLog::entries(), 'key'))->toBe([
        $prefix,
        $prefix . '_' . md5('42'),
        $prefix . '_' . md5('42') . '_3',
        $prefix . '_' . md5('42') . '_0',
    ]);
});

it('marks reads and writes as ignored when the cache is disabled', function (): void {
    $GLOBALS['config']['allow_cache'] = false;
    cacheCallsTestRead('dletoolbar_cachecalls_off', false, false, static fn() => false);
    cacheCallsTestWrite('dletoolbar_cachecalls_off', 'payload', false, false, static fn() => false);

    $entries = CacheCallLog::entries();
    expect($entries[0]['status'])->toBe('disabled')
        ->and($entries[0]['size'])->toBeNull()
        ->and($entries[1]['status'])->toBe('disabled')
        ->and($entries[1]['operation'])->toBe('write')
        ->and($entries[1]['size'])->toBe(7);
});

it('records writes with their backend and content size', function (): void {
    cacheCallsTestWrite('dletoolbar_cachecalls_write', 'payload', false, false, static fn() => true);
    cacheCallsTestWrite('dletoolbar_cachecalls_write', false, false, false, static fn() => true);

    $entries = CacheCallLog::entries();
    expect($entries[0]['status'])->toBe('written')
        ->and($entries[0]['size'])->toBe(7)
        ->and($entries[1]['size'])->toBe(0);
});

it('uses the fast cache backend when DLE has a live connection', function (string $cacheType, string $backend): void {
    $GLOBALS['config']['cache_type'] = $cacheType;
    $GLOBALS['dlefastcache'] = new class {
        /** @var int */
        public $connection = 1;
    };
    cacheCallsTestRead('dletoolbar_cachecalls_fast', false, false, static fn() => false);

    expect(CacheCallLog::entries()[0]['backend'])->toBe($backend);
})->with([
    'redis' => ['2', 'redis'],
    'memcache' => ['1', 'memcache'],
]);

it('starts the journal only for a visible component', function (bool $visible): void {
    (new ComponentRegistry())->initialize(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => $visible ? ['cachecalls'] : []],
    ]));

    expect(CacheCallLog::isActive())->toBe($visible);
})->with(['visible' => true, 'hidden' => false]);

it('renders every cache status, key and call site in one panel', function (): void {
    CacheCallLog::start();
    cacheCallsTestRead('<prefix&>', false, false, static fn() => 'body');
    cacheCallsTestRead('dletoolbar_cachecalls_panel', false, false, static fn() => false);
    cacheCallsTestWrite('dletoolbar_cachecalls_panel', 'written', false, false, static fn() => true);
    $GLOBALS['config']['allow_cache'] = false;
    cacheCallsTestRead('dletoolbar_cachecalls_panel', false, false, static fn() => false);

    $rendered = (new ComponentRegistry())->render(ToolbarPreferences::fromConfig([
        'toolbar' => ['enabled' => ['cachecalls']],
    ]));

    expect($rendered['controls'])->toContain('data-component="cachecalls"')
        ->toContain('Вызовы кэша: 4')
        ->and($rendered['panels'])->toContain('Кэш страницы')
        ->toContain('Вызовов: 4 · Из кэша: 1 · Без кэша: 2 · Записей: 1')
        ->toContain('&lt;prefix&amp;&gt;')
        ->toContain('cachecall-hit')
        ->toContain('cachecall-miss')
        ->toContain('cachecall-written')
        ->toContain('cachecall-disabled')
        ->toContain('из кэша')
        ->toContain('не из кэша')
        ->toContain('записано')
        ->toContain('кэш отключён')
        ->toContain('tests/Unit/CacheCallsTest.php:')
        ->toContain('<span>чтение</span>')
        ->and($rendered['scripts'])->toContain('createPopup');
});

it('shows an empty journal and can be rendered twice without changing the entries', function (): void {
    $registry = new ComponentRegistry();
    $preferences = ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['cachecalls']]]);
    $empty = $registry->render($preferences);
    expect($empty['controls'])->toContain('Вызовы кэша: 0')
        ->and($empty['panels'])->toContain('Вызовов dle_cache() и create_cache() на этой странице не было.')
        ->not->toContain('cachecall-status');

    CacheCallLog::start();
    cacheCallsTestRead('dletoolbar_cachecalls_render', false, false, static fn() => false);
    $rendered = $registry->render($preferences);
    expect($rendered['panels'])->toContain('Вызовов: 1')
        ->and($rendered)->toBe($registry->render($preferences))
        ->and(CacheCallLog::entries())->toHaveCount(1);
});

it('records DLE cache calls through the patched functions', function (): void {
    $prefix = 'dletoolbar_cachecalls_wrapper_' . bin2hex(random_bytes(4));
    $file = ENGINE_DIR . '/cache/' . $prefix . '.tmp';
    file_put_contents($file, 'cached');
    CacheCallLog::start();

    try {
        expect(dle_cache($prefix))->toBe('cached');
        expect(create_cache($prefix, 'written'))->toBeTrue();

        $entries = CacheCallLog::entries();
        expect($entries)->toHaveCount(2)
            ->and($entries[0]['status'])->toBe('hit')
            ->and($entries[0]['key'])->toBe($prefix)
            ->and($entries[1]['operation'])->toBe('write')
            ->and($entries[1]['status'])->toBe('written')
            ->and($entries[0]['source'])->toContain('tests/Unit/CacheCallsTest.php:');
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
});
