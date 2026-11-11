# Media and Playback Qualification

Verification of native FFmpeg segment encoding, two-command worker media deployment, browser playback checkpoints, shared media control capacity, stream selection, household deployment limits, and the Playwright playback destination specs.

## Native Segment Encoder Suites

`SegmentEncoderMediaTest` generates deterministic colour patches, probes them,
encodes through the actual movie command builder and independently decodes the
published fragment. H.264 and HEVC cases compare interior Y/U/V samples at the
same pixel format and range, covering fresh output and a nonzero source start.
BT.2020 SDR must retain its primaries, transfer, matrix and range without tone
mapping; PQ and HLG cases require decoded software tone mapping to BT.709.

This covers transfer-based HDR classification and the software encode path, not
display-gamut certification, a complete HDR reference-vector suite, Dolby Vision
or hardware acceleration. Native colour checks do not establish new HTTP or
browser playback acceptance.

`SegmentEncoderSourceSelectionMediaTest` generates a moving movie with distinct
attached artwork and checks both cover-first and cover-last stream ordering with
FFprobe. It runs the production probe, filters and command builder, reads the
published presentation, then independently decodes each initialization/media pair.
Fresh 14-second output and the eight-second tail after source second six must
retain the movie dimensions, frame rate, duration and changing frames. A cover-only
source must fail the required video mapping and publish no movie fragments.
These cases qualify native source selection through publication; they do not add
browser playback acceptance or establish CMAF conformance.

`SegmentEncoderAudioProfileMediaTest` covers Opus and AAC on fresh 14-second
output and the eight-second tail from source second six. Each case requires
closed MP4 video/audio tracks with continuous native timelines, checks the actual
MPD and initialized codec, and independently decodes every published fragment.
Audio must retain stereo at 48 kHz. Decoded duration allows 50 ms per fragment
and 100 ms overall for priming and padding. Video colour markers and audio
frequency changes distinguish the requested source interval from an incorrect
restart; positive audio energy and changing video frames are required.

`SegmentEncoderSparseMediaTest` covers 14 sparse movie cases. Software H.264
and HEVC retain the covering red, green or blue source frame on fresh output
and restarts at source seconds six and 24. Other cases cover fractional cadence,
a nonintegral final hold, video-only output including a silent six-second restart,
and audio delayed by eight seconds in MOV. Independent fragment and HLS decoding
checks continuous timelines, zero-based one-second video timestamps, the complete
held-frame sequence and positive audio signal. Every growing playlist prefix and
completed playlist must retain the seven-second target. Two paced H.264/HEVC
cases require independently decodable video and audio, followed by a larger video
publication edge while the encoder remains running and the presentation remains
incomplete. The later edge must arrive before second 29 of a thirty-second paced
input, leaving a one-second margin before EOF to exclude final-flush output.
Each producer has a 45-second process bound.

The sparse path applies only to finite positive probed rates below one fps and
normalizes video to one fps. Transitions round upward so future content is not
shown early; terminal extension must be nonnegative and less than one second,
with a small numerical tolerance. Delayed-audio tests require approximately two
seconds of remaining delay after a six-second restart. Their indexed MOV source
establishes video origin zero. This does not establish a general repair for
Matroska sources whose reported start time disagrees with their video packets,
or arbitrary variable-rate gaps.

`SegmentEncoderTimestampOriginMediaTest` covers 19 sparse Matroska source-origin
cases through software H.264/HEVC output. Verified origins zero and four, delayed
and matched audio, fresh output and restarts at source seconds six and 24 must
retain the full colour holds, duration, audio delay and subtitle source interval.
Three audio-first cases require the first movie packet after packet sixteen and
within the bounded 128-packet prefix. Independent fragment/HLS decoding and
production subtitle extraction check media clocks and embedded cues. Two Opus
cases supplement the AAC controls. Complete HLS audio decoding must retain the
full presentation duration, near-silent initial samples and audible tone onset;
a correct MPD delay or MP4 edit list alone cannot satisfy this check.

The shared video probe reads at most 128 packets across all streams. Origin
evidence comes only from the first observed packet of the selected non-attached
movie stream. The qualified source is Matroska H.264 with explicitly zero B-frames
and finite positive cadence below one fps. Its first packet must be a key packet
without a discard flag and have finite nonnegative PTS; reported format start
must be at or after that PTS and format duration must be greater. A mismatched
reported start or positive verified origin selects an explicit common timestamp
anchor. Matched zero origins keep the existing path.

For this Matroska path, duration is normalized once by subtracting the verified
origin. Media encoding seeks in absolute timestamps and shifts video and audio
by the same origin-plus-source-position offset. Subtitle extraction subtracts
only the origin; existing timeline rebasing then applies the requested seek.
With a verified anchor, a positive sparse restart before one nominal frame
interval reads from the file beginning instead of input seeking, preserving
leading physical audio while retaining the requested offset and trimming. Later
restarts keep fast input seeking. The nominal interval is not a proof of general
variable-rate behavior.
Source-origin qualification remains unavailable for missing movie-packet
evidence, malformed metadata, negative origins, B-frame input, other containers
and general variable-rate gaps.

Every movie with audio applies `aresample=first_pts=0` after any negative-preroll
trimming and profile filters, whether or not the probe has a verified timestamp
anchor. Positive leading delay becomes actual encoded silence; negative codec
priming is trimmed without asynchronous compensation. Ordinary sources retain
their existing source-origin and seek commands, and video-only output gains no
audio options. HLS therefore receives actual silent samples before the intended
tone instead of relying on an MP4 edit list. The audio-only transcoder is outside
this movie-path normalization.

For the qualified cases where selected audio ends at or before video,
`apad=packet_size=1024` follows normalization. When the selected video stream
states its own end (MOV or MP4 stream timing, or the Matroska track duration
tag), `whole_dur` pads the audio to that end and `atrim` cuts it there, so both
tracks finish together. A source whose end is only a container estimate keeps
output `-shortest`, so its audio still stops at the video end. On FFmpeg 7, `-shortest` buffers up to ten seconds of frames before
the first segment is published and drops the final AAC frame that crosses the
video end. Commands with audio also set `-max_interleave_delta 0`: padded audio
runs ahead of the movie, and without strict interleaving the DASH muxer can flush
it before the first video packet and cut empty leading audio segments. When
selected source audio ends early, real silence must cover the remaining video,
including sought output with no source audio packets left. The producer must
terminate without losing the video tail. Video-only commands omit padding,
trimming, strict interleaving and `-shortest`; separate subtitle helpers do not
set duration. Longer-soundtrack endpoint resolution uses qualified
selected-video metadata; missing or invalid evidence retains the container
estimate.

One FFmpeg process reads the same source through two inputs when audio exists.
Video input zero retains hardware options, fixture pacing and source selection;
unpaced audio input one has two decoder threads and disables video, subtitle
and data decoding. The selected audio ordinal maps from input one. Both readers
share the logical source position and verified-origin translation. Sparse audio
requests one nominal source-frame interval before the generation start, clamped
to zero; a zero-relative position omits its input seek. Sparse audio retains
preroll when it seeks. Unanchored audio receives the negative difference
between the generation start and its earlier input seek, preserving the
generation clock. Verified-origin audio keeps the existing common offset, and
existing trimming removes negative local samples. Video seeking and ordinary
accurate audio seeking are unchanged. Cue alignment may decode farther back
than the requested nominal interval, so it is not a universal I/O bound.
Independent audio EOF lets padding publish playable fragments while video
remains active. Video-only commands retain one input; cancellation stops the
one process and both source reads.

`SegmentEncoderAudioDelayMediaTest` covers sixteen ordinary 25-fps MOV cases
with video beginning at zero and no verified source origin. H.264/HEVC output
and AAC/Opus profiles cover fresh output and six-/24-second restarts of a
thirty-second source with audio delayed eight seconds. Independent fragment and
HLS decoding must retain the full video grid, held colour intervals, continuous
tracks, complete PCM including silence, measured tone onset and the audible tail.
Controls cover 44.1 kHz AAC with DRC, matched PCM audio and encoded AAC with
negative initial priming timestamps. Matched-source waveform correlation and
bounded lag check content alignment without requiring byte-identical PCM.

Ordinary B-frame timestamps are checked from bounded native MP4 decode times,
sample durations and signed composition offsets, with the actual timescale and
unshifted initialization edit. FFprobe timestamps remain diagnostic evidence;
subtracting its first reported PTS must not conceal a displaced presentation.

