#!/usr/bin/env bash
# Build or fetch the reference codecs and quality meters the benchmark
# compares against, at the versions the published results used, into
# $1 (default ./work/ext), and write env.sh there for bench.py.
#
# Needs: git, cmake, ninja, a C/C++ toolchain, autotools, the GStreamer
# development packages (GstPEAQ), wine and 7z (Apple AAC), curl, and
# Python 3 with numpy, scipy and soundfile.  On Ubuntu 24.04:
#   apt-get install cmake ninja-build autoconf automake libtool pkg-config \
#     libgstreamer1.0-dev libgstreamer-plugins-base1.0-dev gstreamer1.0-plugins-base gtk-doc-tools \
#     wine wine64 p7zip-full osslsigncode flac sox ffmpeg time
#   pip install numpy scipy soundfile
#
# The Apple AAC encoder is Core Audio's, from Apple's iTunes for Windows
# installer, run through qaac under Wine.  Apple's download servers were
# not reachable from the benchmark machine, so the DLLs come from the
# QTFiles repackaging of iTunes 12.13.9.1; the script checks the archive
# hashes, and `osslsigncode verify` confirms Apple's Authenticode
# signature on CoreAudioToolbox.dll and CoreFoundation.dll.  On a machine
# with iTunes installed, point QAAC at a qaac64.exe next to its DLLs
# instead.  Not redistributable: fetch it yourself.
set -euo pipefail
ext=$(realpath -m "${1:-$(dirname "$0")/work/ext}")
mkdir -p "$ext"
cd "$ext"

fetch() {  # repo dir commit
  [ -d "$2" ] || git clone -q "https://github.com/$1" "$2"
  git -C "$2" checkout -q "$3"
}

# Fraunhofer FDK AAC (aac-enc, aac-dec, xhe-aac-enc), the C++ tree of
# the AOSP mirror.  Test oracle only; nothing of it ships.
fetch mstorsjo/fdk-aac fdk-aac c41c3c7516ea8d0d78a53b96b30bed3a29745cc6
# aac-dec without loudness normalisation and limiter, so that every
# decoder returns the coded signal at its coded level.
python3 - fdk-aac/aac-dec.c <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
anchor = "\thandle = aacDecoder_Open(loas ? TT_MP4_LOAS : TT_MP4_ADTS, 1);\n"
if "AAC_DRC_REFERENCE_LEVEL" not in s:
    s = s.replace(anchor, anchor + "\taacDecoder_SetParam(handle, AAC_DRC_REFERENCE_LEVEL, -1);\n"
                  "\taacDecoder_SetParam(handle, AAC_PCM_LIMITER_ENABLE, 0);\n", 1)
    open(p, "w").write(s)
PY
cmake -S fdk-aac -B fdk-aac/build -G Ninja -DCMAKE_BUILD_TYPE=Release \
  -DBUILD_PROGRAMS=ON -DBUILD_SHARED_LIBS=OFF >/dev/null
ninja -C fdk-aac/build aac-enc aac-dec xhe-aac-enc >/dev/null

# Ittiam libxaac (xaacenc, xaacdec), tag v0.1.14.
fetch ittiam-systems/libxaac libxaac 2fbadd57bca46693b7a077bcf1513131106d960c
cmake -S libxaac -B libxaac/build -G Ninja -DCMAKE_BUILD_TYPE=Release >/dev/null
ninja -C libxaac/build xaacenc xaacdec >/dev/null

# GstPEAQ (ITU-R BS.1387 PEAQ, basic and advanced versions).  The
# documentation target needs gtk-doc, so only src/ is built.
fetch HSU-ANT/gstpeaq gstpeaq 7397e49f5ede2f2429fb78f6663784e548401fec
# automake wants the GNU files and gtk-doc.make (gtk-doc-tools) even so.
(cd gstpeaq && touch ChangeLog NEWS README AUTHORS && autoreconf -fi >/dev/null 2>&1 && ./configure -q --prefix="$ext/prefix" \
  && make -s -C src -j"$(nproc)" && make -s -C src install) >/dev/null

# qaac 2.85 and Core Audio (iTunes 12.13.9.1) for Wine.
mkdir -p apple && cd apple
dl() {  # url file sha256
  [ -f "$2" ] || curl -fsSL -o "$2" "$1"
  echo "$3  $2" | sha256sum -c --quiet -
}
dl https://github.com/nu774/qaac/releases/download/v2.85/qaac_2.85.zip qaac_2.85.zip \
  4e4a1dd8145d64b760fd73db62f155aae040fbd6189a0d40b664ea90538473a7
dl https://github.com/AnimMouse/QTFiles/releases/download/v12.13.9.1/QTfiles64.7z QTfiles64.7z \
  a81ac9146e8daec8c681e1c8d49548a950f62bb5fe44bdcdaca5aabe3afb3b8f
dl https://github.com/AnimMouse/QTFiles/releases/download/v12.13.9.1/QTfiles64-msvc.7z QTfiles64-msvc.7z \
  3dcc6888507672a27210f9c24568bc5436aef7d72a2d634d4083bbd96fc46daa
7z x -y -oqaac qaac_2.85.zip >/dev/null
7z x -y -oqt QTfiles64.7z >/dev/null
7z x -y -oqt QTfiles64-msvc.7z >/dev/null
mkdir -p run/QTfiles64
cp qaac/qaac_2.85/x64/* run/
cp qt/*.dll run/QTfiles64/
if command -v osslsigncode >/dev/null; then
  for f in CoreAudioToolbox CoreFoundation; do
    # The verification exits non-zero when only the timestamp
    # countersignature fails to verify, so read its output instead.
    { osslsigncode verify -CAfile /etc/ssl/certs/ca-certificates.crt -in "run/QTfiles64/$f.dll" || true; } \
      | grep -q "O=Apple Inc." || { echo "$f.dll: Apple signature not found" >&2; exit 1; }
  done
fi
export WINEPREFIX="$ext/wineprefix" WINEDEBUG=-all
wineboot -i >/dev/null 2>&1 || true
wine run/qaac64.exe --check
cd "$ext"

cat > env.sh <<ENV
export FDK_AAC_ENC=$ext/fdk-aac/build/aac-enc FDK_AAC_DEC=$ext/fdk-aac/build/aac-dec
export FDK_XHE_ENC=$ext/fdk-aac/build/xhe-aac-enc
export XAAC_ENC=$ext/libxaac/build/xaacenc XAAC_DEC=$ext/libxaac/build/xaacdec
export QAAC=$ext/apple/run/qaac64.exe REFALAC=$ext/apple/run/refalac64.exe
export WINEPREFIX=$ext/wineprefix WINEDEBUG=-all
export PEAQ=$ext/prefix/bin/peaq GST_PLUGIN_PATH=$ext/prefix/lib/gstreamer-1.0
ENV
[ -x "$ext/visqol/bazel-bin/visqol" ] && echo "export VISQOL=$ext/visqol/bazel-bin/visqol" >> env.sh
echo "wrote $ext/env.sh; ViSQOL: see README.md"
