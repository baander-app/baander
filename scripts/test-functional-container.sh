#!/usr/bin/env bash
set -euo pipefail
if [ "${BAANDER_FUNCTIONAL_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_FUNCTIONAL_TIMEOUT_ACTIVE=1 timeout 300s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
if [ "$#" -eq 0 ]; then
    set -- tests/Functional
fi
run_id="baander-functional-$(date +%s)-$$"
cleanup() {
    docker rm -f "$run_id-app" "$run_id-redis" "$run_id-postgres" >/dev/null 2>&1 || true
    docker network rm "$run_id" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$run_id" >/dev/null
docker run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    -e REDIS_ARGS='--requirepass test-only' redis/redis-stack-server:edge >/dev/null
docker run -d --name "$run_id-postgres" --network "$run_id" --network-alias postgres \
    -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=functional_test \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}" >/dev/null
ready=false
for attempt in $(seq 1 30); do
    if docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d functional_test >/dev/null 2>&1 &&
        docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG; then
        ready=true
        break
    fi
    sleep 1
done
if [ "$ready" != true ]; then
    echo 'Functional services did not become ready within 30 attempts.' >&2
    docker logs --tail 40 "$run_id-postgres" >&2 || true
    docker logs --tail 40 "$run_id-redis" >&2 || true
    exit 1
fi

archive_paths=(vendor src tests config packages migrations bin docker/general templates public phpunit.xml.dist
    .env .env.test composer.json composer.lock translations)
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" = 1 ]; then
    archive_paths=(--files-from /dev/null)
fi
tar --exclude='config/secrets/oauth' --exclude='config/secrets/*.decrypt.private.php' -cf - "${archive_paths[@]}" |
    docker run --rm --name "$run_id-app" --privileged --network "$run_id" -i --entrypoint sh \
        -e BAANDER_TEST_CHECKOUT_IN_IMAGE="${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" \
        -e APP_ENV=test -e APP_DEBUG=0 -e XDEBUG_MODE=off -e REDIS_PASSWORD=test-only \
        -e REDIS_URL=redis://default:test-only@redis:6379 \
        -e MESSENGER_TEST_REDIS_DSN=redis://default:test-only@redis:6379 \
        -e MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages \
        -e OUTBOX_TEST_DATABASE_URL="postgresql://baander:test-only@postgres:5432/functional_test?serverVersion=18&charset=utf8" \
        -e DATABASE_URL="postgresql://baander:test-only@postgres:5432/functional_test?serverVersion=18&charset=utf8" \
        "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}" -c '
            set -eu
            if [ "$BAANDER_TEST_CHECKOUT_IN_IMAGE" = 1 ]; then
                cd /var/www/html
            else
                mkdir -p /tmp/baander-functional
                cd /tmp/baander-functional
            fi
            tar -xf -
            oauth_directory="$(mktemp -d /tmp/baander-functional-oauth.XXXXXXXX)"
            export OAUTH_PRIVATE_KEY_PATH="$oauth_directory/private.key"
            export OAUTH_PUBLIC_KEY_PATH="$oauth_directory/public.key"
            (
                umask 077
                openssl genrsa -out "$OAUTH_PRIVATE_KEY_PATH" 2048 2>/dev/null
                openssl rsa -in "$OAUTH_PRIVATE_KEY_PATH" -pubout -out "$OAUTH_PUBLIC_KEY_PATH" 2>/dev/null
            )
            php -d memory_limit=512M bin/console cache:clear --env=test --no-debug
            php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=test
            # A second run must recognize the completed migration history.
            php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=test
            exec php -d memory_limit=512M vendor/bin/phpunit -c phpunit.xml.dist --no-progress --colors=never \
                --display-all-issues --fail-on-phpunit-notice --fail-on-skipped "$@"
        ' sh "$@"
