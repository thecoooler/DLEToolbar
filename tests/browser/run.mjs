import { createRequire } from 'node:module';
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
