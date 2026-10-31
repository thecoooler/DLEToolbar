<?php

namespace iCooLER\DLEToolbar\Toolbar;

/** Lets tests fail the toolbar constructor; delegates to the native function otherwise. */
function random_bytes(int $length): string
{
    if ($GLOBALS['dletoolbar_fail_random'] ?? false) {
        throw new \Random\RandomException('Injected entropy failure');
    }
    return \random_bytes($length);
}
