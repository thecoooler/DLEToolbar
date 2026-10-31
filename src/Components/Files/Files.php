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
            'Время выполнения файлов и интервалы между ручными отметками не меньше порога выделяются цветом.',
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
                . '<div class="files-scroll-area"><div class="files-scroll" id="dle-toolbar-files-timeline" role="region" tabindex="0" aria-label="Лог и файлы">'
                . $this->timeline($entries) . '</div><div class="files-scrollbar" data-files-scrollbar aria-hidden="true" hidden><div class="files-scroll-thumb"></div></div></div></section>',
            'styles' => self::styles(),
            'script' => self::script(),
        ];
    }

    /** @param list<array{message: string, file: string, line: int, cached: bool, time: float, gap: float, kind: string, duration: float|null, files: list<string>}> $entries */
    private function timeline(array $entries): string
    {
        if ($entries === []) {
            return '<p class="files-empty">Отметок нет. Для ручной записи вызовите DebugLog::add() в нужной точке CMS.</p>';
        }

        $pending = $starts = $paired = [];
        foreach ($entries as $index => $entry) {
            if ($entry['kind'] === 'before') {
                $pending[$entry['file']][] = $index;
            } elseif ($entry['kind'] === 'after' && !empty($pending[$entry['file']])) {
                $before = array_pop($pending[$entry['file']]);
                $starts[$index] = $entries[$before]['time'];
                $paired[$before] = true;
                $entries[$index]['files'] = array_merge($entries[$before]['files'], $entry['files']);
            }
        }


        $rows = '';
        foreach ($entries as $index => $entry) {
            if (isset($paired[$index])) {
                continue;
            }
            $gap = $entry['gap'];
            $isSlow = ($entry['duration'] ?? $gap) >= $this->slowThreshold;

            $sourceHtml = '';
            if ($entry['file'] !== '') {
                $badge = $entry['cached']
                    ? '<span class="files-badge files-cached">из кэша</span>'
                    : '<span class="files-badge files-uncached">не из кэша</span>';
                $sourceHtml = '<div class="files-source">' . $badge . '</div>';
            }

            $fileCount = count($entry['files']);
            if ($fileCount === 0) {
                $filesHtml = '';
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

            $timeLabel = self::milliseconds($entry['time']);

            $times = isset($starts[$index])
                ? '<span class="files-time-before" title="До подключения"><span class="files-boundary">До</span> ' . self::milliseconds($starts[$index]) . '</span>'
                    . '<span class="files-time-after" title="После подключения"><span class="files-boundary">После</span> ' . $timeLabel . '</span>'
                : '<span class="files-time-start" aria-label="От начала запроса: ' . $timeLabel . '">' . $timeLabel . '</span>';
            $rows .= '<li class="files-event' . ($isSlow ? ' files-slow' : '') . '">'
                . '<div class="files-times">' . $times
                . '</div><div class="files-rail-dot" aria-hidden="true"></div>'
                . '<div class="files-event-body"><div class="files-event-title"><strong>' . self::escape($entry['message']) . '</strong></div>'
                . ($entry['kind'] === 'before' ? '<span class="files-phase">До подключения</span>' : '')
                . ($entry['duration'] !== null ? '<span class="files-duration">Выполнение · ' . self::milliseconds($entry['duration']) . '</span>' : '')
                . $sourceHtml
                . $filesHtml
                . '</div></li>';
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
.files-scroll-area { position: relative; min-height: 0; display: flex; flex-direction: column; }
.files-scroll { scrollbar-width: none; overflow: auto; overscroll-behavior: contain; min-height: 0; padding: 8px 16px 16px; }
.files-scroll::-webkit-scrollbar { display: none; }
.files-phase { display: inline-block; margin: 4px 8px 0 0; color: var(--muted); font-size: 11px; }
.files-duration { display: inline-block; margin-top: 4px; font-size: 11px; font-variant-numeric: tabular-nums; color: var(--foreground); }
.files-panel :focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; }
.files-events { --time-column: 116px; --rail-gap: 28px; list-style: none; margin: 0; padding: 0; position: relative; }
.files-events::before { content: ""; position: absolute; left: calc(var(--time-column) + var(--rail-gap) / 2); top: 20px; bottom: 24px; width: 1px; background: var(--border); }
.files-event { position: relative; display: grid; grid-template-columns: var(--time-column) minmax(0, 1fr); column-gap: var(--rail-gap); padding: 14px 0; }
.files-event-body { min-width: 0; }
.files-rail-dot { position: absolute; left: calc(var(--time-column) + var(--rail-gap) / 2 - 3px); top: 20px; width: 7px; height: 7px; border-radius: 50%; background: var(--muted); box-shadow: 0 0 0 4px var(--surface); }
.files-slow .files-rail-dot { background: #dc2626; }
:host([data-theme="dark"]) .files-slow .files-rail-dot { background: #f87171; }
.files-event-title { overflow-wrap: anywhere; font-size: 13px; line-height: 20px; }
.files-event-title strong { font-weight: 600; }
.files-times { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; font-size: 11px; line-height: 20px; font-variant-numeric: tabular-nums; white-space: nowrap; }
.files-boundary { color: var(--muted); font-size: 10px; margin-right: 3px; }
.files-time-before, .files-time-after { color: var(--foreground); }
.files-time-start { color: var(--foreground); }
@media (max-width: 480px) {
    .files-scroll { padding-inline: 12px; }
    .files-events { --time-column: 96px; --rail-gap: 22px; }
    .files-times { font-size: 10px; }
}
.files-source { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 4px; font: 11px/1.5 ui-monospace, monospace; color: var(--muted); overflow-wrap: anywhere; }
.files-badge { display: inline-flex; align-items: center; flex-shrink: 0; font: 600 10px/1 system-ui, -apple-system, sans-serif; padding: 2px 6px; border-radius: 3px; }
.files-cached { background: rgba(245, 158, 11, 0.15); color: #b45309; border: 1px solid rgba(245, 158, 11, 0.3); }
:host([data-theme="dark"]) .files-cached { background: rgba(245, 158, 11, 0.22); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.35); }
.files-uncached { background: transparent; color: var(--muted); border: 1px solid var(--border); }
.files-count { display: inline-block; margin-top: 6px; font-size: 11px; color: var(--muted); }
.files-details { margin-top: 8px; }
.files-toggle { cursor: pointer; font-size: 11px; color: var(--muted); user-select: none; }
.files-toggle:hover { color: var(--foreground); }
.files-list { margin: 6px 0 0; padding: 6px 8px; list-style: none; border-radius: 6px; background: var(--hover); border: 1px solid var(--border); }
.files-list li { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 4px 6px; overflow-wrap: anywhere; border-bottom: 1px solid rgba(128, 128, 128, 0.12); }
.files-list li:last-child { border-bottom: none; }
.files-list code { font: 11px/1.5 ui-monospace, monospace; flex: 1; min-width: 0; }
.files-empty { color: var(--muted); font-size: 12px; }
            .files-scrollbar {
                position: absolute;
                top: 3px;
                right: 0;
                bottom: 3px;
                width: 10px;
                cursor: pointer;
                touch-action: none;
                user-select: none;
            }
            .files-scrollbar[hidden] { display: none; }
            .files-scroll-thumb {
                position: absolute;
                top: 0;
                right: 2px;
                width: 4px;
                border-radius: 4px;
                background: var(--muted);
                opacity: .4;
            }
            .files-scrollbar:hover .files-scroll-thumb,
            .files-scrollbar[data-dragging] .files-scroll-thumb { width: 6px; opacity: .8; }
CSS;
    }

    private static function script(): string
    {
        return <<<'JS'
    (() => {
        const button = root.querySelector('[data-files-open]');
        const panel = root.querySelector('#dle-toolbar-files-panel');
        const closedLabel = button.getAttribute('aria-label');
        const fileTimeline = panel.querySelector('.files-scroll');
        const scrollbar = panel.querySelector('[data-files-scrollbar]');
        const scrollThumb = scrollbar.firstElementChild;
        const updateScrollbar = () => {
            const maxScroll = fileTimeline.scrollHeight - fileTimeline.clientHeight;
            scrollbar.hidden = fileTimeline.clientHeight === 0 || maxScroll <= 0;
            if (scrollbar.hidden) return;
            const trackHeight = scrollbar.clientHeight;
            const thumbHeight = Math.min(trackHeight, Math.max(24, trackHeight * fileTimeline.clientHeight / fileTimeline.scrollHeight));
            scrollThumb.style.height = thumbHeight + 'px';
            scrollThumb.style.transform = 'translateY(' + (trackHeight - thumbHeight) * fileTimeline.scrollTop / maxScroll + 'px)';
        };
        fileTimeline.addEventListener('scroll', updateScrollbar, { passive: true });
        scrollbar.addEventListener('wheel', (event) => {
            event.preventDefault();
            const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? fileTimeline.clientHeight : 1;
            fileTimeline.scrollTop += event.deltaY * unit;
        }, { passive: false });
        const scrollResizeObserver = new ResizeObserver(updateScrollbar);
        scrollResizeObserver.observe(fileTimeline);
        [...fileTimeline.children].forEach((entry) => scrollResizeObserver.observe(entry));
        let dragOffset = 0;
        const scrollToPointer = (event) => {
            const travel = scrollbar.clientHeight - scrollThumb.offsetHeight;
            if (travel <= 0) return;
            fileTimeline.scrollTop = (event.clientY - scrollbar.getBoundingClientRect().top - dragOffset)
                / travel * (fileTimeline.scrollHeight - fileTimeline.clientHeight);
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
