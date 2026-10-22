---
title: Transcode format correctness and end-to-end validation
date: 2026-10-22
status: reviewed
execution: code
---

# Transcode format correctness and end-to-end validation

## Purpose and scope

This work investigates the active FFmpeg worker, signed HLS and DASH delivery,
fragmented MP4 output, subtitle extraction, and the movie player. It repairs
reproduced defects and adds executable evidence for the supported path. An
investigation cannot certify every codec, device and player: the final report
must identify the tested matrix and remaining acceptance work.

The user authorized equivalent planning and review because the required `ce-*`
skills are unavailable. This plan receives an independent review before code
changes. Work lands as local commits on the branch named after this plan; no push
is authorized. Existing local data and running development services are preserved.

## Findings driving implementation

* The software HEVC builder produces 7.5-second fragments on a 24 fps source while
  serving code assumes six-second segments. Forced I pictures are not necessarily
  random access points. Encoding tests must inspect and decode the real output.
* Hardware device flags contain shell quotes even though process execution uses
  an argument array. FFprobe's `tt`, `bb`, `tb` and `bt` field orders are not
  recognized as interlaced.
* Six-channel downmix assumes back surrounds and discards side surrounds from
  `5.1(side)`. A mandatory audio map rejects otherwise valid video-only files.
* Subtitle identity currently equals language. Missing language metadata and
  duplicate languages fail extraction; the worker ignores the failure. Extraction
  waits until the video encoder finishes, so throttled playback has no subtitles.
* A full subtitle file is advertised as six seconds. A restarted generation has a
  local video timeline but retains source subtitle timestamps. Probe disposition
  flags are read incorrectly and raw metadata can break HLS quoted attributes.
* HLS and DASH bandwidth omit muxed audio. DASH declares the on-demand profile
  while emitting SegmentList. The output contains muxed audio/video and has not
  established CMAF conformance or general DASH player interoperability.
* The active browser hook uses native HLS. The installed Chromium reports native
  HLS capability; this audit has not yet run its decoding checkpoint.
  The separate player package is not wired into the application. An existing
  disposable full-stack browser checkpoint verifies decoded playback and controls,
  but the ordinary CI invocation does not enable it.

## Implementation units

### Encoding correctness

Repair random access point scheduling for software HEVC, hardware argument
construction, interlace recognition, layout-aware downmix and video-only input
handling. Video-only output must omit audio codec declarations; synthesized audio
is not required. Exercise real H.264 and HEVC output at
ordinary and fractional frame rates, including a restarted generation and final
partial segment. Verify six-second boundaries within one frame, independently
decodable fragments, audio presence, monotonic timestamps, and no unfinished
public files. Hardware argument tests do not qualify physical GPU execution.
Use isolated-channel signals to check both common six-channel layouts.

### Subtitle delivery and manifest contracts

Give each source subtitle track an index-based identity independent of language.
The track key is `s` followed by its absolute FFprobe stream index. It is carried
through helper identity, output directory and the existing subtitle URL slot;
the slot's historical name does not turn language metadata into identity.
Preserve language, title, default and forced dispositions as metadata. Extract
supported text tracks by stream index into generation-local output, with seek
timestamps aligned to that generation. Publish files atomically only after
successful conversion. Start extraction with video generation and poll its
results without blocking control handling or waiting for whole-video completion.
Do not advertise missing, failed, or unsupported bitmap output. Bound helper
waiting, keep generation fencing, and log extraction failures.

Advertise the full remaining timeline for a full WebVTT resource. Sanitize HLS
quoted metadata and preserve unique track names. Correct bandwidth declarations
for muxed renditions and choose a DASH profile compatible with the actual segment
addressing (`urn:mpeg:dash:profile:full:2011`, verified in a focused regression).
Keep general DASH interoperability and CMAF certification distinct
from successful XML generation and local fragment decoding.

### End-to-end evidence

Extend the disposable two-command fixture with multiple real subtitle tracks,
including duplicate and absent languages and cues beyond six seconds. Follow
signed manifests through HTTP to actual worker output. Decode retrieved HLS/DASH
media, verify audio and video, and inspect subtitle cues before and after a seek.
Check stale generation refusal and altered signatures. Keep the existing lifecycle
and containment checks. Enable the actual movie-player browser checkpoint in CI
and add browser subtitle evidence where the tested engine exposes text tracks.
Subtitle rendering acceptance requires expected active cues on the selected track,
including after seeking. Unsupported rendering is recorded as open acceptance,
not converted into a passing conditional assertion. Rapid scrubbing, offline
reconnect and paused remount require the separate instrumented client-controls
gate; the ordinary production checkpoint does not establish those cases.

No external CDN or production service supplies fixtures. Browser traffic stays
within the disposable test deployment. Required tests fail on missing prerequisites
rather than silently passing through a skip.

### Review and documentation

Review changes at unit boundaries and simplify unnecessary abstractions. Run an
independent final review, apply justified findings, and record exact check results
and open items in `ROADMAP.md`. The investigation report explains defects,
remedies, architecture strengths, improvements and coverage limits with source
references. Apply the project's prose guidance to documents.

## Ownership and dependencies

* Encoding worker: encoder argument and video processing code, video probe
  interlace handling, and their focused tests.
* Delivery worker: subtitle value object, helper and worker extraction lifecycle,
  streaming/manifests, and related focused tests. Coordinate shared metadata
  contracts with the lead before edits.
* Browser worker: existing two-command browser checkpoint and browser assertions;
  focused review of the plan and final frontend changes.
* Lead: two-command PHP fixture, shared runner/CI configuration, documentation,
  integration, authoritative checks and commits.

Workers do not stage or commit. Expensive suites and shared-state runners are
serialized by the lead. No database schema change is planned.

## Acceptance and verification

1. Reproductions fail before fixes and pass afterward, with retained diagnostics.
2. Focused Transcode units and affected integration/functional tests pass in the
   CI runtime. Native media tests execute real FFmpeg and FFprobe.
3. The disposable full-stack drill passes through real worker and HTTP delivery;
   the browser checkpoint requires decoded frames and an advancing media clock.
4. Subtitle identity, duration, failure, publication and seek cases have automated
   coverage. Evidence distinguishes browser rendering from fetching valid WebVTT.
5. PHPStan, Deptrac, affected frontend checks and whitespace checks pass, or a
   pre-existing/environment failure is precisely recorded without claiming a pass.
6. The report and roadmap state remaining DASH, CMAF, browser, GPU, subtitle-format,
   cache and performance qualification work. No universal compatibility claim is
   inferred from this matrix.

## Review checkpoint

Independent review approved the plan on 2026-10-22. Its amendments specify track
identity, the DASH profile, video-only and downmix cases, native capability versus
decode evidence, and the distinction between the two browser gates. Implementation
may begin; final acceptance depends on the recorded checks.
