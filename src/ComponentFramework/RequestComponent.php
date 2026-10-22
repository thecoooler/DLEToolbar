<?php

namespace iCooLER\DLEToolbar\ComponentFramework;

interface RequestComponent extends Component
{
    /** Runs for visible components after toolbar access is granted. */
    public function initialize(): void;
}
