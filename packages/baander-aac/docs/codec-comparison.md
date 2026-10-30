# Codec comparison

Measured on 2026-10-30 at codec commit 166dec8. baander-aac against
FFmpeg's native `aac` encoder, Fraunhofer FDK AAC, Ittiam's libxaac and
Apple's Core Audio AAC encoder, with ALAC and FLAC as the lossless
reference. The harness in
[bench/](../bench/README.md) reproduces every number. The raw rows and
the generated tables are in `bench/results/` (`quality.csv`,
`tables.md`).

## Summary

- **The decoder is in good shape.** It decodes every stream of every
  encoder tested. Its output matches FFmpeg and Apple's Core Audio
  decoder to floating-point precision on AAC-LC. It is the fastest
  decoder measured in three of the four families, and it needs under
  10 MiB of memory.
- **The AAC-LC encoder trails FDK and libxaac at 64 to 128 kbit/s.**
  The gap is about 0.15 to 0.18 PEAQ grade points at equal bitrate.
  ViSQOL rates ours level or slightly ahead at those rates. The encoder
  leads FFmpeg's encoder by 0.26 to 0.27 PEAQ points from 160 kbit/s
  up.
- **Most of that gap is FDK's afterburner, not the core encoder.**
  Without its afterburner FDK scores below ours at every measured rate.
  Its afterburner adds 0.25 to 0.41 points. Ours, which is off by
  default, adds 0.06 to 0.11.
- **The encoder is slow.** AAC-LC encodes at 18 times realtime on one
  core, against 101 for FDK and 181 for libxaac.
- **xHE-AAC below 32 kbit/s is the largest quality gap.** FDK leads by
  0.4 to 0.6 ViSQOL points there. FDK uses tools our encoder does not
  have yet: 4:1 and 8:3 SBR, MPEG Surround 2-1-2 and the LPD core.
- **Apple's true VBR mode is the best AAC-LC encoder at 112 to 160
  kbit/s.** At equal bitrate it beats our VBR by 0.33 to 0.44 PEAQ
  points.
- **HE-AAC v2 is at parity.** HE-AAC v1 trails FDK by about 0.13 PEAQ
  points, and ViSQOL sees parity there too.
- **Lossless:** ALAC needs 690 to 697 kbit/s on this corpus, FLAC 615 to
  630. Both decode at about 200 to 400 times realtime. AAC-LC at 256
  kbit/s is 2.7 times smaller than ALAC and scores -0.04 on PEAQ.

## What was measured

| Item | Version or setting |
|------|--------------------|
| Ours | `baac` from this branch, Release build, GCC 13.3 |
| FFmpeg | 6.1.1 (Ubuntu 24.04): native `aac` encoder (two-loop coder) and decoder, ALAC encoder and decoder |
| FDK AAC | AOSP mirror `mstorsjo/fdk-aac` c41c3c7 (2026-10-12), C++ tree: `aac-enc` (afterburner on, its default), `aac-dec`, `xhe-aac-enc` |
| libxaac | `ittiam-systems/libxaac` v0.1.14 (2fbadd5): `xaacenc`, `xaacdec` |
| Apple AAC | Core Audio AAC encoder and decoder from iTunes for Windows 12.13.9.1 (CoreAudioToolbox 7.10.9.0), through qaac 2.85 under Wine 9.0, quality setting 2 (highest) |
| Lossless | FFmpeg ALAC, Apple's reference ALAC (`refalac` 2.85), FLAC 1.4.3 at levels 5 and 8 |
| Machine | 4-core Intel Xeon at 2.1 GHz (cloud VM), Ubuntu 24.04; every timed run single-threaded |

**Corpus.** Ten music excerpts from TCDAudio14 at 48 kHz, 16-bit
stereo, 7 to 16 s each, 130 s in all. They are strauss, sopr,
contrabassoon, guitar, glock, castanets, harpsichord, steely, ravel and
moonlight, taken from the ViSQOL repository at a pinned commit. The set
includes classic codec-killer items: castanets for pre-echo, glockenspiel
and harpsichord for tonal transients, and a soprano.

