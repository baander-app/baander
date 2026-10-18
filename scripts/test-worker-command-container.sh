#!/usr/bin/env bash
set -euo pipefail
if [ "${BAANDER_WORKER_COMMAND_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_WORKER_COMMAND_TIMEOUT_ACTIVE=1 timeout 600s bash "$0" "$@"
fi
cd "$(dirname "$0")/.."
run_id="baander-worker-command-$(date +%s)-$$"
app_image="${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}"
autoscaling_profile="$(cat tests/Fixtures/Worker/autoscaling-profile.json)"
containers=()
ipc_volumes=()
cleanup() {
    docker rm -f "${containers[@]}" "$run_id-prepare" "$run_id-observer" "$run_id-redis" "$run_id-postgres" >/dev/null 2>&1 || true
    docker volume rm "${ipc_volumes[@]}" "$run_id-workspace" >/dev/null 2>&1 || true
    docker network rm "$run_id" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$run_id" >/dev/null
docker volume create "$run_id-workspace" >/dev/null
docker run -d --name "$run_id-redis" --network "$run_id" --network-alias redis \
    -e REDIS_ARGS='--requirepass test-only' redis/redis-stack-server:edge >/dev/null
# The test gate blocks a query; promptly detect the killed client while it waits.
docker run -d --name "$run_id-postgres" --network "$run_id" --network-alias postgres \
    -e POSTGRES_USER=baander -e POSTGRES_PASSWORD=test-only -e POSTGRES_DB=worker_command_test \
    "${BAANDER_TEST_POSTGRES_IMAGE:-baander-database:latest}" \
    postgres -c client_connection_check_interval=100ms >/dev/null
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
    -e CONVERT_STORAGE_PATH=/workspace/storage/conversions
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
if [ "${BAANDER_TEST_CHECKOUT_IN_IMAGE:-0}" = 1 ]; then
    # CI source and dependencies live in the built image, not the host checkout.
    docker run --rm --name "$run_id-prepare" "${common[@]}" --entrypoint sh "$app_image" -c 'set -eu; cd /var/www/html; tar -cf /tmp/worker-command-checkout.tar .; tar -xf /tmp/worker-command-checkout.tar -C /workspace; rm /tmp/worker-command-checkout.tar'
    archive_paths=(--files-from /dev/null)
fi
tar -cf - "${archive_paths[@]}" |
    docker run --rm --name "$run_id-prepare" "${common[@]}" -i --entrypoint sh "$app_image" -c '
        set -eu
        tar -xf -
        mkdir -p storage/conversions
        cp tests/Fixtures/Worker/ConsumerAckFixture.php src/Shared/Infrastructure/Worker/ConsumerAckFixture.php
        php -d memory_limit=512M bin/console cache:clear --env=prod --no-debug
        php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=prod
        php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=prod
        php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php prepare-consumer-ack baander.app:commandtest aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
        php -d memory_limit=256M tests/Fixtures/Worker/worker-command-check.php seed-outbox baander.app:commandtest aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
    '

journal() {
    docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
        -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php "$@"
}
launch() {
    local container="$1" namespace="$2" boot="$3" denied="${4:-0}"
    local ipc="$container-ipc" unit name memory pids cpus console id
    ipc_volumes+=("$ipc")
    docker volume create "$ipc" >/dev/null
    docker run --rm --user 0 --volume "$ipc:/run/baander-worker" --entrypoint sh "$app_image" \
        -c 'chown "$1:$2" /run/baander-worker; chmod 700 /run/baander-worker' sh "$app_uid" "$app_gid"
    if [ "$denied" != 1 ]; then journal reserve-pair "$namespace" "$boot"; fi
    for unit in control execution; do
        name="$container"; memory=2944; pids=129; cpus=0; console=0
        if [ "$unit" = execution ]; then name="$container-execution"; memory=4480; pids=256; cpus=1,2; console=320; fi
        containers+=("$name")
        if [ "$denied" != 1 ]; then journal claim-unit "$namespace" "$boot" "$unit"; fi
        id="$(docker create --name "$name" "${common[@]}" --volume "$ipc:/run/baander-worker" \
            -e BAANDER_WORKER_LEASE_EPOCH=9999 -e BAANDER_WORKER_UNIT="$unit" \
            --memory="${memory}m" --memory-swap="${memory}m" --cpuset-cpus="$cpus" --pids-limit="$pids" \
            --restart=no --entrypoint sh "$app_image" -c '
                set -eu
                mkdir -m 700 /tmp/baander-worker-locks
                exec php -d memory_limit=256M bin/console app:worker --deployment="$1" --boot-id="$2" \
                    --unit="$3" --memory-mib="$4" --pids-limit="$5" --scheduled-console-mib="$6" \
                    --autoscaling-profile="$7" --lock-dir=/tmp/baander-worker-locks --ipc-dir=/run/baander-worker --no-interaction
            ' sh "$namespace" "$boot" "$unit" "$memory" "$pids" "$console" "$autoscaling_profile")"
        if [ "$denied" != 1 ]; then journal register-unit "$namespace" "$boot" "$unit" "$id"; fi
    done
    for unit in control execution; do
        name="$container"; if [ "$unit" = execution ]; then name="$container-execution"; fi
        if [ "$denied" != 1 ]; then
            id="$(docker inspect --format '{{.Id}}' "$name")"
            journal start-unit "$namespace" "$boot" "$unit" "$id"
        fi
        docker start "$name" >/dev/null
        if [ "$unit" = control ] && [ "$denied" != 1 ]; then
            local admitted=false
            for attempt in $(seq 1 50); do
                if docker exec "$container" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php lease-ready "$namespace" "$boot" >/dev/null 2>&1; then admitted=true; break; fi
                sleep 0.1
            done
            if [ "$admitted" != true ]; then docker logs "$container" >&2; exit 1; fi
        fi
    done
}
await_ready() {
    local container="$1" namespace="$2" boot="$3"
    for attempt in $(seq 1 200); do
        if docker exec "$container" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php ready "$namespace" "$boot" > "/tmp/$container-ready" 2>&1 &&
            docker exec "$container-execution" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php ready "$namespace" "$boot" > "/tmp/$container-execution-ready" 2>&1; then
            docker logs "$container" > "/tmp/$container-media-ready" 2>&1
            if python3 - "/tmp/$container-ready" "/tmp/$container-media-ready" "$namespace" "$boot" <<'PYREADY'
import json, sys
with open(sys.argv[1]) as result:
    roles = json.load(result)
expected = {
    'deploymentId': sys.argv[3],
    'supervisorBootId': sys.argv[4],
    'workerId': 'media',
    'generation': 1,
}
for line in open(sys.argv[2]):
    try:
        frame = json.loads(line)
    except ValueError:
        continue
    if isinstance(frame, dict) and frame.get('event') == 'worker_media_ready' and frame.get('identity') == expected and frame.get('pid') == roles['media']:
        sys.exit(0)
sys.exit(1)
PYREADY
            then
                rm -f "/tmp/$container-ready" "/tmp/$container-execution-ready" "/tmp/$container-media-ready"
                return
            fi
        fi
        if [ "$(docker inspect --format '{{.State.Running}}' "$container")" != true ]; then
            break
        fi
        sleep 0.2
    done
    tail -15 "/tmp/$container-ready" >&2
    rm -f "/tmp/$container-ready" "/tmp/$container-execution-ready" "/tmp/$container-media-ready"
    docker logs --tail 60 "$container" >&2
    docker logs --tail 60 "$container-execution" >&2
    echo 'Production worker roles / initialized media / lease / Redis consumer did not become ready.' >&2
    exit 1
}
await_unit_exit() {
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
    # The summary follows reaping; the exact count proves no replacement launch.
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
await_exit() {
    local container="$1" expected="$2" launches="$3" wait_seconds="${4:-45}"
    local control_launches=4 execution_launches=2 control_exit=1 execution_exit=1
    if [ "$launches" = 0 ]; then
        control_launches=0; execution_launches=0
    else
        test "$launches" = "$((control_launches + execution_launches))"
    fi
    if [ "$expected" = 0 ]; then control_exit=0; fi
    await_unit_exit "$container" "$control_exit" "$control_launches" "$wait_seconds"
    await_unit_exit "$container-execution" "$execution_exit" "$execution_launches" "$wait_seconds"
}
await_outbox() {
    local container="$1" attempts="${2:-30}"
    for attempt in $(seq 1 "$attempts"); do
        if docker exec "$container" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php verify-outbox baander.app:commandtest aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa > "/tmp/$container-outbox" 2>&1; then
            cat "/tmp/$container-outbox"
            rm -f "/tmp/$container-outbox"
            return
        fi
        sleep 0.2
    done
    cat "/tmp/$container-outbox" >&2
    rm -f "/tmp/$container-outbox"
    docker logs --tail 60 "$container" >&2
    echo 'Real worker outbox projection/handoff did not complete within the bounded check.' >&2
    exit 1
}
await_scheduler() {
    local container="$1" namespace="$2" boot="$3"
    for attempt in $(seq 1 50); do
        if docker exec "$container" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php verify-scheduler "$namespace" "$boot" > "/tmp/$container-scheduler" 2>&1; then
            cat "/tmp/$container-scheduler"
            rm -f "/tmp/$container-scheduler"
            return
        fi
        sleep 0.2
    done
    cat "/tmp/$container-scheduler" >&2
    rm -f "/tmp/$container-scheduler"
    docker logs --tail 60 "$container" >&2
    echo 'Real manual scheduler occurrence did not finish and acknowledge exactly once.' >&2
    exit 1
}
reserved() {
    docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
        -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php reserved "$1" "$2"
}

# Invalid transport topology must fail before locks, lease authority or child launch.
for invalid_case in path query scheduler; do
    invalid_container="$run_id-invalid-$invalid_case"
    invalid_namespace="baander.app:invalid-$invalid_case"
    invalid_setting=MESSENGER_TRANSPORT_DSN
    case "$invalid_case" in
        path) invalid_dsn='redis://default:fixture-secret@redis:6379/messages/baander/fixed' ;;
        query) invalid_dsn='redis://default:fixture-secret@redis:6379/messages?consumer=fixed' ;;
        scheduler) invalid_setting=REDIS_URL; invalid_dsn='redis://default:fixture-secret@redis:6379/other' ;;
    esac
    containers+=("$invalid_container")
    docker run -d --name "$invalid_container" "${common[@]}" -e "$invalid_setting=$invalid_dsn" \
        --entrypoint php "$app_image" -d memory_limit=256M bin/console app:worker \
        --deployment="$invalid_namespace" --boot-id=ffffffffffffffffffffffffffffffff \
        --unit=control --memory-mib=2944 --pids-limit=129 --scheduled-console-mib=0 \
        --autoscaling-profile="$autoscaling_profile" --ipc-dir=/run/baander-worker --lock-dir=/tmp/baander-invalid-lock --no-interaction >/dev/null
    invalid_exit="$(timeout 15s docker wait "$invalid_container")"
    test "$invalid_exit" != 0
    docker logs "$invalid_container" > "/tmp/$invalid_container-log" 2>&1
    python3 - "/tmp/$invalid_container-log" "$invalid_setting" <<'PYINVALID'
