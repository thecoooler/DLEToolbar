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
        <<<'PHP'
require_once \DLEPlugins::Check(ENGINE_DIR . '/iCooLER/DLEToolbar/ComponentFramework/SourcePath.php');
require_once \DLEPlugins::Check(ENGINE_DIR . '/iCooLER/DLEToolbar/Components/Debug/DebugLog.php');
PHP,
        <<<'PHP'
try {
  \iCooLER\DLEToolbar\Components\Debug\DebugLog::after(ENGINE_DIR . '/modules/functions.php', true);
} catch (\Throwable $error) {
  error_log('[DLEToolbar] file timing: ' . $error);
}
PHP,
        <<<'PHP'
\iCooLER\DLEToolbar\Components\Debug\DebugLog::includePath(DLEPlugins::Check(ENGINE_DIR . '/modules/functions.php'), true)
PHP,
        <<<'PHP'
try {
  \iCooLER\DLEToolbar\Components\Debug\DebugLog::after(ENGINE_DIR . '/modules/sitelogin.php', true);
} catch (\Throwable $error) {
  error_log('[DLEToolbar] file timing: ' . $error);
}
PHP,
        <<<'PHP'
\iCooLER\DLEToolbar\Components\Debug\DebugLog::includePath(DLEPlugins::Check(ENGINE_DIR . '/modules/sitelogin.php'), true)
PHP,
        <<<'PHP'
try {
  \iCooLER\DLEToolbar\Components\Debug\DebugLog::after(ENGINE_DIR . '/modules/main.php');
} catch (\Throwable $error) {
  error_log('[DLEToolbar] file timing: ' . $error);
}
PHP,
        <<<'PHP'
\iCooLER\DLEToolbar\Components\Debug\DebugLog::includePath(DLEPlugins::Check(ENGINE_DIR . '/modules/main.php'))
PHP,
        <<<'PHP'
try {
  \iCooLER\DLEToolbar\Components\Debug\DebugLog::after(ENGINE_DIR . '/modules/calendar.php');
} catch (\Throwable $error) {
  error_log('[DLEToolbar] file timing: ' . $error);
}
PHP,
        <<<'PHP'
\iCooLER\DLEToolbar\Components\Debug\DebugLog::includePath(DLEPlugins::Check(ENGINE_DIR . '/modules/calendar.php'))
PHP,
        <<<'PHP'
try {
  \iCooLER\DLEToolbar\Components\Debug\DebugLog::after(ENGINE_DIR . '/modules/topnews.php');
} catch (\Throwable $error) {
  error_log('[DLEToolbar] file timing: ' . $error);
}
PHP,
        <<<'PHP'
\iCooLER\DLEToolbar\Components\Debug\DebugLog::includePath(DLEPlugins::Check(ENGINE_DIR . '/modules/topnews.php'))
PHP,
    ],
    'engine/engine.php' => [
        <<<'PHP'
try {
  \iCooLER\DLEToolbar\Components\Debug\DebugLog::after(ENGINE_DIR . '/modules/show.full.php');
} catch (\Throwable $error) {
  error_log('[DLEToolbar] file timing: ' . $error);
}
PHP,
        <<<'PHP'
\iCooLER\DLEToolbar\Components\Debug\DebugLog::includePath(DLEPlugins::Check(ENGINE_DIR . '/modules/show.full.php'))
PHP,
        <<<'PHP'
try {
  \iCooLER\DLEToolbar\Components\Debug\DebugLog::after(ENGINE_DIR . '/modules/show.short.php');
} catch (\Throwable $error) {
  error_log('[DLEToolbar] file timing: ' . $error);
}
PHP,
        <<<'PHP'
\iCooLER\DLEToolbar\Components\Debug\DebugLog::includePath(DLEPlugins::Check(ENGINE_DIR . '/modules/show.short.php'))
PHP,
        <<<'PHP'
try {
  \iCooLER\DLEToolbar\Components\Debug\DebugLog::after(ENGINE_DIR . '/modules/static.php');
} catch (\Throwable $error) {
  error_log('[DLEToolbar] file timing: ' . $error);
}
PHP,
        <<<'PHP'
\iCooLER\DLEToolbar\Components\Debug\DebugLog::includePath(DLEPlugins::Check(ENGINE_DIR . '/modules/static.php'))
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
    \iCooLER\DLEToolbar\Components\Debug\DebugLog::after(__FILE__, false, 'engine/modules/main.php · до вывода');
    require_once \DLEPlugins::Check(ENGINE_DIR . "/iCooLER/DLEToolbar/main.php");
  }
} catch (\Throwable $error) {
  $tpl->result['main'] = str_replace($DLEToolbar->marker(), '', $tpl->result['main']);
  error_log('[DLEToolbar] output: ' . $error);
}
PHP,
    ],
];
