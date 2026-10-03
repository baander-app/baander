#!/usr/bin/env bash
set -euo pipefail
if [ "${BAANDER_WORKER_PRODUCTION_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_WORKER_PRODUCTION_TIMEOUT_ACTIVE=1 timeout 300s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
: "${BAANDER_TEST_IMAGE:?Set BAANDER_TEST_IMAGE to an image built with --target worker.}"
# Use the same daemon as the lifecycle runner, including remote CI contexts.
docker_args=()
if [ -n "${BAANDER_TEST_DOCKER_ENDPOINT:-}" ]; then docker_args=(--host "$BAANDER_TEST_DOCKER_ENDPOINT"); fi
image_id="$(docker "${docker_args[@]}" image inspect --format '{{.Id}}' "$BAANDER_TEST_IMAGE")"
docker "${docker_args[@]}" image inspect "$image_id" | python3 -c '
import json, sys
config = json.load(sys.stdin)[0]["Config"]
assert config["User"] == "www-data", "Worker must run as application user"
assert config["Entrypoint"] == ["/usr/local/bin/php", "/var/www/html/bin/console", "app:worker", "--no-interaction"], "Worker target entrypoint differs"
assert config.get("Cmd") in (None, []), "Worker target must not inherit a web command"
assert config["Healthcheck"]["Test"] == ["NONE"], "Worker must not inherit the web healthcheck"
assert "APP_ENV=prod" in config["Env"] and "APP_DEBUG=0" in config["Env"], "Worker target must default to production without debug"
assert not config.get("Volumes"), "Worker target must not declare host volumes"
'
docker "${docker_args[@]}" run --rm --network none --entrypoint /usr/local/bin/php "$image_id" -r '
require "vendor/autoload.php";
if (!in_array(ini_get("display_errors"), ["", "0"], true)
    || !in_array(ini_get("display_startup_errors"), ["", "0"], true)) {
    fwrite(STDERR, "Production PHP must not display runtime or startup errors.\n");
    exit(1);
}
if (!is_file("vendor/autoload_runtime.php")
    || Composer\InstalledVersions::isInstalled("phpunit/phpunit")
    || Composer\InstalledVersions::isInstalled("symfony/web-profiler-bundle")
    || is_dir("config/secrets") || is_file("docker/dev/juul.localdomain.key")
    || is_dir(".gitnexus") || is_dir(".reli")) {
    fwrite(STDERR, "Production image contains development artifacts or lacks the runtime autoloader.\n");
    exit(1);
}
'
docker "${docker_args[@]}" run --rm --network none --entrypoint /usr/bin/composer "$image_id" check-platform-reqs --no-dev
# No source, vendor, cache or keys are injected into the worker image.
BAANDER_TEST_IMAGE="$image_id" BAANDER_TEST_USE_IMAGE=1 \
    BAANDER_TEST_CHECKOUT_IN_IMAGE=1 BAANDER_TEST_OPERATOR_IN_CONTAINER=1 \
    bash scripts/test-worker-operator-container.sh
