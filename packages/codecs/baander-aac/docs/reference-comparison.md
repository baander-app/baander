# Reference comparison

Measured on 2026-10-22 with FFmpeg 6.1.1 (native `aac` encoder and
decoder) on synthetic signals: `click44` (decaying noise bursts, stereo),
`music44` (pink noise plus a 330 Hz tone, stereo, the hardest case for
any AAC encoder), `song44` (harmonic chords, melody, kick and hi-hat,
stereo), `mono44` (pink noise plus tone, mono), `tone48` (two tones per
channel at 48 kHz). SNR is measured against the source after delay
alignment; it rewards accurate reconstruction, not perceived quality, so
treat the numbers as a sanity check of the allocation, not as a listening
test.

## Decoder

Our decoder against FFmpeg's decoder on FFmpeg-encoded streams (difference
SNR between the two decodes; 135 dB is floating-point rounding):

| Stream | Difference SNR |
|--------|---------------:|
| 44.1 kHz stereo 32, 128, 320 kbit/s | 135.7–136.4 dB |
| 44.1 kHz stereo, `-aac_coder fast` | 136.5 dB |
| 44.1 kHz stereo, transients (short windows, TNS) | 136.3 dB |
| 44.1 kHz mono 64 kbit/s | 135.8 dB |
| 8, 16, 22.05, 32, 96 kHz stereo | 135.4–136.0 dB |
| CRC-protected ADTS | 136.2 dB |

FFmpeg's streams use short windows with grouping, KBD and sine windows,
TNS, intensity stereo, perceptual noise substitution and escape codes;
all decode identically.

## Encoder

Our encoder (CBR unless noted) against FFmpeg's encoder at the same target
bitrate, each decoded by FFmpeg. The decode of our streams by FFmpeg
matches our own decoder to 135 dB in every case.

| Signal | Rate | Ours: kbit/s, SNR L/R | FFmpeg: kbit/s, SNR L/R |
|--------|-----:|----------------------:|------------------------:|
| click44 | 96 k | 63.8, 6.3 / 6.8 dB | 101.6, 3.8 / 4.2 dB |
| music44 | 32 k | 31.5, 8.3 / 6.2 dB | 34.2, 11.4 / 11.0 dB |
| music44 | 64 k | 63.8, 14.0 / 13.5 dB | 66.7, 13.6 / 13.2 dB |
| music44 | 128 k | 128.2, 19.0 / 17.8 dB | 130.3, 15.9 / 15.7 dB |
| music44 | 256 k | 256.3, 24.7 / 22.9 dB | 258.2, 25.1 / 24.4 dB |
| music44 | 512 k | 486.0, 30.8 / 29.0 dB | 373.3, 33.6 / 32.3 dB |
| song44 | 64 k | 63.9, 18.2 / 15.7 dB | 68.1, 15.0 / 13.2 dB |
| song44 | 128 k | 127.5, 19.9 / 17.4 dB | 143.9, 19.3 / 16.6 dB |
| song44 | 256 k | 197.8, 29.6 / 30.0 dB | 268.3, 28.1 / 24.4 dB |
| mono44 | 64 k | 64.1, 16.7 dB | 67.1, 14.0 dB |
| tone48 | 128 k | 104.1, 47.8 / 45.5 dB | 130.6, 22.4 / 24.0 dB |
| harm44 | 48 k | 47.8, 31.0 / 30.5 dB | 59.7, 13.3 / 12.6 dB |
| trans44 | 48 k | 25.5, 2.4 / 2.4 dB | 39.1, 1.8 / 1.8 dB |
| trans44 | 96 k | 44.8, 4.9 / 4.9 dB | 71.0, 5.9 / 5.8 dB |
| noise44 | 64 k | 63.6, 0.0 / 0.0 dB | 66.9, 0.3 / 0.4 dB |

## HE-AAC

Measured on 2026-10-23 against the Fraunhofer FDK encoder (`aacenc`,
`libfdk-aac` 2.0.3) at the same object type and bitrate, both streams
decoded by FFmpeg 6.1.1. `wide44`/`wide48` is the test suite's wideband
signal (harmonics of 220 Hz up to 15.4 kHz over a noise bed; right
channel at 0.7, offset in phase), `music44` a 220 Hz harmonic series with
vibrato under an enveloped 6 to 18 kHz noise band and clicks, `am44` two
low tones under 8 to 14 kHz noise modulated at 1.5 Hz, `gate44` the same
with the noise gated on and off. "Low band" is the SNR of the signal
below 3.5 kHz (the AAC-LC core); "9–12 kHz" the level of that band in the
decode relative to the source (inside the SBR range of every tuning).

