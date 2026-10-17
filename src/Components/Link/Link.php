<?php

namespace iCooLER\DLEToolbar\Components\Link;

use iCooLER\DLEToolbar\ComponentFramework\ConfigurableComponent;
use iCooLER\DLEToolbar\ComponentFramework\Parameters\Parameter;

/** @phpstan-import-type IconName from Icons */
final class Link implements ConfigurableComponent
{
    private string $url;
    private string $name;
    private string $target;
    /** @var 'all'|'main'|'category'|'news' */
    private string $scope;
    /** @var IconName */
    private string $icon;

    public static function definition(): array
    {
        return ['label' => 'Кастомная ссылка', 'description' => 'Переход по указанному адресу.'];
    }

    public static function parameters(): array
    {
        return [
            'name' => Parameter::text('Название'),
            'url' => Parameter::text('Ссылка', '', 'HTTP(S)-адрес или путь от корня сайта. На странице новости доступны [xfvalue_name] и [xfgiven_name]…[/xfgiven_name].', \Closure::fromCallable([UrlTemplate::class, 'normalize']), true),
            'target' => Parameter::select('Открывать ссылку', '_self', [
                '_self' => 'В текущей вкладке', '_blank' => 'В новой вкладке',
            ]),
            'scope' => Parameter::select('Область видимости', 'all', [
                'all' => 'Все страницы', 'main' => 'Главная', 'category' => 'Категории', 'news' => 'Новость',
            ]),
            'icon' => Parameter::icons('Иконка', 'link', Icons::choices(), Icons::previews()),
        ];
    }

    public function __construct(array $values)
    {
        $this->name = (string) $values['name'];
        $this->url = (string) $values['url'];
        $this->target = (string) $values['target'];
        /** @var 'all'|'main'|'category'|'news' $scope */
        $scope = (string) $values['scope'];
        $this->scope = $scope;
        /** @var IconName $icon */
        $icon = (string) $values['icon'];
        $this->icon = $icon;
    }

    public function render(): ?array
    {
        global $dle_module, $news_found;
        /** @var string $dle_module */
        /** @var bool $news_found */
        $isNews = $dle_module === 'showfull' && $news_found;
        $visible = $this->scope === 'all'
            || ($this->scope === 'main' && $dle_module === 'main')
            || ($this->scope === 'category' && $dle_module === 'cat')
            || ($this->scope === 'news' && $isNews);
        if (!$visible) return null;
        $resolved = UrlTemplate::resolve($this->url, $isNews, NewsFields::values());
        if ($resolved === '') return null;
        $url = htmlspecialchars($resolved, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = htmlspecialchars($this->name !== '' ? $this->name : $resolved, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return ['control' => '<a class="control" data-component="link" href="' . $url
            . '" target="' . $this->target . '"' . ($this->target === '_blank' ? ' rel="noopener"' : '')
            . ' aria-label="' . $label . '">' . Icons::render($this->icon) . '</a>'];
    }
}
