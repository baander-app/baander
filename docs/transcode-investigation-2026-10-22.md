# Transcoding investigation, 2026-10-22

The layer is not fully sound. The existing Transcode suite passed 774 tests, but
a real 24 fps HEVC encode produced two fragments where the serving code expected
three. Subtitle extraction
also failed on ordinary files with duplicate or missing language metadata. The
layer had useful ownership and isolation safeguards, but its media-format
assumptions were not sufficiently tested. The signed DASH MPD also fails actual
audio/video decoding in both tested FFmpeg builds; that acceptance remains open.

This investigation follows the active worker through FFmpeg, generation storage,
signed HLS/DASH delivery and the movie player. It also distinguishes the unwired
player package from production playback. Scope and review remain in the
[plan history](../ROADMAP.md#completed-work-and-evidence). This dated report
preserves the original investigation; the [roadmap](../ROADMAP.md) records current
acceptance and the revisions needed to recover completed checks.

## Defects and repairs

| Area | Reproduction or consequence | Repair and evidence |
| --- | --- | --- |
| HEVC segment boundaries | A 14-second, 24 fps source produced 7.5 and 6.625-second fragments. Synthetic manifests expected 6/6/2 and referenced a missing segment. | Force IDR pictures in the software HEVC encoder. Real-media tests inspect fragment counts, durations, first packet random access flags, monotonic decode timestamps and independent decoding. |
| Interlaced inputs | FFprobe field orders `tt`, `bb`, `tb` and `bt` all produced `isInterlaced=false`. | Recognize standard field orders and exercise the selected deinterlacing filter. |
| Surround downmix | Isolated `SL`/`SR` tones in `5.1(side)` became silent because the pan expression selected `BL`/`BR`. | Preserve channel layout and select the appropriate surround pair. Real signal tests measure the output energy of both layouts. |
| Video-only input | Mandatory `-map 0:a:0` caused FFmpeg to reject a valid video-only source. | Carry audio presence through probing, worker launch and manifests; omit absent audio mapping, filters and codec declarations. |
| Hardware device arguments | An argv element contained literal apostrophes around `/dev/dri/renderD128`. | Reuse literal argument-array construction. Whitespace and quote tests verify arguments; no physical GPU is qualified. |
| Subtitle identity | Language mapping selected multiple same-language streams or no stream when metadata was absent. | Use the absolute source stream index, represented as `s2`, `s3`, etc. Language remains descriptive metadata. |
| Subtitle availability | Extraction ran only after the throttled video encoder completed. Failed helpers were treated as completed work. | Run one subtitle helper per video job alongside its encoder. Settle the batch before initial readiness, inspect outcomes and publish successful files atomically. Controls continue to run; a 30-second batch deadline retires unfinished work. |
| Subtitle concurrency | The initial parallel-extraction implementation let three browser-admitted renditions launch nine commands against a global limit of eight, causing worker containment during a seek. | Use one helper per video job: three encoder/helper pairs plus one audio command require at most seven commands. Preserve the global limit and whole-batch deadline. |
| Subtitle duration and seeking | A complete VTT file advertised six seconds; restarted video used local time while subtitles used source time. FFmpeg input seeking also dropped crossing cues. | Convert the complete subtitle track, then clip and rebase cues before publication. Native tests verify 15–40 becomes 3–28 and crossing 10–40 becomes 0–28 after a 12-second generation start. |
| Subtitle metadata | Default disposition was read from tags; forced disposition was lost; raw quotes/newlines could inject HLS tags. | Read dispositions, retain title/default/forced flags, give track names unique identities and constrain quoted attributes. |
| Variant bandwidth | Advertised bandwidth included only target video bitrate, excluding audio and video peaks. | Advertise a conservative estimate from maximum video bitrate plus configured audio bitrate and container allowance. This is not a measured peak guarantee. |
| DASH profile | The MPD claimed the on-demand profile while using separate-file SegmentList addressing. | Declare the full profile. This metadata repair does not fix the separate demux failure below. |
| DASH playback | The current MPD sends muxed AAC packets to the video decoder in FFmpeg 5.1.9 and 8.1.3. | Reproduced, unresolved. Keep a strict executable qualification that fails. Separate audio/video fragments and representations are required for the tested readers. |
| Browser gate | The real production-player checkpoint was optional and absent from the standard CI invocation. | Build the frontend before the worker image and enable that checkpoint in the two-command CI drill. |

The relevant production code is
[SegmentEncoder](../src/Transcode/Infrastructure/FFmpeg/SegmentEncoder.php),
[VideoProcessingRules](../src/Transcode/Domain/Service/VideoProcessingRules.php),
[AudioProcessingRules](../src/Transcode/Domain/Service/AudioProcessingRules.php),
[TranscodeSessionSubscriber](../src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php),
[TranscodeProcessPool](../src/Transcode/Infrastructure/Swoole/TranscodeProcessPool.php),
and [TranscodeStreamingService](../src/Transcode/Infrastructure/Transcode/TranscodeStreamingService.php).
The [WebVTT timeline transformer](../src/Transcode/Infrastructure/HLS/WebVttTimeline.php)
limits each file to 16 MiB, each cue block to 1 MiB and each line to 64 KiB before
publication. Exceeding a limit omits the track and records an extraction failure.

Forced I pictures and random-access IDR pictures are distinct; FFmpeg documents
the `forced-idr` option for this purpose. WebVTT duration and timestamps must match
the presentation described by HLS. The DASH full profile identifier is registered
by DASH-IF. These decisions follow the
[FFmpeg codec documentation](https://ffmpeg.org/ffmpeg-codecs.html),
[HLS specification](https://www.rfc-editor.org/rfc/rfc8216.html#section-3.5), and
[DASH-IF profile registry](https://dashif.org/identifiers/profiles/).

## Why DASH acceptance fails

The production image contains FFmpeg `5.1.9-0+deb12u1`. The same H.264/AAC
fragments decode through HLS but fail through the signed MPD with `Invalid NAL
unit size`. A narrow HTTP reproduction also fails with the host's SUSE FFmpeg
8.1.3 build. Packet hashes identify 658 AAC packets that HLS assigns to audio
stream 1 and the DASH reader assigns to video stream 0, byte for byte.
FFmpeg's [DASH packet reader](https://github.com/FFmpeg/FFmpeg/blob/n5.1.9/libavformat/dashdec.c#L2093)
replaces the inner stream index with the representation's single stream index.
Adding ContentComponents, removing contentType, or pointing separate adaptations
at the same muxed files did not repair the reproduction.

A one-second decode on the host initially exited zero while producing no audio;
full decoding exposed the error. The end-to-end decoder therefore requires both
actual video frames and nonempty PCM output. An exit code alone cannot establish
playback. Upgrading FFmpeg without qualifying the exact build is not a verified
remedy.

The ordinary HLS drill checks the signed MPD and referenced resources but records
`demuxQualified=false`. The explicit DASH qualification adds real MPD decoding
and currently fails. Keeping those outcomes separate makes the working playback
path testable without concealing the DASH defect. This is an observed reader
compatibility failure; it does not prove every possible DASH player rejects the
full-profile muxed representation.

## What the architecture already protects

Each video job belongs to a playback owner. Durable execution generations fence
worker output, and signed requests bind the generation, segment index and admitted
rendition set. Delivery checks current ownership again while waiting for output.
HMAC comparisons use `hash_equals`, and generation-scoped storage constrains path
resolution. The existing retirement drill restores old files and verifies that
they still cannot be delivered.

FFmpeg receives argument arrays rather than shell command strings. Native media
work runs in the execution service, outside HTTP workers. Fragment publication
uses temporary files and atomic rename; initialization readiness has a separate
completeness check. File delivery streams bytes without loading a whole media file
into PHP memory. Those protections deserve regression tests and were preserved.

The active movie route uses `MoviePlayerOverlay` and `useBaanderPlayer`, which
attach signed HLS to a native video element. The pinned Chromium reports native
HLS capability. The browser checkpoint verifies actual decoded frames, an advancing
clock, paused seek restoration and recovery against the application. Capability
reporting alone is not playback evidence.

## Test matrix and its limits

| Layer | Automated evidence | What it does not establish |
| --- | --- | --- |
| Encoder | Actual H.264 and HEVC at ordinary/fractional frame rates; restarted generation; final partial segment; video-only output; independent decode; audio/video packet timing | Every source/container, HDR correctness, AV1, GPU operation or codec quality |
| Audio | Isolated back/side surround channel energy survives stereo downmix | Perceptual quality, full loudness conformance, every multichannel layout |
| Subtitle helper | Native execution service, real FFmpeg, duplicate/absent languages, dispositions, seek timestamps, atomic publication and failure cleanup | ASS styling fidelity, bitmap/OCR support, external sidecar discovery |
| Worker controls | Deterministic slow/failing subtitle tests, pause/cancel, generation replacement and deadline cleanup | Production throughput or long soak behavior |
| HTTP and HLS demuxer | H.264/AAC worker output; signed HLS; actual decoded video frames and PCM bytes; signed DASH manifest/resources; three independent subtitle resources; seek-local cues; altered signature and retired-generation refusal | DASH demuxing, rendition-switch quality or full CMAF conformance |
| DASH qualification | Separate strict signed-HTTP MPD decoder check; currently fails with invalid H.264 NAL errors | No passing DASH playback claim |
| Production browser | Real movie page and native HLS; decoded frames and clock; paused seek and recovery; native subtitle observations | Safari qualification, Firefox fallback, automatic visual caption comparison |

When Chromium exposes subtitle TextTracks, the browser check selects Dialogue and
requires the expected active cue before and after seeking. If it exposes no native
subtitle tracks, the retained result explicitly records
`native-tracks-unavailable`; that is an open rendering qualification, not a
subtitle rendering pass. Screenshots support inspection when cues activate.

The ordinary production checkpoint does not run the instrumented client-controls
phase. That separate drill exercises rapid scrubbing, offline/reconnect and paused
remount. Its invocation and results must be recorded separately before claiming
those cases for the changed implementation.

## Executed checks

The final backend run passed 853 tests with 9,798 assertions in the CI runtime.
Full PHPStan, Deptrac, OpenAPI consistency, frontend type/build checks, 43
player-hook tests and 46 worker-verifier tests passed. Deptrac retained 484
existing skipped violations and reported no new violations, warnings or errors.

The production HLS drill passed all seven browser checkpoints on Chromium
148.0.7778.96: authentication, initial playback, paused seek, web replacement,
worker containment, worker recovery and completion. Retrieved fragments produced
181 actual video frames and 1,146,880 PCM bytes both initially and after recovery.
All three subtitle resources passed identity, disposition, duration, seek and
retirement checks. Chromium exposed no TextTracks at initial playback or after
seeking, so native cue activation and painted captions remain unqualified. The
instrumented client-controls drill was not run for this checkpoint.

Backend receipts are under `var/test-runs/20261022-191108-d1c3b386`; production
HLS/browser receipts, screenshots and verified cleanup are under
`var/test-runs/transcode-formats-hls-final`. The final strict DASH run exited 1
with an invalid-NAL error; its failed acceptance and verified cleanup are retained
under `var/test-runs/transcode-formats-dash-final`. The [roadmap](../ROADMAP.md) records the
checkpoint and outstanding acceptance.

## Improvements still worth making

1. **Repair DASH with separate media tracks, then qualify CMAF.** Current fragments mux
   audio and video, and DASH does not advertise subtitles. Reusing `.m4s` bytes in
   both manifest formats does not establish CMAF conformance. For independently
   selectable audio and subtitle tracks, define separate adaptation/switching sets,
   consistent timestamps and init segments, then test actual players and a format
   validator. [Apple's CMAF mapping](https://developer.apple.com/documentation/http-live-streaming/about-the-common-media-application-format-with-http-live-streaming-hls)
   describes the track and switching-set relationship. Do not add a CMAF label
   based only on FFmpeg flags or file extensions. A bounded implementation can keep
   one generation-owned encoder while emitting separate video/audio fMP4 outputs,
   add signed audio routes and readiness checks, attach HLS audio groups, and emit
   separate DASH AdaptationSets. Store actual per-track durations: AAC boundaries
   need not match video boundaries exactly. Test initial playback, seek, video-only
   sources, cancellation and stale-generation refusal through both formats before
   enabling a passing DASH gate.
2. **Qualify browser coverage and expose unsupported playback clearly.** Native
   HLS behavior differs by engine. Test Safari on its own media stack and Firefox
   explicitly; choose a maintained MSE fallback only if those are supported target
   browsers. Require actual subtitle cue activation and visible rendering on each
   target, including after seek and rendition changes.
3. **Verify codec declarations and signed-URL lifetime.** Compare each rendition
   CODECS profile/level with its actual initialization data; current declarations
   use encoder/tier heuristics. Test requested expiration through child playlists
   and fragments: they currently receive independent default 24-hour signatures.
   Tamper and generation tests do not establish that a requested top-level lifetime
   bounds every derived URL. The [child signer](../src/Transcode/Infrastructure/Auth/HmacStreamAuthAdapter.php)
   supplies that default, while the [signing controller](../src/Transcode/Interface/Controller/StreamSigningController.php)
   accepts a requested parent lifetime. Define the contract before treating the
   difference as either intended delegation or a defect.
4. **Extend real-media fixtures deliberately.** Add HDR10/HLG tone mapping, VFR,
   nonzero source start timestamps, discontinuities, malformed/truncated inputs,
   alternate audio selection, additional multichannel layouts and corrupt subtitle
   streams. Validate colors, timestamps or signals, not merely successful exits.
5. **Measure adaptation and startup.** Test bandwidth-constrained rendition
   switching and compare declared bandwidth with actual fragment peaks. Record
   time to first decoded frame, seek latency, CPU, memory and temporary disk usage.
   Subtitle extraction can currently add up to 30 seconds to initial readiness;
   a published-track revision protocol could permit earlier video startup while
   making later subtitles discoverable. Serving still synthesizes six-second
   segment durations from source duration; reading emitted fragment timing would
   remove that assumption for variable frame rates, unusual timestamps and tails.
6. **Qualify hardware through the admitted worker.** Native execution currently
   chooses software encoders deliberately. Correct argv handling does not enable
   or certify GPU device exposure, filter transfers, driver behavior or failover.
   Test each intended accelerator with its real worker resource limits.
7. **Remove or integrate the separate player package.**
   `ui/web/packages/baander-player` is not imported by the production movie hook.
   Its obsolete stream URLs, partial DASH timeline interpretation and discarded
   subtitle bodies are debt in an unwired implementation. Establish one player
   ownership path before investing in both. Existing mock/parser tests do not
   qualify that package for production.
8. **Finish cache and operational acceptance.** Cold/warm starts, disk-full
   behavior, eviction during active playback, long-run growth, truncated source
   recovery and concurrent quality changes need bounded real-stack tests. Keep
   the earlier cache-sweep acceptance open until its workload is exercised.

## Reproducing the checks

Run focused backend tests in the disposable CI runner:

```bash
BAANDER_TEST_IMAGE=baander-ci:test \
  bash scripts/test-backend-container.sh \
  tests/Unit/Transcode tests/Integration/Transcode \
  tests/Integration/Transcode*Test.php tests/Functional/Transcode
```

Build current frontend assets before the production worker, then run signed media
and actual browser playback together:

```bash
(cd ui/web && corepack yarn install --immutable && corepack yarn build)
docker buildx build --target worker --load -t baander-worker:test .
BAANDER_TEST_IMAGE=baander-worker:test \
BAANDER_TEST_TWO_COMMAND_CHECKPOINT="$PWD/scripts/test-two-command-browser-checkpoint.sh" \
  bash scripts/test-two-command-deployment-container.sh
```

The strict DASH qualification is separate and currently fails. It must pass
before DASH playback can be accepted:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_DASH_QUALIFICATION=1 \
  bash scripts/test-two-command-deployment-container.sh
```

For the additional instrumented control cases:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_OPERATOR_IN_CONTAINER=1 \
BAANDER_TEST_TWO_COMMAND=1 BAANDER_TEST_MEDIA_CONTROL_OBSERVATION=1 \
BAANDER_TEST_TWO_COMMAND_CHECKPOINT="$PWD/scripts/test-two-command-browser-checkpoint.sh" \
  bash scripts/test-worker-operator-container.sh
```

Keep the runner's JSON receipts, browser timeline, screenshots and cleanup evidence
with each result. Successful manifest parsing or HTTP 200 alone is not playback
acceptance. Final counts and execution limits belong to the dated checkpoint in
`ROADMAP.md`.
