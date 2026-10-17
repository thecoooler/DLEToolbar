<?php

namespace iCooLER\DLEToolbar;

if (!defined('DATALIFEENGINE')) {
    http_response_code(403);
    die('Hacking attempt!');
}

global $tpl, $DLEToolbar;

/** @var \iCooLER\DLEToolbar\Toolbar\DLEToolbar $DLEToolbar */
/** @var \dle_template $tpl */

$tpl->result['main'] = str_replace($DLEToolbar->marker(), $DLEToolbar->render(), $tpl->result['main']);

unset($DLEToolbar);
