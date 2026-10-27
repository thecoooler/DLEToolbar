<?php

namespace iCooLER\DLEToolbar\ComponentFramework;

use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;
use iCooLER\DLEToolbar\Components\Cache\Cache;
use iCooLER\DLEToolbar\Components\Clock\Clock;
use iCooLER\DLEToolbar\Components\EditPost\EditPost;
use iCooLER\DLEToolbar\Components\Files\Files;
use iCooLER\DLEToolbar\Components\Link\Link;
use iCooLER\DLEToolbar\Components\Queries\Queries;
use iCooLER\DLEToolbar\Components\Response\Response;
use iCooLER\DLEToolbar\Components\Skin\Skin;
use iCooLER\DLEToolbar\Components\Time\Time;
use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;

/** @phpstan-import-type ComponentId from ToolbarPreferences */
final class ComponentRegistry
{
    /** @var array<ComponentId, class-string<Component>> */
    private const CLASSES = [
        'response' => Response::class,
        'time' => Time::class,
        'queries' => Queries::class,
        'files' => Files::class,
        'skin' => Skin::class,
        'cache' => Cache::class,
        'editpost' => EditPost::class,
        'clock' => Clock::class,
    ];

    /** @var array<string, class-string<ConfigurableComponent>> */
    private const REPEATABLE_CLASSES = ['link' => Link::class];

    /** @var array<ComponentId, Component> */
    private array $components = [];

    public function __construct()
    {
        foreach (self::CLASSES as $id => $class) {
            if (!is_subclass_of($class, ConfigurableComponent::class)) {
                /** @var class-string<Component> $class */
                $this->components[$id] = new $class();
            }
        }
    }

    /** Initializes request behavior for visible components. */
    public function initialize(ToolbarPreferences $preferences): void
    {
        $classes = self::classes($preferences->componentInstances());
        foreach ($preferences->visibleItems() as $id) {
            $component = $this->createComponent($id, $classes[$id], $preferences);
            if ($component instanceof RequestComponent) {
                $component->initialize();
            }
        }
    }

    /** @return array{controls: string, hints: string, styles: string, panels: string, scripts: string} */
    public function render(ToolbarPreferences $preferences): array
    {
        $controls = $hints = $styles = $panels = $scripts = [];
        $classes = self::classes($preferences->componentInstances());
        foreach ($preferences->visibleItems() as $id) {
            $component = $this->createComponent($id, $classes[$id], $preferences);
            $rendered = $component->render();
            if ($rendered === null) {
                continue;
            }
            if ($rendered['control'] !== '') {
                $controls[] = $rendered['control'];
            }
            if (isset($rendered['hint'])) {
                $hints[] = $rendered['hint'];
            }
            $styles[] = $rendered['styles'] ?? '';
            $panels[] = $rendered['panel'] ?? '';
            $scripts[] = $rendered['script'] ?? '';
        }

        return [
            'controls' => implode("\n", $controls),
            'hints' => implode("\n", $hints),
            'styles' => implode("\n", $styles),
            'panels' => implode("\n", $panels),
            'scripts' => implode("\n", $scripts),
        ];
    }

    /**
     * @param ComponentId $id
     * @param class-string<Component> $class
     */
    private function createComponent(string $id, string $class, ToolbarPreferences $preferences): Component
    {
        return is_subclass_of($class, ConfigurableComponent::class)
            ? new $class($preferences->componentParameters($id))
            : $this->components[$id];
    }

    /**
     * @param array<string, string> $instances
     * @return array<ComponentId, array{label: string, description: string}>
     */
    public static function definitions(array $instances = []): array
    {
        $definitions = [];
        foreach (self::classes($instances) as $id => $class) {
            $definitions[$id] = $class::definition();
        }
        return $definitions;
    }

    /**
     * @param array<string, string> $instances
     * @return array<ComponentId, array<string, Parameter>>
     */
    public static function parameters(array $instances = []): array
    {
        $parameters = [];
        foreach (self::classes($instances) as $id => $class) {
            if (is_subclass_of($class, ConfigurableComponent::class)) {
                $parameters[$id] = $class::parameters();
            }
        }
        return $parameters;
    }

    /**
     * @param mixed $values
     * @param array<string, string> $instances
     * @return array<ComponentId, array<string, bool|float|string>>
     */
    public static function normalizeParameters($values, array $instances = []): array
    {
        $values = is_array($values) ? $values : [];
        $normalized = [];
        foreach (self::parameters($instances) as $id => $parameters) {
            $input = is_array($values[$id] ?? null) ? $values[$id] : [];
            foreach ($parameters as $name => $parameter) {
                $normalized[$id][$name] = $parameter->normalize($input[$name] ?? $parameter->default);
            }
        }
        return $normalized;
    }

    /**
     * @param mixed $values
     * @param array<string, string> $instances
     * @return array<string, array<string, array{message: string, value: string}>>
     */
    public static function parameterErrors($values, array $instances): array
    {
        $values = is_array($values) ? $values : [];
        $errors = [];
        foreach (self::parameters($instances) as $id => $parameters) {
            $input = is_array($values[$id] ?? null) ? $values[$id] : [];
            foreach ($parameters as $name => $parameter) {
                $value = $input[$name] ?? null;
                $message = $parameter->validationError($value);
                if ($message !== null) {
                    $errors[$id][$name] = ['message' => $message, 'value' => is_string($value) ? $value : ''];
                }
            }
        }
        return $errors;
    }

    /** @param mixed $values
     * @return array<string, string>
     */
    public static function normalizeInstances($values): array
    {
        $instances = [];
        foreach (is_array($values) ? $values : [] as $id => $type) {
            if (is_string($id) && preg_match('/^custom_[a-f0-9]{16}$/D', $id)
                && is_string($type) && isset(self::REPEATABLE_CLASSES[$type])) {
                $instances[$id] = $type;
            }
        }
        return $instances;
    }

    /** @return array<string, array{label: string, description: string}> */
    public static function repeatableDefinitions(): array
    {
        $definitions = [];
        foreach (self::REPEATABLE_CLASSES as $type => $class) {
            $definitions[$type] = $class::definition();
        }
        return $definitions;
    }

    /**
     * @param array<string, string> $instances
     * @return array<ComponentId, class-string<Component>>
     */
    private static function classes(array $instances): array
    {
        $classes = self::CLASSES;
        foreach ($instances as $id => $type) {
            $classes[$id] = self::REPEATABLE_CLASSES[$type];
        }
        return $classes;
    }
}
