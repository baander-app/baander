#!/usr/bin/env bash
# Proves that an FFmpeg binary carries the bAAC codec and that the codec works
# end to end. Runs inside the FFmpeg image build and against a local build.
#
#   smoke.sh <ffmpeg-binary> <ffprobe-binary> [work-dir]
#
# SMOKE_EXPECT_FDK=1 also requires the libfdk_aac encoder (the image build).
# SMOKE_EXPECT_MAJOR (default 8.1) is the FFmpeg release the patch targets.
set -euo pipefail

FFMPEG=${1:?ffmpeg binary}
FFPROBE=${2:?ffprobe binary}
WORK=${3:-$(mktemp -d)}
MAJOR=${SMOKE_EXPECT_MAJOR:-8.1}
mkdir -p "$WORK"

fail() { echo "smoke: FAIL: $*" >&2; exit 1; }
step() { echo "smoke: $*"; }

version=$("$FFMPEG" -hide_banner -version | head -1)
case "$version" in
  "ffmpeg version ${MAJOR}"*|"ffmpeg version n${MAJOR}"*) step "version: $version" ;;
  *) fail "expected FFmpeg ${MAJOR}, got: $version" ;;
esac

encoders=$("$FFMPEG" -hide_banner -encoders)
echo "$encoders" | grep -q ' libbaac ' || fail "libbaac encoder missing"
step "libbaac encoder present"
if [ "${SMOKE_EXPECT_FDK:-0}" = 1 ]; then
  echo "$encoders" | grep -q ' libfdk_aac ' || fail "libfdk_aac encoder missing"
  step "libfdk_aac encoder present"
fi
decoders=$("$FFMPEG" -hide_banner -decoders)
have_baac_decoder=0
echo "$decoders" | grep -q ' libbaac ' && have_baac_decoder=1

# A stereo tone and a 5.1 signal with a distinct amplitude per channel.
"$FFMPEG" -hide_banner -v error -y -f lavfi -i "sine=frequency=1000:sample_rate=48000:duration=2" \
  -af "aformat=channel_layouts=stereo" -c:a pcm_f32le -f f32le "$WORK/tone.f32le"
"$FFMPEG" -hide_banner -v error -y -f lavfi \
  -i "aevalsrc=0.1*sin(2*PI*440*t)|0.2*sin(2*PI*440*t)|0.3*sin(2*PI*440*t)|0.4*sin(2*PI*60*t)|0.5*sin(2*PI*440*t)|0.6*sin(2*PI*440*t):s=48000:d=2:c=5.1,aformat=sample_fmts=flt" \
  -c:a pcm_f32le -f f32le "$WORK/six.f32le"

step "encode stereo to ADTS and fMP4 with libbaac"
"$FFMPEG" -hide_banner -v error -y -f f32le -ar 48000 -ac 2 -i "$WORK/tone.f32le" -c:a libbaac -b:a 128k -f adts "$WORK/tone.aac"
"$FFMPEG" -hide_banner -v error -y -f f32le -ar 48000 -ac 2 -i "$WORK/tone.f32le" -c:a libbaac -b:a 128k -f mp4 "$WORK/tone.m4a"
"$FFMPEG" -hide_banner -v error -y -f f32le -ar 48000 -ac 6 -channel_layout 5.1 -i "$WORK/six.f32le" -c:a libbaac -b:a 320k -f mp4 "$WORK/six.m4a"

probe=$("$FFPROBE" -hide_banner -v error -show_entries stream=codec_name,profile,sample_rate,channels -of csv=p=0 "$WORK/tone.m4a")
[ "$probe" = "aac,LC,48000,2" ] || fail "ffprobe on the fMP4 output reported '$probe', expected 'aac,LC,48000,2'"
step "ffprobe: $probe"
probe=$("$FFPROBE" -hide_banner -v error -show_entries stream=codec_name,profile,sample_rate,channels -of csv=p=0 "$WORK/tone.aac")
[ "$probe" = "aac,LC,48000,2" ] || fail "ffprobe on the ADTS output reported '$probe'"

