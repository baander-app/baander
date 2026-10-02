#!/usr/bin/env bash
set -euo pipefail
if [ "${BAANDER_OUTBOX_RUNTIME_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_OUTBOX_RUNTIME_TIMEOUT_ACTIVE=1 timeout 180s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
run_id="baander-outbox-$(date +%s)-$$"
cleanup() {
    docker rm -f "$run_id-app" "$run_id-redis" "$run_id-postgres" >/dev/null 2>&1 || true
    docker network rm "$run_id" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$run_id" >/dev/null
docker run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    -e REDIS_ARGS='--requirepass test-only' redis/redis-stack-server:edge >/dev/null
docker run -d --name "$run_id-postgres" --network "$run_id" --network-alias postgres \
    -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=outbox_test \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}" >/dev/null
for attempt in $(seq 1 30); do
    if docker exec "$run_id-postgres" pg_isready -U baander -d outbox_test >/dev/null 2>&1 &&
        docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG; then
        break
    fi
    sleep 1
done
docker exec "$run_id-postgres" pg_isready -U baander -d outbox_test >/dev/null
docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG

archive_paths=(vendor src tests config packages migrations bin docker/general phpunit.xml.dist
    .env .env.test composer.json composer.lock translations)
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" = 1 ]; then
    archive_paths=(--files-from /dev/null)
fi
tar -cf - "${archive_paths[@]}" |
    docker run --rm --name "$run_id-app" --privileged --network "$run_id" -i --entrypoint sh \
        -e APP_ENV=prod -e APP_DEBUG=0 -e REDIS_PASSWORD=test-only \
        -e REDIS_URL=redis://default:test-only@redis:6379 \
        -e MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages \
        -e DATABASE_URL="postgresql://baander:test-only@postgres:5432/outbox_test?serverVersion=18&charset=utf8" \
        "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}" -c '
            set -eu
            cd /var/www/html
            tar -xf -
            php tests/Fixtures/outbox-runtime.php prepare
            php bin/console app:outbox:consume --once --no-interaction
            php tests/Fixtures/outbox-runtime.php verify
            php tests/Fixtures/outbox-runtime.php reset-ack
            php bin/console app:outbox:consume --once --no-interaction
            php tests/Fixtures/outbox-runtime.php verify
            echo "Production outbox replay preserved projections and avoided duplicate Redis handoffs."
        '
