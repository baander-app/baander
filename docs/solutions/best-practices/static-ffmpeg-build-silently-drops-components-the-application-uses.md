---
title: The static FFmpeg build silently drops components the application uses
date: "2026-11-07"
category: best-practices
module: FFmpeg image (docker/ffmpeg) and the application image that copies its binaries
problem_type: integration_issue
component: infrastructure
symptoms:
  - "A copy presentation fails with \"Copy source verification failed: Media helper failed.\" and no FFmpeg error text"
  - "ffmpeg opening a published manifest.mpd over HTTP fails with \"Invalid data found when processing input\""
  - "ffprobe -demuxers on the static build lists webm_dash_manifest but no dash demuxer"
  - "ffmpeg -filters lists hwupload_cuda but neither scale_cuda nor yadif_cuda, which the NVENC filter chain emits"
root_cause: config_error
resolution_type: config_change
severity: high
framework_version: ffmpeg 8.1 (markus-perl ffmpeg-build-script v1.59)
related_components: [background_job, testing_framework]
tags: [ffmpeg, static-build, dash-demuxer, libxml2, cuda-filters, capability-gate, ffmpeg-image, copy-presentation]
---

# The static FFmpeg build silently drops components the application uses

## Problem

The application image switched from Debian's FFmpeg package to the project's
static build in `docker/ffmpeg/Dockerfile`. That build lacked components Debian's
package carries and Bånder relies on: the DASH demuxer and the CUDA filters. The
image built and its smoke passed, and the gaps showed up only as integration
test failures that named neither component.

## Symptoms

- `tests/Integration/VideoCopyPresentationTest.php` failed in both cases, video
  with AAC and video only, with outcomes `["accepted","ready","failed"]`. The job
  reason was `Copy source verification failed: Media helper failed.`
- `ProgressiveMediaPublicationTest` failed with
  `dash must decode growing video during publication`, and its log showed FFmpeg
  failing to open the manifest URL with `Invalid data found when processing input`.
- Both tests passed with the same branch code on the previous image, which ran
  Debian's FFmpeg 7.1.

## What Didn't Work

- **Blaming the keyframe probe.** The copy path runs two ffprobe helpers through
  the same pool. The first suspect was the keyframe listing
  (`-skip_frame nokey -show_frames`), because it is the helper that decodes. Run
  by hand on a fixture of the same shape, it exited 0 with valid JSON.
- **Blaming the native execution daemon.** The daemon launches every media command
  with a fixed environment through `setsid`, `nice` and `choom`. An empty
  environment (`env -i`) changed nothing. Submitting both probes through a real
  daemon (`NativeExecutionFixture` in the CI image) also returned exit 0 for each.
- **Reading the error text.** There was none. The failing helper was the output
  probe, `src/Transcode/Infrastructure/Swoole/TranscodeSessionSubscriber.php:337`,
  which probes the published `manifest.mpd`. That probe uses
  `FFprobeArguments::video`, which passes `-v quiet`
  (`src/Transcode/Infrastructure/FFmpeg/FFprobeArguments.php:19`), so stderr is
  empty. `TranscodeProcessPool::readResult` then falls back to the generic
  `Media helper failed.` (`src/Transcode/Infrastructure/Swoole/TranscodeProcessPool.php:98`).
  The same message prefix at `TranscodeSessionSubscriber.php:473` covers both
  helpers, so the message did not say which one failed.

## Solution

1. **Find which helper fails.** Add the job's failure reason to the test's
   assertion message. The copy test now asserts with
   `json_encode([$outcomes, $job->getFailReason()])`. Then list what each probe
   reads. Only the output probe reads a DASH manifest.
2. **Compare the two builds component by component**, not by version. Each
   listing is a set of names, and a missing name the application uses is a
   finding:

   ```bash
   for k in demuxers muxers filters decoders bsfs; do
     ffmpeg -hide_banner -$k | awk 'NR>1 && $2 !~ /^=/ {print $2}' | LC_ALL=C sort -u > $k
   done
   # run once per image, then:
   LC_ALL=C comm -13 static.demuxers debian.demuxers   # in Debian, not in the static build
   ```

   Grep `src/` for each missing name. The DASH demuxer is used to probe
   published manifests. `scale_cuda` and `yadif_cuda` are used by the NVENC
   filter chain (`src/Transcode/Infrastructure/FFmpeg/VideoFilterBuilder.php:31`
   and `:44`). The rest of the gap (`libgme`, `sdl2`, `frei0r`, `librsvg` and
   others) is unused.
