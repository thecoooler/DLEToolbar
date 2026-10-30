<?php

namespace iCooLER\DLEToolbar\Components\Files;

use iCooLER\DLEToolbar\ComponentFramework\ConfigurableComponent;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;
use iCooLER\DLEToolbar\ComponentFramework\SourcePath;
use iCooLER\DLEToolbar\Components\Debug\DebugLog;

/** One diagnostics panel: chronological marks and the files loaded during each DLE milestone. */
final class Files implements ConfigurableComponent
{
    private float $slowThreshold;

    public static function definition(): array
    {
        return ['label' => 'Лог и файлы', 'description' => 'Таймлайн ручных отметок и этапов DLE, файл и строка вызова, список и количество подключённых файлов с признаком кэша.'];
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
        $entries = $snapshot['entries'];
        $count = $snapshot['totalFiles'];
        $entryCount = count($entries);
        $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h16M8 3v4M16 10v4M10 17v4"/></svg>';

        return [
            'control' => '<button class="control metric files" data-component="files" data-files-open type="button"'
                . ' aria-expanded="false" aria-controls="dle-toolbar-files-panel" aria-label="Лог и файлы: ' . $entryCount
                . ' отметок, ' . $count . ' файлов. Показать панель">'
                . $icon . '<span class="metric-value">' . $count . '</span></button>',
            'panel' => '<section class="files-panel" id="dle-toolbar-files-panel" aria-labelledby="dle-toolbar-files-title" hidden>'
                . '<header class="files-header"><div><h2 id="dle-toolbar-files-title">Лог и файлы</h2>'
                . '<span class="files-subtitle">Отметок: ' . $entryCount . ' · Файлов: ' . $count . '</span></div>'
                . '<button type="button" data-files-close aria-label="Закрыть панель диагностики">×</button></header>'
                . '<div class="files-scroll" id="dle-toolbar-files-timeline" role="region" tabindex="0" aria-label="Лог и файлы">'
                . '<p class="files-note">Время от начала запроса. Полоса показывает интервал до отметки. Нажмите на количество файлов, чтобы посмотреть подключённые файлы.</p>'
                . $this->timeline($entries, $snapshot['end']) . '</div></section>',
            'styles' => self::styles(),
            'script' => self::script(),
        ];
    }

