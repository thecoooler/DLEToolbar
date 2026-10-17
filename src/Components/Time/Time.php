<?php

namespace iCooLER\DLEToolbar\Components\Time;

use iCooLER\DLEToolbar\ComponentFramework\Component;

final class Time implements Component
{
    public static function definition(): array
    {
        return ['label' => 'Время генерации страницы', 'description' => 'Время формирования страницы на сервере с цветной меткой скорости.'];
    }

    public function render(): array
    {
        global $Timer;
        /** @var \microTimer $Timer */
        /** @var float $time */
        $time = $Timer->get();
        $ms = $time * 1000;
        $milliseconds = round($ms);
        if ($milliseconds < 1000) {
            $value = number_format($milliseconds, 0, '.', '');
            $unit = 'мс';
            $shown = $milliseconds;
        } else {
            $tenths = round($ms / 100);
            if ($tenths < 100) {
                $value = number_format($tenths / 10, 1, '.', '');
                $unit = 'сек';
                $shown = $tenths * 100;
            } else {
                $seconds = min(100, round($ms / 1000));
                $value = number_format($seconds, 0, '.', '');
                $unit = 'сек';
                $shown = $seconds * 1000;
            }
        }
        $speed = $shown < 200 ? 'success' : ($shown < 1000 ? 'redirect' : 'error');
        $label = $shown < 200 ? 'быстро' : ($shown < 1000 ? 'медленно' : 'очень медленно');

        return ['control' => '<span class="control metric time" data-component="time" data-speed="' . $speed . '" role="group" tabindex="0"'
            . ' aria-label="Время генерации страницы: ' . $value . ' ' . $unit . ', ' . $label . '">'
            . '<span class="speed-bar" aria-hidden="true"></span><span class="metric-value">' . $value
            . '<span class="metric-unit">' . $unit . '</span></span></span>',
            'styles' => <<<'CSS'
            .time[data-speed="success"] { --speed: #16a34a; }
            .time[data-speed="redirect"] { --speed: #d97706; }
            .time[data-speed="error"] { --speed: #dc2626; }
            :host([data-theme="dark"]) .time[data-speed="success"] { --speed: #4ade80; }
            :host([data-theme="dark"]) .time[data-speed="redirect"] { --speed: #fbbf24; }
            :host([data-theme="dark"]) .time[data-speed="error"] { --speed: #f87171; }
            .time .speed-bar { position: absolute; left: -7px; top: 50%; width: 5px; height: 22px; transform: translateY(-50%); border-radius: 0 4px 4px 0; background: var(--speed); }
            .time { min-width: 0; justify-content: flex-start; padding: 0 10px 0 15px; isolation: isolate; }
            .time .metric-value { min-width: 43px; font-variant-numeric: tabular-nums; }
CSS,
        ];
    }
}
