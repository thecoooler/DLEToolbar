<?php

namespace iCooLER\DLEToolbar\Toolbar;

/** Administrator-only access to the existing settings screen. */
final class SettingsPanel
{
    /** @return array{control: string, panel: string, styles: string, script: string} */
    public static function render(): array
    {
        global $config;
        /** @var array{http_home_url: string, admin_path: string} $config */
        $url = htmlspecialchars($config['http_home_url'] . $config['admin_path'] . '?mod=dletoolbar&dlt_embed=1', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return [
            'control' => '<button class="control" data-settings-open type="button" aria-label="Настройки панели" aria-expanded="false" aria-controls="dle-toolbar-settings-panel">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8a4 4 0 1 0 0 8a4 4 0 0 0 0 -8"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06l-1.41 2.44l-.09-.03a1.65 1.65 0 0 0 -1.75.62l-.05.09h-2.82l-.03-.09a1.65 1.65 0 0 0 -1.64-1.11a1.65 1.65 0 0 0 -1.64 1.11l-.03.09H7.51l-.05-.09a1.65 1.65 0 0 0 -1.75-.62l-.09.03l-1.41-2.44l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0 -1.51-1l-.09-.01v-2.82l.09-.01A1.65 1.65 0 0 0 4.6 10a1.65 1.65 0 0 0 -.33-1.82l-.06-.06l1.41-2.44l.09.03a1.65 1.65 0 0 0 1.75-.62l.05-.09h2.82l.03.09A1.65 1.65 0 0 0 12 6.2a1.65 1.65 0 0 0 1.64-1.11l.03-.09h2.82l.05.09a1.65 1.65 0 0 0 1.75.62l.09-.03l1.41 2.44l-.06.06A1.65 1.65 0 0 0 19.4 10a1.65 1.65 0 0 0 1.51 1l.09.01v2.82l-.09.01A1.65 1.65 0 0 0 19.4 15z"/></svg></button>',
            'panel' => '<section class="settings-panel" data-settings-panel id="dle-toolbar-settings-panel" aria-labelledby="dle-toolbar-settings-title" hidden>'
                . '<header class="settings-header"><h2 id="dle-toolbar-settings-title">Настройки панели</h2>'
                . '<button class="settings-close" data-settings-close type="button" aria-label="Закрыть настройки">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></button></header>'
                . '<iframe data-settings-frame data-src="' . $url . '" title="Настройки DLEToolbar"></iframe></section>',
            'styles' => <<<'CSS'
            .settings-panel { display: flex; flex-direction: column; width: min(900px, 100%); height: min(700px, calc(100dvh - 96px)); overflow: hidden; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); color: var(--foreground); box-shadow: var(--shadow); pointer-events: auto; }
            .settings-panel[hidden] { display: none; }
            .settings-header { display: flex; align-items: center; justify-content: space-between; flex: 0 0 auto; padding: 12px 16px; border-bottom: 1px solid var(--border); }
            .settings-header h2 { margin: 0; font: 600 15px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
            .settings-close { display: grid; place-items: center; width: 32px; height: 32px; padding: 0; border: 0; border-radius: 8px; color: var(--muted); background: transparent; cursor: pointer; }
            .settings-close:hover { color: var(--foreground); background: var(--hover); }
            .settings-close:focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; }
            .settings-panel iframe { display: block; flex: 1 1 auto; width: 100%; min-height: 0; border: 0; background: var(--surface); color-scheme: inherit; }
CSS,
            'script' => <<<'JS'
    (() => {
        const trigger = root.querySelector('[data-settings-open]');
        const panel = root.querySelector('[data-settings-panel]');
        const frame = panel.querySelector('[data-settings-frame]');
        const popup = createPopup({ trigger, panel, closeDuration: 0 });
        trigger.addEventListener('click', () => {
            if (popup.isOpen()) popup.close();
            else {
                if (!frame.hasAttribute('src')) frame.src = frame.dataset.src;
                popup.open();
            }
        });
        panel.querySelector('[data-settings-close]').addEventListener('click', () => popup.close({ restoreFocus: true }));
        panel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') popup.close({ restoreFocus: true });
        });
        window.addEventListener('message', (event) => {
            if (event.origin !== window.location.origin || event.source !== frame.contentWindow) return;
            if (event.data === 'dletoolbar-settings-saved') window.location.replace(window.location.href.split('#')[0]);
            if (event.data === 'dletoolbar-settings-close' && popup.isOpen()) popup.close({ restoreFocus: true });
        });
    })();
JS,
        ];
    }
}
