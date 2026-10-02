#!/usr/bin/env bash
set -euo pipefail
if [ "${BAANDER_WORKER_COMMAND_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_WORKER_COMMAND_TIMEOUT_ACTIVE=1 timeout 240s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
run_id="baander-worker-command-$(date +%s)-$$"
app_image="${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}"
containers=()
cleanup() {
    docker rm -f "${containers[@]}" "$run_id-prepare" "$run_id-observer" "$run_id-redis" "$run_id-postgres" >/dev/null 2>&1 || true
    docker volume rm "$run_id-workspace" >/dev/null 2>&1 || true
    docker network rm "$run_id" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$run_id" >/dev/null
docker volume create "$run_id-workspace" >/dev/null
docker run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    -e REDIS_ARGS='--requirepass test-only' redis/redis-stack-server:edge >/dev/null
docker run -d --name "$run_id-postgres" --network "$run_id" --network-alias postgres \
    -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=worker_command_test \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}" >/dev/null
ready=false
for attempt in $(seq 1 30); do
    if docker exec "$run_id-postgres" pg_isready -h 127.0.0.1 -U baander -d worker_command_test >/dev/null 2>&1 &&
        docker exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli ping | grep -qx PONG; then
        ready=true
        break
    fi
    sleep 1
done
if [ "$ready" != true ]; then
    echo 'Disposable worker command services did not become ready.' >&2
    exit 1
fi
common=(--network "$run_id" --volume "$run_id-workspace:/workspace" --workdir /workspace
    -e APP_ENV=prod -e APP_DEBUG=0 -e XDEBUG_MODE=off
    -e REDIS_PASSWORD=test-only -e REDIS_URL=redis://default:test-only@redis:6379
    -e MESSENGER_TRANSPORT_DSN=redis://default:test-only@redis:6379/messages
    -e DATABASE_URL=postgresql://baander:test-only@postgres:5432/worker_command_test?serverVersion=18\&charset=utf8)
# A new named volume is root-owned; preserve the application image's ordinary UID/GID.
app_uid="$(docker run --rm --entrypoint id "$app_image" -u)"
app_gid="$(docker run --rm --entrypoint id "$app_image" -g)"
[[ "$app_uid" =~ ^[0-9]+$ && "$app_gid" =~ ^[0-9]+$ ]]
# Seed the volume so Docker's next empty-volume copy-up cannot restore image ownership.
docker run --rm "${common[@]}" --user 0 --entrypoint sh "$app_image" -c 'touch /workspace/.fixture-ready; chown "$1:$2" /workspace' sh "$app_uid" "$app_gid"
archive_paths=(vendor src tests config packages migrations bin docker/general templates public
    .env .env.test composer.json composer.lock translations)
tar -cf - "${archive_paths[@]}" |
    docker run --rm --name "$run_id-prepare" "${common[@]}" -i --entrypoint sh "$app_image" -c '
        set -eu
        tar -xf -
        php -d memory_limit=512M bin/console cache:clear --env=prod --no-debug
        php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=prod
        php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=prod
    '

launch() {
    local container="$1" namespace="$2" boot="$3"
    containers+=("$container")
    docker run -d --name "$container" "${common[@]}" --memory=1024m --memory-swap=1024m \
        --pids-limit=64 --restart=no --entrypoint sh "$app_image" -c '
            set -eu
            mkdir -m 700 /tmp/baander-worker-locks
            exec php -d memory_limit=256M bin/console app:worker --deployment="$1" --boot-id="$2" \
                --memory-mib=1024 --management-mib=256 --consumer-mib=384 --relay-mib=384 \
                --lock-dir=/tmp/baander-worker-locks --no-interaction
        ' sh "$namespace" "$boot" >/dev/null
}
await_ready() {
    local container="$1" namespace="$2" boot="$3"
    for attempt in $(seq 1 25); do
        if docker exec "$container" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php ready "$namespace" "$boot" > "/tmp/$container-ready" 2>&1; then
            rm -f "/tmp/$container-ready"
            return
        fi
        if [ "$(docker inspect --format '{{.State.Running}}' "$container")" != true ]; then
            break
        fi
        sleep 0.2
    done
    tail -15 "/tmp/$container-ready" >&2
    rm -f "/tmp/$container-ready"
    docker logs --tail 60 "$container" >&2
    echo 'Production worker roles / lease / Redis consumer did not become ready.' >&2
    exit 1
}
await_exit() {
    local container="$1" expected="$2" launches="$3" wait_seconds="${4:-45}"
    if ! timeout "${wait_seconds}s" docker wait "$container" > "/tmp/$container-exit"; then
        docker logs --tail 60 "$container" >&2
        echo 'Worker did not contain and reap children within the 35-second drain deadline plus 10-second harness headroom.' >&2
        exit 1
    fi
    local code
    code="$(cat "/tmp/$container-exit")"
    rm -f "/tmp/$container-exit"
    if [ "$code" != "$expected" ]; then
        docker logs --tail 60 "$container" >&2
        echo "Expected worker exit $expected, got $code." >&2
        exit 1
    fi
    # This summary is emitted only after all direct handles are reaped; two launches
    # prove the crashed child was not replaced, and denial must have launched none.
    docker logs "$container" > "/tmp/$container-summary" 2>&1
    python3 - "/tmp/$container-summary" "$expected" "$launches" <<'PYSUMMARY'
import json, sys
frames = []
for line in open(sys.argv[1]):
    try:
        frame = json.loads(line)
    except ValueError:
        continue
    if isinstance(frame, dict) and frame.get('event') == 'worker_supervisor_stopped':
        frames.append(frame)
assert frames == [{'event': 'worker_supervisor_stopped', 'launchAttempts': int(sys.argv[3]), 'exitCode': int(sys.argv[2])}], frames
PYSUMMARY
    rm -f "/tmp/$container-summary"
    # A stopped PID-1 deployment has no running descendants in its private namespace.
    test "$(docker inspect --format '{{.State.Pid}}' "$container")" = 0
}
reserved() {
    docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
        -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php reserved "$1" "$2"
}

boot="$(php -r 'echo bin2hex(random_bytes(16));')"
namespace=baander.app:commandtest
launch "$run_id-crash" "$namespace" "$boot"
await_ready "$run_id-crash" "$namespace" "$boot"
docker exec "$run_id-crash" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php kill-consumer "$namespace" "$boot"
await_exit "$run_id-crash" 1 2
reserved "$namespace" "$boot"

# An active predecessor reservation denies even its original boot; admission has no retry shortcut.
launch "$run_id-denied" "$namespace" "$boot"
await_exit "$run_id-denied" 1 0
reserved "$namespace" "$boot"

term_boot="$(php -r 'echo bin2hex(random_bytes(16));')"
term_namespace=baander.app:commandterm
launch "$run_id-term" "$term_namespace" "$term_boot"
await_ready "$run_id-term" "$term_namespace" "$term_boot"
docker kill --signal=TERM "$run_id-term" >/dev/null
await_exit "$run_id-term" 0 2
reserved "$term_namespace" "$term_boot"
expiry_boot="$(php -r 'echo bin2hex(random_bytes(16));')"
expiry_namespace=baander.app:commandexpiry
launch "$run_id-expiry" "$expiry_namespace" "$expiry_boot"
await_ready "$run_id-expiry" "$expiry_namespace" "$expiry_boot"
docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php expire "$expiry_namespace" "$expiry_boot"
# Allow the renewal schedule plus its bounded 35-second drain and harness headroom.
await_exit "$run_id-expiry" 1 2 60
reserved "$expiry_namespace" "$expiry_boot"
echo 'Real app:worker crash, TERM, same-boot denial, and renewal loss acceptance passed; reservations remain active.'
