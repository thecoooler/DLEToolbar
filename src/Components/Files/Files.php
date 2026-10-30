<?php

namespace iCooLER\DLEToolbar\Components\Files;

use iCooLER\DLEToolbar\ComponentFramework\ConfigurableComponent;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;
use iCooLER\DLEToolbar\ComponentFramework\SourcePath;
use iCooLER\DLEToolbar\Components\Debug\DebugLog;

/** One diagnostics panel: chronological marks and the files loaded during each DLE stage. */
final class Files implements ConfigurableComponent
{
    private float $slowThreshold;

    public static function definition(): array
    {
        return ['label' => 'Лог и файлы', 'description' => 'Общий таймлайн ручных отметок и этапов DLE, файл и строка вызова, список подключённых файлов.'];
    }

    public static function parameters(): array
    {
        return ['slow_threshold_ms' => Parameter::number(
            'Порог задержки, мс',
            50,
            0.1,
            0.1,
            'Интервалы между отметками не меньше порога выделяются цветом.',
        )];
    }

    public function __construct(array $values)
    {
        $this->slowThreshold = (float) $values['slow_threshold_ms'];
    }

    public function render(): array
    {
        $snapshot = DebugLog::snapshot();
        $count = count($snapshot['before']);
        $groups = '<details class="files-group"><summary>До начала замеров · ' . $count . '</summary>'
            . self::fileRows($snapshot['before']) . '</details>';
        foreach ($snapshot['stages'] as $stage) {
            $count += count($stage['files']);
            $groups .= '<details class="files-group" open><summary><span>' . self::escape($stage['label']) . '</span>'
                . '<span class="files-timing">+' . self::milliseconds($stage['start']) . ' · '
                . self::milliseconds($stage['duration']) . ' · файлов: ' . count($stage['files']) . '</span></summary>'
                . self::fileRows($stage['files']) . '</details>';
        }
        $entryCount = count($snapshot['entries']);
        $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h16M8 3v4M16 10v4M10 17v4"/></svg>';
        return [
            'control' => '<button class="control metric files" data-component="files" data-files-open type="button"'
                . ' aria-expanded="false" aria-controls="dle-toolbar-files-panel" aria-label="Лог и файлы: ' . $entryCount
                . ' отметок, ' . $count . ' файлов. Показать панель">'
                . $icon . '<span class="metric-value">' . $count . '</span></button>',
            'panel' => '<section class="files-panel" id="dle-toolbar-files-panel" aria-labelledby="dle-toolbar-files-title" hidden>'
                . '<header class="files-header"><h2 id="dle-toolbar-files-title">Лог и файлы</h2>'
                . '<button type="button" data-files-close aria-label="Закрыть панель диагностики">×</button></header>'
                . '<div class="files-tabs" role="group" aria-label="Представление диагностики">'
                . '<button type="button" data-files-view="timeline" aria-controls="dle-toolbar-files-timeline" aria-pressed="true">Таймлайн · ' . $entryCount . '</button>'
                . '<button type="button" data-files-view="list" aria-controls="dle-toolbar-files-list" aria-pressed="false">Файлы · ' . $count . '</button></div>'
                . '<div class="files-scroll" id="dle-toolbar-files-timeline" role="region" tabindex="0" aria-label="Лог и таймлайн">'
                . '<p class="files-note">Время от начала запроса. Полоса показывает интервал перед отметкой: задержка находится между предыдущей и текущей точкой. '
                . 'Ручные отметки сохраняют длительности этапов DLE целиком.</p>'
                . $this->timeline($snapshot['entries'], $snapshot['end']) . '</div>'
                . '<div class="files-scroll" id="dle-toolbar-files-list" role="region" tabindex="0" aria-label="Список подключённых файлов" hidden>'
                . '<p class="files-note">Время измерено для этапа целиком, включая SQL и вложенные подключения. '
                . 'У engine/engine.php измерено подключение целиком. +мс — начало этапа от запуска сбора после инициализации панели. '
                . 'Повторные подключения одного файла в списке не дублируются. Снимок сделан до вывода панели.</p>'
                . $groups . '</div></section>',
            'styles' => self::styles(),
            'script' => self::script(),
        ];
    }