**Configurations.** Each encoder ran in its constant-bitrate mode at the
same nominal bitrates. AAC-LC used 64, 96, 128, 192 and 256 kbit/s.
HE-AAC v1 used 32, 48 and 64, HE-AAC v2 24 and 32, and xHE-AAC 24, 32,
48, 64 and 96. AAC-LC also ran in each encoder's own VBR scale: ours and
FDK's modes 2 to 5, and Apple's true VBR at qualities 45, 63, 82 and
100. Experiments added our encoder with its afterburner, our encoder
with FDK's lowpass, and FDK without its afterburner. That makes 81
configurations and 810 encodes.

**Quality pipeline.** One decoder per family decodes every encoder's
stream, so only the encoder differs. That is FFmpeg for AAC-LC and
HE-AAC, and libxaac for xHE-AAC, with loudness normalisation and limiter
off. The decode is aligned to the source by cross-correlation and
level-matched below 3 kHz. The level correction stays within ±0.3 dB
for AAC-LC from 96 kbit/s up. At the lowest rates it reaches 3.2 dB on
single items, where coarse coding makes the low band a poor level
reference. One pattern there is a finding of its own; see xHE-AAC.

**Metrics.**

- **PEAQ ODG**, from GstPEAQ, basic version, with the advanced version
  in the CSV. 0 is imperceptible, -1 perceptible but not annoying, -2
  slightly annoying, -3 annoying and -4 very annoying.
- **ViSQOL v3 MOS-LQO**, audio mode, default model. The scale runs from
  1 to about 4.75. Our ViSQOL build passes all 20 conformance cases of
  the ViSQOL repository.

"Δ vs ours" is the mean over the ten items of the competitor's score
minus ours, with the 95 % interval of that paired difference. Positive
means the competitor scores higher. An interval that includes zero
means the ten items do not separate the two encoders.

**Bitrates** are file bitrates including the ADTS or LOAS framing.
Encoders overshoot their nominal AAC-LC rate by different amounts:
FDK by up to 1.3 %, libxaac by up to 4.8 % and Apple by 1.5 to 5 %. So the decisive comparisons
are the equal-bitrate tables. They interpolate each item's score
linearly along each encoder's own rate curve.

## Limits of these measurements

- **No listening test.** Both metrics are models. They often disagree
  on ranking: Apple at 64 kbit/s is best on PEAQ and among the worst on
  ViSQOL. Read each finding with the metric that supports it.
- **PEAQ** (ITU-R BS.1387) was designed for small impairments. Its
  grades compress below about -3, which covers most of the HE-AAC and
  xHE-AAC rows. It was not validated for spectral band replication or
  parametric stereo. GstPEAQ states that it does not meet the
  standard's conformance requirements.
- **ViSQOL v3** rewards audio bandwidth strongly. FDK's 16.5 kHz lowpass
  at 256 kbit/s costs it 0.2 MOS against the full-band encoders, while
  PEAQ ranks FDK first there. **The corpus is ViSQOL's own conformance
  material from TCDAudio14,** which ViSQOL's mapping was developed on.
  That can flatter ViSQOL's agreement with listeners on exactly these
  items.
- **Coverage gaps.** There are ten items, all at 48 kHz stereo, with no
  44.1 kHz material, no speech and no multichannel.
  Low-delay profiles were not compared.
- **Apple ran under Wine** with DLLs from a repackaging of the iTunes
  installer, because Apple's servers were unreachable from the test
  machine. Apple's signature on the DLLs verifies. Its speed figures
  include Wine's overhead. Its encoder may differ from the one in
  current macOS.
- **libxaac's xHE-AAC encoder** accepts only 64 and 96 kbit/s. It
  silently resets any other rate to 96, so it appears only at those two
  rates.
- **FDK's xHE-AAC encoder** ran in its two-pass mode, which measures
  loudness and then encodes. Its one-pass mode (`-l`) levels the
  programme to the given loudness inside the encoder, which changes the
  signal under test by up to 13 dB.
