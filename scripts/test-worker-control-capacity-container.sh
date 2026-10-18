#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [ "$#" = 0 ]; then
    artifacts="${BAANDER_TEST_ARTIFACTS:-$(pwd)/var/test-runs/control-capacity-$(date +%s)-$$}"
    mkdir -p "$artifacts"; chmod 700 "$artifacts"
    for phase in cpu memory pid; do
        BAANDER_TEST_ARTIFACTS="$artifacts/$phase" BAANDER_TEST_CAPACITY_PHASE="$phase" \
            BAANDER_TEST_TWO_COMMAND=1 BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT="$(pwd)/scripts/test-worker-control-capacity-container.sh" \
            bash scripts/test-worker-operator-container.sh > "$artifacts/$phase.log" 2>&1 || {
                tail -60 "$artifacts/$phase.log" >&2; exit 1;
            }
    done
    echo "Control capacity evidence: $artifacts"
    exit 0
fi
artifacts="$1"; control="$2"; execution="$3"; postgres="$5"; phase="${BAANDER_TEST_CAPACITY_PHASE:?}"
web="${postgres%-postgres}-web"
docker_args=()
if [ "${BAANDER_TEST_OPERATOR_IN_CONTAINER:-0}" != 1 ] || [ -n "${BAANDER_TEST_DOCKER_ENDPOINT:-}" ]; then
    docker_args=(--host "${BAANDER_TEST_DOCKER_ENDPOINT:-unix:///var/run/docker.sock}")
fi
docker_local() { docker "${docker_args[@]}" "$@"; }
mkdir -p "$artifacts"
docker_local info --format '{{json .}}' > "$artifacts/daemon.json"
docker_local inspect "$control" "$execution" "$postgres" "${postgres%-postgres}-redis" "$web" > "$artifacts/containers-before.json"
docker_local exec -i "$postgres" psql -X -w -U baander -d "$6" > "$artifacts/postgres-before.txt" 2>&1 <<'SQL'
\pset pager off
SELECT name, setting, unit, source FROM pg_settings WHERE name IN
('shared_buffers', 'work_mem', 'hash_mem_multiplier', 'maintenance_work_mem', 'autovacuum_work_mem',
 'max_connections', 'max_worker_processes', 'max_parallel_workers', 'max_parallel_workers_per_gather',
 'huge_pages', 'temp_buffers', 'effective_cache_size', 'jit', 'log_destination', 'logging_collector', 'log_directory') ORDER BY name;
SELECT pid, backend_type, state, wait_event_type, wait_event,
       clock_timestamp() - query_start AS query_age, left(query, 500) AS query
FROM pg_stat_activity WHERE datname = current_database() ORDER BY pid;
EXPLAIN (COSTS, VERBOSE) SELECT owner_boot_id, epoch, state, expires_at > clock_timestamp() AS unexpired
FROM worker_deployment_leases WHERE namespace IS NOT NULL;
SQL
postgres_sampler=''; oom_subscriber=''

docker_local exec "$control" php tests/Fixtures/Worker/control-capacity-sample.php 180 control > "$artifacts/control-samples.jsonl" 2> "$artifacts/control-sampler.log" &
control_sampler=$!
docker_local exec "$execution" php tests/Fixtures/Worker/control-capacity-sample.php 180 execution > "$artifacts/execution-samples.jsonl" 2> "$artifacts/execution-sampler.log" &
execution_sampler=$!
client_pid=''; pressure_pid=''; failure_pid=''
cleanup_sampling() {
    kill "$control_sampler" "$execution_sampler" ${client_pid:+"$client_pid"} ${pressure_pid:+"$pressure_pid"} ${failure_pid:+"$failure_pid"} ${postgres_sampler:+"$postgres_sampler"} ${oom_subscriber:+"$oom_subscriber"} 2>/dev/null || true
    wait "$control_sampler" "$execution_sampler" ${client_pid:+"$client_pid"} ${pressure_pid:+"$pressure_pid"} ${failure_pid:+"$failure_pid"} ${postgres_sampler:+"$postgres_sampler"} ${oom_subscriber:+"$oom_subscriber"} 2>/dev/null || true
}
trap cleanup_sampling EXIT
read -r control_id execution_id <<< "$(python3 - "$artifacts/containers-before.json" <<'PYID'
import json,sys
units = json.load(open(sys.argv[1]))
print(units[0]['Id'], units[1]['Id'])
PYID
)"
timeout 180s docker "${docker_args[@]}" events --filter type=container --filter "container=$control_id" --filter "container=$execution_id" --filter event=oom --format '{{json .}}' > "$artifacts/worker-oom-events.jsonl" 2> "$artifacts/worker-oom-events.log" &
oom_subscriber=$!
event_since="$(python3 - "$artifacts/daemon.json" <<'PYSINCE'
import datetime,json,sys
stamp = json.load(open(sys.argv[1]))['SystemTime']
print(int(datetime.datetime.fromisoformat(stamp.replace('Z', '+00:00')).timestamp()) - 1)
PYSINCE
)"


