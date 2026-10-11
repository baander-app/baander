#!/usr/bin/env bash
# Stops the Swoole web server the way a container runtime does and checks that it exits on
# its own. Two parts:
#
# 1. Swoole's shutdown order. A bare Swoole server with app:serve's process-mode settings
#    (tests/Fixtures/WebShutdown) runs as PID 1 and records each step with a timestamp. The web
#    server's drain depends on this order, so a Swoole upgrade that changes it fails here:
#    - SIGTERM runs onBeforeShutdown once, however many SIGTERMs or Server::shutdown() calls follow;
#    - a master timer started there keeps the master's event loop, its reactor threads and every
#      client connection open; meanwhile a worker's response and WebSocket close frame reach the
#      client, and a request on a connection kept alive from before the stop is still served;
#    - client connections close when the master joins its reactor threads, after that timer ends
#      and before any worker stops;
#    - the workers stop while the master waits for the manager in waitpid.
#
# 2. app:serve as PID 1, started by docker/general/start-web.sh with PostgreSQL and Redis: an
#    idle server exits 0 within the stop timeout after `docker stop` (SIGTERM) and after SIGINT.
#
# Why app:serve used to ignore SIGTERM (found by removing the master's registrations one at a
# time, ROADMAP item 4):
# - After SIGTERM, Swoole stops the master's event loop only once no timer is left in it, and
#   it checks for timers before it checks its own max_wait_time deadline. The CPU pool's health
#   check (CpuProcessPool::startHealthCheck, a Timer::tick in the master) never ended, so the
#   loop ran forever: no worker was ever stopped, and `docker stop` ended in SIGKILL. The
#   SIGINT listener does not hold the loop. SwooleWorkerEventSubscriber now ends the health
#   check in onBeforeShutdown.
# - Once the loop ends, the master joins Swoole's heartbeat thread, which sleeps
#   heartbeat_check_interval seconds with signals blocked. At 60 that added up to a minute to
#   every stop; config/packages/swoole.yaml now checks every second.
set -euo pipefail
# Timestamps are compared and sorted as decimal numbers with a decimal point.
export LC_ALL=C
if [ "${BAANDER_WEB_SHUTDOWN_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_WEB_SHUTDOWN_TIMEOUT_ACTIVE=1 timeout 300s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
image="${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}"
run_id="baander-web-shutdown-$(date +%s)-$$"
# Seconds `docker stop` waits before SIGKILL; the server must exit well inside it.
stop_timeout=20
cleanup() {
    local status=$?
    if [ "$status" != 0 ]; then
        for container in "$run_id-probe" "$run_id-web"; do
            if docker inspect "$container" >/dev/null 2>&1; then
                echo "--- $container output (last 60 lines)" >&2
                docker logs --tail 60 "$container" >&2 2>&1 || true
            fi
        done
    fi
    docker rm -f "$run_id-web" "$run_id-probe" "$run_id-client" "$run_id-redis" "$run_id-postgres" >/dev/null 2>&1 || true
    docker network rm "$run_id" >/dev/null 2>&1 || true
}
trap cleanup EXIT
fail() {
    echo "FAIL: $*" >&2
    exit 1
}

docker network create --internal "$run_id" >/dev/null
docker run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    -e REDIS_ARGS='--requirepass test-only' redis/redis-stack-server:edge >/dev/null
docker run -d --name "$run_id-postgres" --network "$run_id" --network-alias postgres \
    -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=web_shutdown_test \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}" >/dev/null

# The image owns /var/www/html/vendor as root, so a host checkout is unpacked into a
# directory the www-data user can write.
app_dir=/tmp/baander-web-shutdown
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" = 1 ]; then
    app_dir=/var/www/html
fi
app_env=(
    -e APP_ENV=prod -e APP_DEBUG=0 -e XDEBUG_MODE=off -e OTEL_ENABLED=false
    -e APP_SECRET=web-shutdown-test-only-secret -e STREAM_URL_HMAC_SECRET=web-shutdown-test-only-hmac
    -e APP_URL=https://baander.app -e DEFAULT_URI=https://baander.app -e APP_DOMAIN=http://127.0.0.1:9501
    -e MAILER_DSN=null://null -e MAIL_FROM_ADDRESS=noreply@baander.app
    -e MEDIA_STORAGE_PATH="$app_dir/storage/media" -e CONVERT_STORAGE_PATH="$app_dir/storage/conversions"
    -e REDIS_PASSWORD=test-only -e REDIS_URL=redis://default:test-only@redis:6379
    -e MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages
    -e DATABASE_URL="postgresql://baander:test-only@postgres:5432/web_shutdown_test?serverVersion=18&charset=utf8"
)
# The client idles; it prepares the database and checkout and makes the probe's requests.
docker run -d --name "$run_id-client" --privileged --network "$run_id" --entrypoint sleep \
    "${app_env[@]}" "$image" infinity >/dev/null