- **FDK** is the C++ tree of the AOSP mirror, not the `libfdk-aac` 2.0.3
  that FFmpeg links.

## AAC-LC

Constant bitrate, each encoder's CBR mode. "ours+AB" is our encoder with
`--afterburner`.

| Encoder | 64 kbit/s PEAQ / ViSQOL | 96 | 128 | 192 | 256 |
|---------|------------------------:|---:|----:|----:|----:|
| ours | -3.19 / 3.85 | -1.95 / 4.10 | -0.96 / 4.35 | -0.14 / 4.50 | -0.04 / 4.71 |
| ours+AB | -3.12 / 3.84 | -1.84 / 4.10 | -0.90 / 4.35 | -0.13 / 4.50 | -0.02 / 4.72 |
| FFmpeg | -3.00 / 3.50 | -1.97 / 4.08 | -1.14 / 4.20 | -0.39 / 4.48 | -0.27 / 4.67 |
| FDK | -2.99 / 3.77 | -1.71 / 4.09 | -0.79 / 4.28 | -0.10 / 4.47 | 0.05 / 4.49 |
| FDK without AB | -3.24 / - | -2.12 / - | -1.08 / - | - | - |
| libxaac | -2.94 / 3.81 | -1.65 / 4.12 | -0.74 / 4.29 | -0.21 / 4.62 | -0.17 / 4.65 |
| Apple | -2.92 / 3.50 | -1.88 / 4.25 | -0.67 / 4.43 | -0.07 / 4.67 | 0.02 / 4.72 |

The bitrates achieved at nominal 128 kbit/s are 127.9 (ours), 131.8
(FFmpeg), 128.7 (FDK), 130.9 (libxaac) and 131.3 (Apple). The full
table with the worst item, PEAQ advanced and the intervals is in
`bench/results/tables.md`.

At equal bitrate (interpolated), Δ vs ours:

| kbit/s | FFmpeg | FDK | libxaac | Apple | ours+AB |
|-------:|-------:|----:|--------:|------:|--------:|
| 80, PEAQ | -0.03 ± 0.26 | **+0.18 ± 0.05** | **+0.15 ± 0.11** | +0.05 ± 0.21 | +0.08 ± 0.03 |
| 80, ViSQOL | **-0.25 ± 0.09** | -0.05 ± 0.04 | -0.04 ± 0.06 | **-0.17 ± 0.10** | 0.00 |
| 112, PEAQ | -0.19 ± 0.26 | **+0.18 ± 0.08** | **+0.18 ± 0.10** | +0.05 ± 0.36 | +0.08 ± 0.03 |
| 112, ViSQOL | -0.10 ± 0.16 | -0.04 ± 0.04 | -0.04 ± 0.05 | **+0.10 ± 0.07** | 0.00 |
| 160, PEAQ | **-0.27 ± 0.13** | **+0.10 ± 0.05** | **+0.06 ± 0.05** | +0.15 ± 0.18 | +0.04 ± 0.03 |
| 160, ViSQOL | -0.10 ± 0.21 | **-0.05 ± 0.04** | +0.02 ± 0.06 | **+0.11 ± 0.06** | 0.00 |
| 224, PEAQ | **-0.26 ± 0.07** | **+0.07 ± 0.06** | -0.10 ± 0.14 | **+0.06 ± 0.06** | +0.01 ± 0.02 |
| 224, ViSQOL | -0.04 ± 0.13 | **-0.13 ± 0.08** | +0.02 ± 0.05 | **+0.08 ± 0.05** | 0.00 |

Bold differences have an interval that excludes zero.

**FDK and libxaac lead on PEAQ.** At 80 to 112 kbit/s both lead by 0.15
to 0.18 points, and the lead is significant. It shrinks to 0.06 to 0.10
points at 160. ViSQOL puts ours level with or slightly ahead of them.
FDK is ahead on all ten items at 64, 96 and 128 kbit/s, so the gap is
systematic and not the work of one item. The largest gaps are on glock
and harpsichord, where FDK leads by 0.28 to 0.53 points at 96 and 128
kbit/s. Both items combine sharp attacks with sustained
tonal partials.

