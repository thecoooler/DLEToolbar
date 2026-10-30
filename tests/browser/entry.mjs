import assert from 'node:assert/strict';

export async function testEntry(browser, url) {
    const page = await browser.newPage({ reducedMotion: 'reduce' });
    try {
        const fixture = new URL(url);
        fixture.searchParams.set('entry', '1');
        await page.addInitScript(() => sessionStorage.setItem('dletoolbar:intro-shown', '1'));
        await page.goto(fixture.href);
        const button = page.locator('[data-entry-open]');
        const panel = page.locator('[data-entry-panel]');
        const main = page.locator('[data-entry-tab="main"]');
        const seo = page.locator('[data-entry-tab="seo"]');
        await button.focus();
        await page.keyboard.press('Enter');
        assert.equal(await main.evaluate((tab) => tab.getRootNode().activeElement === tab), true,
            'Opening the entry panel focuses the selected tab');
        await page.keyboard.press('ArrowRight');
        assert.equal(await seo.evaluate((tab) => tab.getRootNode().activeElement === tab), true,
            'Arrow keys move focus between tabs');
        await page.keyboard.press('Escape');
        assert.equal(await panel.isHidden(), true, 'Escape closes the entry panel');
        assert.equal(await button.evaluate((element) => element.getRootNode().activeElement === element), true,
            'Escape restores focus to the opener');
        await page.keyboard.press('Enter');
        assert.equal(await seo.evaluate((tab) => tab.getRootNode().activeElement === tab), true,
            'Reopening focuses the previously selected tab');
        await page.keyboard.press('Tab');
        assert.equal(await panel.locator('[role="tabpanel"]:not([hidden])')
            .evaluate((element) => element.getRootNode().activeElement === element), true,
            'Tab reaches the selected panel');
        console.log('PASS: entry keyboard focus, tabs and Escape');
    } finally {
        await page.close();
    }
}
