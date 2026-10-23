<?php

namespace iCooLER\DLEToolbar\Toolbar;

use iCooLER\DLEToolbar\ComponentFramework\ComponentRegistry;

/**
 * @phpstan-type ComponentId string
 * @phpstan-type RawSettings array{access?: array<string, mixed>, toolbar?: array<string, mixed>, components?: mixed, instances?: mixed, ...<string, mixed>}
 * @phpstan-type ParameterValues array<string, bool|float|string>
 * @phpstan-type Settings array{instances: array<string, string>, components: array<ComponentId, ParameterValues>, access: array{groups: list<int>, ...<string, mixed>}, toolbar: array{items: list<ComponentId>, enabled: list<ComponentId>, ...<string, mixed>}, ...<string, mixed>}
 */
final class ToolbarPreferences
{
    private static ?self $loaded = null;

    /** @var array<string, array<string, array{message: string, value: string}>> */
    private array $errors = [];

    /** @return Settings */
    private static function defaults(): array
    {
        $items = array_keys(self::components());
        return [
            'instances' => [],
            'components' => ComponentRegistry::normalizeParameters([]),
            'access' => ['groups' => [1]],
            'toolbar' => ['items' => $items, 'enabled' => $items],
        ];
    }

    /** @var Settings */
    private array $values;

    /** @param Settings $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /** @return array<ComponentId, array{label: string, description: string}> */
    public static function components(): array
    {
        return ComponentRegistry::definitions();
    }

    /** @return array<int, array{group_name: string, allow_admin: mixed}> Groups allowed into the admin panel may be granted toolbar access. */
    public static function groups(): array
    {
        global $user_group;
        /** @var array<int, array{group_name: string, allow_admin: mixed}> $user_group */

        return array_filter($user_group, static fn (array $group): bool => (bool) $group['allow_admin']);
    }

    public static function load(): self
    {
        if (self::$loaded !== null) return self::$loaded;

        global $db;
        /** @var \db $db */
        $result = $db->query('SELECT setting_key, setting_value FROM `' . PREFIX . '_dletoolbar_settings`', false);
        if ($result === false) throw new \RuntimeException('Failed to load toolbar settings.');

        $values = [];
        while ($row = $db->get_row($result)) {
            /** @var array{setting_key: string, setting_value: string} $row */
            $values[$row['setting_key']] = json_decode($row['setting_value'], true, 512, JSON_THROW_ON_ERROR);
        }
        $db->free($result);

        /** @var RawSettings $values */
        return self::$loaded = self::fromConfig($values);
    }

    /** Clears the current request's settings for tests. */
    public static function resetRequestCache(): void
    {
        self::$loaded = null;
    }

    /** @param RawSettings $values */
    public static function fromConfig(array $values): self
    {
        $defaults = self::defaults();
        $instances = ComponentRegistry::normalizeInstances($values['instances'] ?? []);
        $definitions = ComponentRegistry::definitions($instances);
        $toolbar = $values['toolbar'] ?? [];
        $access = $values['access'] ?? [];
        $items = self::normalizeIds($toolbar['items'] ?? [], $definitions);
        foreach (array_keys($definitions) as $id) {
            if (!in_array($id, $items, true)) {
                $items[] = $id;
            }
        }
        $enabled = array_key_exists('enabled', $toolbar)
            ? self::normalizeIds($toolbar['enabled'], $definitions)
            : $defaults['toolbar']['enabled'];

        $groups = self::normalizeGroups($access['groups'] ?? $defaults['access']['groups']);
        $values = array_replace($defaults, $values);
        $values['access'] = array_replace($defaults['access'], $access, ['groups' => $groups]);
        $values['toolbar'] = array_replace($defaults['toolbar'], $toolbar, ['items' => $items, 'enabled' => $enabled]);

        $values['instances'] = $instances;
        $values['components'] = ComponentRegistry::normalizeParameters($values['components'] ?? [], $instances);

        return new self($values);
    }

    /** @param array<array-key, mixed> $request */
    public static function fromRequest(array $request, ?self $current = null): self
    {
        $toolbar = is_array($request['toolbar'] ?? null) ? $request['toolbar'] : [];
        $access = is_array($request['access'] ?? null) ? $request['access'] : [];
        $values = ($current ?? self::fromConfig([]))->toConfig();
        $values['access']['groups'] = self::normalizeGroups($access['groups'] ?? []);
        if (array_key_exists('instances', $request)) {
            $values['instances'] = ComponentRegistry::normalizeInstances($request['instances']);
        }
        $definitions = ComponentRegistry::definitions($values['instances']);
        $values['toolbar']['items'] = self::normalizeIds($toolbar['items'] ?? [], $definitions);
        $values['toolbar']['enabled'] = self::normalizeIds($toolbar['enabled'] ?? [], $definitions);

        if (is_array($request['components'] ?? null)) {
            $values['components'] = array_replace_recursive($values['components'], $request['components']);
        }

        $preferences = self::fromConfig($values);
        $preferences->errors = ComponentRegistry::parameterErrors($values['components'], $values['instances']);
        return $preferences;
    }

    /** @return array<string, array<string, array{message: string, value: string}>> */
    public function parameterErrors(): array
    {
        return $this->errors;
    }

    /** @return array<string, string> */
    public function componentInstances(): array
    {
        return $this->values['instances'];
    }

    /** @return ParameterValues */
    public function componentParameters(string $id): array
    {
        return $this->values['components'][$id];
    }

    /** @return Settings */
    public function toConfig(): array
    {
        return $this->values;
    }

    /** @return list<ComponentId> */
    public function itemOrder(): array
    {
        return $this->values['toolbar']['items'];
    }

    public function isEnabled(string $id): bool
    {
        return in_array($id, $this->values['toolbar']['enabled'], true);
    }

    /** @return list<int> */
    public function allowedGroups(): array
    {
        return $this->values['access']['groups'];
    }

    public function allowsGroup(int $group): bool
    {
        return in_array($group, $this->allowedGroups(), true);
    }

    /** @return list<ComponentId> */
    public function visibleItems(): array
    {
        return array_values(array_filter($this->itemOrder(), fn (string $id): bool => $this->isEnabled($id)));
    }

    public function save(): void
    {
        global $db;
        /** @var \db $db */
        $rows = [];
        foreach ($this->values as $key => $value) {
            /** @var string $key */
            $key = $db->safesql($key);
            /** @var string $value */
            $value = $db->safesql(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            $rows[] = "('$key', '$value', CURRENT_TIMESTAMP)";
        }

        $sql = 'INSERT INTO `' . PREFIX . '_dletoolbar_settings` (setting_key, setting_value, updated_at) VALUES '
            . implode(', ', $rows)
            . ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)';
        if ($db->query($sql, false) === false) throw new \RuntimeException('Failed to save toolbar settings.');

        self::$loaded = $this;
    }

    /**
     * @param mixed $values
     * @return list<int>
     */
    private static function normalizeGroups($values): array
    {
        $groups = [];
        $available = self::groups();
        foreach (is_array($values) ? $values : [] as $value) {
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                continue;
            }
            $group = (int) $value;
            if (isset($available[$group]) && !in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /** @param mixed $values
     * @param array<string, array{label: string, description: string}> $components
     * @return list<ComponentId>
     */
    private static function normalizeIds($values, array $components): array
    {
        $ids = [];
        foreach (is_array($values) ? $values : [] as $id) {
            if (is_string($id) && isset($components[$id]) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