**The afterburner explains most of the gap.**

| kbit/s | FDK's afterburner gain | Our afterburner gain | FDK without AB vs ours |
|-------:|-----------------------:|---------------------:|-----------------------:|
| 64 | +0.25 | +0.06 ± 0.03 | -0.05 ± 0.06 |
| 96 | +0.41 | +0.11 ± 0.04 | -0.18 ± 0.09 |
| 128 | +0.29 | +0.06 ± 0.03 | -0.12 ± 0.10 |

All figures are PEAQ points at the nominal rate. Without its
afterburner, FDK's encoder is no better than ours. FDK's afterburner
works inside scalefactor estimation, on every band, before the rate
loop settles. It compares the real quantisation distortion with the
threshold, saves bits where the distortion allows, and smooths
scalefactor differences, and the bits it saves feed back into lower
thresholds. ours runs after the rate loop and only spends the slack the
loop leaves. The method is the scalefactor improvement step of 3GPP TS
26.403.

**Bandwidth is not the cause.** Our lowpass is 0.8 to 2.6 kHz wider than
FDK's at 64 to 128 kbit/s: 14.6 against 12.6 kHz at 64, and 17.6
against 15.0 kHz at 128. Restricting ours to FDK's lowpass
(`--bandwidth`) gained +0.11 ± 0.03 PEAQ at 64 kbit/s and nothing
measurable at 96 or 128.

**Against FFmpeg's encoder,** ours is level at 64 to 112 kbit/s on
PEAQ and ahead by 0.26 to 0.27 points from 160 up. ViSQOL puts ours clearly ahead at 64 to 80
kbit/s, where FFmpeg cuts the bandwidth to 12 kHz.

**Apple** scores best on ViSQOL from 96 kbit/s up. Its PEAQ results
vary widely by item: it is the best encoder on harpsichord at 128
kbit/s (-0.53 against our -1.62) and among the worst on glock (-1.28).
That is why its PEAQ intervals are wide.

### VBR

| Encoder, mode | kbit/s | PEAQ | ViSQOL |
|---------------|-------:|-----:|-------:|
| ours, mode 2 / 3 / 4 / 5 | 71.9 / 111.9 / 147.9 / 225.2 | -2.84 / -1.50 / -0.55 / -0.00 | 3.91 / 4.33 / 4.41 / 4.52 |
| FDK, mode 2 / 3 / 4 / 5 | 102.7 / 121.2 / 154.9 / 221.6 | -1.57 / -1.23 / -0.48 / -0.08 | 3.86 / 4.31 / 4.41 / 4.69 |
| Apple TVBR 45 / 63 / 82 / 100 | 106.3 / 136.0 / 165.0 / 219.7 | -1.33 / -0.41 / -0.06 / 0.04 | 4.28 / 4.45 / 4.56 / 4.71 |

At equal bitrate, Apple's true VBR leads our VBR by **+0.41 ± 0.34,
+0.44 ± 0.23 and +0.33 ± 0.12** PEAQ points at 112, 128 and 160 kbit/s.
On ViSQOL the lead is +0.03, +0.03 and +0.10. FDK's VBR leads ours by
0.19 ± 0.16 and 0.12 ± 0.11 PEAQ points at 112 and 128 kbit/s and is
level at 160. On PEAQ our VBR is
no better than our own CBR at the same bitrate: -1.50 against -1.45 at
112 kbit/s. On ViSQOL it is slightly better: 4.32 against 4.23.

## HE-AAC

