---
title: Pace held-frame transcode fixtures by generated frames, not input packets
date: "2026-11-05"
category: best-practices
module: Transcode browser fixtures and media verification
problem_type: test_failure
component: testing_framework
related_components: [background_job]
severity: medium
symptoms:
  - "sparse-seek and matroska-delayed-seek fail about every other run on a fast host with: Retain a growing playlist"
  - "The first media playlist the player fetches already carries #EXT-X-ENDLIST, although the fixture reported the presentation incomplete a moment earlier"
  - "Every segment of a held-frame seek presentation closes within about 100 ms of the first"
root_cause: async_timing
resolution_type: test_fix
tags: [transcode, ffmpeg, sparse, held-frame, realtime, fps, fixture, playlist]
---

# Pace held-frame transcode fixtures by generated frames, not input packets

## Problem

The adaptive browser fixture runs the production encoder command and has to keep
the presentation publishing while the player attaches, so that
`sparse-browser.mjs` can prove the player handles a playlist that grows. For
held-frame sources the pacing the fixture used, `-re` on the video input, did not
keep the presentation open long enough: on the development host the two seek
scenarios failed about every other run, and the fix for one of the failures made
the player stall instead.

## Symptoms

- `BAANDER_TEST_VIDEO_SCENARIO=sparse-seek` and `matroska-delayed-seek` failed with
  `AssertionError: Retain a growing playlist`
  (`ui/web/tests/e2e/transcode-adaptive/sparse-browser.mjs:366`). The fresh
  scenarios of the same sources passed.
- The attach check passed: `/__adaptive/status` reported `complete: false` with
  two of four segments (`sparse-browser.mjs:285`). The first media playlist the
  player fetched about 60 ms later held all four segments and `#EXT-X-ENDLIST`.
- A passing run of the same scenario showed three segments and no end tag at that
  fetch. The whole window between readiness and completion was the browser's
  startup time.

## What Didn't Work

**Treating it as host speed.** The burst is structural, not a fast CPU. `-re` paces
input *packets*, and a held-frame source carries one packet per hold, so the
`fps=1` filter the encoder adds for sparse sources
(`src/Transcode/Infrastructure/FFmpeg/SegmentEncoder.php:159`) still receives each
hold at once and generates its frames in one burst. A seek scenario starts at 6 s
of a 30 s source (`tests/Fixtures/Transcode/AdaptiveBrowserBootstrap.php:36`), so
only the packets at 10 s and 20 s remain before the end of the stream, and the
frames of the last hold follow the last packet immediately. A slower host widens
the window by tens of milliseconds, which is why the fresh scenarios, with one
more hold, passed.

**Pacing the video alone.** Appending FFmpeg's `realtime` filter after `fps`
made the playlist grow as intended, and both seek scenarios passed twice. But the
padded silence that replaces audio after the seek point is generated at once, so
the audio track completed immediately while video arrived at one frame per
second, and the player stalled: 53 of 170 playback samples sat at 5.99 s in one
run.

## Solution

For sparse sources the fixture no longer inserts `-re`. It appends `realtime` to
the video filter chain, after the `fps` filter the encoder put there, and
`arealtime` to the audio chain
(`tests/Fixtures/Transcode/AdaptiveBrowserBootstrap.php:226-241`, abridged):

```php
if ($sparse) {
    $filters = array_search('-vf', $arguments, true);
    if ($filters === false || !str_contains($arguments[$filters + 1], 'fps=1:')) {
        throw new RuntimeException('The encoder omitted its sparse frame rate filter.');
    }
    $arguments[$filters + 1] .= ',realtime';
    $audio = array_search('-af', $arguments, true);
    if ($audio !== false) {
        $arguments[$audio + 1] .= ',arealtime';
    }
} else {
    array_splice($arguments, $input, 0, ['-re']);
}
```

Ordinary sources keep `-re`: their packets arrive at the frame rate, so packet
pacing and frame pacing are the same thing.

## Why This Works

`realtime` holds each frame until its timestamp, after `fps` has generated it, so
the encoder receives one frame per second whatever the packet layout of the
source. Completing a 24 s presentation then takes at least 24 s of wall time from
the first frame, and the player attaches about 0.3 s after the first segment
closes, so the first playlist it fetches cannot be complete. The four held-frame
scenarios (`sparse-fresh`, `sparse-seek`, `matroska-delayed-fresh` and
`matroska-delayed-seek`) passed four of four runs after the change, with the
video playlist growing one segment at a time. `arealtime` keeps the padded audio at the same
rate, so the muxer's strict interleaving (`SegmentEncoder.php:292`) sees both
tracks advance together instead of a finished audio track and a trickling video
track.

**What this does not fix.** The frames `fps` generates after the source's last
packet are published about ten seconds late. Measured with `showinfo` on both
sides of `realtime`, every frame leaves the filter one second after the previous
one, but `-progress` shows the muxer receiving frames 12–21 of a 24-frame seek
presentation together, ten seconds after frame 11, and the segment files carry the
same gap. The delay is the same with one x264 thread, with `sync-lookahead=0`,
without `-max_interleave_delta 0`, and with `-fps_mode passthrough` or `vfr`, so
it sits somewhere between the filter graph's end-of-stream handling and the DASH
muxer. It also occurred under `-re`, where the burst hid it. Every held-frame run
therefore stalls playback for about ten seconds and still passes; the sibling
learning on FFmpeg 7 records a ten-second hold of a different shape under
`-shortest`, and the two may share a cause that this session did not find.

## Prevention

- Pace a fixture producer at the point where the frames it must publish exist.
  For a held-frame source that is after the `fps` filter; `-re`, `-readrate` and
  any other input-side pacing keep the burst, because one input packet carries a
  whole hold. Where packets are the published unit, as in a stream copy,
  input-side pacing is the right tool (see the copy pacing learning under
  Related). The fixture refuses to run a sparse
  scenario whose command lacks the `fps=1:` filter, so a change to the encoder's
  sparse branch surfaces here rather than as a flaky playlist assertion.
- Pace every track the muxer interleaves. A paced video track beside an unpaced
  audio track stalls the player as surely as no pacing.
- To measure publication timing, rerun the recorded command from the run's
  `media/producer-commands.json` with `-progress pipe:1` and compare the
  `frame=` and `out_time_us=` lines against wall time, and read the segment
  files' mtimes. `showinfo` before and after a pacing filter shows which side of
  the filter a delay is on. Run the container as the host user, because the CI
  image's `www-data` cannot write a bind-mounted output directory.
- A pass of the growing-playlist assertion proves only that the first fetch was
  incomplete. Read `samples[].time` in `result.json` for stalls before trusting a
  presentation's shape.

## Related

- [FFmpeg major upgrades](ffmpeg-major-upgrades-break-shortest-and-guessed-frame-rates.md):
  the same sparse branch of the encoder, and the ten-second `-shortest` hold on
  FFmpeg 7.1.
- [Local gate images](../developer-experience/local-gate-images-and-webkit-harness-runs.md):
  the CI image these scenarios run in.
- [Pace a stream copy with a read-rate burst counted from the seek boundary](../performance-issues/pace-a-stream-copy-with-a-read-rate-burst-counted-from-the-seek-boundary.md):
  the production copy, where packets are the published unit and input-side
  pacing bounds the lead.
