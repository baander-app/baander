#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - <<'PY'
import json
import os
import pathlib
import subprocess
import tempfile

source = pathlib.Path('scripts/test-worker-operator-container.sh').read_text()
cleanup = source[source.index('cleanup() {'):source.index('\ntrap cleanup EXIT')]
stub = r'''#!/usr/bin/env python3
import hashlib, json, os, sys
path = os.environ['CLEANUP_STUB_STATE']
state = json.load(open(path))
args = sys.argv[1:]
state['calls'].append(args)
mode = state['mode']
code = 0
if args[0] == 'logs':
    pass
elif len(args) >= 2 and args[1] == 'ls':
    kind = args[0]
    state['queries'][kind] = state['queries'].get(kind, 0) + 1
    if mode == 'transport' or (mode == 'verification-transport' and state['queries'][kind] == 2):
        code = 125
    else:
        for name in state['resources'][kind]:
            if kind == 'container':
                namespace = 'fixture-namespace' if name in ['worker-current', 'control-current', 'worker-retired', 'control-retired'] else ''
                print(hashlib.sha256(name.encode()).hexdigest() + '|' + name + '|' + namespace + ('|control' if name.startswith('control-') else '|execution'))
            else:
                print(name)
elif len(args) >= 2 and args[1] == 'rm':
    kind = args[0]
    names = [name for name in args[2:] if not name.startswith('-')]
    for name in names:
        if kind == 'container':
            name = next((candidate for candidate in state['resources'][kind] if hashlib.sha256(candidate.encode()).hexdigest() == name), name)
        blocked = name == state.get('blocked')
        if blocked and mode == 'remove-failure':
            code = 1
        elif not (blocked and mode == 'false-removal'):
            if name in state['resources'][kind]:
                state['resources'][kind].remove(name)
            if blocked and mode == 'remove-error-absent':
                code = 1
else:
    code = 126
json.dump(state, open(path, 'w'))
sys.exit(code)
'''
owned = {
    'container': ['fixture-web', 'fixture-controller', 'fixture-media-prepare', 'fixture-host-probe',
                  'fixture-client', 'fixture-prepare', 'fixture-source', 'fixture-redis', 'fixture-postgres',
                  'worker-current', 'control-current', 'worker-retired', 'control-retired'],
    'network': ['fixture', 'fixture-control'],
    'volume': ['fixture-source-media', 'fixture-storage', 'fixture-media-state', 'fixture-web-secrets', 'fixture-worker-ipc'],
    'image': ['fixture:controller', 'fixture:cli', 'fixture:fixture', 'fixture:base'],
}
cases = [('transport', 0, None, 1), ('clean', 0, None, 0), ('absent', 0, None, 0),
         ('verification-transport', 0, None, 1), ('remove-failure', 0, 'fixture-controller', 1),
         ('remove-failure', 7, 'fixture-controller', 7), ('checkpoint', 0, None, 1),
         ('clean', 7, None, 7), ('remove-error-absent', 0, 'fixture-controller', 1)]
for kind, names in owned.items():
    cases.append(('false-removal', 0, names[0], 1))

