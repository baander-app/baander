#!/usr/bin/env bash
# Checks an FFmpeg binary against the hardware capabilities, AAC codecs, filters
# and demuxers the application can select, and that every shared object the
# binaries need resolves.
#
#   ffmpeg-capability-gate.sh <ffmpeg-binary> <expected-capabilities.txt>
#
# The expected file lists one capability per line: `hwaccel <name>`,
# `encoder <name>`, `decoder <name>`, `filter <name>` or `demuxer <name>`.
# ffprobe is expected beside ffmpeg.
set -euo pipefail

FFMPEG=${1:?ffmpeg binary}
EXPECTED=${2:?expected capabilities file}
FFPROBE="$(dirname "$FFMPEG")/ffprobe"

missing=0
hwaccels=$("$FFMPEG" -hide_banner -hwaccels | tail -n +2)
encoders=$("$FFMPEG" -hide_banner -encoders | awk 'NR > 10 || /^ [VAS]/ { print $2 }')
decoders=$("$FFMPEG" -hide_banner -decoders | awk 'NR > 10 || /^ [VAS]/ { print $2 }')
filters=$("$FFMPEG" -hide_banner -filters | awk 'NR > 8 { print $2 }')
demuxers=$("$FFMPEG" -hide_banner -demuxers | awk '$1 ~ /D/ { print $2 }' | tr ',' '\n')
# Here-strings, not `echo | grep -q`: grep -q exits at its first match, and under
# pipefail the writer's SIGPIPE (141) would read as a missing capability.
while read -r kind name; do
  case "$kind" in
    ''|'#'*) continue ;;
    hwaccel) grep -qx "$name" <<<"$hwaccels" || { echo "gate: missing hwaccel $name" >&2; missing=1; } ;;
    encoder) grep -qx "$name" <<<"$encoders" || { echo "gate: missing encoder $name" >&2; missing=1; } ;;
    decoder) grep -qx "$name" <<<"$decoders" || { echo "gate: missing decoder $name" >&2; missing=1; } ;;
    filter) grep -qx "$name" <<<"$filters" || { echo "gate: missing filter $name" >&2; missing=1; } ;;
    demuxer) grep -qx "$name" <<<"$demuxers" || { echo "gate: missing demuxer $name" >&2; missing=1; } ;;
    *) echo "gate: unknown entry '$kind $name' in $EXPECTED" >&2; exit 2 ;;
  esac
done < "$EXPECTED"

for bin in "$FFMPEG" "$FFPROBE"; do
  [ -x "$bin" ] || { echo "gate: $bin is not executable" >&2; exit 2; }
  # ldd exits 1 for a static binary, which has nothing to resolve.
  libraries=$(ldd "$bin" || true)
  if grep -q 'not found' <<<"$libraries"; then
    echo "gate: $bin has unresolved shared objects:" >&2
    grep 'not found' <<<"$libraries" >&2
    missing=1
  fi
done

if [ "$missing" != 0 ]; then
  echo "gate: FAIL" >&2
  exit 1
fi
echo "gate: OK ($(grep -c -E '^(hwaccel|encoder|decoder|filter|demuxer) ' "$EXPECTED") capabilities present, shared objects resolved)"
