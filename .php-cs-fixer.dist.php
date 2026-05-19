<?php

/**
 * Config php-cs-fixer do Togare — PSR-12 + short array, risky desligado.
 *
 * Escopo: apenas código custom Togare (espocrm/custom/ + tools/).
 * NUNCA formata vendor/, node_modules/, core do EspoCRM ou arquivos do BMAD.
 */

declare(strict_types=1);

// Finder cobre:
//   - espocrm/togare-*/src/files/custom/Espo/Modules/**  (código PHP dos módulos)
//   - tools/                                              (scripts internos do monorepo)
// NUNCA cobre: vendor/, node_modules/, build/, php_scripts/ (helpers do ext-template).
$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/tools',
    ])
    ->append(
        (new PhpCsFixer\Finder())
            ->in(__DIR__ . '/espocrm')
            ->path('#^togare-[a-z0-9-]+/src/files/custom/Espo/Modules/#')
            ->name('*.php'),
    )
    ->exclude([
        'vendor',
        'node_modules',
        'build',
        'php_scripts',
    ])
    ->notPath('#/(vendor|node_modules|build|php_scripts)/#')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
