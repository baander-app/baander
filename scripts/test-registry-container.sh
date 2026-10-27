#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
exec php "$script_dir/test-registry-container.php" "$@"
