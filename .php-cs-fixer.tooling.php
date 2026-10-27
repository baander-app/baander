<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__ . '/packages/baander-tooling')
    ->in(__DIR__ . '/scripts')
    ->in(__DIR__ . '/relay/tests')
    ->in(__DIR__ . '/relay/tools')
    ->in(__DIR__ . '/.agents/skills/prose-fix/scripts')
    ->exclude('vendor')
    ->notPath('backend-test-runner.php')
    ->notPath('e2e-ingest-video.php')
    ->notPath('retain-build-evidence.php')
    ->notPath('web-runtime')
    ->append([
        __DIR__ . '/tests/Fixtures/Http/dns-orphan-supervisor.php',
        __DIR__ . '/tests/Fixtures/Http/dns-server.php',
        __DIR__ . '/tests/Fixtures/Worker/autoscaling-verify.php',
        __DIR__ . '/tests/Fixtures/Worker/control-capacity-postgres-sample.php',
        __DIR__ . '/tests/Fixtures/Worker/control-capacity-verify.php',
        __DIR__ . '/tests/Fixtures/Worker/legacy-runtime.php',
        __DIR__ . '/tests/Fixtures/Worker/lifecycle-checkpoint.php',
        __DIR__ . '/tests/Fixtures/Worker/media-control-verify.php',
    ]);

return new Config()
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS3x0' => true,
        'no_multiple_statements_per_line' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'phpdoc_indent' => true,
        'phpdoc_trim' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer-tooling.cache')
    ->setFinder($finder);
