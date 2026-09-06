<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP82Migration' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_align' => false,
        'concat_space' => ['spacing' => 'one'],
        // This codebase reads left-to-right ($x === null, not null === $x)
        // consistently; Yoda style would churn every file for no benefit.
        'yoda_style' => false,
    ])
    ->setFinder(
        (new PhpCsFixer\Finder())
            ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    );
