# Bånder Audio Core in the FFmpeg image

`libavcodec/` holds the libavcodec wrapper sources for the Bånder Audio Core codec from
`packages/baander-audio-core`, and `ffmpeg-8.1-libbac.patch` registers them with
FFmpeg's `configure`, `libavcodec/allcodecs.c` and `libavcodec/Makefile`.
The `ffmpeg-final` stage of `docker/ffmpeg/Dockerfile` copies the sources into
the FFmpeg tree the build script extracted, checks that the patch applies and
applies it, then re-runs `configure` with `--enable-libbac`. A patch that no
longer applies fails the image build.

## Regenerating the patch for a new FFmpeg release

The patch is a plain `diff -ruN` between the pristine release tree and an
edited copy. For a new tag:

1. Extract the release tarball twice, as `FFmpeg-<tag>` and `work`.
2. In `work`, add `libbac` to `EXTERNAL_LIBRARY_VERSION3_LIST`, the
   `--enable-libbac` help line, the `libbac_*_deps` and
   `libbac_encoder_select="audio_frame_queue"` lines, and the
   `require_pkg_config libbac bac bac/aac.h aac_encoder_create` check
   in `configure`; the `ff_libbac_*` externs in `libavcodec/allcodecs.c`;
   and the `OBJS-$(CONFIG_LIBBAC_*)` lines in `libavcodec/Makefile`.
3. `diff -ruN FFmpeg-<tag> work > ffmpeg-<tag>-libbac.patch`, then rewrite
   the paths to `a/` and `b/` so that `git apply -p1` and `patch -p1` both
   take it.
4. Point the Dockerfile at the new patch and bump `SMOKE_EXPECT_MAJOR` in
   the smoke step.

The wrapper itself uses libavcodec's internal headers (`codec_internal.h`,
`audio_frame_queue.h`, `encode.h`), which change between releases; compare
`libavcodec/libfdk-aacenc.c` and `libfdk-aacdec.c` of the new release with
the wrapper before building.
