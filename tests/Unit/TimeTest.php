<?php

use iCooLER\DLEToolbar\Components\Time\Time;

use function iCooLER\DLEToolbar\setupDleToolbarTestEnvironment;

beforeEach(function (): void {
    setupDleToolbarTestEnvironment();
});

it('keeps the speed indicator consistent with the rounded time at unit and colour boundaries', function (float $ms, string $value, string $unit, string $speed): void {
    $GLOBALS['Timer'] = new class ($ms) {
        private float $ms;
        public function __construct(float $ms)
        {
            $this->ms = $ms;
        }
        public function get(): float
        {
            return $this->ms / 1000;
        }
    };
    $control = (new Time())->render()['control'];
    expect($control)->toContain('data-speed="' . $speed . '"')
        ->toContain('<span class="metric-value">' . $value . '<span class="metric-unit">' . $unit . '</span>');
})->with([
    [0.0, '0', 'мс', 'success'],
    [0.4, '0', 'мс', 'success'],
    [199.2, '199', 'мс', 'success'],
    [199.5, '200', 'мс', 'redirect'],
    [999.2, '999', 'мс', 'redirect'],
    [999.5, '1.0', 'сек', 'error'],
    [1001.0, '1.0', 'сек', 'error'],
    [1240.0, '1.2', 'сек', 'error'],
    [9940.0, '9.9', 'сек', 'error'],
    [9960.0, '10', 'сек', 'error'],
    [10001.0, '10', 'сек', 'error'],
    [120000.0, '100', 'сек', 'error'],
]);
