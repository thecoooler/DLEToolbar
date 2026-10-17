<?php

namespace iCooLER\DLEToolbar\AdminUI;

use iCooLER\DLEToolbar\Toolbar\ToolbarPreferences;
use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;

final class ToolbarSettingsPage
{
    private ToolbarPreferences $preferences;
    private string $actionUrl;
    private ?string $notice;

    public function __construct(
        ToolbarPreferences $preferences,
        string $actionUrl,
        ?string $notice = null
    ) {
        $this->preferences = $preferences;
        $this->actionUrl = $actionUrl;
        $this->notice = $notice;
    }

    public function render(): string
    {
        global $dle_login_hash;
        /** @var string $dle_login_hash */

        $preferences = $this->preferences;
        $groups = ToolbarPreferences::groups();
        $instances = $preferences->componentInstances();
        $definitions = ComponentRegistry::definitions($instances);
        $parameters = ComponentRegistry::parameters($instances);
        $errors = $preferences->parameterErrors();
        $components = [];
        foreach ($preferences->itemOrder() as $id) {
            $components[$id] = new ComponentSettings(
                $definitions[$id],
                $parameters[$id] ?? [],
                isset($parameters[$id]) ? $preferences->componentParameters($id) : [],
                $instances[$id] ?? null,
                $preferences->isEnabled($id)
            );
        }

        $templates = [];
        foreach (ComponentRegistry::repeatableDefinitions() as $type => $definition) {
            $templateId = '__COMPONENT_ID__';
            $templateParameters = ComponentRegistry::parameters([$templateId => $type])[$templateId];
            $defaults = array_map(static fn (Parameter $parameter) => $parameter->default, $templateParameters);
            $templates[$type] = new ComponentSettings($definition, $templateParameters, $defaults, $type, true);
        }
        $actionUrl = $this->actionUrl;
        $notice = $this->notice;
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require \DLEPlugins::Check(ENGINE_DIR . '/iCooLER/DLEToolbar/Views/settings.php');
        return (string) ob_get_clean();
    }
}
