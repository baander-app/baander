#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [ "${BAANDER_WORKER_LIFECYCLE_TIMEOUT_ACTIVE:-0}" != 1 ]; then
    exec env BAANDER_WORKER_LIFECYCLE_TIMEOUT_ACTIVE=1 timeout --signal=TERM --kill-after=40s 1300s bash "$0" "$@"
fi
if [ "$#" != 0 ]; then echo 'Worker lifecycle qualification takes no arguments.' >&2; exit 2; fi
artifacts="${BAANDER_TEST_ARTIFACTS:-$(pwd)/var/test-runs/worker-lifecycle-$(date +%s)-$$}"
mkdir -p "$artifacts"; chmod 700 "$artifacts"
for phase in redis postgres; do
    BAANDER_TEST_ARTIFACTS="$artifacts/$phase" BAANDER_TEST_WORKER_LIFECYCLE_PHASE="$phase" \
        BAANDER_TEST_AUTOSCALING_MAXIMUM=2 BAANDER_TEST_TWO_COMMAND=0 \
        BAANDER_TEST_OPERATOR_IN_CONTAINER="${BAANDER_TEST_OPERATOR_IN_CONTAINER:-1}" \
        bash scripts/test-worker-operator-container.sh > "$artifacts/$phase.log" 2>&1 || {
            tail -60 "$artifacts/$phase.log" >&2; exit 1;
        }
done
python3 - "$artifacts" <<'PYVERIFY'
import json, pathlib, sys
root = pathlib.Path(sys.argv[1])
for phase in ('redis', 'postgres'):
    result = json.loads((root / phase / 'qualification.json').read_text())
    cleanup = json.loads((root / phase / 'cleanup.json').read_text())
    assert result['phase'] == phase and result['success'] is True, result
    assert cleanup['exitStatus'] == 0 and cleanup['cleanupVerified'] is True, cleanup
print('PASS: standalone Redis and PostgreSQL outage, retirement and successor checks; cleanup verified.')
PYVERIFY
echo "Worker lifecycle evidence: $artifacts"
