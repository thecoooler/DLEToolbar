<?php

namespace iCooLER\DLEToolbar\Components\Entry;

/** @phpstan-import-type EntryData from Entry */
final class CategoryEntry
{
    private const SORT = ['date' => 'Дата публикации', 'editdate' => 'Дата редактирования', 'rating' => 'Рейтинг',
        'news_read' => 'Просмотры', 'title' => 'Алфавит', 'comm_num' => 'Комментарии'];
    private const DIRECTION = ['DESC' => 'По убыванию', 'ASC' => 'По возрастанию'];
    private const GLOBAL = '<span class="entry-muted">по настройкам сайта</span>';

    /**
     * @param array<int, array<string, scalar|null>> $categories DLE's $cat_info.
     * @return EntryData
     */
    public static function build(int $id, array $categories): array
    {
        $row = $categories[$id];
        $name = static fn (int $id): string => html_entity_decode((string) $categories[$id]['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $global = self::GLOBAL;

        $parent = (int) $row['parentid'];
        $children = [];
        foreach ($categories as $child => $category) {
            if ((int) $category['parentid'] === $id) $children[] = $name($child);
        }

        $badges = [];
        if (array_key_exists('active', $row)) {
            $badges[] = (int) $row['active'] ? ['Активна', 'success'] : ['Отключена', 'warning'];
        }
        if (!empty($row['disable_main'])) $badges[] = ['Скрыта с главной', 'neutral'];

        $main = Format::rows($row, [
            ['Название', 'name', [Format::class, 'text']],
            ['ID', 'id', [Format::class, 'code']],
            ['Альтернативное имя', 'alt_name', [Format::class, 'code']],
        ]);
        $main[] = ['Родительская категория', isset($categories[$parent])
            ? Format::escape($name($parent)) . ' <span class="entry-muted">ID ' . $parent . '</span>'
            : '<span class="entry-muted">корневая</span>'];
        $main[] = ['Подкатегории', $children ? Format::text(implode(', ', $children)) : Format::empty()];
        $main = array_merge($main, Format::rows($row, [
            ['Позиция', 'posi', [Format::class, 'number']],
            ['Новостей на странице', 'news_number', static fn ($value): string => (int) $value ? Format::number($value) : $global],
            ['Сортировка', 'news_sort', static fn ($value): string => self::choice(self::SORT, $value)],
            ['Порядок сортировки', 'news_msort', static fn ($value): string => self::choice(self::DIRECTION, $value)],
            ['Иконка', 'icon', [Format::class, 'text']],
        ]));

        $seo = Format::rows($row, [
            ['Метатег title', 'metatitle', [Format::class, 'text']],
            ['Описание', 'descr', [Format::class, 'text']],
            ['Ключевые слова', 'keywords', [Format::class, 'text']],
            ['Полное описание', 'fulldescr', [Format::class, 'text']],
            ['Запретить индексацию', 'disable_index', [Format::class, 'flag']],
            ['Микроразметка', 'schema_org', static fn ($value): string => (string) $value === '1' ? $global
                : ((string) $value === '0' ? '<span class="entry-muted">отключена</span>' : Format::code($value))],
        ]);

        $templates = Format::rows($row, [
            ['Шаблон сайта', 'skin', [Format::class, 'code']],
            ['Краткая новость', 'short_tpl', [Format::class, 'code']],
            ['Полная новость', 'full_tpl', [Format::class, 'code']],
        ]);

        $options = Format::rows($row, [
            ['Новости подкатегорий', 'show_sub', static fn ($value): string => self::choice(['1' => 'Да', '2' => 'Нет'], $value)],
            ['В RSS', 'allow_rss', [Format::class, 'flag']],
            ['Исключить из поиска', 'disable_search', [Format::class, 'flag']],
            ['Скрыть с главной', 'disable_main', [Format::class, 'flag']],
            ['Отключить рейтинг', 'disable_rating', [Format::class, 'flag']],
            ['Отключить комментарии', 'disable_comments', [Format::class, 'flag']],
            ['В Дзене', 'enable_dzen', [Format::class, 'flag']],
            ['В Турбо-страницах', 'enable_turbo', [Format::class, 'flag']],
            ['Тип рейтинга', 'rating_type', static fn ($value): string => (int) $value < 0 ? $global : Format::code($value)],
        ]);

        $active = !array_key_exists('active', $row) || (int) $row['active'] === 1;
        $restrictions = [];
        foreach ([
            'disable_main' => 'Скрыта с главной',
            'disable_index' => 'Индексация запрещена',
            'disable_search' => 'Исключена из поиска',
            'disable_rating' => 'Рейтинг отключён',
            'disable_comments' => 'Комментарии отключены',
        ] as $column => $note) {
            if (!empty($row[$column])) $restrictions[] = $note;
        }

        $overview = Dashboard::render([
            ['Подкатегорий', Format::number(count($children))],
            ['Новостей на странице', (int) ($row['news_number'] ?? 0) ? Format::number($row['news_number']) : $global],
            ['Сортировка', self::choice(self::SORT, $row['news_sort'] ?? '')],
        ], [
            Dashboard::card('Статус', $active ? 'Активна' : 'Отключена', $active ? 'success' : 'warning', [
                ['Родитель', isset($categories[$parent]) ? Format::escape($name($parent)) : '<span class="entry-muted">корневая</span>'],
                ['Подкатегории', $children ? Format::text(implode(', ', $children)) : Format::empty()],
            ]),
            Dashboard::card('Видимость и доступ', $restrictions ? 'Есть ограничения' : 'Без ограничений',
                $restrictions ? 'warning' : 'success', [], array_map([Format::class, 'escape'], $restrictions)),
        ]);

        $links = [];
        if (Format::allows('admin_categories')) {
            $links[] = ['label' => 'Редактировать в админке', 'url' => Format::adminUrl('mod=categories&action=edit&catid=' . $id), 'icon' => 'edit'];
        }

        return [
            'kind' => 'Категория',
            'title' => $name($id),
            'id' => (string) $id,
            'badges' => $badges,
            'links' => $links,
            'tabs' => array_values(array_filter([
                ['id' => 'overview', 'label' => 'Обзор', 'rows' => [], 'dashboard' => $overview],
                ['id' => 'main', 'label' => 'Основное', 'rows' => $main],
                ['id' => 'seo', 'label' => 'SEO', 'rows' => $seo],
                ['id' => 'templates', 'label' => 'Шаблоны', 'rows' => $templates],
                ['id' => 'options', 'label' => 'Параметры', 'rows' => $options],
                ['id' => 'raw', 'label' => 'Все поля', 'rows' => Format::raw($row, [])],
            ], static fn (array $tab): bool => $tab['rows'] !== [] || isset($tab['dashboard']))),
        ];
    }

    /**
     * @param array<array-key, string> $labels
     * @param scalar|null $value
     */
    private static function choice(array $labels, $value): string
    {
        return isset($labels[(string) $value]) ? Format::escape($labels[(string) $value]) : self::GLOBAL;
    }
}
