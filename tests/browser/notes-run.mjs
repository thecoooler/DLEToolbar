// Run against tests/browser served by PHP: NODE_PATH=... node tests/browser/notes-run.mjs
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
const { chromium } = createRequire(import.meta.url)('playwright');
const browser = await chromium.launch({ headless: true });
try {
    for (const [width, version, endpoint] of [
        [1280, '18.0', '**/engine/ajax/controller.php?mod=dletoolbar_notes'],
        [375, '19.0', '**/index.php?controller=ajax&mod=dletoolbar_notes'],
    ]) {
        const page = await browser.newPage({ viewport: { width, height: 800 }, reducedMotion: 'reduce' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let notes = [];
        let fail = false;
        await page.route(endpoint, async route => {
            const input = new URLSearchParams(route.request().postData());
            assert.equal(input.get('user_hash'), 'browser-test-token');
            const action = input.get('action');
            if (fail) {
                fail = false;
                return route.fulfill({ status: 500, json: { error: 'Ошибка сохранения' } });
            }
            if (action === 'save') {
                const id = Number(input.get('id')) || notes.length + 1;
                const old = notes.find(note => note.id === id);
                notes = notes.filter(note => note.id !== id);
                notes.push({ id, title: input.get('title'), body: input.get('body'), page_url: input.get('page_url'), pinned: old?.pinned || 0 });
            }
            if (action === 'pin') notes.find(note => note.id === Number(input.get('id'))).pinned = Number(input.get('pinned'));
            if (action === 'delete') notes = notes.filter(note => note.id !== Number(input.get('id')));
            notes.sort((a, b) => b.pinned - a.pinned || b.id - a.id);
            await route.fulfill({ json: { notes } });
        });
        const fixture = new URL(process.argv[2] ?? 'http://127.0.0.1:8002/notes-panel.php?test=1#anchor');
        fixture.searchParams.set('dle_version', version);
        await page.goto(fixture.href);
        await page.locator('[data-notes-trigger]').click();
        await page.getByText('Заметок пока нет.').waitFor();
        for (const height of [800, 480]) {
            await page.setViewportSize({ width, height });
            const notesBounds = await page.locator('.notes-panel').boundingBox();
            await page.locator('[data-queries-open]').click();
            const queryBounds = await page.locator('.query-panel').boundingBox();
            assert.equal(notesBounds.width, queryBounds.width, `panel widths at ${width}×${height}`);
            assert.equal(notesBounds.height, queryBounds.height, `panel heights at ${width}×${height}`);
            await page.locator('[data-notes-trigger]').click();
        }
        await page.setViewportSize({ width, height: 800 });
        await page.getByRole('button', { name: 'Добавить', exact: true }).click();
        const title = '<img src=x onerror=alert(1)> ' + 'Длинное название '.repeat(9);
        await page.locator('input[name=title]').fill(title);
        await page.locator('textarea').fill('Текст\nзаметки <script>alert(1)</script>');
        await page.locator('input[type=checkbox]').check();
        fail = true;
        await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
        await page.getByText('Ошибка сохранения', { exact: true }).waitFor();
        assert.equal(await page.locator('input[name=title]').inputValue(), title);
        await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
        await page.locator('.notes-row').waitFor();
        assert.equal(notes[0].page_url, fixture.pathname + fixture.search + fixture.hash);
        const row = page.locator('.notes-row');
        const bounds = await row.boundingBox();
        assert.equal(bounds.height, 36);
        assert(bounds.width <= width - 24);
        assert.equal(await page.locator('.notes-panel img, .notes-panel script').count(), 0);
        await page.locator('.notes-pin').click();
        await page.locator('.notes-pin[aria-pressed=true]').waitFor();
        await page.locator('.notes-title').click();
        assert.equal(await page.locator('textarea').inputValue(), 'Текст\nзаметки <script>alert(1)</script>');
        await page.locator('input[name=title]').fill('Исправлено');
        await page.locator('input[type=checkbox]').uncheck();
        await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
        await page.getByRole('button', { name: 'Исправлено', exact: true }).waitFor();
        assert.equal(notes[0].page_url, '');
        assert.equal(notes[0].pinned, 1);
        page.on('dialog', dialog => dialog.accept());
        await page.locator('.notes-delete').click();
        await page.getByText('Заметок пока нет.').waitFor();
        await page.locator('[data-notes-add]').focus();
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('[data-notes-trigger]').getAttribute('aria-expanded'), 'false');
        assert.deepEqual(errors, []);
        await page.close();
        console.log(`PASS: DLE ${version} notes CRUD, pin, binding, retry, escaping, one-line row and Escape at ${width}px`);
    }
} finally {
    await browser.close();
}
