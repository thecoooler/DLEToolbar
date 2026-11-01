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
        if ($action === 'list') {
            $where = '';
            if (isset($input['before_id']) || isset($input['before_updated_at'])) {
                $beforeId = filter_var($input['before_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $beforeTime = $input['before_updated_at'] ?? null;
                if ($beforeId === false || !is_string($beforeTime) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $beforeTime)) {
                    http_response_code(422);
                    return ['error' => 'Некорректная страница списка.'];
                }
                $beforeTime = $db->safesql($beforeTime);
                $where = " WHERE (updated_at < '$beforeTime' OR (updated_at = '$beforeTime' AND id < $beforeId))";
            }
            $result = $db->query("SELECT id, title, created_at, UNIX_TIMESTAMP(created_at) AS created_epoch, updated_at FROM $table$where ORDER BY updated_at DESC, id DESC LIMIT 51", false);
            if ($result === false) {
                throw new \RuntimeException('Failed to load toolbar notes.');
            }
            $notes = [];
            $today = new \DateTimeImmutable('today');
            while ($row = $db->get_row($result)) {
                /** @var array{id: int|string, title: string, created_at: string|null, created_epoch: int|string|null, updated_at: string} $row */
                $row['id'] = (int) $row['id'];
                $row['created_days_ago'] = null;
                if ($row['created_epoch'] !== null) {
                    $timestamp = (int) $row['created_epoch'];
                    $row['created_at'] = date('Y-m-d H:i:s', $timestamp);
                    $day = new \DateTimeImmutable(date('Y-m-d', $timestamp));
                    $row['created_days_ago'] = (int) $day->diff($today)->format('%r%a');
                }
                unset($row['created_epoch']);
                $notes[] = $row;
            }
            $db->free($result);
            $hasMore = count($notes) > 50;
            if ($hasMore) {
                array_pop($notes);
            }
            return ['notes' => $notes, 'has_more' => $hasMore];
        }

        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($id === false || !in_array($action, ['save', 'delete'], true) || ($action === 'delete' && $id === 0)) {
            http_response_code(422);
            return ['error' => 'Некорректное действие.'];
        }
        $title = '';
        if ($action === 'save') {
            $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
            if (!preg_match('/^.{1,200}$/usD', $title)) {
                http_response_code(422);
                return ['error' => 'Введите заметку до 200 символов.'];
            }
        }
        if ($id === 0) {
            $key = $input['request_key'] ?? null;
            if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) {
                http_response_code(422);
                return ['error' => 'Некорректный запрос на добавление заметки.'];
            }
            $safeTitle = $db->safesql($title);
            $sql = "INSERT INTO $table SET title = '$safeTitle', body = '', created_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP, request_key = '$key'"
                . ' ON DUPLICATE KEY UPDATE request_key = VALUES(request_key)';
        } else {
            $original = $input['original_title'] ?? null;
            if (!is_string($original) || !preg_match('/^.{0,200}$/usD', $original)) {
                http_response_code(422);
                return ['error' => 'Откройте заметку заново и повторите действие.'];
            }
            $safeOriginal = $db->safesql($original);
            $where = " WHERE id = $id AND BINARY title = BINARY '$safeOriginal'";
            if ($action === 'save') {
                $safeTitle = $db->safesql($title);
                $sql = "UPDATE $table SET title = '$safeTitle', updated_at = CURRENT_TIMESTAMP$where";
            } else {
                $sql = "DELETE FROM $table$where";
            }
        }
        if ($db->query($sql, false) === false) {
            throw new \RuntimeException('Failed to change toolbar note.');
        }
        if ($id !== 0 && $db->get_affected_rows() === 0) {
            $result = $db->query("SELECT title FROM $table WHERE id = $id", false);
            if ($result === false) {
                throw new \RuntimeException('Failed to check toolbar note.');
            }
            $current = $db->get_row($result);
            $db->free($result);
            if (($action === 'save' && (!$current || $current['title'] !== $title))
                || ($action === 'delete' && $current)) {
                http_response_code(409);
                return ['error' => 'Заметка изменена или удалена другим пользователем. Скопируйте свой текст и обновите список.'];
            }
        }
        return ['ok' => true];
    }
}