from pathlib import Path
import sys
output = Path(sys.argv[1]).read_text()
assert sys.argv[2] in output, output
assert 'fixture-secret' not in output
assert 'worker_supervisor_stopped' not in output
PYINVALID
    rm -f "/tmp/$invalid_container-log"
    if docker diff "$invalid_container" | grep -q '/tmp/baander-invalid-lock'; then
        echo 'Invalid topology created a worker lock directory.' >&2
        exit 1
    fi
    test "$(docker exec "$run_id-postgres" psql -U baander -d worker_command_test -Atc \
        "SELECT count(*) FROM worker_deployment_leases WHERE namespace = '$invalid_namespace'")" = 0
done
echo 'Transport topology rejected before locks and authority; credentials redacted (3 cases).'

# Block only the first renewal, after the event claim's autocommit boundary.
claim_boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
claim_namespace=baander.app:commandclaimcrash
containers+=("$run_id-claim-gate")
docker run -d --name "$run_id-claim-gate" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php hold-relay-claim "$claim_namespace" "$claim_boot" >/dev/null
gate_ready=false
for attempt in $(seq 1 30); do
    if docker logs "$run_id-claim-gate" 2>&1 | grep -qx 'Claim gate armed.'; then
        gate_ready=true
        break
    fi
    sleep 0.2
