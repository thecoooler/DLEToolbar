<?php

use iCooLER\DLEToolbar\Components\Notes\NotesActions;

if (!defined('DATALIFEENGINE')) {
    http_response_code(403);
    die('Hacking attempt!');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Используйте POST.']);
    return;
}

try {
    echo json_encode((new NotesActions())->handle($_POST), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $error) {
    error_log('[DLEToolbar] notes: ' . $error);
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось загрузить или сохранить заметки. Попробуйте ещё раз.'], JSON_UNESCAPED_UNICODE);
}
