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
# 2. app:serve as PID 1, started by docker/general/start-web.sh with PostgreSQL and Redis, and a
#    drain deadline WEB_DRAIN_SECONDS (D) of 10 seconds: window S = 3 s, worker stop wait W = 1 s.
#    Every stop route runs one drain (DrainCoordinator) and the server exits within D:
#    - `docker stop` (SIGTERM), SIGINT, DELETE /api/server and swoole:server:stop each log one
#      drain-start line and exit 0; SIGTERM followed by SIGINT still drains once;
#    - a PATCH /api/server reload keeps the server, the CPU pool's processes and an open
#      WebSocket, and starts no drain;
#    - each event worker drains on its own (WorkerDrain): an open WebSocket receives a close frame
#      with code 1012 before its connection ends, and a server with nothing streaming closes the
#      drain window as soon as every worker has reported drained, then stops within S + W;
#    - a request for /ready on a connection kept alive from before the stop gets 503 with
#      Connection: close while a stalled stream holds the window open;
#    - a client that stopped reading a progressive stream does not hold the server past D;
#    - a stopped event worker is killed by Swoole after 2W and the server still exits 0;
#    - a stopped manager is killed by the drain watchdog at D, and the server exits 1;
#    - with PostgreSQL down, the start-up cleanup logs its failure once and the server exits 0.
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
    exec env BAANDER_WEB_SHUTDOWN_TIMEOUT_ACTIVE=1 timeout 900s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
image="${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}"
run_id="baander-web-shutdown-$(date +%s)-$$"
# The drain deadline D of part 2, and the stop timeout D + 5 that operators must allow.
drain_seconds=10
stop_timeout=$((drain_seconds + 5))
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
    -e WEB_DRAIN_SECONDS="$drain_seconds"
)
# The client idles; it prepares the database and checkout and makes the probe's requests.
docker run -d --name "$run_id-client" --privileged --network "$run_id" --entrypoint sleep \
    "${app_env[@]}" "$image" infinity >/dev/null
client() {
    docker exec -i -w "$app_dir" "$run_id-client" "$@"
}
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" != 1 ]; then
    docker exec "$run_id-client" mkdir -p "$app_dir"
    tar -cf - vendor src tests/Fixtures/WebShutdown tests/Fixtures/WebRuntime tests/Fixtures/Auth tests/Fixtures/web-runtime.php config packages migrations bin docker/general templates public \
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
    if grep -q ' master_start ' <<<"$(docker logs "$run_id-probe" 2>&1)"; then break; fi
    sleep 0.1
done
grep -q ' master_start ' <<<"$(docker logs "$run_id-probe" 2>&1)" || fail 'the probe server did not start'
docker exec -d -w "$app_dir" "$run_id-client" \
    sh -c 'exec php tests/Fixtures/WebShutdown/shutdown-order-client.php probe > /tmp/shutdown-order-client.log 2>&1'
for attempt in $(seq 1 50); do
    if client grep -q ' ready ' /tmp/shutdown-order-client.log 2>/dev/null &&
        grep -q ' hold_received ' <<<"$(docker logs "$run_id-probe" 2>&1)" &&
        grep -q ' ws_open ' <<<"$(docker logs "$run_id-probe" 2>&1)"; then
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
# A listener, and a track whose progressive stream the stalled client opens.
listener=drain-listener@baander.app
printf '%s\n' 'Web-shutdown-test-only-1' | client php bin/console app:user:create --no-interaction \
    --password --role admin --force "$listener" 'Drain Listener' >/dev/null
client php bin/console app:settings:set transcode.enabled true --no-interaction >/dev/null
client php tests/Fixtures/web-runtime.php prepare >/dev/null
track=$(client php -r 'echo json_decode(file_get_contents("/tmp/baander-web-runtime.json"), true)["track"];')
docker create --name "$run_id-web" --privileged --network "$run_id" -w "$app_dir" "${app_env[@]}" \
    "$image" "$app_dir/docker/general/start-web.sh" >/dev/null
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" != 1 ]; then
    client tar -cf - . | docker cp -a - "$run_id-web:$app_dir"
