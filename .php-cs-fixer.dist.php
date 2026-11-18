<?php

declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->in(__DIR__)
    ->exclude(['var', 'vendor', 'tests/PHPStan/Unit/Fixtures'])
    ->notPath(['config/reference.php'])
    ->append([__DIR__.'/bin/console']);

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP8x5Migration' => true,
        '@PHP8x5Migration:risky' => true,
        '@PHPUnit11x0Migration:risky' => true,
        'declare_strict_types' => ['strategy' => 'enforce'],
        'strict_param' => true,
        'strict_comparison' => true,
    ])
    ->setFinder($finder);