docker_local exec -i --user postgres "$postgres" python3 - 180 "$6" < tests/Fixtures/Worker/control-capacity-postgres-sample.py > "$artifacts/postgres-samples.jsonl" 2> "$artifacts/postgres-sampler.log" &
postgres_sampler=$!

if [ "$phase" = cpu ]; then
    docker_local exec "$web" php tests/Fixtures/TwoCommand/deployment.php capacity > "$artifacts/media-client.log" 2>&1 &
    client_pid=$!
    ready=false
    for attempt in $(seq 1 150); do
        if docker_local exec "$web" test -f storage/two-command/capacity-ready; then ready=true; break; fi
        if ! kill -0 "$client_pid" 2>/dev/null; then cat "$artifacts/media-client.log" >&2; exit 1; fi
        sleep .2
    done
    test "$ready" = true
    docker_local top "$execution" -eo pid,ppid,args > "$artifacts/occupied-slots.txt"
fi
# Establish the observers before injecting pressure; a prompt automatic drain may
# correctly finish in less time than eleven sample intervals.
samplers_ready=false
for attempt in $(seq 1 100); do
    if ! kill -0 "$control_sampler" 2>/dev/null || ! kill -0 "$execution_sampler" 2>/dev/null; then
        tail -20 "$artifacts/control-sampler.log" "$artifacts/execution-sampler.log" >&2
        echo 'Capacity sampler exited before its baseline was ready.' >&2
        exit 1
    fi
    if python3 - "$artifacts" > "$artifacts/samplers-ready.json" <<'PYREADY'
import json,pathlib,sys
root = pathlib.Path(sys.argv[1])
receipt = {}
for unit in ('control', 'execution'):
    valid = []
    try:
        lines = (root / (unit + '-samples.jsonl')).read_text().splitlines()
    except OSError:
        sys.exit(1)
    for line in lines:
        try:
            sample = json.loads(line)
        except json.JSONDecodeError:
            continue
        if not isinstance(sample, dict) or not isinstance(sample.get('monotonic'), (int, float)):
            continue
        if any(not isinstance(sample.get(key), str) for key in ('cpu.stat', 'memory.events', 'pids.events')):
            continue
        if unit == 'control' and (not isinstance(sample.get('heartbeat'), dict) or not isinstance(sample.get('lease'), dict)):
            continue
        valid.append(sample)
    if len(valid) <= 10:
        sys.exit(1)
    receipt[unit] = {'validBaselineSamples': len(valid), 'firstMonotonic': valid[0]['monotonic'], 'lastMonotonic': valid[-1]['monotonic']}
print(json.dumps(receipt))
PYREADY
    then samplers_ready=true; break; fi
    sleep .1
done
if [ "$samplers_ready" != true ]; then
    echo 'Capacity samplers did not establish eleven valid baseline samples within ten seconds.' >&2
    exit 1
fi
kill -0 "$oom_subscriber" || { cat "$artifacts/worker-oom-events.log" >&2; exit 1; }
exhaustion_started="$(date +%s)"
if [ "$phase" != cpu ]; then
    docker_local exec "$control" php tests/Fixtures/Worker/control-capacity-owned-failure.php "$phase" > "$artifacts/owned-failure.jsonl" 2> "$artifacts/owned-failure.log" &
    failure_pid=$!
fi
docker_local exec "$execution" php -d memory_limit=-1 tests/Fixtures/Worker/control-capacity-pressure.php "$phase" > "$artifacts/pressure.log" 2>&1 &
pressure_pid=$!
if [ "$phase" = cpu ]; then
    pressure_ready=false
    for attempt in $(seq 1 100); do
        if rg -q heldAudioIdentities "$artifacts/pressure.log"; then pressure_ready=true; break; fi
        if ! kill -0 "$pressure_pid" 2>/dev/null; then cat "$artifacts/pressure.log" >&2; exit 1; fi
        sleep .05
    done
    test "$pressure_ready" = true
    docker_local exec "$web" touch storage/two-command/capacity-pressure-start
    wait "$client_pid"; client_pid=''
    docker_local cp "$web:/var/www/html/storage/two-command/result-capacity.json" "$artifacts/media.json"
    docker_local inspect "$control" "$execution" > "$artifacts/containers-after-pressure.json"
    wait "$pressure_pid"; pressure_pid=''
