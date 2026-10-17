<?php

namespace iCooLER\DLEToolbar\Components\Cache;

use iCooLER\DLEToolbar\ComponentFramework\RequestComponent;

final class Cache implements RequestComponent
{
    public static function definition(): array
    {
        return ['label' => 'Обновить страницу со сбросом кэша', 'description' => 'Очищает кэш DLE и перезагружает текущую страницу.'];
    }

    public function initialize(): void {
        if (!isset($_COOKIE['dle_toolbar_clear_cache'])) {
            return;
        }

        setcookie('dle_toolbar_clear_cache', '', time() - 3600, '/');

        global $config, $dle_login_hash;
        /** @var array{allow_cache: mixed} $config */
        /** @var string $dle_login_hash */
        $token = $_COOKIE['dle_toolbar_clear_cache'];
        if (!is_string($token) || !hash_equals($dle_login_hash, $token)) {
            return;
        }

        $config['allow_cache'] = false;

        (new CacheCleaner())->clear();
    }

    public function render(): array
    {
        global $dle_login_hash;
        /** @var string $dle_login_hash */

        return ['control' => '<button class="control" data-component="cache" data-clear-cache type="button"'
            . ' data-clear-cache-token="' . htmlspecialchars($dle_login_hash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' aria-label="Обновить страницу со сбросом кэша"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8.1 8.1 0 0 0 -14.9 -4L3 10"/><path d="M3 4v6h6"/><path d="M4 13a8.1 8.1 0 0 0 14.9 4L21 14"/><path d="M21 20v-6h-6"/></svg></button>',
            'script' => <<<'JS'
(() => {
    const clearCacheButton = root.querySelector('[data-clear-cache]');
    clearCacheButton.addEventListener('click', () => {
        if (clearCacheButton.disabled) return;
        clearCacheButton.disabled = true;
        clearCacheButton.setAttribute('aria-busy', 'true');
        clearCacheButton.setAttribute('aria-label', 'Обновление страницы со сбросом кэша');
        root.dispatchEvent(new CustomEvent('dle-toolbar-popup-open'));
        document.cookie = 'dle_toolbar_clear_cache=' + encodeURIComponent(clearCacheButton.dataset.clearCacheToken)
            + '; max-age=60; path=/; SameSite=Lax'
            + (window.location.protocol === 'https:' ? '; Secure' : '');
        requestAnimationFrame(() => requestAnimationFrame(() => window.location.reload()));
    });
})();
JS];
    }
}
