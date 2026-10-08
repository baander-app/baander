#!/usr/bin/env bash
set -euo pipefail

# Run backend unit and custom static-analysis rule tests in the application PHP runtime. Swoole was built
# with io_uring; the coroutine filesystem tests require a privileged container.
# No host paths are mounted and the container has no network access. Streaming
# the checkout also works when the Docker daemon runs inside a separate VM.
cd "$(dirname "$0")/.."
test -f vendor/autoload.php || { echo 'Install Composer dependencies first.' >&2; exit 1; }

tar -cf - vendor src tests config packages migrations bin templates translations scripts/run-phpunit-shards.php phpunit.xml.dist deptrac.yaml \
    .env .env.test composer.json composer.lock .forgejo/workflows docs-book |
    docker run --rm --privileged --network none -i --entrypoint sh \
        "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}" -c '
            set -eu
            mkdir -p /tmp/baander-tests
            tar -xf - -C /tmp/baander-tests
            cd /tmp/baander-tests
            exec php -d memory_limit=256M vendor/bin/phpunit -c phpunit.xml.dist --testsuite Unit,StaticAnalysisRules --no-progress --colors=never \
                --display-all-issues --fail-on-phpunit-notice --fail-on-skipped
        '
