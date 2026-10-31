---
title: AAC codec USAC decoder: xHE-AAC playback with MPEG-D DRC and loudness
date: 2026-10-24
status: draft
execution: code
---

# AAC codec USAC decoder

## Purpose and scope

First phase of the fourth-generation scope (2026-10-23-004): decoding
Unified Speech and Audio Coding (USAC, ISO/IEC 23003-3, AOT 42) streams as
the xHE-AAC profile constrains them, with the MPEG-D DRC and loudness
processing (ISO/IEC 23003-4) that xHE-AAC playback requires. The encoder
side, MPEG Surround beyond the 212 stereo tool, and the DRC/loudness
encoder follow in the later phases.

In scope:

1. Configuration and transport: `UsacConfig` inside the
   `AudioSpecificConfig` (sampling rate, core SBR frame length index,
   channel configuration, the element list with its `UsacCoreConfig`,
   `SbrConfig` and `Mps212Config`, the configuration extensions including
   `ID_CONFIG_EXT_FILL` and `ID_CONFIG_EXT_LOUDNESS_INFO`), USAC frames in
   LATM/LOAS, raw access units and MP4 (`mp4a` with `usac` in the ASC),
   `usacIndependencyFlag`, the audio pre-roll extension
   (`ID_EXT_ELE_AUDIOPREROLL`) with its embedded configuration and the
   stream access point handling (decode the pre-roll frames, output the
   current one), configuration changes at an IPF.
2. Frequency-domain core: `fd_channel_stream` with its `ics_info` (the
   USAC window sequences including `STOP_START_SEQUENCE`), the scale
   factor data, the spectral noiseless coding with the context based
   arithmetic coder (the state, the context mapping, the cumulative
   frequency tables of the standard, the escape handling, the arithmetic
   reset at independent frames), noise filling, TNS, the `fd_cpe` stereo
   tools (M/S per band, complex stereo prediction with the MDST estimate
   from the current and previous frame, `common_window`, `common_tw`), the
   USAC time warping flag read and refused (not in xHE-AAC), the window
   shapes and the frame lengths 1024 and 768.
3. Linear-prediction-domain core: `lpd_channel_stream` with the mode
   decision per subframe, ACELP (the adaptive and innovation codebooks,
   pitch and gains, the post filter is not applied, bass post filter per
   the standard), TCX (20, 40 and 80 ms with the arithmetic coded spectrum,
   the LPC-shaped noise filling and the gain), the LPC filter sets with the
   LSF quantiser (the first and second stage codebooks of the standard),
   the forward aliasing cancellation (`fac_data`) at every FD/LPD and
   ACELP/TCX boundary, the windowing and overlap across the mode switches.
4. Enhanced SBR as USAC carries it (`UsacSbrData`): the SBR header in the
   configuration, the harmonic transposer and the inter-TES tool, the PVC
   (predictive vector coding) envelope path, the low-power and high-quality
   paths as the existing SBR decoder has them, the `sbrRatioIndex` cases
   (4:1 with the 768 core, 2:1, 8:3), reuse of the existing QMF banks.
5. MPEG Surround 212 as `Mps212Data` inside a USAC CPE: the OTT box with
   CLD, ICC and (optional) IPD parameters, the decorrelator, the residual
   coding with `bsResidualCoding`, the temporal shaping tools (`bsTempShape`,
   `bsEnvQuantMode`), phase coding, the 2:1 and 4:1 QMF coupling with SBR;
   `bsPseudoLr`. This is the stereo tool xHE-AAC uses at low bitrates; the
   515 and 525 trees belong to the next phase.
6. MPEG-D DRC and loudness: `uniDrcConfig` and `loudnessInfoSet` from the
   configuration extension, `uniDrcGain` payloads from
   `ID_EXT_ELE_UNI_DRC` extension elements, the gain decoder (gain
   sequences with the spline interpolation, time-domain and QMF-domain
   application, the delay alignment), the selection process with its
   requests (target loudness, DRC effect type with the fallback order,
   loudness normalisation on and off, album mode, the peak limiter) and
   the default behaviour of a player (loudness normalisation to the
   requested target with `-24` LUFS as our default, DRC off unless
   requested), the `downmixId` handling for the configurations we
   output, the truncation and clipping prevention. Exposed through a
   small decoder API (`aac_decoder_set_loudness`,
   `aac_decoder_set_drc_effect`, readback of the measured loudness and the
   applied gain). The `uniDrc` syntax also appears in AAC-LC/HE streams as
   an extension payload; the parser is shared, the application in the
   legacy path comes when such a stream is at hand.
7. The decoder output path for USAC: the same `AacLayout` assignment from
   the channel configuration, the pre-roll frames discarded, the output
   delay reported, concealment for lost USAC frames (fade of the FD
   spectrum, repetition of the LPD excitation, the arithmetic coder reset
   on the next independent frame), the limiter and the existing DRC API
   refusing MPEG-4 DRC on USAC streams.
8. Binding and Electron: object type and the loudness/DRC options surfaced
   in the Node addon and the Electron service, the `AAC_AOT_USAC` constant.

Out of scope: the USAC encoder, the 515/525 MPEG Surround trees and the
standalone MPEG Surround decoder, the DRC and loudness encoder, the
time-warped MDCT (`tw_mdct`, outside xHE-AAC), USAC in ADTS (not allowed).

