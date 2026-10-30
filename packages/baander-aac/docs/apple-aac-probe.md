# Apple AAC probe

Measured on 2026-10-30. This note records what Apple's Core Audio AAC
encoder does, as far as its output bitstreams show it, and which of
those behaviours improved baander-aac. It follows the
[codec comparison](codec-comparison.md), which found Apple ahead of us
at AAC-LC 112 kbit/s and above, in true VBR, in HE-AAC at 40 kbit/s and
in speed.

Apple's encoder is qaac 2.85 over CoreAudioToolbox 7.10.9.0 under Wine,
set up by [bench/setup-refs.sh](../bench/setup-refs.sh). Everything
below comes from its output streams and from public literature. Its
binaries were not disassembled or decompiled.

## Method

- **Probe.** `aac-probe` (built from `tools/aac-probe.cpp`) decodes an
  ADTS stream with our decoder and prints one JSON line per frame. Each
  line holds the window sequence and shape, the grouping, TNS, M/S, and
  per band the codebook, scalefactor, largest magnitude, bit cost,
  signal energy and coding noise. Given the source PCM it recomputes the
  source MDCT with the stream's own window sequence and shape, so the
  noise is measured per band in both the coded and the left/right
  domain. Apple's ADTS streams lead the source by 2112 samples, ours by
  2048; `--find-offset` finds the delay.
- **Rounding.** The probe also histograms, for every coded line, the
  fractional part of |x|^(3/4) against whether the encoder rounded up
  or down.
- **Synthetic signals.** Pure tones from -20 to -120 dBFS, white and
  pink noise, level-scaled copies of corpus items, and single
  transients.
- **A/B.** Every change was scored on the ten TCDAudio14 items of the
  comparison corpus, decoded by FFmpeg, with GstPEAQ (basic) and
  ViSQOL v3 (audio mode). Differences are paired per item and reported
  with a t-based 95 % interval over the ten items.

## What Apple's encoder does

- **Rounding is the same as ours.** Lines round up when the fractional
  part of |x|^(3/4) exceeds about 0.595, which is the standard offset of
  0.4054. There is no trellis or rate-distortion rounding.
- **Long windows are sine at 96 kbit/s and above.** Nine of ten items
  use sine long windows throughout. Glockenspiel switches to KBD in
  sustained stretches; neither spectral flatness nor perceptual entropy
  predicts when. At 64 kbit/s every item uses KBD.
- **Short blocks are more frequent and grouped by energy.** Apple
  switches to short windows more often than we do and groups the eight
  short windows into one to six groups by energy. Our encoder always
  used four groups.
- **TNS is rare.** Apple used TNS in 0 to 14 % of frames per item. Ours
  used it in up to 27 %, including every frame of a pure 6 kHz tone.
- **There is no hard lowpass.** Below a cutoff close to ours Apple codes
  every band at full precision. Above it, up to about 17 kHz at
  128 kbit/s and 19.5 kHz at 192 kbit/s, it codes sparse, coarse bands:
  magnitudes of about 1 at 3 to 5 dB SNR.
- **The noise shape is fixed.** On white and pink noise the SNR falls
  with frequency in the same shape at every level. Low frequencies get
  less noise than ours.
- **There is no absolute-threshold gate.** A lone tone is coded down to
  -120 dBFS.
- **True VBR is scale-invariant.** Scaling the input by any gain moves
  the coding noise by exactly that gain; the scalefactors shift and the
  quantized values stay the same. Within a frame the noise grows with
  about the square root of band energy.
- **True VBR spends where it is needed.** At quality 63 the rate per
  item ranges from 123 to 154 kbit/s. Frames with short blocks take
  about 1.65 times the bytes of long frames. Glockenspiel scores -1.28
  in Apple's CBR and -0.43 in its VBR at the same mean rate.

## What improved our encoder

| Change | Rate | PEAQ | ViSQOL |
|---|---|---|---|
| Sine long windows, TNS gain threshold 2.0 | 96k | +0.137 ± 0.126 | +0.012 ± 0.025 |
| | 128k | +0.162 ± 0.191 | +0.003 ± 0.009 |
| | 160k | +0.060 ± 0.072 | +0.005 ± 0.010 |
| | HE-AAC 48k | +0.051 ± 0.051 | -0.006 ± 0.006 |
| Sparse bands up to 20 kHz | 96k | +0.206 ± 0.180 | +0.210 ± 0.104 |
| | 128k | +0.022 ± 0.070 | +0.148 ± 0.058 |
| | 160k | -0.006 ± 0.063 | +0.161 ± 0.092 |

