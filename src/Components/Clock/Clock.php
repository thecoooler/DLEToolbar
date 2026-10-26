<?php

namespace iCooLER\DLEToolbar\Components\Clock;

use iCooLER\DLEToolbar\ComponentFramework\ConfigurableComponent;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;

final class Clock implements ConfigurableComponent
{
    private bool $showSeconds;

    public static function parameters(): array
    {
        return ['show_seconds' => Parameter::boolean('Показывать секунды', true,
            'Выключите, чтобы показывать время в формате ЧЧ:ММ.')];
    }

    public function __construct(array $values)
    {
        $this->showSeconds = (bool) $values['show_seconds'];
    }

    public static function definition(): array
    {
        return ['label' => 'Текущее время', 'description' => 'Часы в часовом поясе браузера с автоматическим обновлением.'];
    }

    public function render(): array
    {
        return [
            'control' => '<span class="control metric clock" data-component="clock" role="group" tabindex="0"'
                . ' aria-label="Текущее время (часовой пояс браузера)">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>'
                . '<time class="metric-value" data-clock data-show-seconds="' . ($this->showSeconds ? 'true' : 'false')
                . '" role="timer" aria-live="off"></time></span>',
            'styles' => <<<'CSS'
            .clock .metric-value { min-width: 8ch; }
            .clock [data-show-seconds="false"] { min-width: 5ch; }
CSS,
            'script' => <<<'JS'
    {
        const clock = root.querySelector('[data-clock]');
        const formatter = new Intl.DateTimeFormat('ru-RU', {
            hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
            second: clock.dataset.showSeconds === 'true' ? '2-digit' : undefined,
        });
        const updateClock = () => {
            const value = formatter.format(new Date());
            clock.textContent = value;
            clock.dateTime = value;
        };
        updateClock();
        window.setInterval(updateClock, 1000);
    }
JS,
        ];
    }
}
