#!/usr/bin/env bash
set -euo pipefail
exec php "$(dirname "$0")/test-producer-runtime-container.php" "$@"
