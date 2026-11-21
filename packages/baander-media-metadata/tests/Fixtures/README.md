# MP4 interoperability fixtures

`Mp4ReaderTest` reads these files produced by FFmpeg 8.1.3, including their tags and audio stream properties.
Both contain generated mono 440 Hz audio at 48 kHz.
Tests use the committed files without running FFmpeg.

Generate them from the repository root:

```sh
ffmpeg -hide_banner -loglevel error -f lavfi \
  -i 'sine=frequency=440:sample_rate=48000:duration=0.1' \
  -c:a aac -b:a 64k -metadata title='Encoded AAC' -metadata artist='Baander' \
  -y packages/baander-media-metadata/tests/Fixtures/tagged-aac.m4a
ffmpeg -hide_banner -loglevel error -f lavfi \
  -i 'sine=frequency=440:sample_rate=48000:duration=0.1' \
  -c:a alac -metadata title='Encoded ALAC' -metadata artist='Baander' \
  -y packages/baander-media-metadata/tests/Fixtures/tagged-alac.m4a
```
