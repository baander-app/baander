---
title: Separate media tracks and actual publication timing for HLS and DASH
date: 2026-10-22
status: reviewed
execution: code
---

# Separate media tracks and actual publication timing for HLS and DASH

## Scope and evidence

Continue the next repair recorded in `ROADMAP.md`: replace muxed audio/video
fragments with separate tracks, preserve signed generation ownership, and make
real DASH decoding pass alongside HLS. The preceding investigation reproduced
AAC packets reaching the video decoder in both tested FFmpeg DASH readers.
Changing manifest attributes or duplicating muxed URLs did not repair it.

The equivalent planning, review and implementation workflow remains authorized
because the compound-engineering skills are unavailable. Work uses this plan's
branch, independent reviews and local commits. This phase does not push changes.
Existing application databases, Redis and media storage remain untouched.

A native FFmpeg 5.1 probe confirms its DASH muxer can emit separate audio/video
fragments, actual MPD timelines and companion HLS playlists in one process. Both
formats decode real frames and PCM. The MPD is dynamic while encoding and static
at completion; temporary files are renamed after closed media publication. This
provides an authoritative timing source without another probe process per segment.
Implementation is gated on progressive decoding, full HLS completion and reference
DASH browser completion. Native output comparisons use completed-manifest decoding
of the same media and reader version. Positive output alone cannot detect
duplicated audio.

The pre-implementation prefix probe passed with explicit SegmentList addressing
and the one-second response wait. FFmpeg 5.1.9 and 8.1.3 each produced 312 video frames;
their PCM and packet results matched their own completed-manifest baselines byte
for byte, with increasing decode timestamps. The two readers made 13 MPD requests
in total and requested no unpublished media. Different decoder versions produced
different priming lengths, which is why comparison uses the same version. Backend
and player reviews approved the amended contracts before implementation.

Full-length testing subsequently exposed a separate FFmpeg reader limitation:
the checked DASH demuxers retain their live flag after a static MPD arrives and
keep polling after all fragments have been consumed. The prefix probe did not
test natural EOF. Explicit interruption also fails to qualify the full output:
the retained FFmpeg 5.1.9 run produced 479 video frames against its completed
baseline's 481, with unequal packet records and interrupted trailer writes.
Its PCM matched, but that does not establish complete video delivery.

Qualification therefore separates reader capabilities. FFmpeg verifies full HLS
EOF and exact output equality, full completed static DASH decoding, and an explicit
13-second live DASH prefix against an identically limited static baseline. The
source lasts 20 seconds, so the prefix crosses two publication boundaries without
claiming full content or EOF. A pinned DASH-IF dash.js 5.2.1/Chromium fixture verifies
complete DASH playback and natural EOF through dynamic publication and the static
transition. Independent review approved this decomposition. Full live FFmpeg DASH
tail decoding and EOF remain unqualified; no nonstandard manifest or patched test
client conceals that limitation. The failed full-output probe is retained under
`var/test-runs/20261022-204449-64d13932`.

Actual timing requires a published-media timeline. An incomplete static playlist
would falsely announce the end, while a complete synthetic VOD playlist invents
future fragment timing. Active HLS therefore exposes growing playlists without
ENDLIST, and active DASH preserves the native dynamic timing model. The movie
player must retain a full-source seek control independently of that growing window.

## Contracts

### One encoder, separate tracks, one published index

Use the FFmpeg DASH muxer with HLS output enabled, explicit video/audio adaptation
sets, timeline addressing and fixed generation-local file names. Video-only input
creates only video output. Keep the existing encoding filters, random-access point
schedule, resource limits, cancellation and pause behavior. This changes no native
command budget: three video encoders with one subtitle helper each plus one audio
encoder still require at most seven of eight commands.

The generation's atomic native MPD is its published media index. A bounded reader
validates the expected local file names and native representation structure, then
exposes the closed video/audio segments, initialization files, codecs, timescales,
start times, durations and completion state. Preserve native timeline offsets and
presentation metadata; do not independently zero the two tracks. Expand timeline
repeats only within explicit bounds, reject unsafe paths and malformed values, and
never read remote XML resources. No incomplete file becomes published output.

Worker readiness requires video and, when present, audio initialization plus media
covering the requested cursor. Persist actual video segment durations through the
existing aggregate contract, using zero-based generation-local video ordinals.
Native sequence numbers remain separate in the published index and native file
names; public segment URLs use the generation-local ordinal. The aggregate's
estimated count must admit observed output and settle to the
actual count at completion; a source-duration estimate cannot reject a final
fragment. Audio publication stays generation-local. A generation
change retires old work before starting replacements. Keep subtitle extraction,
clipping, readiness and cleanup guarantees from the preceding phase. No database
schema or persistence-query redesign is planned.

### Signed adaptive delivery