| Encoder | v1 32 kbit/s PEAQ / ViSQOL | v1 48 | v1 64 | v2 24 | v2 32 |
|---------|---------------------------:|------:|------:|------:|------:|
| ours | -3.63 / 3.59 | -3.25 / 3.93 | -2.54 / 4.27 | -3.67 / 3.68 | -3.53 / 3.86 |
| FDK | -3.53 / 3.68 | -3.06 / 3.92 | -2.43 / 4.29 | -3.64 / 3.73 | -3.51 / 3.90 |
| libxaac | -3.49 / 3.64 | -3.11 / 3.92 | -2.45 / 4.31 | -3.56 / 3.71 | -3.45 / 3.87 |
| Apple | -3.56 / 3.65 | -2.68 / 4.06 | -2.47 / 4.31 | - | - |

FFmpeg's native encoder has no HE-AAC. Apple's Windows encoder has no
HE-AAC v2.

At equal bitrate, FDK leads HE-AAC v1 by +0.13 PEAQ points at both 40
and 56 kbit/s, with intervals of ±0.07 and ±0.13. On ViSQOL the
difference is +0.03 and -0.01, which is parity. Apple at 48 kbit/s has
the best mean on both metrics, +0.57 ± 0.39 PEAQ and +0.13 ± 0.23
ViSQOL, but it is also worst on some items. HE-AAC v2 is at parity with
FDK within 0.05 on both metrics. Ours, FDK and libxaac reach the same
audio bandwidth for v1: 15, 16.5 and 24 kHz at 32, 48 and 64 kbit/s.
Apple reaches 13.8, 22.0 and 24 kHz. PEAQ's validity for SBR is limited, so treat the HE-AAC
PEAQ differences as indications.

## xHE-AAC

| Encoder | 24 kbit/s nominal: achieved, PEAQ, ViSQOL | 32 | 48 | 64 | 96 |
|---------|-----------------------------------------:|---:|---:|---:|---:|
| ours | 25.7, -3.68, 3.39 | 34.4, -3.54, 3.68 | 51.8, -2.96, 3.95 | 69.3, -2.13, 4.27 | 104.0, -1.30, 4.33 |
| FDK | 27.7, -3.40, 4.02 | 36.3, -3.27, 4.06 | 54.1, -3.00, 4.00 | 71.6, -1.79, 4.33 | 96.4, -1.53, 4.17 |
| libxaac | - | - | - | 64.3, -2.77, 4.31 | 96.4, -1.43, 4.38 |

At equal bitrate, FDK leads at 32 kbit/s by **0.44 ± 0.23** ViSQOL
points and 0.24 ± 0.14 PEAQ points. At 24 kbit/s nominal FDK leads by
0.63 ± 0.32 ViSQOL points, on 2 kbit/s more. Our worst item there
scores 2.44 against FDK's 3.29. At 48 kbit/s FDK still leads by 0.12 ±
0.09 ViSQOL points. At 64 and 80 kbit/s the two are level within the
intervals, and libxaac is level as well.

**Our low-rate xHE-AAC decodes are louder in the low band.** At 24
kbit/s, eight of our ten decodes needed 0.8 to 3.2 dB of attenuation to
match the source below 3 kHz. FDK needed that on one item. At 32 kbit/s
castanets needed 2.8 dB, and HE-AAC at 32 kbit/s needed 3.0 dB on
castanets. The level matching removes this from the scores, but a
listener would hear it. The cause was not investigated. Plausible
sources are the noise-filling level and the quantiser's reconstruction
at coarse step sizes.

**Tools FDK uses at low rates that ours lacks.**

- Our encoder picks 2:1 SBR below 64 kbit/s per channel and otherwise no
  SBR. It never uses 4:1 or 8:3 SBR.
- MPEG Surround 2-1-2 is opt-in only, because FFmpeg's USAC decoder
  rejects it.
- Our encoder has no LPD core (ACELP or TCX), no complex prediction
  stereo, and no harmonic SBR, PVC or inter-TES.

FDK switches to 2:1 SBR with MPEG Surround at 24 to 32 kbit/s and to 8:3
SBR at 48 to 64. One visible result is audio bandwidth: ours reaches
12.7 kHz at 24 kbit/s against FDK's 18.0 kHz, and 15.0 against 18.0 kHz
at 32.