Sine long windows give most of the first change's gain. Harpsichord at 128 kbit/s moves
from -1.62 to -0.82 and Ravel from -1.38 to -0.93. The low-delay and
xHE-AAC encoders keep KBD and the previous TNS rule.

The rate loop was also rebuilt for speed:

| Profile | CPU before | CPU after |
|---|---|---|
| AAC-LC 128 kbit/s | 3.01 s | 1.20 s |
| AAC-LC 256 kbit/s | 3.60 s | 1.05 s |
| HE-AAC 48 kbit/s | 1.94 s | 0.81 s |

These are CPU seconds for a 45 s stereo excerpt on one core; 1.20 s is
37 times realtime. The threshold-reduction search warm-starts from the
previous frame and interpolates (false position with the Illinois
correction), and each band's scalefactor search starts from an estimate
of the rounding noise. Its PEAQ cost is at most 0.008 ± 0.007.

Sparse bands above the lowpass, as Apple codes them, made the third
change. AAC-LC in CBR and ABR at 48 to 95 kbit/s per channel codes the
bands from its lowpass up to 20 kHz at no better than 6 dB SNR, and
drops them when the frame runs short:

| Rate | PEAQ | ViSQOL |
|---|---|---|
| 96k | +0.206 ± 0.180 | +0.210 ± 0.104 |
| 128k | +0.022 ± 0.070 | +0.148 ± 0.058 |
| 160k | -0.006 ± 0.063 | +0.161 ± 0.092 |

The same extension at 192 kbit/s cost 0.045 ± 0.037 PEAQ while ViSQOL
rose by 0.16, so 96 kbit/s per channel and above keep the plain
lowpass. A plain wider lowpass, coding the extra bands at full
precision, cost PEAQ at 96 and 192 kbit/s (intervals given where
measured):

| Rate | Lowpass | PEAQ | ViSQOL |
|---|---|---|---|
| 96k | 17 kHz | -0.081 | +0.255 |
| 128k | 20 kHz | +0.024 ± 0.161 | +0.230 |
| 192k | 20 kHz | -0.066 | +0.165 |

PEAQ and ViSQOL disagree on bandwidth at 192 kbit/s. No listening test
settled it. The extension costs about 7 % more encode time at
128 kbit/s.

## What did not help

None of these improved PEAQ on the ten items; most made it worse.

- Raising the threshold at low frequencies to match Apple's lower
  low-frequency noise.
- Changing the signal-to-mask ratio or the Bark spreading slopes.
- A frequency tilt on the thresholds.
- More short blocks. They cost glockenspiel more than they gained on
  castanets.
- Energy-based short-window grouping.
- Extra bits for short-block or transient frames in CBR.
- Spending by perceptual entropy, and a larger reservoir in VBR mode
  (+0.005).
- Three true-VBR designs: one constant reduction relative to the
  thresholds, a constant mean noise-to-mask ratio, and allowances
  normalised to the thresholds. All scored well below CBR at the same
  mean rate.

## Remaining gaps

- **True VBR.** At an equal mean rate of 136 kbit/s Apple's VBR scores
  -0.409 PEAQ and our CBR -0.639, a gap of 0.23 ± 0.11. ViSQOL differs
  by 0.083. Steely Dan, castanets and glockenspiel carry most of it. Our
  quality scale is also off: quality 60 gives about 294 kbit/s. Apple's
  scale invariance and square-root noise rule suggest its VBR sets
  noise from band energy and not from a masking model; our thresholds
  do not reproduce it.
- **The psychoacoustic model.** The probe shows where Apple puts its
  noise but not why. Copying the observed noise shape into our
  thresholds did not raise PEAQ.
- **Speed.** Apple encodes at about 94 times realtime and we at about
  34. Sectioning is now 37 % of our encode time and band quantization
  16 %.
- **Bandwidth at 192 kbit/s and up.** Apple codes sparse bands to
  19.5 kHz there. PEAQ penalises it in our encoder and ViSQOL rewards
  it; a listening test would decide.
- **Window shape.** Apple's switch to KBD on sustained glockenspiel
  passages is not reproduced.
