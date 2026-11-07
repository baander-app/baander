#!/usr/bin/env bash
# Checks an FFmpeg binary against the hardware capabilities the application
# can select, and that every shared object the binaries need resolves.
#
#   ffmpeg-capability-gate.sh <ffmpeg-binary> <expected-capabilities.txt>
#
# The expected file lists one capability per line: `hwaccel <name>` or
# `encoder <name>`. ffprobe is expected beside ffmpeg.
set -euo pipefail

FFMPEG=${1:?ffmpeg binary}
EXPECTED=${2:?expected capabilities file}
FFPROBE="$(dirname "$FFMPEG")/ffprobe"

missing=0
hwaccels=$("$FFMPEG" -hide_banner -hwaccels | tail -n +2)
encoders=$("$FFMPEG" -hide_banner -encoders | awk 'NR > 10 || /^ [VAS]/ { print $2 }')
while read -r kind name; do
  case "$kind" in
    ''|'#'*) continue ;;
    hwaccel) echo "$hwaccels" | grep -qx "$name" || { echo "gate: missing hwaccel $name" >&2; missing=1; } ;;
    encoder) echo "$encoders" | grep -qx "$name" || { echo "gate: missing encoder $name" >&2; missing=1; } ;;
    *) echo "gate: unknown entry '$kind $name' in $EXPECTED" >&2; exit 2 ;;
  esac
done < "$EXPECTED"

for bin in "$FFMPEG" "$FFPROBE"; do
  [ -x "$bin" ] || { echo "gate: $bin is not executable" >&2; exit 2; }
  if ldd "$bin" | grep -q 'not found'; then
    echo "gate: $bin has unresolved shared objects:" >&2
    ldd "$bin" | grep 'not found' >&2
    missing=1
  fi
done

if [ "$missing" != 0 ]; then
  echo "gate: FAIL" >&2
  exit 1
fi
echo "gate: OK ($(grep -c -E '^(hwaccel|encoder) ' "$EXPECTED") capabilities present, shared objects resolved)"
