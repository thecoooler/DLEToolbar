<?php

namespace iCooLER\DLEToolbar\Components\Response;

use iCooLER\DLEToolbar\ComponentFramework\Component;

final class Response implements Component
{
    public static function definition(): array
    {
        return ['label' => 'Статус ответа текущей страницы', 'description' => 'Показывает над панелью HTTP-код и описание ответа, если код отличается от 200.'];
    }

    public function render(): ?array
    {
        $value = (int) http_response_code();
        if ($value === 200) {
            return null;
        }

        $state = $value >= 400 ? 'error' : ($value >= 300 ? 'redirect' : 'success');
        $reasons = [
            201 => 'Ресурс создан', 301 => 'Постоянное перенаправление', 302 => 'Временное перенаправление',
            304 => 'Не изменено', 400 => 'Некорректный запрос', 401 => 'Требуется авторизация',
            403 => 'Доступ запрещён', 404 => 'Страница не найдена', 422 => 'Ошибка в данных запроса',
            429 => 'Слишком много запросов', 500 => 'Внутренняя ошибка сервера', 503 => 'Сервис недоступен',
        ];
        $reason = $reasons[$value] ?? 'Код ответа страницы';
        return [
            'control' => '',
            'hint' => '<div class="status-hint" data-component="response" data-state="' . $state
                . '" role="status" aria-label="HTTP-код ответа: ' . $value . '"><span class="status-code">'
                . $value . '</span>' . htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>',
            'styles' => <<<'CSS'
    .status-hint {
        pointer-events: auto;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 10px 6px 8px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--surface);
        color: var(--foreground);
        box-shadow: var(--shadow);
        font-size: 12px;
    }
    .status-code { padding: 1px 6px; border-radius: 5px; font-weight: 650; font-variant-numeric: tabular-nums; }
    .status-hint[data-state="success"] .status-code { background: #dcfce7; color: #15803d; }
    .status-hint[data-state="redirect"] .status-code { background: #fef3c7; color: #a16207; }
    .status-hint[data-state="error"] .status-code { background: #fee2e2; color: #b91c1c; }
    :host([data-theme="dark"]) .status-hint[data-state="success"] .status-code { background: #14532d; color: #86efac; }
    :host([data-theme="dark"]) .status-hint[data-state="redirect"] .status-code { background: #713f12; color: #fde047; }
    :host([data-theme="dark"]) .status-hint[data-state="error"] .status-code { background: #7f1d1d; color: #fca5a5; }
CSS,
        ];
    }
}
