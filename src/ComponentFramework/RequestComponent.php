<?php

namespace iCooLER\DLEToolbar\ComponentFramework;

interface RequestComponent extends Component
{
    /** Runs after toolbar access is granted, even when the control is disabled. */
    public function initialize(): void;
}