`SegmentEncoderDefaultAudioMediaTest` drives the production probe, stream
manager, encoder and HLS renderer with distinguishable source audio. Commentary
is 220 Hz, mono/44.1 kHz/English; later default dialogue is 880 Hz,
surround/48 kHz/Danish. Selection uses the first marked default in discovery
order, falling back to the first audio track when none is marked. Multiple
defaults choose the first marked track; no audio has no selected ordinal.
The ordinal counts audio streams and does not use the global FFprobe index.
Discovered order stays intact, while raw/serialized probe reconstruction and
direct construction derive the same selected codec, channels, sample rate and
layout for filters and mapping. Native MPD and production HLS/DASH manifest
checks establish selected language; independent HLS probes check actual codec,
sample rate and channels alongside decoded tone identity.

Independent fragment and full HLS decoding require the selected tone and complete
fresh/seek-six tails for AAC and Opus. No-default and multiple-default controls,
eight-second delayed dialogue and delayed unselected commentary check selection
and timing together. Fixture preflight verifies actual defaults and layouts:
MOV PCM supplies the marked-default cases, and Matroska FLAC preserves an
explicitly absent default. The chosen audio keeps its own timestamps and existing
leading-silence normalization; video-origin qualification remains independent.

`SegmentEncoderShortAudioMediaTest` drives the real probe, stream manager,
encoder and presentation reader with fourteen-second video and short source
audio. Independent video-fragment and full HLS PCM decoding require complete
decoded video, including frame count, colour sequence and track duration, plus
tone before the audio boundary and measured silence through the tail.
AAC/Opus fresh output and exhausted-audio seeks, leading delay plus trailing
silence, a selected second track ending before an unselected track and matched
audio controls check selection, coverage and bounded termination.
An encoded AAC source control verifies a restart after its final source packet.
Two paced AAC/Opus cases require increasing, independently decodable joint
video/audio prefixes while the presentation remains dynamic and the producer
runs, followed by complete final tails. A cancellation case stops the same
producer after playable prefixes appear. Receipts retain publication timing,
closed audio/video lead and process memory; these fixture measurements do not
establish general resource guarantees. A separate longer-soundtrack audit shows
the final producer reaches the actual video endpoint. Current probe duration
uses qualified selected-video timing when available; the earlier container-based
failure is retained as historical evidence, and the estimate fallback remains
unqualified for exact-end behavior.

`SegmentEncoderVideoEndpointMediaTest` covers fourteen selected-video endpoint
cases through the real probe, stream manager, encoder and presentation reader.
MOV/Matroska sources with longer audio, B-frames, delayed video, positive starts
and sparse held frames must retain complete decoded video, bounded audio and
exact-end coverage using the DTO duration. Fresh and near-end AAC/Opus cases
check source identity, content and finite process completion. Distinct audio
markers at source seconds 20 and 26 verify the clock through sparse seeks,
including the retained Matroska cue-layout regression.

