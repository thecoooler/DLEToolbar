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
        await page.clock.setFixedTime(new Date('2026-10-29T12:00:00Z'));
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let notes = [];
        let nextId = 1;
        let fail = false;
        let failNextList = false;
        let loseNextSave = false;
        const keys = new Map();
        const payloads = [];
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
            if (action === 'list') {
                if (failNextList) {
                    failNextList = false;
                    return route.fulfill({ status: 500, json: { error: 'Ошибка загрузки' } });
                }
                const after = input.has('before_id') ? notes.findIndex(note => note.id === Number(input.get('before_id'))) + 1 : 0;
                return route.fulfill({ json: { notes: notes.slice(after, after + 50), has_more: notes.length > after + 50 } });
            }
            if (action === 'save') {
                const requestKey = input.get('request_key');
                if (requestKey && keys.has(requestKey)) return route.fulfill({ json: { ok: true } });
                const id = Number(input.get('id')) || nextId++;
                const previous = notes.find(note => note.id === id);
                if (previous) assert.equal(input.get('original_title'), previous.title);
                if (requestKey) keys.set(requestKey, id);
                notes = notes.filter(note => note.id !== id);
                notes.unshift({ id, title: input.get('title').trim(), created_at: previous ? previous.created_at : '2026-10-30 00:15:00', created_days_ago: previous ? previous.created_days_ago : 0, updated_at: '2026-10-30 00:15:00' });
                if (loseNextSave) {
                    loseNextSave = false;
                    return route.fulfill({ status: 500, json: { error: 'Ответ потерян' } });
                }
            }
            if (action === 'delete') {
                const previous = notes.find(note => note.id === Number(input.get('id')));
                if (previous) assert.equal(input.get('original_title'), previous.title);
                notes = notes.filter(note => note.id !== Number(input.get('id')));
            }
            await route.fulfill({ json: { ok: true } });
        };
        await page.route(endpoint, async route => {
            const input = new URLSearchParams(route.request().postData());
            assert.equal(input.get('user_hash'), 'browser-test-token');
            assert.equal(input.has('body') || input.has('pinned') || input.has('page_url'), false);
            requests.push(input.get('action'));
            payloads.push(input);
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
        assert.equal(await row.locator('.notes-date').textContent(), 'Сегодня, 00:15');
        assert.equal(await row.locator('.notes-date').getAttribute('title'), 'Дата добавления: 30.10.2026, 00:15');
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
        assert.equal(await row.locator('.notes-date').textContent(), 'Сегодня, 00:15');
        assert((await editor.boundingBox()).width >= 100);
        assert.equal(await row.evaluate(element => element.scrollWidth <= element.clientWidth), true);
        assert.equal(await input.isVisible(), true);
        await page.locator('.notes-header').click();
        assert.equal(await page.locator('.notes-title').textContent(), title);
        assert.equal(await editor.count(), 0);
        assert.equal(await focused(page.locator('.notes-title')), true);
        await page.locator('.notes-title').click();
        await editor.fill('Не сохранять');
        await page.locator('.notes-header').click();
        assert.equal(await editor.isVisible(), true);
        assert.equal(await editor.inputValue(), 'Не сохранять');
        assert.equal(await page.locator('.notes-edit').getAttribute('class'), 'notes-edit notes-edit-alert');
        assert.equal(await focused(editor), true);
        await page.locator('[data-notes-close]').click();
        assert.equal(await trigger.getAttribute('aria-expanded'), 'true');
        assert.equal(await editor.isVisible(), true);
        assert.equal(await focused(editor), true);
        await editor.press('Backspace');
        assert.equal(await page.locator('.notes-edit').getAttribute('class'), 'notes-edit');
        await editor.press('Escape');
        assert.equal(await trigger.getAttribute('aria-expanded'), 'true');
        assert.equal(await page.locator('.notes-title').textContent(), title);
        assert.equal(await focused(page.locator('.notes-title')), true);
        await page.locator('.notes-title').click();
        await editor.fill('Отменить кнопкой');
        const cancel = page.getByRole('button', { name: 'Отменить редактирование', exact: true });
        assert.equal(await cancel.textContent(), 'Отмена');
        await cancel.click();
        assert.equal(await page.locator('.notes-title').textContent(), title);
        assert.equal(await focused(page.locator('.notes-title')), true);
        await page.locator('.notes-title').click();
        await editor.fill('Исправлено');
        fail = true;
        await editor.press('Enter');
        await page.getByText('Ошибка сохранения', { exact: true }).waitFor();
        assert.equal(await editor.inputValue(), 'Исправлено');
        assert.equal(await focused(editor), true);
        await editor.dispatchEvent('keydown', { key: 'Escape', isComposing: true, bubbles: true });
        assert.equal(await editor.isVisible(), true);
        await page.locator('.notes-header').click();
        assert.equal(await editor.inputValue(), 'Исправлено');
        await input.focus();
        await page.keyboard.press('Escape');
        assert.equal(await trigger.getAttribute('aria-expanded'), 'true');
        assert.equal(await focused(editor), true);
        await editor.press('Enter');
        await page.getByRole('button', { name: 'Исправлено', exact: true }).waitFor();
        assert.equal(await row.locator('.notes-date').textContent(), 'Сегодня, 00:15');
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

        // A failed request after closing the panel keeps the editor and its text on reopen.
        await page.locator('.notes-title').click();
        await editor.fill('Ожидает сохранения');
        const failedClosedHold = pauseNext();
        await editor.press('Enter');
        await failedClosedHold;
        await editor.press('Escape');
        assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
        fail = true;
        await held();
        await idle();
        await trigger.click();
        assert.equal(await editor.inputValue(), 'Ожидает сохранения');
        await editor.press('Escape');

        // Text entered while a save is in flight remains editable after acknowledgement.
        await page.locator('.notes-title').click();
        await editor.fill('Первая правка');
        const editDraftHold = pauseNext();
        await editor.press('Enter');
        await editDraftHold;
        await editor.fill('Вторая правка');
        await held();
        await page.getByText('Заметка сохранена. Новые изменения ещё не сохранены.').waitFor();
        assert.equal(await editor.inputValue(), 'Вторая правка');
        await page.locator('.notes-header').click();
        assert.equal(await editor.inputValue(), 'Вторая правка');
        await editor.press('Enter');
        await page.getByRole('button', { name: 'Вторая правка', exact: true }).waitFor();

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
        notes.unshift({ id: nextId++, title: 'От другого администратора', created_at: null, created_days_ago: null });
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
        await page.getByRole('button', { name: 'Удалить: Вторая правка', exact: true }).click();
        await page.getByText('Заметок пока нет.').waitFor();
        assert.equal(await focused(input), true);

        // A lost acknowledgement is retried with the same key; a failed refresh never resubmits a successful save.
        await input.fill('Повтор без дубля');
        loseNextSave = true;
        await input.press('Enter');
        await page.getByText('Ответ потерян').waitFor();
        const firstKey = payloads.at(-1).get('request_key');
        await input.press('Enter');
        await page.getByRole('button', { name: 'Повтор без дубля', exact: true }).waitFor();
        assert.equal(notes.filter(note => note.title === 'Повтор без дубля').length, 1);
        assert.equal(payloads.filter(payload => payload.get('action') === 'save').at(-1).get('request_key'), firstKey);
        failNextList = true;
        await input.fill('Сохранено без обновления');
        await input.press('Enter');
        await page.getByText('Изменения сохранены, но список не обновился. Откройте панель снова.').waitFor();
        assert.equal(await input.inputValue(), '');
        assert.equal(notes.filter(note => note.title === 'Сохранено без обновления').length, 1);
        await page.locator('[data-notes-close]').click();
        notes = [
            { id: nextId++, title: 'Вчерашняя заметка', created_at: '2026-10-29 23:59:00', created_days_ago: 1 },
            { id: nextId++, title: 'Прежняя заметка', created_at: '2025-12-31 09:05:00', created_days_ago: 303 },
        ];
        await trigger.click();
        await idle();
        assert.deepEqual(await page.locator('.notes-date').allTextContents(), ['Вчера, 23:59', '31.12.2025, 09:05']);
        assert.equal(await page.locator('.notes-row').evaluateAll(rows => rows.every(element => element.scrollWidth <= element.clientWidth)), true);
        const scrollbar = page.locator('[data-notes-scrollbar]');
        const thumb = page.locator('.notes-scroll-thumb');
        assert.equal(await scrollbar.getAttribute('hidden'), '');
        await page.evaluate(() => {
            const list = document.querySelector('[data-dle-toolbar]').shadowRoot.querySelector('.notes-list');
            const row = list.firstElementChild;
            const copies = Array.from({ length: 30 }, (_, i) => {
                const copy = row.cloneNode(true);
                copy.dataset.noteId = String(900 + i);
                return copy;
            });
            list.append(...copies);
        });
        await scrollbar.waitFor({ state: 'visible' });
        const thumbBox = await thumb.boundingBox();
        const trackBox = await scrollbar.boundingBox();
        await page.mouse.move(thumbBox.x + thumbBox.width / 2, thumbBox.y + thumbBox.height / 2);
        await page.mouse.down();
        await page.mouse.move(thumbBox.x + thumbBox.width / 2, trackBox.y + trackBox.height - 2, { steps: 8 });
        await page.mouse.up();
        const contentScroll = await page.locator('.notes-content').evaluate(element => element.scrollTop);
        assert(contentScroll > 0, 'Thumb drag scrolled notes list');
        await page.mouse.move(trackBox.x + trackBox.width / 2, trackBox.y + 5);
        await page.mouse.wheel(0, 50);
        await page.evaluate(() => {
            const list = document.querySelector('[data-dle-toolbar]').shadowRoot.querySelector('.notes-list');
            list.querySelectorAll('[data-note-id]').forEach((element, index) => {
                if (index >= 2) element.remove();
            });
        });
        await scrollbar.waitFor({ state: 'hidden' });
        await page.locator('[data-notes-close]').click();
        notes = Array.from({ length: 120 }, (_, i) => ({ id: 1000 - i, title: `Заметка ${i}`, created_at: null, created_days_ago: null, updated_at: '2026-10-30 00:15:00' }));
        await trigger.click();
        await idle();
        assert.equal(await page.locator('.notes-row').count(), 50);
        const more = page.getByRole('button', { name: 'Показать ещё' });
        await more.click();
        await page.getByRole('button', { name: 'Заметка 50', exact: true }).waitFor();
        assert.equal(await page.locator('.notes-row').count(), 100);
        assert.equal(payloads.at(-1).get('before_id'), '951');
        await more.click();
        await page.getByRole('button', { name: 'Заметка 119', exact: true }).waitFor();
        assert.equal(await page.locator('.notes-row').count(), 120);
        assert.equal(await more.isVisible(), false);
        await page.keyboard.press('Escape');
        assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
        assert.deepEqual(errors, []);
        await page.close();
        console.log(`PASS: DLE ${version}, ${width}px — simple notes, inline edits, scrollbar, focus, slow requests, retry, refresh and panel layout`);
    }
    // A hung request times out and leaves the controls available for a retry.
    const timeoutPage = await browser.newPage();
    await timeoutPage.clock.install({ time: new Date('2026-10-29T12:00:00Z') });
    let signalRequest;
    const pending = new Promise(resolve => { signalRequest = resolve; });
    await timeoutPage.route('**/engine/ajax/controller.php?mod=dletoolbar_notes', route => { signalRequest(route); });
    await timeoutPage.goto('http://127.0.0.1:8002/notes-panel.php?test=1&dle_version=18.0');
    await timeoutPage.locator('[data-notes-trigger]').click();
    const pendingRoute = await pending;
    await timeoutPage.clock.fastForward(16000);
    await timeoutPage.getByText('Сервер не ответил. Повторите запрос.').waitFor();
    assert.equal(await timeoutPage.locator('.notes-panel').getAttribute('aria-busy'), 'false');
    await pendingRoute.abort().catch(() => {});
    await timeoutPage.close();
} finally {
    await browser.close();
}
