<?php

namespace iCooLER\DLEToolbar\Components\Queries;

use iCooLER\DLEToolbar\ComponentFramework\ConfigurableComponent;
use iCooLER\DLEToolbar\ComponentFramework\RequestComponent;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;

/**
 * Owns the SQL control and panel inside the toolbar's shadow root.
 * The script runs with `root` in scope and emits dle-toolbar-popup-open
 * so the toolbar can dismiss its shared tooltip.
 */
final class Queries implements ConfigurableComponent, RequestComponent
{
    private float $slowThreshold;
    private bool $foldFast;
    private bool $traceSources;

    public static function parameters(): array
    {
        return [
            'slow_threshold_ms' => Parameter::number('Порог долгого запроса, мс', 20, 0.1, 0.1,
                'Запросы с длительностью не меньше порога подсвечиваются как долгие.'),
            'fold_fast' => Parameter::boolean('Сворачивать быстрые запросы', true,
                'Объединять соседние запросы быстрее 1 мс. Долгие запросы остаются видимыми.'),
            'trace_sources' => Parameter::boolean('Показывать источник и плагин запросов', true,
                'Для каждого запроса показывает файл и строку вызова и подсвечивает плагин, от которого он идёт; плагины можно отфильтровать. Учитываются запросы после запуска панели. Журнал $db->query_list на время страницы заменяется объектом — сторонний код, проверяющий его через is_array(), может работать иначе.'),
        ];
    }

    public function __construct(array $values)
    {
        $this->slowThreshold = (float) $values['slow_threshold_ms'] / 1000;
        $this->foldFast = (bool) $values['fold_fast'];
        $this->traceSources = (bool) $values['trace_sources'];
    }

    public function initialize(): void
    {
        global $config;
        /** @var array{version_id: string} $config */

        if ($this->traceSources && version_compare($config['version_id'], '14.0', '>=')) {
            QueryLog::install();
        }
    }

    public static function definition(): array
    {
        return ['label' => 'Количество запросов MySQL', 'description' => 'Показывает число запросов из счётчика DLE.'];
    }

    /** @return array{control: string, panel: string, script: string} */
    public function render(): array
    {
        return [
            'control' => $this->renderControl(),
            'panel' => $this->renderStyles() . $this->renderPanel(),
            'script' => $this->renderScript(),
        ];
    }

    private function renderControl(): string {
        global $db;
        /** @var \db $db */

        /** @var int $queryCount */
        $queryCount = $db->query_num;
        $value = (string) $queryCount;
        $label = 'Количество запросов MySQL: ' . $value . '. Показать список запросов';

        return '<button class="control metric queries" data-component="queries" data-queries-open type="button"'
            . ' aria-expanded="false" aria-controls="dle-toolbar-queries-panel" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . '<span class="query-open-icon">' . self::icon('queries') . '</span>'
            . '<span class="query-close-icon">' . self::icon('close') . '</span>'
            . '</button>';
    }

