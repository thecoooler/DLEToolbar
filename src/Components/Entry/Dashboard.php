<?php

namespace iCooLER\DLEToolbar\Components\Entry;

/**
 * HTML of the overview tab: key figures and cards with a verdict. All values arrive as escaped HTML.
 * @phpstan-import-type EntryRow from Entry
 * @phpstan-type DashboardCard array{title: string, verdict: string, tone: 'success'|'warning'|'info'|'danger'|'neutral', facts: list<EntryRow>, notes: list<string>, raw?: string}
 */
final class Dashboard
{
    /**
     * Active layout variant:
     * - 'a': classic (tiles on top + verdict cards)
     * - 'b': compact hero (status banner + 2-col metric grid + collapsible details)
     * - 'c': vertical timeline (single-line metrics strip + event timeline)
     */
    public const LAYOUT = 'a';

    /**
     * @param list<array{0: string, 1: string, 2?: string}> $tiles label, value, optional hint
     * @param list<DashboardCard|string> $cards
     */
    public static function render(array $tiles, array $cards): string
    {
        return self::renderLayout(self::LAYOUT, $tiles, $cards);
    }

    /**
     * @param 'a'|'b'|'c' $layout
     * @param list<array{0: string, 1: string, 2?: string}> $tiles
     * @param list<DashboardCard|string> $cards
     */
    public static function renderLayout(string $layout, array $tiles, array $cards): string
    {
        switch ($layout) {
            case 'b':
                return self::renderHero($tiles, $cards);
            case 'c':
                return self::renderTimeline($tiles, $cards);
            case 'a':
            default:
                return self::renderClassic($tiles, $cards);
        }
    }

    /**
     * Variant A: Classic grid of key-value tiles followed by cards with left accent tone.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $tiles
     * @param list<DashboardCard|string> $cards
     */
    public static function renderClassic(array $tiles, array $cards): string
    {
        $tilesHtml = '';
        foreach ($tiles as $tile) {
            $tilesHtml .= '<div class="entry-tile"><span class="entry-tile-label">' . Format::escape($tile[0]) . '</span>'
                . '<span class="entry-tile-value">' . $tile[1] . '</span>'
                . (isset($tile[2]) ? '<span class="entry-tile-hint">' . $tile[2] . '</span>' : '') . '</div>';
        }
        $cardsHtml = '';
        foreach ($cards as $card) {
            $cardsHtml .= is_array($card) ? self::renderCard($card) : (string) $card;
        }
        return '<div class="entry-dash entry-dash--classic"><div class="entry-tiles">' . $tilesHtml . '</div>'
            . '<div class="entry-cards">' . $cardsHtml . '</div></div>';
    }