The [Selected video endpoint](../../../CONCEPTS.md#selected-video-endpoint) uses
the same first non-attached movie stream as encoding. For MOV/MP4, its start at
or after the normalization origin plus its positive duration span gives the
endpoint. Valid rational time-base values take precedence over textual seconds,
allowing only FFprobe's decimal rounding differences and rejecting
contradictory metadata. Matroska uses the selected track's strictly parsed
`DURATION` tag as an absolute endpoint; inferred stream duration is not
independent evidence. Normalize once by the verified source origin, or by an
ordinary finite nonnegative format start without inventing an origin. A
qualified result must be finite and positive.

Missing, malformed or unqualified timing retains the prior container-duration
estimate, including its existing verified-origin adjustment. That fallback does
not establish an exact video end. The bounded 128-packet prefix cannot determine
an unknown endpoint; no second probe, full-file scan, frame-rate inference or
endpoint cache is added. Serialized duration preserves the resolved value without
another origin subtraction. Fresh worker playback metadata changes; existing
catalog records are not backfilled or reset, and native manifest clocks stay based
on actual media.


## Two-Command Deployment and Browser Checkpoint

The combined two-command runner exercises shared media with the production worker
image:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-two-command-deployment-container.sh
```

It has a 900-second outer deadline. For an attached browser checkpoint, install web
dependencies and provide the pinned Playwright Docker image, then enable the
optional hook:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
BAANDER_TEST_TWO_COMMAND_CHECKPOINT="$PWD/scripts/test-two-command-browser-checkpoint.sh" \
  bash scripts/test-two-command-deployment-container.sh
```

The hook uses the configured `browser` image role and the checkout's
`playwright-core` dependency. In this drill the browser container joins the
drill's Docker network and forwards its own loopback port 9501 to the `web` alias,
so it reaches the application at its loopback origin and follows a replaced web
container. Called without a network, the hook uses Docker host networking. A
custom `BAANDER_BROWSER_IMAGE` requires
`BAANDER_BROWSER_PLAYWRIGHT_VERSION`; preflight checks that declaration against the
installed package before browser creation. The default reference and compatible
version live in the tooling catalog. Set
`BAANDER_TEST_BROWSER_ENGINE` to `chromium` (the default) or `firefox`; unknown
values fail before the deployment runner creates resources. Chromium keeps its
`channel: 'chromium'` launch option; Firefox uses its own engine. Both run
headless. Receipts retain the actual engine and version, or the requested engine
and error if launch fails.

The preflight checks H.264/AAC support through Media Source Extensions and records
native HLS support separately. Playback uses the product's HLS.js player through
MSE, without media feature overrides. A capability report or HTTP response does
not prove decoding: the gate requires current-generation delivered media, decoded
frames, an advancing clock and real seekable ranges.
Growing movie playback waits for newly published media rather than moving its
clock to a live sync point; the source cursor remains authoritative.

Paused restoration retains the browser's raw frame counter. If it is zero, a
fresh draw of the current video into a 16-by-9 canvas can establish the fixture's
expected colour pattern. The sample is tied to the current element, source,
media time and dimensions. This pixel fallback rejects blank and black samples;
a positive raw counter remains sufficient frame evidence. The fallback is
specific to this fixture and covers Firefox displaying a paused decoded image
while reporting zero quality-counter frames. Running playback still requires
the raw frame counter to increase alongside the clock.

Set `BAANDER_TEST_BROWSER_CLIENT_CONTROLS=1` to include client controls. With that
flag, each engine runs all eight phases: authentication, attachment, client
controls, paused seek, web restart, worker containment, worker recovery and
completion. Each run uses the 900-second outer deadline. CI runs Chromium and
then Firefox against the same built worker image, with separate
`var/test-runs/two-command-chromium` and `var/test-runs/two-command-firefox`
artifacts. Run the local engine checks sequentially as well; use
`BAANDER_TEST_ARTIFACTS` to keep their evidence separate. CI configuration alone
is not evidence that either browser passed.

Each subtitle-continuity scenario selects its choice once through the TextTrack
API, then only observes modes and active cues. The nondefault Forced track must remain
exclusively showing through a source seek and paused capability renewal; Off must
leave every subtitle track disabled through a paused seek and running renewal.
Selection follows the source stream key, preserving identity between tracks that
share a language. The remembered choice belongs to the current movie playback
owner and is not durable across page reloads. If a chosen track is temporarily
absent, captions stay off while its identity is retained.

Screenshots support inspection, but these checks do not automate the native
caption menu or prove caption pixels. Safari/native HLS remains unqualified.
Choosing Off when every available track is already disabled creates no observable
TextTrack mode change; in that case an absent-track preference remains retained.

After worker recovery, a 60-second completion/replay check seeks near the known
source end through the real source slider and plays the tail at normal speed.
It requires a genuine native ended event, completed current-set audio/video
playlists and delivered final fragments. The ending pause is dropped before
forwarding; at least two fresh canonical running polls and the attached signed
capability's actual expiry must leave the same completed frame and cursor stopped.
Native Play must then submit one source-zero seek, attach a new execution and
receive current-generation media with advancing time and raw frame counts. The
check does not force the clock to EOF, synthesize ended or accelerate playback.
It qualifies completion after a user seek, not uninterrupted full-source playback
or byte-exact decoding. The eight phases and phase deadlines remain unchanged;
the outer deadline is 900 seconds.

The replay that ends the completion check starts a 24-second edge-chasing window.
The source-zero seek has just restarted every encoder, so the published edge runs
away from a playhead that has only begun to move. A player that treats that edge
as a target cuts forward here, and a player that forwards its own internal seeks
to the server restarts the encoders again on every correction. The window samples
the media clock and the wall clock in one page task. Between any two samples,
counted from the moment the committed seek was sent, the clock may gain at most
one second on the wall clock. The player's stall nudges total at most 0.6 seconds
and the smallest step toward the published edge is one six-second segment, so the
bound separates the two.

An outrunning producer does not by itself make the player correct anything. The
fixture's generated media gave it nothing to repair: in a run with the old
forwarding restored, twelve seconds of playback sent no seek although the producer
had published 41.67 seconds within 2.6 seconds of its restart. So halfway through,
the window makes the correction itself. It assigns the media clock half a second
ahead inside buffered media, as the player's own gap and stall corrections do, and
allows that half second in the bound. The player must record the assignment as a
`baander:native-seek` mark. For the whole window the job, the session and the
generation of every rendition in the signed set must stay unchanged, and no seek
may reach the server. With the forwarding restored, the same assignment sent a
seek within a millisecond and the check failed on it.

The window counts only if production outran playback. Some video playlist of the
restarted generation must hold at least 1.5 times as many published seconds as
wall seconds have passed since the seek was sent, and the published edge must
still lead the playhead when the window ends. Publication trails encoding, so
this never overstates the encoder's rate. A host too slow to reach 1.5 fails the
check; it does not pass it idly. The `worker-recovered` checkpoint request budget
is 180 seconds to hold the window. `cursorAuthority` in `result.json`,
`failure.json` and `playback-timeline.json` retains the samples, the correction,
the playlist observations with their rates, the player's seek marks and the final
lead.

The production fixture also submits a captured old-generation native seek after
an explicit paused seek, while the worker is live and while it is absent. It must
leave desired position, execution generation and control revision unchanged. This
checks the server's fence independently of whether a browser reproduces the race.

The hook retains browser evidence with the drill artifacts and removes its browser
container during cleanup. The standard runner does not require this hook. The
recorded gate before the paired deployment change passed authenticated playback,
paused seek,
web restart, worker containment and recovery, and final completion. It also proves
both stale-generation probes leave paused state unchanged, checks fourteen
contained process identities with no survivors and verifies cleanup. Ordinary
[autoscaling](workers-and-messaging.md#ordinary-worker-autoscaling-qualification) and saturated
[media controls](#shared-media-control-qualification) have dedicated gates below;
regional qualification remains separate.

### Iterating on the drill locally

The drill runs the worker image as built. Server code under `src/`, the fixture
programs under `tests/Fixtures` and the web bundle in `public/` all come from the
image, so a change to any of them needs a new image. Build the bundle first,
because the image build copies it from the checkout:

```bash
(cd ui/web && corepack yarn build && node scripts/verify-build.mjs)
php scripts/build-application.php --buildx --target worker --tag baander-worker:test
```

The checkpoint hook copies the browser probe,
`ui/web/tests/e2e/two-command/browser.mjs`, and `playwright-core` from the checkout
into the browser container on every run, and the host-side orchestration under
`scripts/` and `packages/baander-tooling` runs from the checkout as well. A change
to the probe or to that orchestration needs only another run:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
BAANDER_TEST_BROWSER_ENGINE=chromium BAANDER_TEST_BROWSER_CLIENT_CONTROLS=1 \
BAANDER_TEST_ARTIFACTS="$PWD/var/test-runs/two-command-chromium" \
BAANDER_TEST_TWO_COMMAND_CHECKPOINT="$PWD/scripts/test-two-command-browser-checkpoint.sh" \
  bash scripts/test-two-command-deployment-container.sh
```

`BAANDER_TEST_POSTGRES_IMAGE` overrides the database image, which defaults to
`baander-database:latest`. On a development host the worker image build took 10 to
20 minutes and the full drill about ten, with client controls reached after about
seven. The [endpoint check](#selected-video-endpoint-playback) took about five.

Browser evidence lands in `browser/` under the artifacts directory: `result.json`
after a pass, `failure.json` and `failure.png` after a failure. Beyond what
`result.json` holds, `failure.json` records the error, the player's `baander:*`
performance marks, the visible alert and status texts, page errors, console errors
and warnings, and the recorded API responses. Read the marks first: they give the
order in which the player committed, queued and sent each seek.

Run the image build and each drill one at a time on an otherwise idle host. The
drills run real encoders against deadlines, and the
[media-control drill](#shared-media-control-qualification) applies CPU pressure on
purpose. On 2026-11-10 it ended with exit status 137 while image builds ran beside
it, and passed when run alone.

The `attach` phase of the two-command drill has the step most exposed to load. The
worker encodes the fixture's 30-minute track to MP3 on one thread while web streams
the growing file, and the fixture waits 110 seconds for that stream to end. On an
idle development host the encode took about 48 seconds, so the step fails once the
encoder runs slower than about 0.44 of that speed. On 2026-11-11 the stream missed
the 55-second wait the fixture had then, on a host that was also running builds and
benchmarks, and the drill passed when run alone.

Each phase receipt, `<phase>.json` in the artifacts directory, records under `host`
the load averages from `/proc/loadavg` and the first line of `/proc/pressure/cpu`,
where the kernel provides it, at the start and at the end of the phase. The `attach`
receipt adds `audioSeconds`, the time from the audio request to the end of its
stream. A failed phase writes the same host load to `<phase>.stderr`, and the audio
wait's timeout message states the bytes received, the bytes expected and the
seconds elapsed, which shows whether the encoder was slow or the stream had stalled.


### Shared media control qualification

To exercise controls while native capacity is occupied, build the current worker
image and run the dedicated drill:

```bash
php scripts/build-application.php --buildx --target worker --tag baander-worker:test
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_OPERATOR_IN_CONTAINER=1 \
  BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  BAANDER_TEST_CHECKOUT_IN_IMAGE=1 BAANDER_TEST_USE_IMAGE=0 \
  bash scripts/test-worker-media-control-container.sh
```

The runner selects ordinary maximum two, the combined web/worker fixture and
`BAANDER_TEST_MEDIA_CONTROL_OBSERVATION=1`. It inherits the operator runner's
900-second deadline, resource preflight and cleanup. Set `BAANDER_TEST_ARTIFACTS`
to retain evidence at a chosen path; the default is
`var/test-runs/media-control-*`. Previously recorded pressure, browser and cleanup
evidence covers single-intention pause/seek/reconnect and reattachment with the
earlier encoder output. Rapid native scrubbing also retains the latest queued
target across an in-flight seek, with pause/resume ordering and generation
authority preserved; see
[Playback controls and generations](../../part-1-operator-guide/media/transcoding.md#playback-controls-and-generations).
That interaction contract has focused backend and player-hook regressions; the
recorded single-intention drill does not establish browser pressure coverage of
rapid scrubbing. The launch observer matches the current DASH `manifest.mpd`
output. A new pressure result still requires the complete live drill.

Observation mode builds a disposable fixture derived from the production image.
Its test-only FFmpeg wrapper records each video encoder launch before executing
the original native binary with the same PID, start ticks, arguments and streams.
This is an instrumented runtime test; the immutable production-image contract
remains a separate gate. Observation defaults to zero and is incompatible with
`BAANDER_TEST_USE_IMAGE=1`, which forbids rebuilding the worker fixture.

The drill's fixture profile has three video slots and one audio slot. The drill
fills them, queues ordinary work and applies execution CPU pressure for at least
60 seconds; the pressure processes run at nice 10 and score 600, as encodes do. The audio encoder is held
after initial output to keep its slot occupied; this does not measure sustained
audio throughput. Controls exercise running and paused attachment, ticketed socket
reconnect and replay, stale generation refusal, paused seek, active and pending
cancellation, and deliberate admission after cancellation. Retired init and media
requests must fail even while their old files remain present. Subtitle authority
and readiness-wait races need their separate deterministic coverage.

`qualification.json` records control acknowledgement and heartbeat p99 and maximum,
seek readiness, cancellation retirement, occupancy, sampling gaps and ordinary
progress, together with each unit's absent limits and the sampled kill order. Both p99 limits are one second and both maximum limits are two seconds;
seek and cancel request handling also have two-second limits. Seek readiness and
exact encoder retirement after cancellation each have a 30-second watchdog.
Seek readiness ends when both audio and video init/fragment bytes are available,
recorded with a monotonic clock before certification. HTTP retrieval uses bounded
concurrent batches: two playlists, then four init/fragment resources. The fixture
fetches both tracks before running ffprobe, decoding, signature-tampering and
subtitle checks.
All those checks remain mandatory: late bytes, corrupt media or accepted tampering
fail the qualification. Certification time does not extend the availability limit.
`media.json` holds the scenario receipts; `native-launches.jsonl` records each job,
generation, output directory, process identity and live predecessors at launch.
Process identity includes PID, start ticks, PID namespace and hostname. The ledger
checks duplicate attempts and overlapping predecessors, and attachment compares the
launch records before and after each concurrent attachment batch. Periodic process
counts alone cannot prove those properties. Keep the container inspections, native verification,
control/execution samples, backlog observations, operator log and cleanup receipts
with these results, including failed runs.

#### Per-job media cost

Cost mode measures each media job kind without the control drill's CPU
pressure. Add `BAANDER_TEST_MEDIA_COST=1` to the command above, and set
`BAANDER_TEST_MEDIA_COST_ENCODER` to `libx264` (the shipped default) or
`libx265`. The runner writes that encoder into the fixture image's
`.env.prod.local`. The execution unit has no limits; the preflight needs ten
permitted CPUs and 5 GiB of host memory beyond the drill's 15 GiB for eight
1080p encoders.

Preparation generates a 120-second 1920x1080 30 fps H.264/AAC source with mild
temporal noise and three 600-second 44.1 kHz stereo FLAC tracks before the
measured window. The client then runs these phases in order:

1. An idle baseline.
2. One 1080p encode, one stream copy, and one Opus 128k, AAC 192k and MP3 192k
   rendition, each alone.
3. Three 1080p encodes with queued renditions and a fourth video waiting.
4. Four copy requests at once.

The drill's profile admits only three video jobs, so the checkpoint then replays
the recorded 1080p encoder command eight times at once inside the execution unit.

Samplers in both worker units record each process's peak RSS (`VmHWM`), thread
count and per-thread CPU ticks. A host sampler records `cpu.stat` and
`memory.stat` for web, PostgreSQL, Redis, control and execution. The FFmpeg
wrapper adds `-progress` output, so speed is output seconds per wall second over
the window in which output advances. The window starts after three seconds of
output and ends before the worker's 72-second buffer-ahead throttle stops an
encode. `media-cost.json` holds per-phase figures, a summary and the host's load
average at each phase boundary.

The recorded run on 2026-11-03 used an AMD Ryzen 7 5800X3D (8 cores, 16 threads,
`performance` governor, boost on, 3.44 GHz scaling maximum, 62.7 GiB) and
FFmpeg 5.1 in the Debian 12 worker image, before the move to Debian 13 and
FFmpeg 7.1. Other heavy host work was paused, and the one-minute load average was
1.4 before each run. The run predates the removal of unit limits: the execution
unit was confined to eight logical CPUs and 9216 MiB, on CPUs 1–8, which are seven
cores plus the SMT sibling of the control unit's CPU 0. One H.264 run and one HEVC run each measured every
phase; the table gives the range across both runs:

| Job (1080p30 source) | Peak RSS | Threads | CPU-s per output s: alone / 3 slots / 8 replayed | Speed: alone / 3 slots / 8 replayed |
|---|---|---|---|---|
| H.264 encode (`libx264`) | 531 MiB | 8 | 1.49 / 1.65 / 1.72–1.74 | 1.71x / 1.32–1.33x / 0.55–0.58x |
| HEVC encode (`libx265`) | 535 MiB | 9 | 3.04 / 3.29–3.31 / 3.33–3.56 | 0.70x / 0.62–0.63x / 0.28–0.30x |
| Video stream copy | 72 MiB | 1 | under 0.001 | over 1000x |
| Opus 128k rendition | 53 MiB | 1 | 0.008 / 0.009 | 119x / 103–114x |
| AAC 192k rendition | 56 MiB | 1 | 0.019 / 0.020 | 52x / 47–50x |
| MP3 192k rendition | 52 MiB | 1 | 0.014–0.015 / 0.015–0.016 | 69–70x / 56–66x |

In this run SegmentEncoder capped the encoder's own threads at two; the
FFmpeg process as a whole used more CPU. An H.264 job used 2.56 CPUs alone. Its two x264
threads used 0.9 CPU each, one thread 0.43, and decoding, audio and muxing threads
0.08–0.13 each. An HEVC job used 2.13 CPUs: its two x265 pool threads used 0.99
CPU each and every other thread 0.05 or less. Under that cap, HEVC at 1080p
stayed below real time.

A libx265 thread sweep ran alone on the same CPUs, outside the drill, with the
recorded command and `-threads:v` set to the pool size. It gave 0.72x real time
with `pools=2:frame-threads=2`, 1.02x with `pools=3`, 1.06x with
`pools=3:frame-threads=3`, 1.29–1.31x with `pools=4` and frame threads 2–4
(3.93–3.95 CPUs), and 1.72x with `pools=6:frame-threads=3` (5.38 CPUs). Every
setting cost 2.96–3.13 CPU-s per output second; four pool threads is the
smallest setting above 1.2x. The one-minute load average was 2.8–4.8 during
the sweep.

That source was synthetic. On 4 November 2026 the same sweep ran on real film
content, on an otherwise idle host, in the Debian 13 worker image with FFmpeg 7.1.
It encoded 60-second excerpts at 60, 300 and 480 seconds into the 1080p30 Big Buck
Bunny file, scaled to 1080p at 6 Mb/s with two frame threads and the production
GOP of 180 frames. With four pool threads, every excerpt missed real time, at
0.76–0.87x. Six pool threads ran at 1.07–1.21x for 4.7–5.5 CPU-s per output
second, and eight at 1.18–1.34x for 5.1–6.0 CPU-s per output second. SegmentEncoder
now gives `libx265` six pool threads, the smallest setting ahead of real time on
every excerpt, with two frame threads; libx264 keeps its two encoder threads.

Each media job child in the control unit peaked at 92 MiB with two threads for
video and 66 MiB with one thread for audio. No separate helper process appeared.
The 1080p admission limits also held: a fourth video stayed pending with no
native launch until its startup request returned 503, then ended as `refused`.
A fourth copy request returned 503 with `Retry-After: 2`. Four queued renditions
ran one at a time, and all four completed within the 60-second start wait. The
QoL StreamGovernor reported `learning` with no samples and no active streams in
every phase, so it admitted everything.

Service cgroups, as mean / p95 CPUs and anonymous memory (page cache in brackets):

| Service | Idle | Three 1080p encodes and one rendition |
|---|---|---|
| Web | 0.003 / 0.004, 323 MiB (38 MiB) | 0.023–0.025 / 0.039–0.041, 450 MiB (39 MiB) |
| PostgreSQL | 0.081 / 0.13–0.14, 1.19 GiB (248 MiB) | 0.20 / 0.34–0.37, 2.06–2.39 GiB (251 MiB) |
| Redis | 0.003 / 0.003, 11 MiB | 0.004 / 0.005, 11 MiB |
| Control unit | 0.036 / 0.036–0.089, 336 MiB (16 MiB) | 0.19–0.24 / 0.47–0.56, 483–489 MiB (51 MiB) |
| Execution unit | 0.12 / 0.19, 355 MiB (16 MiB) | 6.89–7.15 / 7.44–7.68, 1.62–1.72 GiB (274–344 MiB) |

These are measurements of one host and one synthetic source, not derived limits.
Real film content, other frame rates and other CPUs change the figures.

#### Hardware encoder cost

Hardware mode measures the application's 1080p hardware encode command on a GPU,
without the worker pair. It needs a worker image built from the checkout and a
render node on the host:

```bash
php scripts/build-application.php --target worker --tag baander-worker:hw-cost
BAANDER_TEST_MEDIA_COST=hardware BAANDER_TEST_IMAGE=baander-worker:hw-cost \
  bash scripts/test-worker-media-control-container.sh
```

`BAANDER_TEST_MEDIA_COST_DEVICE` selects the device (default
`/dev/dri/renderD128`), `BAANDER_TEST_MEDIA_COST_ENCODERS` the encoders (default
`h264_vaapi,hevc_vaapi`), `BAANDER_TEST_MEDIA_COST_STEPS` the concurrency steps
(default `1,2,4,8,12,16,24`) and `BAANDER_TEST_MEDIA_COST_STEP_SECONDS` how long a
step may run (default 150). The runner starts one disposable worker-image
container with no network, no capabilities and Docker's default seccomp profile,
and gives it the device and the group that owns it, as worker creation does for
the execution unit. Inside, the fixture generates the same 120-second 1080p30 H.264/AAC source as cost mode, builds
the encode command with `SegmentEncoder` for each hardware encoder, and starts each
step's encodes together through the native daemon's launcher. Every encode decodes,
scales and encodes on the device and encodes AAC audio on the CPU.

Every half second the fixture records each encode's CPU ticks, `VmHWM`, threads,
`-progress` frame count and the per-client DRM engine time and VRAM from
`/proc/<pid>/fdinfo`, and the device's `gpu_busy_percent`, `vcn_busy_percent` and
`mem_info_vram_used`. `media-cost-hardware.json` reports each step over the window
in which every encode had passed three seconds of output and none had stopped
advancing. A step is real time when its slowest stream runs faster than 1.0x, and
the receipt records each encoder's largest real-time step.

The recorded run on 2026-11-04 used an AMD Radeon RX 9070 XT (16 GiB) through VAAPI
with Mesa radeonsi and FFmpeg 7.1.5 on Debian 13, on the Ryzen 7 5800X3D host above
with other work running (one-minute load average 1.5–6.2). The run added steps 10
and 14 to the default steps:

| Encoder | Streams | Slowest / aggregate speed | CPU-s per output s | Peak RSS | Device VRAM used |
|---|---|---|---|---|---|
| `h264_vaapi` | 1 | 8.98x / 8.98x | 0.031 | 164 MiB | 2.2 GiB |
| `h264_vaapi` | 4 | 2.25x / 9.05x | 0.033 | 164 MiB | 5.4 GiB |
| `h264_vaapi` | 8 | 1.12x / 8.98x | 0.033 | 170 MiB | 9.6 GiB |
| `h264_vaapi` | 10 | 0.91x / 9.14x | 0.033 | 169 MiB | 11.7 GiB |
| `h264_vaapi` | 16 | 0.57x / 9.27x | 0.063 | 163 MiB | 12.7 GiB |
| `hevc_vaapi` | 1 | 13.3x / 13.3x | 0.029 | 185 MiB | 1.1 GiB |
| `hevc_vaapi` | 8 | 1.67x / 13.4x | 0.030 | 192 MiB | 8.5 GiB |
| `hevc_vaapi` | 12 | 1.12x / 13.5x | 0.031 | 195 MiB | 12.7 GiB |
| `hevc_vaapi` | 14 | 1.08x / 15.2x | 0.055 | 196 MiB | 12.7 GiB |
| `hevc_vaapi` | 16 | 0.96x / 15.4x | 0.053 | 200 MiB | 12.7 GiB |

`vcn_busy_percent` read 100 at every step, `gpu_busy_percent` stayed low, and the
encodes' summed engine time equalled the wall time: the video engine bounds the
aggregate rate, about 9x real time for H.264 and 13–15x for HEVC. Device VRAM grew
by about 1.1 GiB per stream and levelled off near 12.7 GiB above twelve streams
without errors. Every encode exited cleanly or ran until the step's deadline. H.264
is real time up to 8 streams and HEVC up to 14. The hardware video default of 12
follows HEVC, the encoder media control selects by default. It sits at the step
that kept a 12% margin; 14 streams left only 8%. These figures are for one GPU, one synthetic
source and one run.

#### Load proof at the default slots

Load mode runs every video and audio job of a full deployment's default slots at
once, through the pooler and with no limits on any container. Set
`BAANDER_TEST_MEDIA_LOAD` to `software` for 8 video and 12 audio slots, or to
`hardware` for the hardware encoder at `/dev/dri/renderD128` with its default of 12
video and 12 audio slots. `BAANDER_TEST_MEDIA_LOAD_SOURCE` names a 1080p video on
the host, such as a 1080p30 Big Buck Bunny file:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_POSTGRES_IMAGE=baander-database:test \
  BAANDER_TEST_OPERATOR_IN_CONTAINER=1 BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  BAANDER_TEST_CHECKOUT_IN_IMAGE=0 BAANDER_TEST_USE_IMAGE=0 \
  BAANDER_TEST_MEDIA_LOAD=software BAANDER_TEST_MEDIA_LOAD_SOURCE=/path/to/1080p.mp4 \
  bash scripts/test-worker-media-control-container.sh
```

`BAANDER_TEST_DEPLOYMENT_PROFILE` selects another profile file; every worker drill
reads its slot counts from it, and without it they keep the fixture's three video
and one audio slot. The load proof needs twelve permitted CPUs and 25 GiB of host
memory. It starts PostgreSQL with the database image's own configuration and the
pooler from the same image, with the full profile's pools.

Preparation copies a 120-second excerpt of the source's picture unchanged, with
AAC audio, and cuts 600-second FLAC tracks from its soundtrack. The client then
warms the playback governor: rounds of every video slot at 1080p, each cancelled
after ten seconds of output, until the saved learning state holds at least the
governor's 50 learning samples and is active. Next, 22 clients hold
50-millisecond transactions on the 20-connection application pool, so no server
connection is idle, and 8 signed-in clients request the album listing without
pause. The run admits every video slot at 1080p and keeps every audio slot busy
with one rendition after another of each lane's track until every video has
completed or the lane has used each of its 15 distinct renditions; a lane never
repeats one, because the cache would answer it without encoding. Each video session reports real-time playback progress, capped at its
encoded output, every two seconds. Finally, with the load stopped, one encode of
each codec runs alone: `libx264` and `libx265` in the software pass, `h264_vaapi` and
`hevc_vaapi` in the hardware pass.

`media-load.json` records each stream's output against wall time and its encode
path, the governor's decisions and their state, native launches, the native
daemon's answer time to a new peer, OOM events, unit containment, PostgreSQL
backends by pool, pooler queues, lease helper lifetimes, web latency, the kill
order and the single encodes. A stream's real-time margin is its steady speed
minus one, measured from three seconds of output to its completion. The verifier
fails the receipt when any of these holds:

- a configured job is missing, failed or did not complete, or no sample saw every
  slot busy at once;
- the governor had not learned before the run, or a run admission was decided
  while it was learning, downgraded or vetoed;
- a unit was contained, drained, restarted or OOM-killed, or native execution
  failed;
- PostgreSQL backends exceeded 20 application, 8 control or 32 in total, or the
  application pool was saturated in fewer than half of the run's samples;
- a lease helper outlived its 1.5-second deadline, or the native daemon took a
  second or more to answer a new peer;
- a stream lacks its margin or encoded on the other path.

Real time under load is recorded, not judged. Check a retained receipt again with
`php scripts/test-worker-media-control-container.php verify-load media-load.json`.

The recorded passes on 2026-11-04 ran on the Ryzen 7 5800X3D and RX 9070 XT
above, with the Debian 13 worker image, FFmpeg 7.1, `libx265` at six pool threads
and a 128-second 1080p30 excerpt of Big Buck Bunny. No other heavy work ran on the
host during either pass. The single encodes ran while the one-minute load average
was still falling from the load run before them: from 33 to 7 after the software
pass and from 18 to 13 after the hardware pass. Both receipts passed every
criterion:

| Pass | Video streams, steady speed | Audio first wave | Peak backends (application / control / direct) | Lease helper maximum, control / execution | Native answer maximum | Web p50 / p95 |
|---|---|---|---|---|---|---|
| Software, 8 + 12 | 8 `libx264`, 0.251–0.256x | 12.0–20.7x | 30 (20 / 8 / 2) | 0.40 / 0.24 s | 9.3 ms | 0.68 / 1.24 s |
| Hardware, 12 + 12 | 12 `hevc_vaapi`, 1.26–1.28x | 31.4–41.8x | 30 (20 / 8 / 2) | 0.19 / 0.18 s | 8.0 ms | 0.41 / 4.45 s |

An active governor decided every run admission, after learning 56 samples in
the software pass and 60 in the hardware pass. It downgraded or vetoed none, and
no unit drained, restarted or recorded an OOM kill. No run sample found an idle
server in the application pool, and pooler waits stayed at or below 31 ms in the
software pass and 36 ms in the hardware pass. In the software pass, each audio
lane encoded 14 or 15 distinct renditions and stayed busy until 420–521 seconds
in, and the videos completed after 503–515 seconds. In the hardware pass, each
lane encoded 6 to 8 renditions and the videos completed after 100–103 seconds.
The web clients completed 5,859 requests in the software pass and 738 in the
hardware pass, and none failed. In the single encodes after the load, `libx264`
ran at 1.08x, `libx265` at 1.30x, `h264_vaapi` at 9.4x and `hevc_vaapi` at
13.7x: every codec ran ahead of real time alone.

An earlier software run on the same day lost its deployment about nine minutes
in. The control unit's autoscaler exited while a backend test suite loaded the
host, and the execution unit followed when it read the control unit's draining
heartbeat. The autoscaler's report named no cause. It now reports its exception
class and location, and a tick that finds its admission lock contended publishes
nothing instead of ending the autoscaler. The passes above ran after both
changes.

Run browser controls against the immutable production worker image through the
combined deployment runner:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
BAANDER_TEST_BROWSER_CLIENT_CONTROLS=1 \
BAANDER_TEST_TWO_COMMAND_CHECKPOINT="$PWD/scripts/test-two-command-browser-checkpoint.sh" \
  bash scripts/test-two-command-deployment-container.sh
```

`BAANDER_TEST_BROWSER_CLIENT_CONTROLS` accepts `0` or `1` and defaults to `0`.
Enabling it selects `client-controls` independently of native launch observation.
It requires `BAANDER_TEST_TWO_COMMAND=1` and an executable browser checkpoint;
the deployment wrapper supplies two-command mode. Invalid values or missing
prerequisites fail before services start. The production runner selects
`BAANDER_TEST_USE_IMAGE=1`, preserving the immutable worker-image contract.
CI enables client controls in its production browser job; the configuration
alone does not establish a passing run.

The browser pauses playback and commits three positions through the movie
player's full-source slider while holding the first real seek response below the
five-second client timeout. It requires exactly the first and latest seek
commands, with the latest using the generation accepted by its predecessor,
and checks the final source cursor, paused state and unchanged job/session
ownership. Slider targets can extend beyond the published seekable window.

The paused-remount check captures its cursor only after decoded, seekable media
is paused, no longer seeking and attached to the current signed rendition set.
It reloads the movie page. The answer to the reloaded page's endpoint
registration must already show no active runner, because the session confirmed
the pause. The check then reopens the player and requires the same session and
job and the source cursor within two seconds; placing that cursor may take at
most one source seek, one generation. Resuming must advance both decoded frames
and the playback clock.

A second reload follows with a start pending. The browser pauses through the
overlay and waits until the pause is acknowledged and its control has answered.
It then holds back every request to `/api/session/ack` without forwarding it,
presses Play, waits until the page has answered the play, and reloads. No
acknowledged pause stands behind the runner now, so only the reload itself can
release it: the page's close report at `pagehide`, a local recovery before the
next page registers, or the registration of the same tab from another page. The
answer to the reloaded page's registration must already show no active runner,
in either engine, and Play must resume within two seconds of the cursor the page
held when it reloaded. The `clientControls` receipts in the browser's
`result.json` record each step as `pause-before-pending-start`,
`pending-start-shown`, `pending-start-registration`,
`pending-start-released-paused` and `pending-start-resumed`.
`pending-start-registration` also records whether the driver saw a request to
`/api/session/close` or `/api/session/recover-local`, which tells which rule
released the runner. A request from an unloading page can escape the driver, so
"not seen" is weaker evidence than "seen".
The run also retains offline refresh recovery, web restart, worker recovery,
subtitle and retired-resource checks. Individual playback waits have a
45-second watchdog; `client-controls` has a 360-second checkpoint request budget.
Inspect `playback-timeline.json` and the browser's `result.json` or `failure.json`
for committed slider positions, snapshots, control timings, network failures and
media events. These client checks remain separate from the saturated-control
drill; a production browser result does not qualify that pressure fixture.
Hardware acceleration, cache-sweep policy and measured FLAC DSP CPU cost remain
separate qualification work. Automatic movie quality switching uses the separate
gate below.

### Selected video endpoint playback

Run the dedicated production-browser check against the production worker image
built from the current checkout:

```bash
BAANDER_TEST_IMAGE=baander-worker:test BAANDER_TEST_DOCKER_SOCKET_PATH=/var/run/docker.sock \
  bash scripts/test-movie-endpoint-browser.sh
```

The wrapper selects `BAANDER_TEST_MOVIE_ENDPOINT=1` in the disposable two-command
stack, with a 300-second outer bound. Its phases are authenticated,
movie-endpoint and endpoint-complete; the existing full lifecycle drill remains
separate. A real 42-second video with 48-second audio must advertise duration 42
through the signing response and actual movie slider. The observer reads those
values from the application rather than supplying fixture-known playback
metadata. It uses the real near-end/exact-end slider controls, natural completion
and source-zero replay with advancing decoded frames. The paired prior-resolver
run must retain its actual 48-second failure.

During the bounded natural-end tail, capability renewal and strict rendition-set
expansion may replace attachments within the same job, session and generation.
Each attachment must supply its genuine metadata/frame baseline before later
advancing frames and natural completion are compared. Source-clock continuity
and existing timing tolerances remain required; an attachment replacement alone
is not a playback defect. This is endpoint/completion/replay evidence, not the
full outage drill or uninterrupted playback of all 42 source seconds.

Keep signed delivery, real publication and verified worker/container cleanup
receipts. This check qualifies the calibrated metadata and source-clock paths;
missing/stale timing, arbitrary muxer tags, additional origins and untested
browser/format combinations remain outside exact-end acceptance. The separate
40 ms capture mismatch and bounded diagnostic follow-up remain unresolved.

### Automatic movie quality switching

Run the separate Chromium gate against the CI image after installing the pinned
web dependencies and Playwright image:

```bash
BAANDER_TEST_IMAGE=baander-ci:local bash scripts/test-transcode-adaptive-browser.sh
```

The default `BAANDER_TEST_AUDIO_PROFILE=streaming_stereo` uses AAC. Select the
accepted Opus profile explicitly:

```bash
BAANDER_TEST_IMAGE=baander-ci:local BAANDER_TEST_AUDIO_PROFILE=opus_stereo \
  bash scripts/test-transcode-adaptive-browser.sh
```

Other profile names fail before container creation. CI runs AAC and Opus
sequentially with separate evidence directories. Readiness records the requested
profile; browser receipts also retain the observed native codec. Both the signed
HLS master declaration and the published audio must agree with that selection.
The same adaptation, signal, throughput, identity and transport checks apply to
both profiles.

The fixture generates a moving 144-second, 24-fps source with audio and
independently encodes 360p and 720p through the production filters and segment
command builder. Both presentations grow during playback; the high producer starts
six seconds later so its published edge lags. Production presentation reading,
signed media delivery and manifest generation serve the actual playback adapter.
In-memory repository and ownership doubles supply fixture state without
application database or Redis access.

The browser changes response-body throughput from 20 to 1.8 and back to 20 Mbit/s
without setting an HLS level or changing the adapter's estimator or buffering
configuration. The three phases have individual bounds of 65, 65 and 90 seconds
within a 180-second browser deadline. Producers have 174-second bounds within
the fixture supervisor's 240-second deadline. Fixture telemetry uses a separate
Node loopback connection, outside the browser's constrained transport.

Each phase requires a fresh signed video request followed by recent native
presentation at its rendition, increasing decoded frames and source time, and a
positive local audio signal. Ordered 720p-to-360p-to-720p presentation must use
one attachment and canonical audio while both producers remain active and
publication edges advance. Completed signed video bodies of at least 128 KiB
and 0.5 seconds, transferred entirely within the constrained phase, must remain
within 1.3 times its configured rate; at least one must reach 0.25 times that rate.
These bounds account for transport-event batching and concurrent audio.

Receipts retain body-transfer observations, requests, publication edges, media
events, buffering periods, screenshots and traces. The gate reports rebuffering;
it does not require stall-free playback. Download cancellation during an
intentional adaptation is evaluated separately from HTTP or other request failure.
Set `BAANDER_TEST_ARTIFACTS` to select the evidence directory. The runner retains
failure evidence and verifies removal of its containers.

This gate qualifies Chromium adaptation through native encoding, production
signed delivery and the playback adapter. It does not requalify the whole
application lifecycle, other browsers, every network profile, DASH switching,
hardware, HDR, alternate audio or formal CMAF conformance. Existing application
databases, Redis and original media are outside the fixture.

### AAC implementation in the suites

The CI image runs the project's FFmpeg build, which carries bAAC, fdk-aac and
FFmpeg's built-in AAC codec. The functional audio stream tests run the real
rendition encoder with the `transcode.aac_implementation` setting at its
default, so their AAC renditions encode with bAAC and AAC sources decode with
it. Unit tests
stub the setting and the codec listing to cover each position and a binary
that lacks one. To run the media suites under another position, set it in the
test database before the run, or change the default in
`TranscodeSettingDefinitions` locally; no environment variable selects it for
the backend suites. The adaptive browser check takes
`BAANDER_TEST_AAC_IMPLEMENTATION` (`baac` by default, `fdk_aac` or `ffmpeg`)
and encodes its DASH audio with that implementation. The image build itself
checks every implementation: its smoke encodes and decodes
with bAAC, and the capability gate requires each implementation as encoder and
as decoder.

### Sparse movie playback

Use the same runner and dependencies with an explicit sparse scenario and AAC:

```bash
BAANDER_TEST_IMAGE=baander-ci:local BAANDER_TEST_VIDEO_SCENARIO=sparse-fresh \
BAANDER_TEST_AUDIO_PROFILE=streaming_stereo bash scripts/test-transcode-adaptive-browser.sh
```

Set `BAANDER_TEST_VIDEO_SCENARIO=sparse-seek` to restart at source second six.
The default scenario remains `adaptive`; unknown names and sparse scenarios with
Opus fail before container creation. CI runs both sparse scenarios separately.

The fixture generates a thirty-second 0.1-fps movie with three held colour
frames and AAC audio. The production probe, encoder, signed delivery and HLS
adapter must preserve red until source second ten, green until twenty and blue
through the natural end. The seek case retains red through local second four
and green through fourteen, then blue to its 24-second end. Native
displayed-frame colour samples must coincide with recent audio signal while the
clock advances. Cropped native frame colours are checked against both
presented-frame time and the callback-entry audio-backed playback clock, with a
50 ms transition tolerance. Fresh signed resources, one attachment, no media
errors or unexplained request failures and stable seven-second media targets
are required. Each scenario has a 120-second browser deadline and retains
growing and completed playlists, samples, screenshots, versions and cleanup
receipts.

The observer copies a centered two-by-two RGBA crop from each owned `VideoFrame`,
checking its native timestamp against callback presentation time. Completed
samples are published in callback order, with at most four copies outstanding;
overflow and capture errors fail the gate. Natural-end evidence cancels callbacks
and bounds copy draining to two seconds. A drain timeout is fatal, with cleanup
still recorded. Each frame must close exactly once; at most 64 error records are
retained.

A failed capture retains detached plain-data diagnostics before the unchanged
one-microsecond timestamp assertion. The record includes callback metadata and
sequence, entry/post-constructor performance and playback clocks, native
timestamp in microseconds and seconds, duration, format, dimensions and visible
rectangle. Constructor failures retain available callback/entry data; crop or
copy failures also retain constructed-frame fields and available copy-stage
clocks. Unavailable values are explicit nulls, and no live frame is retained
after closure. Stage intervals include diagnostic work, so they are not pure
constructor costs or evidence of timing-neutral observation.

The shared `frame-observer.mjs` installer runs in both the browser and controlled
Node tests. CI runs its focused lifecycle tests alongside replacement-classifier
coverage, and the runner copies the same observer into the disposable browser
workspace. Exercise its mismatch, constructor/copy failure, ordered publication
and stop/drain behavior with:

```bash
node --test ui/web/tests/e2e/transcode-adaptive/frame-observer.test.mjs
```

Observer reliability remains bounded by retained receipts. The historical
native snapshot of 24.64 seconds versus a 24.60-second callback lacked decisive
failing timing metadata, so a capture race remains unproven. New diagnostics
improve future failure evidence; controlled values and passing browser runs do
not retroactively establish that cause, fix the mismatch or qualify universal
capture stability. No timestamp tolerance, browser flag or capture retry is
changed.

The fixed diagnostic series at `54e3711c` recorded exactly two
`default-audio-fresh` attempts and one `sparse-seek` control. All passed and
closed all owned frames without capture errors; the historical mismatch did not
recur. Real-run failure retention was not exercised; only controlled mismatches
entered that branch. This is bounded negative evidence, with no extra attempts
or inference about its cause. Exact timings and counts remain in
`var/test-runs/frame-capture-diagnostics/acceptance-summary.json`.

Only explicitly canceled video media requests with `net::ERR_ABORTED` may wait
for classification at natural completion. Server receipts and transfer timing
must establish a completed, uncached, signed replacement from a different known
rendition in the same authenticated set and generation, covering the same native
source interval. Unmatched, ambiguous, stale, cached, wrong-interval or unfinished
replacements fail; other request failures fail immediately. Full playback and
content assertions remain required. The retained strict default-audio rerun had
no deferred cancellations; its positive classification branch is covered by Node
tests, not an exercised abort in that successful browser run. Run the pure
replacement-classification tests with:

```bash
node --test ui/web/tests/e2e/transcode-adaptive/video-cancellation.test.mjs
```

These checks qualify the selected sparse software source and Chromium HLS path.
They do not certify arbitrary variable-rate input, every source container,
hardware, other browsers, DASH browser playback, formal CMAF conformance or the
whole application lifecycle.

### Delayed Matroska playback

Select the verified Matroska-origin scenario with AAC:

```bash
BAANDER_TEST_IMAGE=baander-ci:local BAANDER_TEST_VIDEO_SCENARIO=matroska-delayed-fresh \
BAANDER_TEST_AUDIO_PROFILE=streaming_stereo bash scripts/test-transcode-adaptive-browser.sh
```

Set `BAANDER_TEST_VIDEO_SCENARIO=matroska-delayed-seek` for the six-second restart.
Both require `streaming_stereo` and retain the sparse runner's bounds, signed
uncached resources, stable targets, one attachment and natural completion checks.
CI runs each scenario with separate evidence.

The source has a verified four-second video origin and audio delayed eight
seconds relative to it. Source-relative duration must remain thirty seconds,
or 24 after seeking. Near-silent samples precede audio onset at local second
eight or two respectively; positive audio must follow and remain present during
later colour holds. Signed embedded subtitles must reach the real text track:
fresh cues run at seconds two to four and twelve to fourteen; the seek removes
the first and places the later cue at six to eight. Tests check cue activation
and clearance as well as the served files. A 100 ms observer samples actual
native active cues, cue identity, track mode and times; raw cue-change events
remain diagnostic evidence. A separate seek after natural completion checks
backward re-entry with the same cue objects and unchanged selection and count.

The HLS attachment refreshes native activity if a showing subtitle or caption
cue remains active more than 100 ms after its end while the video is not seeking.
It removes and re-adds the same cue object without changing cue times or mode;
disposal and fatal failure remove the attachment's timing listener. Native HLS
fallback keeps its existing behavior. These cases qualify the verified
packet-origin contract, not arbitrary Matroska origins or missing prefix evidence.

### Ordinary delayed movie audio

Use the same isolated runner with the ordinary delayed source and AAC:

```bash
BAANDER_TEST_IMAGE=baander-ci:local BAANDER_TEST_VIDEO_SCENARIO=ordinary-delayed-fresh \
BAANDER_TEST_AUDIO_PROFILE=streaming_stereo bash scripts/test-transcode-adaptive-browser.sh
```

Set `BAANDER_TEST_VIDEO_SCENARIO=ordinary-delayed-seek` for the six-second restart.
Both scenarios require `streaming_stereo`. The source is a thirty-second 25-fps
MOV with video starting at zero, audio at source second eight, no verified
origin and no embedded subtitles. The fixture verifies that absence explicitly;
Matroska scenarios keep their signed subtitle assertions.

Fresh and restarted playback must retain the red/green/blue source intervals
and natural thirty-/24-second tails. Near-silent samples must precede local
audio onset at eight/two seconds, followed by positive signal through the later
holds. Growing publication, signed uncached resources, canonical audio, stable
targets, one attachment and no media errors or unexplained request failures
remain required. These scenarios qualify the selected software movie path and
Chromium HLS. They do not certify arbitrary timestamp discontinuities, new
source-origin handling, hardware, other browsers, DASH browser playback or
formal CMAF conformance.

### Source default audio playback

Use the isolated runner with the marked-default source and AAC:

```bash
BAANDER_TEST_IMAGE=baander-ci:local BAANDER_TEST_VIDEO_SCENARIO=default-audio-fresh \
BAANDER_TEST_AUDIO_PROFILE=streaming_stereo bash scripts/test-transcode-adaptive-browser.sh
```

Set `BAANDER_TEST_VIDEO_SCENARIO=default-audio-seek` for the six-second restart.
Both scenarios use the thirty-second 25-fps colour source without subtitles.
Commentary starts at zero; default dialogue starts at source second eight.
Preflight checks dispositions, the audio ordinal versus global index, selected
attributes and the dialogue's actual first packet. The selected language must be
Danish, and measured browser frequency must identify 880 Hz dialogue rather than
220 Hz commentary at several post-onset and late-hold windows. The tolerance is
1.5 analyser bins, using the recorded sample rate and FFT resolution.

Complete thirty-/24-second playback, eight-/two-second initial silence,
frame/colour clocks, growing publication, signed fresh canonical audio, stable
targets, one attachment and bounded capture cleanup remain required. The browser
negative control restores only the old producer's ordinal-zero map and
first-track filter chain in a disposable copy, preserving current preflight,
metadata and assertions. It tests the oracle against the demonstrated producer
behavior; it does not run an entire old application stack. This selection adds
no interactive audio controls or new source-origin qualification.

### Movie playback after source audio ends

Use the isolated runner with AAC and the short-audio source:

```bash
BAANDER_TEST_IMAGE=baander-ci:local BAANDER_TEST_VIDEO_SCENARIO=short-audio-fresh \
BAANDER_TEST_AUDIO_PROFILE=streaming_stereo bash scripts/test-transcode-adaptive-browser.sh
```

Set `BAANDER_TEST_VIDEO_SCENARIO=short-audio-seek` for the six-second restart,
where no source audio remains. The thirty-second 25-fps source retains its full
colour intervals and thirty-/24-second natural tails. Fresh playback must contain
the tone before the source audio ends and measured silence afterward; sought
playback must remain silent throughout. Fixture metadata records selected audio
start/end independently of video duration. Existing audible-per-hold checks remain
in force for other scenarios.

Native and worker checks establish encoded audio tail and cursor coverage;
browser checks establish complete playback and measured silence. The browser
gate retains growing publication, signed fresh resources, stable targets, one
attachment and bounded frame-capture cleanup. Native readiness and coverage
requirements remain unchanged.
These cases qualify the selected software movie path and Chromium HLS, not
arbitrary discontinuities, additional source origins, hardware, other browsers,
DASH browser playback or formal CMAF conformance.

### Household deployment qualification

`scripts/qualify-household.php` measures a complete disposable deployment on a
constrained Linux host or VM. The browser driver runs on the invoking machine;
run that machine outside the measured host. Limiting one container on a larger host does not test
whether the complete deployment fits that memory size.

The private JSON configuration has these fields:

| Field | Meaning |
| --- | --- |
| `version` | `1`. |
| `name` | The disposable deployment name; it must match `deployment.name`. |
| `deployment` | A complete deployment configuration in the format of `deploy/household/example.json`, with private secrets and selected production images. |
| `datasetRun` | A stable lowercase fixture identifier, reused for comparable datasets. |
| `host.constraintBytes` | The actual memory constraint of the complete target host or VM. |
| `host.description`, `host.storage` | Hardware, operating-system and storage details for the receipt. |
| `durationSeconds` | Playback observation length; at least `1800`. |
| `fixtureTimeoutSeconds`, `maintenanceDeadlineSeconds` | Preparation and maintenance deadlines; each is at least 3,600 seconds and defaults to `7200`. Maintenance must also cover `durationSeconds`. |
| `observerMemoryMiB` | The measured observer's memory limit; at least 64 MiB, default `128`. |
| `nodeExecutable` | Absolute path to the invoking machine's Node executable. |
| `chromiumExecutablePath` | Optional absolute path to its compatible Chromium executable. |

Keep the selected base images available by a verified tag or repository digest.
Temporary build aliases must not become their only references. Configure the
Docker target through the normal tooling settings; subsequent stages use the
captured target. The target needs usable host `/proc` and cgroup v2 mounts, and
the browser needs access to the configured application origin.

Preparation creates 2,000 songs, 50 movies and 250 episode-shaped video files
before measurement. The episode files use a separate movie-type library; this
does not qualify a TV-library feature. The first scan starts with an empty
catalog, but generation may leave a warm filesystem cache. Provider fixtures
exercise music metadata adapters without external HTTP requests. MusicBrainz
rate limiting remains active, so metadata work can outlast the playback window.
Video coverage includes probing and catalog ingestion. The current normal sync
does not enrich movies through TMDB; the report retains those counts without
claiming that movie metadata enrichment was exercised.

```bash
fixture_state=/absolute/private/household-fixtures
fixture_config=/absolute/private/household-fixtures.json
qualification_state=/absolute/private/household-run
qualification_config=/absolute/private/household-qualification.json
php scripts/qualify-household.php prepare --config "$fixture_config" --state "$fixture_state"
php scripts/qualify-household.php prepare --from "$fixture_state" --config "$qualification_config" --state "$qualification_state"
php scripts/qualify-household.php start --state "$qualification_state"
php scripts/qualify-household.php scenario --state "$qualification_state" --scenario original-overlap --repeat 1
php scripts/qualify-household.php scenario --state "$qualification_state" --scenario fresh-copy-overlap --repeat 1
php scripts/qualify-household.php scenario --state "$qualification_state" --scenario mixed-overlap --repeat 1
php scripts/qualify-household.php stop --state "$qualification_state"
php scripts/qualify-household.php start --state "$qualification_state"
php scripts/qualify-household.php stop --state "$qualification_state" --cleanup
php scripts/qualify-household.php report --state "$qualification_state"
```

Each overlap observes two real players while normal scan and metadata work runs.
A non-playing song and its lyrics file are renamed before the scan, preserving
their bytes and catalog identity while exercising source-path reconciliation.
The old unavailable index entry remains visible in the evidence; it is not a
missing import. Fresh-copy sources are consumed once per deployment. Use fresh
deployment names and state directories for repetitions two and three, retaining
the image, dataset and workload timing needed to compare them. Prepare each run
with `--from` pointing to the same fixture state. This copies and verifies the
media in a fresh owned volume and borrows the exact images; it does not regenerate
the fixtures. The fixture and run configurations need distinct deployment names
and the same selected Docker daemon. Record resource changes as separate candidates.

A fixture source must have no attempted overlap or pending source rename. Once
it has borrowers, run the source-changing scenarios only in those child deployments.
Each child's `stop --cleanup` removes its own data while retaining the shared
images. After every child has completed cleanup, remove the fixture source:

```bash
php scripts/qualify-household.php stop --state "$fixture_state" --cleanup
```

Reports retain host and cgroup memory, swap, pressure, process samples, database
and Redis observations, browser progress and native launch records. Native
concurrency samples are a lower bound because a short process can run between
samples; cgroup memory peaks and the launch journal provide separate evidence.
Short-lived controller actions record their own cgroup peaks and Docker exit
state before cleanup. The report lists those lifetime peaks separately from
simultaneous samples; their sum does not measure concurrent consumption.
Missing observations, prohibited encoding, unfinished maintenance and failed
cleanup prevent a passing report. Growing deployment swap prevents a memory-fit
claim even when total host swap remains stable.

Plain `stop` retains data for restart checks. `stop --cleanup` verifies retirement
and retention, then removes only the owned fixture resources. Keep the private
receipts, including failed runs. A published capacity result also needs the
full-profile comparison, recovery and profile-replacement checks, an insufficient
memory case, and three comparable successful repetitions. Setup success alone
does not establish a minimum memory requirement or Raspberry Pi support.

## Playback Destination Specs

The close report and "Play here anyway" have Playwright specs under
`ui/web/tests/e2e/playback-destinations/`. `close-report.spec.ts` and
`override.spec.ts` drive two browser contexts of one account against the
worktree preview. Each context has
its own storage, so it shares no tab identity, lock or local recovery record
with another one, and only the session connects them. The close report and the
override are proved here rather than in the two-command checkpoint, which has one
page and no way to let a registration lapse.

```bash
make preview-up
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/playback-destinations/close-report.spec.ts \
  tests/e2e/playback-destinations/override.spec.ts \
  tests/e2e/playback-destinations/local-recovery.spec.ts
```

Each test creates test-owned accounts and media through the managed fixture
executor of the [live-update capacity scenario](live-updates.md), plays the
fixture song `Live song 00000` on one of those accounts, and cleans its fixture up
afterwards. The helper `session-tabs.ts` opens a named context, signs it in,
starts the song and records every session `POST` of the context with its
answer; each test attaches that record as `session-exchanges`, with its receipt.

`close-report.spec.ts` has three cases:

- A playing tab leaves its page by navigating away, which dispatches `pagehide`.
  Within 8 seconds, far inside the 30-second registration lapse, the other tab's
  snapshot must show no runner, the session paused on the same media past its
  first second, and the closed tab's destination unavailable. The other tab's Play
  must then start at once, with no refusal, no failed request and no request to
  `/api/session/override` or `/api/session/recover-local`.
- A playing tab is closed with `page.close()`. Whether the engine dispatched
  `pagehide` and the report released the session within the same 8 seconds is
  recorded as `pagehideReportedTheClose`, not assumed. With a report, the other
  tab plays at once; without one, the case is the crash case, and the other tab
  must offer an enabled "Play here anyway" once the registration has lapsed.
- A context opened after the playing tab left must receive registration answers
  that all show no runner and the session paused on the same media, and its Play
  must start at once.

`override.spec.ts` has two cases:

- A playing tab stops reporting presence, as a killed browser would, by aborting
  its `/api/session/observe` requests while its page keeps playing. Once its
  registration has lapsed, the other tab's song selection is refused by the tab
  itself, without a control request. The menu must show the status line and an
  enabled "Play here anyway"; the override must answer 200 with the outcome
  `overridden` and close the menu, and the refused track must start without
  another press. The overridden tab's audio element must be paused within ten
  seconds and stay paused while the other tab plays on.
- A playing tab is taken offline with `setOffline`, and the other tab overrides
  it once its registration has lapsed. The only assertion about the offline tab
  is that its element is paused once it is back online, while the session still
  names the other tab. The receipt records whether the offline tab's socket
  closed, how long its element kept playing and which rule silenced it.

`local-recovery.spec.ts` keeps checking local recovery after a reload, a tab
close and renderer destruction, that a copied tab takes a tab identity of its
own, and the renewal after a presence lapse.

The configuration defines no projects, so these specs run in Chromium, and
Chromium is the engine that binds them. They are written engine-neutral; a
Firefox run with `--browser=firefox` is attempted and its result recorded, and no
gate depends on it. None of them proves that a closed or reloaded page's element
is silent, because nothing of the page is left to inspect, and none drives a
socket replaced without a close; the coordinator's unit tests and the PostgreSQL
integration tests prove the succession. Every run of `e2e:headless` rewrites the
tracked `ui/web/playwright-report/index.html`, so restore that file before you
commit unless the change is intended.

