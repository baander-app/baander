---
title: AAC codec low delay and dynamics: ER tools, AAC-LD, AAC-ELD, afterburner, MPEG-4 DRC, performance pass
date: 2026-10-23
status: draft
execution: code
---

# AAC codec low delay and dynamics

## Purpose and scope

Second tier of the gap list against the AOSP fdk-aac upstream
(`/mnt/project-files/aac-codec/fdk-gap-list.md`), after the transport and
robustness plan (2026-10-23-002). It adds the codecs and tools a
conferencing or broadcast path needs and the quality and speed work that
the music path benefits from.

In scope:

1. Error-resilient AAC-LC syntax (AOT 17) and the ER tools in the decoder:
   virtual codebook 11 (VCB11), reversible variable length coding (RVLC)
   with its concealment, and Huffman codeword reordering (HCR). The
   encoder writes ER syntax for LD and ELD only (as FDK does) without the
   optional tools.
2. AAC-LD (AOT 23): 512 and 480 sample frames, the LD window and the low
   overlap window shape, no block switching, TNS and PNS as in LC, ER
   syntax, LATM/LOAS transport. Decoder and encoder.
3. AAC-ELD (AOT 39): the low-overlap ELD filterbank (512/480 with the
   frame-length-dependent window from the standard's coefficients), the
   ELD-specific ics syntax, LD-SBR (SBR at ratio 1 or 2 with the low-delay
   QMF and the LD frame grid) and ELD downscaled playout (frame lengths
   256/240 and 128/120). Decoder and encoder. The LD-SBR encoder follows the
   same structure as the existing SBR encoder with the low-delay tables.
4. Afterburner: a second quantisation pass in the encoder that re-searches
   the scalefactors and codebooks against the bit budget with a finer
   noise target, taking more CPU for a measurable gain at low bitrates;
   switchable through `aac_encoder_set_afterburner`.
5. MPEG-4 DRC and program reference level: the decoder parses
   `dynamic_range_info` in FIL elements and applies the DRC bands with
   boost and cut factors, a target reference level and the heavy
   compression flag from the DVB ancillary data (`aac_decoder_set_drc`);
   the encoder writes a DRC profile (film/music/speech standard and light)
   with a reference level in the metadata path (`aac_encoder_set_metadata`).
6. Performance pass: SIMD for the FFT and MDCT butterflies, the QMF
   analysis and synthesis inner loops, the SBR envelope adjustment and
   the Huffman fast path; no heap use on the decode and encode paths after
   creation; a benchmark tool (`baac bench`) and figures in the reference
   comparison.

Out of scope: USAC, MPEG-D DRC, MPEG Surround, xHE-AAC (tier 3).

Written from ISO/IEC 14496-3 (ER syntax 4.5.2, LD 4.6.17, ELD 4.6.20 with
its tables, SBR for ELD 4.6.18 with the LD extensions, DRC 4.5.2.7 and
4.6.3.5) and ETSI TS 101 154 for the DVB metadata; no FDK code.

## Design sketch

- ER syntax: `aac_decode_raw_data_block` gains an ER mode in which the
  element sequence is fixed by the channel configuration (no element ids),
  the section data carries VCB11 codebooks 16 to 31, scalefactors may be
  RVLC coded and spectral data may be HCR reordered. The three tools are
  separate modules (`src/er_vcb11.cpp`, `src/er_rvlc.cpp`, `src/er_hcr.cpp`)
  with their own tests against hand-built streams; FFmpeg has no ER
  decoder, so the tests are spec-driven plus our own encoder when it
  learns to write them.
- LD and ELD: a frame-length-aware filterbank gains the LD window (sine
  with the low-overlap variant) and the ELD window with its 4N length;
  `AacIcsInfo` gains the LD band tables (512/480 per rate). The encoder
  path for LD reuses the LC psychoacoustics without block switching and
  with the LD window; the ELD encoder adds the ELD filterbank and LD-SBR.
  `aac_encoder_create` accepts `AAC_AOT_ER_LD` and `AAC_AOT_ER_ELD`;
  `aac_encoder_set_frame_length` accepts 512/480 (and 256/240, 128/120
  for downscaled ELD). Verification: FFmpeg decodes AAC-LD; ELD and LD-SBR
  are verified by roundtrip and, as a black box, against a locally built
  libfdk-aac binary outside the repository.
- Afterburner: after the normal rate loop, an iteration re-quantises with
  the real bit count of the sectioned codebooks and shifts scalefactors
  by one where the noise-to-mask ratio improves most per bit.
- DRC: a per-frame `AacDrcInfo` (bands, dyn_rng_ctl and sign, prog_ref_level,
  pce_tag) applied in the time domain after the filterbank with the
  attenuation and boost factors; DVB heavy compression via the DSE
  ancillary data syntax.
- Performance: measure first with `baac bench` on the committed streams;
  SIMD kernels go into `src/simd/*.cpp` behind the existing dispatch.

## Work units

1. ER syntax and VCB11; 2. RVLC and HCR; 3. AAC-LD decode and encode with
LATM; 4. ELD filterbank and syntax (decode, then encode); 5. LD-SBR;
6. ELD downscaling; 7. afterburner; 8. DRC decode and encoder metadata;
9. performance pass with benchmark; 10. binding, docs, roadmap.

## Verification

CTest suites per unit; FFmpeg interop for LD and for DRC streams FFmpeg
can decode (it applies DRC itself, so compare with it off on both sides
and verify our application separately against the formula); roundtrip SNR
bounds for ELD and LD-SBR; benchmark figures before and after the
performance pass.
