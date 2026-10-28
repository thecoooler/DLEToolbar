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

    // Switching after a login response must use GET, even when the URL has a fragment.
    const loginPage = await browser.newPage();
    const requests = [];
    loginPage.on('request', request => {
        if (request.isNavigationRequest()) requests.push(request);
    });
    loginPage.on('dialog', dialog => dialog.dismiss());
    await loginPage.goto(url);
    const target = new URL(url);
    target.search = '?navigation-test=1&item=one&item=two&submit=kept';
    target.hash = '#section';
    await Promise.all([
        loginPage.waitForURL(target.href),
        loginPage.evaluate(targetUrl => {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = targetUrl;
            document.body.append(form);
            form.submit();
        }, target.href),
    ]);
    if (requests.at(-1).method() !== 'POST') throw new Error('Login fixture did not submit POST');
    await loginPage.locator('[data-skin-trigger]').click();
    const [switchRequest] = await Promise.all([
        loginPage.waitForRequest(request => request.isNavigationRequest() && request.method() === 'GET'),
        loginPage.locator('[data-skin-option][data-skin-value="Studio"]').click(),
    ]);
    await loginPage.waitForLoadState('load');
    if (switchRequest.method() !== 'GET') throw new Error('Template switch repeated the login POST');
    target.hash = '';
    if (loginPage.url() !== target.href) throw new Error(`Template switch changed URL: ${loginPage.url()}`);
    const cookie = (await switchRequest.allHeaders()).cookie ?? '';
    const temporaryCookie = cookie.split('; ').find(value => value.startsWith('dle_toolbar_skin='));
    if (!temporaryCookie) throw new Error('Template switch omitted the temporary cookie');
    const selection = JSON.parse(decodeURIComponent(temporaryCookie.slice('dle_toolbar_skin='.length)));
    if (selection.skin !== 'Studio' || selection.token !== 'browser-test-user-hash' || selection.fragment !== '#section') throw new Error('Template switch sent an incorrect selection');
    await loginPage.close();
    console.log('PASS: template switch after login uses GET and preserves query and passes fragment');
} catch (error) {
    console.error(error.stack ?? error.message);
    process.exitCode = 1;
} finally {
    await browser?.close();
}
