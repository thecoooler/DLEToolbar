<?php

namespace iCooLER\DLEToolbar\Components\CacheCalls;

use iCooLER\DLEToolbar\ComponentFramework\Component;
use iCooLER\DLEToolbar\ComponentFramework\RequestComponent;

/** Lists the DLE cache calls made on the page: hits, misses, expired entries and writes. */
final class CacheCalls implements Component, RequestComponent
{
    public static function definition(): array
    {
        return [
            'label' => 'Вызовы кэша DLE',
            'description' => 'Показывает обращения к dle_cache() и create_cache(): попадание, промах, истёкший кэш, запись, ключ и время.',
        ];
    }

    public function initialize(): void
    {
        CacheCallLog::start();
    }

    public function render(): array
    {
        $entries = CacheCallLog::entries();
        $total = count($entries);
        $hits = $misses = $writes = 0;

        foreach ($entries as $entry) {
            if ($entry['operation'] === 'write') {
                $writes++;
            } elseif ($entry['status'] === 'hit') {
                $hits++;
            } else {
                $misses++;
            }
        }

        $rows = '';
        foreach ($entries as $entry) {
            $rows .= $this->row($entry);
        }

        $subtitle = 'Вызовов: ' . $total . ' · Из кэша: ' . $hits . ' · Без кэша: ' . $misses . ' · Записей: ' . $writes;
        $empty = '<p class="cachecalls-empty">Вызовов dle_cache() и create_cache() на этой странице не было.</p>';

        return [
            'control' => '<button class="control metric cachecalls" data-component="cachecalls" data-cachecalls-open type="button"'
                . ' aria-expanded="false" aria-controls="dle-toolbar-cachecalls-panel" aria-label="Вызовы кэша: ' . $total
                . '. Показать журнал кэша"><svg viewBox="0 0 24 24" aria-hidden="true">'
                . '<path d="M12 3c4.97 0 9 1.79 9 4s-4.03 4-9 4-9-1.79-9-4 4.03-4 9-4Z"/>'
                . '<path d="M3 7v5c0 2.21 4.03 4 9 4s9-1.79 9-4V7"/>'
                . '<path d="M3 12v5c0 2.21 4.03 4 9 4s9-1.79 9-4v-5"/></svg>'
                . '<span class="metric-value">' . $total . '</span></button>',
            'panel' => '<section class="cachecalls-panel" id="dle-toolbar-cachecalls-panel"'
                . ' aria-labelledby="dle-toolbar-cachecalls-title" hidden>'
                . '<header class="cachecalls-header"><div><h2 id="dle-toolbar-cachecalls-title">Кэш страницы</h2>'
                . '<span class="cachecalls-subtitle">' . $subtitle . '</span></div>'
                . '<button type="button" data-cachecalls-close aria-label="Закрыть журнал кэша">×</button></header>'
                . '<div class="cachecalls-scroll">'
                . ($rows === '' ? $empty : '<ol class="cachecalls-list">' . $rows . '</ol>')
                . '</div></section>',
            'styles' => self::styles(),
            'script' => self::script(),
        ];
    }

