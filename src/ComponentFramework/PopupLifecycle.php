<?php

namespace iCooLER\DLEToolbar\ComponentFramework;

/** Shared popup lifecycle inside one toolbar shadow root. */
final class PopupLifecycle
{
    public static function renderScript(): string
    {
        return <<<'JS'
    const createPopup = (() => {
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        let activePopup = null;

        return ({ trigger, panel, closeDuration, onOpen = () => {}, onClose = () => {} }) => {
            let closeTimer;
            const isOpen = () => trigger.getAttribute('aria-expanded') === 'true';
            const close = ({ restoreFocus = false, immediate = false } = {}) => {
                clearTimeout(closeTimer);
                trigger.setAttribute('aria-expanded', 'false');
                onClose();
                const finish = () => {
                    panel.hidden = true;
                    delete panel.dataset.closing;
                    if (activePopup === popup) activePopup = null;
                    if (restoreFocus) trigger.focus();
                };
                if (panel.hidden || reducedMotion.matches || immediate) {
                    finish();
                    return;
                }
                panel.dataset.closing = 'true';
                closeTimer = setTimeout(finish, closeDuration);
            };
            const open = () => {
                if (activePopup && activePopup !== popup) activePopup.close({ immediate: true });
                clearTimeout(closeTimer);
                delete panel.dataset.closing;
                panel.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                activePopup = popup;
                root.dispatchEvent(new CustomEvent('dle-toolbar-popup-open'));
                onOpen();
            };
            const popup = { open, close, isOpen };
            return popup;
        };
    })();
JS;
    }
}