done
if [ "$gate_ready" != true ]; then
    docker logs "$run_id-claim-gate" >&2
    exit 1
fi
launch "$run_id-claim-crash" "$claim_namespace" "$claim_boot"
await_ready "$run_id-claim-crash" "$claim_namespace" "$claim_boot"
claim_snapshot=""
for attempt in $(seq 1 30); do
    if claim_snapshot="$(docker exec "$run_id-claim-crash" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php verify-claim-blocked "$claim_namespace" "$claim_boot" 2>/dev/null)"; then
        break
    fi
    sleep 0.2
done
claim_token="$(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["lease_token"])' "$claim_snapshot")"
claim_until="$(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["lease_until"])' "$claim_snapshot")"
[[ "$claim_token" =~ ^[a-f0-9]{64}$ ]]
docker exec "$run_id-claim-crash" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php kill-relay-claimed "$claim_namespace" "$claim_boot"
await_exit "$run_id-claim-crash" 1 6
reserved "$claim_namespace" "$claim_boot"
docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php verify-claim-contained "$claim_namespace" "$claim_boot" "$claim_token" "$claim_until"
docker rm -f "$run_id-claim-gate" >/dev/null
docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php release-relay-claim "$claim_namespace" "$claim_boot"
# Recover the pending event under a distinct admitted deployment, then kill the
# relay during ORM flush while its receipt/projection transaction is still open.
projection_boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
projection_namespace=baander.app:commandprojectioncrash
containers+=("$run_id-projection-gate")

