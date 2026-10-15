#!/usr/bin/env bash
# Bounded malformed-input drill against the production Swoole HTTP stack.
set -euo pipefail
if [ "${BAANDER_SECURITY_RUNTIME_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_SECURITY_RUNTIME_TIMEOUT_ACTIVE=1 timeout 360s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
run_id="baander-security-$(date +%s)-$$"
# The server's health monitor interval (seconds); production checks every 60.
monitor_interval=2
cleanup() {
    local status=$?
    if [ "$status" != 0 ] && docker inspect "$run_id-app" >/dev/null 2>&1; then
        echo "--- app:serve output (last 100 lines)" >&2
        docker exec "$run_id-app" tail -n 100 /tmp/security-runtime-serve.log >&2 || true
    fi
    docker rm -f "$run_id-app" "$run_id-redis" "$run_id-postgres" >/dev/null 2>&1 || true
    docker network rm "$run_id" >/dev/null 2>&1 || true
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker network create --internal "$run_id" >/dev/null
docker run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    -e REDIS_ARGS='--requirepass test-only' redis/redis-stack-server:edge >/dev/null
docker run -d --name "$run_id-postgres" --network "$run_id" --network-alias postgres \
    -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=web_test \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}" >/dev/null
# Probe PostgreSQL over TCP: during initialisation the image runs a temporary server that
# answers on its socket only, then restarts.
for attempt in $(seq 1 30); do
    if docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d web_test >/dev/null 2>&1 &&
        docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG; then
        break
    fi
    sleep 1
done
docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d web_test >/dev/null
docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG

# The image owns /var/www/html/vendor as root, so a host checkout is unpacked into a
# directory the www-data user can write.
app_dir=/tmp/baander-security-runtime
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" = 1 ]; then
    app_dir=/var/www/html
fi
# The app container idles and every step runs in it through docker exec, so a step can
# also act on the other containers from here.
docker run -d --name "$run_id-app" --privileged --network "$run_id" --entrypoint sleep \
    -e APP_ENV=prod -e APP_DEBUG=0 -e XDEBUG_MODE=off -e OTEL_ENABLED=false \
    -e APP_SECRET=security-runtime-test-only-secret -e STREAM_URL_HMAC_SECRET=security-runtime-test-only-hmac \
    -e APP_URL=https://baander.app -e DEFAULT_URI=https://baander.app -e APP_DOMAIN=http://127.0.0.1:9501 \
    -e HEALTH_MONITOR_INTERVAL_SECONDS="$monitor_interval" \
    -e MAILER_DSN=smtp://mailpit:1025 -e MAIL_FROM_ADDRESS=noreply@baander.app \
    -e MEDIA_STORAGE_PATH="$app_dir/storage/media" -e CONVERT_STORAGE_PATH="$app_dir/storage/conversions" \
    -e REDIS_PASSWORD=test-only -e REDIS_URL=redis://default:test-only@redis:6379 \
    -e MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages \
    -e DATABASE_URL="postgresql://baander:test-only@postgres:5432/web_test?serverVersion=18&charset=utf8" \
    "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}" infinity >/dev/null
app() {
    docker exec -i -w "$app_dir" "$run_id-app" "$@"
}
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" != 1 ]; then
    docker exec "$run_id-app" mkdir -p "$app_dir"
    tar --exclude='config/secrets/oauth' --exclude='config/secrets/*.decrypt.private.php' -cf - vendor src tests config packages migrations bin docker/general templates public \
        phpunit.xml.dist .env .env.test composer.json composer.lock translations | app tar -xf -
fi
app mkdir -p storage/media storage/conversions var/log
# Throwaway OAuth signing keys; the CI image already carries its own.
app sh -c 'test -f config/secrets/oauth/private.key || (umask 077 && mkdir -p config/secrets/oauth &&
    openssl genrsa -out config/secrets/oauth/private.key 2048 2>/dev/null &&
    openssl rsa -in config/secrets/oauth/private.key -pubout -out config/secrets/oauth/public.key 2>/dev/null)'
app php bin/console doctrine:migrations:migrate --no-interaction >/dev/null

docker exec -d -w "$app_dir" "$run_id-app" \
    sh -c 'exec php bin/console app:serve --no-interaction > /tmp/security-runtime-serve.log 2>&1'
for attempt in $(seq 1 60); do
    if app curl -fsS -o /dev/null http://127.0.0.1:9501/live 2>/dev/null; then break; fi
    sleep 1
done
app curl -fsS -o /dev/null http://127.0.0.1:9501/live
app php tests/Fixtures/security-runtime.php
app php tests/Fixtures/websocket-runtime.php normal
app php tests/Fixtures/websocket-runtime.php prepare-outage
docker pause "$run_id-redis" >/dev/null
app php tests/Fixtures/websocket-runtime.php outage
docker unpause "$run_id-redis" >/dev/null
app php tests/Fixtures/websocket-runtime.php recovered
app php tests/Fixtures/websocket-runtime.php prepare-outage
docker stop -t 1 "$run_id-redis" >/dev/null
app php tests/Fixtures/websocket-runtime.php outage
docker start "$run_id-redis" >/dev/null
for attempt in $(seq 1 20); do
    if docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping 2>/dev/null | grep -qx PONG; then break; fi
    sleep 1
done
app php tests/Fixtures/websocket-runtime.php recovered
app php tests/Fixtures/websocket-runtime.php logs
if app sh -c 'grep -R -F "security-runtime-secret-canary" var/log /tmp/security-runtime-serve.log'; then
    echo 'A malformed request credential reached runtime logs.' >&2
    exit 1
fi