Written from ISO/IEC 23003-3:2020 (USAC), ISO/IEC 23003-4:2020 (MPEG-D
DRC), ISO/IEC 23003-1 (the OTT tool as USAC uses it), ISO/IEC 14496-3 for
the shared SBR and QMF syntax and the xHE-AAC profile constraints of
ISO/IEC 23003-3 Annex; no FDK code. The standard texts are not available
in this environment, so the tables of the arithmetic coder, the LSF
codebooks, the ACELP codebooks and the DRC characteristic curves are
written from the authors' knowledge of the standard and verified against
the oracles below; where a table cannot be reconstructed, the plan says
so in the roadmap checkpoint.

## Oracles

- FFmpeg 7.1.1 built from the GitHub mirror (tag `n7.1.1`) in the
  scratchpad, with its native USAC decoder: decodes FD and LPD frames,
  eSBR and MPS 212 at the quality FFmpeg reached in 7.1 (its LPD path is
  young), and applies no MPEG-D DRC.
- The FDK `xhe-aac-enc` and `aac-dec` programs built from the mirror in
  the scratchpad as black boxes: they produce xHE-AAC streams at every
  configuration we must decode (mono and stereo, 8 to 128 kbit/s, 1024
  and 768 cores, with and without MPS 212, with loudness and DRC gain
  sets) and decode them with MPEG-D DRC applied, which gives the
  reference for the DRC and loudness path. Binaries only; no source is
  consulted for the implementation.
- Reference streams committed under `tests/data/usac_*.loas` and
  `tests/data/usac_*.mp4` (short, synthetic signals), with the decoded
  output compared at run time against FFmpeg where it decodes and against
  committed PCM references otherwise.

## Design sketch

- A new `src/usac_*.cpp` family under the existing decoder state: the
  configuration (`usac_config.cpp`, extending `AacAsc` with a
  `UsacConfig` block), the frame parser (`usac_frame.cpp`), the arithmetic
  decoder (`usac_arith.cpp` with the context model and tables in
  `usac_arith_tables.cpp`), the FD channel decode (`usac_fd.cpp`, reusing
  the TNS, noise filling and MDCT of the existing decoder), the LPD path
  (`usac_lpd.cpp`, `usac_acelp.cpp`, `usac_tcx.cpp`, `usac_lpc.cpp` and
  `usac_fac.cpp`), the stereo tools (`usac_stereo.cpp` with the complex
  prediction and the MDST estimation filters), the eSBR extensions on the
  existing SBR decoder (`sbr_esbr.cpp`: harmonic transposer, PVC,
  inter-TES), MPS 212 (`mps212.cpp`), and MPEG-D DRC (`unidrc_parse.cpp`,
  `unidrc_gain.cpp`, `unidrc_select.cpp`).
- `aac_decode_raw_data_block` dispatches on `s->aot == AAC_AOT_USAC` to
  `aac_usac_decode_frame`, which walks the configured element list (the
  USAC frame has no element ids). The output stage, concealment, limiter
  and the layouts are shared.
- The pre-roll is decoded with a scratch copy of the decoder state so that
  the arithmetic coder, LPD memories and SBR history are warm when the
  current frame is decoded; the output of the pre-roll frames is dropped.
- MPEG-D DRC runs after the core and SBR (in the QMF domain when SBR is
  active, in the time domain otherwise) and before the limiter; the
  selection process runs once per configuration change and per API
  change.
- The API gains `AAC_AOT_USAC = 42`, `aac_decoder_set_loudness(ctx,
  enabled, target_lufs_x100)`, `aac_decoder_set_drc_effect(ctx, effect)`
  with an `AacDrcEffect` enum (NONE, NIGHT, NOISY, LIMITED, LOWLEVEL,
  DIALOG, GENERAL), `aac_decoder_loudness(ctx, &program_loudness_x100,
  &applied_gain_db_x100)`. The existing `aac_decoder_set_drc` keeps its
  MPEG-4 meaning and reports unsupported on USAC streams.

## Work units

1. UsacConfig parsing, element list, transport and MP4 plumbing, the frame
   walker with empty elements, pre-roll and independency handling, the
   `AAC_AOT_USAC` constant; tests with FDK-produced streams parsed and
   frame boundaries checked.
2. Arithmetic decoder and the FD channel stream with noise filling, TNS,
   the USAC window sequences and shapes; mono FD streams decode against
   FFmpeg.
3. FD stereo: M/S, complex prediction with the MDST estimate; stereo FD
   streams against FFmpeg.
4. LPD: LPC/LSF, ACELP, TCX, FAC and the mode transitions; speech streams
   at low bitrates against FFmpeg and the FDK decoder output.
5. eSBR: harmonic transposer, PVC, inter-TES and the USAC SBR syntax on
   the existing SBR decoder; HE-type xHE streams against FFmpeg.
6. MPS 212 with residual coding and the SBR coupling; low-bitrate stereo
   streams against FFmpeg and FDK.
7. MPEG-D DRC and loudness: parser, gain decoder, selection, application,
   API; against the FDK decoder's output with the same requests.
8. Concealment for USAC, output delay reporting, binding and Electron,
   docs, reference comparison, roadmap.

## Verification

CTest suites per unit; interop figures (SNR against FFmpeg 7.1.1 and
against the FDK decoder output as a black box) recorded in the reference
comparison; the sanitizer build on every merge; the mutation harness of
the tier 2 review over the new parsers; the binding smoke test and the
Electron service suite. Not possible here: listening tests and the ISO
conformance suite.
