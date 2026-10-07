#!/usr/bin/env bash
# Historical Messenger transport recovery under Supervisor. Deployment startup
# uses app:serve/app:worker; this fixture does not describe deployed supervision.
set -euo pipefail
cd "$(dirname "$0")/.."
run_id="baander-worker-$(date +%s)-$$"
cleanup() {
    docker rm -f "$run_id-app" "$run_id-redis" "$run_id-postgres" >/dev/null 2>&1 || true
    docker network rm "$run_id" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$run_id" >/dev/null
docker run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    -e REDIS_ARGS='--requirepass test-only' redis/redis-stack-server:edge >/dev/null
docker run -d --name "$run_id-postgres" --network "$run_id" --network-alias postgres \
    -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=worker_test \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}" >/dev/null
for attempt in $(seq 1 30); do
    if docker exec "$run_id-postgres" pg_isready -U baander -d worker_test >/dev/null 2>&1 &&
        docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG; then
        break
    fi
    sleep 1
done
docker exec "$run_id-postgres" pg_isready -U baander -d worker_test >/dev/null
docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG

archive_paths=(vendor src tests config packages migrations bin docker/general templates public phpunit.xml.dist
    .env .env.test composer.json composer.lock translations)
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" = 1 ]; then
    archive_paths=(--files-from /dev/null)
fi
tar -cf - "${archive_paths[@]}" |
    docker run --rm --name "$run_id-app" --privileged --network "$run_id" -i --entrypoint sh \
        -e BAANDER_TEST_CHECKOUT_IN_IMAGE="${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" \
        -e APP_ENV=prod -e APP_DEBUG=0 -e XDEBUG_MODE=off -e REDIS_PASSWORD=test-only \
        -e REDIS_URL=redis://default:test-only@redis:6379 \
        -e MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages \
        -e DATABASE_URL="postgresql://baander:test-only@postgres:5432/worker_test?serverVersion=18&charset=utf8" \
        "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}" -c '
            set -eu
            # The image owns /var/www/html/vendor as root, so a host checkout is unpacked into a
            # directory the www-data user can write.
            app_dir=/var/www/html
            if [ "$BAANDER_TEST_CHECKOUT_IN_IMAGE" != 1 ]; then
                app_dir=/tmp/baander-worker-runtime
                mkdir -p "$app_dir"
            fi
            cd "$app_dir"
            tar -xf -
            php tests/Fixtures/messaging-runtime.php prepare
            # Exercise historical transport recovery without a deployed supervisor or HTTP server.
            python3 - "$app_dir" <<"PY"
import configparser
import sys
config = configparser.RawConfigParser()
config.read("tests/Fixtures/Worker/legacy-supervisord.conf")
config.remove_section("program:swoole")
# Run the consumers from the checkout under test.
for section in config.sections():
    for option in ("command", "directory"):
        if config.has_option(section, option):
            config.set(section, option, config.get(section, option).replace("/var/www/html", sys.argv[1]))
with open("/tmp/worker-supervisord.conf", "w") as output:
    config.write(output)
PY
            supervisord -c /tmp/worker-supervisord.conf > /tmp/worker-supervisord.log 2>&1 &
            supervisor_pid=$!
            trap "kill -TERM $supervisor_pid 2>/dev/null || true; wait $supervisor_pid || true" EXIT
            await_check() {
                for attempt in $(seq 1 30); do
                    if php tests/Fixtures/messaging-runtime.php "$@"; then return 0; fi
                    sleep 1
                done
                cat /tmp/worker-supervisord.log >&2
                return 1
            }
            await_check ready
            php tests/Fixtures/messaging-runtime.php send
            await_check handled 1
            # Hold the real handler in a database operation, then kill it before ack.
            php tests/Fixtures/messaging-runtime.php hold-lock &
            lock_pid=$!
            for attempt in $(seq 1 30); do
                test ! -f /tmp/baander-worker-test-lock-held || break
                sleep 1
            done
            test -f /tmp/baander-worker-test-lock-held
            php tests/Fixtures/messaging-runtime.php send
            await_check busy
            original_pid=$(php -r '\''echo json_decode(file_get_contents("/tmp/baander-messenger-async.json"), true)["pid"];'\'')
            kill -KILL "$original_pid"
            if php tests/Fixtures/messaging-runtime.php ready; then
                echo "Killed worker incorrectly reported ready" >&2; exit 1
            fi
            touch /tmp/baander-worker-test-release-lock
            wait "$lock_pid"
            await_check ready
            replacement_pid=$(php -r '\''echo json_decode(file_get_contents("/tmp/baander-messenger-async.json"), true)["pid"];'\'')
            test "$original_pid" != "$replacement_pid"
            await_check handled 2
            php tests/Fixtures/messaging-runtime.php one-row-per-job 2
            kill -TERM "$supervisor_pid"
            wait "$supervisor_pid"
            trap - EXIT
            if php tests/Fixtures/messaging-runtime.php ready; then
                echo "Stopped worker incorrectly reported ready" >&2; exit 1
            fi
            echo "Supervisor consumed jobs, recovered unacknowledged work after SIGKILL, and stopped cleanly."
        '
