#!/usr/bin/env bash
# Fetch the benchmark corpus: ten 48 kHz stereo music excerpts of the
# TCDAudio14 set and one speech item, as distributed in the ViSQOL
# repository (Apache-2.0) at a pinned commit, verified by SHA-256.
set -euo pipefail
dest="${1:-$(dirname "$0")/work/corpus}"
commit=38d0b0163e441047d4429bf07ad09e5b9031d02c
base="https://raw.githubusercontent.com/google/visqol/$commit/testdata"
mkdir -p "$dest"
while read -r sum path; do
  name=$(basename "$path")
  if [ ! -f "$dest/$name" ] || ! echo "$sum  $dest/$name" | sha256sum -c --quiet - 2>/dev/null; then
    curl -fsSL -o "$dest/$name" "$base/$path"
  fi
  echo "$sum  $dest/$name" | sha256sum -c --quiet -
done <<'LIST'
1579948f92658b44a58ab87464a66ce6a0578996261b8d6c17d8a5952f90ad2f conformance_testdata_subset/strauss48_stereo.wav
7db23a912a337ff88533175d8e9fcf6dd0b1002473852eb4a7670644102a6cbe conformance_testdata_subset/sopr48_stereo.wav
80a2732de2963785c6fbdd3ac9fc51f72d77a747d189609d7b20a9efc2b7ad6d conformance_testdata_subset/contrabassoon48_stereo.wav
88225befce729ca347b9d8ba9401c16fcbb3b5b54f057ba1da60fcebfb6d0872 conformance_testdata_subset/guitar48_stereo.wav
0c36171272f190c3ff826eb51f23c2b87298f3cb463efd495b6160a1cc59883d conformance_testdata_subset/glock48_stereo.wav
9d47aac38d9ccca511be0419222a0bf6125b411f8b4600c6a289fbcd78bd0cb7 conformance_testdata_subset/castanets48_stereo.wav
93ecba59f8f104a19e87a45fb8ec769449cb85704ae6a047a82d884fa494116b conformance_testdata_subset/harpsichord48_stereo.wav
4ea466e5ee48224d78502799b9547ee4fbab1e3b8552757b3f1019017676b67a conformance_testdata_subset/steely48_stereo.wav
15f383ee739a7936cac946419dd896525e22327f449060fac6ea0890c097fc44 conformance_testdata_subset/ravel48_stereo.wav
daa0bd83c5b193d55657528ca8f04289c2421848a9cd1a0ef452f945a1e0b322 conformance_testdata_subset/moonlight48_stereo.wav
9d150f092cd14a98c2ae2ff8ca8e5bc9445e245a95db5074ca75103bad61ae16 clean_speech/CA01_01.wav
LIST
echo "corpus ready in $dest"
