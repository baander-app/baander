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

### Encoder tuning

Measured on 2026-10-30 on three excerpts at 48 kHz: `music` (6 s
stereo), `speech` (6 s mono) and `clicks` (6 s stereo of the test
suite's decaying noise bursts every 180 ms). Every stream is decoded by
FFmpeg 6.1.1; the delay is found by cross-correlation, and the
segmental SNR is the mean over 1024-sample segments, each clamped to
-10..50 dB. The FDK figures are its encoder (`aac-enc -t 2|5|29
-v 1..5`) on the same input.

VBR modes (`aac_encoder_set_vbr_mode`), kbit/s and segmental SNR per
mode 1 to 5 (stereo targets 32, 72, 112, 148, 228 kbit/s; mono half
that):

| Excerpt, profile | Ours | FDK |
|------------------|------|-----|
| music, AAC-LC | 31.4 (14.3), 72.0 (25.6), 109.6 (30.1), 137.6 (32.2), 189.0 (34.3) | 87.6 (35.9), 85.3 (35.5), 92.2 (35.4), 110.6 (37.3), 148.9 (39.6) |
| music, HE-AAC | 31.6 (19.7), 71.1 (27.9), 106.6 (30.2), 132.4 (31.6), 176.3 (33.6) | 57.0 (32.2), 73.3 (32.0), 71.0 (31.8), 81.5 (32.5), 95.4 (33.1) |
| music, HE-AAC v2 | 31.7, 72.1, 98.8, 103.6, 103.6 (3.8 dB each) | 37.9, 39.9, 42.5, 48.1, 55.0 (3.4 dB each) |
| speech, AAC-LC | 14.8 (6.8), 31.5 (15.8), 48.3 (18.6), 62.9 (20.9), 94.9 (22.8) | 53.8 (21.8), 52.3 (21.4), 60.0 (22.0), 74.3 (24.1), 110.1 (27.0) |
| speech, HE-AAC | 15.3 (4.5), 32.5 (6.0), 49.3 (6.2), 64.2 (6.3), 94.8 (6.3) | 31.7 (6.3), 33.1 (6.3), 37.6 (6.3), 46.1 (6.4), 57.2 (6.5) |
| clicks, AAC-LC | 16.3 (1.1), 33.5 (2.0), 53.0 (5.1), 67.8 (5.4), 94.6 (5.7) | 36.2 (2.9), 36.0 (2.9), 43.1 (4.1), 48.9 (4.7), 61.5 (6.4) |
| clicks, HE-AAC | 19.6, 41.4, 64.3, 75.1, 83.7 (-1.5 to -1.1 dB) | 27.4, 37.3, 38.0, 48.9, 51.4 (-1.8 to -1.6 dB) |
| clicks, HE-AAC v2 | 21.1, 45.0, 54.5, 54.5, 54.5 (-1.5 to -1.0 dB) | 27.8, 31.1, 35.7, 37.1, 38.7 (-2.1 to -1.9 dB) |

On the music excerpt our AAC-LC modes land 1.9 % below, on, 2.1 %
below, 7.0 % below and 17.1 % below their targets; the test suite's
wideband signal lands within 2.2 % of all five (31.3, 72.1, 113.0,
149.7, 230.8 kbit/s). Mode 5 cannot reach 228 kbit/s on this excerpt:
the encoder stops spending once every band sits far enough below its
masking threshold, and CBR at 228 kbit/s itself gives 196.4 kbit/s;
qualities past the mode's 130 reach 193.5 kbit/s at 160 and 200.0 at
200 while the segmental SNR stops rising (34.0 and 33.0 dB against
34.3). Speech, being mono,
lands 7 to 17 % under its halved targets, and the sparse clicks well
under. The FDK encoder treats the modes as quality points of each
profile rather than bitrates: its AAC-LC mode 1 is 88 kbit/s on music,
and its modes span 88 to 149 kbit/s there against our 31 to 189.
HE-AAC v2 waveform SNR stays at 3 to 4 dB in both encoders, since the
parametric stereo rebuilds the pair from a downmix. A peak bitrate of
120 kbit/s turns mode 5 into the stream of mode 3 byte for byte.

Peak bitrate (`aac_encoder_set_peak_bitrate`), largest frame against
the cap on two seconds of clicks and of noise (ADTS frames; raw access
units for xHE-AAC without the pre-roll and for AAC-LD):

| Profile, mode, bitrate / peak | Clicks: kbit/s, largest | Noise: kbit/s, largest | Cap |
|-------------------------------|------------------------:|-----------------------:|----:|
| AAC-LC CBR 64 / 80 | 13.6, 212 B | 64.3, 212 B | 213 B |
| AAC-LC ABR 64 / 96 | 14.3, 244 B | 65.0, 245 B | 256 B |
| AAC-LC CVBR 64 / 72 | 13.4, 191 B | 71.9, 191 B | 192 B |
| AAC-LC TVBR 64 / 96 | 16.6, 255 B | 96.7, 256 B | 256 B |
| HE-AAC CBR 48 / 64 | 18.1, 340 B | 50.3, 340 B | 341 B |
| HE-AAC TVBR 48 / 80 | 22.5, 425 B | 83.2, 426 B | 426 B |
| HE-AAC v2 TVBR 32 / 48 | 17.4, 256 B | 50.0, 256 B | 256 B |
| xHE-AAC CBR 64 / 80 | 22.7, 418 B | 65.2, 418 B | 426 B |
| xHE-AAC TVBR 64 / 96 | 27.2, 504 B | 98.8, 505 B | 512 B |
| AAC-LD TVBR 64 / 96 | 12.8, 119 B | 88.6, 120 B | 128 B |

Without the cap the AAC-LC TVBR noise stream at quality 100 runs at
454 kbit/s with frames of up to 1222 bytes.

Bandwidth (`aac_encoder_set_bandwidth`) on one second of stereo noise at
128 kbit/s CBR, the highest 375 Hz band of the decode within 50 dB of
the loudest: the table's 15.5 kHz gives 16.1 kHz, an override of 6, 11
and 16 kHz gives 6.4, 11.6 and 16.9 kHz (the band holding the cutoff
is coded whole). With HE-AAC the override leaves the stream unchanged.

Low bit reservoir, AAC-LC stereo CBR, segmental SNR per 1024-sample
frame as mean / 10th percentile / minimum. Without a peak bitrate the
reservoir is never short on the clicks: it fills during the silence
between bursts, and the perceptual entropy's spending curve holds a
burst frame to 1.4 times the average frame (1.5 for short windows), so
the streams use 17.5 and 25.3 kbit/s at 32 and 48 kbit/s with 1.07 /
0.63 / 0.32 dB and 2.08 / 1.50 / 0.97 dB. Letting a frame spend up to
the whole reservoir once it is over 90 % full gave 1.24 / 0.89 /
0.47 dB at 21.0 kbit/s and 2.19 / 1.51 / 0.93 dB at 29.2 kbit/s and
left the music excerpt and a tones-with-bursts signal unchanged. A
peak bitrate of 1.25 times the bitrate makes the reservoir small (166
bits at 32 kbit/s, under a third of a frame): the curve then gives the
second frame of a burst only 0.9 of the average, because the first one
emptied the reservoir, and the clicks fall to 0.77 / -0.06 / -0.27 dB
at 15.6 kbit/s and 1.70 / 0.70 / 0.32 dB at 21.7 kbit/s, while the
music excerpt rises (14.59 to 15.06 dB mean at 32 kbit/s) from the
evener spending. Two changes were measured in that state: scaling the
curve's deviation from the average by the reservoir's size against a
frame (0.75 / 0.10 / -0.60 dB and 1.76 / 1.11 / 0.21 dB on the clicks,
but the music down to 14.61 and 19.71 dB and the tones-with-bursts
down 0.5 to 0.8 dB) and a floor of the average frame for every frame
(0.78 / -0.02 / -0.33 dB and 1.78 / 0.99 / -0.60 dB, the music down
0.2 dB). Neither improves all three signals, and the gains on the
clicks are under 0.2 dB on the mean, so the rate loop is unchanged.

Correctness checks: a 5.1 HE-AAC frame is SCE FIL CPE FIL CPE FIL LFE
END, each SBR payload in the fill element right after its own element.
At 192 kbit/s (201 kbit/s achieved) the six channels decode at 21.9,
22.0, 22.3, 8.5, 22.0 and 22.0 dB against their own inputs in ours,
FFmpeg 6.1.1 and FFmpeg master (the LFE keeps only its lowest lines, so
the 200 Hz partial of its test signal is gone), 21.7 to 22.1 dB in the
FDK decoder and 21.9 to 22.3 dB in libxaac, with no channel swapped. An
HE-AAC v2 stream whose last block holds 700 of 2048 samples returns
them at 32.7 dB in ours and in FFmpeg, and a one-sample HE-AAC v2 input
gives one frame and three of drain that both decode without error.

The tuned streams in the four decoders (SNR against the input):

| Stream | kbit/s | Ours | FFmpeg 6.1.1 / master | FDK | libxaac |
|--------|-------:|-----:|----------------------:|----:|--------:|
| music, AAC-LC mode 3 | 109.6 | 27.12 | 27.12 / 27.12 | 27.12 | 27.12 |
| music, HE-AAC mode 2 | 71.1 | 24.89 | 24.89 / 24.89 | 24.97 | 24.90 |
| music, HE-AAC v2 mode 1 | 31.7 | 2.25 | 2.25 / 2.25 | 2.24 | 2.25 |
| music, xHE-AAC mode 2 (LOAS) | 79.7 | 23.65 | - / 23.65 | 23.65 | 23.65 |
| clicks, xHE-AAC TVBR 90, peak 96 (LOAS) | 55.4 | -0.39 | - / -0.39 | -0.08 | -0.45 |
| clicks, AAC-LC TVBR 100, peak 96 | 37.9 | 7.44 | 7.44 / 7.44 | 7.44 | 7.44 |
| music, AAC-LC 128 kbit/s, 11 kHz | 127.4 | 27.44 | 27.44 / 27.44 | 27.44 | 27.44 |
| speech, AAC-LC mode 2 (mono) | 31.5 | 11.21 | 11.21 / 11.21 | 11.19 | 11.20 |
| 5.1, HE-AAC 192 kbit/s | 201.1 | 15.40 | 15.40 / 15.40 | 15.37 | 15.40 |

FFmpeg master agrees with our decode to 131.6 to 136.1 dB on the AAC
streams and to 27 to 39 dB on the xHE-AAC ones (its noise filling and
SBR noise draw other random values). The xHE-AAC mode 2 stream is
79.7 kbit/s in LOAS with the pre-roll and 70.8 kbit/s as raw access
units without it.

One finding left as it is: in TVBR the rate loop still spends a full
reservoir's surplus on frames that fit, so the bitrate of a true VBR
stream grows with the nominal bitrate (quality 30 on the music excerpt
gives 117.3 kbit/s at 64 or 128 kbit/s nominal and 215.2 kbit/s at
256 kbit/s). The VBR modes do not spend the surplus.

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

### xHE-AAC encoder

Measured on 2026-10-30 on the two committed streams of `test_usac_enc`
(`tests/data/usac_enc_sce_64k.loas`, mono at 64 kbit/s, and
`usac_enc_cpe_96k.loas`, stereo at 96 kbit/s: one second of the HE-AAC
suite's tones, noise and clicks at half level, 48 frames each, noise
filling and the pre-roll on) and on two three-second mixes of tones and
lowpassed noise (mono at 64 kbit/s, stereo at 96 kbit/s, noise filling
on and off). Four decoders: FFmpeg master (through MP4, since its LATM
path refuses the USAC configuration), the FDK decoder (16-bit output,
loudness normalisation off), libxaac (16-bit) and ours. Every stream
decodes in all four without an error; the FDK decoder also starts
cleanly at the second independent frame of the three-second mono mix
from its pre-roll (27.1 dB against the source from the second frame on,
the same as ours).

| Stream | Decoder | SNR against the source | Against our decode |
|--------|---------|-----------------------:|-------------------:|
| `usac_enc_sce_64k` | ours / FFmpeg / FDK / libxaac | 22.7 / 22.7 / 22.7 / 22.7 dB | - / 36.8 / 82.1 / 81.1 dB |
| `usac_enc_cpe_96k` | ours / FFmpeg / FDK / libxaac | 20.6 / 20.6 / 20.6 / 20.6 dB | - / 33.2 / 82.1 / 80.9 dB |
| mono mix 64 kbit/s | ours / FFmpeg / FDK / libxaac | 27.2 dB | - / 37.2 / 86.6 / 86.1 dB |
| stereo mix 96 kbit/s | ours / FFmpeg / FDK / libxaac | 26.7 dB | - / 35.6 / 86.5 / 86.1 dB |
| mono mix 64 kbit/s, no noise filling | ours / FFmpeg | 27.5 dB | - / 136.6 dB |
| stereo mix 96 kbit/s, no noise filling | ours / FFmpeg | 27.3 dB | - / 136.2 dB |

FDK and libxaac agree with our decoder to the limit of their 16-bit
output. FFmpeg agrees to the same limit without noise filling and to
33 to 37 dB with it: its noise filling draws other random signs, which
changes the waveform of the filled lines and nothing of the SNR
against the source. The same signals through AAC-LC at the same
bitrates decode to within 0.5 dB of these figures on the mixes (27.1
and 27.0 dB) and 1 to 2 dB above them on the clicks, where noise
filling and the frames the pre-roll shares the budget with cost a
little waveform accuracy. Raw access units of the one-second stereo
clicks signal come to 44.6, 60.3, 92.9 and 125.6 kbit/s at 48, 64, 96
and 128 kbit/s; LOAS adds about 5 kbit/s for the StreamMuxConfig every
frame.

#### Enhanced SBR and MPEG Surround 2-1-2

Measured on 2026-10-30 on the three committed streams of
`test_usac_enc_sbr` (the same one-second clicks signal at half level,
25 frames of 2048 samples each; the two-channel ones from the stereo
form of the signal, whose channels differ) and, for the bitrate
ladders, on a six-second stereo music excerpt at 48 kHz and its mono
mix. The same four decoders; the FDK decoder and libxaac decode every
stream without an error, FFmpeg the SBR streams, and the FDK decoder
starts cleanly at the second and third immediate playout frame of the
music streams from their two pre-roll frames (30 to 73 dB against the
continuous decode in the first frame, the same as our decoder). Against
the source the 2-1-2 stream is measured per channel and on the sum of
the channels, which the single core channel carries.

| Stream | Tools | Against the source | FDK / libxaac / FFmpeg against our decode |
|--------|-------|-------------------:|------------------------------------------:|
| `usac_enc_sbr_sce_32k` (mono 32 kbit/s) | 2:1 SBR | 17.5 dB | 58.0 / 80.5 / 26.0 dB |
| `usac_enc_sbr_cpe_64k` (stereo 64 kbit/s) | 2:1 SBR | 19.4 dB | 74.1 / 80.3 / 26.7 dB |
| `usac_enc_mps_cpe_32k` (stereo 32 kbit/s) | 2:1 SBR, 2-1-2 | 12.6 / 12.4 dB L/R, 20.5 dB on the sum | 77.3 / 79.4 / rejected |

FFmpeg's USAC decoder reads two `core_mode` bits for a
`stereoConfigIndex` 1 pair, whose second core channel is absent, and
has no 2-1-2 upmix (it copies the downmix to both channels), so it
rejects the 2-1-2 streams of every encoder; our decoder, the FDK decoder
and libxaac read one bit, and the FDK encoder's own 2-1-2 streams decode
in those three alike.

The ladders, with the configuration each bitrate takes, the bitrate of
the raw access units, the SNR against the source after the encoder's
delay (for the 2-1-2 points on the sum of the channels, since the
excerpt's channels are offset by a fraction of a millisecond, which
level and coherence parameters cannot carry) and the highest frequency
whose long-term spectrum stays within 50 dB of the peak. The FDK
xHE-AAC encoder's figures on the same excerpt follow for reference;
below 48 kbit/s it codes the pair at the 8:3 ratio with the 2-1-2's
phase coding, which keeps the channel offset and costs the sum.

| Stereo | Ours: tools (SBR start, stop index) | kbit/s | SNR | Bandwidth | FDK: tools | SNR | Bandwidth |
|-------:|-------------------------------------|-------:|----:|----------:|------------|----:|----------:|
| 24 | 2:1 SBR (default) | 28.2 (LOAS) | 13.0 dB | 9.8 kHz | 8:3 SBR, 2-1-2 with phase | 12.5 dB | 8.4 kHz |
| 24 | 2:1 SBR (7, 8), 2-1-2 (opted in) | 24.0 | 16.7 dB (sum) | 11.4 kHz | | | |
| 32 | 2:1 SBR (default) | 37.0 (LOAS) | 17.8 dB | 11.3 kHz | 8:3 SBR, 2-1-2 with phase | 14.9 dB | 8.6 kHz |
| 32 | 2:1 SBR (10, 9), 2-1-2 (opted in) | 32.3 | 17.2 dB (sum) | 13.2 kHz | | | |
| 48 | 2:1 SBR (12, 9) | 49.4 | 23.2 dB | 15.6 kHz | 2:1 SBR, 2-1-2 with residual | 24.6 dB | 16.8 kHz |
| 64 | 2:1 SBR (14, 12) | 66.1 | 24.4 dB | 21.6 kHz | 2:1 SBR, 2-1-2 with residual | 25.9 dB | 19.9 kHz |
| 96 | 2:1 SBR (14, 12) | 99.6 | 25.5 dB | 21.2 kHz | plain core | 30.1 dB | 13.9 kHz |
| 128 | plain core | 126.6 | 27.5 dB | 13.9 kHz | plain core | 31.3 dB | 13.9 kHz |

| Mono | Ours: tools (SBR start, stop index) | kbit/s | SNR | Bandwidth | FDK: tools | SNR | Bandwidth |
|-----:|-------------------------------------|-------:|----:|----------:|------------|----:|----------:|
| 16 | 2:1 SBR (3, 5) | 15.6 | 22.3 dB | 8.5 kHz | 4:1 SBR | 14.0 dB | 13.5 kHz |
| 24 | 2:1 SBR (7, 8) | 24.2 | 25.4 dB | 11.4 kHz | 2:1 SBR | 16.3 dB | 13.9 kHz |
| 32 | 2:1 SBR (10, 9) | 32.6 | 26.4 dB | 13.5 kHz | 2:1 SBR | 16.4 dB | 13.1 kHz |
| 48 | 2:1 SBR (13, 11) | 49.0 | 26.9 dB | 17.3 kHz | 2:1 SBR | 28.7 dB | 17.2 kHz |
| 64 | plain core | 62.4 | 28.7 dB | 13.9 kHz | plain core | 31.9 dB | 13.9 kHz |

Every one of these streams decodes in the FDK decoder and libxaac at
63 to 84 dB against our decoder (libxaac at 46.7 dB on the stereo
48 kbit/s stream, both at 44 to 49 dB on the default stereo 24 and
32 kbit/s points), and in FFmpeg at 31 to 50 dB except the two 2-1-2
points it rejects. The 2-1-2 is therefore never chosen by bitrate; the
default stereo points below 48 kbit/s are SBR pairs, whose LOAS bitrate
is given (the per-frame StreamMuxConfig adds about four kbit/s to the
access units), and a caller who knows the players opts into the 2-1-2
for 1.5 to 2 kHz more bandwidth at the same rate. At 96 kbit/s stereo our rule keeps SBR, which codes
the full band where the FDK encoder's plain core stops at 14 kHz; the
SNR on music is lower there because the replicated band carries no
waveform. The mono SBR points sit 8 to 10 dB above the FDK encoder's at
16 to 32 kbit/s on this excerpt, where its lower crossover leaves more
of the band to replication; the 2-1-2 points carry the sum of the
channels 3 dB better than the FDK encoder's and the channels themselves
worse, for want of phase coding.

## MPEG Surround

Measured on 2026-10-30 against the Ittiam libxaac decoder (AOSP
`external/libxaac`, run as a black box) on the streams the test tool
`mps-enc` wrote from a synthetic 5.1 programme with the codec's own
AAC-LC encoder (`tests/data/mps_*_q.loas`, 48 kHz, at -12 dB because
the loud versions saturated the references). No free encoder writes
MPEG Surround 5.1, and the FDK decoder binary pads the AAC-LC core to
six channels without running its surround decoder, so libxaac is the
only oracle for the 5.1 trees; its parameter mapping equals ours bit for
bit on every frame. Difference SNR of our decode against libxaac's over
the first second, per channel, at delay 0 (both decoders carry the same
1521 samples: 560 of input alignment, 384 of hybrid delay and 577 of
QMF synthesis); the libxaac decodes are 16-bit.

| Stream | Tree, downmix, form | L | R | C | LFE | Ls | Rs |
|--------|---------------------|--:|--:|--:|----:|---:|---:|
| `mps_5151_header_q` | 5151, mono, in-band header | 69.6 dB | 62.0 dB | 62.0 dB | 72.4 dB | 67.2 dB | 63.8 dB |
| `mps_5152_header_q` | 5152, mono, in-band header | 69.6 dB | 68.4 dB | 69.0 dB | 72.3 dB | 65.0 dB | 63.7 dB |
| `mps_525_header_q` | 525, stereo, in-band header, TTT prediction mode 1 | 69.4 dB | 68.9 dB | 68.1 dB | 73.1 dB | 64.9 dB | 63.3 dB |
| `mps_525_energy_q` | 525, stereo, in-band header, TTT energy mode 5 on a broadband programme | 66.3 dB | 54.4 dB | 46.6 dB | 52.4 dB | 65.5 dB | 46.8 dB |

AAC-ELD v2 (`mps_212_eld`, an ELD core of 512 samples with the low-delay
2-1-2 payload in the ELD extension, stereo out of a mono core) decodes at
77.0 / 76.6 dB L/R against libxaac at delay 0 and at 76.6 dB against the
FDK decoder, which does decode ELD v2, at the 256-sample delay of the
low-delay filterbank pair.

The object type 30 form (`mps_525_asc_q`, the configuration in a second
LATM layer) is checked against the in-band form of the same content only,
at 40 dB or better per channel. TTT prediction mode 0 and the
centre-subtraction modes 2 and 3 are verified by construction against
the standard's equations, since libxaac's own output breaks on them; in
the energy modes a band whose two level differences exceed about 48 dB
cannot be matched against libxaac's fixed-point weights, which is why
the energy stream keeps every band populated. SBR input (HE-AAC plus
surround) is wired but exercised by no stream; LD-SBR with the spatial
payload, temporal shaping in the low-delay path and ELD frame lengths
other than 512 are not covered; the 7.1 trees, the HRTF and binaural
modes and the 3D audio mode are refused.