**Rate.** Our LOAS streams run 7 to 8 % above the nominal bitrate at
every rate, from the pre-roll and the StreamMuxConfig at each immediate
playout frame. FDK's run 12 to 15 % above nominal from 24 to 64 kbit/s,
and 0.4 % above at 96. libxaac writes a raw elementary stream without
framing.

## Speed and memory

Single-threaded, best of three runs, over the 130 s concatenation of
the corpus. The figure is audio seconds per CPU second (user plus
system).

| Encode | AAC-LC 128 | HE-AAC 48 | HE-AAC v2 32 | xHE-AAC 64 |
|--------|-----------:|----------:|-------------:|-----------:|
| ours | 18× | 28× | 65× | 20× |
| ours+AB | 12× | 19× | - | - |
| FFmpeg | 42× | - | - | - |
| FDK | 101× (233× without AB) | 89× | 148× | 34×, including its loudness pass |
| libxaac | 181× | 189× | 290× | 78× |
| Apple, under Wine | 94× | 101× | - | - |

| Decode | AAC-LC | HE-AAC | HE-AAC v2 | xHE-AAC |
|--------|-------:|-------:|----------:|--------:|
| ours | **724×** | 372× | **483×** | **197×** |
| FFmpeg | 251× | 296× | 296× | - |
| FDK | 395× | 326× | 237× | 142× |
| libxaac | 501× | 150× | 192× | 136× |
| Apple, under Wine | 465× | **420×** | 352× | - |

The decodes are of FDK's streams, or libxaac's where FDK has none. The
FFmpeg figures include its demuxer and WAV muxer.

**Memory, as peak resident set of the process.**

| Process | Peak RSS |
|---------|---------:|
| Our decoder | 7.8 to 9.6 MiB |
| FDK `aac-dec` | 3.0 to 3.5 MiB |
| libxaac decoder | about 42 MiB |
| FFmpeg decoder | about 52 MiB, mostly libavcodec |
| Our encoder, 1 s input | 5.6 to 6.2 MiB |
| FDK encoder, 1 s input | 3.2 MiB |
| libxaac encoder | about 59 MiB |

Our encoder process peaks at 69 MiB on the 130 s input, because `baac`
reads the whole input into memory. That is the tool, not the library.

**Code size**, as static library text:

| Library | Text | Covers |
|---------|-----:|--------|
| ours | 1.04 MB, plus 1.0 MB of zero-initialised tables | encoder and decoder, every profile |
| FDK `libfdk-aac` | 1.67 MB | |
| FDK `libfdk-xhe-aac` | 1.71 MB | the xHE-AAC encoder |
| libxaac | 4.04 MB | encoder and decoder |

## Bitstream compatibility

Every decoder decoded every one of our streams without an error. We
tested AAC-LC at 64, 128 and 256 kbit/s, HE-AAC at 32 and 64, HE-AAC v2
at 24 and 32, and xHE-AAC at 24, 64 and 96, ten items each. The
decoders were FFmpeg, FDK, libxaac and Apple's Core Audio, with FFmpeg
6.1.1 left out for xHE-AAC because it has no USAC decoder.

Agreement with our decoder, as SNR between the two decodes after
alignment:

- **FFmpeg and Apple on AAC-LC without noise substitution:** 135 dB,
  which is floating-point rounding.
- **FDK and libxaac on AAC-LC:** 30 to 68 dB. Both output 16-bit PCM,
  which caps agreement in the 60s. FDK's lowest figures, 30 and 36 dB on
  strauss and harpsichord, were not investigated. Its SNR against the
  source on those items is within 0.4 dB of ours.
- **AAC-LC at 64 kbit/s:** about 19 to 37 dB. Every decoder draws
  different noise for the substituted bands. FFmpeg's draw matches ours
  to 134 dB.
- **HE-AAC and HE-AAC v2:** 2 to 135 dB. The decoders differ in their
  SBR noise, their delay and their high-band phase. The SNR against the
  source is the same in every decoder to within 0.42 dB.