    private function renderPanel(): string {
        global $db, $config;
        /** @var \db $db */
        /** @var array{version_id?: string} $config */
        /** @var iterable<int, array{query: string, time: float, num: int, source?: string, plugin?: string}> $queries */
        $queries = $db->query_list;
        /** @var int $queryCount */
        $queryCount = $db->query_num;

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $format = static function (float $seconds): string {
            $milliseconds = $seconds * 1000;

            return $milliseconds < 1 ? '&lt;1 мс' : number_format(round($milliseconds), 0, '.', '') . ' мс';
        };

        $groups = [];
        /** @var array<string, array{count: int, time: float}> $owners */
        $owners = [];
        $number = 0;
        $queries = $queries instanceof \Traversable ? iterator_to_array($queries, false) : (array) $queries;

        foreach ($queries as $query) {
            if ($query['query'] === 'Connection with MySQL Server') {
                continue;
            }

            $number++;
            $sql = self::redact((string) $query['query']);
            $source = (string) ($query['source'] ?? '');
            $owner = isset($query['plugin']) ? (string) $query['plugin'] : self::ownerFromSource($source);
            $owners[$owner]['count'] = ($owners[$owner]['count'] ?? 0) + 1;
            $owners[$owner]['time'] = ($owners[$owner]['time'] ?? 0) + (float) $query['time'];
            $item = ['query' => $sql, 'time' => $query['time'], 'number' => $number, 'source' => $source, 'owner' => $owner];
            $lastIndex = array_key_last($groups);
            $isFast = $this->foldFast && $query['time'] < 0.001 && $query['time'] < $this->slowThreshold;
            if ($isFast && $lastIndex !== null && $groups[$lastIndex]['fast']) {
                $groups[$lastIndex]['items'][] = $item;
                continue;
            }

            $groups[] = ['fast' => $isFast, 'items' => [$item]];
        }

        $colors = self::ownerColors(array_keys($owners));
        $ownerAttr = static function (string $owner) use ($colors, $escape): string {
            return ' data-owner="' . $escape($colors[$owner]['id']) . '"'
                . ($colors[$owner]['color'] !== '' ? ' style="--owner: ' . $colors[$owner]['color'] . '"' : '');
        };

        $rows = '';
        $slowCount = 0;
        $totalTime = 0.0;

        foreach ($groups as $group) {
            $items = $group['items'];

            if ($group['fast'] && count($items) > 1) {
                $sum = array_sum(array_column($items, 'time'));
                $totalTime += $sum;
                $rows .= '<div class="query-group" data-query-group><div class="query-row query-fold" data-fold role="button" tabindex="0" aria-expanded="false">'
                    . '<span class="query-bar"></span>'
                    . '<span class="query-num">···</span>'
                    . '<span class="query-sql">№' . $items[0]['number'] . '–' . $items[count($items) - 1]['number'] . ' · <span data-fold-verb></span>' . count($items) . ' быстрых</span>'
                    . '<span class="query-time"></span>'
                    . '</div>';

                foreach ($items as $item) {
                    $rows .= '<div class="query-row query-row-plain query-row-fast" data-fast-row' . $ownerAttr($item['owner']) . ' hidden>'
                        . '<span class="query-bar"></span>'
                        . '<span class="query-num">#' . $item['number'] . '</span>'
                        . '<span class="query-sql"><span class="query-text">' . $escape($item['query']) . '</span>'
                        . $this->renderSource($item['source'], $item['owner']) . '</span>'
                        . '<span class="query-time"></span>'
                        . $this->renderQueryCopyButton()
                        . '</div>';
                }

                $rows .= '</div>';
                continue;
            }

            foreach ($items as $item) {
                $isSlow = $item['time'] >= $this->slowThreshold;
                $slowCount += $isSlow ? 1 : 0;
                $totalTime += $item['time'];
                $rows .= '<div class="query-row' . ($isSlow ? ' query-row-slow' : ' query-row-plain') . '"' . $ownerAttr($item['owner']) . '>'
                    . '<span class="query-bar"></span>'
                    . '<span class="query-num">#' . $item['number'] . '</span>'
                    . '<span class="query-sql"><span class="query-text">' . $escape($item['query']) . '</span>'
                    . $this->renderSource($item['source'], $item['owner']) . '</span>'
                    . '<span class="query-time">' . ($this->foldFast && !$isSlow && $item['time'] < 0.001 ? '' : $format($item['time'])) . '</span>'
                    . $this->renderQueryCopyButton()
                    . '</div>';
            }
        }

        if (version_compare($config['version_id'] ?? '14.0', '14.0', '<')) {
            // DLE before 14.0 counts queries but does not keep their list.
            $stats = '';
            $rows = '<p class="query-empty">Список SQL-запросов не показывается в DLE 13.0–13.3: эти версии его не сохраняют.'
                . ' Всего запросов: ' . $queryCount . '.</p>';
        } elseif ($rows === '') {
            $stats = '';
            $rows = '<p class="query-empty">SQL-запросов на этой странице нет.</p>';
        } else {
            $remainder = $number % 100;
            $words = [1 => 'запрос', 2 => 'запроса', 3 => 'запроса', 4 => 'запроса'];
            $word = $remainder >= 11 && $remainder <= 14 ? 'запросов' : ($words[$number % 10] ?? 'запросов');
            $stats = '<span class="query-stats"><b>' . $number . ' ' . $word . '</b> · ' . $format($totalTime)
                . ($slowCount > 0 ? ' · <span class="query-stats-alert">⚠ ' . $slowCount . '</span>' : '') . '</span>';
        }

        $ownersBar = '';
        if ($owners !== []) {
            uasort($owners, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
            $chips = '';
            foreach ($owners as $owner => $stat) {
                $owner = (string) $owner;
                $chips .= '<button class="query-owner-chip" type="button" data-owner-filter="' . $escape($colors[$owner]['id']) . '"'
                    . ($colors[$owner]['color'] !== '' ? ' style="--owner: ' . $colors[$owner]['color'] . '"' : '')
                    . ' aria-pressed="false" title="Показать только запросы: ' . $escape($owner) . '">'
                    . '<span class="query-owner-dot" aria-hidden="true"></span>'
                    . $escape($owner) . ' <b>' . $stat['count'] . '</b><span class="query-owner-time">' . $format($stat['time']) . '</span></button>';
            }
            $ownersBar = '<div class="query-owners" role="group" aria-label="Источники запросов">'
                . $chips
                . (!$this->traceSources ? '<span class="query-owners-hint">Плагины не определяются: включите «Показывать источник и плагин запросов» в настройках панели.</span>' : '')
                . '</div>';
        }

        return '<section class="query-panel" data-query-panel id="dle-toolbar-queries-panel"'
            . ' aria-labelledby="dle-toolbar-queries-title" hidden>'
            . '<header class="query-header"><h2 id="dle-toolbar-queries-title">Запросы страницы</h2>'
            . $stats
            . '<button class="query-close" data-query-close type="button" aria-label="Закрыть список запросов">'
            . self::icon('close') . '</button></header>'
            . $ownersBar
            . '<div class="query-list">' . $rows . '</div>'
            . '<p class="query-copy-status" data-copy-status role="status" aria-live="polite"></p></section>';
    }

    private static function redact(string $sql): string
    {
        $secret = '(?:pass|password|hash|token|secret|salt|auth|twofactor|apikey|api_key|private)';
        $quoted = "'(?:[^'\\\\]|\\\\.)*'";
        $sql = (string) preg_replace('/\\b(`?\\w*' . $secret . '\\w*`?)(\\s*(?:=|<>|!=|LIKE)\\s*)' . $quoted . '/i', "$1$2'***'", $sql);
        return $sql;
    }

    private static function ownerFromSource(string $source): string
    {
        if ($source === '') return 'DLE';
        $source = (string) preg_replace('~ \\(.*\\)$~', '', $source);
        $parts = explode('/', substr($source, 0, (int) strrpos($source, ':')));
        if ($parts[0] !== 'engine' || count($parts) < 3) return 'DLE';
        $nativeDirectories = ['ajax', 'api', 'cache', 'classes', 'data', 'editor', 'inc', 'modules', 'skins'];
        $nativeModules = ['addcomments', 'addnews', 'antibot', 'banned', 'banners', 'calendar', 'changemail',
            'comments', 'cron', 'deletenews', 'favorites', 'feedback', 'findcomments', 'functions',
            'lastcomments', 'links', 'lostpassword', 'main', 'newsletter', 'offline', 'pm', 'poll', 'profile',
            'profile_innews', 'register', 'rssinform', 'search', 'show.custom', 'show.full', 'show.related',
            'show.short', 'sitelogin', 'social', 'static', 'stats', 'tagscloud', 'topnews', 'vote'];
        if ($parts[1] === 'modules') {
            if (count($parts) >= 4) return $parts[2] === 'dletoolbar' ? 'DLEToolbar' : $parts[2];
            $module = basename($parts[2], '.php');
            return in_array($module, $nativeModules, true) ? 'DLE' : $module;
        }
        if (!in_array($parts[1], $nativeDirectories, true)) return count($parts) >= 4 ? $parts[2] : $parts[1];
        return 'DLE';
    }

    /**
     * @param list<string> $owners
     * @return array<string, array{id: string, color: string}>
     */
    private static function ownerColors(array $owners): array
    {
        $palette = ['#5b7bb8', '#3f9c8f', '#c27c3a', '#9b6bb5', '#c25b7a', '#6a9a44', '#b88a2e', '#4f8fbf', '#8a7f6b', '#7b6fd0'];
        $colors = [];
        $index = 0;
        foreach ($owners as $owner) {
            $owner = (string) $owner;
            $colors[$owner] = [
                'id' => 'o' . count($colors),
                'color' => $owner === 'DLE' ? '' : $palette[$index++ % count($palette)],
            ];
        }
        return $colors;
    }

    private function renderSource(string $source, string $owner): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<span class="query-source"><span class="query-owner">' . $escape($owner) . '</span>'
            . ($source !== '' ? '<span class="query-source-file">' . $escape($source) . '</span>' : '') . '</span>';
    }

