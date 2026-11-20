# Playback lifecycle qualification

Use the two-command drill to check a production worker image and its browser lifecycle. The [capacity drill](media-capacity.md) and [destination specs](playback-destinations.md) cover separate cases.

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
frames, an advancing clock and real seekable ranges. Growing movie playback waits
for newly published media rather than moving its clock to a live sync point; the
source cursor remains authoritative.

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
API, then only observes modes and active cues. The nondefault Forced track must
remain exclusively showing through a source seek and paused capability renewal; Off
must leave every subtitle track disabled through a paused seek and running renewal.
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
gate recorded before the paired deployment change passed authenticated playback,
paused seek, web restart, worker containment and recovery, and final completion. It
also showed that both stale-generation probes leave the paused state unchanged,
checked fourteen contained process identities with no survivors, and verified
cleanup. Ordinary
[autoscaling](worker-capacity-qualification.md#ordinary-worker-autoscaling-qualification)
and saturated [media controls](media-capacity.md#shared-media-control-qualification) have dedicated
gates; regional qualification remains separate.

## Iterating on the drill locally

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
`scripts/` and the workflow packages under `packages/` runs from the checkout as well. A change
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
seven. The [endpoint check](movie-endpoint-playback.md#selected-video-endpoint-playback) took about five.

Browser evidence lands in `browser/` under the artifacts directory: `result.json`
after a pass, `failure.json` and `failure.png` after a failure. Beyond what
`result.json` holds, `failure.json` records the error, the player's `baander:*`
performance marks, the visible alert and status texts, page errors, console errors
and warnings, and the recorded API responses. Read the marks first: they give the
order in which the player committed, queued and sent each seek.

Run the image build and each drill one at a time on an otherwise idle host. The
drills run real encoders against deadlines, and the
[media-control drill](media-capacity.md#shared-media-control-qualification) applies CPU pressure on
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

## Browser client controls

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

The paused-remount check first waits for decoded, seekable media to be paused,
no longer seeking and attached to the current signed rendition set. Only then
does it capture the cursor and reload the movie page.

Because the session confirmed the pause, the reloaded page's endpoint-registration
answer must already show no active runner. The check reopens the player and
requires the same session and job, with the source cursor within two seconds.
Placing that cursor may take at most one source seek, one generation. Resuming
must advance both decoded frames and the playback clock.

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
[adaptive playback gate](adaptive-playback.md#automatic-movie-quality-switching).