fi
web() {
    docker exec -w "$app_dir" "$run_id-web" "$@"
}
# The log lines of the current run start after this many lines.
log_offset=0
run_log() {
    docker logs "$run_id-web" 2>&1 | tail -n +$((log_offset + 1))
}
start_web() {
    log_offset=$(docker logs "$run_id-web" 2>&1 | wc -l)
    docker start "$run_id-web" >/dev/null
    for attempt in $(seq 1 90); do
        if web curl -fsS -o /dev/null http://127.0.0.1:9501/live 2>/dev/null; then break; fi
        sleep 1
    done
    web curl -fsS -o /dev/null http://127.0.0.1:9501/live || fail 'app:serve did not answer /live'
    web sh -c 'tr "\0" " " < /proc/1/cmdline' | grep -q 'bin/console app:serve' || fail 'app:serve is not PID 1'
}
# The manager is the master's child that is not a CPU pool worker; the workers are its children.
manager_pid() {
    web sh -c 'for pid in $(pgrep -P 1); do tr "\0" " " < /proc/$pid/cmdline | grep -q "^cpu-pool-worker" || echo $pid; done' |
        grep -v '^$'
}
pool_pids() {
    web sh -c 'for pid in $(pgrep -P 1); do tr "\0" " " < /proc/$pid/cmdline | grep -q "^cpu-pool-worker" && echo $pid; done; true' | sort
}
worker_pids() {
    web pgrep -P "$(manager_pid)" | sort
}
# The event worker that answered an API server request: a worker, never a task worker.
event_worker_pid() {
    web sh -c 'curl -fsS http://127.0.0.1:9200/api/server |
        php -r "echo json_decode(stream_get_contents(STDIN), true)[\"server\"][\"processes\"][\"worker\"][\"pid\"];"'
}
# Starts a drain-client.php mode in the web container, writing its lines to /tmp/drain-<mode>.log.
start_client() {
    docker exec -d -w "$app_dir" "$run_id-web" sh -c \
        "exec php -d memory_limit=512M tests/Fixtures/WebShutdown/drain-client.php $* > /tmp/drain-$1.log 2>&1"
}
await_client() {
    for attempt in $(seq 1 600); do
        if web grep -q " $2 " "/tmp/drain-$1.log" 2>/dev/null; then return 0; fi
        sleep 0.1
    done
    web cat "/tmp/drain-$1.log" >&2 || true
    fail "the $1 client did not record $2"
}
await_exit() {
    for attempt in $(seq 1 $((stop_timeout * 10 + 50))); do
        if [ "$(docker inspect --format '{{.State.Running}}' "$run_id-web")" = false ]; then return 0; fi
        sleep 0.1
    done
}
# assert_stopped SCENARIO STARTED_AT EXIT_CODE LIMIT: app:serve exited with EXIT_CODE less than
# LIMIT seconds after STARTED_AT, through one drain.
assert_stopped() {
    local elapsed exit_code log
    elapsed=$(awk -v a="$2" -v b="$(date +%s.%N)" 'BEGIN { printf "%.1f", b - a }')
    [ "$(docker inspect --format '{{.State.Running}}' "$run_id-web")" = false ] ||
        fail "$1: app:serve was still running $elapsed seconds after the signal"
    exit_code=$(docker inspect --format '{{.State.ExitCode}}' "$run_id-web")
    [ "$(docker inspect --format '{{.State.OOMKilled}}' "$run_id-web")" = false ] || fail "$1: app:serve was OOM-killed"
    [ "$exit_code" = "$3" ] || fail "$1: app:serve exited $exit_code after $elapsed seconds, not $3"
    awk -v e="$elapsed" -v limit="$4" 'BEGIN { exit !(e < limit) }' ||
        fail "$1: app:serve took $elapsed seconds, not less than $4"
    log=$(run_log)
    [ "$(grep -c "Web server drain started: deadline $drain_seconds s, window 3 s, worker stop wait 1 s." <<<"$log")" = 1 ] ||
        fail "$1: the log does not show exactly one drain start"
    if [ "$3" = 0 ]; then
        [ "$(grep -c 'Swoole HTTP Server has been successfully shutdown' <<<"$log")" = 1 ] ||
            fail "$1: the log does not show Swoole's shutdown"
    fi
    echo "PASS: $1: app:serve exited $exit_code after $elapsed seconds."
}
# assert_workers_drained SCENARIO: every event worker reported drained, so the master closed the
# drain window well before the window S of 3 seconds.
assert_workers_drained() {
    local window
    window=$(run_log | sed -nE 's/^Drain window closed after ([0-9.]+) s, every worker drained; reset [0-9]+ client connection\(s\)\.$/\1/p')
    [ -n "$window" ] || fail "$1: the drain window did not close with every worker drained: $(run_log | grep '^Drain window closed' || true)"
    awk -v w="$window" 'BEGIN { exit !(w < 1.0) }' || fail "$1: the workers took $window seconds to report drained"
    echo "PASS: $1: every worker reported drained; the window closed after $window seconds."
}
# The drain window S and Swoole's worker stop wait W for a drain deadline of 10 seconds.
window_seconds=3
worker_stop_wait_seconds=1

