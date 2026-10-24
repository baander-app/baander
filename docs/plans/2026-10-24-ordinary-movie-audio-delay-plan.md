---
title: Preserve audible delay in ordinary movie playback
date: 2026-10-24
status: reviewed
execution: code
---

# Preserve audible delay in ordinary movie playback

## Selected failure

Master `2f7a055a` preserves delayed audio for a verified sparse Matroska origin,
but an ordinary 25 fps MOV still loses its audio delay in HLS. The production
probe reports video starting at zero, duration fourteen seconds and no Source
timestamp origin. Audio starts at source second eight. The encoder puts that
delay in the native MPD and an empty initialization edit, while fragment decode
time starts at zero. Decoded HLS audio starts immediately and contains only six
seconds of tone. Seeking to source second six similarly loses the remaining
two-second delay.

Controlled CI FFmpeg 5.1.9 decoding reproduces the failure through the real probe,
encoder and generated HLS playlist. Appending only `aresample=first_pts=0` retains
the complete fourteen- and eight-second presentations, with actual AAC tone onset
at about 8.02048 and 2.02048 seconds. Opus onset becomes 7.99940 seconds; a 44.1 kHz
AAC profile with DRC and sample-rate conversion retains about two seconds after
seeking. The matched audio-zero control retains identical packet timing, decoded
length and zero waveform lag, although PCM is not byte-identical after changing
the filter graph. Evidence is under `var/test-runs/ordinary-movie-delay-audit/`;
the authoritative directory uses the CI image for both production and HLS decode.

The active consumer is the existing web movie player: session subscriber, stream
manager, SegmentEncoder, published presentation and signed HLS delivery. This is
a demonstrated playback failure, not qualification of an unused codec or client.

## Change and boundaries

Apply the existing initial audio normalization whenever a movie has audio,
independently of a verified timestamp anchor or sparse frame rate. Keep it after
existing negative-preroll trimming and profile filters. The
[FFmpeg resampler contract](https://www.ffmpeg.org/ffmpeg-resampler.html) defines
`first_pts` in samples and permits padding or trimming at the start; setting zero
represents a positive leading gap as actual silence. Keep asynchronous
compensation disabled. Preserve ordinary zero-start content timing, profile
selection, sample rate, channel processing and the complete audible tail.

This changes the output audio samples, not source-origin qualification. An absent
Source timestamp origin remains absent; do not add copyts, timestamp offsets or
absolute-seek flags to ordinary sources. Existing sparse video normalization,
first-interval seeking, generation source positions, subtitle rebasing, codec
declarations and stable playlist targets retain their contracts. Video-only
commands must not gain audio options. The audio-only transcoder is outside this
movie-path change. No API, schema or new shared term is needed.

## Implementation and verification

Continue the previously authorized equivalent reviewed-plan workflow while the
compound-engineering skills remain unavailable. Obtain independent plan review
before implementation. One worker owns the encoder and its argument tests. A
second owns ordinary delayed-audio native regressions and any existing sparse
audio assertions that must now measure encoded silence instead of a delayed
first packet. A third owns the browser fixture and observer. The lead owns the
runner, native-lane registration, CI, documentation, integration and commits.
Workers preserve others' edits and do not stage or commit.

Native cases must use the production probe, encoder, native presentation reader
and HLS renderer. Verify ordinary 25 fps video beginning at zero and audio delayed
eight seconds, with a null anchor. Cover fresh output, a six-second restart while
delay remains, and a restart after onset. Require full video duration and frame
content, continuous native tracks, independently decoded fragments, complete HLS
PCM including silence, measured audible onset and signal through the source tail.
Cover software H.264/HEVC output, AAC/Opus, a 44.1 kHz profile and matched audio
controls. Include ordinary AAC source audio with negative initial packet PTS from
codec priming, requiring near-zero audible onset, aligned content and a complete
tail after decoding. Compare bounded waveform lag or frequency/content rather
than requiring byte-identical PCM. Keep existing sparse and anchored regressions; strengthen their audio
assertions where first-packet timing previously stood in for audible delay.

Argument tests cover filter ordering with and without an anchor, ordinary and
sparse rates, fresh and seeking paths, empty/custom chains and video-only output.
Null-anchor cases must still omit source-origin flags. Do not use a new frame-rate
condition to conceal the same defect in another movie path.

Add explicit ordinary delayed fresh/seek browser scenarios to the disposable
runner. Their source is a thirty-second 25 fps movie with video zero, audio at
eight seconds, red/green/blue intervals of ten seconds and no verified anchor.
Preserve actual growing publication, signed uncached resources, canonical audio,
stable targets, one attachment and natural completion. Require near-silent samples
before local eight/two seconds and positive measured audio afterward, full colour
intervals and thirty-/24-second tails. Embedded text cues, if included, must pass
the existing signed native subtitle timing and identity assertions; otherwise
make subtitle availability explicit without weakening the Matroska scenarios.
Do not classify every non-adaptive source as sparse or infer an anchor from delay.

Retain failing native and browser evidence against the prior encoder and passing
receipts after repair. Then run the full Transcode selection in the CI image,
full PHPStan and Deptrac, both new browser scenarios, an existing anchored-delay
browser control and the ordinary adaptive control. Serialize expensive checks.
Run relevant fixture syntax, runner preflight/configuration and whitespace checks.
Complete independent code and simplification review, apply justified findings,
and record exact revisions, counts, media observations, cleanup and limits in
ROADMAP.md and the testing guide through prose-fix. Commit locally; merge and push
are separate requests.

Acceptance covers the selected software movie path and Chromium HLS sources.
It does not certify arbitrary timestamp discontinuities, new source-origin
handling, hardware, other browsers, DASH browser playback or formal CMAF
conformance. Original media and existing application services remain untouched.

## Separate concrete follow-up

The current movie path also ignores a source audio track's default disposition:
commentary first and main dialogue second still maps ordinal zero, and probe
filter attributes and manifest language use that first track. A bounded two-tone
fixture confirms the selected audio differs from the marked default. Record this
as a separate actionable roadmap item; it does not belong in the delay repair.
Interactive alternate-track selection remains a separate product change.

Independent plan review approved implementation, including the encoded-source
priming control and preservation of ordinary frame-rate and timestamp handling.
