---
title: Use selected-video timing for movie duration
date: 2026-10-24
status: reviewed
execution: code
---

# Use selected-video timing for movie duration

## Demonstrated failure

Movie playback advertises the container's duration even when the selected video
ends sooner. At `edebfa90`, the retained fourteen-second video/eighteen-second
audio source produces complete fourteen-second video and bounded audio. Native
coverage at the actual video end succeeds, but `VideoProbeResult::duration`
remains eighteen and coverage at that declared endpoint fails. The evidence is
under `var/test-runs/short-audio-tail-gates/final-dual-input-long-audio/`.

The same value reaches generation metadata, the signing response and the movie
player. It permits slider positions beyond the video and prevents the player's
canonical completion handling from recognizing the real endpoint. Published HLS
and DASH clocks already describe actual media; changing those clocks to match
the container would conceal the defect.

## Contract and scope

Resolve the selected video's endpoint from qualified metadata and express it in
the existing Source position coordinates. Keep the same first non-attached video
selection as the encoder. Audio, subtitles, artwork and a longer unselected video
must not determine this endpoint. Preserve Source audio selection, the existing
Source timestamp origin qualification and encoding behavior.

MOV/MP4 video duration is a span: combine the selected stream's start and own
duration using its time base where available. Matroska stream duration can be an
inferred copy of the container duration and is not independent evidence. The
calibrated Matroska selected-track `DURATION` tag describes an absolute endpoint;
do not add the reported stream start to it. Normalize each endpoint once using
the existing input clock: use the verified Source timestamp origin when present;
otherwise use the format start applied by ordinary FFmpeg input handling. Do not
invent a Source timestamp origin for the latter. Native MOV controls establish
video starting at four with audio at zero as an eighteen-second endpoint, and
both starting at four as a fourteen-second endpoint. Their selected video spans
are both fourteen seconds; treating the span alone as the endpoint is wrong.

The [FFmpeg 5.1.9 Matroska muxer](https://github.com/FFmpeg/FFmpeg/blob/n5.1.9/libavformat/matroskaenc.c#L2613)
tracks the maximum packet presentation end and writes that per-track value into
the `DURATION` tag. Native calibration establishes this behavior for the tested
files; it does not prove that arbitrary third-party tags are correct.

Qualification requires finite selected-stream timing, a finite normalization
origin and a strictly positive result. Ordinary format starts must be present,
finite and nonnegative; other starts retain the old estimate. Valid rational
timing takes precedence over textual seconds, with only FFprobe's decimal
rounding allowed when both representations exist. Contradictory or malformed
evidence must not be silently combined. Parse Matroska clock tags strictly and
only for that format; reject malformed, overflowed or nonpositive endpoints.

Keep probing bounded. The existing shared FFprobe command already returns stream
metadata and at most 128 initial packets; that prefix cannot establish an unknown
endpoint. This phase adds no full-file scan, second probe, endpoint cache or new
source-origin inference. Missing, invalid or unqualified selected-video metadata
retains the existing container-based duration estimate. That fallback remains
unqualified for exact video-end behavior and must be documented as a limitation.
Do not derive an endpoint from audio, nominal frame count/rate, the largest stream
or the last packet in demux order. B-frame presentation order can differ.

Keep the serialized `duration` field and its consumers. Raw-to-DTO-to-serialized
round trips preserve the resolved value without a second origin subtraction.
Worker probing supplies fresh playback metadata; existing catalog records are
not backfilled or reset. There is no API/schema migration, new frontend duration
calculation, direct-seek rejection policy or change to native manifest timing.

The separate frame-capture mismatch remains outside this implementation. Its
diagnostic follow-up stays in the roadmap; no observer tolerance is relaxed here.

## Implementation and verification

Continue the established equivalent reviewed-plan workflow while the named
compound-engineering skills remain unavailable. Review this unified plan before
implementation. Work on its named branch, commit local units, apply independent
simplification/code review and record exact verification in `ROADMAP.md`. Apply
prose-fix to documentation. Merge and push require a separate request.

One worker owns duration resolution and focused DTO/consumer regressions. A
second owns native media regressions. A third owns the production browser fixture
and its endpoint proof. The lead owns runner/CI configuration, integration,
authoritative checks, commits and final documentation. Workers do not stage or
commit, and file ownership must not overlap. Preserve existing services and user
media; serialize expensive tests in disposable containers.

1. Calibrate selected-video metadata against independent decoding for MOV and
   Matroska with longer audio, sparse held frames, B-frames, ordinary delayed
   video and nonzero starts. Retain commands, raw metadata, decoded endpoints,
   source revision and cleanup. Establish the normalization rule before coding.
2. Add DTO regressions for the selected stream's endpoint, attached pictures and
   multiple video streams, invalid/nonfinite metadata and the bounded estimate
   fallback. Verify serialization and existing source-origin behavior. Keep all
   origin, audio-selection, duration and coverage assertions strict.
3. Drive the actual probe, stream manager, encoder and publication reader with
   longer-audio inputs. Cover fresh and near-end seeks, native AAC/Opus, dense
   MOV/Matroska and sparse/verified-origin Matroska. Require correct DTO duration,
   complete decoded video/content, bounded audio and process termination, and
   exact-end coverage using the DTO's duration. Preserve short/matched-audio,
   delayed-audio and default-track controls.
4. Verify generation metadata, worker readiness and playback serialization carry
   the corrected endpoint. Keep publication manifests based on actual fragments.
   Preserve rejection of genuinely incomplete video/audio presentations.
5. Add a bounded production-browser phase using the existing disposable
   two-command stack, selected by `BAANDER_TEST_MOVIE_ENDPOINT=1`. Use video
   lasting 42 seconds and audio lasting 48, retaining
   raw source evidence. Read the actual signing response and real movie slider;
   do not supply fixture-known duration as playback metadata. Retain a paired
   baseline failure showing 48, using the unchanged prior resolver with the same
   fixture. With the repair, require duration and slider maximum 42,
   real near-end/exact-end seeks, canonical completion and source-zero replay
   with advancing decoded frames. Reuse authentication and playback helpers,
   skip unrelated outage phases and retain verified worker/container cleanup.
   The mode's phases are authenticated, movie-endpoint and endpoint-complete;
   the existing full-drill defaults and failure assertions stay unchanged.
6. Run focused tests, the full affected Transcode backend selection in the CI
   image, PHPStan and Deptrac. Check runner configuration and targeted existing
   player completion/seek tests. Run the new browser phase and affected existing
   native/browser controls without repeating unchanged expensive gates merely
   for documentation edits.

Acceptance qualifies the calibrated MOV/MP4 and Matroska metadata paths and their
existing source clocks. It does not establish exact endpoints for missing/stale
metadata, arbitrary muxer tags, timestamp discontinuities, additional source
origins, hardware or untested browser/format combinations. Record any failure
separately from final passing evidence. Close this plan only after justified
review findings are resolved and the required gates pass.

Independent plan review approved the scope before implementation. Its required
boundary cases include missing/invalid format starts, conflicting timing
representations, malformed or overflowed tags, endpoints at/before the origin,
selected-stream identity and raw/serialized parity. The existing fallback,
including its verified-origin subtraction, remains unchanged and explicitly
unqualified for exact endpoints.
