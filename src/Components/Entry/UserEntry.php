<?php

namespace iCooLER\DLEToolbar\Components\Entry;

/** @phpstan-import-type EntryData from Entry */
final class UserEntry
{
    /** Secrets of the account; the panel never shows them. */
    private const SECRET = ['password', 'hash', 'twofactor_secret'];
    private const RESTRICTED = [1 => 'Публикация новостей', 2 => 'Комментарии', 3 => 'Новости и комментарии'];

    /**
     * @param array<string, scalar|null> $row
     * @return EntryData
     */
    public static function build(array $row): array
    {
        global $config, $user_group, $member_id, $is_logged;
        /** @var array{version_id: string} $config */
        /** @var array<int, array{group_name: string, admin_editusers?: int|string}> $user_group */
        /** @var array{user_id: int|string, user_group: int|string} $member_id */
        /** @var bool $is_logged */

        $name = (string) $row['name'];
        $group = (int) $row['user_group'];
        $banned = $row['banned'] === 'yes';
        $restricted = (int) ($row['restricted'] ?? 0);

        $badges = [];
        if ($banned) $badges[] = ['Заблокирован', 'danger'];
        if (isset(self::RESTRICTED[$restricted])) $badges[] = ['Ограничен', 'warning'];
        if (!empty($row['twofactor_auth'])) $badges[] = ['Двухфакторная авторизация', 'success'];

        $main = Format::rows($row, [
            ['Логин', 'name', [Format::class, 'text']],
            ['ID', 'user_id', [Format::class, 'code']],
            ['Группа', 'user_group', static fn ($value): string => Format::escape($user_group[$group]['group_name'] ?? (string) $value)
                . (empty($row['time_limit']) ? '' : ' <span class="entry-muted">до ' . Format::date($row['time_limit']) . '</span>')],
            ['E-mail', 'email', [Format::class, 'text']],
            ['Полное имя', 'fullname', [Format::class, 'text']],
            ['Место жительства', 'land', [Format::class, 'text']],
            ['Регистрация', 'reg_date', [Format::class, 'date']],
            ['Последний визит', 'lastdate', [Format::class, 'date']],
            ['Публикаций', 'news_num', [Format::class, 'number']],
            ['Комментариев', 'comm_num', [Format::class, 'number']],
            ['Часовой пояс', 'timezone', [Format::class, 'text']],
        ]);

        $profile = Format::rows($row, [
            ['Аватар', 'foto', [Format::class, 'text']],
            ['О себе', 'info', [Format::class, 'text']],
            ['Подпись', 'signature', [Format::class, 'text']],
            ['В избранном', 'favorites', static fn ($value): string =>
                Format::number(count(array_filter(explode(',', (string) $value))))],
            ['Сообщений', 'pm_all', static fn ($value): string => Format::number($value)
                . ' <span class="entry-muted">непрочитанных: ' . (int) ($row['pm_unread'] ?? 0) . '</span>'],
            ['Получать письма', 'allow_mail', [Format::class, 'flag']],
            ['Подписка на новости', 'news_subscribe', [Format::class, 'flag']],
            ['Подписка на ответы', 'comments_reply_subscribe', [Format::class, 'flag']],
        ]);

        $modernFields = version_compare($config['version_id'], '19.0', '>=');
        /** @var array<string, string> $data */
        $data = $modernFields ? \DLEUserXFields::xfieldsdataload(stripslashes((string) $row['xfields']))
            : \xfieldsdataload(stripslashes((string) $row['xfields']));
        /** @var array<array-key, array{name: string, private: bool|int|string}> $definitions */
        $definitions = $modernFields ? \DLEUserXFields::GETFields() : array_map(static fn (array $field): array => [
            'name' => $field[0], 'private' => $field[5],
        ], \xfieldsload(true));
        // DLE 19+ uses the edit-users permission; older versions reserve private fields for group 1.
        $isOwn = $is_logged && ((int) $member_id['user_id'] === (int) $row['user_id']
            || ($modernFields ? !empty($user_group[$member_id['user_group']]['admin_editusers']) : (int) $member_id['user_group'] === 1));
        $fields = [];
        foreach ($definitions as $field) {
            if ($field['private'] && !$isOwn) continue;
            if (!array_key_exists($field['name'], $data)) continue;
            $fields[] = ['<code>' . Format::escape($field['name']) . '</code>',
                Format::text(str_replace("\x01", "\n", $data[$field['name']]))];
        }

        $access = Format::rows($row, [
            ['Блокировка', 'banned', static fn ($value): string => Format::flag($value === 'yes')],
            ['Ограничение', 'restricted', static fn (): string =>
                isset(self::RESTRICTED[$restricted]) ? Format::escape(self::RESTRICTED[$restricted]) : Format::empty()],
            ['Срок ограничения, дней', 'restricted_days', static fn ($value): string => (int) $value ? Format::number($value) : Format::empty()],
            ['Ограничение до', 'restricted_date', [Format::class, 'date']],
            ['Двухфакторная авторизация', 'twofactor_auth', [Format::class, 'flag']],
            ['Разрешённые IP', 'allowed_ip', [Format::class, 'text']],
            ['Последний IP', 'logged_ip', [Format::class, 'code']],
            ['Категории для добавления', 'cat_add', [Format::class, 'text']],
            ['Категории без модерации', 'cat_allow_addnews', [Format::class, 'text']],
        ]);

        $links = [];
        if (Format::allows('admin_editusers')) {
            $links[] = ['label' => 'Редактировать в админке', 'url' => Format::adminUrl('mod=editusers&action=edituser&id=' . (int) $row['user_id']), 'icon' => 'edit'];
        }

        return [
            'kind' => 'Пользователь',
            'title' => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'id' => (string) (int) $row['user_id'],
            'badges' => $badges,
            'links' => $links,
            'tabs' => array_values(array_filter([
                ['id' => 'main', 'label' => 'Основное', 'rows' => $main],
                ['id' => 'profile', 'label' => 'Профиль', 'rows' => $profile],
                ['id' => 'fields', 'label' => 'Допполя', 'rows' => $fields],
                ['id' => 'access', 'label' => 'Доступ', 'rows' => $access],
                ['id' => 'raw', 'label' => 'Все поля', 'rows' => Format::raw($row, array_merge(self::SECRET, ['xfields']))],
            ], static fn (array $tab): bool => $tab['rows'] !== [])),
        ];
    }
}
