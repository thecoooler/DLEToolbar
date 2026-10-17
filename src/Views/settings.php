<?php

if (!defined('DATALIFEENGINE')) {
    http_response_code(403);
    die('Hacking attempt!');
}

/** @var \iCooLER\DLEToolbar\Toolbar\ToolbarPreferences $preferences */
/** @var array<string, \iCooLER\DLEToolbar\AdminUI\ComponentSettings> $components */
/** @var array<string, \iCooLER\DLEToolbar\AdminUI\ComponentSettings> $templates */
/** @var array<string, array<string, array{message: string, value: string}>> $errors */
/** @var string $actionUrl */
/** @var string|null $notice */
/** @var string $dle_login_hash */
/** @var array<int, array{group_name: string}> $groups */
/** @var \Closure(string): string $escape */
?>
<?php if ($notice !== null): ?>
    <div class="alert alert-success alert-styled-left alert-arrow-left alert-component" role="status"><?= $escape($notice) ?></div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert">
        <p>Настройки не сохранены. Исправьте ошибки:</p>
        <ul>
            <?php foreach ($errors as $errorId => $parameterErrors): ?>
                <?php foreach ($parameterErrors as $error): ?>
                    <li><?= $escape($components[$errorId]->label) ?>: <?= $escape($error['message']) ?></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= $escape($actionUrl) ?>" id="dlt-settings-form">
    <input type="hidden" name="instances" value="">
    <input type="hidden" name="user_hash" value="<?= $escape($dle_login_hash) ?>">
    <div class="panel panel-default">
        <div class="panel-heading">
            <ul class="nav nav-tabs nav-tabs-solid">
                <li class="active"><a href="#dlt-components-tab" data-toggle="tab"><i class="fa fa-sliders position-left"></i>Компоненты</a></li>
                <li><a href="#dlt-access-tab" data-toggle="tab"><i class="fa fa-users position-left"></i>Доступ</a></li>
            </ul>
        </div>
        <div class="panel-tab-content tab-content">
            <div class="tab-pane active" id="dlt-components-tab">
                <div class="panel-body">
                    <p class="text-muted no-margin">Перетаскивайте строки за значок слева. Переключатель управляет видимостью компонента. После изменений нажмите «Сохранить».</p>
                </div>
                <div class="panel-body border-top">
                    <?php foreach ($templates as $type => $component): ?>
                        <button type="button" class="btn bg-teal btn-sm btn-raised" data-add-component="<?= $escape($type) ?>"><i class="fa fa-plus position-left"></i>Добавить ссылку</button>
                    <?php endforeach; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th class="dlt-handle-cell"><span class="sr-only">Перетащить</span></th>
                                <th>Компонент</th>
                                <th class="dlt-actions-cell"><span class="sr-only">Управление компонентом</span></th>
                            </tr>
                        </thead>
                        <tbody id="dlt-component-list">
                            <?php foreach ($components as $id => $component): ?>
                                <?php require \DLEPlugins::Check(ENGINE_DIR . '/iCooLER/DLEToolbar/Views/component-row.php'); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="tab-pane" id="dlt-access-tab">
                <div class="panel-body">
                    <label for="dlt-allowed-groups" class="text-semibold">Группы пользователей</label>
                    <div class="mt-10">
                        <select id="dlt-allowed-groups" name="access[groups][]" class="dlt-groupselect" multiple data-placeholder="Выберите группы пользователей" style="width:100%; max-width:600px;" aria-describedby="dlt-groups-help">
                            <?php foreach ($groups as $groupId => $group): ?>
                                <option value="<?= (int) $groupId ?>"<?= $preferences->allowsGroup((int) $groupId) ? ' selected' : '' ?>><?= $escape($group['group_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p id="dlt-groups-help" class="text-muted mt-10 no-margin">Панель и её функции доступны выбранным группам. По умолчанию — администраторы. Если не выбрано ни одной группы, доступ отключён для всех. Пользователи и гости недоступны для выбора: панель показывает SQL-запросы и сбрасывает кэш.</p>
                </div>
            </div>
        </div>
        <div class="panel-footer">
            <button type="submit" class="btn bg-teal btn-sm btn-raised"><i class="fa fa-floppy-o position-left"></i>Сохранить</button>
        </div>
    </div>
    <?php foreach ($components as $id => $component): ?>
        <?php if ($component->parameters !== []): ?>
            <?php require \DLEPlugins::Check(ENGINE_DIR . '/iCooLER/DLEToolbar/Views/component-modal.php'); ?>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php foreach ($templates as $type => $component): $id = '__COMPONENT_ID__'; ?>
        <template data-component-template="<?= $escape($type) ?>">
            <table><tbody><?php require \DLEPlugins::Check(ENGINE_DIR . '/iCooLER/DLEToolbar/Views/component-row.php'); ?></tbody></table>
            <?php require \DLEPlugins::Check(ENGINE_DIR . '/iCooLER/DLEToolbar/Views/component-modal.php'); ?>
        </template>
    <?php endforeach; ?>
</form>
<style>
    #dlt-settings-form .dlt-component-label { overflow-wrap: anywhere; }
    #dlt-settings-form .ui-dialog-title { display: block; padding-right: 24px; overflow-wrap: anywhere; }
    #dlt-settings-form .dlt-handle-cell { width: 40px; }
    /* DLE 13.0–13.2 admin styles have no .dd-handles icon. */
    #dlt-settings-form td.dlt-handle-cell { cursor: move; }
    #dlt-settings-form td.dlt-handle-cell::before { content: '\f142'; font-family: FontAwesome; display: block; text-align: center; }
    #dlt-settings-form .dlt-actions-cell { width: 1%; white-space: nowrap; }
    #dlt-settings-form .dlt-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
    #dlt-settings-form .dlt-actions > * { flex-shrink: 0; }
    #dlt-settings-form .dlt-disabled td:nth-child(2) { opacity: .55; }
    #dlt-settings-form .dlt-icon-list { display: flex; flex-wrap: wrap; gap: 6px; }
    #dlt-settings-form .dlt-icon-choice { position: relative; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 8px 10px; margin: 0; border: 1px solid #ddd; border-radius: 6px; cursor: pointer; font-weight: normal; text-align: center; }
    #dlt-settings-form .dlt-icon-choice input[type="radio"] { position: absolute; inset: 0; opacity: 0; margin: 0; cursor: pointer; }
    #dlt-settings-form .dlt-icon-choice:has(input:checked) { border-color: #00796b; background: #e0f2f1; color: #004d40; }
    #dlt-settings-form .dlt-icon-choice:has(input:focus-visible) { outline: 2px solid #00796b; outline-offset: 2px; }
    #dlt-settings-form .dlt-icon-glyph svg { display: block; width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }
    #dlt-settings-form .dlt-icon-glyph svg.fill { fill: currentColor; stroke: none; }
    #dlt-settings-form .dlt-icon-label { font-size: 11px; line-height: 1.2; }
    #dlt-settings-form .modal-header,
    #dlt-settings-form .modal-body,
    #dlt-settings-form .modal-footer { padding-left: 32px; padding-right: 32px; }
    @media (max-width: 480px) {
        #dlt-settings-form .modal-header,
        #dlt-settings-form .modal-body,
        #dlt-settings-form .modal-footer { padding-left: 24px; padding-right: 24px; }
    }
</style>
<?php require \DLEPlugins::Check(ENGINE_DIR . '/iCooLER/DLEToolbar/Views/settings-script.php'); ?>
