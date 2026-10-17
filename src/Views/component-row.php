<?php

if (!defined('DATALIFEENGINE')) {
    http_response_code(403);
    die('Hacking attempt!');
}

/** @var string $id */
/** @var \iCooLER\DLEToolbar\AdminUI\ComponentSettings $component */
/** @var \Closure(string): string $escape */
?>
<tr class="drag-bg allow-drag" data-id="<?= $escape($id) ?>" data-component="<?= $escape($id) ?>" data-default-label="<?= $escape($component->defaultLabel) ?>">
    <td class="dd-handles dlt-handle-cell" title="Перетащить: <?= $escape($component->label) ?>"><span class="sr-only">Перетащить</span></td>
    <td>
        <div class="text-semibold dlt-component-label"><?= $escape($component->label) ?></div>
        <div class="text-muted text-size-small"><?= $escape($component->description) ?></div>
        <?php if ($component->instanceType !== null): ?>
            <input type="hidden" name="instances[<?= $escape($id) ?>]" value="<?= $escape($component->instanceType) ?>">
        <?php endif; ?>
        <input type="hidden" name="toolbar[items][]" value="<?= $escape($id) ?>">
    </td>
    <td class="dlt-actions-cell">
        <div class="dlt-actions">
            <?php if ($component->parameters !== []): ?>
                <button type="button" class="btn bg-slate-600 btn-sm btn-raised" data-toggle="modal" data-target="#dlt-settings-<?= $escape($id) ?>" aria-label="Настройки: <?= $escape($component->label) ?>" title="Настройки: <?= $escape($component->label) ?>">
                    <i class="fa fa-cog" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
            <input type="checkbox" class="switch" name="toolbar[enabled][]" value="<?= $escape($id) ?>" aria-label="Показывать: <?= $escape($component->label) ?>"<?= $component->enabled ? ' checked' : '' ?>>
        </div>
    </td>
</tr>
