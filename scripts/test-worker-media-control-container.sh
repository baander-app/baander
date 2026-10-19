#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [ "$#" = 0 ]; then
    artifacts="${BAANDER_TEST_ARTIFACTS:-$(pwd)/var/test-runs/media-control-$(date +%s)-$$}"
    mkdir -p "$artifacts"; chmod 700 "$artifacts"
    BAANDER_TEST_ARTIFACTS="$artifacts" BAANDER_TEST_TWO_COMMAND=1 BAANDER_TEST_AUTOSCALING_MAXIMUM=2 \
        BAANDER_TEST_MEDIA_CONTROL_OBSERVATION=1 \
        BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT="$(pwd)/scripts/test-worker-media-control-container.sh" \
        bash scripts/test-worker-operator-container.sh > "$artifacts/operator.log" 2>&1 || {
            tail -60 "$artifacts/operator.log" >&2; exit 1;
        }
    echo "Media control evidence: $artifacts"
    exit 0
fi
artifacts="$1"; control="$2"; execution="$3"; postgres="$5"
web="${postgres%-postgres}-web"
docker_args=()
if [ "${BAANDER_TEST_OPERATOR_IN_CONTAINER:-0}" != 1 ] || [ -n "${BAANDER_TEST_DOCKER_ENDPOINT:-}" ]; then
    docker_args=(--host "${BAANDER_TEST_DOCKER_ENDPOINT:-unix:///var/run/docker.sock}")
fi
docker_local() { docker "${docker_args[@]}" "$@"; }
mkdir -p "$artifacts"
pids=()
cleanup() {
    if [ "${#pids[@]}" -gt 0 ]; then
        kill "${pids[@]}" 2>/dev/null || true
        wait "${pids[@]}" 2>/dev/null || true
    fi
}
trap cleanup EXIT
docker_local inspect "$control" "$execution" "$postgres" "${postgres%-postgres}-redis" "$web" > "$artifacts/containers-before.json"
docker_local exec "$control" php tests/Fixtures/Worker/control-capacity-sample.php 240 control > "$artifacts/control-samples.jsonl" 2> "$artifacts/control-sampler.log" &
pids+=("$!")
docker_local exec "$execution" php tests/Fixtures/Worker/control-capacity-sample.php 240 execution > "$artifacts/execution-samples.jsonl" 2> "$artifacts/execution-sampler.log" &
pids+=("$!")
docker_local exec "$execution" php tests/Fixtures/Worker/MediaControlObservation.php monitor /var/www/html/storage/two-command/media-native-state.json 240 > "$artifacts/native-monitor.log" 2>&1 &
pids+=("$!")
docker_local exec "$web" php tests/Fixtures/Worker/media-control-backlog.php > "$artifacts/backlog.jsonl" 2> "$artifacts/backlog.log" &
pids+=("$!")
docker_local exec "$web" php tests/Fixtures/TwoCommand/deployment.php media-control > "$artifacts/media-client.log" 2>&1 &
client_pid=$!; pids+=("$client_pid")
ready=false
for attempt in $(seq 1 300); do
    if docker_local exec "$web" test -f storage/two-command/capacity-ready; then ready=true; break; fi
    if ! kill -0 "$client_pid" 2>/dev/null; then tail -40 "$artifacts/media-client.log" >&2; exit 1; fi
    sleep .2
done
test "$ready" = true
docker_local top "$execution" -eo pid,ppid,args > "$artifacts/occupied-slots.txt"
docker_local exec "$execution" php -d memory_limit=-1 tests/Fixtures/Worker/control-capacity-pressure.php cpu 180 > "$artifacts/pressure.log" 2>&1 &
pressure_pid=$!; pids+=("$pressure_pid")
ready=false
for attempt in $(seq 1 100); do
    if rg -q heldAudioIdentities "$artifacts/pressure.log"; then ready=true; break; fi
    if ! kill -0 "$pressure_pid" 2>/dev/null; then tail -30 "$artifacts/pressure.log" >&2; exit 1; fi
    sleep .05
done
test "$ready" = true
docker_local exec "$web" touch storage/two-command/capacity-pressure-start
if ! wait "$client_pid"; then tail -50 "$artifacts/media-client.log" >&2; exit 1; fi
docker_local cp "$web:/var/www/html/storage/two-command/result-media-control.json" "$artifacts/media.json"
docker_local cp "$web:/var/www/html/storage/two-command/native-launches.jsonl" "$artifacts/native-launches.jsonl"
docker_local exec "$execution" php tests/Fixtures/Worker/MediaControlObservation.php verify > "$artifacts/native-verification.json"
docker_local inspect "$control" "$execution" > "$artifacts/containers-after.json"
python3 tests/Fixtures/Worker/media-control-verify.py "$artifacts"
