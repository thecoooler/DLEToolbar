// Run against tests/browser served by PHP: NODE_PATH=... node tests/browser/notes-run.mjs
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
const { chromium } = createRequire(import.meta.url)('playwright');
const browser = await chromium.launch({ headless: true });
const headerStyle = element => {
    const header = getComputedStyle(element);
    const title = getComputedStyle(element.querySelector('h2'));
    const close = getComputedStyle(element.querySelector('button:last-child'));
    return [header.height, header.padding, header.gap, header.borderBottomWidth,
        title.font, title.letterSpacing, close.width, close.height, close.borderRadius];
};
try {
    for (const [width, version, endpoint] of [
        [1280, '18.0', '**/engine/ajax/controller.php?mod=dletoolbar_notes'],
        [375, '19.0', '**/index.php?controller=ajax&mod=dletoolbar_notes'],
    ]) {
        const page = await browser.newPage({ viewport: { width, height: 800 }, reducedMotion: 'reduce', colorScheme: 'light', timezoneId: 'America/Los_Angeles' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let notes = [];
        let nextId = 1;
        let fail = false;
        let hold = false;
        let held;
        let signalHeld;
        const requests = [];
        const pauseNext = () => {
            hold = true;
            return new Promise(resolve => { signalHeld = resolve; });
        };
        const respond = async (route, input) => {
            const action = input.get('action');
            if (fail) {
                fail = false;
                return route.fulfill({ status: 500, json: { error: 'Ошибка сохранения' } });
            }
            if (action === 'save') {
                const id = Number(input.get('id')) || nextId++;
                const previous = notes.find(note => note.id === id);
                notes = notes.filter(note => note.id !== id);
                notes.unshift({ id, title: input.get('title').trim(), created_at: previous ? previous.created_at : '2026-10-30 00:15:00' });
            }
            if (action === 'delete') notes = notes.filter(note => note.id !== Number(input.get('id')));
            await route.fulfill({ json: { notes } });
        };
        await page.route(endpoint, async route => {
            const input = new URLSearchParams(route.request().postData());
            assert.equal(input.get('user_hash'), 'browser-test-token');
            assert.equal(input.has('body') || input.has('pinned') || input.has('page_url'), false);
            requests.push(input.get('action'));
            if (hold) {
                hold = false;
                held = () => respond(route, input);
                signalHeld();
                return;
            }
            await respond(route, input);
        });
        const fixture = new URL(process.argv[2] ?? 'http://127.0.0.1:8002/notes-panel.php?test=1#anchor');
        fixture.searchParams.set('dle_version', version);
        await page.goto(fixture.href);
        const trigger = page.locator('[data-notes-trigger]');
        const input = page.getByRole('textbox', { name: 'Новая заметка', exact: true });
        const add = page.getByRole('button', { name: 'Добавить', exact: true });
        const idle = () => page.locator('.notes-panel[aria-busy=false]').waitFor({ state: 'attached' });
        const focused = locator => locator.evaluate(element => element.getRootNode().activeElement === element);
        await trigger.click();
        await page.getByText('Заметок пока нет.').waitFor();
        assert.equal(await focused(input), true);
        assert.equal(await page.locator('.notes-panel textarea, .notes-panel input[type=checkbox], .notes-page').count(), 0);
        await page.keyboard.press('Escape');
        assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
        assert.equal(await focused(trigger), true);
        await trigger.click();
        await idle();
        await trigger.focus();
        await page.keyboard.press('Escape');
        assert.equal(await trigger.getAttribute('aria-expanded'), 'false');

        // Closing remains available while a list request is still in flight.
        const initialHold = pauseNext();
        await trigger.click();
        await initialHold;
        assert.equal(await page.locator('[data-notes-close]').isEnabled(), true);
        await page.locator('[data-notes-close]').click();
        assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
        await held();
        await idle();
        assert.equal(await focused(trigger), true);
        await trigger.click();
        await idle();

        // SQL and notes use the same panel and header dimensions, including short viewports.
        for (const height of [800, 480]) {
            await page.setViewportSize({ width, height });
            const notesBounds = await page.locator('.notes-panel').boundingBox();
            const notesHeader = await page.locator('.notes-header').evaluate(headerStyle);
            const entry = await input.boundingBox();
            const submit = await add.boundingBox();
            assert(Math.abs(entry.y + entry.height / 2 - submit.y - submit.height / 2) < 1);
            assert(entry.x + entry.width <= submit.x);
            await page.locator('[data-queries-open]').click();
            const queryBounds = await page.locator('.query-panel').boundingBox();
            assert.equal(notesBounds.width, queryBounds.width);
            assert.equal(notesBounds.height, queryBounds.height);
            assert.deepEqual(notesHeader, await page.locator('.query-header').evaluate(headerStyle));
            await trigger.click();
            await idle();
        }
        await page.setViewportSize({ width, height: 800 });
        const border = await input.evaluate(element => {
            element.blur();
            const plain = getComputedStyle(element).borderColor;
            element.focus();
            return { plain, focused: getComputedStyle(element).borderColor, outline: getComputedStyle(element).outlineStyle };
        });
        assert.equal(border.outline, 'none');
        assert.notEqual(border.plain, border.focused);
        const title = ('<img src=x onerror=alert(1)> ' + 'Длинная заметка '.repeat(9)).trim();
        await input.fill(title);
        fail = true;
        await input.press('Enter');
        await page.getByText('Ошибка сохранения', { exact: true }).waitFor();
        assert.equal(await input.inputValue(), title);
        assert.equal(await focused(input), true);
        await add.click();
        await page.locator('.notes-row').waitFor();
        assert.equal(await input.inputValue(), '');
        assert.equal(await focused(input), true);
        const row = page.locator('.notes-row');
        assert.equal((await row.boundingBox()).height, 36);
        assert.equal(await row.locator(':scope > :first-child').getAttribute('class'), 'notes-date');
        assert.equal(await row.locator('.notes-date').textContent(), '30.10.2026');
        assert.equal(await row.locator('time').getAttribute('datetime'), '2026-10-30T00:15:00');
        const dateBounds = await row.locator('.notes-date').boundingBox();
        const textBounds = await row.locator('.notes-title').boundingBox();
        assert(dateBounds.x + dateBounds.width <= textBounds.x);
        assert(textBounds.width > 0);
        assert.equal(await row.locator('a, .notes-pin').count(), 0);
        assert.equal(await page.locator('.notes-panel img, .notes-panel script').count(), 0);
        const borders = await row.evaluate(element => {
            const last = getComputedStyle(element).borderBottomWidth;
            const extra = element.cloneNode(true);
            element.parentElement.append(extra);
            const first = getComputedStyle(element).borderBottomWidth;
            extra.remove();
            return { first, last, radius: getComputedStyle(element).borderRadius };
        });
        assert.deepEqual(borders, { first: '1px', last: '0px', radius: '6px' });
        const padding = await page.locator('.notes-content').evaluate(element => {
            const style = getComputedStyle(element);
            return [style.paddingTop, style.paddingRight, style.paddingBottom, style.paddingLeft];
        });
        assert.equal(new Set(padding).size, 1);
        await page.locator('.notes-title').hover();
        const colors = await row.evaluate(element => ({
            row: getComputedStyle(element).backgroundColor,
            title: getComputedStyle(element.querySelector('.notes-title')).backgroundColor,
            remove: getComputedStyle(element.querySelector('.notes-delete')).backgroundColor,
        }));
        assert.equal(colors.row, 'rgb(244, 244, 245)');
        assert.equal(colors.title, 'rgba(0, 0, 0, 0)');
        assert.equal(colors.remove, 'rgba(0, 0, 0, 0)');

        // Editing stays in its row; the addition field is always available.
        await page.locator('.notes-title').click();
        const editor = page.getByRole('textbox', { name: 'Изменить заметку', exact: true });
        assert.equal(await editor.inputValue(), title);
        assert.equal(await row.locator('.notes-date').textContent(), '30.10.2026');
        assert.equal(await input.isVisible(), true);
        await editor.fill('Не сохранять');
        await editor.press('Escape');
        assert.equal(await trigger.getAttribute('aria-expanded'), 'true');
        assert.equal(await page.locator('.notes-title').textContent(), title);
        assert.equal(await focused(page.locator('.notes-title')), true);
        await page.locator('.notes-title').click();
        await editor.fill('Исправлено');
        fail = true;
        await editor.press('Enter');
        await page.getByText('Ошибка сохранения', { exact: true }).waitFor();
        assert.equal(await editor.inputValue(), 'Исправлено');
        assert.equal(await focused(editor), true);
        await editor.press('Enter');
        await page.getByRole('button', { name: 'Исправлено', exact: true }).waitFor();
        assert.equal(await row.locator('.notes-date').textContent(), '30.10.2026');
        assert.equal(await focused(page.locator('.notes-title')), true);
        await page.locator('.notes-title').click();
        const editHold = pauseNext();
        await editor.press('Enter');
        await editHold;
        await editor.press('Escape');
        assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
        await held();
        await idle();
        assert.equal(await focused(trigger), true);
        await trigger.click();
        await idle();

        // A pending save cannot be submitted twice, and typing another draft does not lose it.
        await input.fill('Вторая заметка');
        const savesBefore = requests.filter(action => action === 'save').length;
        const saveHold = pauseNext();
        await input.press('Enter');
        await saveHold;
        await input.press('Enter');
        await add.focus();
        await page.keyboard.press('Enter');
        assert.equal(requests.filter(action => action === 'save').length, savesBefore + 1);
        assert.equal(await focused(add), true);
        await input.fill('Следующая заметка');
        await page.locator('[data-notes-close]').click();
        await held();
        await idle();
        assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
        assert.equal(await focused(trigger), true);
        assert.equal(await page.locator('.notes-compose input').inputValue(), 'Следующая заметка');

        // Reopening refreshes edits made by another administrator.
        notes.unshift({ id: nextId++, title: 'От другого администратора', created_at: null });
        const listsBefore = requests.filter(action => action === 'list').length;
        await trigger.click();
        await page.getByRole('button', { name: 'От другого администратора', exact: true }).waitFor();
        assert.equal(await page.locator('.notes-row').first().locator('.notes-date').textContent(), '—');
        assert.equal(requests.filter(action => action === 'list').length, listsBefore + 1);
        page.on('dialog', dialog => dialog.accept());
        await page.getByRole('button', { name: 'Удалить: От другого администратора', exact: true }).click();
        await idle();
        assert.equal(await page.getByRole('button', { name: 'От другого администратора', exact: true }).count(), 0);
        assert.equal(await focused(page.getByRole('button', { name: 'Вторая заметка', exact: true })), true);
        await page.getByRole('button', { name: 'Удалить: Вторая заметка', exact: true }).click();
        await idle();
        await page.getByRole('button', { name: 'Удалить: Исправлено', exact: true }).click();
        await page.getByText('Заметок пока нет.').waitFor();
        assert.equal(await focused(input), true);
        await page.keyboard.press('Escape');
        assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
        assert.deepEqual(errors, []);
        await page.close();
        console.log(`PASS: DLE ${version}, ${width}px — simple notes, inline edits, focus, slow requests, retry, refresh and panel layout`);
    }
} finally {
    await browser.close();
}
