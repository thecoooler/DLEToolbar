<?php

namespace iCooLER\DLEToolbar\Components\Files;

use iCooLER\DLEToolbar\ComponentFramework\Component;
use iCooLER\DLEToolbar\ComponentFramework\RequestComponent;

final class Files implements Component, RequestComponent
{
    public static function definition(): array
    {
        return ['label' => 'Подключённые файлы', 'description' => 'Список подключённых файлов и время этапов DLE после проверки доступа. Длительность отдельного файла доступна только для явно измеренного подключения engine.php.'];
    }

    public function initialize(): void
    {
        FileTimeline::start();
    }

    public function render(): array
    {
        $snapshot = FileTimeline::snapshot();
        $count = count($snapshot['before']);
        $rows = self::fileRows($snapshot['before']);
        $groups = '<details class="files-group"><summary>До начала замеров · ' . $count . '</summary>' . $rows . '</details>';
        foreach ($snapshot['stages'] as $stage) {
            $count += count($stage['files']);
            $groups .= '<details class="files-group" open><summary><span>' . self::escape($stage['label']) . '</span>'
                . '<span class="files-timing">+' . self::milliseconds($stage['start']) . ' · '
                . self::milliseconds($stage['duration']) . ' · файлов: ' . count($stage['files']) . '</span></summary>'
                . self::fileRows($stage['files']) . '</details>';
        }
        $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5"/></svg>';
        return [
            'control' => '<button class="control metric files" data-component="files" data-files-open type="button"'
                . ' aria-expanded="false" aria-controls="dle-toolbar-files-panel" aria-label="Подключённые файлы: ' . $count . '. Показать список">'
                . $icon . '<span class="metric-value">' . $count . '</span></button>',
            'panel' => '<section class="files-panel" id="dle-toolbar-files-panel" aria-labelledby="dle-toolbar-files-title" hidden>'
                . '<header class="files-header"><h2 id="dle-toolbar-files-title">Подключённые файлы · ' . $count . '</h2>'
                . '<button type="button" data-files-close aria-label="Закрыть список файлов">×</button></header>'
                . '<p class="files-note">Время измерено для этапа целиком, включая SQL и вложенные подключения. '
                . 'У engine/engine.php измерено подключение целиком. +мс — начало этапа от запуска сбора после проверки доступа. '
                . 'Повторные подключения одного файла в списке не дублируются. Снимок сделан до вывода панели.</p>'
                . '<div class="files-scroll">' . $groups . '</div></section>',
            'styles' => self::styles(),
            'script' => self::script(),
        ];
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
        $prefix = ROOT_DIR . '/';
        foreach ($files as $file) {
            $path = strpos($file, $prefix) === 0 ? substr($file, strlen($prefix)) : $file;
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
.files-panel[hidden] { display: none; }
.files-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); }
.files-header h2 { margin: 0; font-size: 14px; font-weight: 650; }
.files-header button { border: 0; border-radius: 6px; background: transparent; color: var(--muted); font: inherit; font-size: 22px; cursor: pointer; }
.files-header button:hover { background: var(--hover); }
.files-note { margin: 0; padding: 12px 16px; font-size: 12px; color: var(--muted); }
.files-scroll { overflow: auto; overscroll-behavior: contain; min-height: 0; padding: 0 16px 12px; }
.files-group { border-top: 1px solid var(--border); }
.files-group summary { padding: 10px 0; cursor: pointer; overflow-wrap: anywhere; }
.files-timing { display: block; margin-top: 4px; color: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums; }
.files-list { margin: 0 0 12px; padding-left: 30px; }
.files-list li { padding: 4px 6px; overflow-wrap: anywhere; }
.files-list li:nth-child(odd) { background: var(--row-stripe); }
.files-list code { font: 12px/1.5 ui-monospace, monospace; }
.files-empty { color: var(--muted); font-size: 12px; }
.files-panel :focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; }
CSS;
    }

    private static function script(): string
    {
        return <<<'JS'
    (() => {
        const button = root.querySelector('[data-files-open]');
        const panel = root.querySelector('#dle-toolbar-files-panel');
        const popup = createPopup({ trigger: button, panel, closeDuration: 0 });
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
