#!/usr/bin/env bash
# Fetch the held-out recordings of the lossless stage into $1 (default
# work/heldout) and verify each against the SHA-256 manifest next to
# this script.  Prints the directory on standard output and nothing else
# there.  A directory that already verifies is left alone, so the
# directory can sit behind a cache keyed on the manifest.
#
# This script downloads and checksums and does nothing else: it does not
# decode a file or run any encoder on it.  The lossless stage is the
# only thing that may, and it keeps a ledger of when it did (see
# README.md, "Held-out recordings").
#
#   sintel-master-51.flac, sintel_trailer-audio.flac
#       the 5.1 master and the trailer audio of the open movie Sintel,
#       (c) Blender Foundation | durian.blender.org, Creative Commons
#       Attribution 3.0, from media.xiph.org
#   open-goldberg-variatio-4.flac
#       Kimiko Ishizaka, J.S. Bach: "Open" Goldberg Variations, BWV 988,
#       Variatio 4, 96 kHz 24-bit; Creative Commons Zero 1.0, from the
#       Internet Archive item OpenGoldbergVariations
#
# Needs: curl, sha256sum.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
dest=$(realpath -m "${1:-$here/work/heldout}")
manifest="$here/heldout.sha256"

sources=(
  "sintel-master-51.flac https://media.xiph.org/sintel/sintel-master-51.flac"
  "sintel_trailer-audio.flac https://media.xiph.org/sintel/sintel_trailer-audio.flac"
  "open-goldberg-variatio-4.flac https://archive.org/download/OpenGoldbergVariations/Kimiko%20Ishizaka%20-%20J.S.%20Bach-%20-Open-%20Goldberg%20Variations%2C%20BWV%20988%20%28Piano%29%20-%2005%20Variatio%204%20a%201%20Clav..flac"
)

verified() {
  [ -d "$dest" ] && (cd "$dest" && sha256sum -c --quiet "$manifest") >/dev/null 2>&1
}

if ! verified; then
  mkdir -p "$dest"
  for source in "${sources[@]}"; do
    name=${source%% *}
    url=${source#* }
    if ! (cd "$dest" && grep " $name\$" "$manifest" | sha256sum -c --quiet) >/dev/null 2>&1; then
      curl -fsSL --retry 3 -o "$dest/$name.part" "$url" >&2
      mv "$dest/$name.part" "$dest/$name"
    fi
  done
  (cd "$dest" && sha256sum -c --quiet "$manifest") >&2
fi
echo "$dest"
