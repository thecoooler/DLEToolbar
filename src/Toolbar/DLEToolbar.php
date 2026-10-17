<?php

namespace iCooLER\DLEToolbar\Toolbar;

use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\ComponentFramework\PopupLifecycle;
use iCooLER\DLEToolbar\Components\Queries\TracingDb;

class DLEToolbar {
    private ComponentRegistry $components;
    private string $marker;

    public function __construct() {
        $this->components = new ComponentRegistry();
        $this->marker = '<!-- DLEToolbar ' . bin2hex(random_bytes(16)) . ' -->';
    }

    /** Per-request placeholder: page content cannot predict it and place the toolbar elsewhere. */
    public function marker(): string {
        return $this->marker;
    }

    public function isHasAccess(): bool {
        global $member_id;
        /** @var array{user_group: int|string} $member_id */
        $group = (int) $member_id['user_group'];

        // Users and guests can never be granted access, so their pages skip the settings query.
        return isset(ToolbarPreferences::groups()[$group])
            && ToolbarPreferences::load()->allowsGroup($group);
    }

    public function init(): void {
        if (!$this->isHasAccess()) return;

        global $config;
        /** @var array{version_id: string} $config */
        if (version_compare($config['version_id'], '14.0', '>=')
            && in_array('queries', ToolbarPreferences::load()->visibleItems(), true)) {
            TracingDb::install();
        }

        $this->components->initialize();
    }