    /** @param list<array{message: string, file: string, line: int, time: float, kind: string}> $entries */
    private function timeline(array $entries, float $end): string
    {
        if ($entries === []) {
            return '<p class="files-empty">Отметок нет. Для ручной записи вызовите DebugLog::add() в нужной точке CMS.</p>';
        }
        if ($entries[count($entries) - 1]['kind'] !== 'end') {
            $entries[] = ['message' => 'Снимок панели', 'file' => '', 'line' => 0, 'time' => $end, 'kind' => 'end'];
        }
        $gaps = [];
        $previous = 0.0;
        foreach ($entries as $entry) {
            $gaps[] = $entry['time'] - $previous;
            $previous = $entry['time'];
        }
        $longest = array_search(max($gaps), $gaps, true);
        $rows = '';
        foreach ($entries as $index => $entry) {
            $gap = $gaps[$index];
            $left = $end > 0 ? ($entry['time'] - $gap) / $end * 100 : 0;
            $width = $end > 0 ? $gap / $end * 100 : 0;
            $source = $entry['file'] === '' ? 'Граница измерения' : $entry['file'] . ':' . $entry['line'];
            $rows .= '<li class="files-event' . ($gap >= $this->slowThreshold ? ' files-slow' : '') . '">'
                . '<div class="files-event-title"><strong>' . self::escape($entry['message']) . '</strong>'
                . ($index === $longest ? '<span class="files-longest">Самый долгий интервал</span>' : '') . '</div>'
                . '<span class="files-source">' . self::escape($source) . '</span>'
                . '<div class="files-track" aria-hidden="true"><span style="left:' . number_format($left, 6, '.', '')
                . '%;width:' . number_format($width, 6, '.', '') . '%"></span></div>'
                . '<div class="files-times"><span>От начала: ' . self::milliseconds($entry['time']) . '</span>'
                . '<b>Интервал: +' . self::milliseconds($gap) . '</b></div></li>';
        }
        return '<ol class="files-events">' . $rows . '</ol>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function milliseconds(float $value): string
    {
        return number_format($value, 2, '.', '') . ' мс';
    }

    /** @param list<string> $files */
    private static function fileRows(array $files): string
    {
        $rows = '';
        foreach ($files as $file) {
            $path = SourcePath::display($file);
            $rows .= '<li><code>' . self::escape($path) . '</code></li>';
        }
        return $rows === '' ? '<p class="files-empty">Новых файлов нет.</p>' : '<ol class="files-list">' . $rows . '</ol>';
    }

    private static function styles(): string
    {
        return <<<'CSS'
.files { gap: 6px; cursor: pointer; }
.files[aria-expanded="true"] { background: var(--hover); }
.files-panel { pointer-events: auto; display: flex; flex-direction: column; width: min(760px, 100%); max-height: min(560px, calc(100dvh - 96px)); overflow: hidden; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); color: var(--foreground); box-shadow: var(--shadow); }
.files-panel[hidden], .files-scroll[hidden] { display: none; }
.files-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); }
.files-header h2 { margin: 0; font-size: 14px; font-weight: 650; }
.files-header button { border: 0; border-radius: 6px; background: transparent; color: var(--muted); font: inherit; font-size: 22px; cursor: pointer; }
.files-header button:hover { background: var(--hover); }
.files-tabs { display: flex; flex-shrink: 0; gap: 4px; padding: 8px 12px; border-bottom: 1px solid var(--border); }
.files-tabs button { padding: 6px 10px; border: 0; border-radius: 6px; background: transparent; color: var(--muted); font: inherit; cursor: pointer; }
.files-tabs button[aria-pressed="true"] { background: var(--hover); color: var(--foreground); }
.files-note { margin: 0; padding: 12px 0; font-size: 12px; color: var(--muted); }
.files-scroll { overflow: auto; overscroll-behavior: contain; min-height: 0; padding: 0 16px 12px; }
.files-group { border-top: 1px solid var(--border); }
.files-group summary { padding: 10px 0; cursor: pointer; overflow-wrap: anywhere; }
.files-timing { display: block; margin-top: 4px; color: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums; }
.files-list { margin: 0 0 12px; padding-left: 30px; }
.files-list li { padding: 4px 6px; overflow-wrap: anywhere; }
.files-list li:nth-child(odd) { background: var(--row-stripe); }
.files-list code { font: 12px/1.5 ui-monospace, monospace; }
.files-empty { color: var(--muted); font-size: 12px; }
.files-panel :focus-visible { outline: 2px solid var(--foreground); outline-offset: -2px; }
.files-events { list-style: none; margin: 0; padding: 0; }
.files-event { padding: 12px 0; border-top: 1px solid var(--border); }
.files-event-title { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 12px; overflow-wrap: anywhere; white-space: pre-wrap; }
.files-longest { font-size: 11px; color: var(--muted); }
.files-source { display: block; margin-top: 4px; font: 11px/1.5 ui-monospace, monospace; color: var(--muted); overflow-wrap: anywhere; }
.files-track { position: relative; height: 8px; margin: 10px 0 6px; border-radius: 4px; background: var(--hover); }
.files-track span { position: absolute; top: 0; height: 100%; min-width: 2px; max-width: 100%; border-radius: 4px; background: #3b82f6; }
.files-times { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; font-size: 11px; font-variant-numeric: tabular-nums; color: var(--muted); }
.files-slow .files-track span { background: #dc2626; }
.files-slow .files-times b { color: #dc2626; }
:host([data-theme="dark"]) .files-slow .files-times b { color: #f87171; }
CSS;
    }

    private static function script(): string
    {
        return <<<'JS'
    (() => {
        const button = root.querySelector('[data-files-open]');
        const panel = root.querySelector('#dle-toolbar-files-panel');
        const closedLabel = button.getAttribute('aria-label');
        const popup = createPopup({ trigger: button, panel, closeDuration: 0,
            onOpen: () => button.setAttribute('aria-label', closedLabel.replace('Показать панель', 'Закрыть панель')),
            onClose: () => button.setAttribute('aria-label', closedLabel),
        });
        button.addEventListener('click', () => popup.isOpen() ? popup.close({ immediate: true }) : popup.open());
        panel.querySelector('[data-files-close]').addEventListener('click', () => popup.close({ restoreFocus: true, immediate: true }));
        const timeline = panel.querySelector('#dle-toolbar-files-timeline');
        const list = panel.querySelector('#dle-toolbar-files-list');
        const views = panel.querySelectorAll('[data-files-view]');
        views.forEach((view) => view.addEventListener('click', () => {
            views.forEach((item) => item.setAttribute('aria-pressed', item === view ? 'true' : 'false'));
            timeline.hidden = view.dataset.filesView !== 'timeline';
            list.hidden = view.dataset.filesView !== 'list';
        }));
        root.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && popup.isOpen()) {
                event.preventDefault();
                popup.close({ restoreFocus: true, immediate: true });
            }
        });
        document.addEventListener('click', (event) => {
            if (popup.isOpen() && !event.composedPath().includes(root.host)) popup.close({ immediate: true });
        }, true);
    })();
JS;
    }
}
