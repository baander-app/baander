#!/usr/bin/env bash
# Proves that an FFmpeg binary carries the bAAC codec and that the codec works
# end to end. Runs inside the FFmpeg image build and against a local build.
#
#   smoke.sh <ffmpeg-binary> <ffprobe-binary> [work-dir]
#
# SMOKE_EXPECT_FDK=1 also requires the libfdk_aac encoder and decoder (the image build).
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
decoders=$("$FFMPEG" -hide_banner -decoders)
echo "$decoders" | grep -q ' libbaac ' || fail "libbaac decoder missing"
step "libbaac decoder present"
if [ "${SMOKE_EXPECT_FDK:-0}" = 1 ]; then
  echo "$encoders" | grep -q ' libfdk_aac ' || fail "libfdk_aac encoder missing"
  echo "$decoders" | grep -q ' libfdk_aac ' || fail "libfdk_aac decoder missing"
  step "libfdk_aac encoder and decoder present"
fi

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

# Per-channel RMS level in dB over the middle second of a decode, after the
# given filter chain. FFmpeg's astats prints one "RMS level dB" per channel.
rms_levels() {
  local decoder=$1 input=$2 filters=$3
  "$FFMPEG" -hide_banner -nostats -v info -c:a:0 "$decoder" -i "$input" \
    -af "${filters}atrim=start=0.5:end=1.5,astats=measure_overall=none:measure_perchannel=RMS_level" -f null - 2>&1 \
    | awk '/RMS level dB:/ { print $NF }'
}

# Decodes an output and compares it with the tone it was encoded from. The
# test signals are pure tones, so notching each tone frequency out leaves the
# coding noise and distortion: the level difference is a signal-to-noise ratio
# that needs no sample alignment between input and output, and the per-channel
# levels show whether the channels kept their order.
decode_and_compare() {
  local decoder=$1 input=$2 channels=$3 label=$4 notch=$5
  local total residual
  total=$(rms_levels "$decoder" "$input" "")
  residual=$(rms_levels "$decoder" "$input" "$notch")
  [ "$(echo "$total" | wc -l)" = "$channels" ] || fail "$label: expected $channels channels, astats reported: $total"
  paste <(echo "$total") <(echo "$residual") | awk -v label="$label" -v channels="$channels" '
    { signal[NR] = $1; snr = $1 - $2; if (NR == 1 || snr < worst) worst = snr }
    END {
      line = sprintf("smoke: %s: worst channel SNR %.1f dB, channel levels", label, worst)
      for (c = 1; c <= NR; c++) line = line sprintf(" %.1f", signal[c])
      print line " dB"
      if (worst < 20) { print "smoke: FAIL: " label ": SNR below 20 dB" > "/dev/stderr"; exit 1 }
      if (channels > 2) for (c = 1; c < NR; c++) if (signal[c] >= signal[c + 1]) {
        print "smoke: FAIL: " label ": channel order differs from the input" > "/dev/stderr"; exit 1
      }
    }' || exit 1
}

stereo_notch="bandreject=f=1000:width_type=h:width=100,"
six_notch="bandreject=f=440:width_type=h:width=60,bandreject=f=60:width_type=h:width=20,"

step "decode the libbaac output with FFmpeg's built-in decoder"
decode_and_compare aac "$WORK/tone.aac" 2 adts-via-aac "$stereo_notch"
decode_and_compare aac "$WORK/tone.m4a" 2 mp4-via-aac "$stereo_notch"
decode_and_compare aac "$WORK/six.m4a" 6 six-via-aac "$six_notch"

step "decode the libbaac output with libbaac"
decode_and_compare libbaac "$WORK/tone.aac" 2 adts-via-libbaac "$stereo_notch"
decode_and_compare libbaac "$WORK/tone.m4a" 2 mp4-via-libbaac "$stereo_notch"
decode_and_compare libbaac "$WORK/six.m4a" 6 six-via-libbaac "$six_notch"
# A damaged ADTS frame decodes through concealment without aborting.
# Zero part of a frame's payload in the middle of the stream, past its sync word.
cp "$WORK/tone.aac" "$WORK/damaged.aac"
dd if=/dev/zero of="$WORK/damaged.aac" bs=1 seek=$(( $(stat -c %s "$WORK/tone.aac") / 2 + 10 )) count=50 conv=notrunc status=none
"$FFMPEG" -hide_banner -v error -y -c:a:0 libbaac -i "$WORK/damaged.aac" -f null - || fail "libbaac aborted on a damaged ADTS frame"
step "damaged ADTS frame decoded with concealment"

echo "smoke: OK"
