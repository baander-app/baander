#!/usr/bin/env bash
set -euo pipefail

image="${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}"

# Bypassing the entrypoint is how CI starts PHP with coverage support.
docker run --rm --network none --entrypoint php "$image" -r '
$expected = getenv("XDEBUG_VERSION");
$actual = phpversion("xdebug");
if (!$expected || $actual !== $expected) {
    fwrite(STDERR, "Xdebug version mismatch: expected $expected, got $actual\n");
    exit(1);
}
'

docker run --rm --network none "$image" php -r '
exit(extension_loaded("xdebug") ? 1 : 0);
'

# Execute a real file so coverage must contain an executed source line.
docker run --rm --network none -e XDEBUG_MODE=coverage "$image" php -r '
$file = tempnam(sys_get_temp_dir(), "xdebug-smoke-");
file_put_contents($file, "<?php\nreturn 42;\n");
xdebug_start_code_coverage();
require $file;
$coverage = xdebug_get_code_coverage();
xdebug_stop_code_coverage();
unlink($file);
exit(($coverage[$file][2] ?? null) === 1 ? 0 : 1);
'

# Run as the image default user: developers must be able to change the INI files.
docker run --rm --network none "$image" bash -c '
set -e
shopt -s expand_aliases
source /home/www-data/.bashrc
test "$(id -un)" = www-data
eval "debug-on"
php -r '\''exit(extension_loaded("xdebug")
    && in_array("debug", xdebug_info("mode"), true)
    && in_array("develop", xdebug_info("mode"), true) ? 0 : 1);'\''
eval "debug-off"
php -r '\''exit(extension_loaded("xdebug") ? 1 : 0);'\''
'

printf 'Xdebug container checks passed (4 scenarios).\n'