    /**
     * Variant B: Compact Hero with prominent status badges, meta details, 2-column metric grid,
     * and collapsible <details> accordions for edits and access restrictions.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $tiles
     * @param list<DashboardCard|string> $cards
     */
    public static function renderHero(array $tiles, array $cards): string
    {
        $normalized = array_map([self::class, 'normalizeCard'], $cards);
        $primary = $normalized[0] ?? null;
        $secondary = array_slice($normalized, 1);

        $heroHtml = '';
        if ($primary !== null) {
            $badges = '<span class="entry-hero-badge" data-tone="' . Format::escape($primary['tone']) . '">'
                . Format::escape($primary['verdict']) . '</span>';

            foreach ($secondary as $card) {
                if ($card['title'] === 'Доступ' || $card['title'] === 'Видимость и доступ') {
                    $badges .= '<span class="entry-hero-badge" data-tone="' . Format::escape($card['tone']) . '">'
                        . Format::escape($card['title']) . ': ' . Format::escape($card['verdict']) . '</span>';
                    break;
                }
            }

            $metaItems = [];
            foreach ($primary['facts'] as [$label, $value]) {
                $metaItems[] = '<span class="entry-hero-fact"><span class="entry-hero-label">'
                    . Format::escape($label) . ':</span> <span class="entry-hero-val">' . $value . '</span></span>';
            }
            $metaHtml = implode(' <span class="entry-hero-sep" aria-hidden="true">·</span> ', $metaItems);

            $heroHtml = '<div class="entry-hero">'
                . '<div class="entry-hero-head"><div class="entry-hero-badges">' . $badges . '</div></div>'
                . ($metaHtml === '' ? '' : '<div class="entry-hero-meta">' . $metaHtml . '</div>')
                . '</div>';
        }

        $gridHtml = '';
        foreach ($tiles as $tile) {
            $gridHtml .= '<div class="entry-hero-metric">'
                . '<span class="entry-hero-metric-label">' . Format::escape($tile[0]) . '</span>'
                . '<span class="entry-hero-metric-val">' . $tile[1] . '</span>'
                . (isset($tile[2]) ? '<span class="entry-hero-metric-hint">' . $tile[2] . '</span>' : '')
                . '</div>';
        }

        $collapsesHtml = '';
        foreach ($secondary as $card) {
            if (isset($card['raw'])) {
                $collapsesHtml .= $card['raw'];
                continue;
            }

            $isOpen = ($card['tone'] === 'warning' || $card['tone'] === 'danger'
                || ($card['title'] === 'Редактирование' && $card['verdict'] === 'Редактировалась'));

            $factsList = '';
            foreach ($card['facts'] as [$label, $value]) {
                $factsList .= '<dt>' . Format::escape($label) . '</dt><dd>' . $value . '</dd>';
            }
            $notesList = '';
            foreach ($card['notes'] as $note) {
                $notesList .= '<li>' . $note . '</li>';
            }

            $bodyHtml = '';
            if ($factsList !== '') $bodyHtml .= '<dl class="entry-facts">' . $factsList . '</dl>';
            if ($notesList !== '') $bodyHtml .= '<ul class="entry-notes">' . $notesList . '</ul>';
            if ($bodyHtml === '') {
                $bodyHtml = '<p class="entry-collapse-empty">Дополнительные сведения отсутствуют.</p>';
            }

            $collapsesHtml .= '<details class="entry-collapse" data-tone="' . Format::escape($card['tone']) . '"'
                . ($isOpen ? ' open' : '') . '>'
                . '<summary>'
                . '<span class="entry-collapse-title">'
                . '<span class="entry-collapse-name">' . Format::escape($card['title']) . '</span>'
                . '<span class="entry-badge" data-tone="' . Format::escape($card['tone']) . '">'
                . Format::escape($card['verdict']) . '</span></span>'
                . '<span class="entry-collapse-toggle" aria-hidden="true"></span></summary>'
                . '<div class="entry-collapse-body">' . $bodyHtml . '</div></details>';
        }

        return '<div class="entry-dash entry-dash--hero">'
            . $heroHtml
            . ($gridHtml === '' ? '' : '<div class="entry-hero-grid">' . $gridHtml . '</div>')
            . ($collapsesHtml === '' ? '' : '<div class="entry-collapses">' . $collapsesHtml . '</div>')
            . '</div>';
    }

