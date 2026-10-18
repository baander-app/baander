#!/usr/bin/env bash
# Sourced only by the disposable operator runner after its initial pair is ready.

lifecycle_observe() {
    local action="$1" label="$2" epoch="$3" input="${4:-/dev/null}" code=0
    docker_local run --name "$run_id-client" --network "$run_id" --env-file "$work/runtime.env" \
        --cap-drop ALL --security-opt no-new-privileges --memory 256m --pids-limit 32 \
        -i --entrypoint /usr/local/bin/php "$image_id" -d memory_limit=128M \
        tests/Fixtures/Worker/lifecycle-check.php "$action" "$namespace" "$boot" "$epoch" /tmp/lifecycle-receipt.json \
        < "$input" > "$artifacts/$label.log" 2>&1 || code=$?
    docker_local cp "$run_id-client:/tmp/lifecycle-receipt.json" "$artifacts/$label.json" || code=1
    docker_local rm -f "$run_id-client" >/dev/null || code=1
    return "$code"
}

lifecycle_manifest_boot() {
    boot="$1"
    python3 - "$work/manifest.json" "$boot" <<'PY'
import json, sys
path, boot = sys.argv[1:]
manifest = json.load(open(path))
manifest['bootId'] = boot
with open(path, 'w') as stream: json.dump(manifest, stream)
PY
    if [ "$operator_in_container" = 1 ]; then
        docker_local exec -i "$run_id-controller" /bin/sh -c 'umask 077; cat > /tmp/operator/manifest.json' < "$work/manifest.json"
    fi
}

lifecycle_await_completed() {
    local seed="$1" label="$2" deadline=$((SECONDS + 90))
    while [ "$SECONDS" -lt "$deadline" ]; do
        if lifecycle_observe completed "$label" 2 "$artifacts/$seed.json"; then return 0; fi
        sleep .5
    done
    cat "$artifacts/$label.json" >&2
    return 1
}

lifecycle_retired() {
    local label="$1" expected_control="$2" expected_execution="$3"
    operator recover 0
    cp "$work/recover.json" "$artifacts/$label-recover.json"
    operator recover 0
    operator status 0
    cp "$work/status.json" "$artifacts/$label-status.json"
    python3 - "$work/status.json" "$expected_control" "$expected_execution" <<'PY'
import json, sys
r = json.load(open(sys.argv[1]))['result']
assert r['retirement'] == 'completed' and r['reservation'] == 'none' and r['manifestMatches'], r
assert set(r['units']) == {'control', 'execution'}, r
for role, identity in zip(('control', 'execution'), sys.argv[2:]):
    assert r['units'][role]['containerId'] == identity and r['units'][role]['removed'], r
PY
    docker_local container ls --all --no-trunc --format '{{.ID}}' > "$artifacts/$label-containers.txt"
    python3 - "$artifacts/$label-containers.txt" "$expected_control" "$expected_execution" <<'PY'
import pathlib, sys
present = set(pathlib.Path(sys.argv[1]).read_text().splitlines())
assert all(identity not in present for identity in sys.argv[2:]), 'Retired immutable unit still exists'
PY
}