client() {
    docker exec -i -w "$app_dir" "$run_id-client" "$@"
}
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" != 1 ]; then
    docker exec "$run_id-client" mkdir -p "$app_dir"
    tar -cf - vendor src tests/Fixtures/WebShutdown config packages migrations bin docker/general templates public \
        .env composer.json composer.lock translations | client tar -xf -
fi
client mkdir -p storage/media storage/conversions
# Throwaway OAuth signing keys; the CI image already carries its own.
client sh -c 'test -f config/secrets/oauth/private.key || (umask 077 && mkdir -p config/secrets/oauth &&
    openssl genrsa -out config/secrets/oauth/private.key 2048 2>/dev/null &&
    openssl rsa -in config/secrets/oauth/private.key -pubout -out config/secrets/oauth/public.key 2>/dev/null)'

# Part 1: Swoole's shutdown order, observed on the bare probe server as PID 1.
docker create --name "$run_id-probe" --network "$run_id" --network-alias probe --entrypoint php \
    "$image" /tmp/tests/Fixtures/WebShutdown/shutdown-order-probe.php >/dev/null
tar -cf - tests/Fixtures/WebShutdown | docker cp - "$run_id-probe:/tmp/"
docker start "$run_id-probe" >/dev/null
for attempt in $(seq 1 50); do
    if docker logs "$run_id-probe" 2>&1 | grep -q ' master_start '; then break; fi
    sleep 0.1
done
docker logs "$run_id-probe" 2>&1 | grep -q ' master_start ' || fail 'the probe server did not start'
docker exec -d -w "$app_dir" "$run_id-client" \
    sh -c 'exec php tests/Fixtures/WebShutdown/shutdown-order-client.php probe > /tmp/shutdown-order-client.log 2>&1'
for attempt in $(seq 1 50); do
    if client grep -q ' ready ' /tmp/shutdown-order-client.log 2>/dev/null &&
        docker logs "$run_id-probe" 2>&1 | grep -q ' hold_received ' &&
        docker logs "$run_id-probe" 2>&1 | grep -q ' ws_open '; then
        break
    fi
    sleep 0.1
done
client grep -q ' ready ' /tmp/shutdown-order-client.log || fail 'the probe client did not open its connections'
term_at=$(date +%s.%N)
docker kill --signal TERM "$run_id-probe" >/dev/null
sleep 1
second_term_at=$(date +%s.%N)
docker kill --signal TERM "$run_id-probe" >/dev/null
probe_exit=$(timeout 30 docker wait "$run_id-probe") || fail 'the probe server did not exit within 30 seconds'
[ "$probe_exit" = 0 ] || fail "the probe server exited $probe_exit"
for attempt in $(seq 1 50); do
    if client grep -q ' client_done ' /tmp/shutdown-order-client.log; then break; fi
    sleep 0.1
done
timeline=$( (printf 'drill %s sigterm\ndrill %s sigterm\n' "$term_at" "$second_term_at"
    docker logs "$run_id-probe" 2>&1 | grep '^probe '; client cat /tmp/shutdown-order-client.log | grep '^client ') |
    sort -s -k2,2n)
echo "Shutdown order of the probe server (seconds after the first SIGTERM):"
awk -v t0="$term_at" '{ printf "  %+10.6f  %-6s %s", $2 - t0, $1, $3; for (i = 4; i <= NF; i++) printf " %s", $i; print "" }' <<<"$timeline"
# The time of an event's first line, and its details.
at() {
    awk -v source="$1" -v event="$2" '$1 == source && $3 == event { print $2; exit }' <<<"$timeline"
}
line() {
    awk -v source="$1" -v event="$2" '$1 == source && $3 == event { $1 = $2 = $3 = ""; sub(/^ +/, ""); print; exit }' <<<"$timeline"
}
before() {
    awk -v a="$1" -v b="$2" 'BEGIN { exit !(a != "" && b != "" && a + 0 <= b + 0) }'
}
before_shutdown=$(at probe before_shutdown)
hold_end=$(at probe master_hold_end)
first_worker_stop=$(at probe worker_stop)
[ "$(grep -c ' before_shutdown ' <<<"$timeline")" = 1 ] ||
    fail 'onBeforeShutdown ran more than once for two SIGTERMs and a worker'"'"'s Server::shutdown()'
grep -q ' worker_shutdown_call result=true' <<<"$timeline" || fail 'the worker did not call Server::shutdown() during the drain'
before "$before_shutdown" "$(at client hold_response)" && before "$(at client hold_response)" "$hold_end" ||
    fail 'the held response did not arrive while the master timer held the server open'
[ "$(line client hold_response)" = 'status=200 body=drained errno=0' ] || fail "held response: $(line client hold_response)"
before "$before_shutdown" "$(at client ws_close_frame)" && before "$(at client ws_close_frame)" "$hold_end" ||
    fail 'the WebSocket close frame did not arrive while the master timer held the server open'
