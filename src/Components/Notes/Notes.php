<?php

namespace iCooLER\DLEToolbar\Components\Notes;

use iCooLER\DLEToolbar\ComponentFramework\Component;

final class Notes implements Component
{
    public static function definition(): array
    {
        return ['label' => 'Заметки', 'description' => 'Общие заметки с закреплением и привязкой к странице сайта.'];
    }

    public function render(): array
    {
        global $config, $dle_login_hash;
        /** @var array{http_home_url: string, version_id: string} $config */
        /** @var string $dle_login_hash */
        $controller = version_compare($config['version_id'], '19.0', '>=')
            ? 'index.php?controller=ajax&mod=dletoolbar_notes'
            : 'engine/ajax/controller.php?mod=dletoolbar_notes';
        $endpoint = htmlspecialchars($config['http_home_url'] . $controller, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $token = htmlspecialchars($dle_login_hash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return [
            'control' => '<button type="button" class="control" data-component="notes" data-notes-trigger aria-expanded="false" aria-controls="toolbar-notes" aria-label="Заметки"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8zM16 3v5h5M7 12h10M7 16h6"/></svg></button>',
            'panel' => '<section id="toolbar-notes" class="notes-panel" data-endpoint="' . $endpoint . '" data-token="' . $token . '" role="dialog" aria-label="Заметки" hidden>' . <<<'HTML'
<header class="notes-header"><strong>Заметки</strong><button type="button" data-notes-add>Добавить</button><button type="button" data-notes-close aria-label="Закрыть заметки">×</button></header>
<p class="notes-status" role="status" aria-live="polite"></p>
<div class="notes-list" aria-label="Список заметок"></div>
<form class="notes-form" hidden>
<label>Название<input name="title" maxlength="200" required autocomplete="off"></label>
<label>Текст<textarea name="body" rows="6" maxlength="20000"></textarea></label>
<label class="notes-binding"><input type="checkbox" name="bind"> Привязать к текущей странице</label>
<a class="notes-page" hidden target="_blank" rel="noopener">Открыть связанную страницу ↗</a>
<div class="notes-actions"><button type="submit">Сохранить</button><button type="button" data-notes-cancel>Отмена</button></div>
</form>
</section>
HTML,
            'styles' => <<<'CSS'
.notes-panel { pointer-events: auto; width: min(760px, 100%); height: min(560px, calc(100dvh - 96px)); overflow-y: auto; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); color: var(--foreground); box-shadow: var(--shadow); padding: 12px; }
@media (max-width: 480px) { .notes-panel { height: min(560px, calc(100dvh - 112px)); border-radius: 12px; } }
.notes-panel[hidden], .notes-panel [hidden] { display: none; }
.notes-header, .notes-actions { display: flex; gap: 8px; align-items: center; }
.notes-header strong { flex: 1; }
.notes-panel button { border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px; background: var(--surface); color: var(--foreground); font: inherit; cursor: pointer; }
.notes-panel button:hover { background: var(--hover); }
.notes-panel button:disabled { opacity: .5; cursor: wait; }
.notes-panel :focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; }
.notes-status { margin: 8px 0; color: var(--muted); font-size: 12px; }
.notes-status:empty { display: none; }
.notes-row { display: flex; align-items: center; gap: 4px; height: 36px; min-width: 0; border-bottom: 1px solid var(--border); }
.notes-row .notes-title { flex: 1; min-width: 0; text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border: 0; }
.notes-row .notes-pin, .notes-row .notes-delete { flex: 0 0 30px; padding: 4px; border: 0; }
.notes-pin[aria-pressed="true"] { background: var(--hover); color: var(--foreground); }
.notes-row a { flex: 0 0 auto; color: var(--muted); text-decoration: none; padding: 4px; }
.notes-form { display: flex; flex-direction: column; gap: 12px; margin-top: 12px; }
.notes-form label { display: flex; flex-direction: column; gap: 5px; }
.notes-form input:not([type="checkbox"]), .notes-form textarea { width: 100%; min-width: 0; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--foreground); padding: 8px; font: inherit; }
.notes-form textarea { resize: vertical; }
.notes-form .notes-binding { flex-direction: row; align-items: center; }
.notes-page { color: var(--foreground); overflow-wrap: anywhere; }
CSS,
            'script' => <<<'JS'
(() => {
    const trigger = root.querySelector('[data-notes-trigger]');
    const panel = root.querySelector('.notes-panel');
    const list = panel.querySelector('.notes-list');
    const form = panel.querySelector('form');
    const title = form.elements.namedItem('title');
    const body = form.elements.namedItem('body');
    const bind = form.elements.namedItem('bind');
    const page = form.querySelector('.notes-page');
    const status = panel.querySelector('.notes-status');
    const add = panel.querySelector('[data-notes-add]');
    let notes = [];
    let editing = null;
    let busy = false;
    let loaded = false;
    const currentPage = () => window.location.pathname + window.location.search + window.location.hash;
    const showList = () => {
        form.hidden = true;
        list.hidden = false;
        add.hidden = false;
        editing = null;
    };
    const edit = (note = null) => {
        editing = note;
        form.reset();
        title.value = note ? note.title : '';
        body.value = note ? note.body : '';
        bind.checked = !!(note && note.page_url);
        bind.parentElement.lastChild.textContent = note && note.page_url ? ' Привязать к странице: ' + note.page_url : ' Привязать к текущей странице';
        page.hidden = !note || !note.page_url;
        if (note && note.page_url) page.href = note.page_url;
        form.hidden = false;
        list.hidden = true;
        add.hidden = true;
        status.textContent = '';
        title.focus();
    };
    const button = (label, className, action) => {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = className;
        element.textContent = label;
        element.addEventListener('click', action);
        return element;
    };
    const renderList = () => {
        list.replaceChildren();
        notes.forEach((note) => {
            const row = document.createElement('div');
            row.className = 'notes-row';
            const pin = button('⌖', 'notes-pin', () => request('pin', { id: note.id, pinned: note.pinned ? '0' : '1' }));
            pin.setAttribute('aria-pressed', note.pinned ? 'true' : 'false');
            pin.setAttribute('aria-label', (note.pinned ? 'Открепить: ' : 'Закрепить: ') + note.title);
            const open = button(note.title, 'notes-title', () => edit(note));
            open.title = note.title;
            row.append(pin, open);
            if (note.page_url) {
                const link = document.createElement('a');
                link.href = note.page_url;
                link.textContent = '↗';
                link.setAttribute('aria-label', 'Связанная страница: ' + note.page_url);
                row.append(link);
            }
            const remove = button('×', 'notes-delete', () => {
                if (window.confirm('Удалить заметку «' + note.title + '»?')) request('delete', { id: note.id });
            });
            remove.setAttribute('aria-label', 'Удалить: ' + note.title);
            row.append(remove);
            list.append(row);
        });
        status.textContent = notes.length ? '' : 'Заметок пока нет.';
    };
    const request = async (action, values = {}) => {
        if (busy) return;
        busy = true;
        panel.setAttribute('aria-busy', 'true');
        panel.querySelectorAll('button').forEach((element) => { element.disabled = true; });
        status.textContent = 'Загрузка…';
        try {
            const response = await fetch(panel.dataset.endpoint, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                body: new URLSearchParams({ ...values, action, user_hash: panel.dataset.token }),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Не удалось выполнить действие.');
            notes = result.notes;
            loaded = true;
            if (action === 'save') showList();
            renderList();
        } catch (error) {
            status.textContent = error.message || 'Не удалось загрузить заметки.';
        } finally {
            busy = false;
            panel.removeAttribute('aria-busy');
            panel.querySelectorAll('button').forEach((element) => { element.disabled = false; });
        }
    };
    const popup = createPopup({ trigger, panel, closeDuration: 0, onOpen: () => {
        if (!loaded) request('list');
    } });
    trigger.addEventListener('click', () => popup.isOpen() ? popup.close() : popup.open());
    panel.querySelector('[data-notes-close]').addEventListener('click', () => popup.close({ restoreFocus: true }));
    add.addEventListener('click', () => edit());
    panel.querySelector('[data-notes-cancel]').addEventListener('click', () => { showList(); renderList(); add.focus(); });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        request('save', { id: editing ? editing.id : 0, title: title.value, body: body.value,
            page_url: bind.checked ? (editing && editing.page_url ? editing.page_url : currentPage()) : '' });
    });
    panel.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { event.preventDefault(); popup.close({ restoreFocus: true }); }
    });
    document.addEventListener('click', (event) => {
        if (popup.isOpen() && !event.composedPath().includes(panel) && !event.composedPath().includes(dock)) popup.close();
    }, true);
})();
JS,
        ];
    }
}