# A reload keeps the server, the CPU pool and an open WebSocket, and starts no drain.
start_web
start_client ws "$listener"
await_client ws ws_pong
pool_before=$(pool_pids)
workers_before=$(worker_pids)
started_before=$(docker inspect --format '{{.State.StartedAt}}' "$run_id-web")
[ -n "$pool_before" ] || fail 'the CPU pool has no processes'
web curl -fsS -X PATCH -o /dev/null http://127.0.0.1:9200/api/server || fail 'PATCH /api/server failed'
for attempt in $(seq 1 100); do
    workers_after=$(worker_pids)
    if [ -z "$(comm -12 <(printf '%s\n' "$workers_before") <(printf '%s\n' "$workers_after"))" ]; then break; fi
    sleep 0.1
done
[ -z "$(comm -12 <(printf '%s\n' "$workers_before") <(printf '%s\n' "$workers_after"))" ] ||
    fail 'the reload did not replace every worker'
reloaded_at=$(date +%s.%N)
sleep 1
[ "$(docker inspect --format '{{.State.StartedAt}}' "$run_id-web")" = "$started_before" ] &&
    [ "$(docker inspect --format '{{.State.Running}}' "$run_id-web")" = true ] || fail 'the reload restarted the server'
[ "$(pool_pids)" = "$pool_before" ] || fail 'the reload replaced the CPU pool processes'
ws_log=$(web cat /tmp/drain-ws.log)
grep -qE ' ws_(closed|close_frame) ' <<<"$ws_log" && fail "the reload closed the WebSocket: $ws_log"
awk -v t="$reloaded_at" '$3 == "ws_pong" && $2 > t { found = 1 } END { exit !found }' <<<"$ws_log" ||
    fail 'the WebSocket answered no ping after the reload'
grep -q 'Web server drain started' <<<"$(run_log)" && fail 'the reload started a drain'
echo "PASS: PATCH /api/server reloaded every worker and kept the server, the CPU pool and the WebSocket, with no drain."
# The WebSocket is still open: its worker closes it with 1012 ("service restart") during the
# drain, before the connection ends.
started_at=$(date +%s.%N)
docker stop -t "$stop_timeout" "$run_id-web" >/dev/null
assert_stopped 'docker stop (SIGTERM)' "$started_at" 0 "$drain_seconds"
assert_workers_drained 'docker stop (SIGTERM) with a WebSocket open'
# The container has stopped, so the client's log is copied out of it.
ws_log=$(docker cp "$run_id-web:/tmp/drain-ws.log" - | tar -xOf -)
close_frame=$(awk '$3 == "ws_close_frame" { print NR, $4, $5, $6; exit }' <<<"$ws_log")
closed=$(awk '$3 == "ws_closed" { print NR; exit }' <<<"$ws_log")
[ "${close_frame#* }" = 'code=1012 reason=service restart' ] || fail "the WebSocket got no 1012 close frame: $ws_log"
# A close frame can only arrive while the connection is open. The client runs inside the web
# container, which may end it before it logs the connection's end, so a missing ws_closed line
# is not a failure; a ws_closed line before the close frame is.
[ -z "$closed" ] || [ "${close_frame%% *}" -lt "$closed" ] || fail "the WebSocket ended before its close frame: $ws_log"
echo "PASS: the open WebSocket received close code 1012 before its connection ended."

# Nothing is streaming: the window closes once every worker has reported, and the workers, with
# no timer left, exit at once.
start_web
started_at=$(date +%s.%N)
docker kill --signal INT "$run_id-web" >/dev/null
await_exit
assert_stopped 'SIGINT' "$started_at" 0 $((window_seconds + worker_stop_wait_seconds))
assert_workers_drained 'SIGINT'

start_web
started_at=$(date +%s.%N)
web curl -fsS -X DELETE -o /dev/null http://127.0.0.1:9200/api/server || fail 'DELETE /api/server failed'
await_exit
assert_stopped 'DELETE /api/server' "$started_at" 0 "$drain_seconds"
assert_workers_drained 'DELETE /api/server'

