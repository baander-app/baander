---
title: AAC codec MPEG Surround decoder: 5.1 from a downmix and 212 in AAC-ELD
date: 2026-10-29
status: draft
execution: code
---

# AAC codec MPEG Surround decoder

## Purpose and scope

Second phase of the fourth-generation scope (2026-10-23-004): decoding
MPEG Surround (ISO/IEC 23003-1) as it travels with AAC-LC, HE-AAC and
AAC-ELD streams, so that a 5.1 programme coded as a mono or stereo downmix plus
spatial parameters plays as six channels, and an ELD v2 stream (ELD plus
the 2-1-2 tool) plays as stereo. The USAC decoder already carries the 2-1-2
tool as `Mps212Data`; this phase generalises its parameter path to the
5.1 trees and adds the transport that AAC uses for the spatial payload.

In scope:

1. Transport and configuration: the `SpatialSpecificConfig` in the
   `AudioSpecificConfig` of object type 30 (MPEG Surround with the core
   object type, `sacPayloadEmbedding`) in LATM/LOAS and MP4, the payload
   as an extension payload of type `EXT_SAC_DATA` (12) in a fill element
   with the in-band `bsSacHeader`, the LD variant in AAC-ELD (`ldmps`
   configuration in the ELD-specific config, payload in the ELD extension
   element), the `bsFrameLength`, `bsFreqRes`, `bsTreeConfig` (5151,
   5152, 525 and 212), `bsQuantMode`, `bsOneIcc`, `bsArbitraryDownmix`,
   `bsFixedGainSur`, `bsFixedGainCenter`, `bsFixedGainLfe`,
   `bsFixedGainDmx`, `bsMatrixMode`, `bsTempShapeConfig`,
   `bsDecorrConfig`, `bs3DaudioMode` (read, the HRTF modes refused), the
   OTT and TTT configuration (`bsOttBands`, `bsTttModeLow`,
   `bsTttModeHigh`, `bsTttBandsLow`), the residual configuration
   (`bsResidualCoding`, `bsResidualBands`, `bsResidualFramesPerSpatialFrame`),
   `bsEnvQuantMode`, the SAC extension configuration (`SacExtensionConfig`
   skipped), and the frame-level `bsIndependencyFlag` and
   `bsFramingInfo` with its parameter slots.
2. Parameter syntax: `SpatialFrame` with `OttData`, `TttData`, `SmgData`,
   `TempShapeData`, `ArbitraryDownmixData` and `ResidualData`; the
   `EcData` paths already implemented for 2-1-2 (PCM, differential and
   pair-wise Huffman coding, the LAV classes, the escape codes) extended
   with the CPC codebooks of the TTT box and the `bsCpcCldEscape` rule;
   the quantisation tables of CLD, ICC, CPC and the arbitrary downmix
   gains; the parameter mapping over the slots and the frequency
   resolution; the concealment of a lost spatial frame (parameters kept).
3. Trees and matrices: the OTT boxes of 5151 and 5152 (five OTT boxes
   in two orders from a mono downmix) and the TTT box with three OTT
   boxes of 525 (a stereo downmix, the arbitrary downmix gains applied
   before the TTT box); the mixing
   matrices M1 (pre-matrix: downmix, decorrelator inputs, residual mixing)
   and M2 (post-matrix: direct and diffuse parts to the output channels)
   per parameter band, the prediction and energy modes of the TTT box
   (`bsTttModeLow` 0 to 5, the CPC to prediction coefficients, the
   energy-based inverse), the ICC and CLD to the OTT mixing gains as 2-1-2
   does, the interpolation between parameter sets over the slots, the
   smoothing (`SmgData`), the fixed gains of the surround, centre, LFE and
   downmix channels, the `bsMatrixMode` 0 and 1 handling.
4. Signal path: the QMF analysis of the stereo downmix (64 bands, the
   hybrid filterbank of 71 bands shared with 2-1-2), the decorrelators of
   the baseline profile (the per-index lattice allpass and delay
   configurations of the standard's table, `bsDecorrConfig` 0 to 2, the
   energy-based ducking per band), the residual signals (AAC-LC
   `residual_data` channel elements decoded with the existing core decoder
   and converted from the MDCT to the QMF domain with the standard's
   MDCT-to-QMF filters, applied in the residual bands in place of the
   decorrelated signal), the temporal shaping tools (STP and GES as 2-1-2
   has them, per output channel), the QMF synthesis of the six channels
   and the LFE as a plain band-limited channel, the output in the codec's
   canonical 5.1 order (`L R C LFE Ls Rs`), the 7.1 layouts untouched.
5. The 2-1-2 tool outside USAC: ELD v2 (an ELD core with the low-delay
   MPS payload, `treeConfig` 212, the low-delay hybrid and decorrelator
   configurations of the LD mode) and 212 inside AAC-LC/HE as the
   standard allows, reusing `mps212.cpp` with its input timing taken from
   the core's QMF, the LD-SBR coupling of ELD included.
