<?php

namespace iCooLER\DLEToolbar\Components\Notes;

use iCooLER\DLEToolbar\ComponentFramework\Component;

final class Notes implements Component
{
    public static function definition(): array
    {
        return ['label' => 'Заметки', 'description' => 'Быстрое добавление, редактирование и удаление общих заметок.'];
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
            'panel' => '<section id="toolbar-notes" class="notes-panel" data-endpoint="' . $endpoint . '" data-token="' . $token . '" role="dialog" aria-labelledby="toolbar-notes-title" hidden>' . <<<'HTML'
<header class="notes-header"><h2 id="toolbar-notes-title">Заметки</h2><button class="notes-close" type="button" data-notes-close aria-label="Закрыть заметки"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m18 6-12 12"/><path d="m6 6 12 12"/></svg></button></header>
<form class="notes-compose">
<input class="notes-input" name="title" aria-label="Новая заметка" placeholder="Новая заметка" maxlength="200" required autocomplete="off">
<button type="submit" data-notes-action>Добавить</button>
</form>
<div class="notes-content">
<p class="notes-status" role="status" aria-live="polite"></p>
<div class="notes-list" aria-label="Список заметок"></div>
</div>
</section>
HTML,
            'styles' => <<<'CSS'
.notes-panel { pointer-events: auto; display: flex; flex-direction: column; width: min(760px, 100%); height: min(560px, calc(100dvh - 96px)); overflow: hidden; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); color: var(--foreground); box-shadow: var(--shadow); }
.notes-panel[hidden], .notes-panel [hidden] { display: none; }
.notes-header { display: flex; align-items: center; gap: 12px; padding: 13px 18px; border-bottom: 1px solid var(--border); }
.notes-header h2 { margin: 0 auto 0 0; color: var(--foreground); font: 650 16px/1.25 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; letter-spacing: -.025em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.notes-panel button { border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px; background: var(--surface); color: var(--foreground); font: inherit; cursor: pointer; }
.notes-panel button:hover { background: var(--hover); }
.notes-panel .notes-close { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 30px; height: 30px; padding: 0; border-radius: 8px; color: var(--muted); transition: background-color 140ms ease, color 140ms ease; }
.notes-panel .notes-close:hover { color: var(--foreground); }
.notes-close svg { width: 16px; height: 16px; }
.notes-panel button[aria-disabled="true"] { opacity: .5; cursor: wait; }
.notes-panel :focus-visible { outline: 2px solid var(--foreground); outline-offset: 2px; }
.notes-content { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 18px; }
.notes-status { margin: 0 0 8px; color: var(--muted); font-size: 12px; }
.notes-status:empty { display: none; }
.notes-row { display: flex; align-items: center; gap: 4px; height: 36px; min-width: 0; border-bottom: 1px solid var(--border); border-radius: 6px; transition: background-color 140ms ease; }
.notes-row:hover { background: var(--hover); }
.notes-row:last-child { border-bottom: 0; }
.notes-panel .notes-row button, .notes-panel .notes-row button:hover { background: transparent; }
.notes-row .notes-title { flex: 1; min-width: 0; text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border: 0; }
.notes-row .notes-delete { flex: 0 0 30px; padding: 4px; border: 0; }
.notes-compose, .notes-edit { display: flex; align-items: center; gap: 8px; min-width: 0; }
.notes-compose { padding: 18px 18px 0; }
.notes-compose > button { flex: 0 0 auto; }
.notes-input { flex: 1; width: 100%; min-width: 0; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--foreground); padding: 8px; font: inherit; }
.notes-input:focus, .notes-input:focus-visible { outline: none; border-color: var(--muted); }
.notes-edit { flex: 1; width: 100%; }
.notes-edit .notes-input { padding: 4px 8px; }
.notes-edit button { flex: 0 0 auto; }
@media (max-width: 480px) {
    .notes-panel { height: min(560px, calc(100dvh - 112px)); border-radius: 12px; }
    .notes-header { gap: 8px; padding: 11px 12px; }
    .notes-content { padding: 12px; }
    .notes-compose { padding: 12px 12px 0; }
}
@media (prefers-reduced-motion: reduce) { .notes-panel .notes-close, .notes-row { transition: none; } }
CSS,
            'script' => <<<'JS'
(() => {
    const trigger = root.querySelector('[data-notes-trigger]');
    const panel = root.querySelector('.notes-panel');
    const list = panel.querySelector('.notes-list');
    const form = panel.querySelector('.notes-compose');
    const input = form.elements.namedItem('title');
    const status = panel.querySelector('.notes-status');
    let notes = [];
    let busy = false;
    const focusNote = (id) => {
        const button = list.querySelector('[data-note-id="' + id + '"] .notes-title');
        if (button) button.focus();
        else input.focus();
    };
    const button = (label, className, action) => {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = className;
        element.textContent = label;
        element.dataset.notesAction = '';
        element.addEventListener('click', () => { if (!busy) action(); });
        return element;
    };
    const edit = (note) => {
        renderList();
        const row = list.querySelector('[data-note-id="' + note.id + '"]');
        const editor = document.createElement('form');
        editor.className = 'notes-edit';
        const field = document.createElement('input');
        field.className = 'notes-input';
        field.value = note.title;
        field.maxLength = 200;
        field.required = true;
        field.setAttribute('aria-label', 'Изменить заметку');
        const save = document.createElement('button');
        save.type = 'submit';
        save.textContent = 'Сохранить';
        save.dataset.notesAction = '';
        const cancel = button('×', 'notes-cancel', () => { renderList(); focusNote(note.id); });
        cancel.setAttribute('aria-label', 'Отменить редактирование');
        editor.append(field, save, cancel);
        editor.addEventListener('submit', (event) => {
            event.preventDefault();
            request('save', { id: note.id, title: field.value });
        });
        editor.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !busy) {
                event.preventDefault();
                event.stopPropagation();
                renderList();
                focusNote(note.id);
            }
        });
        row.replaceChildren(editor);
        field.focus();
        field.select();
    };
    const renderList = () => {
        list.replaceChildren();
        notes.forEach((note) => {
            const row = document.createElement('div');
            row.className = 'notes-row';
            row.dataset.noteId = note.id;
            const open = button(note.title, 'notes-title', () => edit(note));
            open.title = note.title;
            const remove = button('×', 'notes-delete', () => {
                if (window.confirm('Удалить заметку «' + note.title + '»?')) request('delete', { id: note.id });
            });
            remove.setAttribute('aria-label', 'Удалить: ' + note.title);
            row.append(open, remove);
            list.append(row);
        });
        status.textContent = notes.length ? '' : 'Заметок пока нет.';
    };
    const setBusy = (value) => {
        busy = value;
        panel.setAttribute('aria-busy', String(value));
        panel.querySelectorAll('[data-notes-action]').forEach((element) => {
            element.setAttribute('aria-disabled', String(value));
        });
    };
    const request = async (action, values = {}) => {
        if (busy) return false;
        setBusy(true);
        status.textContent = 'Загрузка…';
        try {
            const response = await fetch(panel.dataset.endpoint, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                body: new URLSearchParams({ ...values, action, user_hash: panel.dataset.token }),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Не удалось выполнить действие.');
            const restoreFocus = list.contains(root.activeElement);
            const index = notes.findIndex((note) => note.id === Number(values.id));
            notes = result.notes;
            renderList();
            if (restoreFocus && popup.isOpen()) {
                const next = notes.find((note) => note.id === Number(values.id)) || notes[Math.min(index, notes.length - 1)];
                focusNote(next ? next.id : 0);
            }
            return true;
        } catch (error) {
            status.textContent = error.message || 'Не удалось загрузить заметки.';
            return false;
        } finally {
            setBusy(false);
        }
    };
    const popup = createPopup({ trigger, panel, closeDuration: 0, onOpen: () => {
        input.focus();
        request('list');
    } });
    trigger.addEventListener('click', () => popup.isOpen() ? popup.close() : popup.open());
    panel.querySelector('[data-notes-close]').addEventListener('click', () => popup.close({ restoreFocus: true }));
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const title = input.value;
        if (await request('save', { title })) {
            if (input.value === title) input.value = '';
            if (popup.isOpen() && form.contains(root.activeElement)) input.focus();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && popup.isOpen()) {
            event.preventDefault();
            popup.close({ restoreFocus: true });
        }
    });
    document.addEventListener('click', (event) => {
        if (popup.isOpen() && !event.composedPath().includes(panel) && !event.composedPath().includes(dock)) popup.close();
    }, true);
})();
JS,
        ];
    }
}
