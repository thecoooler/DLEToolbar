<?php

use iCooLER\DLEToolbar\AdminUI\ToolbarSettingsPage;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;

if (!defined('DATALIFEENGINE') || !defined('LOGGED_IN')) {
    http_response_code(403);
    die('Hacking attempt!');
}

global $dle_login_hash, $js_array, $member_id, $config;

/** @var string $dle_login_hash */
/** @var list<string> $js_array */
/** @var array{user_group: int|string} $member_id */
/** @var array{version_id: string} $config */

if ((int) $member_id['user_group'] !== 1) {
    http_response_code(403);
    die('Access denied');
}

if (version_compare($config['version_id'], '19.0', '>=')) {
    $js_array[] = 'public/js/sortable.js';
} elseif (version_compare($config['version_id'], '13.1', '>=')) {
    $js_array[] = 'engine/classes/js/sortable.js';
}

$embedded = ($_GET['dlt_embed'] ?? null) === '1';
$actionUrl = '?mod=dletoolbar' . ($embedded ? '&dlt_embed=1' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !is_string($_POST['user_hash'] ?? null)
        || !hash_equals($dle_login_hash, $_POST['user_hash'])
    ) {
        http_response_code(403);
        die('Access denied');
    }
}

$notice = null;
try {
    $preferences = ToolbarPreferences::load();
    $savedState = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submission = ToolbarSettingsPage::submit($_POST, $preferences, $actionUrl);
        $preferences = $submission['preferences'];
        if ($submission['redirect'] !== null) {
            header('Location: ' . $submission['redirect']);
            exit;
        }
        http_response_code(422);
        $savedState = is_string($_POST['dlt_saved_state'] ?? null) ? $_POST['dlt_saved_state'] : null;
    }

    $notice = $_SESSION['dletoolbar_settings_notice'] ?? null;
    /** @var string|null $notice */
    unset($_SESSION['dletoolbar_settings_notice']);
    $content = (new ToolbarSettingsPage($preferences, $actionUrl, $notice, $savedState))->render();
} catch (\Throwable $error) {
    http_response_code(500);
    error_log('[DLEToolbar] admin: ' . $error);
    unset($_SESSION['dletoolbar_settings_notice']);
    // The page is open to administrators only, so the cause may be shown in full.
    $details = [];
    for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
        $details[] = get_class($cause) . ': ' . $cause->getMessage() . "\n"
            . $cause->getFile() . ':' . $cause->getLine() . "\n"
            . $cause->getTraceAsString();
    }
    $details = str_replace(ROOT_DIR . DIRECTORY_SEPARATOR, '', implode("\n\nПредыдущее исключение:\n", $details));
    $content = '<div class="alert alert-danger" role="alert">Не удалось загрузить или сохранить настройки панели.'
        . '<details class="mt-10"><summary>Подробности</summary>'
        . '<pre class="mt-10" style="white-space: pre-wrap;">' . htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>'
        . '</details></div>';
}

echoheader(
    '<i class="fa fa-sliders position-left"></i><span class="text-semibold">DLEToolbar</span>',
    'Настройки плагина',
);
if ($embedded) {
    echo <<<'HTML'
<script>
document.addEventListener('keydown', (event) => {
    if (event.isComposing || event.key !== 'Escape' || document.querySelector('.modal.in, .chosen-container-active.chosen-with-drop, .bootstrap-select.open')) return;
    window.parent.postMessage('dletoolbar-settings-close', window.location.origin);
}, true);
</script>
HTML;
    if ($notice !== null) {
        echo '<script>window.parent.postMessage("dletoolbar-settings-saved", window.location.origin);</script>';
    }
}
if (version_compare($config['version_id'], '13.1', '<')) {
    require \DLEPlugins::Check(ENGINE_DIR . '/iCooLER/DLEToolbar/Views/sortable.php');
}
echo $content;
if ($embedded) {
    require \DLEPlugins::Check(ENGINE_DIR . '/iCooLER/DLEToolbar/Views/embedded-style.php');
}
echofooter();
