---
title: AAC codec player-grade controls: decoder flush and seek, streaming random access, DRC gain sets, encoder tuning, fuzzing
date: 2026-10-30
status: draft
execution: code
---

# AAC codec player-grade controls

## Purpose and scope

Fourth phase of the fourth-generation scope (2026-10-23-004): the items
of the FDK gap list that matter for a music streaming server with an
Electron client and are not yet in the codec, taken from the Android
branch survey of 2026-10-29 (V1, V3, V4, V5, V6, X1 in part, X2, T1,
F2, F4). Martin asked on 2026-10-30 for everything worth doing for this
kind of project; the optimized (non-standard) pair stays a plan of its
own, since no candidate improvement has been proven yet and the rule is
measured proof before any such change.

In scope:

1. Decoder playback control: `aac_decoder_flush` drains the delayed tail
   at the end of a stream (the overlap, the SBR and QMF history, the
   limiter and the surround delay) so gapless playback hears the last
   samples; `aac_decoder_clear_history` for seeks drops the overlap, the
   SBR, PS and MPS state and the concealment history while keeping the
   configuration, and restarts concealment with a fade-in at the next
   frame; `aac_decoder_delay` reports the whole decoder-side delay (SBR,
   surround, limiter) so a player trims it, and the encoder reports its
   priming for an MP4 edit list; the FDK applicability rules (MPEG-D DRC
   applied to USAC only, legacy MPEG-4 DRC ignored inside USAC, an AAC-LC
   stream whose SBR rate exceeds twice the core rate refused) and the
   field tolerance rules (a PCE whose element order breaks ISO/IEC
   13818-7 8.5.1 accepted, a change of implicit SBR activity treated as
   a configuration change, the SBR payload of a frame whose header update
   fails discarded, a channel-configuration change inside a PCE under
   ADTS refused with a reset) where the decoder lacks them; the binding
   (`decoder.flush()`, `decoder.clearHistory()`, `decoder.delay`) and the
   Electron service's streaming sessions gain the calls.
2. xHE-AAC random access for streaming: an independent frame on demand
   (`aac_encoder_request_independent_frame`), the interval in
   milliseconds or in samples (rounded to whole frames) instead of
   frames, a maximum independency distance, and a bitrate ceiling per
   independent-frame segment so a segment never exceeds a given size;
   the raw access unit form reports frame boundaries and the independent
   frames so a muxer can cut segments.
3. Encoder tuning with proof: VBR modes as FDK 4.0.1 defines them
   (named points 1 to 5 as stereo targets: 32 kbit/s HE-AAC v2, 72
   HE-AAC v1, 112, 148 and 228 AAC-LC), the mode lowered when a peak
   bitrate is given; a peak bitrate limit (`AACENC_PEAK_BITRATE`) and an
   explicit bandwidth override (`AACENC_BANDWIDTH`) for every profile;
   the low-bit-reservoir quality fix (the rate loop's behaviour when the
   reservoir cannot absorb a transient); the encoder correctness checks
   from FDK's bug history (the SBR payload mapped to the right element in
   5.1 HE-AAC, the PS encoder's input flush when fewer samples than a
   frame remain). Every tuning change ships with measurements against the
   baseline (segmental SNR, bitrate, the band-level comparison against
   the FDK encoder) and keeps the output decodable by FFmpeg.
4. MPEG-D DRC gain sets in xHE-AAC streams: a gain generator for a
   target loudness range (6 to 16 LU) producing `uniDrcConfig` (one DRC
   set with a limiter-style and a compressor-style gain sequence, the
   effect types night and general) and the per-frame `uniDrcGain`
   payloads in the USAC extension element, so a player that applies DRC
   (ours, FDK) gets a quieter-listening mode while every other decoder
   ignores the element; the two-pass loudness measurement feeds it.
5. Fuzzing and benchmarks: libFuzzer harnesses for the decoder (ADTS,
   LOAS, raw with an ASC; every object type) and the encoder (options and
   input), seeded from the test streams and the bitstream fields FDK had
   to bound; a short mutation run as a CTest; decode and encode benchmarks
   over a fixed corpus printing realtime factors.

Out of scope: the optimized pair (its own plan once an improvement is
proven), the LPD core, the live leveler, phase coding in 2-1-2 (the
tool is opt-in while FFmpeg rejects it), DRM, ADIF, the 7.1 variant
channel modes, the Rust port.

## Oracles

FFmpeg master and 6.1.1, the FDK decoder and encoder binaries, libxaac,
and our own suites; the FDK encoder's VBR streams for unit 3; the FDK
decoder's DRC output for unit 4; ASan/UBSan for unit 5.

## Design sketch

- Unit 1 in `decoder.cpp`, `decoder_output.cpp`, `sbr_dec.cpp`,
  `mps_glue.cpp` and `api.cpp`: flush feeds silent frames until the delay
  is paid out; clear history resets the per-channel state without the
  configuration; `node/src/addon.cc` and the Electron service follow.
- Unit 2 in `usac_enc.cpp` and `api.cpp`: the independent-frame schedule
  becomes a policy object (interval in frames derived from ms or samples,
  on-demand requests, maximum distance) and the rate loop takes a
  per-segment ceiling.
- Unit 3 in `encoder.cpp`, `psycho.cpp`, `sbr_enc.cpp`, `ps_enc.cpp`:
  the VBR points as named presets over the existing quality modes, the
  peak bitrate as a hard per-frame cap, the bandwidth override into the
  tuning lookup.
- Unit 4 in `src/drc_gen.cpp` and `usac_enc.cpp`: gain sequences from
  the short-term loudness of the meter, written per frame.
- Unit 5 under `tools/fuzz/` with a CMake option `BAAC_FUZZ` and
  `tools/bench.cpp`.

## Work units

1. Decoder playback control, rules and tolerance; binding and service.
2. Streaming random access controls.
3. Encoder tuning and correctness with measurements.
4. DRC gain sets (after unit 2).
5. Fuzzers and benchmarks.
6. Docs, CONCEPTS, reference comparison, roadmap checkpoint.

## Verification

CTest suites per unit; the four decoders on every new stream; the
measurements of unit 3 in the reference comparison; a ten-minute fuzz
run per harness under ASan before the checkpoint; the sanitizer build;
the binding smoke test. Not possible here: the Electron suite under
Yarn, listening tests, the ISO conformance suite.