- **xHE-AAC:** 18 to 72 dB against FDK and libxaac. Noise filling and
  eSBR noise are random.

Our decoder decodes every other encoder's AAC-LC and HE-AAC stream. Its
output agrees with FFmpeg's to 97 to 136 dB. On FDK's xHE-AAC streams
it agrees with libxaac to a median of 65 dB at 64 kbit/s (19 dB on the
worst item) and 22 dB at 24 kbit/s, where eSBR noise is random. libxaac's raw xHE-AAC elementary streams were not tested,
because `baac dec` takes no AudioSpecificConfig and size list.

**The decoder's default loudness normalisation changes the level.** It
brings USAC streams to -24 LUFS from their MPEG-D loudness metadata, as
FDK's decoder does. On FDK's xHE-AAC streams that moved the level by
-12 to +5 dB. The comparisons above turn it off with the new
`baac dec --no-loudness`.

## Lossless

Over the 130 s corpus, single-threaded:

| Codec | kbit/s | Encode | Decode | Bit-exact |
|-------|-------:|-------:|-------:|-----------|
| ALAC, FFmpeg | 697 | 132× | 192× | yes |
| ALAC, Apple reference (refalac) | 690 | 181×, under Wine | 214× | yes |
| FLAC level 5 | 630 | 521× | 407× | yes |
| FLAC level 8 | 615 | 195× | 395× | yes |

Every lossless stream decodes with FFmpeg. On this corpus ALAC is 10 to
13 % larger than FLAC and decodes at about half FLAC's speed. AAC-LC at 256
kbit/s is 2.7 times smaller than ALAC and scores -0.04 PEAQ and 4.71
ViSQOL with our encoder. baander-aac has no lossless mode, and none is
proposed here. Both lossless codecs decode cheaply through FFmpeg,
which Baander already uses, so a lossless tier is a passthrough decision
for the server rather than codec work.

## Features

| | ours | FFmpeg `aac` | FDK | libxaac | Apple Core Audio |
|-|------|--------------|-----|---------|------------------|
| AAC-LC encode | yes | yes | yes | yes | yes |
| HE-AAC v1 / v2 encode | yes / yes | no / no | yes / yes | yes / yes | yes / not in the Windows encoder |
| xHE-AAC encode | yes; FD core, 2:1 eSBR, opt-in MPS 2-1-2 | no | yes; FD and LPD cores, 2:1, 4:1, 8:3 SBR, MPS 2-1-2 | yes; only 64 and 96 kbit/s, its parameter check resets other rates | not tested |
| AAC-LD / ELD encode | yes | no | yes | yes | not tested |
| Rate modes | CBR, ABR, CVBR, TVBR, VBR modes 1 to 5 | CBR, VBR | CBR, VBR modes 1 to 5 | CBR in its test application | CBR, ABR, CVBR, TVBR |
| Second quantisation pass | opt-in afterburner | two-loop search | afterburner, on by default | not checked | not documented |
| Decode LC / HE / v2 / xHE | yes | yes; xHE partial from FFmpeg 7.1 | yes | yes | yes / yes / yes / not tested |
| Decode ER tools, LD, ELD, MPEG Surround | yes | partial | yes | yes | not tested |
| MPEG-D DRC and loudness | encode and decode | no | encode and decode | encode and decode | not tested |
| Arithmetic | float, SSE2, AVX2 and NEON | float, and a fixed-point decoder | fixed point | fixed point | float, judging by its output |
| Licence | Apache-2.0 | LGPL | Fraunhofer licence: no patent grant, GPL-incompatible | Apache-2.0 | proprietary, Apple platforms and iTunes for Windows |

"Not tested" marks a capability this comparison neither ran nor
confirmed from the documentation of the version used. AAC patent
licensing applies to every implementation alike.

## Where we fall short, in priority order

