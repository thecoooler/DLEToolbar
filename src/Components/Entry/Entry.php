<?php

namespace iCooLER\DLEToolbar\Components\Entry;

use iCooLER\DLEToolbar\ComponentFramework\Component;

/**
 * Shows the data of the viewed news, category or user profile in a tabbed panel.
 * Rows hold the HTML of the label and the value.
 * @phpstan-type EntryRow array{0: string, 1: string}
 * @phpstan-type EntryTab array{id: string, label: string, rows: list<EntryRow>, dashboard?: string} A dashboard tab shows ready HTML instead of rows.
 * @phpstan-type EntryData array{kind: string, title: string, id: string, badges: list<array{0: string, 1: string}>, links: list<array{label: string, url: string, icon: string}>, tabs: list<EntryTab>}
 */
final class Entry implements Component
{
    public static function definition(): array
    {
        return ['label' => 'Сведения о странице', 'description' => 'Данные просматриваемой новости, категории или профиля пользователя: даты, статус, допполя, SEO и параметры.'];
    }

    public function render(): ?array
    {
        $entry = self::current();
        if ($entry === null) return null;

        $label = 'Сведения о странице: ' . $entry['kind'] . ' «' . $entry['title'] . '»';
        return [
            'control' => '<button class="control entry-open" data-component="entry" data-entry-open type="button"'
                . ' aria-expanded="false" aria-controls="dle-toolbar-entry-panel" aria-label="' . Format::escape($label) . '">'
                . '<span class="entry-open-icon">' . self::icon('info') . '</span>'
                . '<span class="entry-close-icon">' . self::icon('close') . '</span></button>',
            'panel' => self::styles() . self::panel($entry),
            'script' => self::script(),
        ];
    }

    /** @return EntryData|null */
    private static function current(): ?array
    {
        global $dle_module, $news_found, $category_id, $cat_info;
        /** @var string $dle_module */
        /** @var array<int, array<string, scalar|null>> $cat_info */

        $news = PageEntry::news();
        if ($dle_module === 'showfull' && !empty($news_found) && $news !== null) return NewsEntry::build($news);
        $user = PageEntry::user();
        if ($dle_module === 'userinfo' && $user !== null) return UserEntry::build($user);
        if ($dle_module === 'cat' && is_numeric($category_id) && isset($cat_info[(int) $category_id])) {
            return CategoryEntry::build((int) $category_id, $cat_info);
        }
        return null;
    }

    /** @param EntryData $entry */
    private static function panel(array $entry): string
    {
        $badges = '';
        foreach ($entry['badges'] as [$text, $tone]) {
            $badges .= '<span class="entry-badge" data-tone="' . $tone . '">' . Format::escape($text) . '</span>';
        }
        $links = '';
        foreach ($entry['links'] as $link) {
            $links .= '<a class="entry-action" href="' . Format::escape($link['url']) . '" target="_blank" rel="noopener"'
                . ' title="' . Format::escape($link['label']) . '" aria-label="' . Format::escape($link['label']) . '">'
                . self::icon($link['icon']) . '</a>';
        }

        $tabs = $panels = '';
        foreach ($entry['tabs'] as $index => $tab) {
            $selected = $index === 0;
            $tabId = 'dle-toolbar-entry-tab-' . $tab['id'];
            $panelId = 'dle-toolbar-entry-tabpanel-' . $tab['id'];
            $tabs .= '<button class="entry-tab" id="' . $tabId . '" data-entry-tab="' . $tab['id'] . '" type="button" role="tab"'
                . ' aria-selected="' . ($selected ? 'true' : 'false') . '" aria-controls="' . $panelId . '"'
                . ' tabindex="' . ($selected ? '0' : '-1') . '">' . Format::escape($tab['label'])
                . (isset($tab['dashboard']) ? '' : '<span class="entry-count">' . count($tab['rows']) . '</span>') . '</button>';
            $rows = '';
            foreach ($tab['rows'] as [$label, $value]) {
                $rows .= '<dt>' . $label . '</dt><dd>' . $value . '</dd>';
            }
            $panels .= '<div class="entry-body" id="' . $panelId . '" role="tabpanel" aria-labelledby="' . $tabId . '" tabindex="0"'
                . ($selected ? '' : ' hidden') . '>' . ($tab['dashboard'] ?? '<dl class="entry-list">' . $rows . '</dl>') . '</div>';
        }

        return '<section class="entry-panel" data-entry-panel data-kind="' . Format::escape($entry['kind']) . '" id="dle-toolbar-entry-panel"'
            . ' aria-labelledby="dle-toolbar-entry-title" hidden>'
            . '<header class="entry-header"><div class="entry-heading">'
            . '<p class="entry-kind">' . Format::escape($entry['kind']) . ' <span>ID ' . Format::escape($entry['id']) . '</span></p>'
            . '<h2 id="dle-toolbar-entry-title">' . Format::escape($entry['title']) . '</h2>'
            . ($badges === '' ? '' : '<div class="entry-badges">' . $badges . '</div>')
            . '</div>' . $links
            . '<button class="entry-action" data-entry-close type="button" aria-label="Закрыть сведения о странице">'
            . self::icon('close') . '</button></header>'
            . '<div class="entry-tabs" role="tablist" aria-label="Разделы сведений">' . $tabs . '</div>'
            . $panels . '</section>';
    }

