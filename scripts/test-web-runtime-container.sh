#!/usr/bin/env bash
# Starts the real Swoole web server (app:serve) in the production environment and
# checks what functional tests cannot see outside Swoole: the language of a credential
# email resolved at kernel.terminate, progressive streaming of a rendition while it
# encodes, byte ranges once it is complete, the answers to Safari's opening request
# sequence against an in-progress rendition (a committed baseline), and the health alert
# for a PostgreSQL outage that spans a reload of the server's workers.
set -euo pipefail
if [ "${BAANDER_WEB_RUNTIME_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_WEB_RUNTIME_TIMEOUT_ACTIVE=1 timeout 180s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
run_id="baander-web-$(date +%s)-$$"
# The server's health monitor interval (seconds); production checks every 60.
monitor_interval=2
cleanup() {
    local status=$?
    if [ "$status" != 0 ] && docker inspect "$run_id-app" >/dev/null 2>&1; then
        echo "--- app:serve output (last 100 lines)" >&2
        docker exec "$run_id-app" tail -n 100 /tmp/web-runtime-serve.log >&2 || true
    fi
    docker rm -f "$run_id-app" "$run_id-mailpit" "$run_id-redis" "$run_id-postgres" >/dev/null 2>&1 || true
    docker network rm "$run_id" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$run_id" >/dev/null
docker run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    -e REDIS_ARGS='--requirepass test-only' redis/redis-stack-server:edge >/dev/null
docker run -d --name "$run_id-postgres" --network "$run_id" --network-alias postgres \
    -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=web_test \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}" >/dev/null
docker run -d --name "$run_id-mailpit" --network "$run_id" --network-alias mailpit \
    axllent/mailpit:v1.31.4 >/dev/null
# Probe PostgreSQL over TCP: during initialisation the image runs a temporary server that
# answers on its socket only, then restarts.
for attempt in $(seq 1 30); do
    if docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d web_test >/dev/null 2>&1 &&
        docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG &&
        docker exec "$run_id-mailpit" /mailpit readyz >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d web_test >/dev/null
docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG
docker exec "$run_id-mailpit" /mailpit readyz >/dev/null

# The image owns /var/www/html/vendor as root, so a host checkout is unpacked into a
# directory the www-data user can write.
app_dir=/tmp/baander-web-runtime
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" = 1 ]; then
    app_dir=/var/www/html
fi
# The app container idles and every step runs in it through docker exec, so a step can
# also act on the other containers from here.
docker run -d --name "$run_id-app" --privileged --network "$run_id" --entrypoint sleep \
    -e APP_ENV=prod -e APP_DEBUG=0 -e XDEBUG_MODE=off -e OTEL_ENABLED=false \
    -e APP_SECRET=web-runtime-test-only-secret -e STREAM_URL_HMAC_SECRET=web-runtime-test-only-hmac \
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
    tar -cf - vendor src tests config packages migrations bin docker/general templates public \
        phpunit.xml.dist .env .env.test composer.json composer.lock translations | app tar -xf -
fi
app mkdir -p storage/media storage/conversions
# Throwaway OAuth signing keys; the CI image already carries its own.
app sh -c 'test -f config/secrets/oauth/private.key || (umask 077 && mkdir -p config/secrets/oauth &&
    openssl genrsa -out config/secrets/oauth/private.key 2048 2>/dev/null &&
    openssl rsa -in config/secrets/oauth/private.key -pubout -out config/secrets/oauth/public.key 2>/dev/null)'
app php bin/console doctrine:migrations:migrate --no-interaction >/dev/null

# Set up through the console before the server starts, as an operator would.
printf '%s\n' 'Web-runtime-test-only-1' | app php bin/console app:user:create --no-interaction \
    --password web-runtime-dansk@baander.app 'Web Runtime Dansk' >/dev/null
app php bin/console app:user:setting set web-runtime-dansk@baander.app language da --no-interaction >/dev/null
printf '%s\n' 'Web-runtime-test-only-2' | app php bin/console app:user:create --no-interaction \
    --password --role admin --force web-runtime-admin@baander.app 'Web Runtime Admin' >/dev/null
printf '%s\n' 'Web-runtime-test-only-3' | app php bin/console app:user:create --no-interaction \
    --password --role super-admin --force web-runtime-super-admin@baander.app 'Web Runtime Super Admin' >/dev/null
