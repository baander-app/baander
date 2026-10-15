#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
exec bash scripts/test-backend-container.sh --testsuite Unit,StaticAnalysisRules "$@"