docker run -d --name "$run_id-projection-gate" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
    hold-relay-projection "$projection_namespace" "$projection_boot" >/dev/null

gate_ready=false
for attempt in $(seq 1 30); do
    if docker logs "$run_id-projection-gate" 2>&1 | grep -qx 'Projection gate armed.'; then
        gate_ready=true
        break
    fi
    sleep 0.2
done

if [ "$gate_ready" != true ]; then
    docker logs "$run_id-projection-gate" >&2
    exit 1
fi

launch "$run_id-projection-crash" "$projection_namespace" "$projection_boot"
await_ready "$run_id-projection-crash" "$projection_namespace" "$projection_boot"
docker exec "$run_id-projection-crash" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php verify-claim-contained \
    "$projection_namespace" "$projection_boot" "$claim_token" "$claim_until"

projection_snapshot=""
for attempt in $(seq 1 350); do
    if projection_snapshot="$(docker exec "$run_id-projection-crash" php -d memory_limit=64M \
        tests/Fixtures/Worker/worker-command-check.php verify-projection-blocked \
        "$projection_namespace" "$projection_boot" 2>/dev/null)"; then
        break
    fi
    sleep 0.2
done

if [ -z "$projection_snapshot" ]; then
    docker logs --tail 60 "$run_id-projection-crash" >&2
    echo 'Relay did not reach the projection transaction within the bounded check.' >&2
    exit 1
fi

projection_token="$(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["lease_token"])' "$projection_snapshot")"
projection_until="$(python3 -c 'import json, sys; print(json.loads(sys.argv[1])["lease_until"])' "$projection_snapshot")"
[[ "$projection_token" =~ ^[a-f0-9]{64}$ && "$projection_token" != "$claim_token" ]]

docker exec "$run_id-projection-crash" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php kill-relay-projecting \
    "$projection_namespace" "$projection_boot"
await_exit "$run_id-projection-crash" 1 6
reserved "$projection_namespace" "$projection_boot"

docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
    verify-projection-contained "$projection_namespace" "$projection_boot" \
    "$projection_token" "$projection_until"

docker rm -f "$run_id-projection-gate" >/dev/null
docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
    release-relay-projection "$projection_namespace" "$projection_boot"

