---
title: AAC codec fourth generation: USAC/xHE-AAC decode, MPEG-D DRC, MPEG Surround, xHE-AAC encode
date: 2026-10-23
status: draft
execution: code
---

# AAC codec fourth generation

## Purpose and scope

Third tier of the gap list against the AOSP fdk-aac upstream
(`/mnt/project-files/aac-codec/fdk-gap-list.md`). Three large, separable
items, each its own phase with its own acceptance:

1. USAC / xHE-AAC decoder (ISO/IEC 23003-3): the USAC frame syntax with
   `UsacConfig`, the frequency-domain core (arithmetic coded spectra with
   the context model, noise filling, TNS, complex stereo prediction,
   window groups and the USAC window shapes), the LPD path (ACELP and TCX
   with the FAC transitions, LPC with the LSF quantiser), the enhanced SBR
   (harmonic transposer, PVC, inter-TES), MPEG Surround 212 as used by
   USAC stereo, audio pre-roll and the stream access points, LATM/LOAS and
   raw-AU transport with the USAC `AudioSpecificConfig`. Verified against
   FFmpeg 7.1 or later (FFmpeg's USAC decoder) and the committed reference
   streams.
2. MPEG-D DRC and loudness (ISO/IEC 23003-4): the `uniDrc` payload parser
   (DRC instructions, gain sets, loudness info, downmix instructions), the
   selection process with the effect type, target loudness and album mode
   requests, the gain decoder with its interpolation and the application
   in the time and QMF domains; mandatory for xHE-AAC playback and used
   as an extension payload with AAC-LC/HE.
3. MPEG Surround decoder (ISO/IEC 23003-1): 212 (mono to stereo) and the
   515 and 525 trees for 5.1 from a stereo downmix, the baseline profile
   tools (OTT and TTT boxes, decorrelators, smoothing, temporal shaping,
   residual coding), and the MPS 212 encoder as the xHE-AAC encoder needs it.
4. xHE-AAC encoder: the USAC core encoder (FD with arithmetic coding, the
   switching decision to LPD, ACELP/TCX), eSBR encoder, MPS 212 encoder,
   loudness measurement (ITU-R BS.1770, EBU Tech 3342) in one and two
   passes, the live loudness leveler, MPEG-D DRC gain sets, audio pre-roll
   at random access points, stream id.

Each phase gets its own detailed plan before implementation; this file
records the scope and the order agreed on 2026-10-23.

## Verification

FFmpeg (7.1 or later) for USAC decoding, the ISO conformance bitstreams
where they can be obtained, roundtrip SNR and bitrate bounds for the
encoder, loudness measurement against a reference implementation of
BS.1770 (FFmpeg's `ebur128` filter), MPEG Surround against a locally
built libfdk-aac binary as a black box.
