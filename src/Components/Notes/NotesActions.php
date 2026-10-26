<?php

namespace iCooLER\DLEToolbar\Components\Notes;

use iCooLER\DLEToolbar\Toolbar\DLEToolbar;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;

final class NotesActions
{
    /** @param array<array-key, mixed> $input
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        global $db, $dle_login_hash;
        /** @var \db $db */
        /** @var string $dle_login_hash */
        if (!(new DLEToolbar())->isHasAccess() || !ToolbarPreferences::load()->isEnabled('notes')
            || !is_string($input['user_hash'] ?? null) || !hash_equals($dle_login_hash, $input['user_hash'])) {
            http_response_code(403);
            return ['error' => 'Нет доступа к заметкам. Обновите страницу.'];
        }

        $action = $input['action'] ?? 'list';
        $table = '`' . PREFIX . '_dletoolbar_notes`';
        if ($action !== 'list') {
            $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($id === false || !in_array($action, ['save', 'pin', 'delete'], true) || ($action !== 'save' && $id === 0)) {
                http_response_code(422);
                return ['error' => 'Некорректное действие.'];
            }
            if ($action === 'save') {
                $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
                $body = is_string($input['body'] ?? null) ? trim($input['body']) : '';
                $url = is_string($input['page_url'] ?? null) ? $input['page_url'] : '';
                if ($title === '' || mb_strlen($title, 'UTF-8') > 200 || mb_strlen($body, 'UTF-8') > 20000
                    || strlen($url) > 2048 || ($url !== '' && !preg_match('~^/(?!/)[^\\\\\\x00-\\x20]*$~D', $url))) {
                    http_response_code(422);
                    return ['error' => 'Укажите название до 200 символов, текст до 20 000 символов и страницу этого сайта.'];
                }
                $title = $db->safesql($title);
                $body = $db->safesql($body);
                $url = $db->safesql($url);
                $values = "title = '$title', body = '$body', page_url = '$url', updated_at = CURRENT_TIMESTAMP";
                $sql = $id === 0 ? "INSERT INTO $table SET $values" : "UPDATE $table SET $values WHERE id = $id";
            } elseif ($action === 'pin') {
                $pinned = ($input['pinned'] ?? '') === '1' ? 1 : 0;
                $sql = "UPDATE $table SET pinned = $pinned WHERE id = $id";
            } else {
                $sql = "DELETE FROM $table WHERE id = $id";
            }
            if ($db->query($sql, false) === false) throw new \RuntimeException('Failed to change toolbar note.');
        }

        $result = $db->query("SELECT id, title, body, page_url, pinned FROM $table ORDER BY pinned DESC, updated_at DESC, id DESC", false);
        if ($result === false) throw new \RuntimeException('Failed to load toolbar notes.');
        $notes = [];
        while ($row = $db->get_row($result)) {
            /** @var array{id: int|string, title: string, body: string, page_url: string, pinned: int|string} $row */
            $row['id'] = (int) $row['id'];
            $row['pinned'] = (int) $row['pinned'];
            $notes[] = $row;
        }
        $db->free($result);
        return ['notes' => $notes];
    }
}
