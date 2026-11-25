<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

// Namespaced names are imported, never written inline. packages/swoole-bundle is a fork
// compared with upstream by file hash, and Symfony Flex rewrites config/bundles.php whole,
// so both keep their own form.
$finder = Finder::create()
    ->in([
        __DIR__ . '/bin',
        __DIR__ . '/config',
        __DIR__ . '/migrations',
        __DIR__ . '/packages',
        __DIR__ . '/public',
        __DIR__ . '/relay/tests',
        __DIR__ . '/relay/tools',
        __DIR__ . '/scripts',
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/.agents/skills',
    ])
    ->name('*.php')
    ->exclude(['node_modules', 'var'])
    ->notPath(['#^swoole-bundle/#', '#(^|/)vendor/#', '#^bundles\.php$#'])
    ->ignoreVCSIgnored(true);

return new Config()
    ->setRiskyAllowed(false)
    ->setRules([
        'fully_qualified_strict_types' => ['import_symbols' => true, 'leading_backslash_in_global_namespace' => false],
    ])
    ->setFinder($finder);