app php bin/console app:settings:set transcode.enabled true --no-interaction >/dev/null
app php tests/Fixtures/web-runtime.php prepare

docker exec -d -w "$app_dir" "$run_id-app" \
    sh -c 'exec php bin/console app:serve --no-interaction > /tmp/web-runtime-serve.log 2>&1'
for attempt in $(seq 1 60); do
    if app curl -fsS -o /dev/null http://127.0.0.1:9501/live 2>/dev/null; then break; fi
    sleep 1
done
app curl -fsS -o /dev/null http://127.0.0.1:9501/live
echo "app:serve answers /live in the production environment."

app php tests/Fixtures/web-runtime.php mail
app php tests/Fixtures/web-runtime.php stream

safari_answers=$(app php tests/Fixtures/web-runtime.php safari)
if ! diff -u tests/Fixtures/WebRuntime/safari-in-progress.txt <(printf '%s\n' "$safari_answers"); then
    echo "The answers to the Safari request sequence differ from the baseline (diff above)." >&2
    exit 1
fi
echo "The answers to the Safari request sequence match the baseline."

# PostgreSQL goes down, the server's workers reload during the outage, and PostgreSQL
# returns. The monitor cannot deliver while PostgreSQL is down, so the alert stays owed
# across the reload and arrives once PostgreSQL is back, naming the outage window.

# The HTTP and task worker PIDs: the children of the server's manager process, which is
# the master's one app:serve child (the CPU pool's processes are the master's too).
worker_pids() {
    app sh -c 'set -e; master=$(pgrep -o -f "^php bin/console app:serve")
        manager=$(pgrep -P "$master" -f "^php bin/console app:serve")
        pgrep -P "$manager" | sort'
}
# Lines of the server's log that carry the message and name PostgreSQL.
postgresql_log_lines() {
    app sh -c 'grep -F "$1" var/log/prod.log | grep -cF "\"component\":\"postgresql\""' sh "$1" || true
}
same_workers() {
    comm -12 <(printf '%s\n' "$old_workers") <(printf '%s\n' "$new_workers")
}
stopped_at=$(date +%s)
docker stop "$run_id-postgres" >/dev/null
sleep $((2 * monitor_interval))
for attempt in $(seq 1 10); do
    if [ "$(postgresql_log_lines 'Health degradation detected')" -ge 1 ]; then break; fi
    sleep 1
done
if [ "$(postgresql_log_lines 'Health degradation detected')" != 1 ]; then
    echo "The health monitor did not report PostgreSQL unhealthy while it was stopped." >&2
    exit 1
fi
old_workers=$(worker_pids)
test -n "$old_workers"
reloaded_at=$(date +%s)
# The swoole bundle's API server reloads the workers as \Swoole\Server::reload() does.
app curl -fsS -o /dev/null -X PATCH http://127.0.0.1:9200/api/server
for attempt in $(seq 1 40); do
    new_workers=$(worker_pids || true)
    if [ "$(wc -l <<<"$new_workers")" = "$(wc -l <<<"$old_workers")" ] && [ -z "$(same_workers)" ]; then break; fi
    sleep 0.5
done
if [ "$(wc -l <<<"$new_workers")" != "$(wc -l <<<"$old_workers")" ] || [ -n "$(same_workers)" ]; then
    printf 'The server did not replace its workers after the reload (before: %s; after: %s).\n' \
        "$(tr '\n' ' ' <<<"$old_workers")" "$(tr '\n' ' ' <<<"$new_workers")" >&2
    exit 1
fi
echo "PostgreSQL stopped; the monitor saw it and the server reloaded its workers during the outage."
started_at=$(date +%s)
docker start "$run_id-postgres" >/dev/null
for attempt in $(seq 1 30); do
    if docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d web_test >/dev/null 2>&1; then break; fi
    sleep 1
done
docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d web_test >/dev/null
app php tests/Fixtures/web-runtime.php alert "$stopped_at" "$reloaded_at" "$started_at" wait
# A few more ticks: the delivered alert is not repeated.
sleep $((3 * monitor_interval))
app php tests/Fixtures/web-runtime.php alert "$stopped_at" "$reloaded_at" "$started_at" >/dev/null
echo "No second alert arrived in the next $((3 * monitor_interval)) seconds."
