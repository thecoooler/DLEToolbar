<?php

if (!defined('DATALIFEENGINE')) {
    http_response_code(403);
    die('Hacking attempt!');
}

?>
<script>
(() => {
    const init = () => {
        $('#dlt-allowed-groups').chosen({
            width: '100%',
            no_results_text: 'Группы не найдены'
        });
        const form = document.getElementById('dlt-settings-form');
        const list = document.getElementById('dlt-component-list');
        const rowFor = (id) => list.querySelector('[data-id="' + id + '"]');
        const unsavedNotice = document.getElementById('dlt-unsaved-notice');
        const formState = (source = form) => {
            const state = new URLSearchParams(new FormData(source));
            // Field order outside array values has no effect on saved settings.
            state.sort();
            return state.toString();
        };
        const savedTemplate = document.getElementById('dlt-saved-settings');
        const savedState = savedTemplate
            ? formState(savedTemplate.content.querySelector('form'))
            : formState();
        const updateUnsaved = () => {
            const unsaved = formState() !== savedState;
            unsavedNotice.hidden = !unsaved;
            form.classList.toggle('dlt-dirty', unsaved);
        };
        const updateVisibility = (row) => {
            row.classList.toggle('dlt-disabled', !row.querySelector('.switch').checked);
        };
        const updateLabel = (input) => {
            const modal = input.closest('.modal');
            const row = rowFor(modal.dataset.componentId);
            const label = input.value.trim() || row.dataset.defaultLabel;
            row.querySelector('.dlt-component-label').textContent = label;
            modal.querySelector('.ui-dialog-title').textContent = label;
            row.querySelector('.dd-handles').title = 'Перетащить: ' + label;
            const settings = row.querySelector('[data-toggle="modal"]');
            settings.title = 'Настройки: ' + label;
            settings.setAttribute('aria-label', settings.title);
            row.querySelector('.switch').setAttribute('aria-label', 'Показывать: ' + label);
        };
        const addComponent = (type) => {
            const id = 'custom_' + Array.from(crypto.getRandomValues(new Uint8Array(8)),
                byte => byte.toString(16).padStart(2, '0')).join('');
            const source = form.querySelector('[data-component-template="' + type + '"]');
            const fragment = source.content.cloneNode(true);
            fragment.querySelectorAll('*').forEach((element) => {
                [...element.attributes].forEach((attribute) => {
                    if (attribute.value.includes('__COMPONENT_ID__')) {
                        element.setAttribute(attribute.name, attribute.value.replaceAll('__COMPONENT_ID__', id));
                    }
                });
            });
            const row = fragment.querySelector('tr');
            const modal = fragment.querySelector('.modal');
            list.append(row);
            form.append(modal);
            new Switchery(row.querySelector('.switch'), {
                color: '#4CAF50', secondaryColor: '#d9d9d94d', speed: '0.25s'
            });
            $(modal).find('select.uniform').selectpicker();
            updateVisibility(row);
            updateUnsaved();
            $(modal).modal('show');
        };
        const removeComponent = (button) => {
            const id = button.dataset.removeComponent;
            const modal = button.closest('.modal');
            const type = rowFor(id).querySelector('input[name="instances[' + id + ']"]').value;
            $(modal).one('hidden.bs.modal', () => {
                $(modal).find('select.uniform').selectpicker('destroy');
                rowFor(id).remove();
                $(modal).remove();
                updateUnsaved();
                form.querySelector('[data-add-component="' + type + '"]').focus();
            }).modal('hide');
        };

        form.addEventListener('invalid', (event) => {
            const modal = event.target.closest('.modal');
            if (modal && !modal.classList.contains('in')) {
                event.preventDefault();
                $(modal).one('shown.bs.modal', () => event.target.reportValidity()).modal('show');
            }
        }, true);
        form.addEventListener('input', (event) => {
            if (event.target.hasAttribute('data-validation-error')) {
                event.target.setCustomValidity('');
                event.target.removeAttribute('data-validation-error');
                event.target.removeAttribute('aria-invalid');
                event.target.removeAttribute('aria-describedby');
                const group = event.target.closest('.form-group');
                group.classList.remove('has-error');
                group.querySelector('.help-block').remove();
            }
            if (event.target.matches('input[name$="[name]"]')) updateLabel(event.target);
        });
        form.addEventListener('click', (event) => {
            const add = event.target.closest('[data-add-component]');
            if (add) addComponent(add.dataset.addComponent);
            const remove = event.target.closest('[data-remove-component]');
            if (remove) removeComponent(remove);
        });
        // Chosen and bootstrap-select report changes as jQuery events only.
        $(form).on('input change', updateUnsaved);
        list.addEventListener('change', (event) => {
            if (event.target.matches('.switch')) updateVisibility(event.target.closest('tr'));
        });
        new Sortable(list, {
            animation: 150,
            draggable: '.allow-drag',
            handle: '.dd-handles',
            ghostClass: 'drop-bg',
            onEnd: updateUnsaved
        });
        [...list.children].forEach(updateVisibility);
        updateUnsaved();
        const invalidFields = [...form.querySelectorAll('[data-validation-error]')];
        invalidFields.forEach(field => field.setCustomValidity(field.dataset.validationError));
        if (invalidFields.length) invalidFields[0].reportValidity();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
</script>
