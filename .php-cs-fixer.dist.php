<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS3.0' => true,
        // Плагин поддерживает PHP 7.4: висячая запятая в списке параметров появилась только в PHP 8.0.
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays']],
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'single_quote' => true,
    ])
    ->setFinder($finder);
