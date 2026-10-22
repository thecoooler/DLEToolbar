<?php

namespace iCooLER\DLEToolbar\Components\Skin;

use iCooLER\DLEToolbar\ComponentFramework\RequestComponent;

final class Skin implements RequestComponent
{
    public static function definition(): array
    {
        return ['label' => 'Смена шаблона', 'description' => 'Выбор установленного шаблона для текущего браузера с перезагрузкой страницы.'];
    }

    private string $defaultSkin;

    public function __construct()
    {
        global $config;
        /** @var array{skin: string} $config */
        $this->defaultSkin = $config['skin'];
    }

    public function initialize(): void
    {
        if (!isset($_COOKIE['dle_toolbar_skin'])) return;

        $cookie = $_COOKIE['dle_toolbar_skin'];
        setcookie('dle_toolbar_skin', '', time() - 3600, '/');
        unset($_COOKIE['dle_toolbar_skin']);
        $request = is_string($cookie) ? json_decode($cookie, true) : null;
        global $dle_login_hash;
        /** @var string $dle_login_hash */
        if (!is_array($request) || !is_string($request['token'] ?? null)
            || !hash_equals($dle_login_hash, $request['token']) || !is_string($request['skin'] ?? null)) return;

        /** @var string $skin */
        $skin = totranslit($request['skin'], false, false);
        $skin = trim($skin);
        if ($skin === '' || $skin !== $request['skin'] || !is_dir(ROOT_DIR . '/templates/' . $skin)) return;

        // Authentication finishes after DLE selects its template. Apply the cookie on a fresh GET.
        if ($skin === $this->defaultSkin) {
            set_cookie('dle_skin', '', -1);
            unset($_COOKIE['dle_skin']);
        } else {
            set_cookie('dle_skin', $skin, 365);
            $_COOKIE['dle_skin'] = $skin;
        }
        /** @var string $uri */
        $uri = $_SERVER['REQUEST_URI'];
        header('Location: /' . ltrim($uri, '/'), true, 303);
        exit;
    }