Expose generation-pinned video and audio playlist, initialization and segment
resources through explicit track routes. Sign the complete resource identity,
including track, index and generation. Validate current ownership again while
waiting for publication. Remove the obsolete muxed delivery assumptions and update
ports, decorators, generated API descriptions and callers together; do not add a
compatibility route or file-name fallback for pre-release output.

Revalidate media identity rather than requiring an unchanged control revision.
Position-only telemetry can advance that revision while published fragments remain
valid. Final validation must still reject changed audio profiles, generations,
ownership and lifecycle state before serving a resource.

Keep the existing primary playback job/session identity. Readiness cannot come
from a secondary rendition while the primary lacks required tracks. At signing,
include only readable renditions with the same source presentation origin, control
state and audio profile. Signed requests must reject a retired or incompatible
member rather than silently changing the signed set. Different native wall-clock
launch timestamps alone do not make identical media origins incompatible.

Use the primary rendition's audio as the common HLS audio group and DASH audio
AdaptationSet. The video representations retain their own actual timelines and
codec declarations. Preserve a single coherent dynamic presentation clock from the
primary native index. Convert native addressing to signed URLs without discarding
timescales, timeline starts, durations or presentation offsets. Do not advertise
redundant copies of the same audio as language alternatives. Video-only manifests
omit audio groups, audio adaptations and audio codec declarations.

Capture the native dynamic clock before first readiness so it remains available
when the primary finishes before another admitted rendition. The merged DASH
presentation becomes static only after every admitted track completes. If the
primary completes before any dynamic clock is observed, signing admits completed
renditions only. Keep a previously signed set intact while it finishes.

Rewrite native SegmentTemplate addressing as explicit SegmentList entries. The
progressive probe shows this avoids future-segment guesses and duplicate PCM in
FFmpeg 5.1. Its reader still ignores the advertised update interval at the end of
the current list. Bound active DASH responses with a one-second cooperative wait,
then reload the index and revalidate the signature, signed set and generation. Completed
static responses return immediately. This adds a one-second cooperative wait to
initial dynamic-manifest delivery and does not delay HLS or media resources. Keep native
clock metadata and update intervals intact; no client polling state is stored.
The wait bounds a reader's sequential reload loop; it is not a global rate limit.
Declare listed closed segments immediately available, including when encoding runs
ahead of wall-clock playback, using the inherited SegmentList availability offset
and completeness attributes. This changes segment availability without rewriting the
native presentation timestamps.

HLS playlists contain only published segments and remain refreshable until native
completion, with a stable target duration throughout a generation. DASH similarly
remains dynamic until completion. Track durations and
final fragments come from the native index, including audio tails that do not match
video segment counts. Subtitle delivery retains its full generation-relative VTT
resource contract. This phase does not claim formal CMAF conformance or add DASH
subtitle rendering.

### Full-source movie seeking

The playback snapshot's source duration remains authoritative for the movie's time
labels and accessible seek control. Committing a source position calls the backend
seek operation directly, including positions beyond the published media window.
Native controls continue to provide play, pause, volume and fullscreen.

The first production browser run established a native Chromium EVENT limitation:
the requested local cursor was 18 seconds and buffered media extended to 24 seconds,
but every recorded seekable range was empty. The worker had published about 60
seconds. The restoration check correctly refused the seek. A later rendition-set
expansion also replaced the source while restoration was pending.

Use pinned HLS.js 1.7.3 through Media Source Extensions where supported. Each
attachment owns one lazily loaded player instance, starts loading at its explicit
generation-local cursor, and destroys the instance on replacement or unmount.
Fence imports, events and playback promises by attachment identity. Retain native
HLS only where the MSE path is unavailable. Preserve actual seekable-range checks;
buffered media or an infinite EVENT duration is not a substitute. Defer optional
same-generation rendition expansion until source restoration settles, while
applying committed execution changes immediately. This leaves the verified
backend generation-origin and publication contracts intact.

Keep HLS.js subtitle handling enabled: the existing signed subtitle playlists
already wrap generation-relative VTT. The MSE browser check must observe all three
tracks and active Dialogue cues before and after seeking. Retain playlist bodies
and complete per-attachment segment request order to check source-origin behavior;
a computed global cursor alone does not establish which source content played.
Native-only caption behavior remains a separate qualification limit.

Explicit seeks use the existing first/latest request coalescing and generation
fences. Preserve requested global position and pause intent while replacement media
loads. Restore native local time only when the target lies in a real seekable range;
EVENT duration may be infinite or incomplete. Suppress progress and native seek
feedback during this transition so clamping cannot overwrite the requested source
position. Release pending state on failure and unmount.

Compare a ready execution with the attached generation, since preparing polls can
already describe its successor. A retired attachment's failure must not replace
the successor's canonical cursor with the old source origin. Transient signing-poll
transport and server failures preserve an already usable attachment; subsequent
polls reconcile it. Initial failures, access refusal and fatal media-loader errors
remain terminal. Verify paused signing-poll reconnection with the same generation
and cursor, followed by resumed decoding; running control failures remain outside
that qualification.