| Signal | Profile, rate | Ours: kbit/s, low band L/R, 9–12 kHz L/R | FDK: kbit/s, low band L/R, 9–12 kHz L/R |
|--------|--------------|-------------------------------------------:|-----------------------------------------:|
| wide44 mono | HE v1 32 k | 32.9, 21.7 dB, −1.4 dB | 33.8, 22.6 dB, −1.1 dB |
| wide48 | HE v1 64 k | 65.9, 19.8 / 17.2 dB, −1.0 / −0.8 dB | 67.4, 22.0 / 20.2 dB, −1.1 / −0.8 dB |
| wide44 | HE v2 32 k | 32.5, 16.6 / 13.4 dB, −1.5 / −1.4 dB | 34.3, 17.8 / 12.9 dB, −1.2 / −0.9 dB |
| wide48 | HE v2 24 k | 24.3, 13.2 / 9.8 dB, −1.4 / −2.1 dB | 25.8, 14.3 / 8.0 dB, −1.1 / −1.8 dB |

On `music44` at HE v1 40 kbit/s mono the decoded band levels from 6 to
18 kHz sit between −0.8 and −2.4 dB of the source (FDK: −0.5 to −2.2 dB)
with a spread of 0.6 to 1.6 dB over time (FDK: 0.4 to 2.2 dB), the five
clicks land in the same 64-sample slot as the source, and the low band
reaches 28.0 dB (FDK: 23.6 dB). The modulated noise of `am44` comes back
within one QMF slot of the source in time at −3.3 dB (FDK: within one
slot, −3.6 dB), and the gated noise at −5.2 dB (FDK: −6.5 dB) with the
same ragged edges: in both encoders the limiter holds the gains down
where the patched core bands carry almost nothing, which these
synthetic signals provoke and music does not. With HE v2 on `music44`
(right channel a delayed, attenuated copy of the left) both encoders
reproduce the per-band level difference within 0.6 dB of each other and
the same inter-channel correlation per band.

Our HE-AAC streams decode identically in FFmpeg and in our decoder
(129 to 134 dB difference SNR); FDK-encoded HE-AAC v1 streams decode
within 100 to 134 dB of FFmpeg and HE-AAC v2 streams within 125 to
128 dB.

`harm44` is the test suite's harmonic series with the right channel at
0.7 of the left, `trans44` its decaying noise bursts (right at 0.8) and
`noise44` its white noise, all at 44.1 kHz stereo.

Tool usage on these streams (frames carrying the tool, of all frames):
noise substitution on 216 of 218 `music44` frames at 64 kbit/s and 130 of
132 `noise44` frames; intensity stereo on 220 of 347 `song44` frames at
64 kbit/s, 131 of 132 `harm44` frames and 55 of 132 `trans44` frames;
TNS on 331 of 347 `song44` frames (33 with short windows) and on 214 of
218 `click44` frames, 177 of them with two filters.

Notes:

- At 32 kbit/s stereo (16 kbit/s per channel) the minimum SNR per band
  collapses to −1 dB as in FDK, so the encoder spreads bits over more
  bands than FFmpeg does; FFmpeg's higher SNR there comes from coding
  fewer bands at 25 dB.
- On tonal and sparse material the encoder stops spending once every
  band sits 24 dB below its masking threshold, which is why `tone48` and
  `song44` at 256 kbit/s use less than the target. The reservoir then
  stays full; frames are not padded.
- Noise substitution lowers the SNR by design: the substituted bands are
  noise of the right level, not the original lines, so `noise44` and the
  bursts of `trans44` sit near 0 dB for both encoders.  Intensity stereo
  on `harm44` reproduces the panned harmonics almost exactly because the
  channels are copies at different levels.
- The transient signals use well under the target rate: between the
  bursts the bands fall below the PCM floor and are not coded, and the
  encoder does not pad.  FFmpeg spends its reservoir on the bursts.
- Encoding runs at roughly 15–60 times real time for stereo 44.1 kHz on
  one core, depending on the material.

## Transport and robustness

Measured on 2026-10-23 with FFmpeg 6.1.1 (difference SNR between the two
decoders; the 90 dB bound of the interop suite is far below every row).

