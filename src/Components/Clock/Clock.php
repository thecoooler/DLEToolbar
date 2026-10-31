<?php

namespace iCooLER\DLEToolbar\Components\Clock;

use iCooLER\DLEToolbar\ComponentFramework\ConfigurableComponent;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;

final class Clock implements ConfigurableComponent
{
    private bool $showSeconds;

    public static function parameters(): array
    {
        return ['show_seconds' => Parameter::boolean(
            'Показывать секунды',
            false,
            'Включите, чтобы показывать время в формате ЧЧ:ММ:СС.',
        )];
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
        $numbers = [];
        foreach (['hour', 'minute', 'second'] as $part) {
            $numbers[$part] = '<span class="clock-number" data-clock-part="' . $part . '">'
                . '<span data-clock-current></span><span class="clock-previous" data-clock-previous aria-hidden="true" hidden></span></span>';
        }

        return [
            'control' => '<span class="control metric clock" data-component="clock" role="group" tabindex="0"'
                . ' aria-label="Текущее время (часовой пояс браузера)">'
                . '<time class="metric-value" data-clock data-show-seconds="' . ($this->showSeconds ? 'true' : 'false')
                . '" role="timer" aria-live="off">'
                . $numbers['hour'] . ':' . $numbers['minute']
                . ($this->showSeconds ? ':' . $numbers['second'] : '')
                . '</time><template data-tooltip-content><time class="clock-tooltip" data-clock-tooltip role="timer" aria-live="off">'
                . $numbers['hour'] . ':' . $numbers['minute'] . ':' . $numbers['second']
                . '</time></template></span>',
            'styles' => <<<'CSS'
            .clock .metric-value { min-width: 8ch; }
            .clock [data-show-seconds="false"] { min-width: 5ch; }
            .clock-tooltip { font-variant-numeric: tabular-nums; }
            .clock-number { display: inline-grid; width: 2ch; overflow: hidden; vertical-align: bottom; }
            .clock-number > span { grid-area: 1 / 1; }
            .clock-previous[hidden] { display: none; }
CSS,
            'script' => <<<'JS'
    {
        const clock = root.querySelector('[data-clock]');
        const control = clock.parentElement;
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const formatter = new Intl.DateTimeFormat('ru-RU', {
            hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
            second: clock.dataset.showSeconds === 'true' ? '2-digit' : undefined,
        });
        const fullTimeFormatter = new Intl.DateTimeFormat('ru-RU', {
            hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
        });
        const updateNumbers = (container, parts) => {
            container.querySelectorAll('.clock-number').forEach((number) => {
                const part = number.dataset.clockPart;
                const current = number.querySelector('[data-clock-current]');
                const previous = number.querySelector('[data-clock-previous]');
                const value = parts[part];
                if (current.textContent === value) return;
                const oldValue = current.textContent;
                current.getAnimations().forEach((animation) => animation.cancel());
                previous.getAnimations().forEach((animation) => animation.cancel());
                current.textContent = value;
                previous.hidden = true;
                previous.textContent = '';
                if (!oldValue || reducedMotion.matches) return;

                previous.textContent = oldValue;
                previous.hidden = false;
                const outgoing = previous.animate([
                    { transform: 'translateY(0)' },
                    { transform: 'translateY(100%)' },
                ], { duration: 320, easing: 'ease-in-out' });
                outgoing.onfinish = () => {
                    previous.hidden = true;
                    previous.textContent = '';
                };
                current.animate([
                    { transform: 'translateY(-100%)' },
                    { transform: 'translateY(0)' },
                ], { duration: 320, easing: 'ease-in-out' });
            });
        };
        const timeParts = (now) => Object.fromEntries(fullTimeFormatter.formatToParts(now)
            .map(({ type, value }) => [type, value]));
        control.addEventListener('dle-toolbar-tooltip-render', ({ detail }) => {
            const tooltipClock = detail.querySelector('[data-clock-tooltip]');
            const now = new Date();
            updateNumbers(tooltipClock, timeParts(now));
            tooltipClock.dateTime = fullTimeFormatter.format(now);
        });
        const updateClock = () => {
            const now = new Date();
            const parts = timeParts(now);
            updateNumbers(clock, parts);
            clock.dateTime = formatter.format(now);
            control.setAttribute('aria-label', 'Текущее время: ' + fullTimeFormatter.format(now));
        };
        updateClock();
        window.setInterval(updateClock, 1000);
    }
JS,
        ];
    }
}
