#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
test -f vendor/autoload.php || { echo 'Install Composer dependencies first.' >&2; exit 1; }

# Test the proposed dedicated-worker boundary, without privileged mode, host PID
# sharing, writable host cgroups, or a Docker socket inside the container.
run_id="baander-containment-$(date +%s)-$$"
cleanup() {
    docker rm -f "$run_id-term" "$run_id-kill" >/dev/null 2>&1 || true
}
trap cleanup EXIT

for scenario in term kill; do
    container="$run_id-$scenario"
    docker create --name "$container" --network none --memory 256m --memory-swap 256m \
        --cpus 1 --pids-limit 32 --cap-drop ALL --security-opt no-new-privileges \
        --entrypoint php "${BAANDER_TEST_IMAGE:-martinjuul/baander-app:latest}" \
        /tmp/tests/Fixtures/Worker/contained-supervisor.php >/dev/null
    tar -cf - vendor packages src tests/Fixtures/Worker | docker cp - "$container:/tmp/"
    docker start "$container" >/dev/null
    ready=false
    for attempt in $(seq 1 50); do
        if docker exec "$container" test -s /tmp/baander-descendant-ready >/dev/null 2>&1; then
            ready=true
            break
        fi
        sleep 0.1
    done
    if [ "$ready" != true ]; then
        docker logs "$container" >&2
        echo 'Worker descendant failed to become ready.' >&2
        exit 1
    fi
    # A real descendant exists before either termination scenario is exercised.
    descendant_pid=$(docker exec "$container" cat /tmp/baander-descendant-ready)
    docker exec "$container" test -d "/proc/$descendant_pid"
    docker kill --signal "${scenario^^}" "$container" >/dev/null
    stopped=false
    for attempt in $(seq 1 50); do
        if [ "$(docker inspect --format '{{.State.Running}} {{.State.Pid}}' "$container")" = 'false 0' ]; then
            stopped=true
            break
        fi
        sleep 0.1
    done
    if [ "$stopped" != true ]; then
        docker logs "$container" >&2
        echo 'Containment boundary did not stop before the test deadline.' >&2
        exit 1
    fi
    expected_exit=137
    if [ "$scenario" = term ]; then
        expected_exit=0
        docker logs "$container" | grep -qx supervisor_direct_children_drained
    fi
    test "$(docker inspect --format '{{.State.ExitCode}}' "$container")" = "$expected_exit"
    test "$(docker inspect --format '{{.State.OOMKilled}}' "$container")" = false
    # Docker must have removed every process in this PID namespace before any
    # replacement is admitted. A stopped container cannot execute another probe.
    if docker exec "$container" true >/dev/null 2>&1; then
        echo 'Stopped worker container still accepts processes.' >&2
        exit 1
    fi
    heartbeat_before=$(docker cp "$container:/tmp/baander-descendant-heartbeat" - | tar -xOf -)
    sleep 0.3
    heartbeat_after=$(docker cp "$container:/tmp/baander-descendant-heartbeat" - | tar -xOf -)
    test "$heartbeat_before" = "$heartbeat_after"
    echo "PASS: $scenario shutdown contained the worker and its descendant"
done