    /** @param list<array{message: string, file: string, line: int, cached: bool, time: float, gap: float, kind: string, files: list<string>}> $entries */
    private function timeline(array $entries, float $end): string
    {
        if ($entries === []) {
            return '<p class="files-empty">Отметок нет. Для ручной записи вызовите DebugLog::add() в нужной точке CMS.</p>';
        }

        $gaps = array_column($entries, 'gap');
        $maxGap = max($gaps);
        $longest = ($maxGap > 0.0 && count($entries) > 1) ? array_search($maxGap, $gaps, true) : false;

        $rows = '';
        foreach ($entries as $index => $entry) {
            $gap = $entry['gap'];
            $left = $end > 0 ? ($entry['time'] - $gap) / $end * 100 : 0;
            $width = $end > 0 ? $gap / $end * 100 : 0;

            $sourceHtml = '';
            if ($entry['file'] !== '') {
                $badge = $entry['cached']
                    ? '<span class="files-badge files-cached">из кэша</span>'
                    : '<span class="files-badge files-uncached">не из кэша</span>';
                $sourceHtml = '<span class="files-source">' . self::escape($entry['file'] . ($entry['line'] > 0 ? ':' . $entry['line'] : '')) . ' ' . $badge . '</span>';
            }

            $fileCount = count($entry['files']);
            if ($fileCount === 0) {
                $filesHtml = '<span class="files-count files-count-zero">Файлов: 0</span>';
            } else {
                $fileItems = '';
                foreach ($entry['files'] as $file) {
                    $resolved = SourcePath::resolve($file);
                    $fileBadge = $resolved['cached']
                        ? '<span class="files-badge files-cached">из кэша</span>'
                        : '<span class="files-badge files-uncached">не из кэша</span>';
                    $fileItems .= '<li><code>' . self::escape($resolved['path']) . '</code> ' . $fileBadge . '</li>';
                }
                $filesHtml = '<details class="files-details">'
                    . '<summary class="files-toggle"><span>' . self::escape('Файлов: ' . $fileCount) . '</span></summary>'
                    . '<ol class="files-list">' . $fileItems . '</ol>'
                    . '</details>';
            }

            $rows .= '<li class="files-event' . ($gap >= $this->slowThreshold ? ' files-slow' : '') . '">'
                . '<div class="files-event-title"><strong>' . self::escape($entry['message']) . '</strong>'
                . ($index === $longest ? '<span class="files-longest">Самый долгий интервал</span>' : '') . '</div>'
                . $sourceHtml
                . '<div class="files-track" aria-hidden="true"><span style="left:' . number_format($left, 6, '.', '')
                . '%;width:' . number_format($width, 6, '.', '') . '%"></span></div>'
                . '<div class="files-times"><span>От начала: ' . self::milliseconds($entry['time']) . '</span>'
                . '<b>Интервал: +' . self::milliseconds($gap) . '</b></div>'
                . $filesHtml
                . '</li>';
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

    private static function styles(): string
    {
        return <<<'CSS'
.files { gap: 6px; cursor: pointer; }
.files[aria-expanded="true"] { background: var(--hover); }
.files-panel { pointer-events: auto; display: flex; flex-direction: column; width: min(760px, 100%); max-height: min(560px, calc(100dvh - 96px)); overflow: hidden; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); color: var(--foreground); box-shadow: var(--shadow); }
.files-panel[hidden], .files-scroll[hidden] { display: none; }
.files-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border); }
.files-header h2 { margin: 0; font-size: 14px; font-weight: 650; }
.files-subtitle { display: block; margin-top: 2px; font-size: 11px; color: var(--muted); }
.files-header button { border: 0; border-radius: 6px; background: transparent; color: var(--muted); font: inherit; font-size: 22px; cursor: pointer; line-height: 1; padding: 2px 6px; }
.files-header button:hover { background: var(--hover); }
.files-note { margin: 0; padding: 12px 0 8px; font-size: 12px; color: var(--muted); }
.files-scroll { overflow: auto; overscroll-behavior: contain; min-height: 0; padding: 0 16px 12px; }
.files-panel :focus-visible { outline: 2px solid var(--foreground); outline-offset: -2px; }
.files-events { list-style: none; margin: 0; padding: 0; }
.files-event { padding: 12px 0; border-top: 1px solid var(--border); }
.files-event:first-child { border-top: 0; }
.files-event-title { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 12px; overflow-wrap: anywhere; white-space: pre-wrap; }
.files-longest { font-size: 11px; color: var(--muted); }
.files-source { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 4px; font: 11px/1.5 ui-monospace, monospace; color: var(--muted); overflow-wrap: anywhere; }
.files-badge { display: inline-flex; align-items: center; flex-shrink: 0; font: 600 10px/1 system-ui, -apple-system, sans-serif; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.02em; }
.files-cached { background: rgba(245, 158, 11, 0.15); color: #b45309; border: 1px solid rgba(245, 158, 11, 0.3); }
:host([data-theme="dark"]) .files-cached { background: rgba(245, 158, 11, 0.22); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.35); }
.files-uncached { background: var(--hover); color: var(--muted); border: 1px solid var(--border); }
.files-track { position: relative; height: 8px; margin: 10px 0 6px; border-radius: 4px; background: var(--hover); }
.files-track span { position: absolute; top: 0; height: 100%; min-width: 2px; max-width: 100%; border-radius: 4px; background: #3b82f6; }
.files-times { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; font-size: 11px; font-variant-numeric: tabular-nums; color: var(--muted); }
.files-slow .files-track span { background: #dc2626; }
.files-slow .files-times b { color: #dc2626; }
:host([data-theme="dark"]) .files-slow .files-times b { color: #f87171; }
.files-count { display: inline-block; margin-top: 6px; font-size: 11px; color: var(--muted); }
.files-details { margin-top: 8px; }
.files-toggle { cursor: pointer; font-size: 11px; color: var(--muted); user-select: none; }
.files-toggle:hover { color: var(--foreground); }
.files-list { margin: 6px 0 0; padding-left: 18px; }
.files-list li { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 4px 6px; overflow-wrap: anywhere; }
.files-list li:nth-child(odd) { background: var(--row-stripe); }
.files-list code { font: 12px/1.5 ui-monospace, monospace; flex: 1; min-width: 0; }
.files-empty { color: var(--muted); font-size: 12px; }
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
