---
title: AAC codec xHE-AAC encoder: USAC streams in compatible mode, loudness metadata, client builds
date: 2026-10-30
status: delivered
execution: code
---

# AAC codec xHE-AAC encoder

## Purpose and scope

Third phase of the fourth-generation scope (2026-10-23-004): an encoder
that writes xHE-AAC (USAC, ISO/IEC 23003-3) streams in compatible mode,
meaning every stream it writes decodes in the stock FFmpeg decoder, the
FDK decoder binary, libxaac and our own decoder, and carries the loudness
metadata that xHE-AAC playback expects. Non-standard improvements belong
to the optimized pair of a later plan and are out of scope here. The
phase also closes the client-build gap: per-platform builds of the native
addon for the Electron client.

In scope:

1. USAC frequency-domain core encoder on the existing AAC-LC encoder
   (`encoder.cpp`: block switching, the masking model, TNS, M/S, the
   rate loop): the USAC window sequences and the stop-start window, the
   scale factor and arithmetic coding of the spectrum (the context
   model, the cumulative frequency tables and the escape coding of the
   decoder's `usac_arith_tables.cpp` reused for the encoder), noise
   filling (the noise level and offset per frame, the empty bands above
   the noise filling start), the stereo tools of `fd_cpe` as the decoder
   reads them (per-band M/S; complex prediction is written only when a
   round trip shows a gain, otherwise off), `UsacCoreConfig` with
   `tw_mdct` 0 and `noiseFilling` on or off, 1024-line frames, the
   `usacIndependencyFlag` at the stream access points, the arithmetic
   coder reset there, and the channel elements SCE, CPE and LFE in the
   layouts the AAC-LC encoder supports today.
2. Configuration and transport: `UsacConfig` in the `AudioSpecificConfig`
   of object type 42 (the sampling frequency, `coreSbrFrameLengthIndex`,
   the channel configuration, the element list with its configurations),
   the configuration extensions for the stream id
   (`ID_CONFIG_EXT_STREAM_ID`) and the loudness info set
   (`ID_CONFIG_EXT_LOUDNESS_INFO`), LOAS/LATM, raw access units with the
   configuration for MP4 (`mp4a` with the USAC ASC), the audio pre-roll
   extension element at every stream access point with the configured
   interval (the preceding access units needed to warm the decoder, as
   the decoder's pre-roll handling consumes them), the fill extension
   element and the `usacExtElement` default lengths.
3. Loudness measurement: ITU-R BS.1770-4 (K-weighting, the 400 ms blocks
   with 75 % overlap, absolute and relative gating) for the integrated
   loudness, EBU Tech 3342 for the loudness range, and the true peak
   (4x oversampled), measured in one pass over the input ahead of the
   encoder (the encoder delays its first output until the measurement of
   the first blocks exists) and in a two-pass form through a measurement
   handle whose result the caller feeds to the encoder; written as the
   `loudnessInfo` of the stream (sample peak, true peak, program loudness,
   anchor loudness when the caller gives one, loudness range) per
   ISO/IEC 23003-4. The live loudness leveler and DRC gain set generation
   of the FDK encoder are out of scope; the stream carries no
   `uniDrcGain` elements, so a player normalises by the program loudness.
4. Enhanced SBR encoder for the 2:1 ratio on the existing SBR encoder
   (`sbr_enc.cpp`): the USAC SBR syntax (`SbrConfig` with the default
   header in the configuration, `UsacSbrData` without the data-extra
   bits, the independent-frame envelope coding), `coreSbrFrameLengthIndex`
   3 (1024-line core at half rate, 2048 output samples per frame), the
   harmonic transposer, PVC and inter-TES off; the 8:3 and 4:1 ratios
   with the 768-line core later, when the decoder's LPD-era streams give
   them a reference.
5. MPS 212 encoder for stereo at low bitrates, opted into by the caller
   (delivered as opt-in rather than by bitrate: FFmpeg's USAC decoder
   rejects every stereoConfigIndex 1 stream, so the compatible-mode gate
   forbids choosing it by default): the OTT parameter
   extraction of the test tool (`tools/mps-enc.cpp`: CLD and ICC per
   band from the hybrid-domain analysis) written as `Mps212Data` with
   stereoConfigIndex 1 (no residual, no phase coding), the downmix fed to
   the SCE core with SBR; chosen by bitrate as the FDK encoder does
   (stereo below 48 kbit/s), with a caller override.
6. Encoder API and tools: `aac_encoder_create` accepts `AAC_AOT_USAC`;
   `aac_encoder_set_usac` for the options (noise filling, SBR on or off
   or auto by bitrate, MPS 212 on or off or auto, the pre-roll interval
   in frames, the stream id), `aac_encoder_set_loudness_info` for the
   two-pass values and `aac_loudness_*` for the measurement handle;
   `baac enc` gains the `xhe` profile and a `--loudness` two-pass option;
   the Node binding and the Electron service expose the profile and the
   loudness measurement.
7. Verification in compatible mode: every committed stream and the
   round-trip tests decode in FFmpeg master (the scratchpad build), the
   FDK decoder binary, libxaac and our decoder, with SNR against the
   input and bitrate bounds recorded in the reference comparison, and
   the loudness measurement checked against FFmpeg's `ebur128` filter.
   (Delivered: FFmpeg master's LATM demuxer refuses USAC, so its gate runs
   on the MP4 form of every stream.)
8. Client builds: a GitHub Actions workflow that builds the native addon
   for Electron on Linux x64, Windows x64 and macOS x64/arm64, runs the
   smoke test, and publishes the prebuilt binaries as release assets;
   the package resolves a prebuilt binary for the running platform
   before falling back to a local build, and `electron-builder` bundles
   the matching binary. The workflow is written and checked for syntax
   here; it runs only once pushed.

Out of scope: the LPD core encoder (ACELP, TCX, the speech/music
switching decision; the encoder stays in the frequency-domain core,
which is what music content takes), the live loudness leveler, DRC gain
set generation, the 8:3 and 4:1 SBR ratios, residual and phase coding in
MPS 212, the optimized (non-standard) pair and its negotiation, and
surround input to the USAC encoder.

Written from ISO/IEC 23003-3 and 23003-4, ITU-R BS.1770-4 and EBU Tech
3341/3342; the encoder-side tuning (bandwidths, the noise filling
thresholds, the SBR and MPS parameter choices) is ours, measured against
the oracles. No FDK source is read; the FDK encoder binary in the
scratchpad is a behavioural reference for stream layout and bitrates.

## Oracles

- Decoders: FFmpeg master (the scratchpad build, the compatible-mode
  gate), the FDK `aac-dec` binary, libxaac `xaacdec`, and our decoder
  (bit-exact parsing against libxaac already established).
- Loudness: FFmpeg's `ebur128` filter (integrated, range, true peak) on
  the same input; the FDK encoder's two-pass loudness files for its
  streams.
- Bitrates and layout: the FDK `xhe-aac-enc` streams of the decoder
  phase (element order, extension elements, pre-roll intervals).

## Design sketch

- `src/usac_enc.cpp` (the USAC frame and element writer over the
  existing encoder state), `src/usac_arith_enc.cpp` (the arithmetic
  encoder with the decoder's context model), `src/usac_enc_config.cpp`
  (`UsacConfig` and extensions), `src/loudness.cpp` (BS.1770 and the
  loudness info writer), `src/sbr_enc_usac.cpp` (the USAC SBR syntax on
  the SBR encoder), `src/mps212_enc.cpp` (the OTT extraction and the
  `Mps212Data` writer). The encoder state gains a USAC block with the
  pre-roll ring of the last access units.
- The arithmetic encoder mirrors the decoder: the same context
  computation, the same tables, the encoder's interval arithmetic; a
  round trip through `aac_usac_arith_*` in the suite guards it.
- The pre-roll extension copies the previous access units' bytes, so the
  encoder keeps the last N written access units per stream.
- Client builds: `.github/workflows/aac-native.yml`, a `prebuilds/`
  layout keyed by platform-arch-abi, `node/index.js` trying the prebuilt
  path first, `electron-builder.yml` including `prebuilds/`.

## Work units

1. The USAC configuration and frame writer with a mono and stereo FD
   core on the AAC-LC tools, the arithmetic encoder, noise filling, the
   independent frames and the pre-roll, LOAS and MP4 out; round trips
   through our decoder, then FFmpeg, FDK and libxaac.
2. Loudness measurement and the loudness info set, one and two pass;
   checked against `ebur128`.
3. The USAC SBR syntax on the SBR encoder at 2:1 and the MPS 212 encoder;
   bitrate ladders; the four decoders on every stream.
4. API, `baac`, binding, Electron service; docs, reference comparison,
   roadmap.
5. The client build workflow and the prebuilt binary resolution.

## Verification

CTest suites per unit; for every committed stream the decode by the four
decoders with SNR against the input and the bitrate; the loudness figures
against `ebur128`; the sanitizer build; the binding smoke test. Not
possible here: listening tests, running the GitHub workflow, the ISO
conformance suite.
