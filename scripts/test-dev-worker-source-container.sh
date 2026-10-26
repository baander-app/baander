#!/usr/bin/env bash
set -euo pipefail
# Extend the actual paired lifecycle drill with an owned daemon-host source bind.
# Source preparation uses disposable storage; no developer instance is contacted.
export BAANDER_TEST_DEV_SOURCE_BIND=1
export BAANDER_TEST_OPERATOR_IN_CONTAINER=1
exec bash "$(dirname "$0")/test-worker-operator-container.sh" "$@"
