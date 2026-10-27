#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail
exec php "$(dirname "$0")/test-registry-static.php" "$@"
