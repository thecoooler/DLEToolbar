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
            if ($id === false || !in_array($action, ['save', 'delete'], true) || ($action !== 'save' && $id === 0)) {
                http_response_code(422);
                return ['error' => 'Некорректное действие.'];
            }
            if ($action === 'save') {
                $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
                if ($title === '' || mb_strlen($title, 'UTF-8') > 200) {
                    http_response_code(422);
                    return ['error' => 'Введите заметку до 200 символов.'];
                }
                $title = $db->safesql($title);
                $values = "title = '$title', updated_at = CURRENT_TIMESTAMP";
                $sql = $id === 0 ? "INSERT INTO $table SET $values, body = '', created_at = CURRENT_TIMESTAMP" : "UPDATE $table SET $values WHERE id = $id";
            } else {
                $sql = "DELETE FROM $table WHERE id = $id";
            }
            if ($db->query($sql, false) === false) {
                throw new \RuntimeException('Failed to change toolbar note.');
            }
        }

        $result = $db->query("SELECT id, title, created_at FROM $table ORDER BY updated_at DESC, id DESC", false);
        if ($result === false) {
            throw new \RuntimeException('Failed to load toolbar notes.');
        }
        $notes = [];
        while ($row = $db->get_row($result)) {
            /** @var array{id: int|string, title: string, created_at: string|null} $row */
            $row['id'] = (int) $row['id'];
            $notes[] = $row;
        }
        $db->free($result);
        return ['notes' => $notes];
    }
}
