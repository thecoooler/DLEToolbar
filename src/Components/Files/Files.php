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
                . '<p class="files-note">Хронология контрольных точек и задержек между ними. Нажмите на количество файлов, чтобы посмотреть подключённые файлы.</p>'
                . $this->timeline($entries) . '</div></section>',
            'styles' => self::styles(),
            'script' => self::script(),
        ];
    }

    /** @param list<array{message: string, file: string, line: int, cached: bool, time: float, gap: float, kind: string, files: list<string>}> $entries */
    private function timeline(array $entries): string
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
            $isSlow = $gap >= $this->slowThreshold;

            $sourceHtml = '';
            if ($entry['file'] !== '') {
                $badge = $entry['cached']
                    ? '<span class="files-badge files-cached">из кэша</span>'
                    : '<span class="files-badge files-uncached">не из кэша</span>';
                $sourceHtml = '<div class="files-source"><span>' . self::escape($entry['file'] . ($entry['line'] > 0 ? ':' . $entry['line'] : '')) . '</span> ' . $badge . '</div>';
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

            $gapLabel = self::milliseconds($gap);
            $timeLabel = self::milliseconds($entry['time']);

            $rows .= '<li class="files-event' . ($isSlow ? ' files-slow' : '') . '">'
                . '<div class="files-rail-dot" aria-hidden="true"></div>'
                . '<div class="files-event-header">'
                . '<div class="files-event-title"><strong>' . self::escape($entry['message']) . '</strong>'
                . ($index === $longest ? '<span class="files-longest">Самый долгий интервал</span>' : '') . '</div>'
                . '<div class="files-times" title="Время от начала запроса и интервал">'
                . '<span class="files-time-start">от начала: ' . $timeLabel . '</span>'
                . '<b class="files-time-gap">+' . $gapLabel . '</b>'
                . '</div>'
                . '</div>'
                . $sourceHtml
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
.files-note { margin: 0; padding: 12px 0 10px; font-size: 12px; color: var(--muted); }
.files-scroll { overflow: auto; overscroll-behavior: contain; min-height: 0; padding: 0 16px 14px; }
.files-panel :focus-visible { outline: 2px solid var(--foreground); outline-offset: -2px; }
.files-events { list-style: none; margin: 0; padding: 0 0 0 20px; position: relative; }
.files-events::before { content: ""; position: absolute; left: 5px; top: 8px; bottom: 12px; width: 2px; background: var(--border); }
.files-event { position: relative; padding: 12px 0 16px 16px; border-bottom: 1px solid rgba(128, 128, 128, 0.08); }
.files-event:last-child { border-bottom: none; }
.files-rail-dot { position: absolute; left: -19px; top: 16px; width: 10px; height: 10px; border-radius: 50%; background: var(--surface); border: 2px solid #3b82f6; z-index: 1; }
.files-slow .files-rail-dot { border-color: #dc2626; background: #dc2626; }
:host([data-theme="dark"]) .files-slow .files-rail-dot { border-color: #f87171; background: #f87171; }
.files-event-header { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 12px; }
.files-event-title { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 10px; overflow-wrap: anywhere; font-size: 13px; }
.files-longest { font-size: 11px; color: var(--muted); font-weight: normal; }
.files-times { display: inline-flex; align-items: baseline; gap: 8px; font-size: 11px; font-variant-numeric: tabular-nums; color: var(--muted); }
.files-time-start { opacity: 0.8; }
.files-time-gap { color: var(--foreground); font-weight: 600; padding: 1px 6px; border-radius: 4px; background: var(--hover); }
.files-slow .files-time-gap { color: #dc2626; background: rgba(220, 38, 38, 0.12); }
:host([data-theme="dark"]) .files-slow .files-time-gap { color: #f87171; background: rgba(248, 113, 113, 0.16); }
.files-source { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 4px; font: 11px/1.5 ui-monospace, monospace; color: var(--muted); overflow-wrap: anywhere; }
.files-badge { display: inline-flex; align-items: center; flex-shrink: 0; font: 600 10px/1 system-ui, -apple-system, sans-serif; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.02em; }
.files-cached { background: rgba(245, 158, 11, 0.15); color: #b45309; border: 1px solid rgba(245, 158, 11, 0.3); }
:host([data-theme="dark"]) .files-cached { background: rgba(245, 158, 11, 0.22); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.35); }
.files-uncached { background: var(--hover); color: var(--muted); border: 1px solid var(--border); }
.files-count { display: inline-block; margin-top: 6px; font-size: 11px; color: var(--muted); }
.files-details { margin-top: 8px; }
.files-toggle { cursor: pointer; font-size: 11px; color: var(--muted); user-select: none; }
.files-toggle:hover { color: var(--foreground); }
.files-list { margin: 6px 0 0; padding: 6px 8px; list-style: none; border-radius: 6px; background: var(--hover); border: 1px solid var(--border); }
.files-list li { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 4px 6px; overflow-wrap: anywhere; border-bottom: 1px solid rgba(128, 128, 128, 0.12); }
.files-list li:last-child { border-bottom: none; }
.files-list code { font: 11px/1.5 ui-monospace, monospace; flex: 1; min-width: 0; }
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
