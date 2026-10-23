<?php

namespace iCooLER\DLEToolbar\Components\Entry;

use iCooLER\DLEToolbar\Components\Link\NewsFields;

/** @phpstan-import-type EntryData from Entry */
final class NewsEntry
{
    /** Access levels of `post_extras.access`, see news_permission(). */
    private const ACCESS = [1 => 'Только чтение', 2 => 'Чтение и комментарии', 3 => 'Нет доступа'];

    /**
     * @param array<string, scalar|array<array-key, string>|null> $row
     * @return EntryData
     */
    public static function build(array $row): array
    {
        // show.full.php splits the tags while compiling the full story.
        $row = array_map(static fn ($value) => is_array($value) ? implode(',', $value) : $value, $row);
        global $cat_info, $user_group;
        /** @var array<int, array{name: string}> $cat_info */
        /** @var array<int, array{group_name: string}> $user_group */

        $categories = [];
        foreach (explode(',', (string) $row['category']) as $id) {
            if (isset($cat_info[(int) $id])) $categories[] = html_entity_decode($cat_info[(int) $id]['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $tags = array_filter(array_map('trim', explode(',', (string) $row['tags'])), static fn (string $tag): bool => $tag !== '');
        $author = (string) $row['autor'];

        $badges = [(int) $row['approve'] ? ['Опубликована', 'success'] : ['На модерации', 'warning']];
        if ((int) $row['date'] > time()) $badges[] = ['Отложенная публикация', 'info'];
        if ((int) $row['allow_main']) $badges[] = ['На главной', 'neutral'];
        if ((int) $row['fixed']) $badges[] = ['Закреплена', 'neutral'];
        if (!empty($row['need_pass'])) $badges[] = ['Доступ по паролю', 'warning'];

        $main = Format::rows($row, [
            ['Заголовок', 'title', [Format::class, 'text']],
            ['ID', 'id', [Format::class, 'code']],
        ]);
        $main[] = ['Категории', $categories ? Format::escape(implode(', ', $categories)) : Format::empty()];
        $main[] = ['Автор', $author === '' ? Format::empty() : Format::link(Format::profileUrl($author), $author)
            . (empty($row['user_id']) ? '' : ' <span class="entry-muted">ID ' . (int) $row['user_id'] . '</span>')];
        $main = array_merge($main, Format::rows($row, [
            ['Дата публикации', 'date', [Format::class, 'date']],
            ['Дата редактирования', 'editdate', [Format::class, 'date']],
            ['Редактор', 'editor', [Format::class, 'text']],
            ['Причина редактирования', 'reason', [Format::class, 'text']],
            ['Просмотры', 'news_read', [Format::class, 'number']],
            ['Комментарии', 'comm_num', [Format::class, 'number']],
            ['Рейтинг', 'rating', static fn ($rating): string => Format::escape((string) $rating)
                . ' <span class="entry-muted">· голосов: ' . (int) ($row['vote_num'] ?? 0) . '</span>'],
        ]));
        $main[] = ['Теги', $tags ? Format::text(implode(', ', $tags)) : Format::empty()];

        $fields = [];
        foreach (NewsFields::fields() as $field) {
            $fields[] = [Format::escape($field['label'] !== '' ? $field['label'] : $field['name'])
                . '<code>' . Format::escape($field['name']) . '</code>', Format::text($field['value'])];
        }

        $seo = Format::rows($row, [
            ['Метатег title', 'metatitle', [Format::class, 'text']],
            ['Описание', 'descr', [Format::class, 'text']],
            ['Ключевые слова', 'keywords', [Format::class, 'text']],
            ['Запретить индексацию', 'disable_index', [Format::class, 'flag']],
            ['Исключить из поиска', 'disable_search', [Format::class, 'flag']],
            ['В RSS', 'allow_rss', [Format::class, 'flag']],
            ['В Турбо-страницах', 'allow_rss_turbo', [Format::class, 'flag']],
            ['В Дзене', 'allow_rss_dzen', [Format::class, 'flag']],
        ]);

        $access = [];
        foreach (explode('||', (string) ($row['access'] ?? '')) as $item) {
            [$group, $level] = array_pad(explode(':', $item, 2), 2, '');
            if (isset($user_group[(int) $group], self::ACCESS[(int) $level])) {
                $access[] = $user_group[(int) $group]['group_name'] . ': ' . self::ACCESS[(int) $level];
            }
        }
        $options = Format::rows($row, [
            ['Комментарии разрешены', 'allow_comm', [Format::class, 'flag']],
            ['Рейтинг разрешён', 'allow_rate', [Format::class, 'flag']],
            ['Показывать причину правки', 'view_edit', [Format::class, 'flag']],
            ['Автоперенос строк', 'allow_br', [Format::class, 'flag']],
            ['Буква каталога', 'symbol', [Format::class, 'text']],
            ['Похожие новости', 'related_ids', [Format::class, 'text']],
            ['Разрешённые страны', 'allowed_country', [Format::class, 'text']],
            ['Запрещённые страны', 'not_allowed_country', [Format::class, 'text']],
        ]);
        $options[] = ['Доступ групп', $access ? implode('<br>', array_map([Format::class, 'escape'], $access)) : '<span class="entry-muted">по настройкам групп</span>'];

        $links = [];
        if (Format::allows('admin_editnews')) {
            $links[] = ['label' => 'Редактировать в админке', 'url' => Format::adminUrl('mod=editnews&action=editnews&id=' . (int) $row['id']), 'icon' => 'edit'];
        }

        return [
            'kind' => 'Новость',
            'title' => html_entity_decode((string) $row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'id' => (string) (int) $row['id'],
            'badges' => $badges,
            'links' => $links,
            'tabs' => array_values(array_filter([
                ['id' => 'main', 'label' => 'Основное', 'rows' => $main],
                ['id' => 'fields', 'label' => 'Допполя', 'rows' => $fields],
                ['id' => 'seo', 'label' => 'SEO', 'rows' => $seo],
                ['id' => 'options', 'label' => 'Параметры', 'rows' => $options],
                // The stories are already split into pages and compiled at capture time.
                ['id' => 'raw', 'label' => 'Все поля', 'rows' => Format::raw($row, ['short_story', 'full_story', 'cats', 'xfields', 'xfields_array'])],
            ], static fn (array $tab): bool => $tab['rows'] !== [])),
        ];
    }
}