lifecycle_checkpoint() {
    local started=$SECONDS predecessor="$boot" successor old_control old_execution fault_started stop_started
    local deadline stopped=false observed=false
    cp "$work/manifest.json" "$artifacts/manifest.json"
    docker_local inspect "$control_name" "$worker_name" > "$artifacts/predecessor-containers.json"
    old_control="$(docker_local inspect --format '{{.Id}}' "$control_name")"
    old_execution="$(docker_local inspect --format '{{.Id}}' "$worker_name")"
    docker_local container ls --all --format '{{.Names}}' > "$artifacts/containers-before.txt"
    python3 - "$artifacts/manifest.json" "$artifacts/containers-before.txt" "$web_name" <<'PY'
import json, pathlib, sys
m = json.load(open(sys.argv[1]))
assert m['version'] == 4 and m['autoscaling']['maximumConcurrency'] == 2, m
assert (m['controlMemoryMiB'], m['controlPidsLimit']) == (2944, 129), m
assert (m['executionMemoryMiB'], m['executionPidsLimit']) == (4800, 257), m
assert sys.argv[3] not in pathlib.Path(sys.argv[2]).read_text().splitlines(), 'HTTP must be absent'
PY
    lifecycle_observe lease predecessor-lease 1
    if [ "$lifecycle_phase" = redis ]; then
        docker_local exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli --raw INFO server > "$artifacts/redis-before.txt"
    fi
    lifecycle_fault_container="$(docker_local inspect --format '{{.Id}}' "$run_id-$lifecycle_phase")"
    docker_local pause "$lifecycle_fault_container" >/dev/null
    fault_started=$SECONDS
    test "$(docker_local inspect --format '{{.State.Paused}}' "$lifecycle_fault_container")" = true
    if [ "$lifecycle_phase" = redis ]; then
        deadline=$((SECONDS + 15))
        while [ "$SECONDS" -lt "$deadline" ]; do
            operator status 0
            if python3 - "$work/status.json" <<'PY'
import json, sys
s = json.load(open(sys.argv[1]))['result']['autoscaling']
ok = s.get('authorityValid') and s.get('controllerFresh') and s.get('evidence', {}).get('queueState') == 'unavailable'
ok = ok and s.get('reason') == 'measurements_unavailable' and 0 <= s.get('desired', -1) <= 1
sys.exit(0 if ok else 1)
PY
            then observed=true; break; fi
            sleep .2
        done
        test "$observed" = true || { echo 'Redis failure was not independently observable.' >&2; return 1; }
        cp "$work/status.json" "$artifacts/redis-unavailable.json"
        lifecycle_observe seed retained-work 1
        lifecycle_observe pending pending-before-stop 1 "$artifacts/retained-work.json"
        stop_started=$SECONDS
        docker_local kill --signal TERM "$old_control" >/dev/null
    else
        # No signal or controller retirement precedes the authority-loss assertion.
        stop_started=$fault_started
    fi
    deadline=$((stop_started + 50))
    while [ "$SECONDS" -lt "$deadline" ]; do
        docker_local inspect "$old_control" "$old_execution" > "$artifacts/draining-containers.json"
        if python3 - "$artifacts/draining-containers.json" <<'PY'
import json, sys
units = json.load(open(sys.argv[1]))
sys.exit(0 if len(units) == 2 and all(not c['State']['Running'] and c['State']['Pid'] == 0 for c in units) else 1)
PY
        then stopped=true; break; fi
        sleep .2
    done
    test "$stopped" = true || { echo 'Pair did not stop within the lifecycle deadline.' >&2; return 1; }
    local drain_seconds=$((SECONDS - stop_started))
    python3 - "$artifacts/draining-containers.json" "$old_control" "$old_execution" "$lifecycle_phase" <<'PY'
import json, sys
units = json.load(open(sys.argv[1]))
assert [c['Id'] for c in units] == sys.argv[2:4], 'Stopped identities changed'
assert all(not c['State']['OOMKilled'] and c['RestartCount'] == 0 for c in units), units
if sys.argv[4] == 'postgres':
    assert any(c['State']['ExitCode'] != 0 for c in units), 'Authority loss must be a failed runtime'
PY
    for unit in "$old_control" "$old_execution"; do
        if docker_local exec "$unit" true >/dev/null 2>&1; then echo 'Stopped namespace accepts processes.' >&2; return 1; fi
    done
    docker_local logs "$old_control" > "$artifacts/predecessor-control.log" 2>&1
    docker_local logs "$old_execution" > "$artifacts/predecessor-execution.log" 2>&1
    docker_local unpause "$lifecycle_fault_container" >/dev/null
    lifecycle_fault_container=''
    if [ "$lifecycle_phase" = redis ]; then
        docker_local exec -e REDISCLI_AUTH=test-only "$run_id-redis" redis-cli --raw INFO server > "$artifacts/redis-after.txt"
        python3 - "$artifacts/redis-before.txt" "$artifacts/redis-after.txt" <<'PY'
import pathlib, sys
def identity(path):
    values = dict(line.split(':', 1) for line in pathlib.Path(path).read_text().splitlines() if ':' in line)
    assert values.get('run_id'), 'Redis server identity missing'
    return values['run_id']
assert identity(sys.argv[1]) == identity(sys.argv[2]), 'Redis restarted during the preserved-state fault'
PY
        lifecycle_observe pending pending-after-stop 1 "$artifacts/retained-work.json"
    fi
    lifecycle_observe lease reserved-lease 1
    operator status 0
    cp "$work/status.json" "$artifacts/reserved-status.json"
    python3 - "$work/status.json" "$old_control" "$old_execution" <<'PY'
import json, sys
r = json.load(open(sys.argv[1]))['result']
assert r['reservation'] == 'this_boot' and r['retirement'] == 'none', r
for role, identity in zip(('control', 'execution'), sys.argv[2:]):
    assert r['units'][role]['containerId'] == identity and not r['units'][role]['removed'], r
PY
    successor="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
    lifecycle_manifest_boot "$successor"
    operator create 3
    cp "$work/create.json" "$artifacts/premature-create.json"
    python3 - "$work/create.json" <<'PY'
import json, sys
r = json.load(open(sys.argv[1]))
assert r['result']['state'] == 'reservation_denied', r
PY
    lifecycle_manifest_boot "$predecessor"
    lifecycle_retired predecessor "$old_control" "$old_execution"
    lifecycle_manifest_boot "$successor"
    worker_name="$(python3 - "$namespace" "$boot" <<'PY'
import hashlib, sys
print('baander-worker-' + hashlib.sha256(sys.argv[1].encode()).hexdigest()[:32] + '-' + sys.argv[2] + '-execution')
PY
)"
    control_name="${worker_name%-execution}-control"
    operator create 0
    operator start 0
    await_check ready-recovered
    await_check ready-recovered "$worker_name"
    lifecycle_observe lease successor-lease 2
    if [ "$lifecycle_phase" = postgres ]; then lifecycle_observe seed retained-work 2; fi
    lifecycle_await_completed retained-work completed-work
    python3 - "$namespace" "$predecessor" "$successor" > "$work/stale.json" <<'PY'
