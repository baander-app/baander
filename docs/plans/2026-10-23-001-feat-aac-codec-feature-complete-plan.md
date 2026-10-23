---
title: AAC codec feature completion: multichannel, HE-AAC v1 and v2, both directions
date: 2026-10-23
status: in progress
execution: code
---

# AAC codec feature completion

## Purpose and scope

`packages/codecs/baander-aac` encodes and decodes AAC-LC in mono and stereo.
This plan makes it feature complete for the MPEG-4 AAC profiles a music player
meets: every standard channel configuration and program-config-driven layout,
HE-AAC v1 (SBR) and HE-AAC v2 (SBR with parametric stereo), in the decoder and
the encoder, with the defects found by the 2026-10-22 trace repaired. The Node
binding, the Electron service and the documentation follow the C API.

Out of scope: AAC Main prediction, LTP, SSR, ER tools, LATM/LOAS and MP4
containers (the binding takes ADTS; container demuxing stays with the host),
MPEG-D DRC, MPEG Surround. The decoder rejects those object types as it does
today. The server-side probing defects (`FormatDetector`, `MimeDetector`,
`.m4a` tags) are outside the codec package and get their own plan.

The `ce-*` skills are not installed in this checkout; this plan stands in for
`ce-plan` and receives a review pass before implementation. Work lands as local
commits on the thread branch; no push until the user asks.

## Design

### Public C API (`include/aac.h`)

- `AAC_MAX_CHANNELS` becomes 8. Channel order of interleaved PCM in and out is
  the canonical order used by WAVE and FFmpeg: L, R, C, LFE, Ls, Rs, then the
  outer front pair for 7.1 (channel configuration 7: L, R, C, LFE, Ls, Rs, Lo,
  Ro as FFmpeg orders `7.1(wide)`).
- Decoder: `aac_decoder_create(sample_rate, channels)` keeps `channels` as the
  output layout; `0` means the stream's own layout. 1 and 2 downmix anything
  with the ITU-R BS.775 coefficients (centre and surrounds at −3 dB, LFE
  dropped); 6 and 8 accept smaller layouts by zero-padding. New queries:
  `aac_decoder_stream_channels` (channels in the last frame),
  `aac_decoder_output_channels`, `aac_decoder_output_sample_rate` (twice the
  core rate while SBR is active), `aac_decoder_reset`. `aac_decoder_frame_size`
  returns the output samples per channel of the last frame (1024, or 2048 with
  SBR). `aac_decoder_decode` requires `pcm_size >= 2048 * output_channels`.
  `aac_decoder_get_sbr_ps` reports what the last frame carried, not a sticky
  flag.
- A frame whose sampling rate, channel layout or SBR presence differs from the
  previous one resets the filterbank, QMF and overlap state before decoding.
- Encoder: `aac_encoder_create(sample_rate, channels, bitrate, aot, rc)`
  accepts channels 1 to 8 (configurations 1 to 7; 8 channels is configuration
  7) and `aot` LC, SBR or PS (PS requires two input channels). `sample_rate` is
  the input rate; for SBR and PS the core runs at half of it and the ADTS header
  carries the core rate and channel count with implicit signalling, as FDK and
  every player expect. `aac_encoder_frame_size` is 1024 for LC and 2048 for SBR
  and PS; `aac_encoder_delay` reports the real delay of each mode.
  `aac_encoder_set_quality` re-derives every quality-dependent setting.
  `aac_encoder_flush` ends with a full state reset, so a reused encoder starts
  a clean stream.

### Decoder (`src/decoder.cpp`, new `src/channel_map.cpp`, `src/sbr_dec.cpp`, `src/ps_dec.cpp`, `src/qmf.cpp`)

- Elements are decoded in bitstream order into up to 8 channel slots plus up to
  2 coupling channels. The layout comes from the ADTS `channel_config`, or from
  the program config element when it is 0 (the first PCE of a stream fixes the
  element-to-channel assignment; a later PCE that differs resets). Multiple
  raw data blocks per ADTS frame decode in sequence.
- LFE elements decode as an SCE restricted to long windows. Coupling channel
  elements are decoded fully: dependent coupling is applied to the target
  spectra before TNS, independent coupling to the time signal after the
  filterbank, with the gain and sign semantics of ISO/IEC 14496-3 4.6.8.3.
- SBR: an `AacSbrState` per SCE or CPE element parses the `sbr_extension_data`
  of the following FIL element (extension types 13 and 14, with CRC skipped),
  builds the master and derived frequency tables, runs the 32-band QMF
  analysis of the core output, the LPC-based high-frequency generator with
  inverse filtering, the envelope and noise-floor adjustment with gain
  limiting and sinusoid insertion, and the 64-band synthesis. The output
  frame is 2048 samples per channel at twice the core rate. Frames without an
  SBR extension after an SBR stream was detected run the synthesis with the
  high band muted, so the sample rate stays stable. The design follows the
  standard text and the structure of FFmpeg's and FDK's decoders.