else
    # cgroup counters, rather than the pressure process exit status, prove exhaustion.
    for attempt in $(seq 1 150); do
        if python3 tests/Fixtures/Worker/control-capacity-verify.py exhausted "$artifacts" "$phase" >/dev/null 2>&1; then break; fi
        sleep .1
    done
    python3 tests/Fixtures/Worker/control-capacity-verify.py exhausted "$artifacts" "$phase"
    wait "$failure_pid"; failure_pid=''
    docker_local inspect "$control" "$execution" > "$artifacts/containers-after-pressure.json"
fi
# Freeze qualification samples; automatic or requested drain is checked separately.
kill "$control_sampler" "$execution_sampler" 2>/dev/null || true
wait "$control_sampler" "$execution_sampler" 2>/dev/null || true
# Only CPU qualification requests graceful shutdown. Exhaustion must drain the pair
# automatically before the trusted operator proves joint retirement.
started="$(date +%s)"
if [ "$phase" != cpu ]; then started="$exhaustion_started"; fi
if [ "$phase" != cpu ]; then
    printf '%s\n' automatic-execution-exhaustion > "$artifacts/drain-trigger.txt"
else
    printf '%s\n' requested-graceful-shutdown-after-contained-pressure > "$artifacts/drain-trigger.txt"
    docker_local kill --signal TERM "$control" >/dev/null 2>&1 || true
fi
while :; do
    docker_local inspect "$control" "$execution" > "$artifacts/drain.json"
    if python3 - "$artifacts/drain.json" <<'PY'
import json,sys
sys.exit(0 if all(not c['State']['Running'] for c in json.load(open(sys.argv[1]))) else 1)
PY
    then break; fi
    test "$(( $(date +%s) - started ))" -lt 35 || { echo 'Pair exceeded 35-second drain.' >&2; exit 1; }
    sleep .1
done
printf '%s\n' "$(( $(date +%s) - started ))" > "$artifacts/drain-seconds.txt"
# A live subscription must survive until the pair has drained. Independently
# retrieve the complete bounded interval before making an absence assertion.
kill -0 "$oom_subscriber" || { echo 'Docker OOM subscriber exited before drain evidence was complete.' >&2; exit 1; }
event_until="$(docker_local info --format '{{.SystemTime}}' | python3 -c 'import datetime,sys; print(int(datetime.datetime.fromisoformat(sys.stdin.read().strip().replace("Z", "+00:00")).timestamp()) + 1)')"
capture_status=0
timeout 10s docker "${docker_args[@]}" events --since "$event_since" --until "$event_until" \
    --filter type=container --filter "container=$control_id" --filter "container=$execution_id" --filter event=oom \
    --format '{{json .}}' > "$artifacts/worker-oom-events-final.jsonl" 2> "$artifacts/worker-oom-events-final.log" || capture_status=$?
test "$capture_status" = 0 || { echo "Bounded Docker OOM event capture failed: $capture_status" >&2; exit 1; }
kill -0 "$oom_subscriber" || { echo 'Docker OOM subscriber exited during final event capture.' >&2; exit 1; }
kill "$oom_subscriber"
monitor_status=0
wait "$oom_subscriber" || monitor_status=$?
oom_subscriber=''
test "$monitor_status" = 143 || { echo "Docker OOM subscriber ended unexpectedly: $monitor_status" >&2; exit 1; }
python3 - "$artifacts/worker-oom-events-capture.json" "$control_id" "$execution_id" "$event_since" "$event_until" "$monitor_status" <<'PYRECEIPT'
import json,pathlib,sys
path, control, execution, since, until, monitor_status = sys.argv[1:]
pathlib.Path(path).write_text(json.dumps({
    'containerIds': {'control': control, 'execution': execution},
    'sinceUnixSeconds': int(since), 'untilUnixSeconds': int(until),
    'sinceSource': 'daemon-info-before-pressure', 'untilSource': 'daemon-info-after-drain',
    'captureExitCode': 0, 'monitorAliveAfterDrainAndCapture': True,
    'monitorIntentionallyStopped': True, 'monitorExitCode': int(monitor_status),
}, indent=2) + '\n')
PYRECEIPT

cleanup_sampling
trap - EXIT
python3 tests/Fixtures/Worker/control-capacity-verify.py verify "$artifacts" "$phase"
