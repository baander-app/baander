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
#   avon-chang-cello-suite-6.flac
#       Avon Chang plays J.S. Bach's Cello Suite No. 6 in D major on the
#       piano, recorded with a Sony PCM-D1 at 96 kHz, 24-bit, in Guelph
#       on 27 January 2012; Creative Commons Zero 1.0, from the Internet
#       Archive item
#       PCMD1_piano_1119006_Bach_Cello_Suite_6_D_Major_120127_front_right,
#       whose FLAC the Archive derived from the uploaded WAV (a checksum
#       failure here may mean the Archive derived it again)
#   tearsofsteel-surround.flac
#       the DVD 5.1 mix of the open movie Tears of Steel,
#       (CC) Blender Foundation | mango.blender.org, Creative Commons
#       Attribution 3.0, from media.xiph.org
#   cloudkicker-let-yourself-be-huge.flac
#       Cloudkicker (Ben Sharp), "Let Yourself Be Huge" (2011), Creative
#       Commons Attribution 3.0, from Wikimedia Commons
#
# Needs: curl, sha256sum.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
dest=$(realpath -m "${1:-$here/work/heldout}")
manifest="$here/heldout.sha256"

sources=(
  "avon-chang-cello-suite-6.flac https://archive.org/download/PCMD1_piano_1119006_Bach_Cello_Suite_6_D_Major_120127_front_right/12012709_front_right_cello.flac"
  "tearsofsteel-surround.flac https://media.xiph.org/tearsofsteel/tearsofsteel-surround.flac"
  "cloudkicker-let-yourself-be-huge.flac https://upload.wikimedia.org/wikipedia/commons/0/04/Cloudkicker_-_Let_Yourself_Be_Huge.flac"
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
