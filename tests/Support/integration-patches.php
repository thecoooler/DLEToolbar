<?php

// Verbatim copies of the replace blocks in dleplugin.yml. Keep in sync.
return [
    'engine/init.php' => [
        <<<'PHP'
$DLEToolbar = null;
try {
  $DLEToolbar = new \iCooLER\DLEToolbar\Toolbar\DLEToolbar();
} catch (\Throwable $error) {
  error_log('[DLEToolbar] bootstrap: ' . $error);
}
PHP,
        <<<'PHP'
try {
  if ($DLEToolbar !== null) $DLEToolbar->init();
} catch (\Throwable $error) {
  $DLEToolbar = null;
  error_log('[DLEToolbar] initialization: ' . $error);
}
PHP,
        <<<'PHP'
try {
  if ($DLEToolbar !== null && $DLEToolbar->isHasAccess()) {
    \iCooLER\DLEToolbar\Components\Debug\DebugLog::before(\DLEPlugins::Check(ROOT_DIR . '/engine/engine.php'));
  }
} catch (\Throwable $error) {
  $DLEToolbar = null;
  error_log('[DLEToolbar] file timing: ' . $error);
}
PHP,
        <<<'PHP'
try {
  if ($DLEToolbar !== null && $DLEToolbar->isHasAccess()) {
    \iCooLER\DLEToolbar\Components\Debug\DebugLog::after(\DLEPlugins::Check(ROOT_DIR . '/engine/engine.php'));
  }
} catch (\Throwable $error) {
  $DLEToolbar = null;
  error_log('[DLEToolbar] file timing: ' . $error);
}
PHP,
    ],
    'engine/modules/show.full.php' => [
        <<<'PHP'
try {
  if ($DLEToolbar !== null && $DLEToolbar->isHasAccess()) {
    \iCooLER\DLEToolbar\Components\Link\NewsFields::capture($row['xfields']);
  }
} catch (\Throwable $error) {
  error_log('[DLEToolbar] news fields: ' . $error);
}
PHP,
    ],
    'engine/modules/main.php' => [
        <<<'PHP'
if ($DLEToolbar !== null && $DLEToolbar->isHasAccess()) {
  $ajax .= $DLEToolbar->marker();
}
PHP,
        <<<'PHP'
try {
  if ($DLEToolbar !== null && $DLEToolbar->isHasAccess()) {
    require_once \DLEPlugins::Check(ENGINE_DIR . "/iCooLER/DLEToolbar/main.php");
  }
} catch (\Throwable $error) {
  $tpl->result['main'] = str_replace($DLEToolbar->marker(), '', $tpl->result['main']);
  error_log('[DLEToolbar] output: ' . $error);
}
PHP,
    ],
];
