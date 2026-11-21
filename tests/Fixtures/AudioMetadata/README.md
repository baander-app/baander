# Tagged audio fixtures

These short silent files were generated with FFmpeg 8.1.3 for `AudioMetadataImportTest`.
The tests copy each file to a temporary directory under its normal extension or an alias, scan it, read its tags, and verify the catalog persistence call and unchanged source hash.
No encoder runs during tests.

The common input and tags are:

```sh
ffmpeg -v error -nostdin -f lavfi -i anullsrc=r=8000:cl=mono -t 0.04 \
  -metadata 'title=Imported title' -metadata 'artist=Imported artist' \
  -metadata 'album=Imported album' -metadata track=3 -metadata date=2026 \
  [format options] tagged.[extension]
```

| Extension | Format options |
| --- | --- |
| `m4a` | `-c:a aac` |
| `wma` | `-c:a wmav2 -ar 44100 -b:a 64k` |
| `wav` | `-c:a pcm_s16le` |
| `aiff` | `-c:a pcm_s16be -write_id3v2 1` |
| `aifc` | `-c:a pcm_s16le -write_id3v2 1 -f aiff` |
| `aac` | `-c:a aac -write_id3v2 1` |
| `ogg` | `-c:a libvorbis` |
