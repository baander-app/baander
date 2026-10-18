#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [ "$#" = 0 ]; then
    if [ "${BAANDER_TEST_AUTOSCALING_COMPARISON_BOUNDED:-0}" != 1 ]; then
        exec env BAANDER_TEST_AUTOSCALING_COMPARISON_BOUNDED=1 timeout --signal=TERM --kill-after=40s 2000s bash "$0"
    fi
    artifacts="${BAANDER_TEST_ARTIFACTS:-$(pwd)/var/test-runs/autoscaling-$(date +%s)-$$}"
    mkdir -p "$artifacts"; chmod 700 "$artifacts"
    python3 tests/Fixtures/Worker/autoscaling-verify.py thresholds > "$artifacts/thresholds.json"
    for maximum in 1 2 4; do
        BAANDER_TEST_ARTIFACTS="$artifacts/$maximum" BAANDER_TEST_AUTOSCALING_MAXIMUM="$maximum" \
            BAANDER_TEST_TWO_COMMAND=1 BAANDER_TEST_AUTOSCALING_CHECKPOINT="$(pwd)/scripts/test-worker-autoscaling-container.sh" \
            timeout --signal=TERM --kill-after=40s 600s bash scripts/test-worker-operator-container.sh > "$artifacts/$maximum.log" 2>&1 || {
                tail -60 "$artifacts/$maximum.log" >&2; exit 1;
            }
    done
    python3 tests/Fixtures/Worker/autoscaling-verify.py compare "$artifacts"
    echo "Autoscaling evidence: $artifacts"
    exit 0
fi
if [ "$#" != 6 ]; then echo 'Expected artifacts control execution ipc-volume postgres database.' >&2; exit 2; fi
if [ "${BAANDER_TEST_AUTOSCALING_CALLBACK_BOUNDED:-0}" != 1 ]; then
    export BAANDER_TEST_AUTOSCALING_CALLBACK_BOUNDED=1
    exec timeout --signal=TERM --kill-after=10s 480s bash "$0" "$@"
fi
artifacts="$1"; control="$2"; execution="$3"; postgres="$5"
maximum="${BAANDER_TEST_AUTOSCALING_MAXIMUM:?}"
web="${postgres%-postgres}-web"
docker_args=()
if [ "${BAANDER_TEST_OPERATOR_IN_CONTAINER:-0}" != 1 ] || [ -n "${BAANDER_TEST_DOCKER_ENDPOINT:-}" ]; then
    docker_args=(--host "${BAANDER_TEST_DOCKER_ENDPOINT:-unix:///var/run/docker.sock}")
fi
docker_local() { timeout --signal=TERM --kill-after=3s 12s docker "${docker_args[@]}" "$@"; }
started=$SECONDS
mkdir -p "$artifacts"
python3 tests/Fixtures/Worker/autoscaling-verify.py thresholds > "$artifacts/thresholds.json"
docker_local info --format '{{json .}}' > "$artifacts/daemon.json"
docker_local inspect "$control" "$execution" "$postgres" "${postgres%-postgres}-redis" "$web" > "$artifacts/containers-before.json"
timeout --signal=TERM --kill-after=2s 25s docker "${docker_args[@]}" exec "$control" php tests/Fixtures/Worker/autoscaling-observation-gap.php > "$artifacts/gap.json" 2> "$artifacts/gap.log"
python3 tests/Fixtures/Worker/autoscaling-verify.py gap "$artifacts"
# Long-running execs have independent bounds; observer shutdown never targets worker processes.
timeout 480s docker "${docker_args[@]}" exec "$control" php tests/Fixtures/Worker/autoscaling-sample.php control 475 > "$artifacts/control-samples.jsonl" 2> "$artifacts/control-sampler.log" &
control_sampler=$!
timeout 480s docker "${docker_args[@]}" exec "$execution" php tests/Fixtures/Worker/autoscaling-sample.php execution 475 > "$artifacts/execution-samples.jsonl" 2> "$artifacts/execution-sampler.log" &
execution_sampler=$!
media_pid=''; pressure_pid=''
cleanup() {
    kill "$control_sampler" "$execution_sampler" ${media_pid:+"$media_pid"} ${pressure_pid:+"$pressure_pid"} 2>/dev/null || true
    wait "$control_sampler" "$execution_sampler" ${media_pid:+"$media_pid"} ${pressure_pid:+"$pressure_pid"} 2>/dev/null || true
}
trap cleanup EXIT
await_evidence() {
    local mode="$1" limit="$2" phase="${3:-}" deadline=$((SECONDS + $2))
    while ! python3 tests/Fixtures/Worker/autoscaling-verify.py "$mode" "$artifacts" "$phase" > "$artifacts/await-$mode.log" 2>&1; do
        kill -0 "$control_sampler" "$execution_sampler" || { tail -20 "$artifacts/"*-sampler.log >&2; exit 1; }
        if [ "$SECONDS" -ge "$deadline" ] || [ "$((SECONDS-started))" -ge 470 ]; then
            cat "$artifacts/await-$mode.log" >&2; exit 1
        fi
        sleep .5
    done
}
await_evidence idle 20
for batch in cold repeat; do
    docker_local exec "$control" php tests/Fixtures/Worker/autoscaling-dispatch.php "$batch" > "$artifacts/$batch.json"
    await_evidence burst 150 "$batch"
    await_evidence idle 110
done
docker_local exec "$control" php tests/Fixtures/Worker/autoscaling-dispatch.php cpu 16 cpu > "$artifacts/cpu.json"
await_evidence burst 70 cpu
await_evidence idle 110
timeout 110s docker "${docker_args[@]}" exec "$web" php tests/Fixtures/TwoCommand/deployment.php capacity > "$artifacts/media-client.log" 2>&1 &
media_pid=$!
ready=false
for attempt in $(seq 1 150); do
    if docker_local exec "$web" test -f storage/two-command/capacity-ready; then ready=true; break; fi
    kill -0 "$media_pid" || { cat "$artifacts/media-client.log" >&2; exit 1; }
    sleep .2
done
test "$ready" = true
timeout 90s docker "${docker_args[@]}" exec "$execution" php tests/Fixtures/Worker/control-capacity-pressure.php cpu > "$artifacts/pressure.log" 2>&1 &
pressure_pid=$!
pressure_ready=false
for attempt in $(seq 1 100); do
    if rg -q heldAudioIdentities "$artifacts/pressure.log"; then pressure_ready=true; break; fi
    kill -0 "$pressure_pid" || { cat "$artifacts/pressure.log" >&2; exit 1; }
    sleep .05
done
test "$pressure_ready" = true
docker_local exec "$web" touch storage/two-command/capacity-pressure-start
wait "$media_pid"; media_pid=''
wait "$pressure_pid"; pressure_pid=''
docker_local cp "$web:/var/www/html/storage/two-command/result-capacity.json" "$artifacts/media.json"
# Media's ordinary feeder leaves a finite backlog; recovery must settle without retiring the pair.
await_evidence idle 110
docker_local inspect "$control" "$execution" > "$artifacts/containers-after.json"
python3 - "$artifacts/containers-after.json" <<'PY'
import json,sys
assert all(c['State']['Running'] for c in json.load(open(sys.argv[1]))), 'Qualification must leave pair running'
PY
python3 tests/Fixtures/Worker/autoscaling-verify.py verify "$artifacts" "$maximum"
printf '%s\n' "$((SECONDS-started))" > "$artifacts/checkpoint-seconds.txt"