    private static function styles(): string
    {
        return <<<'HTML'
        <style>
            .entry-open[aria-expanded="true"] {
                background: var(--hover);
                color: var(--foreground);
                box-shadow: inset 0 0 0 1px var(--border);
            }
            .entry-open .entry-close-icon { display: none; }
            .entry-open[aria-expanded="true"] .entry-open-icon { display: none; }
            .entry-open[aria-expanded="true"] .entry-close-icon { display: block; }
            .entry-panel {
                display: flex;
                flex-direction: column;
                width: min(760px, 100%);
                height: min(560px, calc(100dvh - 96px));
                overflow: hidden;
                border: 1px solid var(--border);
                border-radius: 14px;
                background: var(--surface);
                color: var(--foreground);
                box-shadow: var(--shadow);
                pointer-events: auto;
                transform-origin: left bottom;
                animation: entry-panel-in 180ms cubic-bezier(.2, .8, .2, 1);
            }
            .entry-panel[hidden] { display: none; }
            .entry-panel[data-closing="true"] {
                pointer-events: none;
                animation: entry-panel-out 150ms ease-in forwards;
            }
            @keyframes entry-panel-in {
                from { opacity: 0; transform: translateY(8px) scale(.99); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }
            @keyframes entry-panel-out {
                from { opacity: 1; transform: translateY(0) scale(1); }
                to { opacity: 0; transform: translateY(6px) scale(.99); }
            }
            .entry-header {
                display: flex;
                flex: 0 0 auto;
                align-items: flex-start;
                gap: 6px;
                padding: 14px 14px 12px 18px;
            }
            .entry-heading { flex: 1 1 auto; min-width: 0; margin-right: 6px; }
            .entry-kind {
                margin: 0 0 3px;
                color: var(--muted);
                font: 600 11px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                letter-spacing: .04em;
                text-transform: uppercase;
            }
            .entry-kind span { margin-left: 6px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: 0; }
            .entry-header h2 {
                margin: 0;
                color: var(--foreground);
                font: 650 16px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                letter-spacing: -.02em;
                overflow-wrap: anywhere;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            .entry-badges { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
            .entry-badge {
                padding: 2px 8px;
                border: 1px solid var(--border);
                border-radius: 999px;
                color: var(--muted);
                font-size: 11px;
                line-height: 1.5;
                white-space: nowrap;
            }
            .entry-badge[data-tone="success"] { border-color: rgb(22 163 74 / 30%); background: rgb(22 163 74 / 8%); color: #15803d; }
            .entry-badge[data-tone="warning"] { border-color: rgb(217 119 6 / 30%); background: rgb(217 119 6 / 8%); color: #b45309; }
            .entry-badge[data-tone="info"] { border-color: rgb(37 99 235 / 30%); background: rgb(37 99 235 / 8%); color: #1d4ed8; }
            .entry-badge[data-tone="danger"] { border-color: rgb(185 28 28 / 30%); background: rgb(185 28 28 / 8%); color: #b91c1c; }
            :host([data-theme="dark"]) .entry-badge[data-tone="success"] { color: #4ade80; }
            :host([data-theme="dark"]) .entry-badge[data-tone="warning"] { color: #fbbf24; }
            :host([data-theme="dark"]) .entry-badge[data-tone="info"] { color: #93c5fd; }
            :host([data-theme="dark"]) .entry-badge[data-tone="danger"] { color: #fca5a5; }
            .entry-action {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex: 0 0 auto;
                width: 30px;
                height: 30px;
                padding: 0;
                border: 1px solid var(--border);
                border-radius: 8px;
                background: var(--surface);
                color: var(--muted);
                cursor: pointer;
                transition: background-color 140ms ease, color 140ms ease;
            }
            .entry-action:hover { background: var(--hover); color: var(--foreground); }
            .entry-action:focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; }
            .entry-action svg { width: 16px; height: 16px; }
            .entry-tabs {
                display: flex;
                flex: 0 0 auto;
                gap: 2px;
                padding: 0 12px;
                border-bottom: 1px solid var(--border);
                overflow-x: auto;
                scrollbar-width: none;
            }
            .entry-tab {
                position: relative;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                flex: 0 0 auto;
                padding: 8px 8px 10px;
                border: 0;
                background: transparent;
                color: var(--muted);
                font: 600 12px/1.2 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                cursor: pointer;
                transition: color 140ms ease;
            }
            .entry-tab:hover { color: var(--foreground); }
            .entry-tab:focus-visible { outline: 2px solid var(--foreground); outline-offset: -4px; border-radius: 8px; }
            .entry-tab[aria-selected="true"] { color: var(--foreground); }
            .entry-tab[aria-selected="true"]::after {
                content: '';
                position: absolute;
                right: 8px;
                bottom: -1px;
                left: 8px;
                height: 2px;
                border-radius: 2px 2px 0 0;
                background: var(--foreground);
            }
            .entry-count {
                min-width: 18px;
                padding: 1px 5px;
                border-radius: 999px;
                background: var(--hover);
                color: var(--muted);
                font: 600 10px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                text-align: center;
            }
            .entry-body {
                flex: 1 1 auto;
                min-height: 0;
                padding: 4px 18px 10px;
                overflow: auto;
                overscroll-behavior: contain;
            }
            .entry-body[hidden] { display: none; }
            .entry-body:focus-visible { outline: 2px solid var(--foreground); outline-offset: -2px; }
            .entry-dash { display: grid; gap: 10px; padding: 6px 0 4px; }
            .entry-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(132px, 1fr)); gap: 8px; }
            .entry-tile {
                min-width: 0;
                padding: 10px 12px;
                border: 1px solid var(--border);
                border-radius: 10px;
                background: var(--row-stripe);
            }
            .entry-tile-label { display: block; color: var(--muted); font-size: 11px; line-height: 1.3; }
            .entry-tile-value {
                display: block;
                margin-top: 3px;
                color: var(--foreground);
                font: 650 18px/1.25 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                font-variant-numeric: tabular-nums;
                letter-spacing: -.02em;
                overflow-wrap: anywhere;
            }
            .entry-tile-hint { display: block; margin-top: 1px; color: var(--muted); font-size: 11px; line-height: 1.3; overflow-wrap: anywhere; }
            .entry-cards { display: grid; gap: 8px; }
            .entry-card {
                --tone: var(--border);
                min-width: 0;
                padding: 10px 12px 10px 14px;
                border: 1px solid var(--border);
                border-left: 3px solid var(--tone);
                border-radius: 10px;
            }
            .entry-card[data-tone="success"] { --tone: #16a34a; }
            .entry-card[data-tone="warning"] { --tone: #d97706; }
            .entry-card[data-tone="info"] { --tone: #2563eb; }
            .entry-card[data-tone="danger"] { --tone: #b91c1c; }
            :host([data-theme="dark"]) .entry-card[data-tone="success"] { --tone: #4ade80; }
            :host([data-theme="dark"]) .entry-card[data-tone="warning"] { --tone: #fbbf24; }
            :host([data-theme="dark"]) .entry-card[data-tone="info"] { --tone: #93c5fd; }
            :host([data-theme="dark"]) .entry-card[data-tone="danger"] { --tone: #fca5a5; }
            .entry-card-head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
            .entry-card-title { margin: 0; color: var(--muted); font: 600 11px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; letter-spacing: .04em; text-transform: uppercase; }
            .entry-verdict { margin: 0; color: var(--foreground); font-weight: 650; text-align: right; }
            .entry-facts { display: grid; grid-template-columns: minmax(96px, 30%) minmax(0, 1fr); gap: 3px 12px; margin: 8px 0 0; font-size: 12.5px; line-height: 1.45; }
            .entry-facts > dt { margin: 0; color: var(--muted); font-weight: 500; }
            .entry-facts > dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
            .entry-notes { margin: 8px 0 0; padding: 0; list-style: none; font-size: 12.5px; line-height: 1.45; }
            .entry-notes li { position: relative; padding-left: 14px; overflow-wrap: anywhere; }
            .entry-notes li + li { margin-top: 2px; }
            .entry-notes li::before { content: ''; position: absolute; top: .55em; left: 2px; width: 5px; height: 5px; border-radius: 50%; background: var(--tone); }

            /* Variant B: Hero & Details */
            .entry-hero {
                display: flex;
                flex-direction: column;
                gap: 8px;
                padding: 12px 14px;
                border: 1px solid var(--border);
                border-radius: 12px;
                background: var(--row-stripe);
            }
            .entry-hero-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 8px;
            }
            .entry-hero-badges {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 6px;
            }
            .entry-hero-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 3px 10px;
                border: 1px solid var(--border);
                border-radius: 999px;
                font: 600 12px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                white-space: nowrap;
            }
            .entry-hero-badge[data-tone="success"] { border-color: rgb(22 163 74 / 35%); background: rgb(22 163 74 / 10%); color: #15803d; }
            .entry-hero-badge[data-tone="warning"] { border-color: rgb(217 119 6 / 35%); background: rgb(217 119 6 / 10%); color: #b45309; }
            .entry-hero-badge[data-tone="info"] { border-color: rgb(37 99 235 / 35%); background: rgb(37 99 235 / 10%); color: #1d4ed8; }
            .entry-hero-badge[data-tone="danger"] { border-color: rgb(185 28 28 / 35%); background: rgb(185 28 28 / 10%); color: #b91c1c; }
            .entry-hero-badge[data-tone="neutral"] { border-color: var(--border); background: var(--surface); color: var(--foreground); }
            :host([data-theme="dark"]) .entry-hero-badge[data-tone="success"] { color: #4ade80; }
            :host([data-theme="dark"]) .entry-hero-badge[data-tone="warning"] { color: #fbbf24; }
            :host([data-theme="dark"]) .entry-hero-badge[data-tone="info"] { color: #93c5fd; }
            :host([data-theme="dark"]) .entry-hero-badge[data-tone="danger"] { color: #fca5a5; }
            :host([data-theme="dark"]) .entry-hero-badge[data-tone="neutral"] { color: var(--foreground); }
            .entry-hero-meta {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px 8px;
                color: var(--foreground);
                font-size: 12px;
                line-height: 1.4;
            }
            .entry-hero-fact { display: inline-flex; align-items: baseline; gap: 4px; }
            .entry-hero-label { color: var(--muted); font-weight: 500; }
            .entry-hero-val { font-weight: 550; }
            .entry-hero-sep { color: var(--border); font-weight: 400; }
            .entry-hero-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            .entry-hero-grid .entry-hero-metric:last-child:nth-child(odd) {
                grid-column: 1 / -1;
            }
            .entry-hero-metric {
                min-width: 0;
                padding: 9px 12px;
                border: 1px solid var(--border);
                border-radius: 10px;
                background: var(--surface);
            }
            .entry-hero-metric-label {
                display: block;
                color: var(--muted);
                font-size: 11px;
                line-height: 1.3;
            }
            .entry-hero-metric-val {
                display: block;
                margin-top: 2px;
                color: var(--foreground);
                font: 650 17px/1.25 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                font-variant-numeric: tabular-nums;
                letter-spacing: -.02em;
                overflow-wrap: anywhere;
            }
            .entry-hero-metric-hint {
                display: block;
                margin-top: 1px;
                color: var(--muted);
                font-size: 11px;
                line-height: 1.3;
            }
            .entry-collapses {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .entry-collapse {
                --tone: var(--border);
                border: 1px solid var(--border);
                border-radius: 10px;
                background: var(--surface);
                overflow: hidden;
                transition: border-color 140ms ease;
            }
            .entry-collapse[data-tone="success"] { --tone: #16a34a; }
            .entry-collapse[data-tone="warning"] { --tone: #d97706; border-color: rgb(217 119 6 / 35%); }
            .entry-collapse[data-tone="danger"] { --tone: #b91c1c; border-color: rgb(185 28 28 / 35%); }
            .entry-collapse[data-tone="info"] { --tone: #2563eb; }
            :host([data-theme="dark"]) .entry-collapse[data-tone="success"] { --tone: #4ade80; }
            :host([data-theme="dark"]) .entry-collapse[data-tone="warning"] { --tone: #fbbf24; }
            :host([data-theme="dark"]) .entry-collapse[data-tone="danger"] { --tone: #fca5a5; }
            :host([data-theme="dark"]) .entry-collapse[data-tone="info"] { --tone: #93c5fd; }
            .entry-collapse summary {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                padding: 9px 12px;
                background: var(--row-stripe);
                cursor: pointer;
                list-style: none;
                user-select: none;
            }
            .entry-collapse summary::-webkit-details-marker { display: none; }
            .entry-collapse summary:hover { background: var(--hover); }
            .entry-collapse summary:focus-visible { outline: 2px solid var(--foreground); outline-offset: -2px; }
            .entry-collapse-title {
                display: flex;
                align-items: center;
                gap: 8px;
                min-width: 0;
                font: 600 12px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }
            .entry-collapse-name {
                color: var(--foreground);
                letter-spacing: .02em;
                text-transform: uppercase;
                font-size: 11px;
            }
            .entry-collapse-toggle {
                display: inline-block;
                width: 6px;
                height: 6px;
                border-right: 1.5px solid var(--muted);
                border-bottom: 1.5px solid var(--muted);
                transform: rotate(-45deg);
                transition: transform 140ms ease;
                margin-right: 2px;
            }
            .entry-collapse[open] .entry-collapse-toggle {
                transform: rotate(45deg);
            }
            .entry-collapse-body {
                padding: 10px 14px 12px;
                border-top: 1px solid var(--border);
                background: var(--surface);
            }
            .entry-collapse-body .entry-facts { margin: 0; }
            .entry-collapse-body .entry-notes { margin: 0; }
            .entry-collapse-empty {
                margin: 0;
                color: var(--muted);
                font-size: 12px;
            }

            /* Variant C: Timeline */
            .entry-timeline-strip {
                display: flex;
                align-items: stretch;
                gap: 6px;
                overflow-x: auto;
                padding: 2px 0 4px;
                scrollbar-width: none;
            }
            .entry-timeline-stat {
                flex: 1 1 0;
                min-width: 90px;
                padding: 8px 10px;
                border: 1px solid var(--border);
                border-radius: 9px;
                background: var(--row-stripe);
                text-align: center;
            }
            .entry-timeline-stat-val {
                display: block;
                color: var(--foreground);
                font: 650 16px/1.2 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                font-variant-numeric: tabular-nums;
                letter-spacing: -.02em;
                white-space: nowrap;
            }
            .entry-timeline-stat-label {
                display: block;
                margin-top: 2px;
                color: var(--muted);
                font-size: 11px;
                line-height: 1.25;
                white-space: nowrap;
            }
            .entry-timeline-stat-hint {
                display: block;
                margin-top: 1px;
                color: var(--muted);
                font-size: 10px;
                line-height: 1.2;
                white-space: nowrap;
            }
            .entry-timeline {
                position: relative;
                display: flex;
                flex-direction: column;
                gap: 10px;
                padding: 4px 0 4px 26px;
            }
            .entry-timeline::before {
                content: '';
                position: absolute;
                top: 14px;
                bottom: 14px;
                left: 9px;
                width: 2px;
                background: var(--border);
            }
            .entry-timeline-item {
                position: relative;
                --item-tone: var(--border);
                --tone: var(--border);
            }
            .entry-timeline-item[data-tone="success"] { --item-tone: #16a34a; --tone: #16a34a; }
            .entry-timeline-item[data-tone="warning"] { --item-tone: #d97706; --tone: #d97706; }
            .entry-timeline-item[data-tone="info"] { --item-tone: #2563eb; --tone: #2563eb; }
            .entry-timeline-item[data-tone="danger"] { --item-tone: #b91c1c; --tone: #b91c1c; }
            .entry-timeline-item[data-tone="neutral"] { --item-tone: var(--muted); --tone: var(--muted); }
            :host([data-theme="dark"]) .entry-timeline-item[data-tone="success"] { --item-tone: #4ade80; --tone: #4ade80; }
            :host([data-theme="dark"]) .entry-timeline-item[data-tone="warning"] { --item-tone: #fbbf24; --tone: #fbbf24; }
            :host([data-theme="dark"]) .entry-timeline-item[data-tone="info"] { --item-tone: #93c5fd; --tone: #93c5fd; }
            :host([data-theme="dark"]) .entry-timeline-item[data-tone="danger"] { --item-tone: #fca5a5; --tone: #fca5a5; }
            :host([data-theme="dark"]) .entry-timeline-item[data-tone="neutral"] { --item-tone: var(--muted); --tone: var(--muted); }
            .entry-timeline-marker {
                position: absolute;
                top: 12px;
                left: -24px;
                width: 14px;
                height: 14px;
                border-radius: 50%;
                background: var(--surface);
                border: 2.5px solid var(--item-tone);
                box-shadow: 0 0 0 2px var(--surface);
                z-index: 1;
            }
            .entry-timeline-box {
                padding: 10px 12px 10px 14px;
                border: 1px solid var(--border);
                border-radius: 10px;
                background: var(--surface);
            }
            .entry-timeline-head {
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                gap: 8px;
            }
            .entry-timeline-title {
                margin: 0;
                color: var(--muted);
                font: 600 11px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                letter-spacing: .04em;
                text-transform: uppercase;
            }
            .entry-timeline-callout {
                margin: 8px 0 0;
                padding: 6px 10px;
                border-radius: 7px;
                background: var(--row-stripe);
                border-left: 2px solid var(--item-tone);
                font-size: 12px;
                line-height: 1.4;
            }
            .entry-timeline-callout-label {
                color: var(--muted);
                font-weight: 600;
                margin-right: 4px;
            }
            .entry-timeline-callout-value {
                color: var(--foreground);
            }
            .entry-timeline-empty {
                margin: 6px 0 0;
                color: var(--muted);
                font-size: 12px;
                line-height: 1.4;
            }

            .entry-list {
                display: grid;
                grid-template-columns: minmax(110px, 36%) minmax(0, 1fr);
                margin: 0;
                font-size: 12.5px;
                line-height: 1.45;
            }
            .entry-list > dt, .entry-list > dd {
                min-width: 0;
                margin: 0;
                padding: 7px 0;
                border-bottom: 1px solid var(--border);
            }
            .entry-list > dt:nth-last-child(2), .entry-list > dd:last-child { border-bottom: 0; }
            .entry-list > dt { padding-right: 14px; color: var(--muted); font-weight: 500; overflow-wrap: anywhere; }
            .entry-list > dt code { display: block; margin-top: 2px; font-size: 10.5px; }
            .entry-list > dd { color: var(--foreground); overflow-wrap: anywhere; }
            .entry-list code {
                color: var(--foreground);
                font: 11.5px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            }
            .entry-list > dt code { color: var(--muted); }
            .entry-list a, .entry-facts a { color: inherit; text-decoration: underline; text-decoration-color: var(--border); text-underline-offset: 3px; }
            .entry-list a:hover, .entry-facts a:hover { text-decoration-color: currentColor; }
            .entry-empty, .entry-muted { color: var(--muted); }
            .entry-flag { color: var(--muted); }
            .entry-flag[data-on="true"] { color: var(--foreground); }
            .entry-flag::before {
                content: '';
                display: inline-block;
                width: 6px;
                height: 6px;
                margin: 0 7px 1px 0;
                border-radius: 50%;
                background: var(--border);
            }
            .entry-flag[data-on="true"]::before { background: #16a34a; }
            :host([data-theme="dark"]) .entry-flag[data-on="true"]::before { background: #4ade80; }
            .entry-spoiler summary {
                display: flex;
                align-items: baseline;
                gap: 8px;
                cursor: pointer;
                list-style: none;
            }
            .entry-spoiler summary::-webkit-details-marker { display: none; }
            .entry-spoiler summary::after {
                content: 'Развернуть';
                flex: 0 0 auto;
                color: var(--muted);
                font-size: 11px;
                text-decoration: underline dotted;
                text-underline-offset: 3px;
            }
            .entry-spoiler[open] summary::after { content: 'Свернуть'; }
            .entry-spoiler summary:hover::after { color: var(--foreground); }
            .entry-spoiler summary:focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; border-radius: 4px; }
            .entry-preview { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .entry-spoiler[open] .entry-preview { visibility: hidden; height: 0; }
            .entry-long {
                max-height: 260px;
                margin-top: 2px;
                padding: 8px 10px;
                overflow: auto;
                border-radius: 8px;
                background: var(--row-stripe);
                font: 11.5px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                white-space: pre-wrap;
            }
            @media (max-width: 480px) {
                .entry-panel { height: min(560px, calc(100dvh - 112px)); border-radius: 12px; }
                .entry-header { padding: 12px 10px 10px 12px; }
                .entry-tabs { padding: 0 6px; }
                .entry-body { padding: 2px 12px 8px; }
                .entry-list { grid-template-columns: minmax(0, 1fr); }
                .entry-facts { grid-template-columns: minmax(0, 1fr); gap: 0; }
                .entry-facts > dd { margin-bottom: 4px; }
                .entry-list > dt { padding-bottom: 0; border-bottom: 0; }
                .entry-list > dd { padding-top: 2px; }
                .entry-list > dt:nth-last-child(2) { border-bottom: 0; }
                .entry-hero-grid { grid-template-columns: minmax(0, 1fr); }
                .entry-hero-meta { flex-direction: column; align-items: flex-start; gap: 4px; }
                .entry-hero-sep { display: none; }
                .entry-timeline-strip { flex-wrap: wrap; }
                .entry-timeline-stat { min-width: calc(50% - 4px); }
            }
            @media (prefers-reduced-motion: reduce) {
                .entry-panel { animation: none; }
                .entry-action, .entry-tab { transition: none; }
            }
        </style>
HTML;
    }

    private static function script(): string
    {
        return <<<'JS'
    ((root) => {
        const button = root.querySelector('[data-entry-open]');
        const panel = root.querySelector('[data-entry-panel]');
        const dock = root.querySelector('.dock');
        const tabs = [...panel.querySelectorAll('[data-entry-tab]')];
        const storageKey = 'dletoolbar:entry-tab:' + panel.dataset.kind;
        const popup = createPopup({ trigger: button, panel, closeDuration: 150 });
        const select = (tab, focus = false) => {
            tabs.forEach((item) => {
                const selected = item === tab;
                item.setAttribute('aria-selected', selected ? 'true' : 'false');
                item.tabIndex = selected ? 0 : -1;
                root.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
            });
            sessionStorage.setItem(storageKey, tab.dataset.entryTab);
            if (focus) tab.focus();
        };
        const saved = tabs.find((tab) => tab.dataset.entryTab === sessionStorage.getItem(storageKey));
        if (saved) select(saved);
        tabs.forEach((tab) => tab.addEventListener('click', () => select(tab)));
        panel.querySelector('[role="tablist"]').addEventListener('keydown', (event) => {
            const index = tabs.indexOf(root.activeElement);
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            else return;
            event.preventDefault();
            select(tabs[next], true);
        });
        button.addEventListener('click', () => {
            if (popup.isOpen()) popup.close();
            else popup.open();
        });
        panel.querySelector('[data-entry-close]').addEventListener('click', () => {
            popup.close();
            button.focus();
        });
        document.addEventListener('click', (event) => {
            if (!popup.isOpen()) return;
            const path = event.composedPath();
            if (!path.includes(dock) && !path.includes(panel)) popup.close();
        }, true);
        panel.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            popup.close();
            button.focus();
        });
    })(root);
JS;
    }

    /** @param string $id info, close or edit */
    private static function icon(string $id): string
    {
        $paths = [
            'info' => '<path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0"/><path d="M12 9h.01"/><path d="M11 12h1v4h1"/>',
            'close' => '<path d="m18 6-12 12"/><path d="m6 6 12 12"/>',
            'edit' => '<path d="M16 4l4 4M4 20l4 -1L20 7a2.828 2.828 0 0 0 -4 -4L4 15l-1 5z"/>',
        ];

        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $paths[$id] . '</svg>';
    }
}
