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
        global $config, $user_group, $member_id;
        /** @var array{version_id: string} $config */
        /** @var array<int, array{group_name: string}> $user_group */
        /** @var array{user_id: int|string} $member_id */

        $name = (string) $row['name'];
        $group = (int) $row['user_group'];
        $banned = $row['banned'] === 'yes';
        $restricted = (int) ($row['restricted'] ?? 0);
        $canViewPrivate = (int) $member_id['user_id'] === (int) $row['user_id'] || Format::allows('admin_editusers');
        if (!$canViewPrivate) {
            unset($row['email'], $row['allowed_ip'], $row['logged_ip'], $row['favorites'], $row['pm_all'], $row['pm_unread'], $row['twofactor_auth']);
        }

        $badges = [['ID ' . (int) $row['user_id'], 'neutral']];
        if ($banned) {
            $badges[] = ['Заблокирован', 'danger'];
        }
        if (isset(self::RESTRICTED[$restricted])) {
            $badges[] = ['Ограничен', 'warning'];
        }
        if ($canViewPrivate && !empty($row['twofactor_auth'])) {
            $badges[] = ['Двухфакторная авторизация', 'success'];
        }

        $main = Format::rows($row, [
            ['Логин', 'name', [Format::class, 'text']],
            ['ID', 'user_id', [Format::class, 'code']],
            ['Группа', 'user_group', static fn($value): string => Format::escape($user_group[$group]['group_name'] ?? (string) $value)
                . ($canViewPrivate && !empty($row['time_limit']) ? ' <span class="entry-muted">до ' . Format::date($row['time_limit']) . '</span>' : '')],
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
            ['В избранном', 'favorites', static fn($value): string
                => Format::number(count(array_filter(explode(',', (string) $value))))],
            ['Сообщений', 'pm_all', static fn($value): string => Format::number($value)
                . ' <span class="entry-muted">непрочитанных: ' . (int) ($row['pm_unread'] ?? 0) . '</span>'],
            ['Получать письма', 'allow_mail', [Format::class, 'flag']],
            ['Подписка на новости', 'news_subscribe', [Format::class, 'flag']],
            ['Подписка на ответы', 'comments_reply_subscribe', [Format::class, 'flag']],
        ]);

        // Profile field definitions differ between DLE versions, so private fields are not told apart:
        // the tab is shown to the owner and to groups that edit users.
        $fields = [];
        if ($canViewPrivate) {
            $stored = stripslashes((string) $row['xfields']);
            /** @var array<string, string> $data User and news fields share the storage format. */
            $data = version_compare($config['version_id'], '19.0', '>=') ? \DLEXFields::xfieldsdataload($stored) : \xfieldsdataload($stored);
            foreach ($data as $field => $value) {
                $fields[] = ['<code>' . Format::escape((string) $field) . '</code>', Format::text(str_replace("\x01", "\n", $value))];
            }
        }

        $access = Format::rows($row, [
            ['Блокировка', 'banned', static fn($value): string => Format::flag($value === 'yes')],
            ['Ограничение', 'restricted', static fn(): string
                => isset(self::RESTRICTED[$restricted]) ? Format::escape(self::RESTRICTED[$restricted]) : Format::empty()],
            ['Срок ограничения, дней', 'restricted_days', static fn($value): string => (int) $value ? Format::number($value) : Format::empty()],
            ['Ограничение до', 'restricted_date', [Format::class, 'date']],
            ['Двухфакторная авторизация', 'twofactor_auth', [Format::class, 'flag']],
            ['Разрешённые IP', 'allowed_ip', [Format::class, 'text']],
            ['Последний IP', 'logged_ip', [Format::class, 'code']],
            ['Категории для добавления', 'cat_add', [Format::class, 'text']],
            ['Категории без модерации', 'cat_allow_addnews', [Format::class, 'text']],
        ]);

        return [
            'kind' => 'Пользователь',
            'title' => Format::decode($name),
            'badges' => $badges,
            'edit' => Format::allows('admin_editusers') ? Format::adminUrl('mod=editusers&action=edituser&id=' . (int) $row['user_id']) : null,
            'tabs' => [
                ['id' => 'main', 'label' => 'Основное', 'rows' => $main],
                ['id' => 'profile', 'label' => 'Профиль', 'rows' => $profile],
                ['id' => 'fields', 'label' => 'Допполя', 'rows' => $fields],
                ['id' => 'access', 'label' => 'Доступ', 'rows' => $access],
                ['id' => 'raw', 'label' => 'Все поля', 'rows' => $canViewPrivate ? Format::raw($row, array_merge(self::SECRET, ['xfields'])) : []],
            ],
        ];
    }
}