Paused-seek acceptance must establish that the requested position becomes seekable
before publication pauses. If the native reader's holdback requires more media,
adjust bounded encoder headroom rather than replacing seekable-range validation
with a duration estimate.

## Implementation ownership

* Encoding worker: SegmentEncoder, generation storage contracts/implementation,
  published-index reader, StreamManager/Subscriber, and corresponding FFmpeg,
  storage and worker tests, plus the aggregate's observed segment count handling.
  Agree the reader interface with delivery before edits.
* Delivery worker: streaming application port and adapter, HLS/DASH generators,
  controllers/decorators, and focused delivery/manifest tests. Consume the shared
  published index and coordinate exact route/file identities with encoding.
* Player worker: production playback hook and movie overlay, their unit tests and
  browser checkpoint. The lead owns the PHP deployment fixture and shared scripts.
* Lead: plan, shared configuration/API generation, deployment fixture, native HTTP
  integration checks, roadmap/concepts documentation, authoritative runs and commits.

Workers do not stage or commit and do not overwrite one another's edits. Expensive
or shared-state tests run serially. Review and simplify each implementation unit;
apply justified findings before final acceptance.

## Acceptance

1. Native H.264/AAC and HEVC/AAC output contains exactly one declared track per
   initialization/fragment pair. Video-only output contains no audio. Actual
   advertised timing matches the native muxer's timeline, including fractional
   rates, restarted generations and final partial fragments.
2. Real readers fetch new HLS and DASH segments during active encoding. HLS and the
   reference DASH browser must reach natural EOF; full live FFmpeg DASH tail decoding
   and EOF remain unqualified. Native decoding requires actual video frames and
   nonempty PCM for sources with audio; a successful process exit alone does not
   pass. Compare full progressive HLS output and the explicitly limited live DASH
   prefix against completed-manifest decoding of identical artifacts with identical
   duration limits and reader versions. Require exact output equality and continuous
   timestamps within those qualified intervals, no unpublished-segment requests,
   and bounded dynamic manifest reloads. Record source duration, prefix duration,
   full-content qualification flags and the dynamic response delay. All qualified
   native runs must exit cleanly without decoder, network or trailer errors.
   The browser must attach before final fragments exist, fetch later audio/video
   fragments, observe a static MPD, and emit unforced `ended` near the measured
   media duration. Do not seek to the end, replace the source with a completed
   manifest, or force an end-of-stream signal to satisfy this check. Explicitly
   stopping the FFmpeg live reader does not qualify its missing tail or EOF; retain
   that failed diagnostic separately from the passing prefix gate.
3. Signed HTTP HLS and DASH decode initially, after a seek and after worker recovery.
   Required audio absence prevents readiness. Altered track/index/generation
   signatures fail. Restored retired audio, video and subtitle files remain refused.
4. The ordinary production drill requires DASH decoding; remove the optional failing
   qualification switch after the replacement passes. Retain explicit per-track
   resource/timing receipts and failure diagnostics.
5. The real movie page plays separate-track HLS, seeks beyond its published window
   using the full-source control, returns to an earlier position, preserves paused
   seeking, and survives web replacement and worker containment/recovery. Test
   rapid explicit seeks, stale native events, EVENT duration growth and cancellation
   in the hook, and paused signing-poll reconnection in the browser; retain existing
   subtitle observations without claiming unavailable
   native caption rendering passed.
6. Focused backend/native/HTTP suites, affected frontend tests/type/build checks,
   PHPStan, Deptrac, OpenAPI consistency and whitespace checks pass. Record actual
   counts, artifacts and browser/codec versions in ROADMAP.md.
7. Independent final review checks the signed delivery boundary, publication and
   cleanup races, timeline compatibility, player lifecycle and the acceptance
   claims. Full CMAF validation, native caption qualification, Safari/Firefox,
   hardware/HDR/VFR breadth, URL lifetime policy and cache/performance work remain
   distinct roadmap items unless direct evidence in this phase resolves one.

## References

The [FFmpeg formats documentation](https://ffmpeg.org/ffmpeg-formats.html) describes
DASH/HLS muxer options and track addressing. The
[DASH-IF timing model](https://dashif.org/Guidelines-TimingModel/) describes dynamic
availability and MPD update validity. Native output and real-reader tests determine
which behavior this implementation actually qualifies.
The [HLS.js API](https://hlsjs.video-dev.org/api-docs/hls.js.hls.startload)
defines explicit start positions; its
[lifecycle API](https://hlsjs.video-dev.org/api-docs/hls.js.hls.destroy)
defines attachment teardown. Production browser evidence qualifies their use here.
