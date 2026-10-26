<?php

namespace iCooLER\DLEToolbar\ComponentFramework\Parameters;

final class Parameter
{
    private string $type;
    public string $label;
    public string $description;
    /** @var bool|float|string */
    public $default;
    /** @var array<string, string> */
    private array $choices;
    private float $min;
    private float $step;
    /** @var (\Closure(string): string)|null */
    private ?\Closure $normalizer;
    /** @var array<string, string> */
    private array $icons;
    private bool $required;

    /**
     * @param 'boolean'|'number'|'select'|'icon'|'text' $type
     * @param array<string, string> $choices
     * @param bool|float|string $default
     * @param (\Closure(string): string)|null $normalizer
     * @param array<string, string> $icons
     */
    private function __construct(
        string $type,
        string $label,
        string $description,
        $default,
        array $choices = [],
        float $min = 0,
        float $step = 1,
        ?\Closure $normalizer = null,
        array $icons = [],
        bool $required = false
    ) {
        $this->type = $type;
        $this->label = $label;
        $this->description = $description;
        $this->default = $default;
        $this->choices = $choices;
        $this->min = $min;
        $this->step = $step;
        $this->normalizer = $normalizer;
        $this->icons = $icons;
        $this->required = $required;
    }

    public static function boolean(string $label, bool $default, string $description = ''): self
    {
        return new self('boolean', $label, $description, $default);
    }

    public static function number(string $label, float $default, float $min, float $step = 1, string $description = ''): self
    {
        return new self('number', $label, $description, $default, [], $min, $step);
    }

    /** @param array<string, string> $choices */
    public static function select(string $label, string $default, array $choices, string $description = ''): self
    {
        return new self('select', $label, $description, $default, $choices);
    }

    /**
     * @param array<string, string> $choices
     * @param array<string, string> $icons доверенная SVG-разметка для каждого ключа из $choices
     */
    public static function icons(string $label, string $default, array $choices, array $icons, string $description = ''): self
    {
        return new self('icon', $label, $description, $default, $choices, 0, 1, null, $icons);
    }

    /** @param (\Closure(string): string)|null $normalizer */
    public static function text(string $label, string $default = '', string $description = '', ?\Closure $normalizer = null, bool $required = false): self
    {
        return new self('text', $label, $description, $default, [], 0, 1, $normalizer, [], $required);
    }

    /** @param mixed $value
     * @return bool|float|string
     */
    public function normalize($value)
    {
        if ($this->type === 'boolean') {
            if (in_array($value, [true, 1, '1'], true)) {
                return true;
            }
            if (in_array($value, [false, 0, '0'], true)) {
                return false;
            }
            return $this->default;
        }
        if ($this->type === 'text') {
            if (!is_string($value)) {
                return $this->default;
            }
            return $this->normalizer !== null ? ($this->normalizer)(trim($value)) : trim($value);
        }
        if ($this->type === 'select' || $this->type === 'icon') {
            return is_string($value) && isset($this->choices[$value]) ? $value : $this->default;
        }
        if (is_string($value)) {
            $value = trim($value);
        }
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            return $this->default;
        }
        $number = (float) $value;
        return is_finite($number) && $number >= $this->min ? $number : $this->default;
    }

    /**
     * Returns why a submitted text value cannot be saved: it is required or its normalizer rejects it.
     * @param mixed $value
     */
    public function validationError($value): ?string
    {
        if ($this->type !== 'text') {
            return null;
        }
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            return $this->required ? 'Заполните поле «' . $this->label . '».' : null;
        }
        return $this->normalize($text) === '' ? 'Недопустимое значение поля «' . $this->label . '».' : null;
    }

    /** @param bool|float|string $value */
    public function renderControl(string $name, string $id, $value, ?string $error = null): string
    {
        $attributes = ' id="' . self::escape($id) . '" name="' . self::escape($name) . '"';
        if ($error !== null) {
            $attributes .= ' aria-invalid="true" aria-describedby="' . self::escape($id . '-error')
                . '" data-validation-error="' . self::escape($error) . '"';
        }
        if ($this->type === 'boolean') {
            return '<input type="hidden" name="' . self::escape($name) . '" value="0">'
                . '<input type="checkbox" class="switch"' . $attributes . ' value="1"'
                . ' aria-label="' . self::escape($this->label) . '"' . ($value ? ' checked' : '') . '>';
        }
        if ($this->type === 'text') {
            return '<input type="text" class="form-control" dir="auto"' . $attributes
                . ' value="' . self::escape((string) $value) . '"' . ($this->required ? ' required' : '') . '>';
        }
        if ($this->type === 'number') {
            return '<input type="number" class="form-control"' . $attributes
                . ' value="' . self::formatNumber((float) $value) . '" min="' . self::formatNumber($this->min)
                . '" step="' . self::formatNumber($this->step) . '" required>';
        }
        if ($this->type === 'icon') {
            $list = '';
            foreach ($this->choices as $key => $label) {
                $list .= '<label class="dlt-icon-choice">'
                    . '<input type="radio" id="' . self::escape($id . '-' . $key) . '" name="' . self::escape($name)
                    . '" value="' . self::escape($key) . '"' . ($value === $key ? ' checked' : '') . '>'
                    . '<span class="dlt-icon-glyph" aria-hidden="true">' . $this->icons[$key] . '</span>'
                    . '<span class="dlt-icon-label">' . self::escape($label) . '</span></label>';
            }
            return '<div class="dlt-icon-list" role="radiogroup" aria-label="' . self::escape($this->label)
                . '" id="' . self::escape($id) . '">' . $list . '</div>';
        }
        $options = '';
        foreach ($this->choices as $key => $label) {
            $options .= '<option value="' . self::escape($key) . '"'
                . ($value === $key ? ' selected' : '') . '>' . self::escape($label) . '</option>';
        }
        return '<select class="uniform form-control"' . $attributes . '>' . $options . '</select>';
    }

    public function renderLabel(string $id, string $class = ''): string
    {
        $label = self::escape($this->label);
        $attributes = ' class="' . self::escape($class) . '"';
        return $this->type === 'icon'
            ? '<span' . $attributes . '>' . $label . '</span>'
            : '<label' . $attributes . ' for="' . self::escape($id) . '">' . $label . '</label>';
    }

    /** Locale-independent float formatting for PHP 7.4. */
    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