6. Test material: no free encoder produces MPEG Surround 5.1 streams, so
   a tool `tools/mps-enc.cpp` (test tooling, not a product feature)
   encodes 5.1 PCM: QMF analysis, the OTT and TTT parameters (CLD from
   band energies, ICC from the normalised cross-correlation, CPC in the
   prediction mode and the energy mode), the stereo downmix per tree with
   the fixed gains, the downmix coded with the codec's own AAC-LC or
   HE-AAC encoder, and the spatial payload written with PCM-coded or
   Huffman-coded parameters (`bsPcmCoding` first, Huffman where the
   decoder's tables allow a round trip) in the object type 30 and the
   extension payload forms. The streams are decoded by the FDK decoder
   binary and by libxaac as black boxes; agreement with them is the
   acceptance figure, the round trip against the 5.1 input the sanity
   figure.
7. API and binding: the stream channel count and layout of a surround
   stream (six channels, `channels` 0 emits them, the existing downmix to
   stereo applies when two are requested), `objectType` reporting the core
   type with the surround flag (`hasSurround`), the Electron service type
   unchanged beyond the new field; docs, reference comparison and the
   roadmap checkpoint.

Out of scope: the MPEG Surround encoder as a product feature (the 2-1-2
encoder for xHE-AAC belongs to the next phase; the test tool above is
scoped to producing valid streams), the 7.1 trees (7271, 7272, 7571,
7572), binaural and HRTF output modes, the 3D audio mode, the MPEG
Surround "blind" upmix of plain stereo, the enhanced matrix mode, the
SAC extensions, MPEG Surround over USAC (xHE-AAC carries 2-1-2 only).

Written from ISO/IEC 23003-1:2007 with its amendments and ISO/IEC 14496-3
for the transport; the normative tables not reconstructible from memory
(the CPC codebooks, the decorrelator filter coefficients per index, the
MDCT-to-QMF filters, the parameter quantisers) are transcribed from
libxaac (Apache-2.0, approved 2026-10-29) with the attribution header the
USAC tables carry, and verified entry by entry; libxaac is instrumented as
the bit-level oracle where our output and the FDK decode disagree. No FDK
source is read.

## Oracles

- The FDK `aac-dec` binary in the scratchpad (built with its MPEG
  Surround decoder) decodes AAC plus MPEG Surround to 5.1 as a black box.
- libxaac's `xaacdec` decodes HE-AAC plus MPEG Surround (its
  `heaac_mps_present` path) and can be instrumented to dump the
  intermediate matrices and QMF signals.
- The streams come from the test tool of unit 6; the FDK decoder rejecting
  a stream is the first check on the tool's bitstream writer.
- Committed test material: short streams under `tests/data/mps_*.loas`
  with the FDK decodes as 16-bit WAV, as the USAC suites do.

## Design sketch

- `src/mps_config.cpp`: the `SpatialSpecificConfig` parser into an
  `AacMpsConfig` block of `AacAsc` (object type 30 and the ELD variant)
  and from the in-band header; `src/mps_frame.cpp`: the `SpatialFrame`
  parser into the parameter sets, sharing the `EcData` reader and
  codebooks of `mps212.cpp` (moved to `mps_params.cpp` with the CPC
  additions); `src/mps_tree.cpp`: the tree definitions and the M1/M2
  matrix calculation; `src/mps_dec.cpp`: the signal path (hybrid,
  decorrelators, residual, mixing, temporal shaping, synthesis) over a
  state holding the two input channels and six outputs; `src/mps_resid.cpp`:
  the MDCT-to-QMF conversion of the residual channel elements.
- The decoder's channel element walker hands the surround payload
  (`EXT_SAC_DATA` or the ELD extension) to the MPS state after the core
  and SBR of the frame; the MPS state consumes the downmix as time
  samples when the core has no SBR (own QMF analysis) and as the SBR's
  QMF output otherwise, as `mps212.cpp` does for USAC, so that no
  synthesis and re-analysis sits in the path.
- Output channels: a surround stream reports six stream channels; the
  existing layout assignment and downmix handle the requested output.
- `mps212.cpp` keeps its USAC entry points; its OTT box, decorrelator,
  hybrid, smoothing and temporal shaping become the shared building blocks
  with explicit configuration arguments instead of the 2-1-2 constants.

## Work units

1. Transport and configuration (unit 1 of the scope) with the in-band and
   ASC forms, the ELD variant, tests on hand-written configurations and on
   the tool's streams once they exist.
2. The test encoder tool (unit 6): parameters, downmix, payload writer,
   LOAS output; the FDK and libxaac decodes of its streams committed as
   references; a round-trip check of the writer against our parser.
3. Parameter syntax and codebooks (unit 2), the tree matrices (unit 3),
   verified against libxaac's parameter dumps on the tool's streams.
4. The signal path (unit 4) to 5.1 output, verified against the FDK and
   libxaac decodes per channel and band; the residual path on streams the
   tool writes with residual coding.
5. 2-1-2 in ELD and AAC-LC/HE (unit 5), with ELD v2 streams from the tool.
6. API, binding, docs, reference comparison, roadmap (unit 7).

## Verification

CTest suites per unit; SNR per output channel against the FDK decode and
libxaac on every committed stream, recorded in the reference comparison;
the round-trip figure of the tool's streams against the 5.1 input; the
sanitizer build on every merge; the binding smoke test. Not possible here:
listening tests, the ISO conformance suite, streams from a third-party
MPEG Surround encoder.
