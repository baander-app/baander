# app:e2e:ingest-video

Ingest a directory of video files as a movie library, without workers. The end-to-end transcoding tests use it, and it is useful for debugging video playback. The command finds or creates the library, scans it and catalogs the videos in this process, so it needs no Messenger consumer and works while the web server is running.

## Quick start

```bash
make exec cmd="php bin/console app:e2e:ingest-video /media/e2e-movies"
```

## Arguments

| Argument | Required | Default | Description |
|----------|----------|---------|-------------|
| `path` | Yes | — | Absolute path to a directory of video files |
| `libraryName` | No | `E2E Test Movies` | Name of the movie library |
| `slug` | No | `e2e-test-movies` | Slug of the movie library |

## Details

When no library has the slug, the command creates one and prints its ID. It prints the IDs of the ingested videos.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | At least one video was ingested |
| 1 | No video was ingested; check the path |
