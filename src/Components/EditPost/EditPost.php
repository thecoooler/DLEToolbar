<?php

namespace iCooLER\DLEToolbar\Components\EditPost;

use iCooLER\DLEToolbar\ComponentFramework\ConfigurableComponent;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;

final class EditPost implements ConfigurableComponent
{
    private string $target;

    public static function parameters(): array
    {
        return ['target' => Parameter::select('Открывать редактирование', '_blank', [
            '_self' => 'В текущей вкладке', '_blank' => 'В новой вкладке',
        ])];
    }

    public function __construct(array $values)
    {
        $this->target = (string) $values['target'];
    }

    public static function definition(): array
    {
        return ['label' => 'Редактировать новость', 'description' => 'Открывает текущую новость в админке. Показывается только на странице новости.'];
    }

    public function render(): ?array
    {
        if (!$this->canEditPost()) return null;
        global $config;
        /** @var array{http_home_url: string, admin_path: string} $config */
        /** @var positive-int $newsId */
        $newsId = NEWS_ID;
        $url = $config['http_home_url'] . $config['admin_path']
            . '?mod=editnews&action=editnews&id=' . $newsId;
        return ['control' => '<a class="control" data-component="editpost" href="'
            . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" target="' . $this->target . '"' . ($this->target === '_blank' ? ' rel="noopener"' : '')
            . ' aria-label="Редактировать новость">' . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 4l4 4M4 20l4 -1L20 7a2.828 2.828 0 0 0 -4 -4L4 15l-1 5z"/></svg>' . '</a>'];
    }

    private function canEditPost(): bool {
        global $dle_module, $news_found, $user_group, $member_id;
        /** @var string $dle_module */
        /** @var bool $news_found */
        /** @var array{user_group: int|string} $member_id */
        /** @var array<int, array{allow_admin: mixed, admin_editnews: mixed}> $user_group */

        return $dle_module === 'showfull' && $news_found
            && $user_group[$member_id['user_group']]['allow_admin']
            && $user_group[$member_id['user_group']]['admin_editnews'];
    }

}
