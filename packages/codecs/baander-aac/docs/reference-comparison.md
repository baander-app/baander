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
