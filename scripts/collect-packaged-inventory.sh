#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
php "$script_dir/packaged_inventory.php" baseline
session=$(mktemp -d "${TMPDIR:-/tmp}/baander-application-inventory-session.XXXXXXXX")
collector_pid=
cleanup() {
    local status=$?
    trap - EXIT INT TERM
    if [[ -n "$collector_pid" ]]; then
        kill -TERM "$collector_pid" 2>/dev/null || true
        wait "$collector_pid" 2>/dev/null || true
    fi
    if php "$script_dir/packaged_inventory.php" cleanup-collection --session "$session"; then
        :
    else
        cleanup_status=$?
        if [[ "$status" -eq 0 ]]; then status=$cleanup_status; fi
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
php "$script_dir/packaged_inventory.php" collect --session "$session" "$@" &
collector_pid=$!
if wait "$collector_pid"; then status=0; else status=$?; fi
collector_pid=
exit "$status"
