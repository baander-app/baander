#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${BAANDER_TEST_IMAGE:?Set BAANDER_TEST_IMAGE to the production worker target built from this checkout.}"
# The combined media drill includes the production sixty-second audio refusal.
# One outer deadline owns cleanup; ordinary operator deadlines remain unchanged.
exec timeout 600s env BAANDER_TEST_TWO_COMMAND=1 \
    BAANDER_WORKER_PRODUCTION_TIMEOUT_ACTIVE=1 BAANDER_WORKER_OPERATOR_TIMEOUT_ACTIVE=1 \
    bash scripts/test-worker-production-container.sh