# How the fMP4 output carries the priming: the first packet's pts against
# the stream start (an edit list gives a negative first pts; a timestamp
# offset gives a positive start_time).
first_pts=$("$FFPROBE" -hide_banner -v error -show_entries packet=pts -of csv=p=0 -read_intervals "%+#1" "$WORK/tone.m4a" | head -1)
start_time=$("$FFPROBE" -hide_banner -v error -show_entries stream=start_time -of csv=p=0 "$WORK/tone.m4a")
step "fMP4 priming: first packet pts=${first_pts}, stream start_time=${start_time}"

decode_and_compare() {
  local decoder=$1 input=$2 reference=$3 channels=$4 label=$5
  "$FFMPEG" -hide_banner -v error -y -c:a:0 "$decoder" -i "$input" -f f32le "$WORK/$label.f32le"
  python3 -I - "$reference" "$WORK/$label.f32le" "$channels" "$label" <<'PY'
import array, math, sys
ref_path, out_path, channels, label = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4]
ref = array.array('f'); ref.frombytes(open(ref_path, 'rb').read())
out = array.array('f'); out.frombytes(open(out_path, 'rb').read())
frames_ref = len(ref) // channels
frames_out = len(out) // channels
# Align on the best lag within one encoder delay plus a frame (the ADTS
# path keeps the priming, the MP4 path trims it) and measure the SNR over
# the middle of the signal.
best = (-1e9, 0)
lo, hi = 24000, min(frames_ref, 72000)
for lag in range(0, 3072, 32):
    if frames_out <= hi + lag:
        continue
    num = den = 0.0
    for f in range(lo, hi, 7):
        for c in range(channels):
            r = ref[f * channels + c]; o = out[(f + lag) * channels + c]
            num += r * r; den += (r - o) * (r - o)
    snr = 10 * math.log10(num / den) if den > 0 else 200.0
    if snr > best[0]:
        best = (snr, lag)
snr, lag = best
rms = []
for c in range(channels):
    acc = 0.0; n = 0
    for f in range(lo, hi, 7):
        v = out[(f + lag) * channels + c]; acc += v * v; n += 1
    rms.append(math.sqrt(acc / n))
order_ok = all(rms[i] < rms[i + 1] for i in range(channels - 1)) if channels > 2 else True
print(f"smoke: {label}: {frames_out} frames, lag {lag}, SNR {snr:.1f} dB, channel rms {[round(x, 3) for x in rms]}")
if snr < 20.0:
    sys.exit(f"smoke: FAIL: {label}: SNR {snr:.1f} dB below 20 dB")
if not order_ok:
    sys.exit(f"smoke: FAIL: {label}: channel order differs from the input")
PY
}

step "decode the libbaac output with FFmpeg's built-in decoder"
decode_and_compare aac "$WORK/tone.aac" "$WORK/tone.f32le" 2 adts-via-aac
decode_and_compare aac "$WORK/tone.m4a" "$WORK/tone.f32le" 2 mp4-via-aac
decode_and_compare aac "$WORK/six.m4a" "$WORK/six.f32le" 6 six-via-aac

if [ "$have_baac_decoder" = 1 ]; then
  step "decode the libbaac output with libbaac"
  decode_and_compare libbaac "$WORK/tone.aac" "$WORK/tone.f32le" 2 adts-via-libbaac
  decode_and_compare libbaac "$WORK/tone.m4a" "$WORK/tone.f32le" 2 mp4-via-libbaac
  decode_and_compare libbaac "$WORK/six.m4a" "$WORK/six.f32le" 6 six-via-libbaac
  # A damaged ADTS frame decodes through concealment without aborting.
  python3 -I - "$WORK/tone.aac" "$WORK/damaged.aac" <<'PY'
import sys
data = bytearray(open(sys.argv[1], 'rb').read())
# Corrupt the payload of a frame in the middle, not the sync word.
mid = len(data) // 2
for i in range(mid + 10, mid + 60):
    data[i] ^= 0x5A
open(sys.argv[2], 'wb').write(data)
PY
  "$FFMPEG" -hide_banner -v error -y -c:a:0 libbaac -i "$WORK/damaged.aac" -f null - || fail "libbaac aborted on a damaged ADTS frame"
  step "damaged ADTS frame decoded with concealment"
else
  step "libbaac decoder not present; decoder checks skipped"
fi

echo "smoke: OK"
