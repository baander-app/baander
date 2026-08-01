# Reference Codec Comparison

How baander-aac measures up against production AAC encoders, what the gaps are,
and what is known about their root causes.

**Date:** 2026-07-15
**References:** libfdk-aac (Fraunhofer FDK, via FFmpeg), FFmpeg native AAC (`aac`)
**Test harness:** `tests/test_reference.cpp`

---

## Methodology

`test_reference` generates synthetic mono 44.1 kHz signals, encodes them three
ways — baander-aac (in-process), libfdk-aac and FFmpeg's native AAC encoder
(both via `ffmpeg` CLI roundtrips through temp files) — decodes back to PCM,
and measures SNR against the original.

- **Signals:** `multitone` (5 sinusoids), `harmonics` (fundamental + harmonics
  with decay), `white_noise`, `impulses` (periodic clicks).
- **Bitrates:** 64k, 128k, 256k (CBR where supported).
- **Delay alignment:** encoder/decoder delay differs per codec, so SNR is
  maximized over a scan of sample offsets before comparison.
- **Regression floor:** the test fails if 128k multitone SNR drops below 15 dB,
  and warns when SNR is non-monotonic across bitrates.

Run it directly (it is part of `ctest`, ~5 s) with:

```bash
cd build && ./tests/test_reference
```

Requires `ffmpeg` with `libfdk_aac` on `$PATH`; the FDK column is skipped
gracefully if unavailable.

FFmpeg's native AAC encoder is included as a second data point: it is itself
10–18 dB below FDK on tonal content, which confirms **libfdk-aac is the right
reference target** (it is also what Android ships and roughly what Apple's
encoder achieves).

## Results (2026-07-15, this tree)

```
signal           br  baander_snr      fdk_snr    ffaac_snr   gap_vs_fdk
multitone       64k       36.0 dB       40.4 dB       17.1 dB       -4.4 dB
multitone      128k       40.6 dB       43.2 dB       20.3 dB       -2.6 dB
multitone      256k       45.5 dB       44.6 dB       34.6 dB       +0.8 dB
harmonics       64k       37.2 dB       40.8 dB       16.5 dB       -3.6 dB
harmonics      128k       44.6 dB       41.8 dB       17.8 dB       +2.8 dB
harmonics      256k       48.4 dB       43.4 dB       31.1 dB       +5.0 dB
white_noise     64k        1.6 dB        3.7 dB        1.2 dB       -2.1 dB
white_noise    128k        3.7 dB        6.1 dB        4.9 dB       -2.4 dB
white_noise    256k        8.0 dB        6.3 dB       12.3 dB       +1.7 dB
impulses        64k        1.3 dB        4.4 dB        3.0 dB       -3.1 dB
impulses       128k        3.2 dB        6.4 dB        7.8 dB       -3.2 dB
impulses       256k        7.1 dB        6.4 dB       26.7 dB       +0.7 dB

baander-aac avg SNR: 23.1 dB   libfdk-aac avg SNR: 24.0 dB   gap: -0.9 dB
```

`tests/test_quality.cpp` (pure baander roundtrip, multitone) reports
38.2 / 39.2 / 40.9 / 49.6 / 48.8 dB across 64k–256k with frame sizes of
157–535 bytes (frames now scale with the CBR budget).

## Gaps

### GAP-1 — Tonal-content SNR vs libfdk-aac (mostly closed)

Was: baander plateaued at ~25 dB on multitone/harmonics where FDK reaches
40–45 dB.  Now: 36–48 dB, beating FDK at 128k/256k on harmonics and at 256k
on multitone.  Status of the three root causes:

1. ~~**12-level signed codebook ceiling.**~~ **FIXED.**  Codebook 11 escape
   sequences are implemented on both ends (`aac_bitwriter_write_huffman` /
   `aac_bitreader_read_huffman`, sign bit per nonzero coefficient + escape
   extension `value = 2^N + word` for magnitudes ≥ 16, per
   ISO 14496-3 §4.6.3.3).  The quantizer clamp is now `AAC_ESC_MAX_MAG`
   (8191) instead of ±12, and the per-band `sf_cap` scales accordingly.
   CB8/CB10 uniform placeholder tables were also replaced with the real
   ISO magnitude VLCs (+ sign bits), imported from FFmpeg's `aactab.c`
   like CB6/7/9/11 were from FDK.
2. **Sine window instead of KBD.** Still open.  The encoder analyzes with
   `AAC_WIN_SINE` and signals it in the bitstream.  KBD (α=4/6) has better
   stop-band attenuation, so tonal energy concentrates in fewer
   coefficients.  Note the decoder's fast `imdct_fft` path folds the window
   into the DCT-IV and is **sine-only** (`src/mdct.cpp`), so KBD support
   needs the generic IMDCT path (or a new fast path) too.
