<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
;

return (new Config())
    // Кэш — в var/ рядом с кэшами остальных инструментов, а не в корне
    // api/. Корень должен показывать состав проекта, а не следы прогонов;
    // var/ уже исключён и из git, и из контекста сборки образа.
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache')
    ->setRiskyAllowed(true)
    ->setRules([
        // PER-CS сменил PSR-12 как действующий стиль PHP-FIG.
        // Ревизия закреплена: плавающий алиас @PER-CS означал бы смену
        // правил форматирования на минорном обновлении php-cs-fixer.
        '@PER-CS3.0' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'ordered_imports' => true,
        'no_unused_imports' => true,
    ])
    ->setFinder($finder)
;
