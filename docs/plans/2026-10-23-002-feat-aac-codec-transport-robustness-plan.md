---
title: AAC codec transport and robustness: concealment, limiter, signalling, LATM/LOAS, 960 frames, layouts
date: 2026-10-23
status: delivered
execution: code
---

# AAC codec transport and robustness

## Purpose and scope

`packages/codecs/baander-aac` decodes and encodes AAC-LC and HE-AAC v1/v2
over ADTS with 1024-sample frames. This plan closes the first tier of the gap
list against the AOSP fdk-aac upstream
(`/mnt/project-files/aac-codec/fdk-gap-list.md`): the features that make the
decoder robust for streaming playback and complete the classic AAC transport
surface, in both directions where the upstream has both.

In scope:

1. Error concealment in the decoder (spectral and SBR/PS), with a streaming
   resync that survives damaged bytes.
2. A time-domain PCM limiter after downmix, optional, on by default in the
   binding.
3. AudioSpecificConfig: parse and write, explicit backward-compatible and
   hierarchical SBR/PS signalling, raw access units for the MP4 path.
4. CRC: the encoder can write ADTS CRC; the decoder verifies it and conceals
   a failing frame.
5. LATM/LOAS: AudioSyncStream and AudioMuxElement demux and mux,
   StreamMuxConfig with audioMuxVersion 0 and 1, in-band and out-of-band
   configuration.
6. 960-sample frames (scalefactor band tables, window lengths, SBR with 15
   time slots) in decoder and encoder.
7. Channel modes 7.1 top-front (14), 7.1 rear surround (33), 7.1 front centre
   (34) and the MPEG-2 ADTS variant (profile id with PNS off).

Out of scope (later tiers): ER tools, AAC-LD/ELD, DRC, MPEG-D DRC, USAC,
MPEG Surround, afterburner.

Written from ISO/IEC 14496-3 (2009 + amendments) and ISO/IEC 14496-1 for
LATM; the FDK sources are consulted for the feature list and parameter ranges
only, no code is copied.

The `ce-*` skills are not installed in this checkout; this plan stands in for
`ce-plan` and gets a review pass before implementation. Work lands as local
commits on `claude/project-thread-qa25vy`; no push until the user asks.

## Design

### Public C API (`include/aac.h`)

- `AacTransport { AAC_TRANSPORT_ADTS, AAC_TRANSPORT_LOAS, AAC_TRANSPORT_LATM_MCP1, AAC_TRANSPORT_LATM_MCP0, AAC_TRANSPORT_RAW }`.
  Encoder: `aac_encoder_set_transport(ctx, transport)`; `aac_encoder_config(ctx, buf, size)`
  returns the AudioSpecificConfig bytes (for the MP4 `esds` box or LATM MCP0
  out-of-band). Decoder: `aac_decoder_set_transport(ctx, transport)` and
  `aac_decoder_set_config(ctx, asc, size)` for raw access units; ADTS and
  LOAS are auto-detected from the sync word when the transport is left at
  its default of "detect".
- `AacSignaling { AAC_SIGNAL_IMPLICIT, AAC_SIGNAL_EXPLICIT_BC, AAC_SIGNAL_EXPLICIT_HIER }`
  via `aac_encoder_set_signaling`. ADTS stays implicit (the header cannot say
  otherwise); ASC and LATM carry the chosen one.
- `aac_encoder_set_frame_length(ctx, 1024 | 960)`; `aac_encoder_frame_size`
  reports 960 or 1920 accordingly. `aac_encoder_set_crc(ctx, on)`.
- `aac_decoder_set_concealment(ctx, AAC_CONCEAL_OFF | AAC_CONCEAL_NOISE | AAC_CONCEAL_INTERPOLATE)`
  (default interpolate). A damaged frame then returns the concealed samples
  and sets `aac_decoder_last_frame_concealed`; `AAC_ERR_DECODE` is only
  returned when concealment is off. `aac_decoder_feed(ctx, bytes, n)` plus
  `aac_decoder_decode_next(ctx, pcm, size)` form the streaming interface that
  resyncs inside damaged data; the one-shot `aac_decoder_decode` stays for a
  whole frame.
- `aac_decoder_set_limiter(ctx, on, attack_ms, release_ms)`; the limiter
  adds `attack_ms` of delay, reported by `aac_decoder_delay`.
- `aac_decoder_channels` accepts the new 7.1 modes through a layout enum
  `AacLayoutId`, and `aac_decoder_layout` reports the stream's.

### Concealment (`src/conceal.cpp`)

Per channel element the decoder keeps the last good spectrum, scalefactors
and window sequence. On a parse or CRC failure the frame's element data is
replaced: interpolation mode fades the previous spectrum with the
scalefactors stepped down per the frame counter (fade out over five frames
into noise substitution); noise mode substitutes band energies of the last
good frame with decorrelated noise immediately. The window sequence follows
the last good frame; a short-window frame conceals as eight short windows.
SBR conceals by reusing the previous envelope with decreasing gain and the
previous noise floor; PS reuses the previous parameters. Recovery fades in
over two frames. The decoded output never stops: a lost ADTS frame (sync
error) emits one concealed frame when the stream rate and layout are known.

### Streaming input and CRC (`src/transport.cpp`)

The transport layer owns a byte FIFO. ADTS sync requires two consecutive
headers agreeing in rate, layout and profile before a frame is accepted;
after an error it rescans from the next byte. ADTS CRC (CRC-16 over the
header and the first 192 bits of each raw data block, as the standard
defines) is verified when `protection_absent` is 0; the encoder writes it
on request. LOAS sync (`0x2B7` + 13-bit length) uses the same FIFO.

