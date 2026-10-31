<?php

use iCooLER\DLEToolbar\Components\Notes\Notes;
use iCooLER\DLEToolbar\Components\Notes\NotesActions;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;

use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
    $GLOBALS['db']->query('DELETE FROM `' . PREFIX . '_dletoolbar_notes`');
});

afterEach(function (): void {
    $GLOBALS['db']->query('DELETE FROM `' . PREFIX . '_dletoolbar_notes`');
});

function notesRequest(array $input = []): array
{
    return (new NotesActions())->handle($input + ['user_hash' => 'test-user-hash']);
}

function addNote(string $title, string $key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): array
{
    expect(notesRequest(['action' => 'save', 'title' => $title, 'request_key' => $key]))->toBe(['ok' => true]);
    return notesRequest()['notes'][0];
}

it('creates edits and deletes simple shared notes', function (): void {
    expect(notesRequest()['notes'])->toBe([]);
    $first = addNote('  Проверить <шапку>  ');
    expect($first['title'])->toBe('Проверить <шапку>')
        ->and(array_keys($first))->toBe(['id', 'title', 'created_at', 'updated_at', 'created_days_ago'])
        ->and($first['created_days_ago'])->toBe(0)
        ->and($first['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    $second = addNote('Вторая', str_repeat('b', 32));
    expect(notesRequest(['action' => 'save', 'id' => $second['id'], 'title' => "Новое ' название", 'original_title' => 'Вторая']))->toBe(['ok' => true]);
    expect(notesRequest()['notes'][0]['title'])->toBe("Новое ' название");
    expect(notesRequest(['action' => 'delete', 'id' => $first['id'], 'original_title' => $first['title']]))->toBe(['ok' => true]);
    expect(notesRequest()['notes'])->toHaveCount(1);
    expect(notesRequest(['action' => 'delete', 'id' => $second['id'], 'original_title' => "Новое ' название"]))->toBe(['ok' => true]);
    expect(notesRequest()['notes'])->toBe([]);
});

it('acknowledges writes independently of reading and makes retries idempotent', function (): void {
    $key = str_repeat('c', 32);
    $GLOBALS['db']->failQuery = 'SELECT id, title';
    $payload = ['action' => 'save', 'title' => 'Проверить', 'request_key' => $key];
    expect(notesRequest($payload))->toBe(['ok' => true]);
    expect(fn() => notesRequest())->toThrow(RuntimeException::class, 'Failed to load toolbar notes.');
    expect(notesRequest($payload))->toBe(['ok' => true]);
    expect(notesRequest()['notes'])->toHaveCount(1)->and(notesRequest()['notes'][0]['title'])->toBe('Проверить');
});

it('rejects stale edits and deletes without overwriting another administrator', function (): void {
    $note = addNote('Исходная');
    $first = ['action' => 'save', 'id' => $note['id'], 'title' => 'Правка первого', 'original_title' => 'Исходная'];
    $second = ['action' => 'save', 'id' => $note['id'], 'title' => 'Правка второго', 'original_title' => 'Исходная'];
    expect(notesRequest($first))->toBe(['ok' => true]);
    expect(notesRequest($first))->toBe(['ok' => true]); // Retrying the already applied edit is safe.
    expect(notesRequest($second))->toHaveKey('error')->and(http_response_code())->toBe(409);
    expect(notesRequest()['notes'][0]['title'])->toBe('Правка первого');
    expect(notesRequest(['action' => 'delete', 'id' => $note['id'], 'original_title' => 'Исходная']))->toHaveKey('error')
        ->and(http_response_code())->toBe(409);
    expect(notesRequest(['action' => 'delete', 'id' => $note['id'], 'original_title' => 'Правка первого']))->toBe(['ok' => true]);
    expect(notesRequest(['action' => 'delete', 'id' => $note['id'], 'original_title' => 'Правка первого']))->toBe(['ok' => true]);
    expect(notesRequest($second))->toHaveKey('error')->and(http_response_code())->toBe(409);
});

it('keeps existing note data in storage and excludes removed fields from the list and saves', function (): void {
    $GLOBALS['db']->query('INSERT INTO `' . PREFIX . "_dletoolbar_notes` SET title = 'Прежняя', body = 'Старый текст', page_url = '/old', pinned = 1, updated_at = '2020-01-01 00:00:00'");
    $old = notesRequest()['notes'][0];
    expect($old['created_at'])->toBeNull()->and($old['created_days_ago'])->toBeNull();
    expect(notesRequest(['action' => 'save', 'title' => 'Новая', 'body' => 'Не сохранять', 'page_url' => '/new', 'pinned' => '1', 'request_key' => str_repeat('d', 32)]))->toBe(['ok' => true]);
    expect(notesRequest(['action' => 'save', 'id' => $old['id'], 'title' => 'Изменено', 'original_title' => 'Прежняя']))->toBe(['ok' => true]);
    $result = $GLOBALS['db']->query('SELECT body, page_url, pinned FROM `' . PREFIX . '_dletoolbar_notes` ORDER BY id');
    expect($GLOBALS['db']->get_row($result))->toBe(['body' => 'Старый текст', 'page_url' => '/old', 'pinned' => '1'])
        ->and($GLOBALS['db']->get_row($result))->toBe(['body' => '', 'page_url' => '', 'pinned' => '0']);
    $GLOBALS['db']->free($result);
});

it('uses the DLE site timezone for creation dates and preserves them during editing', function (): void {
    $timezone = date_default_timezone_get();
    $result = $GLOBALS['db']->query('SELECT @@session.time_zone AS zone');
    $databaseZone = $GLOBALS['db']->get_row($result)['zone'];
    $GLOBALS['db']->free($result);
    try {
        $GLOBALS['db']->query("SET time_zone = '+00:00'");
        date_default_timezone_set('Europe/Moscow');
        $note = addNote('Часовой пояс');
        $GLOBALS['db']->query('UPDATE `' . PREFIX . "_dletoolbar_notes` SET created_at = '2020-03-04 23:45:00', updated_at = '2020-03-04 23:45:00' WHERE id = " . $note['id']);
        $saved = notesRequest(['action' => 'save', 'id' => $note['id'], 'title' => 'Изменено', 'original_title' => 'Часовой пояс', 'created_at' => '2030-01-01 00:00:00']);
        expect($saved)->toBe(['ok' => true]);
        expect(notesRequest()['notes'][0]['created_at'])->toBe('2020-03-05 02:45:00');
        $yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
        $timestamp = (new DateTimeImmutable($yesterday . ' 12:00:00'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $GLOBALS['db']->query('UPDATE `' . PREFIX . "_dletoolbar_notes` SET created_at = '$timestamp' WHERE id = " . $note['id']);
        expect(notesRequest()['notes'][0]['created_days_ago'])->toBe(1);
    } finally {
        date_default_timezone_set($timezone);
        $GLOBALS['db']->query("SET time_zone = '" . $GLOBALS['db']->safesql($databaseZone) . "'");
    }
});

it('paginates by timestamp and ID without skipping notes created in the same second', function (): void {
    $table = '`' . PREFIX . '_dletoolbar_notes`';
    for ($i = 1; $i <= 105; $i++) {
        $GLOBALS['db']->query("INSERT INTO $table SET title = 'Заметка $i', body = '', updated_at = '2020-01-01 00:00:00'");
    }
    $first = notesRequest();
    expect($first['notes'])->toHaveCount(50)->and($first['has_more'])->toBeTrue();
    $last = end($first['notes']);
    $second = notesRequest(['before_id' => $last['id'], 'before_updated_at' => $last['updated_at']]);
    expect($second['notes'])->toHaveCount(50)->and($second['has_more'])->toBeTrue();
    $last = end($second['notes']);
    $third = notesRequest(['before_id' => $last['id'], 'before_updated_at' => $last['updated_at']]);
    expect($third['notes'])->toHaveCount(5)->and($third['has_more'])->toBeFalse();
    $ids = array_column(array_merge($first['notes'], $second['notes'], $third['notes']), 'id');
    expect(array_unique($ids))->toHaveCount(105)->and($ids)->toBe(array_values($ids));
});

it('upgrades a legacy table through the SQL hook and can repeat the upgrade', function (): void {
    $root = dirname((new ReflectionClass(NotesActions::class))->getFileName(), 4);
    $manifest = file_get_contents($root . '/dleplugin.yml');
    $mysql = explode("\nmysql:\n", $manifest)[1];
    $upgrade = explode('  enable:', explode("  upgrade: |\n", $mysql)[1])[0];
    $upgrade = str_replace('{prefix}', PREFIX, $upgrade);
    $native = $GLOBALS['dletoolbar_native_db'];
    $connection = (new ReflectionProperty($native, 'db_id'))->getValue($native);
    $runUpgrade = static function () use ($connection, $upgrade): void {
        // DLE 13 advances multi-query results without fetching them.
        expect(mysqli_multi_query($connection, $upgrade))->toBeTrue();
        while (mysqli_more_results($connection) && mysqli_next_result($connection)) {
        }
        expect(mysqli_error($connection))->toBe('');
    };
    try {
        $GLOBALS['db']->query('ALTER TABLE `' . PREFIX . '_dletoolbar_notes` DROP INDEX request_key, DROP INDEX notes_updated_at, DROP COLUMN request_key, DROP COLUMN created_at');
        $GLOBALS['db']->query('INSERT INTO `' . PREFIX . "_dletoolbar_notes` SET title = 'Старая заметка', body = '', updated_at = '2020-01-01 00:00:00'");
        $runUpgrade();
        expect(notesRequest()['notes'][0]['created_at'])->toBeNull();
        $GLOBALS['db']->query('UPDATE `' . PREFIX . "_dletoolbar_notes` SET created_at = '2020-02-03 04:05:06', request_key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'");
        $runUpgrade();
        expect(notesRequest()['notes'][0]['created_at'])->toBe('2020-02-03 04:05:06');
        $result = $GLOBALS['db']->query('SHOW INDEX FROM `' . PREFIX . '_dletoolbar_notes`');
        $indexes = [];
        while ($row = $GLOBALS['db']->get_row($result)) {
            $indexes[] = $row['Key_name'];
        }
        $GLOBALS['db']->free($result);
        expect($indexes)->toContain('request_key', 'notes_updated_at');
    } finally {
        $runUpgrade();
    }
});

it('rejects unauthorized requests and hidden components without reading notes', function (): void {
    expect(notesRequest(['user_hash' => 'wrong']))->toHaveKey('error');
    $GLOBALS['member_id']['user_group'] = 4;
    expect(notesRequest())->toHaveKey('error');
    $GLOBALS['member_id']['user_group'] = 1;
    ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['time']]])->save();
    expect(notesRequest())->toHaveKey('error');
    expect(implode("\n", $GLOBALS['db']->queries))->not->toContain('SELECT id, title');
});

it('rejects malformed note data, actions, and page cursors', function (array $data): void {
    $response = notesRequest($data + ['action' => 'save', 'title' => 'Заметка', 'request_key' => str_repeat('a', 32)]);
    expect($response)->toHaveKey('error')->and(http_response_code())->toBe(422);
    expect(notesRequest()['notes'])->toBe([]);
})->with([
    [['title' => '  ']], [['title' => str_repeat('я', 201)]], [['title' => []]], [['request_key' => 'wrong']],
    [['id' => '-1']], [['id' => '1 OR 1=1']], [['id' => '1']], [['action' => 'delete', 'id' => '0']],
    [['action' => 'pin', 'id' => '1']], [['action' => ['save']]],
    [['action' => 'list', 'before_id' => '1']], [['action' => 'list', 'before_id' => '1', 'before_updated_at' => 'bad']],
]);

it('renders one always visible entry field without optional note features', function (): void {
    $render = (new Notes())->render();
    expect($render['control'])->toContain('data-component="notes"')
        ->and($render['panel'])->toContain('class="notes-compose"')->toContain('maxlength="200"')
        ->not->toContain('<textarea')->not->toContain('type="checkbox"')->not->toContain('notes-page')
        ->and($render['styles'])->toContain('text-overflow: ellipsis; white-space: nowrap')
        ->and($render['script'])->not->toContain('innerHTML');
});

it('uses the DLE AJAX controller for the installed version and site directory', function (string $version, string $endpoint): void {
    $GLOBALS['config']['version_id'] = $version;
    $GLOBALS['config']['http_home_url'] = 'https://example.test/site/';
    expect((new Notes())->render()['panel'])->toContain('data-endpoint="https://example.test/site/' . $endpoint . '"');
})->with([
    'DLE 13' => ['13.0', 'engine/ajax/controller.php?mod=dletoolbar_notes'],
    'DLE 18' => ['18.0', 'engine/ajax/controller.php?mod=dletoolbar_notes'],
    'DLE 19' => ['19.0', 'index.php?controller=ajax&amp;mod=dletoolbar_notes'],
    'DLE 21' => ['21.0', 'index.php?controller=ajax&amp;mod=dletoolbar_notes'],
]);

it('serves notes through the AJAX entry and reports request and database errors', function (string $method, array $input, ?string $failQuery, int $status, string $key): void {
    $previousPost = $_POST;
    $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $input;
    $GLOBALS['db']->failQuery = $failQuery;
    try {
        ob_start();
        require ENGINE_DIR . '/ajax/dletoolbar_notes.php';
        $response = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        expect(http_response_code())->toBe($status)->and($response)->toHaveKey($key);
        if ($status === 200) {
            expect($response['ok'])->toBeTrue();
        }
    } finally {
        $_POST = $previousPost;
        if ($previousMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $previousMethod;
        }
    }
})->with([
    'save' => ['POST', ['user_hash' => 'test-user-hash', 'action' => 'save', 'title' => 'Через AJAX', 'request_key' => str_repeat('a', 32)], null, 200, 'ok'],
    'invalid token' => ['POST', ['user_hash' => 'wrong'], null, 403, 'error'],
    'wrong method' => ['GET', [], null, 405, 'error'],
    'read failure' => ['POST', ['user_hash' => 'test-user-hash'], 'SELECT id, title', 500, 'error'],
    'write failure' => ['POST', ['user_hash' => 'test-user-hash', 'action' => 'save', 'title' => 'Ошибка', 'request_key' => str_repeat('a', 32)], 'INSERT INTO `' . PREFIX . '_dletoolbar_notes`', 500, 'error'],
]);
