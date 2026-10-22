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
| click44 | 96 k | 65.3, 6.7 / 7.3 dB | 101.6, 3.8 / 4.2 dB |
| music44 | 32 k | 31.5, 8.3 / 6.2 dB | 34.2, 11.4 / 11.0 dB |
| music44 | 64 k | 63.8, 12.9 / 11.0 dB | 66.7, 13.6 / 13.2 dB |
| music44 | 128 k | 128.2, 19.0 / 17.8 dB | 130.3, 15.9 / 15.7 dB |
| music44 | 256 k | 256.3, 24.7 / 22.9 dB | 258.2, 25.1 / 24.4 dB |
| music44 | 512 k | 486.0, 30.8 / 29.0 dB | 373.3, 33.6 / 32.3 dB |
| song44 | 64 k | 63.9, 18.1 / 15.6 dB | 68.1, 15.0 / 13.2 dB |
| song44 | 128 k | 127.4, 19.9 / 17.4 dB | 143.9, 19.3 / 16.6 dB |
| song44 | 256 k | 203.8, 29.4 / 29.8 dB | 268.3, 28.1 / 24.4 dB |
| mono44 | 64 k | 64.1, 16.7 dB | 67.1, 14.0 dB |
| tone48 | 128 k | 95.0, 47.0 / 44.2 dB | 130.6, 22.4 / 24.0 dB |

Notes:

- At 32 kbit/s stereo (16 kbit/s per channel) the minimum SNR per band
  collapses to −1 dB as in FDK, so the encoder spreads bits over more
  bands than FFmpeg does; FFmpeg's higher SNR there comes from coding
  fewer bands at 25 dB.
- On tonal and sparse material the encoder stops spending once every
  band sits 24 dB below its masking threshold, which is why `tone48` and
  `song44` at 256 kbit/s use less than the target. The reservoir then
  stays full; frames are not padded.
- Encoding runs at roughly 15–60 times real time for stereo 44.1 kHz on
  one core, depending on the material.