# The command waits for the server to exit, so it ends with the container.
start_web
started_at=$(date +%s.%N)
docker exec -d -w "$app_dir" "$run_id-web" php bin/console swoole:server:stop --no-interaction
await_exit
assert_stopped 'swoole:server:stop' "$started_at" 0 "$drain_seconds"
assert_workers_drained 'swoole:server:stop'

start_web
started_at=$(date +%s.%N)
docker kill --signal TERM "$run_id-web" >/dev/null
sleep 1
docker kill --signal INT "$run_id-web" >/dev/null 2>&1 || true
await_exit
assert_stopped 'SIGTERM, then SIGINT a second later' "$started_at" 0 "$drain_seconds"

# The master resets the stalled connection when the window ends, so joining its reactor threads
# does not wait for output the client will never read. The stalled stream also holds the window
# open while a connection kept alive from before the stop asks for /ready.
start_web
start_client stalled-stream "$listener" "$track"
await_client stalled-stream stalled
start_client keepalive-ready "$listener"
await_client keepalive-ready ready
started_at=$(date +%s.%N)
docker kill --signal TERM "$run_id-web" >/dev/null
await_exit
assert_stopped 'a client that stopped reading a progressive stream' "$started_at" 0 "$drain_seconds"
grep -qE '^Drain window closed after [0-9.]+ s, the window passed; reset [1-9][0-9]* client connection\(s\)\.$' <<<"$(run_log)" ||
    fail 'the window did not reset the stalled connection'
ready_log=$(docker cp "$run_id-web:/tmp/drain-keepalive-ready.log" - | tar -xOf -)
[ "$(awk '$3 == "ready" { print $4, $5; exit }' <<<"$ready_log")" = 'status=200 connection=keep-alive' ] ||
    fail "the kept-alive connection was not ready before the stop: $ready_log"
[ "$(awk '$3 == "ready" { last = $4 " " $5 } END { print last }' <<<"$ready_log")" = 'status=503 connection=close' ] ||
    fail "/ready on the kept-alive connection did not answer 503 with Connection: close during the drain: $ready_log"
grep -q ' ready_eof ' <<<"$ready_log" && fail "the kept-alive connection ended before its 503: $ready_log"
echo "PASS: /ready on a connection kept alive from before the stop answered 503 with Connection: close."

# Swoole's manager kills a worker that does not stop within 2W.
start_web
stopped_worker=$(event_worker_pid)
web kill -STOP "$stopped_worker"
started_at=$(date +%s.%N)
docker kill --signal TERM "$run_id-web" >/dev/null
await_exit
assert_stopped "an event worker stopped with SIGSTOP (pid $stopped_worker)" "$started_at" 0 "$drain_seconds"

# The watchdog kills a manager that never stops, and the master exits 1.
start_web
stopped_manager=$(manager_pid)
web kill -STOP "$stopped_manager"
started_at=$(date +%s.%N)
docker kill --signal TERM "$run_id-web" >/dev/null
await_exit
assert_stopped "the manager stopped with SIGSTOP (pid $stopped_manager)" "$started_at" 1 "$stop_timeout"
elapsed=$(awk -v a="$started_at" -v b="$(date +%s.%N)" 'BEGIN { printf "%.1f", b - a }')
awk -v e="$elapsed" -v d="$drain_seconds" 'BEGIN { exit !(e >= d - 0.5) }' || fail "the watchdog fired after $elapsed seconds, before D"
grep -qE "^The web server did not stop by its drain deadline: killed processes \[([0-9]+, )*$stopped_manager(, [0-9]+)*\]" <<<"$(run_log)" ||
    fail 'the watchdog did not report killing the manager'
grep -q '^The web server reached its drain deadline; exiting with status 1.$' <<<"$(run_log)" || fail 'the master did not report the deadline'
echo "PASS: the watchdog killed the stopped manager at the deadline."

# The start-up listeners survive an unreachable PostgreSQL, and the stop still drains. This start
# also follows the watchdog's unclean exit in the same container.
docker stop "$run_id-postgres" >/dev/null
start_web
started_at=$(date +%s.%N)
docker kill --signal TERM "$run_id-web" >/dev/null
await_exit
assert_stopped 'SIGTERM with PostgreSQL stopped' "$started_at" 0 "$drain_seconds"
[ "$(run_log | grep -c 'Could not clean up orphaned recommendation jobs at server start')" = 1 ] ||
    fail 'the start-up cleanup did not log its database failure once'
echo "PASS: the start-up cleanup logged the unreachable database once."
