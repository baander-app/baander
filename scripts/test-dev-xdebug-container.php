#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Development\Acceptance\Support;
use Baander\Tooling\Runtime\Requirements;

try {
    Requirements::check();
    $environment = getenv();
    $configuration = \Baander\Tooling\Configuration\ToolingConfiguration::forRepository(dirname(__DIR__), $environment);
    $executor = new \Baander\Tooling\Process\EngineExecutor(\Baander\Tooling\Process\ExecutionContext::bounded($configuration->budgets->seconds('backend'), $environment));
    $artifacts = Support::temporary('baander-xdebug-check-');
    $target = new \Baander\Tooling\Docker\TargetResolver($executor, $environment)->resolve($configuration->docker, $artifacts . '/transport');
    $docker = new \Baander\Tooling\Docker\Client($executor, $target, $environment);
    $image = $docker->resolveImage($configuration->images->role('application'), pull: false)->id;
    $ledger = new \Baander\Tooling\Resources\ResourceLedger($artifacts . '/resources.json', $docker, $environment);
    $ledger->scoped(function () use ($docker, $ledger, $image): void {
        $version = <<<'CODE'
$expected = getenv("XDEBUG_VERSION");
$actual = phpversion("xdebug");
if (!$expected || $actual !== $expected) {
    fwrite(STDERR, "Xdebug version mismatch: expected $expected, got $actual\n");
    exit(1);
}
CODE;
        $coverage = <<<'CODE'
$file = tempnam(sys_get_temp_dir(), "xdebug-smoke-");
file_put_contents($file, "<?php\nreturn 42;\n");
xdebug_start_code_coverage();
require $file;
$coverage = xdebug_get_code_coverage();
xdebug_stop_code_coverage();
unlink($file);
exit(($coverage[$file][2] ?? null) === 1 ? 0 : 1);
CODE;
        $aliases = <<<'SH'
set -e
shopt -s expand_aliases
source /home/www-data/.bashrc
php -r 'exit($argv[1] === "www-data" ? 0 : 1);' "$(id -un)"
eval "debug-on"
php -r 'exit(extension_loaded("xdebug") && in_array("debug", xdebug_info("mode"), true) && in_array("develop", xdebug_info("mode"), true) ? 0 : 1);'
eval "debug-off"
php -r 'exit(extension_loaded("xdebug") ? 1 : 0);'
SH;
        foreach ([
            ['--network', 'none', '--entrypoint', 'php', $image, '-r', $version],
            ['--network', 'none', $image, 'php', '-r', 'exit(extension_loaded("xdebug") ? 1 : 0);'],
            ['--network', 'none', '-e', 'XDEBUG_MODE=coverage', $image, 'php', '-r', $coverage],
            ['--network', 'none', $image, 'bash', '-c', $aliases],
        ] as $arguments) {
            $container = $ledger->create(\Baander\Tooling\Resources\ResourceKind::Container, 'baander-xdebug-' . bin2hex(random_bytes(6)), $arguments);
            $docker->run(['start', '--attach', $container], interactive: true);
            $status = (int) trim($docker->run(['wait', $container])->stdout);
            if ($status !== 0) {
                throw new \Baander\Tooling\Process\CommandFailure('Xdebug container check failed.', $status);
            }
        }
    }, $configuration->budgets->seconds('cleanup'));
    Support::removeTree($artifacts);
    echo "Xdebug container checks passed (4 scenarios).\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error->getCode() > 0 && $error->getCode() < 256 ? $error->getCode() : 1);
}