with tempfile.TemporaryDirectory(prefix='baander-cleanup-test-') as temporary:
    root = pathlib.Path(temporary)
    binary = root / 'docker-stub'
    binary.write_text(stub)
    binary.chmod(0o700)
    hook = root / 'checkpoint'
    hook.write_text('#!/bin/sh\nexit 9\n')
    hook.chmod(0o700)
    for index, (mode, original, blocked, expected) in enumerate(cases):
        case = root / str(index)
        case.mkdir()
        work = case / 'work'
        work.mkdir()
        artifacts = case / 'artifacts'
        artifacts.mkdir()
        resources = {kind: ([] if mode == 'absent' else list(names)) + ['unrelated-' + kind]
                     for kind, names in owned.items()}
        state_path = case / 'state.json'
        state_path.write_text(json.dumps(dict(mode=mode, blocked=blocked, resources=resources, calls=[], queries={})))
        script = case / 'case.sh'
        script.write_text('''#!/usr/bin/env bash
set -euo pipefail
run_id=fixture
namespace=fixture-namespace
worker_name=worker-current
control_name=control-current
web_name=fixture-web
fixture_image=fixture:fixture
fixture_base=fixture:base
controller_image=fixture:controller
controller_cli=fixture:cli
source_volume=fixture-source-media
storage_volume=fixture-storage
state_volume=fixture-media-state
secrets_volume=fixture-web-secrets
ipc_volume=fixture-worker-ipc
two_command=1
operator_in_container=1
docker_endpoint=unused
docker_binary=$1
work=$2
artifacts=$3
docker_local() { "$docker_binary" "$@"; }
''' + cleanup + '\ntrap cleanup EXIT\nexit ' + str(original) + '\n')
        environment = dict(os.environ, CLEANUP_STUB_STATE=str(state_path))
        environment.pop('BAANDER_TEST_DOCKER_ENDPOINT', None)
        environment['BAANDER_TEST_TWO_COMMAND_CHECKPOINT'] = str(hook) if mode == 'checkpoint' else ''
        result = subprocess.run(['bash', str(script), str(binary), str(work), str(artifacts)], env=environment,
                                text=True, capture_output=True, timeout=30)
        receipt = json.loads((artifacts / 'cleanup.json').read_text())
        assert result.returncode == expected, (mode, blocked, result.returncode, expected, result.stderr)
        assert receipt['exitStatus'] == expected, (mode, receipt)
        assert receipt['cleanupVerified'] is (mode in ['clean', 'absent']), (mode, receipt)
        final = json.loads(state_path.read_text())
        for call in final['calls']:
            if call[:3] == ['container', 'rm', '-f']:
                assert len(call[3]) == 64 and all(c in '0123456789abcdef' for c in call[3]), call
        for kind in owned:
            assert 'unrelated-' + kind in final['resources'][kind], (mode, kind, 'unrelated resource removed')
        if mode in ['clean', 'absent', 'checkpoint']:
            for kind in owned:
                assert final['resources'][kind] == ['unrelated-' + kind], (mode, kind, final['resources'][kind])
        if mode == 'remove-failure':
            assert final['resources']['volume'] == ['unrelated-volume'], 'Cleanup stopped at the first failed removal'
    print('PASS: 13 cleanup cases; authoritative inventories, failures, original status, callback and unrelated resources verified.')