[ "$(line client ws_close_frame)" = 'code=1012 reason=restart' ] || fail "close frame: $(line client ws_close_frame)"
before "$(at client keepalive_during_drain)" "$hold_end" ||
    fail 'the kept-alive connection was not served while the master timer held the server open'
[ "$(line client keepalive_during_drain)" = 'status=200 body=pong errno=0' ] ||
    fail "kept-alive request during the drain: $(line client keepalive_during_drain)"
[ "$(awk '$1 == "probe" && $3 == "ping" { print $4 }' <<<"$timeline" | sort -u | wc -l)" = 1 ] ||
    fail 'the request during the drain did not reuse the kept-alive connection'
# The client sees the close a little after the server makes it, so the order against the workers'
# stop is checked on the server side: every worker_stop line must report no connection left.
before "$hold_end" "$(at client idle_eof)" || fail 'the idle connection closed while the master timer held the server open'
before "$hold_end" "$first_worker_stop" || fail 'a worker stopped while the master timer held the server open'
if grep ' worker_stop ' <<<"$timeline" | grep -v 'master_wchan=do_wait master_threads=1 connections=0$' >&2; then
    fail 'a worker stopped before the master had joined its threads, closed every connection and waited for the manager'
fi
grep -q ' shutdown $' <<<"$timeline" || fail 'onShutdown did not run'
echo "PASS: Swoole kept the server open during the master timer, then closed connections before stopping workers."

# Part 2: app:serve as PID 1, started by start-web.sh.
for attempt in $(seq 1 30); do
    if docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d web_shutdown_test >/dev/null 2>&1 &&
        docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping 2>/dev/null | grep -qx PONG; then
        break
    fi
    sleep 1
done
docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d web_shutdown_test >/dev/null
client php bin/console doctrine:migrations:migrate --no-interaction >/dev/null
docker create --name "$run_id-web" --privileged --network "$run_id" -w "$app_dir" "${app_env[@]}" \
    "$image" "$app_dir/docker/general/start-web.sh" >/dev/null
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" != 1 ]; then
    client tar -cf - . | docker cp -a - "$run_id-web:$app_dir"
fi
start_web() {
    docker start "$run_id-web" >/dev/null
    for attempt in $(seq 1 90); do
        if docker exec "$run_id-web" curl -fsS -o /dev/null http://127.0.0.1:9501/live 2>/dev/null; then break; fi
        sleep 1
    done
    docker exec "$run_id-web" curl -fsS -o /dev/null http://127.0.0.1:9501/live || fail 'app:serve did not answer /live'
    docker exec "$run_id-web" sh -c 'tr "\0" " " < /proc/1/cmdline' | grep -q 'bin/console app:serve' ||
        fail 'app:serve is not PID 1'
}
# assert_stopped SCENARIO STARTED_AT SHUTDOWN_LINES: exit 0, before the stop timeout, through Swoole's shutdown.
assert_stopped() {
    local elapsed exit_code
    elapsed=$(awk -v a="$2" -v b="$(date +%s.%N)" 'BEGIN { printf "%.1f", b - a }')
    [ "$(docker inspect --format '{{.State.Running}}' "$run_id-web")" = false ] ||
        fail "$1: app:serve was still running $elapsed seconds after the signal"
    exit_code=$(docker inspect --format '{{.State.ExitCode}}' "$run_id-web")
    [ "$exit_code" = 0 ] || fail "$1: app:serve exited $exit_code after $elapsed seconds"
    [ "$(docker inspect --format '{{.State.OOMKilled}}' "$run_id-web")" = false ] || fail "$1: app:serve was OOM-killed"
    awk -v e="$elapsed" -v limit="$stop_timeout" 'BEGIN { exit !(e < limit) }' ||
        fail "$1: app:serve took $elapsed seconds, not less than the $stop_timeout-second stop timeout"
    [ "$(docker logs "$run_id-web" 2>&1 | grep -c 'Swoole HTTP Server has been successfully shutdown')" = "$3" ] ||
        fail "$1: the log does not show Swoole's shutdown"
    echo "PASS: $1: app:serve exited 0 after $elapsed seconds."
}

start_web
started_at=$(date +%s.%N)
docker stop -t "$stop_timeout" "$run_id-web" >/dev/null
assert_stopped 'docker stop (SIGTERM)' "$started_at" 1

start_web
started_at=$(date +%s.%N)
docker kill --signal INT "$run_id-web" >/dev/null
for attempt in $(seq 1 $((stop_timeout * 10))); do
    if [ "$(docker inspect --format '{{.State.Running}}' "$run_id-web")" = false ]; then break; fi
    sleep 0.1
done
assert_stopped 'SIGINT' "$started_at" 2
