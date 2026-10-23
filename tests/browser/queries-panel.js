(async () => {
    let assertions = 0;
    const assert = (condition, message) => {
        if (!condition) throw new Error(message);
        assertions++;
    };
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const key = (target, value) => target.dispatchEvent(new KeyboardEvent('keydown', {
        key: value, bubbles: true, composed: true, cancelable: true,
    }));
    const result = document.getElementById('result');

    try {
        const root = document.querySelector('[data-dle-toolbar]').shadowRoot;
        const trigger = root.querySelector('[data-queries-open]');
        const panel = root.querySelector('[data-query-panel]');
        const tooltip = root.querySelector('[data-tooltip]');
        const folds = [...panel.querySelectorAll('[data-fold]')];
        const groups = [...panel.querySelectorAll('[data-query-group]')];
        const rows = groups.map((group) => [...group.querySelectorAll('[data-fast-row]')]);
        const expanded = () => trigger.getAttribute('aria-expanded') === 'true';

        const typography = (element) => {
            const style = getComputedStyle(element);
            return [style.fontSize, style.fontFamily, style.lineHeight, style.letterSpacing, style.textTransform];
        };
        const elements = [trigger, trigger.querySelector('.query-open-icon'), panel, tooltip];
        const originalTypography = elements.map(typography);
        const templateStyles = document.createElement('style');
        templateStyles.textContent = `
            html { font-size: 40px; }
            body > div, button, span {
                font: italic 32px/3 serif !important;
                letter-spacing: 12px !important;
                text-transform: uppercase !important;
            }
        `;
        document.head.append(templateStyles);
        try {
            elements.forEach((element, index) => {
                assert(JSON.stringify(typography(element)) === JSON.stringify(originalTypography[index]),
                    'Template CSS does not change toolbar typography');
            });
        } finally {
            templateStyles.remove();
        }

        assert(panel.hidden && !expanded(), 'Panel starts closed');
        assert(trigger.getAttribute('aria-label').includes('MySQL: 9'), 'DLE counter stays independent');
        assert(!trigger.querySelector('.metric-value') && trigger.offsetWidth === 40,
            'SQL trigger shows only the icon');
        assert(panel.querySelector('.query-stats').textContent.includes('6 запросов'), 'Connection is excluded from list count');
        assert(folds.length === 2 && rows.every((group) => group.length === 2), 'Separated chronological groups');
        assert(rows.flat().every((row) => row.hidden), 'Fast rows start collapsed');

        trigger.dispatchEvent(new PointerEvent('pointerenter', { pointerType: 'mouse' }));
        await wait(400);
        assert(tooltip.dataset.open === 'true', 'Shared tooltip opens');
        trigger.click();
        assert(!panel.hidden && expanded(), 'Trigger opens panel');
        assert(!tooltip.dataset.open, 'Opening SQL dismisses shared tooltip');
        assert(trigger.getAttribute('aria-label').includes('Закрыть список запросов'), 'Open trigger label');

        // A non-query sibling must not break the group association.
        folds[0].after(document.createElement('span'));
        folds[0].click();
        assert(rows[0].every((row) => !row.hidden), 'Click expands entire first group despite spacer');
        assert(rows[0][0].querySelector('.query-source').textContent.includes('engine/modules/shop/run.php:12'),
            'Expanded fast rows show call sites');
        assert(rows[1].every((row) => row.hidden), 'First group leaves second group collapsed');
        assert(folds[0].getAttribute('aria-expanded') === 'true', 'Fold announces expansion');
        assert(folds[0].textContent.includes('Скрыть'), 'Expanded fold label');
        key(folds[0], ' ');
        assert(rows[0].every((row) => row.hidden), 'Space collapses first group');
        key(folds[1], 'Enter');
        assert(rows[1].every((row) => !row.hidden), 'Enter expands second group');
        assert(rows[0].every((row) => row.hidden), 'Second group leaves first collapsed');

        const chips = [...panel.querySelectorAll('[data-owner-filter]')];
        const queryRows = [...panel.querySelectorAll('.query-row[data-owner]')];
        const visibleOwners = () => queryRows.filter((row) => !row.hidden).map((row) => row.dataset.owner);
        assert(chips.length === 3, 'Three owners rendered as chips');
        assert(chips[0].textContent.includes('DLE') && chips[0].textContent.includes('3'), 'DLE chip present');
        assert(chips[1].textContent.includes('Plugin') && chips[1].textContent.includes('1'), 'Plugin chip present');
        assert(chips[2].textContent.includes('shop') && chips[2].textContent.includes('2'), 'shop chip present');
        assert(chips.every((chip) => chip.getAttribute('aria-pressed') === 'false') && queryRows.every((row) => !row.hidden),
            'All owner rows shown initially');
        chips[2].click();
        assert(chips[2].getAttribute('aria-pressed') === 'true', 'Clicked owner chip is pressed');
        assert(visibleOwners().every((owner) => owner === chips[2].dataset.ownerFilter), 'Only clicked owner rows are visible');
        chips[2].click();
        assert(chips[2].getAttribute('aria-pressed') === 'false', 'Unclicked owner chip resets');
        assert(queryRows.every((row) => !row.hidden), 'All rows restored after untoggling');
        assert(expanded(), 'Chip click does not close panel');

        const copy = panel.querySelector('.query-row:not([data-fast-row]):not([data-fold]) [data-copy-query]');
        const status = panel.querySelector('[data-copy-status]');
        assert(copy.closest('.query-row').querySelector('.query-source').textContent.includes('Plugin'),
            'Ordinary rows show plugin labels');
        const originalClipboard = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
        let copied;
        try {
            Object.defineProperty(navigator, 'clipboard', { configurable: true, value: {
                writeText: (text) => { copied = text; return Promise.resolve(); },
            } });
            copy.click();
            await wait(0);
            assert(copied === 'SELECT "<value>"\nFROM dle_post', 'Clipboard gets original SQL and newline');
            assert(status.textContent === 'SQL-запрос скопирован.', 'Copy success feedback');
            assert(expanded(), 'Copy click does not close panel');
            navigator.clipboard.writeText = () => Promise.reject(new Error('denied'));
            copy.click();
            await wait(0);
            assert(status.textContent.startsWith('Не удалось скопировать'), 'Copy rejection feedback');
            Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
            copy.click();
            assert(status.textContent.includes('HTTPS'), 'Unavailable clipboard feedback');
        } finally {
            if (originalClipboard) Object.defineProperty(navigator, 'clipboard', originalClipboard);
            else delete navigator.clipboard;
        }

        folds[1].focus();
        key(folds[1], 'Escape');
        assert(!expanded(), 'Escape closes panel');
        assert(root.activeElement === trigger, 'Escape restores trigger focus');
        assert(trigger.getAttribute('aria-label').includes('Показать список запросов'), 'Closed trigger label');
        trigger.click();
        await wait(210);
        assert(!panel.hidden && expanded() && !panel.dataset.closing, 'Reopen cancels pending close');
        trigger.click();
        await wait(210);
        assert(panel.hidden && !expanded(), 'Trigger closes after animation');
        trigger.click();
        panel.querySelector('[data-query-close]').click();
        assert(root.activeElement === trigger, 'Close button restores trigger focus');
        await wait(210);
        assert(panel.hidden, 'Close button hides panel');
        trigger.click();
        document.getElementById('outside').click();
        await wait(210);
        assert(panel.hidden && !expanded(), 'Outside click closes panel');
        assert(getComputedStyle(panel).display === 'none', 'Closed panel is not laid out');

        const skinTrigger = root.querySelector('[data-skin-trigger]');
        const skinMenu = root.querySelector('[data-skin-menu]');
        const skinOptions = [...skinMenu.querySelectorAll('[data-skin-option]')];
        const skinExpanded = () => skinTrigger.getAttribute('aria-expanded') === 'true';
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        skinTrigger.focus();
        skinTrigger.click();
        assert(skinExpanded() && !skinMenu.hidden, 'Template menu opens');
        assert(root.activeElement === skinOptions[0], 'Template menu focuses current template');
        const skinList = skinMenu.querySelector('.skin-menu-list');
        assert(getComputedStyle(skinMenu).overflow === 'hidden' && getComputedStyle(skinList).overflowY === 'auto', 'Template list scrolls inside the rounded frame');
        assert(skinMenu.getBoundingClientRect().top >= 0, 'Template menu stays inside the viewport');
        key(skinOptions[0], 'ArrowDown');
        assert(root.activeElement === skinOptions[1], 'Template navigation remains local');
        key(skinOptions[1], 'Escape');
        assert(!skinExpanded(), 'Template Escape announces closure');
        if (reducedMotion) assert(skinMenu.hidden, 'Reduced motion closes template menu immediately');
        skinTrigger.click();
        await wait(210);
        assert(skinExpanded() && !skinMenu.hidden, 'Template reopening cancels pending close');
        assert(root.activeElement === skinOptions[0], 'Cancelled close does not steal menu focus');
        key(skinOptions[0], 'Escape');
        await wait(210);
        assert(skinMenu.hidden && root.activeElement === skinTrigger, 'Template Escape restores trigger focus');

        trigger.focus();
        trigger.click();
        skinTrigger.focus();
        skinTrigger.click();
        assert(panel.hidden && !expanded(), 'Opening template menu immediately closes SQL');
        assert(root.activeElement === skinOptions[0], 'SQL closure does not steal template focus');
        assert(!tooltip.dataset.open, 'Template opening dismisses tooltip');

        // Actual pointerdown starts a delayed close before the new trigger click.
        trigger.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, composed: true }));
        trigger.focus();
        trigger.click();
        assert(skinMenu.hidden && !skinExpanded(), 'Opening SQL finishes template pending close');
        await wait(210);
        assert(root.activeElement === trigger && expanded(), 'Previous close timer does not steal SQL focus');
        skinTrigger.focus();
        assert(expanded(), 'Leaving SQL focus does not close panel');
        key(panel, 'Escape');
        if (reducedMotion) assert(panel.hidden, 'Reduced motion closes SQL immediately');
        await wait(210);

        skinTrigger.click();
        document.getElementById('outside').focus();
        await wait(210);
        assert(skinMenu.hidden && !skinExpanded(), 'Template menu closes when focus leaves');
        assert(document.activeElement === document.getElementById('outside'), 'Focus departure does not restore template trigger');
        skinTrigger.click();
        document.getElementById('outside').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, composed: true }));
        await wait(210);
        assert(skinMenu.hidden && !skinExpanded(), 'Outside pointer closes template menu');
        assert(root.activeElement === skinTrigger, 'Outside pointer restores focused template menu trigger');

        // Hold the reload while checking the one-use cookie passed to PHP.
        const originalFrame = window.requestAnimationFrame;
        const originalUrl = window.location.href;
        const frames = [];
        window.requestAnimationFrame = callback => frames.push(callback);
        try {
            for (const option of [skinOptions[1], skinOptions[0]]) {
                skinTrigger.disabled = false;
                skinTrigger.click();
                option.click();
                const cookie = document.cookie.split('; ').find(value => value.startsWith('dle_toolbar_skin='));
                const request = JSON.parse(decodeURIComponent(cookie.slice('dle_toolbar_skin='.length)));
                assert(request.skin === option.dataset.skinValue, 'Temporary cookie sends selected folder, including default');
                assert(request.token === skinTrigger.dataset.skinToken && request.token !== '', 'Temporary cookie includes CSRF token');
                assert(!document.cookie.split('; ').some(value => value.startsWith('dle_skin=')), 'Native HttpOnly cookie stays inaccessible to JavaScript');
                assert(window.location.href === originalUrl && !document.querySelector('form'), 'Selection preserves URL and creates no form');
                assert(frames.length > 0, 'Selection schedules page reload');
                assert(skinTrigger.disabled && !skinExpanded(), 'Selection prevents duplicate clicks and closes menu');
            }
        } finally {
            window.requestAnimationFrame = originalFrame;
            document.cookie = 'dle_toolbar_skin=; max-age=0; path=/';
            skinTrigger.disabled = false;
            skinTrigger.removeAttribute('aria-busy');
        }

        window.queriesPanelTestResult = { status: 'PASS', assertions };
        result.textContent = `PASS (${assertions} assertions)`;
    } catch (error) {
        window.queriesPanelTestResult = { status: 'FAIL', assertions, error: error.stack };
        result.textContent = `FAIL: ${error.stack}`;
        throw error;
    }
})();
