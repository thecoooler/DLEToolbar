import { createRequire } from 'node:module';
import { mkdir } from 'node:fs/promises';
import { testClock } from './clock.mjs';
import { testEntry } from './entry.mjs';

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
        await firstDetails.locator('.files-toggle').click();
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
        const evidenceDir = process.env.DLETOOLBAR_SCREENSHOT_DIR;
        if (evidenceDir) {
            await mkdir(evidenceDir, { recursive: true });
            await page.emulateMedia({ reducedMotion: 'reduce' });
            await page.locator('[data-files-open]').focus();
            await page.screenshot({ path: `${evidenceDir}/diagnostics-timeline-${page.viewportSize().width}.png` });
        }

    }

    // Template print styles cannot override the shadow :host rule, which wins with !important.
    await page.addStyleTag({ content: '@media print { [data-dle-toolbar] { display: block !important; } }' });
    await page.emulateMedia({ media: 'print' });
    const printDisplay = await page.evaluate(() => getComputedStyle(document.querySelector('[data-dle-toolbar]')).display);
    console.log(`print display: ${printDisplay}`);
    if (printDisplay !== 'none') process.exitCode = 1;
    await testClock(browser, url);
    await testEntry(browser, url);
    await page.emulateMedia({ media: 'screen' });
    const settings = page.locator('[data-settings-panel]');
    const settingsButton = page.locator('[data-settings-open]');
    const settingsFrame = settings.locator('iframe');
    const settingsAdmin = settings.locator('[data-settings-admin]');
    if (await settingsFrame.getAttribute('src') !== null) throw new Error('Settings iframe loaded before opening');
    await settingsButton.click();
    if (!await settings.isVisible() || await settingsButton.getAttribute('aria-expanded') !== 'true') throw new Error('Settings panel did not open');
    if (!await settingsFrame.getAttribute('src')) throw new Error('Settings iframe did not load');
    const adminHref = await settingsAdmin.getAttribute('href');
    if (await settingsAdmin.getAttribute('target') !== '_blank' || !adminHref.endsWith('/admin.php?mod=dletoolbar')) throw new Error('Settings panel does not link to the admin page');
    const settingsDocument = settingsFrame.contentFrame();
    await settingsDocument.locator('#dlt-settings-form').waitFor({ state: 'visible', timeout: 10_000 });
    const settingsAction = await settingsDocument.locator('#dlt-settings-form').getAttribute('action');
    if (new URL(settingsAction, url).searchParams.get('dlt_embed') !== '1') throw new Error('Embedded settings form did not keep the embedded mode');
    const closeFocused = await settingsButton.evaluate((element) => element.getRootNode().activeElement === element.getRootNode().querySelector('[data-settings-close]'));
    if (!closeFocused) throw new Error('Settings panel did not move focus to its close button');
    await settings.locator('[data-settings-close]').click();
    await settings.waitFor({ state: 'hidden' });
    await settingsButton.click();
    if (!await settings.isVisible()) throw new Error('Settings panel did not reopen');
    await page.keyboard.press('Escape');
    await settings.waitFor({ state: 'hidden' });
    const triggerFocused = await settingsButton.evaluate((element) => element.getRootNode().activeElement === element);
    if (!triggerFocused) throw new Error('Escape did not restore focus to the settings button');
    await settingsButton.click();
    const panelBox = await settings.boundingBox();
    if (panelBox.x < 8) throw new Error('Settings panel has no left gutter to click outside');
    await page.mouse.click(panelBox.x - 6, panelBox.y + 6);
    await settings.waitFor({ state: 'hidden' });
    await settingsButton.click();
    await settingsDocument.locator('body').evaluate(() => window.parent.postMessage('dletoolbar-settings-close', window.location.origin));
    await settings.waitFor({ state: 'hidden' });
    await settingsButton.click();
    await page.locator('[data-queries-open]').click();
    await settings.waitFor({ state: 'hidden' });
    console.log('PASS: settings panel lazy loading, real form, escape, focus, click outside, closing, reopening and popup switching');

    await settingsButton.click();
    const saveRequest = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/admin.php'));
    const reload = page.waitForNavigation();
    await settingsDocument.locator('#dlt-settings-form button[type="submit"]').click();
    const saveResponse = await saveRequest;
    const saveLocation = saveResponse.headers()['location'];
    if (saveResponse.status() !== 302 || !saveLocation || new URL(saveLocation, saveResponse.url()).searchParams.get('dlt_embed') !== '1') throw new Error('Embedded save did not redirect with the embedded mode');
    await reload;
    console.log('PASS: embedded save redirects and the page reloads in the embedded mode');
} catch (error) {
    console.error(error.stack ?? error.message);
    process.exitCode = 1;
} finally {
    await browser?.close();
}