    /** @param array{operation: string, prefix: string, key: string, member: bool, status: string, backend: string, size: int|null, at: float, duration: float, source: string} $entry */
    private function row(array $entry): string
    {
        $statuses = [
            'hit' => ['из кэша', 'hit'],
            'miss' => ['не из кэша', 'miss'],
            'expired' => ['истёк', 'expired'],
            'disabled' => ['кэш отключён', 'disabled'],
            'written' => ['записано', 'written'],
        ];
        $backends = ['files' => 'файлы', 'redis' => 'Redis', 'memcache' => 'Memcache'];
        [$statusLabel, $statusClass] = $statuses[$entry['status']];
        $operation = $entry['operation'] === 'write' ? 'запись' : 'чтение';

        $key = $entry['key'] !== $entry['prefix']
            ? '<code class="cachecalls-key">' . self::escape($entry['key']) . '</code>'
            : '';
        $size = $entry['size'] !== null
            ? '<span>' . number_format($entry['size'], 0, '.', '') . ' Б</span>'
            : '';
        $source = $entry['source'] !== ''
            ? '<span class="cachecalls-source">' . self::escape($entry['source']) . '</span>'
            : '';

        return '<li class="cachecall cachecall-' . $statusClass . '">'
            . '<span class="cachecall-status">' . $statusLabel . '</span>'
            . '<div class="cachecall-body">'
            . '<div class="cachecall-head"><code class="cachecalls-prefix">' . self::escape($entry['prefix']) . '</code>' . $key . '</div>'
            . '<div class="cachecall-meta"><span>' . $operation . '</span>'
            . '<span>' . $backends[$entry['backend']] . '</span>'
            . '<span title="Конец вызова от начала запроса">на ' . self::milliseconds($entry['at']) . '</span>'
            . '<span title="Длительность вызова">+' . self::milliseconds($entry['duration']) . '</span>'
            . $size . '</div>'
            . $source . '</div></li>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function milliseconds(float $value): string
    {
        return $value < 0.01 ? '&lt;0.01 мс' : number_format($value, 2, '.', '') . ' мс';
    }

    private static function styles(): string
    {
        return <<<'CSS'
            .cachecalls { gap: 6px; cursor: pointer; }
            .cachecalls[aria-expanded="true"] { background: var(--hover); color: var(--foreground); box-shadow: inset 0 0 0 1px var(--border); }
            .cachecalls-panel {
                pointer-events: auto;
                display: flex;
                flex-direction: column;
                width: min(720px, 100%);
                max-height: min(560px, calc(100dvh - 96px));
                overflow: hidden;
                border: 1px solid var(--border);
                border-radius: 14px;
                background: var(--surface);
                color: var(--foreground);
                box-shadow: var(--shadow);
                transform-origin: left bottom;
                animation: cachecalls-panel-in 180ms cubic-bezier(.2, .8, .2, 1);
            }
            .cachecalls-panel[hidden] { display: none; }
            .cachecalls-panel[data-closing="true"] {
                pointer-events: none;
                animation: cachecalls-panel-out 150ms ease-in forwards;
            }
            @keyframes cachecalls-panel-in {
                from { opacity: 0; transform: translateY(8px) scale(.99); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }
            @keyframes cachecalls-panel-out {
                from { opacity: 1; transform: translateY(0) scale(1); }
                to { opacity: 0; transform: translateY(6px) scale(.99); }
            }
            .cachecalls-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 12px 16px;
                border-bottom: 1px solid var(--border);
            }
            .cachecalls-header h2 {
                margin: 0;
                color: var(--foreground);
                font: 650 15px/1.25 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                letter-spacing: -.02em;
            }
            .cachecalls-subtitle { display: block; margin-top: 2px; color: var(--muted); font-size: 11px; }
            .cachecalls-header button {
                flex: 0 0 auto;
                padding: 2px 6px;
                border: 0;
                border-radius: 6px;
                background: transparent;
                color: var(--muted);
                font: inherit;
                font-size: 22px;
                line-height: 1;
                cursor: pointer;
            }
            .cachecalls-header button:hover { background: var(--hover); color: var(--foreground); }
            .cachecalls-scroll { min-height: 0; overflow: auto; overscroll-behavior: contain; scrollbar-width: thin; }
            .cachecalls-panel :focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; }
            .cachecalls-list { margin: 0; padding: 0; list-style: none; }
            .cachecall {
                display: grid;
                grid-template-columns: 112px minmax(0, 1fr);
                gap: 10px;
                padding: 10px 16px;
                border-bottom: 1px solid var(--border);
            }
            .cachecalls-list > .cachecall:last-child { border-bottom: 0; }
            .cachecall-status {
                align-self: start;
                justify-self: start;
                font: 600 10px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                padding: 2px 7px;
                border-radius: 999px;
                border: 1px solid var(--border);
                color: var(--muted);
                white-space: nowrap;
            }
            .cachecall-hit .cachecall-status { background: rgba(245, 158, 11, .15); border-color: rgba(245, 158, 11, .3); color: #b45309; }
            .cachecall-written .cachecall-status { background: rgba(37, 99, 235, .12); border-color: rgba(37, 99, 235, .28); color: #1d4ed8; }
            .cachecall-expired .cachecall-status { background: rgba(220, 38, 38, .1); border-color: rgba(220, 38, 38, .28); color: #b91c1c; }
            :host([data-theme="dark"]) .cachecall-hit .cachecall-status { background: rgba(245, 158, 11, .22); border-color: rgba(245, 158, 11, .35); color: #fcd34d; }
            :host([data-theme="dark"]) .cachecall-written .cachecall-status { background: rgba(59, 130, 246, .2); border-color: rgba(59, 130, 246, .35); color: #93c5fd; }
            :host([data-theme="dark"]) .cachecall-expired .cachecall-status { background: rgba(248, 113, 113, .14); border-color: rgba(248, 113, 113, .32); color: #fca5a5; }
            .cachecall-body { min-width: 0; }
            .cachecall-head { display: flex; align-items: baseline; flex-wrap: wrap; gap: 8px; min-width: 0; }
            .cachecalls-prefix {
                color: var(--foreground);
                font: 600 12px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                overflow-wrap: anywhere;
            }
            .cachecalls-key {
                color: var(--muted);
                font: 11px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                overflow-wrap: anywhere;
            }
            .cachecall-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 6px 10px;
                margin-top: 4px;
                color: var(--muted);
                font: 600 10px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-variant-numeric: tabular-nums;
            }
            .cachecall-meta > span:first-child { color: var(--foreground); }
            .cachecalls-source {
                display: block;
                margin-top: 4px;
                color: #71717a;
                font: 10px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                overflow-wrap: anywhere;
            }
            :host([data-theme="dark"]) .cachecalls-source { color: #a1a1aa; }
            .cachecalls-empty { margin: 0; padding: 26px 16px; color: var(--muted); font-size: 12px; text-align: center; }
            @media (max-width: 480px) {
                .cachecalls-panel { max-height: min(560px, calc(100dvh - 112px)); border-radius: 12px; }
                .cachecalls-header { padding: 11px 12px; }
                .cachecalls-subtitle { display: none; }
                .cachecall { grid-template-columns: 92px minmax(0, 1fr); gap: 8px; padding: 9px 12px; }
            }
            @media (prefers-reduced-motion: reduce) {
                .cachecalls-panel { animation: none; }
            }
CSS;
    }

    private static function script(): string
    {
        return <<<'JS'
    (() => {
        const button = root.querySelector('[data-cachecalls-open]');
        const panel = root.querySelector('#dle-toolbar-cachecalls-panel');
        const closedLabel = button.getAttribute('aria-label');
        const popup = createPopup({
            trigger: button,
            panel,
            closeDuration: 150,
            onOpen: () => button.setAttribute('aria-label', closedLabel.replace('Показать журнал кэша', 'Закрыть журнал кэша')),
            onClose: () => button.setAttribute('aria-label', closedLabel),
        });
        button.addEventListener('click', () => popup.isOpen() ? popup.close() : popup.open());
        panel.querySelector('[data-cachecalls-close]').addEventListener('click', () => popup.close({ restoreFocus: true }));
        root.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && popup.isOpen()) {
                event.preventDefault();
                popup.close({ restoreFocus: true });
            }
        });
        document.addEventListener('click', (event) => {
            if (popup.isOpen() && !event.composedPath().includes(root.host)) popup.close();
        }, true);
    })();
JS;
    }
}
