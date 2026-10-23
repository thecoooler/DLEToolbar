<?php

namespace iCooLER\DLEToolbar\Components\Link;

final class NewsFields
{
    /** @var array<string, array{value: string, given: bool}> */
    private static array $values = [];

    /** @var list<array{name: string, label: string, value: string}> */
    private static array $fields = [];

    public static function capture(string $raw): void
    {
        global $config, $member_id, $lang, $xfields;
        /** @var array{version_id: string} $config */
        /** @var array{user_group: int|string} $member_id */
        /** @var array{xfield_xyes: string, xfield_xno: string} $lang */
        /** @var array<int, array{0: string, 1: string, 3: string, 20: string}> $xfields */
        self::$values = [];
        self::$fields = [];
        $modernFields = version_compare($config['version_id'], '19.0', '>=');
        /** @var array<string, string> $data */
        $data = $modernFields ? \DLEXFields::xfieldsdataload($raw) : \xfieldsdataload($raw);
        if ($modernFields) {
            /** @var array{fields: array<string, array{name: string, description: string, type: string, allow_view_usergroups: string, dynamic?: bool|int|string}>} $loaded */
            $loaded = \DLEXFields::$fields;
            $definitions = $loaded['fields'];
        } else {
            $definitions = array_map(static fn (array $field): array => [
                'name' => $field[0], 'description' => $field[1], 'type' => $field[3], 'allow_view_usergroups' => $field[20],
            ], $xfields);
        }
        foreach ($definitions as $field) {
            $value = $data[$field['name']] ?? '';
            $groups = explode(',', $field['allow_view_usergroups']);
            if ($groups[0] && !in_array($member_id['user_group'], $groups)) continue;
            $given = $modernFields ? (bool) $value : $value !== '';
            if ($field['type'] === 'yesorno') {
                $given = (bool) intval($value);
                $value = $given ? $lang['xfield_xyes'] : $lang['xfield_xno'];
            } elseif ($given && $modernFields && !empty($field['dynamic'])) {
                $given = false;
                foreach (explode("\x01", $value) as $item) {
                    $item = trim($item);
                    if ($item !== '' && $item !== '<p><br></p>') {
                        $given = true;
                        break;
                    }
                }
            }
            self::$values[strtolower($field['name'])] = ['value' => $value, 'given' => $given];
            self::$fields[] = ['name' => $field['name'], 'label' => $field['description'], 'value' => $value];
        }
    }

    /** @return array<string, array{value: string, given: bool}> */
    public static function values(): array
    {
        return self::$values;
    }

    /** @return list<array{name: string, label: string, value: string}> Fields visible to the current group, in definition order. */
    public static function fields(): array
    {
        return self::$fields;
    }
}
