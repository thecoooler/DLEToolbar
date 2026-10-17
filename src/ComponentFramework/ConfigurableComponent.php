<?php

namespace iCooLER\DLEToolbar\ComponentFramework;

use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;

interface ConfigurableComponent extends Component
{
    /** @return array<string, Parameter> */
    public static function parameters(): array;

    /** @param array<string, bool|float|string> $values Normalized parameter values. */
    public function __construct(array $values);
}
