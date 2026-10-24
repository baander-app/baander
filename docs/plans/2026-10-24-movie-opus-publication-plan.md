---
title: Repair the accepted Opus movie audio profile
date: 2026-10-24
status: reviewed
execution: code
---

# Repair the accepted Opus movie audio profile

## Observed failure and active consumer

The movie session API accepts `opus_stereo`. The controller constructs its
AudioProfile, and the worker passes that profile to SegmentEncoder. The command
builder treats the profile's codec identifier as an FFmpeg encoder name. In the CI
image's FFmpeg 5.1.9, `-c:a opus` selects an experimental native encoder and exits
with status 1. The default AAC control succeeds on the same synthetic source.

Selecting `libopus` alone exposes the next failure: automatic DASH packaging
chooses WebM audio. PublishedPresentationReader requires fragmented MP4, so it
returns no presentation and readiness cannot complete. A bounded reproduction
using libopus with `-dash_segment_type mp4` publishes audio/mp4 with the native
Opus codec declaration. The reader accepts it, and independent decoding produces
194,592 PCM bytes from the one-second fixture. No application data or original
videos were used.

This is a repair of an accepted API option and its existing worker consumer.
It does not add an audio selector, a new profile, a new container backend or a
compatibility layer. AudioProfile continues to identify the codec as opus; the
FFmpeg adapter owns encoder selection. Existing Published presentation and
Canonical audio track concepts apply without new terminology.

## Implementation and acceptance

Map the Opus codec to the installed libopus encoder in the streaming command
builder. Explicitly select MP4 DASH segments for the publication format already
required by storage, delivery and the presentation reader. Preserve the existing
signed composition-offset flags, track separation, thread bounds, source mapping,
profile bitrate/channel/sample-rate settings and video-only behavior. Other audio
profiles keep their current encoder selection; unrelated codec support is outside
this change.

Add focused argument regressions and a native media test with generated video and
audio. Exercise Opus and AAC controls for fresh output and a restart at source
second six. Require successful encoding, readable closed video/audio presentations,
MP4 initialization/media, actual codec and sample-rate/channel declarations,
continuous native timelines and independent decoding of every published fragment.
Compare decoded audio duration and signal with the expected source interval,
accounting for codec priming, and require actual video frames. Retain commands,
probes, native manifests and decode observations on failure and success. Show the
Opus test failing against the unchanged command builder before applying the repair.

Extend the existing adaptive browser fixture with an explicit selection between
streaming_stereo and opus_stereo; the default stays AAC. Carry the selected profile
through generation metadata, runtime ownership doubles and encoder arguments.
Record the profile and expected codec in readiness and browser evidence. Assert
that the served HLS master declares the selected audio codec and that native audio
presentation metadata agrees. Keep all existing fresh-request, decoded-frame,
phase-local audio, publication, throughput, identity and transport assertions.
Run both profiles through the actual HLS adapter in pinned Chromium. This proves
Opus playback with positive audio signal during 720p-to-360p-to-720p adaptation;
it does not promise stall-free playback. Record observed buffering.

Use the existing isolated runner and add both profiles to its CI invocation with
separate evidence directories. Validate the profile before starting containers.
Keep existing runtime and cleanup bounds, disconnected fixture networking and
read-only treatment of original media. No application database or Redis is used.

## Ownership and verification

Use the previously authorized equivalent planning, implementation and independent
review workflow while compound-engineering skills remain unavailable. Obtain
independent plan review before implementation. One worker owns SegmentEncoder and
its argument tests; a second owns the new native media test. The lead owns shared
native-lane registration, browser fixtures, runner, CI, integration, documentation
and commits. Workers preserve others' edits and do not stage or commit.

Run the new native regression against unchanged and repaired production source,
then the authoritative full Transcode unit selection in the CI image. Run full
PHPStan and Deptrac, shell syntax and workflow YAML checks. Repeat the existing
AAC adaptive browser gate and run the Opus variation against the final source,
serializing expensive gates. Review the implementation and apply justified
findings before final acceptance. Record source revisions, exact suite counts,
versions and retained receipts in ROADMAP.md and update the testing guide through
prose-fix. Commit locally on the branch named after this plan; no merge or push
is included.

Native decoding and Chromium playback cover this selected software encode and
signed delivery path. Other browsers, hardware, alternate audio tracks, general
Opus conformance, DASH browser playback and formal CMAF conformance remain outside
this acceptance.

## Separate demonstrated finding

The timing audit also reproduced a sparse-CFR failure: a 0.1-fps source produces
two ten-second fragments, which the stable seven-second HLS target rejects.
This is retained as a separate repair candidate, not changed here. Its eventual
repair must preserve source duration, seeking and a constant target throughout a
growing playlist. The retained reproduction distinguishes sparse CFR from
unverified variable-frame-rate gaps.

Independent plan review approved implementation. Native tests must inspect the
actual MPD and initialized stream codec, use explicit numerical priming tolerances,
and verify that restarted audio and video cover the intended source interval.
Browser expectations must distinguish the requested profile from the observed
native codec so a metadata or encoder mismatch cannot satisfy the check.