    public function render(): string {
        $rendered = $this->components->render(ToolbarPreferences::load());
        if ($rendered['controls'] === '' && $rendered['hints'] === '') return '';

        // Native CSS inspired by shadcn/ui; inline icons from Tabler Icons (MIT).
        return strtr(<<<'HTML'
<div data-dle-toolbar lang="ru" hidden>
    <template>
        <!--
        Tabler Icons, MIT License. Copyright (c) 2020-2026 Paweł Kuna.
        Permission is hereby granted, free of charge, to any person obtaining a copy
        of this software and associated documentation files (the "Software"), to deal
        in the Software without restriction, including without limitation the rights
        to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
        copies of the Software, and to permit persons to whom the Software is
        furnished to do so, subject to the following conditions:
        The above copyright notice and this permission notice shall be included in all
        copies or substantial portions of the Software.
        THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
        IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
        FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
        AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
        LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
        OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
        SOFTWARE.
        -->
        <style>
            :host {
                all: initial !important;
                position: fixed !important;
                z-index: 2147483000 !important;
                inset: auto 12px max(20px, env(safe-area-inset-bottom)) !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                justify-content: flex-start !important;
                gap: 8px !important;
                max-height: calc(100dvh - max(32px, env(safe-area-inset-bottom))) !important;
                pointer-events: none !important;
                direction: ltr !important;
                font: 500 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
                -webkit-font-smoothing: antialiased !important;
                -webkit-text-size-adjust: 100% !important;
                text-size-adjust: 100% !important;
                --surface: #fff;
                --foreground: #27272a;
                --muted: #52525b;
                --border: #e4e4e7;
                --hover: #f4f4f5;
                --row-stripe: #fafafa;
                --shadow: 0 8px 30px rgb(24 24 27 / 10%), 0 2px 6px rgb(24 24 27 / 5%);
                color-scheme: light !important;
            }
            :host([data-theme="dark"]) {
                --surface: #18181b;
                --foreground: #fafafa;
                --muted: #a1a1aa;
                --border: #3f3f46;
                --hover: #27272a;
                --row-stripe: #1c1c20;
                --shadow: 0 8px 30px rgb(0 0 0 / 25%), 0 2px 6px rgb(0 0 0 / 15%);
                color-scheme: dark !important;
            }
            *, *::before, *::after { box-sizing: border-box; }
            :host > * {
                font: 500 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                letter-spacing: normal;
                word-spacing: normal;
                text-transform: none;
                text-indent: 0;
                text-shadow: none;
                -webkit-text-size-adjust: 100%;
                text-size-adjust: 100%;
            }
            .dock[hidden] { display: none; }
            .dock {
                pointer-events: auto;
                border: 1px solid var(--border);
                border-radius: 14px;
                background: var(--surface);
                color: var(--foreground);
                box-shadow: var(--shadow);
                transform-origin: left bottom;
                transition: background-color 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
            }
            .dock {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 4px;
                padding: 6px;
                max-width: 100%;
            }
            :host([data-intro="true"]) .dock {
                animation: toolbar-in 260ms cubic-bezier(.2, .8, .2, 1) backwards;
            }
            :host([data-intro="true"]) .dock > * {
                animation: toolbar-item-in 220ms cubic-bezier(.2, .8, .2, 1) backwards;
            }
            .dock > :nth-child(2) { animation-delay: 30ms; }
            .dock > :nth-child(3) { animation-delay: 60ms; }
            .dock > :nth-child(4) { animation-delay: 90ms; }
            .dock > :nth-child(5) { animation-delay: 120ms; }
            .dock > :nth-child(6) { animation-delay: 150ms; }
            @keyframes toolbar-in {
                from { opacity: 0; transform: translateY(10px) scale(.98); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }
            @keyframes toolbar-item-in {
                from { opacity: 0; transform: translateY(5px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .control {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                flex: 0 0 auto;
                min-width: 40px;
                height: 40px;
                padding: 0;
                font: inherit;
                text-decoration: none;
                white-space: nowrap;
                cursor: pointer;
                touch-action: manipulation;
                -webkit-tap-highlight-color: transparent;
                transition: background-color 140ms ease, color 140ms ease, transform 140ms ease;
            }
            .control {
                position: relative;
                border: 0;
                border-radius: 8px;
                background: transparent;
                color: var(--muted);
            }
            .control:hover { background: var(--hover); color: var(--foreground); }
            .control:active { transform: scale(.96); }
            .control > svg { transition: transform 180ms cubic-bezier(.2, .8, .2, 1); }
            .control:active > svg { transform: translateY(0) scale(.92); }
            .control:focus-visible {
                outline: 2px solid var(--foreground);
                outline-offset: 3px;
            }
            .metric { padding: 0 10px; cursor: default; }
            .metric:active { transform: none; }
            .metric-value { color: var(--foreground); font-variant-numeric: tabular-nums; }
            .metric-unit { color: var(--muted); font-size: 11px; margin-left: 3px; }
            .control[aria-busy="true"] { cursor: default; }
            .control[aria-busy="true"] > svg { display: none; }
            .control[aria-busy="true"]::after {
                content: '';
                width: 16px;
                height: 16px;
                border: 2px solid var(--border);
                border-top-color: var(--foreground);
                border-radius: 50%;
                animation: control-loading 700ms linear infinite;
            }
            @keyframes control-loading { to { transform: rotate(360deg); } }
            svg {
                display: block;
                width: 19px;
                height: 19px;
                flex-shrink: 0;
                fill: none;
                stroke: currentColor;
                stroke-width: 1.75;
                stroke-linecap: round;
                stroke-linejoin: round;
            }
            svg.fill { fill: currentColor; stroke: none; }
            .tooltip {
                position: fixed;
                z-index: 2147483003;
                top: var(--tooltip-y, 0px);
                left: var(--tooltip-x, 0px);
                width: max-content;
                max-width: min(320px, calc(100vw - 24px));
                padding: 7px 10px;
                border: 1px solid var(--border);
                border-radius: 7px;
                background: var(--surface);
                color: var(--foreground);
                box-shadow: var(--shadow);
                font-size: 12px;
                line-height: 1.35;
                text-align: center;
                white-space: normal;
                overflow-wrap: anywhere;
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity 140ms ease, visibility 140ms ease;
            }
            .tooltip[data-open="true"] {
                opacity: 1;
                visibility: visible;
            }
            @media (max-width: 480px) {
                :host { bottom: max(12px, env(safe-area-inset-bottom)) !important; }
                .dock { padding: 5px; }
                .control { min-width: 36px; }
                .metric { padding: 0 6px; gap: 5px; }
                .tooltip { font-size: 11px; padding: 6px 8px; }
            }
            @media (prefers-reduced-motion: reduce) {
                :host([data-intro="true"]) .dock,
                :host([data-intro="true"]) .dock > * { animation: none; }
                .dock, .control, .control > svg, .tooltip { transition: none; }
                .control:active { transform: none; }
            }
            {{component_styles}}
        </style>
        {{component_panels}}
        {{component_hints}}
        <div class="dock"{{dock_hidden}} role="toolbar" aria-label="Управление сайтом" aria-orientation="horizontal">
            {{components}}
        </div>
        <div class="tooltip" data-tooltip data-placement="top" role="tooltip"></div>
    </template>
</div>
<script>
(() => {
    const host = document.currentScript.previousElementSibling;
    const introKey = 'dletoolbar:intro-shown';
    if (sessionStorage.getItem(introKey) === null) {
        host.dataset.intro = 'true';
        sessionStorage.setItem(introKey, '1');
    }

    const template = host.querySelector('template');
    const root = host.attachShadow({ mode: 'open' });
    root.append(template.content.cloneNode(true));
    template.remove();

    const dock = root.querySelector('.dock');
    const controls = [...dock.querySelectorAll('.control:not(:disabled)')];
    const tooltip = root.querySelector('[data-tooltip]');
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
    const setTheme = () => { host.dataset.theme = systemTheme.matches ? 'dark' : 'light'; };
    const setTabStop = (control) => {
        controls.forEach((item) => { item.tabIndex = item === control ? 0 : -1; });
    };
    let hoveredControl = null;
    let focusedControl = null;
    let tooltipControl = null;
    let tooltipShowTimer;
    let tooltipHideTimer;
    let tooltipWarm = false;
    let dockHovered = false;
    const hideTooltip = (resetWarm = true) => {
        clearTimeout(tooltipShowTimer);
        clearTimeout(tooltipHideTimer);
        if (resetWarm) tooltipWarm = false;
        tooltipControl = null;
        delete tooltip.dataset.open;
    };
    const positionTooltip = (control) => {
        const label = control.getAttribute('aria-label');
        if (!label) return;

        const visualViewport = window.visualViewport;
        const viewport = {
            left: visualViewport ? visualViewport.offsetLeft : 0,
            top: visualViewport ? visualViewport.offsetTop : 0,
            width: visualViewport ? visualViewport.width : document.documentElement.clientWidth,
            height: visualViewport ? visualViewport.height : document.documentElement.clientHeight,
        };
        const padding = 12;
        const gap = 10;
        const viewportRight = viewport.left + viewport.width;
        const viewportBottom = viewport.top + viewport.height;

        tooltipControl = control;
        tooltip.textContent = label;
        tooltip.style.maxWidth = Math.max(0, viewport.width - padding * 2) + 'px';
        const controlRect = control.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        const centeredLeft = controlRect.left + controlRect.width / 2 - tooltipRect.width / 2;
        const left = Math.min(
            Math.max(centeredLeft, viewport.left + padding),
            viewportRight - padding - tooltipRect.width
        );
        const topPosition = controlRect.top - gap - tooltipRect.height;
        const bottomPosition = controlRect.bottom + gap;
        const fitsAbove = topPosition >= viewport.top + padding;
        const fitsBelow = bottomPosition + tooltipRect.height <= viewportBottom - padding;
        const spaceAbove = controlRect.top - viewport.top;
        const spaceBelow = viewportBottom - controlRect.bottom;
        let top;
        let placement;

        if (fitsAbove || (!fitsBelow && spaceAbove >= spaceBelow)) {
            placement = 'top';
            top = Math.max(viewport.top + padding, topPosition);
        } else {
            placement = 'bottom';
            top = Math.min(bottomPosition, viewportBottom - padding - tooltipRect.height);
        }

        tooltip.dataset.placement = placement;
        const pixelRatio = window.devicePixelRatio;
        tooltip.style.setProperty('--tooltip-x', Math.round(left * pixelRatio) / pixelRatio + 'px');
        tooltip.style.setProperty('--tooltip-y', Math.round(top * pixelRatio) / pixelRatio + 'px');
        tooltip.dataset.open = 'true';
    };
    const showTooltip = (control, immediate = false) => {
        clearTimeout(tooltipShowTimer);
        clearTimeout(tooltipHideTimer);
        const show = () => {
            tooltipWarm = true;
            positionTooltip(control);
        };
        if (immediate || tooltipWarm) show();
        else tooltipShowTimer = setTimeout(show, 350);
    };
    const restoreTooltip = () => {
        if (hoveredControl) showTooltip(hoveredControl);
        else if (focusedControl) showTooltip(focusedControl, true);
        else {
            clearTimeout(tooltipShowTimer);
            clearTimeout(tooltipHideTimer);
            tooltipHideTimer = setTimeout(() => hideTooltip(!dockHovered), 120);
        }
    };

    dock.addEventListener('pointerenter', (event) => {
        if (event.pointerType !== 'touch') dockHovered = true;
    });
    dock.addEventListener('pointerleave', () => {
        dockHovered = false;
        restoreTooltip();
    });

    controls.forEach((control) => {
        control.addEventListener('pointerenter', (event) => {
            if (event.pointerType === 'touch') return;
            hoveredControl = control;
            showTooltip(control);
        });
        control.addEventListener('pointerleave', () => {
            if (hoveredControl === control) hoveredControl = null;
            restoreTooltip();
        });
        control.addEventListener('focus', () => {
            if (!control.matches(':focus-visible')) return;
            focusedControl = control;
            showTooltip(control, true);
        });
        control.addEventListener('blur', () => {
            if (focusedControl === control) focusedControl = null;
            restoreTooltip();
        });
    });

    setTheme();
    setTabStop(controls[0]);
    document.body.append(host);
    host.hidden = false;

    systemTheme.addEventListener('change', setTheme);
    root.addEventListener('dle-toolbar-popup-open', () => {
        hoveredControl = null;
        hideTooltip();
    });
    {{popup_lifecycle}}
    {{component_scripts}}
    dock.addEventListener('focusin', (event) => {
        if (controls.includes(event.target)) setTabStop(event.target);
    });
    dock.addEventListener('keydown', (event) => {
        if (event.target.matches('select')) return;
        const index = controls.indexOf(root.activeElement);
        if (index < 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        let next;
        if (event.key === 'ArrowRight') next = (index + 1) % controls.length;
        else if (event.key === 'ArrowLeft') next = (index - 1 + controls.length) % controls.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = controls.length - 1;
        else return;
        event.preventDefault();
        controls[next].focus();
    });
    const repositionTooltip = () => {
        if (tooltipControl) positionTooltip(tooltipControl);
    };
    window.addEventListener('resize', repositionTooltip, { passive: true });
    window.addEventListener('scroll', repositionTooltip, { passive: true, capture: true });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', repositionTooltip, { passive: true });
        window.visualViewport.addEventListener('scroll', repositionTooltip, { passive: true });
    }
})();
</script>
HTML, [
            '{{components}}' => $rendered['controls'],
            '{{component_hints}}' => $rendered['hints'],
            '{{dock_hidden}}' => $rendered['controls'] === '' ? ' hidden' : '',
            '{{component_styles}}' => $rendered['styles'],
            '{{component_panels}}' => $rendered['panels'],
            '{{popup_lifecycle}}' => PopupLifecycle::renderScript(),
            '{{component_scripts}}' => $rendered['scripts'],
        ]);
    }
}