# Interrupt the first Redis handoff after acceptance, before the intent ACK.
delivery_boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
delivery_namespace=baander.app:commanddeliveryackcrash
containers+=("$run_id-delivery-ack-gate")

docker run -d --name "$run_id-delivery-ack-gate" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
    hold-relay-delivery-ack "$delivery_namespace" "$delivery_boot" >/dev/null

gate_ready=false
for attempt in $(seq 1 30); do
    if docker logs "$run_id-delivery-ack-gate" 2>&1 | grep -qx 'Delivery acknowledgement gate armed.'; then
        gate_ready=true
        break
    fi
    sleep 0.2
done

if [ "$gate_ready" != true ]; then
    docker logs "$run_id-delivery-ack-gate" >&2
    exit 1
fi

launch "$run_id-delivery-ack-crash" "$delivery_namespace" "$delivery_boot"
await_ready "$run_id-delivery-ack-crash" "$delivery_namespace" "$delivery_boot"
docker exec "$run_id-delivery-ack-crash" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php verify-projection-contained \
    "$delivery_namespace" "$delivery_boot" "$projection_token" "$projection_until"

delivery_snapshot=""
for attempt in $(seq 1 350); do
    if delivery_snapshot="$(docker exec "$run_id-delivery-ack-crash" php -d memory_limit=64M \
        tests/Fixtures/Worker/worker-command-check.php verify-delivery-ack-blocked \
        "$delivery_namespace" "$delivery_boot" 2>/dev/null)"; then
        break
    fi
    sleep 0.2
done

if [ -z "$delivery_snapshot" ]; then
    docker logs --tail 60 "$run_id-delivery-ack-crash" >&2
    echo 'Relay did not reach the post-send delivery acknowledgement boundary.' >&2
    exit 1
fi

docker exec "$run_id-delivery-ack-crash" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php verify-claim-recovered \
    "$delivery_namespace" "$delivery_boot" "$projection_until"
docker exec "$run_id-delivery-ack-crash" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php kill-relay-after-send \
    "$delivery_namespace" "$delivery_boot"
await_exit "$run_id-delivery-ack-crash" 1 6
reserved "$delivery_namespace" "$delivery_boot"

docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
    verify-delivery-ack-contained "$delivery_namespace" "$delivery_boot" "$delivery_snapshot"

docker rm -f "$run_id-delivery-ack-gate" >/dev/null
docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
    release-relay-delivery-ack "$delivery_namespace" "$delivery_boot"

boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
namespace=baander.app:commandtest
launch "$run_id-crash" "$namespace" "$boot"
await_ready "$run_id-crash" "$namespace" "$boot"
docker exec "$run_id-crash" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php verify-delivery-ack-contained \
    "$namespace" "$boot" "$delivery_snapshot"
# No lease rewrite: both delivery intent claims must expire naturally.
await_outbox "$run_id-crash" 350
docker exec "$run_id-crash" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php verify-delivery-ack-recovered \
    "$namespace" "$boot" "$delivery_snapshot"
docker exec "$run_id-crash" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php replay-outbox "$namespace" "$boot"
await_outbox "$run_id-crash"

# Hold WorkerMessageHandledEvent after the scheduler handler commits and returns.
containers+=("$run_id-consumer-ack-gate")
docker run -d --name "$run_id-consumer-ack-gate" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
    hold-consumer-ack "$namespace" "$boot" >/dev/null

gate_ready=false
for attempt in $(seq 1 30); do
    if docker logs "$run_id-consumer-ack-gate" 2>&1 | grep -qx 'Consumer acknowledgement gate armed.'; then
        gate_ready=true
        break
    fi
    sleep 0.2
done

if [ "$gate_ready" != true ]; then
    docker logs "$run_id-consumer-ack-gate" >&2
    exit 1
fi

docker exec "$run_id-crash" php -d memory_limit=256M \
    tests/Fixtures/Worker/worker-command-check.php seed-scheduler "$namespace" "$boot"
