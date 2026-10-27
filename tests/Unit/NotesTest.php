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

it('creates edits pins and deletes shared notes with optional page links', function (): void {
    $api = new NotesActions();
    $call = fn(array $input): array => $api->handle($input + ['user_hash' => 'test-user-hash']);
    expect($call([])['notes'])->toBe([]);
    $first = $call(['action' => 'save', 'title' => '  Проверить <шапку>  ', 'body' => "Текст\nзаметки", 'page_url' => '/news.html?x=1#header'])['notes'][0];
    expect($first['title'])->toBe('Проверить <шапку>')->and($first['page_url'])->toBe('/news.html?x=1#header');
    $second = $call(['action' => 'save', 'title' => 'Вторая', 'body' => ''])['notes'][0];
    $notes = $call(['action' => 'pin', 'id' => $first['id'], 'pinned' => '1'])['notes'];
    expect($notes[0]['id'])->toBe($first['id'])->and($notes[0]['pinned'])->toBe(1);
    $notes = $call(['action' => 'save', 'id' => $first['id'], 'title' => "Новое ' название", 'body' => '<script>alert(1)</script>', 'page_url' => ''])['notes'];
    expect($notes[0]['title'])->toBe("Новое ' название")->and($notes[0]['body'])->toBe('<script>alert(1)</script>')->and($notes[0]['page_url'])->toBe('')->and($notes[0]['pinned'])->toBe(1);
    expect($call(['action' => 'delete', 'id' => $first['id']])['notes'])->toHaveCount(1);
    expect($call(['action' => 'delete', 'id' => $second['id']])['notes'])->toBe([]);
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

it('rejects invalid note data and external or malformed page links', function (array $data): void {
    $response = (new NotesActions())->handle($data + ['action' => 'save', 'title' => 'Заметка', 'user_hash' => 'test-user-hash']);
    expect($response)->toHaveKey('error')->and(http_response_code())->toBe(422);
    expect((new NotesActions())->handle(['user_hash' => 'test-user-hash'])['notes'])->toBe([]);
})->with([
    [['title' => '  ']], [['title' => str_repeat('я', 201)]], [['body' => str_repeat('я', 20001)]],
    [['page_url' => '//evil.test/']], [['page_url' => 'https://evil.test/']], [['page_url' => '/\\evil.test/']],
    [['page_url' => "/test\nheader"]], [['id' => '-1']], [['id' => '1 OR 1=1']],
]);

it('renders an isolated notes popup without loading notes into page markup', function (): void {
    $render = (new Notes())->render();
    expect($render['control'])->toContain('data-component="notes"')
        ->and($render['panel'])->toContain('maxlength="200"')->toContain('maxlength="20000"')->toContain('type="checkbox"')
        ->and($render['styles'])->toContain('text-overflow: ellipsis; white-space: nowrap')
        ->and($render['script'])->toContain('open.title = note.title')->toContain('open = button(note.title')->not->toContain('innerHTML');
});

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
