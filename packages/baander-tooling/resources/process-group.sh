#!/usr/bin/env bash
set -euo pipefail
# setsid starts this launcher as the session and process-group leader. The parent
# verifies this identity through /proc before it can signal the group.
printf '%s\n' "$$" >&3
exec 3>&-
IFS= read -r -u 4 acknowledgement
[[ "$acknowledgement" == owned ]]
exec 4<&-
exec "$@"