### LATM/LOAS (`src/latm.cpp`)

`AudioMuxElement` with `muxConfigPresent` 0 and 1, `StreamMuxConfig` with
`audioMuxVersion` 0 and 1 (the `latmBufferFullness`, `frameLengthType` 0,
one program and one layer, `otherDataPresent`, CRC). The ASC inside the
StreamMuxConfig goes through the ASC parser. The muxer writes one access
unit per element with `muxSlotLengthBytes` as 255-chunk runs, repeats the
StreamMuxConfig every `header_period` frames (default 1 for LOAS).

### AudioSpecificConfig (`src/asc.cpp`)

`GetAudioObjectType` escape, sampling frequency index and explicit rate,
channel configuration, GASpecificConfig (frame length flag for 960, no core
coder, no extension; a PCE when the configuration is 0),
`extensionAudioObjectType` and `extensionSamplingFrequency` for explicit
backward-compatible signalling (SBR 5, PS 29 with the `sbrPresentFlag`
and `psPresentFlag` syncExtensionType 0x2b7 / 0x548), and hierarchical
signalling (AOT 5/29 in front, core AOT after). The decoder uses an ASC to
preset rate, layout, frame length and SBR mode before the first access unit.

### 960 frames (`src/tables.cpp`, `src/mdct.cpp`, `src/sbr_*.cpp`, `src/encoder.cpp`)

Scalefactor band offsets for 960/120 (ISO/IEC 14496-3 Tables 4.129 to
4.140 with the 960 columns), windows of 960 and 120 with the matching KBD
and sine shapes, MDCT sizes 1920/240, the psychoacoustic band mapping of the
encoder regenerated from the tables, the SBR frame with 15 QMF slots
(`numTimeSlots` 15, envelope borders scaled), and the encoder's SBR grid
and tuning rows unchanged. The frame length is fixed per stream (ASC or
encoder setting); ADTS carries no frame length, so an ADTS stream with 960
frames needs the decoder told explicitly.

### Limiter (`src/limiter.cpp`)

Look-ahead peak limiter on the interleaved output: a sliding maximum over
the attack window, gain smoothing with attack and release time constants,
threshold at full scale; delay equals the attack window. Follows the design
in ISO/IEC 23003-4 Annex (the MPEG-D DRC peak limiter), written from the
description.

### Channel modes

The layout table gains 14 (C, L, R, Ls, Rs, LFE, Ltop, Rtop; PCE with the
top pair as a front height CPE), 33 (C, L, R, Ls, Rs, Lrear, Rrear, LFE) and
34 (C, Lc, Rc, L, R, Ls, Rs, LFE). The ADTS header names 7 only; the others
travel as a PCE or the ASC's channel configuration 7/14 as the standard
allows. Canonical output order extends with the new positions appended.
The downmix coefficients for the new positions follow ITU-R BS.775.

### Binding and app

`AacDecoderOptions` gains `transport`, `config` (ASC bytes), `concealment`,
`limiter`, `frameLength`; `AacEncoderOptions` gains `transport`,
`signaling`, `frameLength`, `crc`, `layout`. The decoder exposes
`concealedFrames` next to `errors`. The Electron `AacService` and the IPC
validation follow.

## Work units

1. Transport layer with FIFO, ADTS resync, CRC verify/write. Tests: damaged
   streams, truncated frames, CRC mismatch detection.
2. Concealment (spectral, SBR, PS) and the streaming decode API. Tests: drop
   and corrupt frames of the committed HE-AAC streams and of our LC streams;
   output continuity (no sample-level discontinuity above a bound), the
   concealed frame count.
3. ASC parser and writer, raw access-unit transport, explicit signalling.
   Tests: roundtrip of every signalling mode; FFmpeg decodes our raw AUs when
   wrapped in MP4 by `ffmpeg -c copy` with our ASC.
4. LATM/LOAS mux and demux. Tests: FFmpeg's `latm` muxer output decodes with
   us; our LOAS decodes with FFmpeg.
5. 960 frames, decoder and encoder, including SBR. Tests: tables against the
   standard's sums, roundtrip at every rate, FFmpeg interop (FFmpeg decodes
   960 via ASC in LATM).
6. Limiter. Tests: a clipped-input stream stays within full scale, the
   delay matches, a quiet stream is untouched.
7. Channel modes 14, 33, 34 and the MPEG-2 ADTS variant. Tests: layout
   roundtrips, FFmpeg interop for the layouts it maps.
8. Binding, Electron service, docs (README, reference comparison, CONCEPTS,
   ROADMAP checkpoint).

Each unit lands as a local commit; `ce-simplify-code` and `ce-code-review`
stand-ins run by hand at the phase boundaries as in the previous plan.

## Verification

- CTest suites extended per unit; release and ASAN/UBSAN builds green.
- FFmpeg interop for LATM/LOAS, raw AUs with explicit signalling, 960
  frames and the new layouts.
- A fuzz run of the transport and decoder entry points (libFuzzer harness
  under `tests/fuzz`, run for a bounded time in the ASAN build).
- Concealment measured on the committed streams with 1 percent and 10
  percent frame loss: no decoder error, bounded discontinuities.

## Open questions

- Whether the binding should default the limiter on (FDK does, at the cost
  of delay) or leave it to the player's output chain.
- Whether an ADTS stream with 960 frames needs auto-detection (FDK requires
  the caller to say); the plan says explicit.
