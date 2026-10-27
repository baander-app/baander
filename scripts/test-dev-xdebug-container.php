#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Baander\Tooling\Development\Acceptance\Support;
use Baander\Tooling\Runtime\Requirements;

try {
    Requirements::check();
    $image = getenv('BAANDER_TEST_IMAGE') ?: 'martinjuul/baander-app:latest';
    $version = <<<'CODE'
$expected = getenv("XDEBUG_VERSION");
$actual = phpversion("xdebug");
if (!$expected || $actual !== $expected) {
    fwrite(STDERR, "Xdebug version mismatch: expected $expected, got $actual\n");
    exit(1);
}
CODE;
    Support::run(['docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'php', $image, '-r', $version], passthrough: true);
    Support::run(['docker', 'run', '--rm', '--network', 'none', $image, 'php', '-r', 'exit(extension_loaded("xdebug") ? 1 : 0);'], passthrough: true);
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
    Support::run(['docker', 'run', '--rm', '--network', 'none', '-e', 'XDEBUG_MODE=coverage', $image, 'php', '-r', $coverage], passthrough: true);
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
    Support::run(['docker', 'run', '--rm', '--network', 'none', $image, 'bash', '-c', $aliases], passthrough: true);
    echo "Xdebug container checks passed (4 scenarios).\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit($error instanceof \Baander\Tooling\Development\Acceptance\CommandFailure ? $error->status : 1);
}