- PS: a stereo output from a mono core plus the `ps_data` inside the SBR
  extension (extension id 2): hybrid analysis of the lower QMF bands, IID,
  ICC, IPD and OPD parameter decoding with time and frequency deltas and the
  two Huffman table sets, the all-pass and delay decorrelator, parameter
  interpolation and the stereo mixing matrices for both ICC modes. Only the
  baseline PS tools FFmpeg decodes are required.
- The existing 640-tap QMF prototype in `tables.cpp` is reused by `qmf.cpp`,
  which provides analysis (32 and 64 bands) and synthesis (64 bands, and the
  32-band downsampled form used by the encoder's resampler).

### Encoder (`src/encoder.cpp`, new `src/sbr_enc.cpp`, `src/ps_enc.cpp`)

- Multichannel: the element list per configuration (SCE C, CPE L/R, CPE
  Ls/Rs, SCE Cs, CPE Lo/Ro, LFE) drives one psychoacoustic analysis per
  channel and one stereo decision per pair. The frame budget is split per
  element in proportion to its channel count, the LFE taking a fixed small
  share; the bit reservoir stays global. The LFE element codes only long
  windows up to the band below 120 Hz, without TNS, PNS or intensity.
- Fixes from the trace: the scalefactor delta clamp runs after the zero-band
  drop and iterates to stability; `encode_block` checks the output size before
  consuming the input; `set_quality` recomputes bandwidth, active bands,
  minimum SNR tables and tool thresholds; the 56-bit header reservation uses
  two writes; flush resets state.
- SBR encoder (3GPP TS 26.404 structure, as FDK): 64-band QMF analysis of the
  full-rate input, the 2:1 resampler feeding the core, frequency tables chosen
  by bitrate and rate, transient detection with the FIXFIX, FIXVAR, VARFIX and
  VARVAR frame classes, envelope energy estimation per band and segment,
  noise-floor estimation from tonality, inverse-filtering levels, missing
  harmonics detection for sinusoid flags, quantisation with the time and
  frequency delta decision, Huffman coding, and the SBR header schedule
  (every frame in ADTS, as FDK does for broadcast). Frame extension data is
  written as FIL elements with CRC absent.
- PS encoder: hybrid analysis, IID and ICC estimation per parameter band over
  the frame, quantisation to the fine or coarse grid, delta coding, and the
  mono downmix that feeds the core encoder. IPD and OPD are not sent.

### Node binding and Electron

- `AacDecoder` options gain `channels: 0` for native layout; results expose
  `streamChannels`, `outputChannels`, `sampleRate` (output rate) and `hasSbr`,
  `hasPs` per frame. `AacEncoder` options gain `channels` up to 8 and
  `profile: 'lc' | 'he' | 'hev2'`. The service and IPC validation follow, and
  `AacService.closeAll()` runs when the owning renderer goes away.

## Implementation phases and verification

Each phase lands as one or more local commits with the CTest suite green and
the FFmpeg interop test extended for the new feature. FFmpeg 6.1.1's native
decoder supports multichannel, SBR and PS, so every decoder feature is checked
against FFmpeg's decode of the same stream and every encoder feature by
FFmpeg's decode of our stream (interop) plus SNR against the source.

1. Decoder multichannel, CCE, PCE, multi-block ADTS, state reset, new
   queries. Checks: FFmpeg-encoded 5.1 and 7.1 streams decode identically;
   downmix to stereo matches FFmpeg's `-ac 2` within the coefficient
   difference; hand-built PCE and CCE streams in the compliance test.
2. Encoder multichannel and the fixes. Checks: 5.1 and 7.1 roundtrips at all
   rates; FFmpeg decodes our streams identically; quality changes bandwidth;
   a reused encoder's first frame equals a fresh encoder's; a clamp-order
   regression with a constructed spectrum; the tiny-buffer test asserts no
   frame is lost.
3. SBR decoder. Checks: HE-AAC v1 streams (from our encoder in phase 5 and
   any external vectors reachable) decode to within 60 dB of FFmpeg's
   decode after delay alignment; the compliance test covers both frame
   classes and the header reset path.
4. PS decoder. Checks: HE-AAC v2 streams decode to within 50 dB of FFmpeg's
   output; mono core without PS data still decodes as before.
5. SBR and PS encoder. Checks: FFmpeg decodes our HE-AAC v1 and v2 streams;
   SNR of the decoded output against the source is reported per bitrate;
   the stream's bitrate lands within 5 percent of the target in CBR; the
   core rate and ADTS signalling are what FFmpeg probes.
6. Binding, Electron service and IPC, tests, docs (`docs/README.md`,
   `reference-comparison.md`), `CONCEPTS.md` entries, `ROADMAP.md` record.

Phases 3 and 5 are developed together because our own encoder is the primary
source of SBR test streams; FFmpeg's decoder is the independent reference.
Listening tests are not possible in this environment; quality claims rest on
SNR and the FDK-derived design.
