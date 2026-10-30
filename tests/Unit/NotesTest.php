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

it('creates edits and deletes simple shared notes', function (): void {
    $api = new NotesActions();
    $call = fn(array $input): array => $api->handle($input + ['user_hash' => 'test-user-hash']);
    expect($call([])['notes'])->toBe([]);
    $first = $call(['action' => 'save', 'title' => '  Проверить <шапку>  '])['notes'][0];
    expect($first['title'])->toBe('Проверить <шапку>')->and(array_keys($first))->toBe(['id', 'title', 'created_at'])
        ->and($first['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    $second = $call(['action' => 'save', 'title' => 'Вторая'])['notes'][0];
    expect($second['title'])->toBe('Вторая');
    $notes = $call(['action' => 'save', 'id' => $second['id'], 'title' => "Новое ' название"])['notes'];
    expect($notes[0]['title'])->toBe("Новое ' название");
    expect($call(['action' => 'delete', 'id' => $first['id']])['notes'])->toHaveCount(1);
    expect($call(['action' => 'delete', 'id' => $second['id']])['notes'])->toBe([]);
});

it('keeps existing note data in storage and excludes removed fields from the list and saves', function (): void {
    $api = new NotesActions();
    $call = fn(array $input): array => $api->handle($input + ['user_hash' => 'test-user-hash']);
    $GLOBALS['db']->query('INSERT INTO `' . PREFIX . "_dletoolbar_notes` SET title = 'Прежняя', body = 'Старый текст', page_url = '/old', pinned = 1, updated_at = '2020-01-01 00:00:00'");
    $old = $call([])['notes'][0];
    $new = $call(['action' => 'save', 'title' => 'Новая', 'body' => 'Не сохранять', 'page_url' => '/new', 'pinned' => '1'])['notes'][0];
    expect($new['title'])->toBe('Новая')->and(array_keys($new))->toBe(['id', 'title', 'created_at'])
        ->and($old['created_at'])->toBeNull();
    $notes = $call(['action' => 'save', 'id' => $old['id'], 'title' => 'Изменено'])['notes'];
    expect(array_column($notes, 'title'))->toContain('Изменено');
    $result = $GLOBALS['db']->query('SELECT body, page_url, pinned FROM `' . PREFIX . '_dletoolbar_notes` ORDER BY id');
    expect($GLOBALS['db']->get_row($result))->toBe(['body' => 'Старый текст', 'page_url' => '/old', 'pinned' => '1'])
        ->and($GLOBALS['db']->get_row($result))->toBe(['body' => '', 'page_url' => '', 'pinned' => '0']);
    $GLOBALS['db']->free($result);
});

it('preserves the creation date when a note is edited', function (): void {
    $api = new NotesActions();
    $note = $api->handle(['action' => 'save', 'title' => 'Прежняя', 'user_hash' => 'test-user-hash'])['notes'][0];
    $GLOBALS['db']->query('UPDATE `' . PREFIX . "_dletoolbar_notes` SET created_at = '2020-03-04 05:06:07', updated_at = '2020-03-04 05:06:07' WHERE id = " . $note['id']);
    $saved = $api->handle(['action' => 'save', 'id' => $note['id'], 'title' => 'Изменено', 'created_at' => '2030-01-01 00:00:00', 'user_hash' => 'test-user-hash'])['notes'][0];
    expect($saved['created_at'])->toBe('2020-03-04 05:06:07');
    $result = $GLOBALS['db']->query('SELECT updated_at FROM `' . PREFIX . '_dletoolbar_notes` WHERE id = ' . $note['id']);
    expect($GLOBALS['db']->get_row($result)['updated_at'])->not->toBe('2020-03-04 05:06:07');
    $GLOBALS['db']->free($result);
});

it('upgrades the old notes table without inventing dates and keeps dates on repeated upgrades', function (): void {
    $root = dirname((new ReflectionClass(NotesActions::class))->getFileName(), 4);
    $manifest = file_get_contents($root . '/dleplugin.yml');
    $php = explode("\nphp:\n", $manifest)[1];
    $upgrade = explode('  enable:', explode("  upgrade: |\n", $php)[1])[0];
    $upgrade = preg_replace('/^    /m', '', $upgrade);
    $runUpgrade = static function () use ($upgrade): void {
        global $db;
        eval($upgrade);
    };
    try {
        $GLOBALS['db']->query('ALTER TABLE `' . PREFIX . '_dletoolbar_notes` DROP COLUMN created_at');
        $GLOBALS['db']->query('INSERT INTO `' . PREFIX . "_dletoolbar_notes` SET title = 'Старая заметка', body = '', updated_at = '2020-01-01 00:00:00'");
        $runUpgrade();
        $api = new NotesActions();
        expect($api->handle(['user_hash' => 'test-user-hash'])['notes'][0]['created_at'])->toBeNull();
        $GLOBALS['db']->query('UPDATE `' . PREFIX . "_dletoolbar_notes` SET created_at = '2020-02-03 04:05:06'");
        $runUpgrade();
        expect($api->handle(['user_hash' => 'test-user-hash'])['notes'][0]['created_at'])->toBe('2020-02-03 04:05:06');
    } finally {
        $runUpgrade();
    }
});

it('rejects unauthorized requests and hidden components without reading notes', function (): void {
    $api = new NotesActions();
    expect($api->handle(['user_hash' => 'wrong']))->toHaveKey('error');
    $GLOBALS['member_id']['user_group'] = 4;
    expect($api->handle(['user_hash' => 'test-user-hash']))->toHaveKey('error');
    $GLOBALS['member_id']['user_group'] = 1;
    ToolbarPreferences::fromConfig(['toolbar' => ['enabled' => ['time']]])->save();
    expect($api->handle(['user_hash' => 'test-user-hash']))->toHaveKey('error');
    expect(implode("\n", $GLOBALS['db']->queries))->not->toContain('SELECT id, title');
});

it('rejects empty or oversized notes and invalid actions or IDs', function (array $data): void {
    $response = (new NotesActions())->handle($data + ['action' => 'save', 'title' => 'Заметка', 'user_hash' => 'test-user-hash']);
    expect($response)->toHaveKey('error')->and(http_response_code())->toBe(422);
    expect((new NotesActions())->handle(['user_hash' => 'test-user-hash'])['notes'])->toBe([]);
})->with([
    [['title' => '  ']], [['title' => str_repeat('я', 201)]], [['title' => []]],
    [['id' => '-1']], [['id' => '1 OR 1=1']], [['action' => 'delete', 'id' => '0']],
    [['action' => 'pin', 'id' => '1']], [['action' => ['save']]],
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
            expect($response['notes'])->toHaveCount(1)->and($response['notes'][0]['title'])->toBe('Через AJAX');
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
    'save' => ['POST', ['user_hash' => 'test-user-hash', 'action' => 'save', 'title' => 'Через AJAX'], null, 200, 'notes'],
    'invalid token' => ['POST', ['user_hash' => 'wrong'], null, 403, 'error'],
    'wrong method' => ['GET', [], null, 405, 'error'],
    'read failure' => ['POST', ['user_hash' => 'test-user-hash'], 'SELECT id, title', 500, 'error'],
    'write failure' => ['POST', ['user_hash' => 'test-user-hash', 'action' => 'save', 'title' => 'Ошибка'], 'INSERT INTO `' . PREFIX . '_dletoolbar_notes`', 500, 'error'],
]);
