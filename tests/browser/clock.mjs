import assert from 'node:assert/strict';

export async function testClock(browser, url) {
    for (const [mode, reducedMotion] of [
        ['seconds', 'no-preference'], ['minutes', 'no-preference'], ['minutes', 'reduce'],
    ]) {
        const page = await browser.newPage({ timezoneId: 'UTC', reducedMotion });
        try {
            const fixture = new URL(url);
            fixture.searchParams.set('clock', mode);
            await page.addInitScript(() => sessionStorage.setItem('dletoolbar:intro-shown', '1'));
            await page.clock.install({ time: new Date('2026-10-26T23:59:53Z') });
            await page.goto(fixture.href);
            await page.clock.pauseAt(new Date('2026-10-26T23:59:58Z'));
            const control = page.locator('[data-component="clock"]');
            const snapshot = () => control.evaluate((element) => {
                const root = element.getRootNode();
                const time = element.querySelector('time');
                return {
                    time: time.dateTime,
                    label: element.getAttribute('aria-label'),
                    hours: time.querySelector('[data-clock-part="hour"] [data-clock-current]').textContent,
                    minutes: time.querySelector('[data-clock-part="minute"] [data-clock-current]').textContent,
                    seconds: time.querySelector('[data-clock-part="second"]')?.textContent,
                    animations: [...time.querySelectorAll('.clock-number')]
                        .map((number) => number.getAnimations({ subtree: true }).length),
                    tooltip: root.querySelector('[data-clock-tooltip]')?.dateTime,
                    tooltipText: [...root.querySelectorAll('[data-clock-tooltip] [data-clock-current]')]
                        .map((number) => number.textContent).join(':'),
                    tooltipAnimations: [...root.querySelectorAll('[data-clock-tooltip] .clock-number')]
                        .map((number) => number.getAnimations({ subtree: true }).length),
                    tooltipWidth: root.querySelector('[data-tooltip]').getBoundingClientRect().width,
                    tooltipOpen: root.querySelector('[data-tooltip]').dataset.open === 'true',
                    width: time.getBoundingClientRect().width,
                    icons: element.querySelectorAll('svg').length,
                };
            });
            const initial = await snapshot();
            assert.equal(initial.time, mode === 'seconds' ? '23:59:58' : '23:59');
            assert.deepEqual(initial.animations, [0, 0], 'Initial render has no flip');
            assert.equal(initial.icons, 0, 'Clock has no icon');
            await page.clock.runFor(1000);
            assert.deepEqual((await snapshot()).animations, [0, 0], 'Second ticks do not flip hours or minutes');
            await control.hover();
            await page.clock.runFor(350);
            const hovered = await snapshot();
            assert.equal(hovered.tooltipOpen, true);
            assert.equal(hovered.tooltip, '23:59:59');
            assert.equal(hovered.tooltipText, '23:59:59', 'Tooltip contains only time with seconds');
            assert.deepEqual(hovered.tooltipAnimations, [0, 0, 0], 'Opening tooltip does not flip');

            await page.clock.runFor(650);
            const midnight = await snapshot();
            assert.equal(midnight.hours, '00');
            assert.equal(midnight.minutes, '00');
            assert.equal(midnight.seconds, mode === 'seconds' ? '00' : undefined);
            assert.deepEqual(midnight.animations.map((count) => count > 0),
                reducedMotion === 'reduce' ? [false, false] : [true, true]);
            assert.equal(midnight.tooltipOpen, true);
            assert.equal(midnight.tooltip, midnight.label, 'Open tooltip updates with the clock');
            assert.equal(midnight.tooltip, '00:00:00');
            assert.equal(midnight.tooltipText, '00:00:00');
            assert.deepEqual(midnight.tooltipAnimations.map((count) => count > 0),
                reducedMotion === 'reduce' ? [false, false, false] : [true, true, true]);
            assert.equal(midnight.tooltipWidth, hovered.tooltipWidth, 'Tooltip width stays fixed during flips');
            assert.equal(midnight.width, initial.width, 'Flipping preserves clock width');

            if (reducedMotion !== 'reduce') {
                const positions = await control.evaluate((element) => [...element.getRootNode()
                    .querySelectorAll('.clock-number')].map((number) => {
                    number.getAnimations({ subtree: true }).forEach((animation) => {
                        animation.pause();
                        animation.currentTime = 160;
                    });
                    const slot = number.getBoundingClientRect();
                    const current = number.querySelector('[data-clock-current]').getBoundingClientRect();
                    const previous = number.querySelector('[data-clock-previous]').getBoundingClientRect();
                    return {
                        oldBelow: previous.top > slot.top && previous.bottom > slot.bottom,
                        newAbove: current.top < slot.top && current.bottom < slot.bottom,
                        clipped: getComputedStyle(number).overflow === 'hidden',
                    };
                }));
                assert.equal(positions.length, 5);
                assert.ok(positions.every(({ oldBelow, newAbove, clipped }) => oldBelow && newAbove && clipped),
                    'In the clock and tooltip, old numbers fall below while new numbers enter from above');
            }

            await control.evaluate((element) => element.getRootNode().querySelectorAll('.clock-number').forEach((number) => {
                number.getAnimations({ subtree: true }).forEach((animation) => animation.finish());
            }));
            await page.clock.runFor(1000);
            const second = await snapshot();
            assert.equal(second.tooltip, '00:00:01', 'Tooltip seconds advance in both modes');
            assert.deepEqual(second.tooltipAnimations.map((count) => count > 0),
                reducedMotion === 'reduce' ? [false, false, false] : [false, false, true],
                'Only the changed second flips in the tooltip');
            await control.evaluate((element) => element.getRootNode().querySelectorAll('.clock-number').forEach((number) => {
                number.getAnimations({ subtree: true }).forEach((animation) => animation.finish());
            }));
            await page.clock.fastForward(59000);
            const minute = await snapshot();
            assert.equal(minute.minutes, '01');
            assert.equal(minute.animations[0], 0, 'Unchanged hour does not flip');
            assert.equal(minute.animations[1] > 0, reducedMotion !== 'reduce', 'Changed minute flips');

            await page.mouse.move(0, 0);
            await page.clock.runFor(350);
            await page.locator('#outside').focus();
            await page.keyboard.press('Tab');
            const focused = await snapshot();
            assert.equal(focused.tooltipOpen, true, 'Keyboard focus opens the time tooltip');
            assert.equal(focused.tooltip, focused.label);
            console.log(`PASS: clock ${mode}, motion ${reducedMotion}, rollover and animated time tooltip`);
        } finally {
            await page.close();
        }
    }
}