    /**
     * Variant C: Single horizontal row of key metrics followed by a vertical event timeline.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $tiles
     * @param list<DashboardCard|string> $cards
     */
    public static function renderTimeline(array $tiles, array $cards): string
    {
        $stripHtml = '';
        foreach ($tiles as $tile) {
            $stripHtml .= '<div class="entry-timeline-stat">'
                . '<span class="entry-timeline-stat-val">' . $tile[1] . '</span>'
                . '<span class="entry-timeline-stat-label">' . Format::escape($tile[0]) . '</span>'
                . (isset($tile[2]) ? '<span class="entry-timeline-stat-hint">' . $tile[2] . '</span>' : '')
                . '</div>';
        }

        $itemsHtml = '';
        foreach ($cards as $card) {
            $data = self::normalizeCard($card);
            if (isset($data['raw'])) {
                $itemsHtml .= $data['raw'];
                continue;
            }

            $reason = null;
            $factsList = '';
            foreach ($data['facts'] as [$label, $value]) {
                if ($label === 'Причина' && $value !== '' && $value !== Format::empty()) {
                    $reason = $value;
                } else {
                    $factsList .= '<dt>' . Format::escape($label) . '</dt><dd>' . $value . '</dd>';
                }
            }

            $notesList = '';
            foreach ($data['notes'] as $note) {
                $notesList .= '<li>' . $note . '</li>';
            }

            $bodyHtml = '';
            if ($factsList !== '') $bodyHtml .= '<dl class="entry-facts">' . $factsList . '</dl>';
            if ($reason !== null) {
                $bodyHtml .= '<div class="entry-timeline-callout">'
                    . '<span class="entry-timeline-callout-label">Причина правки:</span>'
                    . '<span class="entry-timeline-callout-value">' . $reason . '</span></div>';
            }
            if ($notesList !== '') $bodyHtml .= '<ul class="entry-notes">' . $notesList . '</ul>';
            if ($bodyHtml === '') {
                $bodyHtml = '<p class="entry-timeline-empty">Изменений или ограничений не зафиксировано.</p>';
            }

            $itemsHtml .= '<div class="entry-timeline-item" data-tone="' . Format::escape($data['tone']) . '">'
                . '<div class="entry-timeline-marker" aria-hidden="true"></div>'
                . '<div class="entry-timeline-box">'
                . '<div class="entry-timeline-head">'
                . '<h3 class="entry-timeline-title">' . Format::escape($data['title']) . '</h3>'
                . '<span class="entry-badge" data-tone="' . Format::escape($data['tone']) . '">'
                . Format::escape($data['verdict']) . '</span></div>'
                . '<div class="entry-timeline-body">' . $bodyHtml . '</div></div></div>';
        }

        return '<div class="entry-dash entry-dash--timeline">'
            . ($stripHtml === '' ? '' : '<div class="entry-timeline-strip">' . $stripHtml . '</div>')
            . ($itemsHtml === '' ? '' : '<div class="entry-timeline">' . $itemsHtml . '</div>')
            . '</div>';
    }

    /**
     * @param 'success'|'warning'|'info'|'danger'|'neutral' $tone
     * @param list<EntryRow> $facts label and value HTML
     * @param list<string> $notes HTML lines
     * @return DashboardCard
     */
    public static function card(string $title, string $verdict, string $tone, array $facts = [], array $notes = []): array
    {
        return [
            'title' => $title,
            'verdict' => $verdict,
            'tone' => $tone,
            'facts' => $facts,
            'notes' => $notes,
        ];
    }

    /**
     * Renders a classic card matching the original markup.
     * @param DashboardCard $card
     */
    public static function renderCard(array $card): string
    {
        $list = '';
        foreach ($card['facts'] as [$label, $value]) {
            $list .= '<dt>' . Format::escape($label) . '</dt><dd>' . $value . '</dd>';
        }
        $items = '';
        foreach ($card['notes'] as $note) {
            $items .= '<li>' . $note . '</li>';
        }
        return '<section class="entry-card" data-tone="' . Format::escape($card['tone']) . '"><div class="entry-card-head">'
            . '<h3 class="entry-card-title">' . Format::escape($card['title']) . '</h3>'
            . '<p class="entry-verdict">' . Format::escape($card['verdict']) . '</p></div>'
            . ($list === '' ? '' : '<dl class="entry-facts">' . $list . '</dl>')
            . ($items === '' ? '' : '<ul class="entry-notes">' . $items . '</ul>') . '</section>';
    }

    /**
     * @param DashboardCard|string $card
     * @return DashboardCard
     */
    private static function normalizeCard($card): array
    {
        if (is_array($card)) {
            return [
                'title' => (string) ($card['title'] ?? ''),
                'verdict' => (string) ($card['verdict'] ?? ''),
                'tone' => (string) ($card['tone'] ?? 'neutral'),
                'facts' => (array) ($card['facts'] ?? []),
                'notes' => (array) ($card['notes'] ?? []),
            ];
        }
        return [
            'title' => '',
            'verdict' => '',
            'tone' => 'neutral',
            'facts' => [],
            'notes' => [],
            'raw' => (string) $card,
        ];
    }
}