3. **Restore the components in the FFmpeg stage.** `libxml2-dev` and `clang` are
   installed in `ffmpeg-prepared` (`docker/ffmpeg/Dockerfile:189-195`), not in
   `base-builder`, so the library stages keep their cache. Configure now also
   passes `--enable-libxml2 --enable-cuda-llvm` (`docker/ffmpeg/Dockerfile:236-237`).
4. **Ship the new shared library.** The binary now links `libxml2.so.2`, so
   `libxml2` joins `docker/ffmpeg/runtime-packages.txt`. The runtime-stage gate
   reported the missing object (`error while loading shared libraries:
   libxml2.so.2`) before any test could. Derive the package list from the built
   binary rather than guessing:

   ```bash
   ldd ffmpeg ffprobe | awk '{print $3}' | grep ^/ | sort -u \
     | xargs -n1 sh -c 'dpkg -S "$0" 2>/dev/null || dpkg -S "$(readlink -f "$0")"' | cut -d: -f1 | sort -u
   ```

5. **Gate the components, not only the encoders.** `scripts/ffmpeg-capability-gate.sh`
   learned `decoder`, `filter` and `demuxer` entries (lines 28-30). The expected
   list now names the AAC implementations as encoder and decoder, the filters the
   video filter builder emits, and the `dash`, `mov` and `matroska` demuxers
   (`docker/ffmpeg/expected-capabilities.txt`). The gate runs in the FFmpeg
   image's runtime stage and again in the application image
   (`Dockerfile:448-451`).

## Why This Works

The build script configures FFmpeg with whatever libraries it finds, and an
autodetected feature whose dependency is absent is dropped without an error.
FFmpeg's DASH demuxer depends on libxml2, so a builder without
`libxml2-dev` produces a binary that can still write DASH output through its
muxer but cannot open a manifest. The CUDA filters are compiled device-only by a
CUDA compiler, and the `cuda_llvm` feature needs `clang` for that. Without it the build keeps NVENC, NVDEC and
`hwupload_cuda`, but loses every `*_cuda` processing filter. Debian's package
has both, as its component listings showed, which is why the same code passed
on the old image. Installing the
dependencies and enabling the features explicitly makes a missing dependency a
configure failure instead of a silent drop. Listing the components in the gate
catches a later script bump or configure change that drops them again.

## Prevention

- When the FFmpeg binary changes source (distribution package, static build,
  build-script bump), diff its component listings against the previous binary
  before running suites. Grep the application for every name that disappeared.
- When application code starts using a new filter, demuxer, decoder or encoder,
  add it to `docker/ffmpeg/expected-capabilities.txt`. The gate checks only what
  is listed.
- Prefer `--enable-<feature>` over autodetection for every feature the
  application needs. For an autodetected library, libxml2 and `cuda_llvm`
  among them, configure stops with "requested but not found" when it is enabled
  explicitly and missing, and drops it silently when it is left to autodetection.
- Keep the runtime package list derived from `ldd` on the built binaries, and let
  the gate's `ldd` check fail the build rather than a container at startup.
- A helper failure that reads only `Media helper failed.` means the command wrote
  nothing to stderr. Commands that run with `-v quiet` need their arguments and
  input checked by hand.

## Related Issues

- [FFmpeg major upgrades break -shortest and guessed frame rates](ffmpeg-major-upgrades-break-shortest-and-guessed-frame-rates.md):
  behaviour changes between FFmpeg versions; this doc covers components missing
  from a build.
- [Moving the images to Debian 13](debian-13-base-images-change-collation-time-zone-links-and-vaapi-limits.md):
  other silent changes when an image's base packages change, including the VAAPI
  test frame.
- `ROADMAP.md`, section "bAAC in the server transcoder": the checkpoint that
  recorded these gaps, and the NVENC HDR path's `tonemap_cuda`, a filter that
  neither build has.
