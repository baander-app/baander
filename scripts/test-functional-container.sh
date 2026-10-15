#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [ "$#" -eq 0 ]; then
    set -- tests/Functional
fi
exec bash scripts/test-backend-container.sh "$@"