1. **Quantisation: FDK-style scalefactor improvement inside the rate
   loop, on by default.**
   - *Evidence:* FDK's afterburner is worth 0.25 to 0.41 PEAQ points.
     FDK without it scores below ours. Our afterburner recovers only
     0.06 to 0.11.
   - *Approach:* measure the real quantisation distortion per band
     during scalefactor estimation. Raise scalefactors where the
     distortion stays under the threshold, reduce scalefactor
     differences, and recalibrate the bits-to-perceptual-entropy
     estimate so the rate loop spends what this saves (3GPP TS 26.403,
     scalefactor improvement). Keep the current pass as a final
     slack-spending step.
   - *Target:* close the 0.15 to 0.18 point gap at 80 to 128 kbit/s,
     then re-measure HE-AAC v1, whose AAC-LC core shares the
     quantiser.
2. **Encoder speed.**
   - *Evidence:* AAC-LC encodes at 18× realtime, against FDK's 101×
     with its afterburner and libxaac's 181×. HE-AAC encodes at 28×
     against 89×. Our afterburner costs a further 50 %. A default-on
     improvement pass from item 1 must not make this worse.
   - *Approach:* profile the encoder before changing it; no profile was
     taken for this comparison. Likely suspects, unverified, are the
     per-move re-sectioning and exact bit counting of the afterburner,
     the scalefactor search, and the psychoacoustic model's per-line
     work.
   - *Target:* at least 60× realtime for AAC-LC at 128 kbit/s with the
     improved quantiser on.
3. **xHE-AAC at 24 to 32 kbit/s.**
   - *Evidence:* FDK leads by 0.4 to 0.6 ViSQOL points, and our worst
     item is 0.85 points lower.
   - *Approach, in order:* select MPEG Surround 2-1-2 by bitrate for
     stereo at 32 kbit/s and below. It is opt-in today because of
     FFmpeg, but FDK, libxaac and Apple's xHE-AAC decoder are the
     players that matter for xHE-AAC. Add 4:1 eSBR for 16 to 24 kbit/s
     and 8:3 for 48 to 64. Widen the SBR range at low rates, since
     ours stops at 12.7 kHz against FDK's 18. Last, add the LPD core
     for speech-like content, which needs a speech corpus to measure.
4. **AAC-LC VBR.**
   - *Evidence:* Apple's true VBR leads by 0.33 to 0.44 PEAQ points at
     112 to 160 kbit/s. Our VBR modes score no better than our CBR at
     the same bitrate on PEAQ.
   - *Approach:* hold a constant perceptual quality across frames, so
     that easy frames spend less and hard frames more, rather than
     tracking the mode's bitrate target. Revisit after item 1, which
     changes the cost of every quality step.
5. **xHE-AAC rate accounting and low-rate level.** Our LOAS streams
   exceed the nominal bitrate by 7 to 8 %. Either subtract the pre-roll
   and StreamMuxConfig overhead from the budget, or document that the
   nominal rate is the payload rate. Separately, find out why the low
   band of our 24 and 32 kbit/s decodes comes out 0.8 to 3.2 dB louder
   than the source. Start with the noise-filling level.
6. **The decoder's loudness default.** Normalising USAC streams to
   -24 LUFS by default follows FDK, but a music player may prefer
   consistent album levels or its own ReplayGain. Decide the product
   default in the binding. The codec already exposes the switch.
7. **Test coverage of the comparison itself.** Add a speech item and
   44.1 kHz material, and run low-delay profiles and multichannel.
   Accept raw access units with an AudioSpecificConfig in `baac dec`,
   so that libxaac's xHE-AAC streams can be cross-decoded. Run a small
   MUSHRA listening test on the items where PEAQ and ViSQOL disagree,
   such as Apple at 64 kbit/s and glock at 96 to 128.

## Reproducing

See [bench/README.md](../bench/README.md). `bench/setup-refs.sh`
fetches and builds the reference codecs and GstPEAQ at the commits
above. ViSQOL needs its own build. `bench/bench.py` runs the stages
`quality`, `speed`, `compat`, `lossless` and `report`. A full run takes
about 40 minutes for the encodes and PEAQ, and 50 minutes of ViSQOL on
four cores.
