---
title: Qualify automatic movie quality switching during growing publication
date: 2026-10-24
status: reviewed
execution: code
---

# Qualify automatic movie quality switching during growing publication

## Active consumer and evidence gap

The movie overlay reaches the installed HLS.js player through attachHlsPlayback.
That adapter leaves automatic bitrate selection enabled. Existing browser checks
cover playback, controls, ownership, renewal, subtitles and completion, but do not
require a rendition transition when network throughput changes. The separate
baander-player package's adaptive controller is not this application's consumer.

The production streaming service admits a sibling rendition once it covers the
requested source cursor. Its published edge may lag the primary rendition, as the
existing AdaptiveDeliveryTest permits. Encoder keyframes already align on
six-second boundaries. What remains unverified is automatic switching between
these independently growing presentations while decoded video and canonical
audio continue. This is a qualification of the active movie path requested by the
transcoding investigation, not a new adaptive algorithm.

## Acceptance boundary

Add a separate, bounded Chromium browser gate using the actual playback adapter,
production encoder, presentation reader, signed delivery and manifest service.
Preserve the existing reference-DASH and whole-application lifecycle gates. A
passing qualification need not change production behavior. If it exposes a defect,
retain the failing receipt, trace the cause and review a narrow repair before
changing the product.

Generate a deterministic moving 1280-by-720 source with audible PCM content encoded
as AAC. Independently produce real 360p and 720p renditions with the production
filters and SegmentEncoder commands. Pace input reading and start the high tier
later so publication grows and the sibling demonstrably lags. Both producers use
the same source origin and publish their own audio, as required by compatibility;
the HLS master selects the low-tier audio as canonical.

Use real TranscodeStreamingService and storage with in-memory repository and
ownership doubles. Use the existing signed-request boundary and manifest
controller where practical. The doubles replace persistence and ownership setup;
they do not invent playlists, segment durations, codec strings or bandwidth values.
Serve only complete files accepted by the production presentation reader. Existing
application databases, Redis, credentials and original videos are not involved.

The browser attaches the production adapter once with its ordinary configuration.
It observes native decoded frames, presented dimensions, source time, audio signal,
media events and resource identity. It must not set an HLS level, override the
estimator or reduce buffering to manufacture adaptation.

## Throughput scenario

Begin with generous throughput and require automatically requested and presented
720p media. Reduce actual response-body throughput to a rate above the advertised
360p-plus-audio requirement and below the 720p requirement, then require presented
360p. Restore generous throughput and require a later presented 720p segment.
Chromium's network protocol provides this transport control without touching the
player. Delaying a fully buffered response is insufficient: HLS.js separates
first-byte latency from body transfer time.

Choose the fixed rates and source duration from one bounded calibration, retaining
measurements and stating the final values. Start with roughly 120 seconds of source
and a 180-second browser deadline; adjust only when measured producer or buffer
behavior justifies it, not to hide a failing playback condition. Each transition
has its own deadline and is observed while publication is still growing.

Require ordered high-to-low-to-high signed fragment requests and native presented
dimensions, progressing decoded frames and source clock, and positive audio signal
in every phase. Capture the native publication edges to prove sibling lag. Require
one attachment, no fatal errors, no unexplained source-clock jumps and exclusively
canonical audio requests. An intentional HLS download abandonment during adaptation
may be recorded as cancellation; HTTP failures and unrelated request failures do
not qualify as successful switching. Save screenshots, traces, samples, throughput
settings, request receipts and producer logs on success or failure.

## Ownership and verification

Use the previously authorized equivalent plan, implementation and independent
review workflow while the compound-engineering skills remain unavailable. Obtain
independent plan approval before implementation. One worker owns the PHP fixture
files. A second owns the browser entry and assertions. The lead owns the runner,
CI wiring, integration, authoritative checks, documentation and commits. Workers
preserve each other's changes and do not stage or commit.

The fixture exposes ready metadata, start/status/request endpoints and signed
media URLs under a disposable baander.app origin. Agree on that interface before
parallel implementation. Use a network-disabled CI container for native media and
a browser container sharing only its loopback network. Bound processes, memory,
logs and cleanup; verify removal of all run-owned containers. The unavailable
agent-browser CLI is replaced by the repository's pinned Playwright runtime.

Run the new browser gate against the final source, relevant manifest/delivery
backend tests, frontend type checking and tests for the production HLS adapter,
plus full PHPStan and Deptrac for any changed backend code. Check shell syntax and
CI configuration. Wire the passing gate beside the existing publication browser
check. Review code and simplification before recording final results.

Update the testing guide and ROADMAP.md with exact counts, native/browser versions,
source revision and retained receipts; apply prose-fix. This proves Chromium
adaptation through the selected native encode/delivery/adapter path. It does not
requalify the whole application, other browsers, every network profile, hardware,
HDR, alternate audio, DASH switching or formal CMAF conformance. Commit locally
on the branch named after this plan; no merge or push is included.

Independent review approved implementation before code changes. Every phase must
include a fresh signed video request followed by presentation at that rendition;
the final high-resolution frame must follow the low-resolution phase. Audio
measurements are local to each phase. Both producers must remain active with
advancing publication edges during transitions, and the sibling lag must be
measured. Disable browser caching and retain actual body-transfer measurements.

## Calibration findings

The first harness run failed before requesting a manifest: the root workspace's
PnP resolver left the caught dynamic HLS.js import outside the bundle. The runner
now resolves the installed web dependency explicitly and verifies the bundle input
list and absence of external imports. This required no production change.

With the real player bundled, high-to-low adaptation passed. After throughput
returned to 20 Mbit/s, completed 720p fragments covered source seconds 90 onward,
but the 65-second recovery observation ended at source time 89.891, before those
frames could be displayed. Decoded video and audio continued without a fatal
error. Retain that failed receipt; it establishes the observation limit rather
than a product defect.

Use a 144-second generated source and a 90-second recovery observation within the
unchanged 180-second browser deadline. This allows the unchanged player's
conservative upward bandwidth estimate to recover while both producers remain
active. The independent producer timeout is 174 seconds; the six-second delayed
start and publication pace stay fixed. Initial and constrained observations keep
their 65-second limits. Record waiting/playing intervals, including any buffering
after the abrupt bandwidth drop; this qualification does not promise stall-free
switches or an upward adaptation latency target.

Move fixture telemetry outside the browser's shaped media connection. Measure
completed signed video transfers wholly within the constrained interval, including
first-chunk time, and require a representative rate consistent with its cap.
Match canceled video requests only to a successful different-tier replacement
for the same source interval near the cancellation. These assertions address
independent review findings without changing the player's behavior.

The next run displayed all three renditions in order but failed the final transport
check on Chromium's implicit favicon request, which the fixture answered with HTTP
204. Its retained receipt records an aborted icon fetch, no player error, and
3.548 seconds of rebuffering after the bandwidth drop. An inline fixture icon
removes that unrelated request; the signed-media transport assertions stay intact.
