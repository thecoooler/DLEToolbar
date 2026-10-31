import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { testClock } from './clock.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const url = process.argv[2] ?? 'http://127.0.0.1:8001/queries-panel.php';
let browser;

try {
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: Number(process.argv[3] ?? 1280), height: 800 } });
    page.on('pageerror', error => console.error(error.stack ?? error.message));
    await page.goto(url, { timeout: 30_000 });
    await page.waitForFunction(() => window.queriesPanelTestResult, null, { timeout: 30_000 });
    const { status, assertions, error } = await page.evaluate(() => window.queriesPanelTestResult);
    console.log(`${status} (${assertions} assertions)`);
    if (error) console.error(error);
    if (status !== 'PASS') process.exitCode = 1;

    if (status === 'PASS') {
        // Use real pointer input: synthetic pointer events cannot establish pointer capture.
        await page.evaluate(() => {
            const root = document.querySelector('[data-dle-toolbar]').shadowRoot;
            const panel = root.querySelector('[data-query-panel]');
            if (panel.hidden) root.querySelector('[data-queries-open]').click();
            panel.style.height = '280px';
            root.querySelector('[data-copy-status]').textContent = '';
            root.querySelectorAll('[data-fold]').forEach((fold) => {
                if (fold.getAttribute('aria-expanded') !== 'true') fold.click();
            });
            root.querySelector('.query-list').scrollTop = 0;
        });
        const list = page.locator('.query-list');
        const track = page.locator('[data-query-scrollbar]');
        const thumb = page.locator('.query-scroll-thumb');
        await track.waitFor({ state: 'visible' });
        const thumbBox = await thumb.boundingBox();
        const trackBox = await track.boundingBox();
        await page.mouse.move(thumbBox.x + thumbBox.width / 2, thumbBox.y + thumbBox.height / 2);
        await page.mouse.down();
        await page.mouse.move(thumbBox.x + thumbBox.width / 2, trackBox.y + trackBox.height - 1, { steps: 8 });
        await page.mouse.up();
        if (await list.evaluate((element) => element.scrollTop) <= 0) throw new Error('Thumb drag did not scroll');
        await track.click({ position: { x: 5, y: 1 } });
        await page.waitForFunction(() => document.querySelector('[data-dle-toolbar]').shadowRoot.querySelector('.query-list').scrollTop === 0);
        await page.mouse.move(trackBox.x + 5, trackBox.y + 5);
        await page.mouse.wheel(0, 40);
        await page.waitForFunction(() => document.querySelector('[data-dle-toolbar]').shadowRoot.querySelector('.query-list').scrollTop > 0);
        await page.evaluate(() => {
            const root = document.querySelector('[data-dle-toolbar]').shadowRoot;
            root.querySelector('[data-query-panel]').style.height = '';
            root.querySelectorAll('[data-fold]').forEach((fold) => {
                if (fold.getAttribute('aria-expanded') === 'true') fold.click();
            });
        });
        await track.waitFor({ state: 'hidden' });
        console.log('PASS: scrollbar drag, track click, wheel and hiding when content fits');

        await page.locator('[data-files-open]').click();
        const layout = await page.locator('.files-event').first().evaluate((event) => ({
            time: event.querySelector('.files-times').getBoundingClientRect().right,
            rail: event.querySelector('.files-rail-dot').getBoundingClientRect().left,
            content: event.querySelector('.files-event-body').getBoundingClientRect().left,
        }));
        if (!(layout.time < layout.rail && layout.rail < layout.content)) {
            throw new Error('Timeline must place time left of the rail and content right of it');
        }
        const firstDetails = page.locator('.files-details').first();
        if (!await firstDetails.evaluate((details) => details.open)) {
            await firstDetails.locator('.files-toggle').click();
        }
        const diagnosticsPanel = page.locator('#dle-toolbar-files-panel');
        await diagnosticsPanel.evaluate((panel) => { panel.style.maxHeight = '280px'; });
        const fileTimeline = page.locator('#dle-toolbar-files-timeline');
        await fileTimeline.focus();
        await fileTimeline.press('End');
        await page.waitForFunction(() => document.querySelector('[data-dle-toolbar]').shadowRoot
            .querySelector('#dle-toolbar-files-timeline').scrollTop > 0);
        await fileTimeline.press('Home');
        await page.waitForFunction(() => document.querySelector('[data-dle-toolbar]').shadowRoot
            .querySelector('#dle-toolbar-files-timeline').scrollTop === 0);
        const fileTrack = page.locator('[data-files-scrollbar]');
        await fileTrack.waitFor({ state: 'visible' });
        const fileThumb = page.locator('.files-scroll-thumb');
        const fileThumbBox = await fileThumb.boundingBox();
        const fileTrackBox = await fileTrack.boundingBox();
        await page.mouse.move(fileThumbBox.x + 2, fileThumbBox.y + 5);
        await page.mouse.down();
        await page.mouse.move(fileThumbBox.x + 2, fileTrackBox.y + fileTrackBox.height - 2, { steps: 8 });
        await page.mouse.up();
        if (await fileTimeline.evaluate((element) => element.scrollTop) <= 0) throw new Error('Diagnostics thumb did not scroll');
        await fileTrack.click({ position: { x: 5, y: 1 } });
        await fileTimeline.focus();
        await fileTimeline.press('Home');
        await diagnosticsPanel.evaluate((panel) => { panel.style.maxHeight = ''; });
        console.log('PASS: keyboard scrolls the file timeline');
        for (const layout of ['grid', 'waterfall', 'rail']) {
            await page.locator(`[data-files-layout="${layout}"]`).click();
            const state = await diagnosticsPanel.evaluate((panel) => ({
                layout: panel.dataset.layout,
                selected: panel.querySelector('[role="tab"][aria-selected="true"]').dataset.filesLayout,
                open: panel.querySelector('.files-details').open,
                width: panel.querySelector('.files-scroll').clientWidth,
                scrollWidth: panel.querySelector('.files-scroll').scrollWidth,
            }));
            if (state.layout !== layout || state.selected !== layout || !state.open) {
                throw new Error('Layout switching lost the active tab or expanded files: ' + JSON.stringify(state));
            }
            if (state.scrollWidth > state.width + 1) throw new Error('Diagnostics layout overflows horizontally');
        }
        await page.locator('[data-files-layout="rail"]').focus();
        await page.keyboard.press('ArrowRight');
        if (await page.locator('[data-files-layout="grid"]').getAttribute('aria-selected') !== 'true') {
            throw new Error('Layout tabs do not support arrow navigation');
        }
        await page.keyboard.press('Home');
        console.log('PASS: three layouts preserve files, fit the viewport and support keyboard tabs');
        const evidenceDir = process.env.DLETOOLBAR_SCREENSHOT_DIR;
        if (evidenceDir) {
            await mkdir(evidenceDir, { recursive: true });
            await page.emulateMedia({ reducedMotion: 'reduce' });
            await page.locator('.files-details').evaluateAll((items) => items.forEach((item) => { item.open = false; }));
            await page.locator('[data-files-open]').focus();
            for (const layout of ['rail', 'grid', 'waterfall']) {
                await page.locator(`[data-files-layout="${layout}"]`).click();
                await page.screenshot({ path: `${evidenceDir}/diagnostics-${layout}-${page.viewportSize().width}.png` });
            }
        }

    }

    // Template print styles cannot override the shadow :host rule, which wins with !important.
    await page.addStyleTag({ content: '@media print { [data-dle-toolbar] { display: block !important; } }' });
    await page.emulateMedia({ media: 'print' });
    const printDisplay = await page.evaluate(() => getComputedStyle(document.querySelector('[data-dle-toolbar]')).display);
    console.log(`print display: ${printDisplay}`);
    if (printDisplay !== 'none') process.exitCode = 1;
    await testClock(browser, url);
} catch (error) {
    console.error(error.stack ?? error.message);
    process.exitCode = 1;
} finally {
    await browser?.close();
}