    private function renderQueryCopyButton(): string
    {
        return '<button class="query-copy" data-copy-query type="button" title="Скопировать SQL-запрос" aria-label="Скопировать SQL-запрос">'
            . self::icon('copy')
            . '</button>';
    }

    private function renderStyles(): string
    {
        return <<<'HTML'
        <style>
            .queries[aria-expanded="true"] {
                background: var(--hover);
                color: var(--foreground);
                box-shadow: inset 0 0 0 1px var(--border);
            }
            .queries .query-close-icon { display: none; }
            .queries[aria-expanded="true"] .query-open-icon { display: none; }
            .queries[aria-expanded="true"] .query-close-icon { display: block; }
            .queries { min-width: 40px; padding: 0; appearance: none; -webkit-appearance: none; cursor: pointer; }
            .query-panel {
                --tb-border: var(--border, #e4e4e7);
                --tb-surface: var(--surface, #fff);
                --tb-foreground: var(--foreground, #27272a);
                --tb-muted: var(--muted, #52525b);
                --tb-hover: var(--hover, #f4f4f5);
                --tb-row-stripe: var(--row-stripe, #fafafa);
                --tb-shadow: var(--shadow, 0 8px 30px rgb(24 24 27 / 10%), 0 2px 6px rgb(24 24 27 / 5%));
                --tb-accent: var(--foreground, #27272a);
                display: flex;
                flex-direction: column;
                width: min(760px, 100%);
                height: min(560px, calc(100dvh - 96px));
                overflow: hidden;
                border: 1px solid var(--tb-border);
                border-radius: 20px;
                background: var(--tb-surface);
                color: var(--tb-foreground);
                box-shadow: var(--tb-shadow);
                pointer-events: auto;
                transform-origin: left bottom;
                animation: query-panel-in 180ms cubic-bezier(.2, .8, .2, 1);
            }
            :host([data-theme="dark"]) .query-panel {
                --tb-border: var(--border, #3f3f46);
                --tb-surface: var(--surface, #18181b);
                --tb-foreground: var(--foreground, #fafafa);
                --tb-muted: var(--muted, #a1a1aa);
                --tb-hover: var(--hover, #27272a);
                --tb-row-stripe: var(--row-stripe, #1c1c20);
                --tb-shadow: var(--shadow, 0 8px 30px rgb(0 0 0 / 25%), 0 2px 6px rgb(0 0 0 / 15%));
                --tb-accent: var(--foreground, #fafafa);
            }
            .query-panel[hidden] { display: none; }
            .query-panel[data-closing="true"] {
                pointer-events: none;
                animation: query-panel-out 150ms ease-in forwards;
            }
            @keyframes query-panel-in {
                from { opacity: 0; transform: translateY(8px) scale(.99); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }
            @keyframes query-panel-out {
                from { opacity: 1; transform: translateY(0) scale(1); }
                to { opacity: 0; transform: translateY(6px) scale(.99); }
            }
            .query-header {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 13px 18px;
                border-bottom: 1px solid var(--tb-border);
            }
            .query-header h2 {
                margin: 0 auto 0 0;
                color: var(--tb-foreground);
                font-family: inherit; font-size: 16px; font-weight: 700; line-height: 1.25;
                letter-spacing: -.025em;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .query-stats {
                flex: 0 0 auto;
                color: var(--tb-muted);
                font: 600 11px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }
            .query-stats b { color: var(--tb-foreground); }
            .query-stats-alert { color: #b91c1c; }
            :host([data-theme="dark"]) .query-stats-alert { color: #fca5a5; }
            .query-close {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex: 0 0 auto;
                width: 30px;
                height: 30px;
                padding: 0;
                border: 1px solid var(--tb-border);
                border-radius: 10px;
                background: var(--tb-surface);
                color: var(--tb-muted);
                cursor: pointer;
                transition: background-color 140ms ease, color 140ms ease;
            }
            .query-close:hover { background: var(--tb-hover); color: var(--tb-foreground); }
            .query-close:focus-visible { outline: 2px solid var(--tb-accent); outline-offset: 2px; }
            .query-close svg { width: 16px; height: 16px; }
            .query-group[hidden] { display: none; }
            .query-list {
                flex: 1 1 auto;
                min-height: 0;
                padding: 2px 0;
                overflow: auto;
                overscroll-behavior: contain;
            }
            .query-row {
                --query-gap: 10px;
                display: grid;
                grid-template-columns: 3px max-content minmax(0, 1fr) 60px 28px;
                align-items: center;
                gap: 0;
                padding: 4px 14px 4px 0;
                border-bottom: 1px solid var(--tb-border);
                color: var(--tb-foreground);
                font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            }
            .query-list > .query-row:last-child,
            .query-group:last-child > .query-row:last-child { border-bottom: 0; }
            .query-row[hidden] { display: none; }
            .query-row:hover { background: var(--tb-hover); }
            .query-bar {
                width: 3px;
                min-height: 18px;
                align-self: stretch;
                border-radius: 0 2px 2px 0;
                background: var(--tb-border);
            }
            .query-num {
                padding-left: 14px;
                padding-right: var(--query-gap);
                color: var(--tb-muted);
                font: 600 10px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-variant-numeric: tabular-nums;
                text-align: right;
            }
            .query-sql {
                min-width: 0;
                padding-right: var(--query-gap);
                white-space: pre-wrap;
                overflow-wrap: anywhere;
            }
            .query-source {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px;
                margin-top: 4px;
                color: var(--tb-muted);
                font-size: 10px;
                line-height: 1.5;
                white-space: normal;
            }
            .query-owner {
                display: inline-flex;
                align-items: center;
                padding: 1px 7px;
                border-radius: 999px;
                background: var(--tb-hover);
                color: var(--tb-muted);
                font: 600 10px/1.5 var(--font, system-ui, sans-serif);
            }
            .query-row[style] .query-owner {
                background: color-mix(in srgb, var(--owner) 16%, transparent);
                color: var(--owner);
            }
            :host([data-theme="dark"]) .query-row[style] .query-owner {
                background: color-mix(in srgb, var(--owner) 24%, transparent);
                color: color-mix(in srgb, var(--owner) 70%, #fff);
            }
            .query-row-plain[style] .query-bar { background: var(--owner); opacity: .85; }
            .query-owners {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px;
                padding: 10px 14px;
                border-bottom: 1px solid var(--tb-border);
            }
            .query-owner-chip {
                --owner: var(--tb-muted);
                display: inline-flex;
                align-items: center;
                gap: 6px;
                height: 28px;
                padding: 0 10px;
                border: 1px solid var(--tb-border);
                border-radius: 999px;
                background: var(--tb-surface);
                color: var(--tb-foreground);
                font: 500 12px/1 var(--font, system-ui, sans-serif);
                cursor: pointer;
                transition: background-color 140ms ease, border-color 140ms ease;
            }
            .query-owner-chip:hover { background: var(--tb-hover); }
            .query-owner-chip:focus-visible { outline: 2px solid var(--tb-accent); outline-offset: 2px; }
            .query-owner-chip b { font-weight: 700; font-variant-numeric: tabular-nums; }
            .query-owner-time { color: var(--tb-muted); font-size: 11px; font-variant-numeric: tabular-nums; }
            .query-owner-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--owner); }
            .query-owner-chip[aria-pressed="true"] { border-color: var(--owner); background: color-mix(in srgb, var(--owner) 14%, transparent); }
            .query-owners-hint { flex-basis: 100%; color: var(--tb-muted); font-size: 11px; }
            .query-list[data-filtered] .query-fold { display: none; }
            .query-time {
                padding-right: var(--query-gap);
                color: var(--tb-muted);
                font: 600 11px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-variant-numeric: tabular-nums;
                text-align: right;
            }
            .query-copy {
                display: grid;
                place-items: center;
                width: 28px;
                height: 28px;
                padding: 0;
                border: 0;
                border-radius: 6px;
                background: transparent;
                color: var(--tb-muted);
            }
            .query-copy:hover { background: var(--tb-hover); color: var(--tb-foreground); }
            .query-copy:focus-visible { outline: 2px solid var(--tb-accent); outline-offset: 2px; }
            .query-copy svg { width: 16px; height: 16px; }
            .query-copy-status { margin: 0; color: var(--tb-muted); font-size: 12px; line-height: 1.45; }
            .query-copy-status:not(:empty) { padding: 8px 14px; border-top: 1px solid var(--tb-border); }
            .query-row-plain .query-bar { background: transparent; }
            .query-row-plain .query-num { opacity: .55; }
            .query-row-plain .query-time { opacity: .65; }
            .query-row-plain:hover .query-num,
            .query-row-plain:hover .query-time { opacity: 1; }
            .query-row-slow { background: rgb(185 28 28 / 4%); }
            .query-row-slow:hover { background: rgb(185 28 28 / 8%); }
            .query-row-slow .query-bar { margin-block: -4px; background: #b91c1c; }
            .query-row-slow .query-num,
            .query-row-slow .query-time { color: #b91c1c; }
            :host([data-theme="dark"]) .query-row-slow { background: rgb(248 113 113 / 6%); }
            :host([data-theme="dark"]) .query-row-slow:hover { background: rgb(248 113 113 / 10%); }
            :host([data-theme="dark"]) .query-row-slow .query-bar { background: #fca5a5; }
            :host([data-theme="dark"]) .query-row-slow .query-num,
            :host([data-theme="dark"]) .query-row-slow .query-time { color: #fca5a5; }
            .query-row-fast { opacity: .7; }
            .query-row-fast:hover { opacity: 1; }
            .query-fold {
                background: var(--tb-row-stripe);
                cursor: pointer;
                font-style: italic;
                opacity: .55;
                transition: opacity 140ms ease, background-color 140ms ease;
            }
            .query-fold:hover { background: var(--tb-hover); opacity: .85; }
            .query-fold:focus-visible { outline: 2px solid var(--tb-accent); outline-offset: -2px; }
            .query-fold .query-bar { background: transparent; }
            .query-fold .query-sql,
            .query-fold .query-num,
            .query-fold .query-time { color: var(--tb-muted); }
            .query-fold[data-open="true"] { opacity: 1; background: var(--tb-hover); }
            .query-fold[data-open="true"] .query-sql {
                color: var(--tb-foreground);
                font-style: normal;
            }
            .query-fold[data-open="true"] .query-num,
            .query-fold[data-open="true"] .query-time { color: var(--tb-foreground); }
            .query-empty {
                display: grid;
                min-height: 100%;
                place-items: center;
                margin: 0;
                padding: 26px 16px;
                color: var(--tb-muted);
                text-align: center;
            }
            @media (max-width: 480px) {
                .query-panel { height: min(560px, calc(100dvh - 112px)); border-radius: 12px; }
                .query-header { gap: 8px; padding: 11px 12px; }
                .query-stats { display: none; }
                .query-row {
                    --query-gap: 8px;
                    grid-template-columns: 3px max-content minmax(0, 1fr) 52px 28px;
                    padding-right: 10px;
                    font-size: 11px;
                }
                .query-sql { font-size: 11px; }
                .query-num { padding-left: 10px; }
            }
            @media (prefers-reduced-motion: reduce) {
                .query-panel { animation: none; }
                .query-close, .query-fold { transition: none; }
            }
        </style>
HTML;
    }

    private function renderScript(): string
    {
        return <<<'JS'
    ((root) => {
        const queriesButton = root.querySelector('[data-queries-open]');
        const queryPanel = root.querySelector('[data-query-panel]');
        const dock = root.querySelector('.dock');
        const copyStatus = queryPanel.querySelector('[data-copy-status]');
        queryPanel.querySelectorAll('[data-copy-query]').forEach((button) => {
            button.addEventListener('click', () => {
                copyStatus.textContent = '';
                if (!navigator.clipboard) {
                    copyStatus.textContent = 'Для копирования SQL-запроса откройте сайт по HTTPS.';
                    return;
                }
                const sql = button.closest('.query-row').querySelector('.query-text').textContent;
                navigator.clipboard.writeText(sql).then(
                    () => { copyStatus.textContent = 'SQL-запрос скопирован.'; },
                    () => { copyStatus.textContent = 'Не удалось скопировать SQL-запрос. Проверьте разрешение браузера на доступ к буферу обмена.'; }
                );
            });
        });
        const closeButton = queryPanel.querySelector('[data-query-close]');
        const closedQueriesLabel = queriesButton.getAttribute('aria-label');
        const folds = [...queryPanel.querySelectorAll('[data-fold]')];
        const queryPopup = createPopup({
            trigger: queriesButton,
            panel: queryPanel,
            closeDuration: 150,
            onOpen: () => queriesButton.setAttribute('aria-label',
                closedQueriesLabel.replace('Показать список запросов', 'Закрыть список запросов')),
            onClose: () => queriesButton.setAttribute('aria-label', closedQueriesLabel),
        });
        const setFold = (fold, expanded) => {
            fold.closest('[data-query-group]').querySelectorAll('[data-fast-row]').forEach((row) => {
                if (!queryPanel.querySelector('.query-list').hasAttribute('data-filtered')) row.hidden = !expanded;
            });
            fold.dataset.open = expanded ? 'true' : 'false';
            fold.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            fold.querySelector('[data-fold-verb]').textContent = expanded ? 'Скрыть ' : '';
        };
        const toggleFold = (fold) => {
            setFold(fold, fold.dataset.open !== 'true');
        };
        folds.forEach((fold) => {
            fold.addEventListener('click', () => toggleFold(fold));
            fold.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                toggleFold(fold);
            });
        });
        const list = queryPanel.querySelector('.query-list');
        const chips = [...queryPanel.querySelectorAll('[data-owner-filter]')];
        let activeOwner = null;
        const applyOwnerFilter = () => {
            list.toggleAttribute('data-filtered', activeOwner !== null);
            list.querySelectorAll('.query-row[data-owner]').forEach((row) => {
                if (activeOwner !== null) {
                    row.hidden = row.dataset.owner !== activeOwner;
                } else if (row.hasAttribute('data-fast-row')) {
                    row.hidden = row.closest('[data-query-group]').querySelector('[data-fold]').dataset.open !== 'true';
                } else {
                    row.hidden = false;
                }
            });
            chips.forEach((chip) => chip.setAttribute('aria-pressed', chip.dataset.ownerFilter === activeOwner ? 'true' : 'false'));
        };
        chips.forEach((chip) => chip.addEventListener('click', () => {
            activeOwner = activeOwner === chip.dataset.ownerFilter ? null : chip.dataset.ownerFilter;
            applyOwnerFilter();
        }));
        queriesButton.addEventListener('click', () => {
            if (queryPopup.isOpen()) queryPopup.close();
            else queryPopup.open();
        });
        closeButton.addEventListener('click', () => {
            queryPopup.close();
            queriesButton.focus();
        });
        document.addEventListener('click', (event) => {
            if (!queryPopup.isOpen()) return;
            const path = event.composedPath();
            if (!path.includes(dock) && !path.includes(queryPanel)) {
                queryPopup.close();
            }
        }, true);
        queryPanel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                queryPopup.close();
                queriesButton.focus();
            }
        });
    })(root);
JS;
    }

    private static function icon(string $id): string {
        $paths = [
            'queries' => '<path d="M4 6a8 3 0 1 0 16 0a8 3 0 1 0 -16 0M4 6v6a8 3 0 0 0 16 0v-6M4 12v6a8 3 0 0 0 16 0v-6"/>',
            'close' => '<path d="m18 6-12 12"/><path d="m6 6 12 12"/>',
            'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0 -2 -2H6a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2"/>',
        ];

        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $paths[$id] . '</svg>';
    }
}
