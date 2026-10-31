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

## True VBR

At 136 kbit/s Apple's true VBR (quality 63) leads our CBR by
0.21 ± 0.13 PEAQ. Our CBR leads it on ViSQOL by 0.08, and it is level
with Apple's own CBR on PEAQ. The study below aligned our encoder's
frames with Apple's, by delaying our input 64 samples. It then compared
the noise Apple left in each band with our encoder's masking
threshold for the same band.

What Apple's noise follows:

- **Energy and threshold together.** Across bands, Apple's noise fits
  sqrt(energy × our threshold) better than either alone. It sits a
  median 11.5 dB above our threshold. The residual is still 5.4 dB.
- **Tonality.** Apple's SNR rises steeply as a band gets more tonal.
  Our energy-to-threshold ratio barely moves:

  | Band flatness | Apple SNR | Our energy / threshold |
  |---|---|---|
  | -40 to -20 dB | 24.1 dB | 29.0 dB |
  | -20 to -12 dB | 17.8 dB | 28.6 dB |
  | -12 to -8 dB | 13.1 dB | 27.1 dB |
  | -8 to -5 dB | 8.9 dB | 25.6 dB |

- **Per-item level.** Glockenspiel is coded about 5 dB finer than any
  of these rules predict.
- **Frame bits.** Frame bits grow only with about the 0.4th power of
  our perceptual entropy.

Every VBR built on these findings lost to our CBR at the same mean
rate, or at best matched it:

| Design | PEAQ vs CBR | ViSQOL vs CBR |
|---|---|---|
| Noise = k · sqrt(energy × threshold) | -0.47 ± 0.47 | -0.04 |
| Same, with tonality-weighted thresholds | -0.22 ± 0.22 | -0.04 |
| Frame bits = k · PE^0.41, rate loop per frame | +0.02 ± 0.09 | not measured |
| Frame bits from PE relative to the item's mean, rate-matched | +0.01 ± 0.10 | -0.09 ± 0.06 |
| Tonality-weighted thresholds in CBR | +0.03 ± 0.06 | -0.01 ± 0.01 |

The model-based rules starve tonal, sparse items: glockenspiel fell to
87 to 98 kbit/s where Apple gives it 134. The PE-relative rule keeps
each item's rate. It gains 0.3 to 0.4 on glockenspiel and loses
elsewhere. The evidence points at Apple's perceptual model, not its
bit allocation. Its tonality dependence explains part of the noise
placement, but our thresholds with tonality added did not reproduce
the gain.

## Psychoacoustic model

A third round added tonality and temporal masking to our thresholds and
tested them in CBR and in true VBR. Only forward masking landed.

**Forward masking.** For AAC-LC and HE-AAC with 1024-sample frames,
each band's energy in the previous long frame now masks noise 30 dB
below it. A band that decays is then coded no finer than that level.
Against the previous commit:

| Rate | PEAQ | ViSQOL |
|---|---|---|
| 64k | -0.000 ± 0.010 | +0.003 ± 0.004 |
| 96k | +0.008 ± 0.022 | +0.016 ± 0.014 |
| 128k | +0.019 ± 0.015 | +0.011 ± 0.012 |
| 192k | +0.017 ± 0.019 | +0.001 ± 0.002 |
| HE-AAC 48k | +0.006 ± 0.017 | +0.002 ± 0.005 |
| 64 to 192k pooled (40 pairs) | +0.011 ± 0.008 | +0.008 ± 0.005 |

The gain is small but holds at every rate. A level 3 dB lower gave less
(+0.016 at 128 kbit/s), and the same rule between short windows gave
nothing. Encode time did not change. The low-delay and xHE-AAC encoders
keep the plain model and their committed streams.

**Tonality.** A band's tonality is the share of its energy in spectral
peaks. Peaks are found on a phase-insensitive power estimate: each
line's square plus the square of its neighbours' half difference. Each
peak counts with its two neighbours. Noise-like bands were allowed up to
6 dB more noise than tonal ones. In CBR this gained +0.025 to +0.03 at
96 and 128 kbit/s, but pooled over 64 to 192 kbit/s on top of forward
masking it measured +0.005 ± 0.013 PEAQ and +0.002 ± 0.005 ViSQOL. It
did not land.

**True VBR with a fitted model.** A regression of Apple's per-band noise
on our threshold, Bark frequency, tonality and energy-to-threshold ratio
leaves a 5.3 dB residual:

noise / threshold ≈ -12.5 + 0.83·bark - 6.8·tonality +
0.18·bark·tonality + 0.48·(energy / threshold) dB

Used directly as a true-VBR rule at 136 kbit/s, it scored -0.11 ± 0.26
PEAQ and -0.10 ViSQOL against our CBR. Contrabassoon, steelpan, soprano
and Strauss improved by 0.4 to 0.5. Glockenspiel fell by 1.9 and
harpsichord by 1.1. In glockenspiel's bands 14 to 34 our noise sat 5 to
10 dB above Apple's. Steeper threshold spreading (25/40 and 35/50
dB/Bark against 15/30) brought PEAQ level with CBR (+0.01 to +0.04 ±
0.2), but it used 5 to 7 % more bits and ViSQOL stayed 0.09 lower.
Smoothing tonality across frames or neighbouring bands, or weighting it
more heavily, did not help.

**TVBR spending.** TVBR and CVBR used to spend leftover bits like CBR,
so a low TVBR quality never went below the nominal bitrate. They now
stop at the noise their quality asks for. On the 45 s excerpt, quality
10 now gives 97 kbit/s instead of 131. Quality 50 gives 230 instead of
240.

## Remaining gaps

- **True VBR.** Apple's VBR leads by about 0.2 PEAQ. Neither a new
  allocation rule nor tonality and forward masking in our thresholds
  closed it (see above). Sparse tonal items such as glockenspiel are
  where every model-based rule loses. Our quality scale is also high:
  quality 50 gives about 230 kbit/s.
- **Bandwidth at 192 kbit/s and up.** Apple codes sparse bands to
  19.5 kHz there. PEAQ penalises it in our encoder and ViSQOL rewards
  it; a listening test would decide.
- **Window shape.** Apple's switch to KBD on sustained glockenspiel
  passages is not reproduced.

## Speed

A second round reuses work between the rate loop's passes. A band
keeps its codebook bit counts, its scalefactor and its quantized values
while its allowance or scalefactor does not change. Sectioning also
runs in linear time per band. Output is byte-identical:

| Profile | CPU before | CPU after |
|---|---|---|
| AAC-LC 128 kbit/s | 1.20 s | 0.55 s |
| AAC-LC 256 kbit/s | 1.24 s | 0.58 s |
| HE-AAC 48 kbit/s | 0.89 s | 0.48 s |

These are CPU seconds for the same 45 s excerpt. The comparison
harness's speed stage, on its 130 s corpus at 128 kbit/s, now gives:

| Encoder | CPU |
|---|---|
| Ours | 1.71 s |
| Apple | 1.61 s |
| FDK | 1.64 s |
| libxaac | 0.90 s |
