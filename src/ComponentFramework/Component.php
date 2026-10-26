<?php

namespace iCooLER\DLEToolbar\ComponentFramework;

interface Component
{
    /** @return array{label: string, description: string} */
    public static function definition(): array;

    /**
     * Returns null when the component is unavailable on the current page.
     * Styles target the toolbar shadow root; scripts run with `root` and `createPopup` in scope.
     * Emit dle-toolbar-popup-open to dismiss the toolbar's shared tooltip.
     * For rich tooltips, include template[data-tooltip-content] in the control.
     * dle-toolbar-tooltip-render on the control provides the tooltip node in event.detail.
     * @return array{control: string, hint?: string, styles?: string, panel?: string, script?: string}|null
     */
    public function render(): ?array;
}
