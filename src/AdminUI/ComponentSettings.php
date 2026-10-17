<?php

namespace iCooLER\DLEToolbar\AdminUI;

use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;

/** Settings shared by the saved component and its creation template. */
final class ComponentSettings
{
    public string $label;
    public string $defaultLabel;
    public string $description;

    /** @var array<string, Parameter> */
    public array $parameters;
    /** @var array<string, bool|float|string> */
    public array $values;
    public ?string $instanceType;
    public bool $enabled;

    /**
     * @param array{label: string, description: string} $definition
     * @param array<string, Parameter> $parameters
     * @param array<string, bool|float|string> $values
     */
    public function __construct(
        array $definition,
        array $parameters,
        array $values,
        ?string $instanceType,
        bool $enabled
    ) {
        $this->parameters = $parameters;
        $this->values = $values;
        $this->instanceType = $instanceType;
        $this->enabled = $enabled;
        $name = (string) ($values['name'] ?? '');
        $this->defaultLabel = $definition['label'];
        $this->label = $name !== '' ? $name : $this->defaultLabel;
        $this->description = $definition['description'];
    }
}