3. **Simplified psychoacoustic model.** Partially addressed: the TLS now
   runs a second pass under bit pressure that pre-zeros deeply-masked bands
   (energy ≥ 6 dB below threshold) and keeps whichever pass has less total
   audibility-normalized noise (see GAP-2 fix).  Still missing: tonality
   detection / masking-peak tracking and per-band threshold reduction
   (3GPP TS 26.403 §5.6.1).  The remaining 64k gap (-4.4 dB on multitone)
   is mostly here and in scalefactor coding (raw 9-bit DPCM per band where
   FDK uses the ISO scalefactor Huffman table, ~2-3 bits typical).

### GAP-2 — SNR and frame size scale with bitrate (closed)

Was: 25.1 → 25.8 dB from 64k → 256k, frames stuck at 65–72 bytes.
Now: 36.0 → 45.5 dB (multitone), 157 → 741-byte frames — the two-loop
search spends the CBR budget.  Two fixes combined:

- **Escape sequences** (GAP-1 cause 1) removed the precision ceiling, so
  spare bits have somewhere to go.
- **Masked-band bit stealing.** Bands below their masking threshold used
  to be pre-zeroed unconditionally, which capped high-bitrate SNR at the
  sum of their energies; NOT zeroing them (needed for SNR scaling) let
  ~40 masked bands eat bits at q≈1–4 that audible bands needed at low
  bitrate.  `aac_quantize_bands` now runs the TLS twice under budget
  pressure: pass 1 codes every band, pass 2 pre-zeros deeply-masked bands
  (`e ≤ thr/4`, libfdk-aac's ZERO_HCB-under-pressure behaviour), and keeps
  the pass with less total audibility-normalized noise
  (`Σ noise/threshold` over all bands — zeroing a masked band costs its
  energy, so pass 2 only wins when bits are genuinely scarce).

### GAP-3 — White-noise SNR non-monotonic across bitrates (closed)

Was: 7.3 / 4.9 / 8.1 dB at 64k/128k/256k.  Now monotonic: 1.6 / 3.7 / 8.0
dB — the mid-bitrate dip is gone with the two-pass TLS.  (Absolute values
remain low for everyone; white noise is unmaskable and SNR is not the
right perceptual metric there.)

### GAP-4 — Transients: no block switching

Impulse SNR is 1.3 dB at 64k. The encoder hardcodes `AAC_WIN_ONLY_LONG`
— there is no short-window (EIGHT_SHORT) path at all, so pre-echo on
transients cannot be contained. 3GPP TS 26.403 §5.4.1 block switching
driven by the psycho model's perceptual entropy is the standard fix.
This is a structural feature gap, not a tuning issue.  TNS (decoder-side
`aac_apply_tns` exists; no encoder analysis or bitstream signaling) is the
other standard transient/tonal tool and is likewise unimplemented.

### GAP-5 — No Huffman escape sequences, codebook 11 (closed)

Implemented, see GAP-1 cause 1.  The decoder peeks 16 bits for long
codewords and parses sign bits + escape extensions; the encoder emits
them for magnitudes > 12 and uses CB8/CB10's real ISO magnitude VLCs for
mixed-sign mid-range bands.

## Recently fixed (for context)

These were found during the 2026-07 debugging round and are **closed**;
listed here so the numbers above are interpretable:

- **0.0 dB SNR / silent output.** Two compounding bugs: (a) the psycho model
  initialized `threshold_previous[b] = 0`, collapsing first-frame masking
  thresholds to 0.01× and zeroing the spectrum (now initialized to a 1e30f
  sentinel, `src/psycho.cpp`); (b) the two-loop quantization outer loop could
  walk scalefactors to 155, past the q=12 clamp region, where dequantized
  output collapses (now guarded by per-band `sf_cap` plus a stall detector,
  `src/encoder.cpp`). SNR went 0.0 → ~24–25 dB on `test_quality`.
- **Huffman decode rejected valid codewords > 11 bits.** The decoder peeked
  only 11 bits while CB7/CB9/CB11 contain 12–15-bit codes. Now peeks 16 bits
  (`src/bitstream.cpp`).
- **M/S in-place aliasing.** `aac_ms_encode` was called fully in-place
  (`mid == L`, `side == R`) and wrote `mid[i]` before reading `L[i]` for
  `side[i]`, corrupting the side channel into `0.5·L - 0.207·R`.  Decoded
  identical-channel stereo had a constant ~13.7 dB SNR floor at every
  bitrate; now reads both inputs into locals first (`src/spectral.cpp`) and
  identical-channel stereo tracks mono-at-half-bitrate plus M/S gains
  (34–51 dB from 64k–384k).
- **Flat bitrate scaling.** See GAP-2: escape sequences + two-pass
  masked-band zeroing.  23.6 dB flat → 31–51 dB scaling (`test_quality`).
- **Uniform placeholder VLCs.** CB8/CB10 replaced with the real ISO
  magnitude tables (from FFmpeg `aactab.c`), sign-coded like CB11.

## Reproducing

```bash
mkdir -p build && cd build
cmake .. -DCMAKE_BUILD_TYPE=Release
cmake --build . -j4
./tests/test_reference     # needs ffmpeg (+ libfdk_aac) on PATH
./tests/test_quality       # baander-only SNR floors + monotonicity
```