    public function render(): array
    {
        return ['control' => $this->renderControl(), 'styles' => <<<'CSS'
            .skin-picker {
                position: relative;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex: 0 0 auto;
                width: 40px;
                height: 40px;
                border-radius: 8px;
                color: var(--muted);
                transition: background-color 140ms ease, color 140ms ease;
            }
            .skin-picker:hover, .skin-picker:focus-within { background: var(--hover); }
            .skin-picker[data-overridden="true"] { color: var(--foreground); }
            .skin-indicator {
                position: absolute;
                top: 5px;
                right: 5px;
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: #16a34a;
                box-shadow: 0 0 0 2px var(--surface);
                pointer-events: none;
            }
            :host([data-theme="dark"]) .skin-indicator { background: #4ade80; }
            .skin-trigger {
                width: 40px;
                height: 40px;
            }
            .skin-menu {
                position: absolute;
                z-index: 2;
                bottom: calc(100% + 8px);
                left: 0;
                display: flex;
                flex-direction: column;
                width: max-content;
                min-width: min(320px, calc(100vw - 24px));
                max-width: min(440px, calc(100vw - 24px));
                max-height: min(560px, var(--skin-menu-space, calc(100dvh - 120px)));
                overflow: hidden;
                border: 1px solid var(--border);
                border-radius: 10px;
                background: var(--surface);
                color: var(--foreground);
                box-shadow: var(--shadow);
                transform-origin: center bottom;
                animation: toolbar-popover-in 160ms cubic-bezier(.2, .8, .2, 1);
            }
            /* The rounded frame clips; only the inner list scrolls, so the scrollbar stays inside the corners. */
            .skin-menu-list {
                display: flex;
                flex-direction: column;
                gap: 2px;
                min-height: 0;
                overflow-y: auto;
                overscroll-behavior: contain;
                padding: 4px;
                scrollbar-width: thin;
                scrollbar-color: var(--border) transparent;
            }
            .skin-menu[hidden] { display: none; }
            .skin-menu[data-closing="true"] {
                pointer-events: none;
                animation: toolbar-popover-out 130ms ease-in forwards;
            }
            @keyframes toolbar-popover-in {
                from { opacity: 0; transform: translateY(6px) scale(.98); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }
            @keyframes toolbar-popover-out {
                from { opacity: 1; transform: translateY(0) scale(1); }
                to { opacity: 0; transform: translateY(4px) scale(.98); }
            }
            .skin-option {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                width: 100%;
                flex: 0 0 auto;
                min-height: 44px;
                padding: 7px 10px;
                border: 0;
                border-radius: 6px;
                background: transparent;
                color: var(--foreground);
                font: inherit;
                font-size: 13px;
                line-height: 1.3;
                text-align: left;
                cursor: pointer;
                transition: background-color 120ms ease, color 120ms ease;
            }
            .skin-option:hover, .skin-option:focus-visible, .skin-option[aria-checked="true"] {
                background: var(--hover);
            }
            .skin-option[aria-checked="true"] { font-weight: 600; }
            .skin-option:focus-visible { outline: 2px solid var(--foreground); outline-offset: -2px; }
            .skin-option-check {
                flex: 0 0 auto;
                opacity: 0;
                color: var(--foreground);
            }
            .skin-option[aria-checked="true"] .skin-option-check { opacity: 1; }
            .skin-option-check svg { width: 16px; height: 16px; }
            .skin-option > span:first-child { min-width: 0; overflow-wrap: anywhere; }
            .skin-option-meta { display: block; margin-top: 2px; color: var(--muted); font-size: 11px; font-weight: 400; }
            @media (max-width: 480px) {
                .skin-menu { min-width: min(280px, calc(100vw - 20px)); max-width: calc(100vw - 20px); }
            }
            @media (max-width: 480px) { .skin-picker { width: 36px; } }
            @media (prefers-reduced-motion: reduce) {
                .skin-menu { animation: none; }
                .skin-picker, .skin-option { transition: none; }
            }
            .control[aria-busy="true"] .skin-indicator { display: none; }
CSS,
            'script' => <<<'JS'
(() => {
    const skinTrigger = root.querySelector('[data-skin-trigger]');
    const skinPicker = skinTrigger.closest('.skin-picker');
    const skinMenu = skinPicker.querySelector('[data-skin-menu]');
    const skinOptions = [...skinMenu.querySelectorAll('[data-skin-option]')];
    const skinPopup = createPopup({
        trigger: skinTrigger,
        panel: skinMenu,
        closeDuration: 130,
        onOpen: () => {
            // Keep the menu below the viewport top: it opens upward from the picker.
            skinMenu.style.setProperty('--skin-menu-space', skinPicker.getBoundingClientRect().top - 20 + 'px');
            const menuWidth = skinMenu.getBoundingClientRect().width;
            const pickerRect = skinPicker.getBoundingClientRect();
            const menuLeft = Math.min(
                Math.max(pickerRect.left + (pickerRect.width - menuWidth) / 2, 12),
                window.innerWidth - menuWidth - 12
            );
            skinMenu.style.left = menuLeft - pickerRect.left + 'px';
            skinMenu.querySelector('[aria-checked="true"]').focus();
        },
    });

    skinTrigger.addEventListener('click', () => {
        if (skinPopup.isOpen()) skinPopup.close({ restoreFocus: true });
        else skinPopup.open();
    });
    skinMenu.addEventListener('click', (event) => {
        const option = event.target.closest('[data-skin-option]');
        if (!option || skinTrigger.disabled) return;
        skinPopup.close({ immediate: true });
        skinTrigger.disabled = true;
        skinTrigger.setAttribute('aria-busy', 'true');
        skinTrigger.setAttribute('aria-label', 'Загрузка шаблона');
        root.dispatchEvent(new CustomEvent('dle-toolbar-popup-open'));
        document.cookie = 'dle_toolbar_skin=' + encodeURIComponent(JSON.stringify({
            skin: option.dataset.skinValue,
            token: skinTrigger.dataset.skinToken,
        })) + '; max-age=60; path=/; SameSite=Lax'
            + (window.location.protocol === 'https:' ? '; Secure' : '');
        requestAnimationFrame(() => requestAnimationFrame(() => window.location.reload()));
    });
    skinMenu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            skinPopup.close({ restoreFocus: true });
            return;
        }

        const index = skinOptions.indexOf(root.activeElement);
        if (index < 0) return;

        let next;
        if (event.key === 'ArrowDown') next = (index + 1) % skinOptions.length;
        else if (event.key === 'ArrowUp') next = (index - 1 + skinOptions.length) % skinOptions.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = skinOptions.length - 1;
        else return;

        event.preventDefault();
        skinOptions[next].focus();
    });
    skinMenu.addEventListener('focusout', (event) => {
        if (skinPopup.isOpen() && !skinPicker.contains(event.relatedTarget)) skinPopup.close();
    });
    document.addEventListener('pointerdown', (event) => {
        if (!skinMenu.hidden && !event.composedPath().includes(skinPicker)) {
            skinPopup.close({ restoreFocus: skinMenu.contains(root.activeElement) });
        }
    });
})();
JS];
    }

    private function renderControl(): string {
        global $config, $dle_login_hash;
        /** @var array{skin: string, version_id: string} $config */
        /** @var string $dle_login_hash */
        $current = $config['skin'];
        /** @var string $cookieSkin */
        $cookieSkin = $_COOKIE['dle_skin'] ?? '';
        $cookieSkin = trim($cookieSkin);
        $overridden = $cookieSkin !== '' && strcasecmp($cookieSkin, $this->defaultSkin) !== 0;
        /** @var array<string, array{name: string}> $skins */
        $skins = version_compare($config['version_id'], '14.3', '>=')
            ? get_folder_list('templates')
            : self::templateFolders();

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $options = '';
        foreach ($skins as $name => $metadata) {
            // PHP turns numeric folder names such as "2024" into integer array keys.
            $name = (string) $name;
            $selected = $name === $current;
            $isDefault = $name === $this->defaultSkin;
            // The folder is always shown: display names may repeat and do not identify the template.
            $meta = $name . ($isDefault ? ' · По умолчанию' : '');
            $options .= '<button class="skin-option" data-skin-option data-skin-value="' . $escape($name)
                . '" data-skin-default="' . ($isDefault ? 'true' : 'false') . '" type="button" role="menuitemradio" aria-checked="' . ($selected ? 'true' : 'false') . '">'
                . '<span>' . $escape($metadata['name'])
                . '<span class="skin-option-meta">' . $escape($meta) . '</span>'
                . '</span><span class="skin-option-check" aria-hidden="true">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>' . '</span></button>';
        }

        $label = 'Выбрать шаблон' . ($overridden ? ' (выбран нестандартный)' : '');

        return '<span class="skin-picker" data-component="skin" data-overridden="' . ($overridden ? 'true' : 'false') . '">'
            . '<button class="control skin-trigger" data-skin-trigger type="button" aria-label="' . $escape($label)
            . '" data-skin-token="' . $escape($dle_login_hash)
            . '" aria-haspopup="menu" aria-expanded="false" aria-controls="dle-toolbar-skin-menu">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21a9 9 0 0 1 0 -18c4.97 0 9 3.582 9 8c0 1.06 -.474 2.078 -1.318 2.828c-.844 .75 -1.989 1.172 -3.182 1.172h-2.5a2 2 0 0 0 -1 3.75a1.3 1.3 0 0 1 -1 2.25"/><path d="M7.5 10.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0M11.5 7.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0M15.5 10.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg>' . ($overridden ? '<span class="skin-indicator" aria-hidden="true"></span>' : '')
            . '</button><div class="skin-menu" id="dle-toolbar-skin-menu" data-skin-menu role="menu"'
            . ' aria-label="Выбрать шаблон" hidden><div class="skin-menu-list">' . $options . '</div></div></span>';
    }

    /**
     * DLE before 14.3 has no get_folder_list(): template folders are named by their directory.
     *
     * @return array<string, array{name: string}>
     */
    private static function templateFolders(): array
    {
        $skins = [];
        foreach (scandir(ROOT_DIR . '/templates') as $name) {
            if ($name !== '.' && $name !== '..' && is_dir(ROOT_DIR . '/templates/' . $name)) {
                $skins[$name] = ['name' => $name];
            }
        }

        return $skins;
    }

}
