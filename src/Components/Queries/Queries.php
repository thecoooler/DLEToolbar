<?php

namespace iCooLER\DLEToolbar\Components\Queries;

use iCooLER\DLEToolbar\ComponentFramework\ConfigurableComponent;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;

/**
 * Owns the SQL control and panel inside the toolbar's shadow root.
 * The script runs with `root` in scope and emits dle-toolbar-popup-open
 * so the toolbar can dismiss its shared tooltip.
 */
final class Queries implements ConfigurableComponent
{
    private float $slowThreshold;
    private bool $foldFast;

    public static function parameters(): array
    {
        return [
            'slow_threshold_ms' => Parameter::number('Порог долгого запроса, мс', 20, 0.1, 0.1,
                'Запросы с длительностью не меньше порога подсвечиваются как долгие.'),
            'fold_fast' => Parameter::boolean('Сворачивать быстрые запросы', true,
                'Объединять соседние запросы быстрее 1 мс. Долгие запросы остаются видимыми.'),
            'trace_sources' => Parameter::boolean('Показывать источник запросов (экспериментально)', false,
                'Экспериментальная функция для DLE 14.0 и новее: показывает файл, строку вызова и модуль или плагин для запросов после инициализации панели. Заменяет журнал запросов $db->query_list на время обработки страницы; сторонний код, работающий с ним как с массивом, может перестать работать.'),
        ];
    }

    public function __construct(array $values)
    {
        $this->slowThreshold = (float) $values['slow_threshold_ms'] / 1000;
        $this->foldFast = (bool) $values['fold_fast'];
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
        /** @var array{version_id: string} $config */
        /** @var iterable<int, array{query: string, time: float, num: int, source?: string}> $queries QueryLog when source tracing is enabled. */
        $queries = $db->query_list;
        /** @var int $queryCount */
        $queryCount = $db->query_num;

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
            // DLE writes the session hash and password hashes in plain UPDATE queries.
            /** @var string $sql */
            $sql = preg_replace("/\\b(password|hash)\\s*=\\s*'(?:[^'\\\\]|\\\\.)*'/i", "\$1='***'", $query['query']);
            $item = ['query' => $sql, 'time' => $query['time'], 'number' => $number, 'source' => $query['source'] ?? ''];
            $lastIndex = array_key_last($groups);
            $isFast = $this->foldFast && $query['time'] < 0.001 && $query['time'] < $this->slowThreshold;
            if ($isFast && $lastIndex !== null && $groups[$lastIndex]['fast']) {
                $groups[$lastIndex]['items'][] = $item;
                continue;
            }

            $groups[] = ['fast' => $isFast, 'items' => [$item]];
        }

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
                    . '<span class="query-sql">№' . $items[0]['number'] . '–' . $items[count($items) - 1]['number']
                    . ' · <span data-fold-verb></span>' . count($items) . ' быстрых</span>'
                    . '<span class="query-time"></span>'
                    . '</div>';

                foreach ($items as $item) {
                    $rows .= '<div class="query-row query-row-plain query-row-fast" data-fast-row hidden>'
                        . '<span class="query-bar"></span>'
                        . '<span class="query-num">·</span>'
                        . '<span class="query-sql"><span class="query-text">' . $escape($item['query']) . '</span>'
                        . $this->renderSource($item['source']) . '</span>'
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
                $rows .= '<div class="query-row' . ($isSlow ? ' query-row-slow' : ' query-row-plain') . '">'
                    . '<span class="query-bar"></span>'
                    . '<span class="query-num">#' . $item['number'] . '</span>'
                    . '<span class="query-sql"><span class="query-text">' . $escape($item['query']) . '</span>'
                    . $this->renderSource($item['source']) . '</span>'
                    . '<span class="query-time">' . ($this->foldFast && !$isSlow && $item['time'] < 0.001 ? '' : $format($item['time'])) . '</span>'
                    . $this->renderQueryCopyButton()
                    . '</div>';
            }
        }

        if (version_compare($config['version_id'], '14.0', '<')) {
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
            $word = $remainder >= 11 && $remainder <= 14
                ? 'запросов'
                : ($words[$number % 10] ?? 'запросов');
            $stats = '<span class="query-stats"><b>' . $number . ' ' . $word . '</b> · ' . $format($totalTime)
                . ($slowCount > 0 ? ' · <span class="query-stats-alert">⚠ ' . $slowCount . '</span>' : '')
                . '</span>';
        }

        return '<section class="query-panel" data-query-panel id="dle-toolbar-queries-panel"'
            . ' aria-labelledby="dle-toolbar-queries-title" hidden>'
            . '<header class="query-header"><h2 id="dle-toolbar-queries-title">Запросы страницы</h2>'
            . $stats
            . '<button class="query-close" data-query-close type="button" aria-label="Закрыть список запросов">'
            . self::icon('close') . '</button></header>'
            . '<div class="query-list">' . $rows . '</div>'
            . '<p class="query-copy-status" data-copy-status role="status" aria-live="polite"></p></section>';
    }

    private function renderSource(string $source): string
    {
        if ($source === '') return '';

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
                if (count($parts) >= 4) $label = $parts[2];
                elseif (!in_array(basename($parts[2], '.php'), $nativeModules, true)) $label = basename($parts[2], '.php');
            } elseif (!in_array($parts[1], $nativeDirectories, true)) {
                $label = count($parts) >= 4 ? $parts[2] : $parts[1];
            }
        }
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<span class="query-source">' . $escape($source)
            . ' · <span class="query-source-label">' . $escape($label) . '</span></span>';
    }

    private function renderQueryCopyButton(): string {
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
            .query-row-fast { opacity: .7; }
            .query-row-fast:hover { opacity: 1; }
            .query-fold {
                background: var(--row-stripe);
                cursor: pointer;
                font-style: italic;
                opacity: .55;
                transition: opacity 140ms ease, background-color 140ms ease;
            }
            .query-fold:hover { background: var(--hover); opacity: .85; }
            .query-fold:focus-visible { outline: 2px solid var(--foreground); outline-offset: -2px; }
            .query-fold .query-bar { background: transparent; }
            .query-fold .query-sql,
            .query-fold .query-num,
            .query-fold .query-time { color: var(--muted); }
            .query-fold[data-open="true"] { opacity: 1; background: var(--hover); }
            .query-fold[data-open="true"] .query-sql {
                color: var(--foreground);
                font-style: normal;
            }
            .query-fold[data-open="true"] .query-num,
            .query-fold[data-open="true"] .query-time { color: var(--foreground); }
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
                row.hidden = !expanded;
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
