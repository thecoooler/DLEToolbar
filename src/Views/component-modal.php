<?php

if (!defined('DATALIFEENGINE')) {
    http_response_code(403);
    die('Hacking attempt!');
}

/** @var array<string, array<string, array{message: string, value: string}>> $errors */
/** @var string $id */
/** @var \iCooLER\DLEToolbar\AdminUI\ComponentSettings $component */
/** @var \Closure(string): string $escape */
?>
<div class="modal fade" id="dlt-settings-<?= $escape($id) ?>" data-component-id="<?= $escape($id) ?>" tabindex="-1" role="dialog" aria-labelledby="dlt-settings-title-<?= $escape($id) ?>">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header ui-dialog-titlebar">
                <button type="button" class="close" data-dismiss="modal" aria-label="Закрыть"><span aria-hidden="true">&times;</span></button>
                <span class="ui-dialog-title" id="dlt-settings-title-<?= $escape($id) ?>"><?= $escape($component->label) ?></span>
            </div>
            <div class="modal-body form-horizontal">
                <?php foreach ($component->parameters as $name => $parameter):
                    $fieldId = 'dlt-parameter-' . $id . '-' . $name;
                    $error = $errors[$id][$name] ?? null;
                    $value = $error !== null ? $error['value'] : $component->values[$name]; ?>
                    <div class="form-group<?= $error !== null ? ' has-error' : '' ?>">
                        <?= $parameter->renderLabel($fieldId, 'control-label col-sm-5') ?>
                        <div class="col-sm-7">
                            <?= $parameter->renderControl('components[' . $id . '][' . $name . ']', $fieldId, $value, $error['message'] ?? null) ?>
                            <?php if ($error !== null): ?>
                                <div class="help-block" id="<?= $escape($fieldId . '-error') ?>"><?= $escape($error['message']) ?></div>
                            <?php endif; ?>
                            <?php if ($parameter->description !== ''): ?>
                                <div class="text-muted text-size-small mt-5"><?= $escape($parameter->description) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <p class="text-muted no-margin">Изменения сохранятся после нажатия «Сохранить» на странице настроек.</p>
            </div>
            <div class="modal-footer">
                <?php if ($component->instanceType !== null): ?>
                    <button type="button" class="btn bg-danger btn-sm btn-raised pull-left" data-remove-component="<?= $escape($id) ?>"><i class="fa fa-trash position-left"></i>Удалить ссылку</button>
                <?php endif; ?>
                <button type="button" class="btn bg-teal btn-sm btn-raised" data-dismiss="modal">Готово</button>
            </div>
        </div>
    </div>
</div>
