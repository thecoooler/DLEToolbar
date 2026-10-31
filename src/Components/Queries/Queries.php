<?php

namespace iCooLER\DLEToolbar\Components\Queries;

use iCooLER\DLEToolbar\ComponentFramework\ConfigurableComponent;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;
use iCooLER\DLEToolbar\ComponentFramework\RequestComponent;

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
            'slow_threshold_ms' => Parameter::number(
                'Порог долгого запроса, мс',
                20,
                0.1,
                0.1,
                'Запросы с длительностью не меньше порога подсвечиваются как долгие.',
            ),
            'fold_fast' => Parameter::boolean(
                'Сворачивать быстрые запросы',
                true,
                'Объединять соседние запросы быстрее 1 мс. Долгие запросы остаются видимыми.',
            ),
            'trace_sources' => Parameter::boolean(
                'Показывать источник и плагин запросов',
                true,
                'Экспериментальная функция для DLE 14.0 и новее: показывает файл, строку вызова и определяет модуль или плагин для запросов после инициализации панели. Заменяет журнал запросов $db->query_list на время обработки страницы; сторонний код, работающий с ним как с массивом, может перестать работать.',
            ),
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

    private function renderControl(): string
    {
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

    private function renderPanel(): string
    {
        global $db, $config;
        /** @var \db $db */
        /** @var array{version_id: string} $config */
        /** @var iterable<int, array{query: string, time: float, num: int, source?: string, plugin?: string}> $queries QueryLog when source tracing is enabled. */
        $queries = $db->query_list;
        /** @var int $queryCount */
        $queryCount = $db->query_num;

        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $format = static function (float $seconds): string {
            $milliseconds = $seconds * 1000;

            return $milliseconds < 1 ? '&lt;1 мс' : number_format(round($milliseconds), 0, '.', '') . ' мс';
        };

        $groups = [];
        $number = 0;

        foreach ($queries as $query) {
            if ($query['query'] === 'Connection with MySQL Server') {
                continue;
            }

            $number++;
            $sql = self::redact($query['query']);
            $source = $query['source'] ?? '';
            $item = ['query' => $sql, 'time' => $query['time'], 'number' => $number, 'source' => $source,
                'initiator' => $query['plugin'] ?? ($source === '' ? '' : self::initiator($source))];
            $lastIndex = array_key_last($groups);
            $isFast = $this->foldFast && $query['time'] < 0.001 && $query['time'] < $this->slowThreshold;
            // A folded group keeps one initiator so the initiator filter can show or hide it whole.
            if ($isFast && $lastIndex !== null && $groups[$lastIndex]['fast']
                && $groups[$lastIndex]['items'][0]['initiator'] === $item['initiator']) {
                $groups[$lastIndex]['items'][] = $item;
                continue;
            }

            $groups[] = ['fast' => $isFast, 'items' => [$item]];
        }

        /** @var array<array-key, array{count: int, time: float}> $initiators */
        $initiators = [];
        foreach ($groups as $group) {
            $initiator = $group['items'][0]['initiator'];
            $initiators[$initiator] ??= ['count' => 0, 'time' => 0.0];
            $initiators[$initiator]['count'] += count($group['items']);
            $initiators[$initiator]['time'] += array_sum(array_column($group['items'], 'time'));
        }
        // DLE goes first, plugins and modules follow by total time, queries without a call site go last.
        uksort($initiators, static function ($a, $b) use ($initiators): int {
            $rank = static fn(string $name): int => $name === 'DLE' ? 0 : ($name === '' ? 2 : 1);
            return [$rank((string) $a), $initiators[$b]['time']] <=> [$rank((string) $b), $initiators[$a]['time']];
        });
        $hues = self::initiatorHues($initiators);
        $tint = static fn(string $initiator): string => isset($hues[$initiator]) ? ' query-tinted' : '';
        $hueStyle = static fn(string $initiator): string
            => isset($hues[$initiator]) ? ' style="--query-hue: ' . $hues[$initiator] . '"' : '';

        $rows = '';
        $slowCount = 0;
        $totalTime = 0.0;

        foreach ($groups as $group) {
            $items = $group['items'];
            $initiator = $items[0]['initiator'];
            $initiatorAttribute = ' data-initiator="' . $escape($initiator) . '"' . $hueStyle($initiator);

            if ($group['fast'] && count($items) > 1) {
                $sum = array_sum(array_column($items, 'time'));
                $totalTime += $sum;
                $rows .= '<div class="query-group' . $tint($initiator) . '" data-query-group' . $initiatorAttribute . '>'
                    . '<div class="query-row query-fold" data-fold role="button" tabindex="0" aria-expanded="false">'
                    . '<span class="query-bar"></span>'
                    . '<span class="query-num" data-fold-marker aria-hidden="true">···</span>'
                    . '<span class="query-sql">№' . $items[0]['number'] . '–' . $items[count($items) - 1]['number']
                    . ' · ' . count($items) . ' быстрых'
                    . ($initiator === '' ? '' : ' · ' . $escape($initiator)) . '</span>'
                    . '<span class="query-time"></span>'
                    . '</div>';

                foreach ($items as $item) {
                    $rows .= '<div class="query-row query-row-plain query-row-fast" data-fast-row hidden>'
                        . '<span class="query-bar"></span>'
                        . '<span class="query-num">#' . $item['number'] . '</span>'
                        . '<span class="query-sql"><span class="query-text">' . $escape($item['query']) . '</span>'
                        . $this->renderSource($item['source'], $item['initiator']) . '</span>'
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
                $rows .= '<div class="query-row' . ($isSlow ? ' query-row-slow' : ' query-row-plain') . $tint($initiator) . '"' . $initiatorAttribute . '>'
                    . '<span class="query-bar"></span>'
                    . '<span class="query-num">#' . $item['number'] . '</span>'
                    . '<span class="query-sql"><span class="query-text">' . $escape($item['query']) . '</span>'
                    . $this->renderSource($item['source'], $item['initiator']) . '</span>'
                    . '<span class="query-time">' . ($this->foldFast && !$isSlow && $item['time'] < 0.001 ? '' : $format($item['time'])) . '</span>'
                    . $this->renderQueryCopyButton()
                    . '</div>';
            }
        }

        $filters = '';
        if (version_compare($config['version_id'], '14.0', '<')) {
            // DLE before 14.0 counts queries but does not keep their list.
            $stats = '';
            $rows = '<p class="query-empty">Список SQL-запросов не показывается в DLE 13.0–13.3: эти версии его не сохраняют.'
                . ' Всего запросов: ' . $queryCount . '.</p>';
        } elseif ($rows === '') {
            $stats = '';
            $rows = '<p class="query-empty">SQL-запросов на этой странице нет.</p>';
        } else {
            $stats = '<span class="query-stats"><b>' . $number . ' ' . self::queriesWord($number) . '</b> · ' . $format($totalTime)
                . ($slowCount > 0 ? ' · <span class="query-stats-alert">⚠ ' . $slowCount . '</span>' : '')
                . '</span>';

            // Initiators are known only when source tracing recorded call sites.
            if (array_keys($initiators) !== ['']) {
                $filter = static fn(string $attribute, string $name, int $count, float $time, bool $pressed, string $initiator = ''): string
                    => '<button class="query-filter' . $tint($initiator) . '" type="button" ' . $attribute . $hueStyle($initiator)
                    . ' aria-pressed="' . ($pressed ? 'true' : 'false') . '"'
                    . ' title="' . $count . ' ' . self::queriesWord($count) . ', ' . $format($time) . '">'
                    . '<span class="query-filter-name">' . $escape($name) . '</span>'
                    . '<span class="query-filter-stats">' . $count . ' · ' . $format($time) . '</span></button>';
                $filters = '<div class="query-filters" role="group" aria-label="Инициатор запросов">'
                    . $filter('data-query-filter-all', 'Все', $number, $totalTime, true);
                foreach ($initiators as $name => $total) {
                    $name = (string) $name;
                    $filters .= $filter(
                        'data-query-filter="' . $escape($name) . '"',
                        $name === '' ? 'Без источника' : $name,
                        $total['count'],
                        $total['time'],
                        false,
                        $name,
                    );
                }
                $filters .= '</div>';
            }
        }

        return '<section class="query-panel" data-query-panel id="dle-toolbar-queries-panel"'
            . ' aria-labelledby="dle-toolbar-queries-title" hidden>'
            . '<header class="query-header"><h2 id="dle-toolbar-queries-title">Запросы страницы</h2>'
            . $stats
            . '<button class="query-close" data-query-close type="button" aria-label="Закрыть список запросов">'
            . self::icon('close') . '</button></header>'
            . $filters
            . '<div class="query-scroll-area"><div class="query-list" role="region"' . ($number > 0 ? ' tabindex="0"' : '')
            . ' aria-label="Список SQL-запросов" style="--query-number-chars: ' . max(3, strlen((string) $number) + 1) . '">' . $rows . '</div>'
            . '<div class="query-scrollbar" data-query-scrollbar aria-hidden="true" hidden><div class="query-scroll-thumb"></div></div></div>'
            . '<p class="query-copy-status" data-copy-status role="status" aria-live="polite"></p></section>';
    }

    private function renderSource(string $source, string $initiator): string
    {
        if ($source === '') {
            return '';
        }

        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<span class="query-source"><span class="query-source-label">' . $escape($initiator) . '</span>'
            . ' · ' . $escape($source) . '</span>';
    }

    private static function redact(string $sql): string
    {
        $secret = '(?:pass|password|hash|token|secret|salt|auth|twofactor|apikey|api_key|private)';
        $quoted = "'(?:[^'\\\\]|\\\\.)*'";
        $sql = (string) preg_replace('/\\b(`?\\w*' . $secret . '\\w*`?)(\\s*(?:=|<>|!=|LIKE)\\s*)' . $quoted . '/i', "$1$2'***'", $sql);
        return $sql;
    }

    /** Names the module or plugin that issued the query, or DLE for the engine itself. */
    private static function initiator(string $source): string
    {
        // Drop the line number, then name the module or plugin by its directory or file.
        $parts = explode('/', substr($source, 0, (int) strrpos($source, ':')));
        $label = 'DLE';
        if ($parts[0] === 'engine' && count($parts) >= 3) {
            // Top-level engine directories and engine/modules files shipped with DLE 21.0.
            $nativeDirectories = ['ajax', 'api', 'cache', 'classes', 'data', 'editor', 'inc', 'modules', 'skins'];
            $nativeModules = ['addcomments', 'addnews', 'antibot', 'banned', 'banners', 'calendar', 'changemail',
                'comments', 'cron', 'deletenews', 'favorites', 'feedback', 'findcomments', 'functions',
                'lastcomments', 'links', 'lostpassword', 'main', 'newsletter', 'offline', 'pm', 'poll', 'profile',
                'profile_innews', 'register', 'rssinform', 'search', 'show.custom', 'show.full', 'show.related',
                'show.short', 'sitelogin', 'social', 'static', 'stats', 'tagscloud', 'topnews', 'vote'];
            if ($parts[1] === 'modules') {
                if (count($parts) >= 4) {
                    $label = $parts[2];
                } elseif (!in_array(basename($parts[2], '.php'), $nativeModules, true)) {
                    $label = basename($parts[2], '.php');
                }
            } elseif (!in_array($parts[1], $nativeDirectories, true)) {
                $label = count($parts) >= 4 ? $parts[2] : $parts[1];
            }
        }
        return $label;
    }

    /**
     * Assigns every traced initiator its own hue in filter order: first the palette,
     * then the hue farthest from those taken. Hues stay within 75–345° like the palette,
     * so none falls into the red band of slow queries.
     *
     * @param array<array-key, mixed> $initiators
     * @return array<array-key, int>
     */
    private static function initiatorHues(array $initiators): array
    {
        $palette = [255, 145, 310, 75, 195, 345];
        /** @var array<array-key, int> $hues */
        $hues = [];
        foreach (array_keys($initiators) as $index => $name) {
            if ((string) $name === '') {
                continue;
            }
            if (isset($palette[$index])) {
                $hues[$name] = $palette[$index];
                continue;
            }
            $best = -1;
            $hue = 75;
            for ($candidate = 75; $candidate <= 345; $candidate++) {
                $distance = 180;
                foreach ($hues as $taken) {
                    $distance = min($distance, abs($candidate - $taken), 360 - abs($candidate - $taken));
                }
                if ($distance > $best) {
                    [$best, $hue] = [$distance, $candidate];
                }
            }
            $hues[$name] = $hue;
        }

        return $hues;
    }

    private static function queriesWord(int $count): string
    {
        $remainder = $count % 100;
        $words = [1 => 'запрос', 2 => 'запроса', 3 => 'запроса', 4 => 'запроса'];
        return $remainder >= 11 && $remainder <= 14 ? 'запросов' : ($words[$count % 10] ?? 'запросов');
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
                animation: query-panel-in 180ms cubic-bezier(.2, .8, .2, 1);
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
                border-bottom: 1px solid var(--border);
            }
            .query-header h2 {
                margin: 0 auto 0 0;
                color: var(--foreground);
                font: 650 16px/1.25 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                letter-spacing: -.025em;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .query-stats {
                flex: 0 0 auto;
                color: var(--muted);
                font: 600 11px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }
            .query-stats b { color: var(--foreground); }
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
                border: 1px solid var(--border);
                border-radius: 8px;
                background: var(--surface);
                color: var(--muted);
                cursor: pointer;
                transition: background-color 140ms ease, color 140ms ease;
            }
            .query-close:hover { background: var(--hover); color: var(--foreground); }
            .query-close:focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; }
            .query-close svg { width: 16px; height: 16px; }
            .query-filters {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                padding: 8px 18px;
                border-bottom: 1px solid var(--border);
            }
            .query-filter {
                display: inline-flex;
                align-items: baseline;
                gap: 6px;
                padding: 4px 10px;
                border: 1px solid var(--border);
                border-radius: 6px;
                background: var(--surface);
                color: var(--foreground);
                font: 600 12px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                cursor: pointer;
                transition: background-color 140ms ease, color 140ms ease;
            }
            .query-filter:hover { background: var(--hover); }
            .query-filter:focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; }
            .query-filter-stats {
                color: var(--muted);
                font: 600 11px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }
            .query-filter[aria-pressed="true"] {
                border-color: var(--foreground);
                background: var(--foreground);
                color: var(--surface);
            }
            .query-filter[aria-pressed="true"] .query-filter-stats { color: inherit; opacity: .75; }
            .query-group[hidden] { display: none; }
            .query-scroll-area {
                position: relative;
                flex: 1 1 auto;
                min-height: 0;
                overflow: hidden;
            }
            .query-list {
                height: 100%;
                padding: 2px 0;
                overflow: auto;
                scrollbar-width: none;
                overscroll-behavior: contain;
            }
            .query-list::-webkit-scrollbar { display: none; }
            .query-list:focus-visible { outline: 2px solid var(--muted); outline-offset: -2px; }
            .query-scrollbar {
                position: absolute;
                top: 3px;
                right: 0;
                bottom: 3px;
                width: 10px;
                cursor: pointer;
                touch-action: none;
                user-select: none;
            }
            .query-scrollbar[hidden] { display: none; }
            .query-scroll-thumb {
                position: absolute;
                top: 0;
                right: 2px;
                width: 4px;
                border-radius: 4px;
                background: var(--muted);
                opacity: .4;
            }
            .query-scrollbar:hover .query-scroll-thumb,
            .query-scrollbar[data-dragging] .query-scroll-thumb { width: 6px; opacity: .8; }
            .query-row {
                --query-gap: 10px;
                display: grid;
                grid-template-columns: 3px calc(24px + var(--query-number-chars) * 1ch) minmax(0, 1fr) 60px 28px;
                align-items: center;
                gap: 0;
                padding: 4px 14px 4px 0;
                border-bottom: 1px solid var(--border);
                color: var(--foreground);
                font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            }
            .query-list > .query-row:last-child,
            .query-group:last-child > .query-row:last-child { border-bottom: 0; }
            .query-row[hidden] { display: none; }
            .query-row:hover { background: var(--hover); }
            .query-bar {
                width: 3px;
                min-height: 18px;
                align-self: stretch;
                border-radius: 0 2px 2px 0;
                background: var(--border);
            }
            .query-num {
                padding-left: 14px;
                padding-right: var(--query-gap);
                color: var(--muted);
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
                display: block;
                margin-top: 3px;
                color: #71717a;
                font: 10px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                white-space: normal;
            }
            .query-source-label { color: #52525b; }
            :host([data-theme="dark"]) .query-source { color: #a1a1aa; }
            :host([data-theme="dark"]) .query-source-label { color: #d4d4d8; }
            .query-time {
                padding-right: var(--query-gap);
                color: var(--muted);
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
                color: var(--muted);
            }
            .query-copy:hover { background: var(--hover); color: var(--foreground); }
            .query-copy:focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; }
            .query-copy svg { width: 16px; height: 16px; }
            .query-copy-status { margin: 0; color: var(--muted); font: 12px/1.45 sans-serif; }
            .query-copy-status:not(:empty) { padding: 8px 14px; border-top: 1px solid var(--border); }
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
            .query-tinted { --query-color: oklch(50% .15 var(--query-hue)); }
            :host([data-theme="dark"]) .query-tinted { --query-color: oklch(76% .13 var(--query-hue)); }
            .query-tinted .query-source-label,
            :host([data-theme="dark"]) .query-tinted .query-source-label { color: var(--query-color); font-weight: 600; }
            .query-filter.query-tinted::before {
                content: "";
                flex: 0 0 auto;
                align-self: center;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: var(--query-color);
            }
            .query-filter.query-tinted[aria-pressed="true"]::before { box-shadow: 0 0 0 2px var(--surface); }
            .query-copy-buffer { position: fixed; top: 0; left: 0; width: 1px; height: 1px; opacity: 0; pointer-events: none; font-size: 16px; }
            .query-row-fast .query-text { opacity: .7; }
            .query-row-fast:hover .query-text { opacity: 1; }
            .query-fold {
                background: var(--row-stripe);
                cursor: pointer;
                -webkit-user-select: none;
                user-select: none;
                opacity: .55;
                transition: opacity 140ms ease, background-color 140ms ease;
            }
            .query-fold:hover { background: var(--hover); opacity: .85; }
            .query-fold:focus-visible { outline: 2px solid var(--foreground); outline-offset: -2px; }
            .query-fold .query-bar { background: transparent; }
            .query-fold .query-sql,
            .query-fold .query-num,
            .query-fold .query-time { color: var(--muted); }
            .query-fold[data-open="true"] .query-num { font-size: 18px; }
            .query-empty {
                display: grid;
                min-height: 100%;
                place-items: center;
                margin: 0;
                padding: 26px 16px;
                color: var(--muted);
                text-align: center;
            }
            @media (max-width: 480px) {
                .query-panel { height: min(560px, calc(100dvh - 112px)); border-radius: 12px; }
                .query-header { gap: 8px; padding: 11px 12px; }
                .query-filters { padding: 8px 12px; }
                .query-stats { display: none; }
                .query-row {
                    --query-gap: 8px;
                    grid-template-columns: 3px calc(18px + var(--query-number-chars) * 1ch) minmax(0, 1fr) 52px 28px;
                    padding-right: 10px;
                    font-size: 11px;
                }
                .query-sql { font-size: 11px; }
                .query-num { padding-left: 10px; }
            }
            @media (prefers-reduced-motion: reduce) {
                .query-panel { animation: none; }
                .query-close, .query-fold, .query-filter { transition: none; }
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
        const queryList = queryPanel.querySelector('.query-list');
        const scrollbar = queryPanel.querySelector('[data-query-scrollbar]');
        const scrollThumb = scrollbar.firstElementChild;
        const updateScrollbar = () => {
            const maxScroll = queryList.scrollHeight - queryList.clientHeight;
            scrollbar.hidden = queryList.clientHeight === 0 || maxScroll <= 0;
            if (scrollbar.hidden) return;
            const trackHeight = scrollbar.clientHeight;
            const thumbHeight = Math.min(trackHeight, Math.max(24, trackHeight * queryList.clientHeight / queryList.scrollHeight));
            scrollThumb.style.height = thumbHeight + 'px';
            scrollThumb.style.transform = 'translateY(' + (trackHeight - thumbHeight) * queryList.scrollTop / maxScroll + 'px)';
        };
        queryList.addEventListener('scroll', updateScrollbar, { passive: true });
        scrollbar.addEventListener('wheel', (event) => {
            event.preventDefault();
            const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? queryList.clientHeight : 1;
            queryList.scrollTop += event.deltaY * unit;
        }, { passive: false });
        const scrollResizeObserver = new ResizeObserver(updateScrollbar);
        scrollResizeObserver.observe(queryList);
        [...queryList.children].forEach((entry) => scrollResizeObserver.observe(entry));
        let dragOffset = 0;
        const scrollToPointer = (event) => {
            const travel = scrollbar.clientHeight - scrollThumb.offsetHeight;
            if (travel <= 0) return;
            queryList.scrollTop = (event.clientY - scrollbar.getBoundingClientRect().top - dragOffset)
                / travel * (queryList.scrollHeight - queryList.clientHeight);
        };
        scrollbar.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) return;
            event.preventDefault();
            dragOffset = event.target === scrollThumb
                ? event.clientY - scrollThumb.getBoundingClientRect().top : scrollThumb.offsetHeight / 2;
            scrollbar.dataset.dragging = 'true';
            scrollbar.setPointerCapture(event.pointerId);
            scrollToPointer(event);
        });
        scrollbar.addEventListener('pointermove', (event) => {
            if (scrollbar.hasPointerCapture(event.pointerId)) scrollToPointer(event);
        });
        scrollbar.addEventListener('pointerup', (event) => {
            if (scrollbar.hasPointerCapture(event.pointerId)) scrollbar.releasePointerCapture(event.pointerId);
        });
        scrollbar.addEventListener('lostpointercapture', () => delete scrollbar.dataset.dragging);
        queryPanel.querySelectorAll('[data-copy-query]').forEach((button) => {
            button.addEventListener('click', () => {
                copyStatus.textContent = '';
                const sql = button.closest('.query-row').querySelector('.query-text').textContent;
                const report = (copied) => {
                    copyStatus.textContent = copied ? 'SQL-запрос скопирован.'
                        : 'Не удалось скопировать SQL-запрос. Проверьте разрешение браузера на доступ к буферу обмена.';
                };
                // Without HTTPS browsers hide the Clipboard API, so copy the selected text of a temporary field instead.
                const copyBySelection = () => {
                    const buffer = document.createElement('textarea');
                    buffer.className = 'query-copy-buffer';
                    buffer.readOnly = true;
                    buffer.value = sql;
                    queryPanel.append(buffer);
                    buffer.select();
                    const copied = document.execCommand('copy');
                    button.focus();
                    buffer.remove();
                    return copied;
                };
                if (navigator.clipboard) navigator.clipboard.writeText(sql).then(() => report(true), () => report(copyBySelection()));
                else report(copyBySelection());
            });
        });
        const closeButton = queryPanel.querySelector('[data-query-close]');
        const closedQueriesLabel = queriesButton.getAttribute('aria-label');
        const folds = [...queryPanel.querySelectorAll('[data-fold]')];
        const filters = [...queryPanel.querySelectorAll('.query-filter')];
        filters.forEach((filter) => {
            filter.addEventListener('click', () => {
                filters.forEach((button) => button.setAttribute('aria-pressed', button === filter ? 'true' : 'false'));
                queryPanel.querySelectorAll('.query-list > [data-initiator]').forEach((entry) => {
                    entry.hidden = !filter.hasAttribute('data-query-filter-all')
                        && entry.dataset.initiator !== filter.dataset.queryFilter;
                });
            });
        });
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
                row.hidden = !expanded;
            });
            fold.dataset.open = expanded ? 'true' : 'false';
            fold.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            fold.querySelector('[data-fold-marker]').textContent = expanded ? '×' : '···';
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

    /** @param 'queries'|'close'|'copy' $id */
    private static function icon(string $id): string
    {
        $paths = [
            'queries' => '<path d="M4 6a8 3 0 1 0 16 0a8 3 0 1 0 -16 0M4 6v6a8 3 0 0 0 16 0v-6M4 12v6a8 3 0 0 0 16 0v-6"/>',
            'close' => '<path d="m18 6-12 12"/><path d="m6 6 12 12"/>',
            'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0 -2 -2H6a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2"/>',
        ];

        return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $paths[$id] . '</svg>';
    }
}