# Exercise the actual final drill block against a dependency failure that stops
# the deployment, as well as a deployment still draining when recovery starts.
final_drill = source.split('    checkpoint worker-recovered\n', 1)[1].split('    python3 - "$artifacts"', 1)[0]
with tempfile.TemporaryDirectory(prefix='baander-operator-final-test-') as temporary:
    root = pathlib.Path(temporary)
    for stopped in [True, False]:
        artifacts = root / ('stopped' if stopped else 'draining')
        artifacts.mkdir()
        script = artifacts / 'case.sh'
        script.write_text(r'''#!/usr/bin/env bash
set -euo pipefail
artifacts=$1
stopped=$2
run_id=fixture
worker_name=worker-current
control_name=control-current
web_name=fixture-web
docker_local() {
    printf '%s\n' "$*" >> "$artifacts/calls"
    case "$1" in
        top)
            if [ "$stopped" = true ] && [ -f "$artifacts/outage" ]; then
                echo 'Cannot inspect PIDs in a stopped worker' >&2; return 1
            fi
            printf 'PID PPID COMMAND\n101 1 php worker\n102 101 ffmpeg\n' ;;
        stop) touch "$artifacts/outage" ;;
        start|logs) : ;;
        container) test -f "$artifacts/recovered" ;;
        *) return 1 ;;
    esac
}
combined_phase() {
    printf '%s\n' "phase $1" >> "$artifacts/calls"
    case "$1" in
        cancel)
            if [ -f "$artifacts/outage" ]; then
                echo 'Cancel must execute while the current owner is live' >&2; return 1
            fi ;;
        dependency) test -f "$artifacts/outage" ;;
        *) return 1 ;;
    esac
}
snapshot() { test "$*" = 'web fixture-web web-queue-outage'; }
host_identities() {
    if [ "$1" = capture ]; then
        input=$(cat)
        [[ "$input" = *ffmpeg* ]] || return 1
        test ! -f "$artifacts/outage"
        printf '{"encoderIdentity":102}\n'
    else
        test -f "$artifacts/recovered"
        read -r identity
        test "$identity" = '{"encoderIdentity":102}'
        printf '{"contained":true}\n'
    fi
}
operator() { test "$*" = 'recover 0'; touch "$artifacts/recovered"; }
checkpoint() { test "$1" = complete; touch "$artifacts/complete"; }
''' + final_drill)
        result = subprocess.run(['bash', str(script), str(artifacts), str(stopped).lower()],
                                text=True, capture_output=True, timeout=5)
        assert result.returncode == 0, (stopped, result.stderr)
        assert (artifacts / 'complete').is_file(), 'Final browser checkpoint was skipped'
        assert json.loads((artifacts / 'final-containment.json').read_text())['contained'] is True
        calls = (artifacts / 'calls').read_text().splitlines()
        top = next(i for i, call in enumerate(calls) if call.startswith('top '))
        cancel = calls.index('phase cancel')
        outage = next(i for i, call in enumerate(calls) if call.startswith('stop '))
        assert top < cancel < outage, calls
    print('PASS: 2 final-drill cases; encoder identities captured before cancel/outage, failed or draining worker retired, containment and final browser checkpoint preserved.')

# Phase stdout can contain production logger JSON. Only the dedicated receipt
# is machine evidence, and missing/invalid receipts must still fail the drill.
phase_function = source[source.index('combined_phase() {'):source.index('\nsnapshot() {')]
with tempfile.TemporaryDirectory(prefix='baander-phase-receipt-test-') as temporary:
    root = pathlib.Path(temporary)
    for mode in ['logged', 'missing', 'malformed', 'wrong-phase', 'failed']:
        artifacts = root / mode
        artifacts.mkdir()
        script = artifacts / 'case.sh'
        script.write_text(r'''#!/usr/bin/env bash
set -euo pipefail
artifacts=$1
mode=$2
run_id=fixture
web_name=fixture-web
work=/unused
secrets_volume=fixture-secrets
image_id=fixture-image
media_mounts=()
docker_local() {
    case "$1" in
        run)
            printf '{"message":"Redis cache unavailable","level":"WARNING"}\n'
            if [ "$mode" = failed ]; then return 1; fi ;;
        cp)
            if [ "$mode" = missing ]; then return 1; fi
            test "$2" = 'fixture-web:/var/www/html/storage/two-command/result-dependency.json'
            case "$mode" in
                malformed) printf '{broken' > "$3" ;;
                wrong-phase) printf '{"phase":"attach","seconds":0.1,"evidence":{}}' > "$3" ;;
                *) printf '{"phase":"dependency","seconds":0.1,"evidence":{"queueAdmissionStatus":503}}' > "$3" ;;
            esac ;;
        exec) : ;;
        *) return 1 ;;
    esac
}
''' + phase_function + '\ncombined_phase dependency\n')
        result = subprocess.run(['bash', str(script), str(artifacts), mode],
                                text=True, capture_output=True, timeout=5)
        assert (result.returncode == 0) is (mode == 'logged'), (mode, result.returncode, result.stderr)
        if mode == 'logged':
            assert json.loads((artifacts / 'dependency.json').read_text())['evidence']['queueAdmissionStatus'] == 503
            assert 'Redis cache unavailable' in (artifacts / 'dependency.stdout.log').read_text()
    print('PASS: 5 phase-receipt cases; logger stdout preserved separately and missing, malformed, mismatched or failed phase evidence rejected.')
PY