import json, sys
print(json.dumps(dict(predecessor=dict(namespace=sys.argv[1], bootId=sys.argv[2], epoch=1),
    successor=dict(namespace=sys.argv[1], bootId=sys.argv[3], epoch=2))))
PY
    lifecycle_observe stale-lease stale-renewal 2 "$work/stale.json"
    lifecycle_manifest_boot "$predecessor"
    operator recover 0
    cp "$work/recover.json" "$artifacts/delayed-predecessor-recovery.json"
    operator start 3
    lifecycle_manifest_boot "$successor"
    lifecycle_observe lease successor-after-stale 2
    lifecycle_observe seed subsequent-work 2
    lifecycle_await_completed subsequent-work completed-subsequent-work
    docker_local inspect "$control_name" "$worker_name" > "$artifacts/successor-containers.json"
    python3 - "$artifacts/successor-containers.json" "$old_control" "$old_execution" <<'PY'
import json, sys
units = json.load(open(sys.argv[1]))
assert len(units) == 2 and all(c['State']['Running'] and c['RestartCount'] == 0 and c['Id'] not in sys.argv[2:] for c in units), units
PY
    local new_control new_execution
    new_control="$(docker_local inspect --format '{{.Id}}' "$control_name")"
    new_execution="$(docker_local inspect --format '{{.Id}}' "$worker_name")"
    lifecycle_retired successor "$new_control" "$new_execution"
    python3 - "$artifacts/qualification.json" "$lifecycle_phase" "$drain_seconds" "$((SECONDS-started))" <<'PY'
import json, sys
with open(sys.argv[1], 'w') as stream:
    json.dump(dict(success=True, phase=sys.argv[2], drainSeconds=int(sys.argv[3]), seconds=int(sys.argv[4]),
        drainTrigger='explicit-pid1-term' if sys.argv[2] == 'redis' else 'automatic-authority-loss',
        maximumConcurrency=2, httpAbsent=True, predecessorEpoch=1, successorEpoch=2), stream, indent=2)
PY
    echo "PASS: $lifecycle_phase outage retained ownership, drained the pair and recovered exact work under a successor without HTTP."
}