consumer_snapshot=""
for attempt in $(seq 1 50); do
    if consumer_snapshot="$(docker exec "$run_id-crash" php -d memory_limit=64M \
        tests/Fixtures/Worker/worker-command-check.php verify-consumer-ack-blocked \
        "$namespace" "$boot" 2>/dev/null)"; then
        break
    fi
    sleep 0.2
done

if [ -z "$consumer_snapshot" ]; then
    docker logs --tail 60 "$run_id-crash" >&2
    echo 'Consumer did not reach the post-handler/pre-XACK boundary.' >&2
    exit 1
fi

docker exec "$run_id-crash-execution" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php kill-consumer-before-ack "$namespace" "$boot"
await_exit "$run_id-crash" 1 6
reserved "$namespace" "$boot"
docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
    verify-consumer-ack-contained "$namespace" "$boot" "$consumer_snapshot"

docker rm -f "$run_id-consumer-ack-gate" >/dev/null
docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php release-consumer-ack "$namespace" "$boot"
docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php \
    age-consumer-ack-pending "$namespace" "$boot" "$consumer_snapshot"

consumer_recovery_boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
consumer_recovery_namespace=baander.app:commandconsumerackrecovery
launch "$run_id-consumer-ack-recovery" "$consumer_recovery_namespace" "$consumer_recovery_boot"
await_ready "$run_id-consumer-ack-recovery" "$consumer_recovery_namespace" "$consumer_recovery_boot"
await_scheduler "$run_id-consumer-ack-recovery" "$namespace" "$boot"
docker exec "$run_id-consumer-ack-recovery" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php verify-consumer-ack-recovered \
    "$namespace" "$boot" "$consumer_snapshot"
docker exec "$run_id-consumer-ack-recovery" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php replay-scheduler "$namespace" "$boot"
await_scheduler "$run_id-consumer-ack-recovery" "$namespace" "$boot"
docker exec "$run_id-consumer-ack-recovery-execution" php -d memory_limit=64M \
    tests/Fixtures/Worker/worker-command-check.php kill-consumer "$consumer_recovery_namespace" "$consumer_recovery_boot"
await_exit "$run_id-consumer-ack-recovery" 1 6
reserved "$consumer_recovery_namespace" "$consumer_recovery_boot"

# An active predecessor reservation denies even its original boot; admission has no retry shortcut.
launch "$run_id-denied" "$namespace" "$boot" 1
await_exit "$run_id-denied" 1 0
reserved "$namespace" "$boot"

scheduler_boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
scheduler_namespace=baander.app:commandschedulercrash
launch "$run_id-scheduler-crash" "$scheduler_namespace" "$scheduler_boot"
await_ready "$run_id-scheduler-crash" "$scheduler_namespace" "$scheduler_boot"
docker exec "$run_id-scheduler-crash" php -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php kill-scheduler "$scheduler_namespace" "$scheduler_boot"
await_exit "$run_id-scheduler-crash" 1 6
reserved "$scheduler_namespace" "$scheduler_boot"

term_boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
term_namespace=baander.app:commandterm
launch "$run_id-term" "$term_namespace" "$term_boot"
await_ready "$run_id-term" "$term_namespace" "$term_boot"
docker kill --signal=TERM "$run_id-term" >/dev/null
await_exit "$run_id-term" 0 6
reserved "$term_namespace" "$term_boot"
expiry_boot="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
expiry_namespace=baander.app:commandexpiry
launch "$run_id-expiry" "$expiry_namespace" "$expiry_boot"
await_ready "$run_id-expiry" "$expiry_namespace" "$expiry_boot"
docker run --rm --name "$run_id-observer" "${common[@]}" --entrypoint php "$app_image" \
    -d memory_limit=64M tests/Fixtures/Worker/worker-command-check.php expire "$expiry_namespace" "$expiry_boot"
# Allow the renewal schedule plus its bounded 35-second drain and harness headroom.
await_exit "$run_id-expiry" 1 6 60
reserved "$expiry_namespace" "$expiry_boot"
echo 'Paired app:worker claim, projection, transport handoff, pre-XACK consumer crash recovery, replay, and lifecycle acceptance passed.'
