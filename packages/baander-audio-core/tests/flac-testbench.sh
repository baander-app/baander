#!/usr/bin/env bash
# Fetch the FLAC decoder testbench (ietf-wg-cellar/flac-test-files, CC0
# 1.0) at a pinned commit into $1 (default ../bench/work/flac-test-files)
# and verify every file against the SHA-256 manifest next to this
# script.  Prints the directory on standard output and nothing else
# there.  The testbench is about 300 MB and is never committed; a
# directory that already verifies is left alone.
#
# The conformance suite reads the directory from BAAC_FLAC_TESTBENCH.
#
# Needs: curl, tar, sha256sum.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
dest=$(realpath -m "${1:-$here/../bench/work/flac-test-files}")
commit=aa7b0c6cf32994c106ae517a08134c28a96ff5b2
manifest="$here/flac-testbench.sha256"

verified() {
  [ -d "$dest" ] && (cd "$dest" && sha256sum -c --quiet "$manifest") >/dev/null 2>&1
}

if ! verified; then
  mkdir -p "$dest"
  tmp=$(mktemp -d)
  trap 'rm -rf "$tmp"' EXIT
  curl -fsSL -o "$tmp/testbench.tar.gz" \
    "https://github.com/ietf-wg-cellar/flac-test-files/archive/$commit.tar.gz"
  tar -xzf "$tmp/testbench.tar.gz" -C "$dest" --strip-components=1
  (cd "$dest" && sha256sum -c --quiet "$manifest") >&2
fi
echo "$dest"