| Stream | Difference SNR |
|--------|---------------:|
| FFmpeg `latm` mux of its own streams (stereo, mono, 5.1, 6.1 PCE), decoded by us | 135.6–137.2 dB |
| Our LOAS (LC stereo, LC 6.1 PCE, HE v1 and v2 with hierarchical and backward compatible signalling), decoded by FFmpeg | 131.4–135.9 dB |
| Our 960-sample LC frames over LOAS (transients, mono harmonics, noise, intensity stereo, 16 kHz TVBR, 5.1), decoded by FFmpeg | above 130 dB |
| Our 7.1 modes (configurations 7 and 12, PCE top front and rear surround, after reordering FFmpeg's channels), decoded by FFmpeg | 136 dB |
| Our MPEG-2 ADTS stream, decoded by FFmpeg | 135.8 dB |

`ffprobe` reports the SBR rate and channel count of the explicitly
signalled LOAS streams, `7.1` for configurations 7 and 12, `7.1(wide)`
for the top-front layout (FFmpeg ignores the height extension and swaps
the two front pairs) and `unknown` for the rear surround PCE, whose
channels it emits in element order. FFmpeg 6.1 rejects SBR with
960-sample frames, so HE-AAC at 960 is verified by roundtrip only
(delay 5379 samples, the same band bounds as at 1024).

Concealment on the committed streams and our own: with 1 and 10 percent
of the frames damaged (random bytes in the first payload bytes,
truncation to half, header only) every damaged frame that the decoder
can detect is concealed and counted, no intact frame fails, the output
length and frame size are unchanged, the largest sample jump around a
concealed frame stays within the bound of the clean decode, and an
8-frame loss fades at 3 dB per frame on LC and HE-AAC v1 and v2 streams.
One corruption in thirteen of a short-window LC stream parses as a valid
frame and is not detected. The 1024-frame encoder output is byte for byte
what it was before the 960-frame work on every profile and mode checked
(LC mono, stereo, 5.1, TVBR; HE v1 stereo and mono; HE v2).

## Low delay, dynamics and speed

Measured on 2026-10-24 with FFmpeg 6.1.1 on this machine (a 2.8 GHz
Xeon, four cores, GCC 13, release build at the AVX2 level).

| Stream | Difference SNR |
|--------|---------------:|
| Our AAC-LD 512 (48 kHz mono, 44.1 kHz stereo, 24 kHz mono, 48 kHz 5.1) in MP4, decoded by FFmpeg | 135.9–136.8 dB |
| Our AAC-ELD 512 (48 kHz stereo, 32 kHz mono, 48 kHz 5.1) and 480 (48 kHz mono), decoded by FFmpeg | 135.4–136.1 dB |
| A hand-built LD stream switching between the sine and low-overlap windows, decoded by FFmpeg | 136.0 dB |
| Our stream with DRC metadata, decoded by FFmpeg (which parses the data and does not apply it) against our decode with DRC off | 135.6 dB |

FFmpeg decodes AAC-LD 480 with a 512-line transform, so that case is
covered by roundtrips only; it has no AAC-ELD with LD-SBR decoder, and
our LD-SBR filterbank windows and frame grid are our own design where
the standard's tables were not available, so ELD with LD-SBR is
verified by roundtrip (low band 21 to 26 dB, the 10 to 16 kHz band
within 1.5 dB of the source, delays 1923/1827 samples in dual rate and
962/914 in single rate at 512/480) and is not known to interoperate.
Low-delay roundtrips at 64 kbit/s per channel reach 30 to 38 dB on
tones; the ELD downscaled playout agrees with the full-rate decode,
lowpassed and resampled, at 65 to 74 dB.

The DRC path applies the signalled band gain within 0.1 dB overall and
per frame on encoder-written metadata (34 quarter-dB steps of cut on a
-9 dBFS tone with the film standard profile), halves it at 50 percent,
normalises the reference level to the target (+6.0 dB for 124 against
100) and applies DVB heavy compression from a hand-built data stream
element.

Afterburner, segmental SNR on a music-like stereo signal at 44.1 kHz,
constant bitrate:

| Bitrate | Off | On | Gain | Encode time |
|--------:|----:|---:|-----:|------------:|
| 32 kbit/s | 18.6 dB | 19.3 dB | +0.7 dB | +52 % |
| 48 kbit/s | 18.1 dB | 18.9 dB | +0.8 dB | +87 % |
| 64 kbit/s | 25.3 dB | 25.9 dB | +0.6 dB | |
| 96 kbit/s | 26.9 dB | 27.9 dB | +1.0 dB | +109 % |

`baac bench` on a 60 s stereo signal at 48 kHz, realtime factors before
and after the performance pass (decode times are a few tenths of a
second and vary by about 20 percent between runs):

| Configuration | Encode before | Encode after | Decode before | Decode after |
|---------------|--------------:|-------------:|--------------:|-------------:|
| LC 128 kbit/s | 7.5x | 13.1x | 281x | 509–541x |
| HE-AAC v1 64 kbit/s | 15.5x | 22.4x | 116x | 191–214x |
| HE-AAC v2 32 kbit/s | 28.1x | 36.8x | 127x | 232–247x |
| LC 960 128 kbit/s | 7.3x | 12.0x | 144x | 160–187x |
| LD 512 64 kbit/s | 9.2x | 13.7x | 344x | 515–634x |
| ELD 512 64 kbit/s | 8.7x | 12.6x | 298x | 317–454x |
| LC 48 kbit/s with afterburner | 5.6x | 11.1x | 381x | 472–685x |

Every encoder output is byte for byte what it was before the pass at the
scalar, SSE2 and AVX2 levels; the LC, LD, ELD and afterburner decodes
are bit-exact and the HE-AAC decodes differ by at most 2.4e-7 through
the FFT-form QMF banks.

## USAC (xHE-AAC)

Measured on 2026-10-29 against the Fraunhofer FDK decoder (the `aac-dec`
program of the AOSP fdk-aac, run as a black box with loudness
normalisation off) on the streams the FDK xHE-AAC encoder produced from
the suite's signals (`tests/data/usac_*.loas`, 48 kHz output). Difference
SNR of our decode against FDK's over the full band and the whole stream,
at delay 0 in every case; the FDK decodes are 16-bit, which bounds the
measurable SNR of a quiet band near 50 dB. FFmpeg plays no part here: its
USAC decoder (master at the time of writing) decodes the 2:1 and 8:3
streams wrongly.

| Stream | Core, SBR ratio, stereo tool | Difference SNR |
|--------|------------------------------|---------------:|
| `usac_fd_cpe_96k` (stereo 96 kbit/s) | FD, no SBR | 83.9 dB |
| `usac_music_48000_96000` (stereo 96 kbit/s) | FD, no SBR | 84.1 dB |
| `usac_fd_sce_64k` (mono 64 kbit/s) | FD, no SBR | 81.3 dB |
| `usac_speech_48000_64000` (mono 64 kbit/s) | FD, no SBR | 81.7 dB |
| `usac_music_48000_48000` (stereo 48 kbit/s) | FD, 2:1, MPS 212 with residual (stereoConfigIndex 3) | 80.5 dB |
| `usac_music_48000_64000` (stereo 64 kbit/s) | FD, 2:1, MPS 212 with residual (stereoConfigIndex 3) | 80.2 dB |
| `usac_sbr_sce_music_48k` (mono) | FD, 2:1 with pre-flattening | 80.1 dB (50 dB above 7 kHz) |
| `usac_lpd_speech_12000` (mono 12 kbit/s) | LPD, 4:1 | 61.0 dB |
| `usac_speech_48000_16000` (mono 16 kbit/s) | LPD, 4:1 | 61.3 dB |
| `usac_lpd_speech_24000` (mono 24 kbit/s) | LPD, 2:1 | 52.1 dB |
| `usac_speech_48000_24000` (mono 24 kbit/s) | LPD, 2:1 | 52.1 dB |
| `usac_music_48000_24000` (stereo 24 kbit/s) | LPD, 8:3 (768-line core), MPS 212 (stereoConfigIndex 1) | 23.9 dB |
| `usac_music_48000_16000` (stereo 16 kbit/s) | LPD, 4:1, MPS 212 (stereoConfigIndex 1) | 25.9 dB |

On the two LPD music streams libxaac itself reaches 19.8 and 27.4 dB
against the FDK decode, and the two references disagree by 38 to 50 dB in
the SBR bands: the limit there is the two implementations' LPD core on
music, not our stereo tools. Below the SBR crossover our LPD core matches
libxaac's at 61 to 73 dB on speech and 33 to 35 dB on music; the
remaining gap is rounding in the LSF to LPC conversion, which runs in
double so that the optimised and plain builds agree above 110 dB. On the
residual-coded 2:1 streams the MPS 212 upmix, fed the reference downmix,
matches libxaac's above 119 dB.

Parsing is bit-exact with libxaac on every stream: every frame of the
core, the SBR and MPS 212 ends at the same bit position, and the MPS 212
parameters match its trace. The MPEG-D DRC loudness normalisation gain
matches the FDK decoder on every stream and the gain curves agree within
0.1 dB. The harmonic transposer, predictive vector coding and inter-TES
of the enhanced SBR, and stereoConfigIndex 2, are exercised by
constructed data only: the FDK encoder emits none of them, so their
interoperability is unverified.
