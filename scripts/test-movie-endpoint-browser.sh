#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${BAANDER_TEST_IMAGE:?Set BAANDER_TEST_IMAGE to the production worker target built from this checkout.}"
case "${BAANDER_TEST_BROWSER_ENGINE:-chromium}" in
    chromium|firefox) ;;
    *) echo 'BAANDER_TEST_BROWSER_ENGINE must be chromium or firefox.' >&2; exit 64 ;;
esac
# The dedicated phase uses the real application without unrelated outage drills.
exec timeout 300s env BAANDER_TEST_MOVIE_ENDPOINT=1 BAANDER_TEST_TWO_COMMAND=1 \
    BAANDER_TEST_TWO_COMMAND_CHECKPOINT="$PWD/scripts/test-two-command-browser-checkpoint.sh" \
    BAANDER_WORKER_PRODUCTION_TIMEOUT_ACTIVE=1 BAANDER_WORKER_OPERATOR_TIMEOUT_ACTIVE=1 \
    bash scripts/test-worker-production-container.sh
