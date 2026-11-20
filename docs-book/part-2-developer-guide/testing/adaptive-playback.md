# Adaptive and source-specific playback qualification

Use the isolated browser runner for adaptive quality, sparse video, audio timing and source selection. These cases share the runner but establish the distinct boundaries stated in each section.

## Automatic movie quality switching

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

## Sparse movie playback

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
or inference about its cause. The original per-run receipt was not retained in
tracked sources. The counts above come from the historical report; the per-run
timings cannot be independently rechecked, and no new run is claimed here.

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

## Delayed Matroska playback

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

## Ordinary delayed movie audio

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

## Source default audio playback

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

## Movie playback after source audio ends

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
requirements remain unchanged. These cases qualify the selected software movie
path and Chromium HLS, not
arbitrary discontinuities, additional source origins, hardware, other browsers,
DASH browser playback or formal CMAF conformance.

