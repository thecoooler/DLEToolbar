<?php

namespace iCooLER\DLEToolbar\Components\Debug;

use iCooLER\DLEToolbar\ComponentFramework\ConfigurableComponent;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;

final class Debug implements ConfigurableComponent
{
    private float $slowThreshold;

    public static function definition(): array
    {
        return ['label' => 'Отладочный лог', 'description' => 'Отметки выполнения с файлом, строкой и таймлайном задержек между ними.'];
    }

    public static function parameters(): array
    {
        return ['slow_threshold_ms' => Parameter::number('Порог задержки, мс', 50, 0.1, 0.1,
            'Интервалы между отметками не меньше порога выделяются цветом.')];
    }

    public function __construct(array $values)
    {
        $this->slowThreshold = (float) $values['slow_threshold_ms'];
    }

    public function render(): array
    {
        $entries = DebugLog::entries();
        $count = count($entries);
        $end = DebugLog::elapsed();
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $format = static fn (float $time): string => number_format($time, 2, '.', '') . ' мс';
        $rows = '';
        if ($entries !== []) {
            // Include both edges: work before the first call and after the last one must stay visible.
            $entries[] = ['message' => 'Вывод тулбара', 'file' => '', 'line' => 0, 'time' => $end];
            $previous = 0.0;
            $gaps = [];
            foreach ($entries as $entry) {
                $gaps[] = $entry['time'] - $previous;
                $previous = $entry['time'];
            }
            $longest = array_search(max($gaps), $gaps, true);
            foreach ($entries as $index => $entry) {
                $gap = $gaps[$index];
                $left = $end > 0 ? ($entry['time'] - $gap) / $end * 100 : 0;
                $width = $end > 0 ? $gap / $end * 100 : 0;
                $rows .= '<li class="debug-row' . ($gap >= $this->slowThreshold ? ' debug-slow' : '') . '">'
                    . '<div class="debug-row-title"><strong>' . $escape($entry['message']) . '</strong>'
                    . ($index === $longest ? '<span class="debug-longest">Самый долгий интервал</span>' : '') . '</div>'
                    . ($entry['file'] === '' ? '<span class="debug-source">Конец измерения</span>'
                        : '<span class="debug-source">' . $escape($entry['file']) . ':' . $entry['line'] . '</span>')
                    . '<div class="debug-track" aria-hidden="true"><span style="left:' . number_format($left, 6, '.', '')
                    . '%;width:' . number_format($width, 6, '.', '') . '%"></span></div>'
                    . '<div class="debug-times"><span>От начала: ' . $format($entry['time']) . '</span>'
                    . '<b>Интервал: +' . $format($gap) . '</b></div></li>';
            }
        }
        $panel = '<section class="debug-panel" id="dle-toolbar-debug-panel" aria-labelledby="dle-toolbar-debug-title" hidden>'
            . '<header><h2 id="dle-toolbar-debug-title">Отладочный лог · ' . $count . '</h2>'
            . '<button type="button" data-debug-close aria-label="Закрыть отладочный лог">×</button></header>'
            . '<p class="debug-description">Время от начала запроса. Полоса — интервал перед отметкой; задержка находится между предыдущей и текущей точкой. '
            . 'До вывода тулбара: ' . $format($end) . '.</p>'
            . ($count === 0 ? '<p class="debug-empty">Записей нет. Добавьте вызовы DebugLog::add() в исследуемые участки CMS.</p>'
                : '<ol class="debug-list" tabindex="0" aria-label="Лог и таймлайн">' . $rows . '</ol>') . '</section>';

        return [
            'control' => '<button class="control debug" type="button" data-component="debug" aria-label="Отладочный лог: ' . $count
                . '" aria-expanded="false" aria-controls="dle-toolbar-debug-panel"><span aria-hidden="true">'
                . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 5h16M4 12h16M4 19h16M8 3v4M16 10v4M10 17v4"/></svg>'
                . '</span><span class="metric-value">' . $count . '</span></button>',
            'panel' => $panel,
            'styles' => self::styles(),
            'script' => self::script(),
        ];
    }

    private static function styles(): string
    {
        return <<<'CSS'
            .debug-panel { pointer-events: auto; width: min(800px, calc(100vw - 24px)); max-height: calc(100dvh - 120px); display: flex; flex-direction: column; background: var(--surface); color: var(--foreground); border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow); overflow: hidden; }
            .debug-panel[hidden] { display: none; }
            .debug-panel header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; gap: 12px; border-bottom: 1px solid var(--border); }
            .debug-panel h2 { margin: 0; font-size: 14px; }
            .debug-panel button { border: 0; background: transparent; color: var(--muted); font: inherit; font-size: 24px; cursor: pointer; border-radius: 6px; padding: 0 8px; }
            .debug-panel button:hover { background: var(--hover); }
            .debug-panel :focus-visible { outline: 2px solid var(--foreground); outline-offset: -2px; }
            .debug-description, .debug-empty { margin: 0; padding: 12px 16px; color: var(--muted); font-size: 12px; }
            .debug-list { list-style: none; margin: 0; padding: 0; overflow: auto; overscroll-behavior: contain; }
            .debug-row { padding: 12px 16px; border-top: 1px solid var(--border); }
            .debug-row:nth-child(even) { background: var(--row-stripe); }
            .debug-row-title { display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: baseline; overflow-wrap: anywhere; white-space: pre-wrap; }
            .debug-longest { font-size: 11px; color: var(--muted); }
            .debug-source { display: block; margin-top: 4px; font: 11px/1.5 monospace; color: var(--muted); overflow-wrap: anywhere; }
            .debug-track { height: 8px; background: var(--hover); border-radius: 4px; position: relative; margin: 10px 0 6px; }
            .debug-track span { position: absolute; top: 0; height: 100%; min-width: 2px; max-width: 100%; border-radius: 4px; background: #3b82f6; }
            .debug-times { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; font-size: 11px; font-variant-numeric: tabular-nums; color: var(--muted); }
            .debug-slow .debug-track span { background: #dc2626; }
            .debug-slow .debug-times b { color: #dc2626; }
            :host([data-theme="dark"]) .debug-slow .debug-times b { color: #f87171; }
CSS;
    }

    private static function script(): string
    {
        return <<<'JS'
    (() => {
        const trigger = root.querySelector('[data-component="debug"]');
        const panel = root.querySelector('#dle-toolbar-debug-panel');
        const close = panel.querySelector('[data-debug-close]');
        const popup = createPopup({ trigger, panel, closeDuration: 0, onOpen: () => close.focus() });
        trigger.addEventListener('click', () => popup.isOpen() ? popup.close() : popup.open());
        close.addEventListener('click', () => popup.close({ restoreFocus: true }));
        root.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && popup.isOpen()) {
                event.preventDefault();
                popup.close({ restoreFocus: true });
            }
        });
        document.addEventListener('click', (event) => {
            if (popup.isOpen() && !event.composedPath().includes(panel) && !event.composedPath().includes(trigger)) popup.close();
        });
    })();
JS;
    }
}
