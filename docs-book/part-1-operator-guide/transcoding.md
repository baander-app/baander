# Transcoding

Baander converts video files into streamable CMAF segments on-the-fly using FFmpeg. Segments are served via HLS v6 and DASH manifests, with per-segment adaptive bitrate selection across multiple quality tiers.

Music tracks can also be transcoded to Opus, AAC or MP3 for clients that cannot play the original. That is off until you turn it on; see [Audio Transcoding](#audio-transcoding).

## How It Works

When a client requests a video stream, Baander creates a **transcode session** and an associated **transcode job** for the requested quality tier. The encoding loop runs in a Swoole coroutine and dispatches FFmpeg work to isolated worker processes:

1. **Probe** the source video (resolution, HDR, interlacing, framerate, audio channels).
2. **Encode the init segment** (movie header for the chosen codec and bitrate).
3. **Analyze loudness** (two-pass EBU R128 measurement) so the audio filter chain can normalize to the target loudness standard.
4. **Build filter chains** -- video scaling, deinterlacing, HDR tonemapping, framerate capping, audio downmixing, loudness normalization, DRC.
5. **Encode media segments** -- 6-second CMAF segments, dispatched to the CPU process pool with a sliding window of in-flight work.
6. **Serve manifests** -- the client receives an HLS v6 media playlist or DASH MPD that references the encoded segments.

Segments are encoded with `libx265` (HEVC) tagged as `hvc1` for broad player compatibility. The pixel format is always `yuv420p`. Each segment is a fragmented MP4 with `frag_keyframe+separate_moof+default_base_moof` flags for independent seekability.

## Quality Tiers

The quality ladder defines six HEVC tiers. The client selects a tier at session creation time based on its capabilities and available bandwidth.

| Tier | Resolution | Video Bitrate | Max Bitrate | Buffer Size | Codec |
|------|-----------|---------------|-------------|-------------|-------|
| 360p | 640 x 360 | 800 kbps | 1.2 Mbps | 1.6 Mbps | hvc1 |
| 480p | 854 x 480 | 1.4 Mbps | 2.1 Mbps | 2.8 Mbps | hvc1 |
| 720p | 1280 x 720 | 2.8 Mbps | 4.2 Mbps | 5.6 Mbps | hvc1 |
| 1080p | 1920 x 1080 | 5 Mbps | 7.5 Mbps | 10 Mbps | hvc1 |
| 1440p | 2560 x 1440 | 10 Mbps | 15 Mbps | 20 Mbps | hvc1 |
| 4K | 3840 x 2160 | 20 Mbps | 30 Mbps | 40 Mbps | hvc1 |

All tiers use the RFC 6381 codec string `hvc1.1.6.L93.B0` with AAC audio (`mp4a.40.2`) in manifests.

### Audio Profiles

Each session is assigned an audio profile that controls codec, bitrate, channel layout, sample rate, loudness target, and dynamic range compression.

| Profile | Codec | Bitrate | Channels | Sample Rate | Loudness Standard | DRC |
|---------|-------|---------|----------|-------------|-------------------|-----|
| mobile_mono | AAC | 32 kbps | 1.0 (mono) | 44.1 kHz | Mobile (-14 LUFS) | On |
| mobile_stereo | AAC | 64 kbps | 2.0 (stereo) | 44.1 kHz | Mobile (-14 LUFS) | On |
| streaming_stereo | AAC | 128 kbps | 2.0 (stereo) | 48 kHz | Streaming (-16 LUFS) | Off |
| streaming_5.1 | AAC | 256 kbps | 5.1 (surround) | 48 kHz | Streaming (-16 LUFS) | Off |
| broadcast_stereo | AAC | 192 kbps | 2.0 (stereo) | 48 kHz | EBU R128 (-23 LUFS) | Off |
| broadcast_5.1 | AAC | 384 kbps | 5.1 (surround) | 48 kHz | EBU R128 (-23 LUFS) | Off |
| hifi_stereo | AAC | 256 kbps | 2.0 (stereo) | 48 kHz | Dialogue (-20 LUFS) | Off |
| opus_stereo | Opus | 96 kbps | 2.0 (stereo) | 48 kHz | Streaming (-16 LUFS) | Off |

## CPU Process Pool

FFmpeg uses `proc_open()` under the hood, which is **not** hooked by Swoole's coroutine runtime. If FFmpeg ran directly in an HTTP worker, it would block that worker for the entire duration of the encode. To avoid this, all FFmpeg work is dispatched to a **CPU process pool** -- a set of isolated worker processes that communicate with the main server over Unix sockets.

The pool is managed by `CpuProcessPool` and accessed through the domain-specific `TranscodeProcessPool` facade. Results are written to a shared Swoole\Table so the encoding coroutine can poll for completion without blocking.

### Worker Count

The default pool size is **2 workers**, configured in `config/services.yaml`:

```yaml
App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool:
    arguments:
        $handlers: !tagged_iterator 'swoole.cpu_pool_worker'
        $workerCount: 2
```

The encoding loop uses a sliding window of at most `workerCount` in-flight segments per job. If multiple transcode sessions are active, they share the pool -- so each job gets at most `workerCount` segments encoding concurrently. Increase the worker count to match your CPU cores if you need more parallelism.

### Worker Process Details

Workers are plain PHP processes (no Symfony container) that receive a JSON payload, execute FFmpeg, and return the result. Each worker has a 300-second timeout for segment encoding and a 600-second timeout for loudness analysis. Stalled processes are killed with `SIGKILL`.

## Job Types

The pool handles three job types:

| Job Type | Description | Timeout |
|----------|-------------|---------|
| `encode_segment` | Encode a single 6-second media segment with video and audio filters | 300s |
| `encode_init_segment` | Encode the init segment (movie header, no audio) | 120s |
| `analyze_loudness` | Run EBU R128 loudness analysis pass on the source audio | 600s |

## Job Monitoring and State

### Segment-by-Segment Tracking

Each transcode job tracks progress per-segment. When a segment completes, the job records the segment index, output file path, file size, and duration. This data is persisted to the database and used by the manifest generator to build accurate playlists.

State transitions for a transcode job: `pending` -> `in_progress` -> `completed` (or `failed` / `cancelled`).

### Seek-Aware Queue

The encoding loop listens for playback position changes (seeks and pauses) via the `SeekSignalBroker`. When a client seeks:

- In-flight segments always finish -- workers are never killed.
- The remaining pending queue is reorganized so segments closest to the seek target are encoded first.
- This ensures the client gets watchable content around the new position as quickly as possible.

On pause, dispatching stops and the loop waits. In-flight segments are allowed to finish before the loop goes idle.

### Graceful Restarts

When the server receives a shutdown signal, the `GracefulRestartHandler` persists all active job state to disk (`var/transcode_state/<job-public-id>.json`). The state file includes the list of completed segments, the current segment index, and the quality tier.

On restart, the handler scans for persisted state files, verifies that previously completed segments still exist on disk, and resumes encoding from the next unencoded segment. This means a server restart does not lose transcoding progress.

See [Monitoring](monitoring.md) for checking job status and pool health.

## Hardware Acceleration

Hardware acceleration is **not currently implemented**. FFmpeg uses `libx265` software encoding exclusively.

The `docker-compose.yml` file contains commented-out configuration for both NVIDIA (NVENC/NVDEC) and Intel (QSV/VAAPI) GPU passthrough. Enabling this in the future would require:

- Installing the NVIDIA Container Toolkit on the host and uncommenting the GPU device reservation.
- Passing `/dev/dri` into the container for Intel iGPU access.
- Updating `TranscodePoolWorker` to select a hardware encoder when available.

## Audio Transcoding

Music tracks stream as their original files unless a client asks for another format. A client that cannot play a track's format, such as a browser without FLAC support, asks for it transcoded:

```
GET /api/stream/track?id=<track public ID>&format=opus
GET /api/stream/track?id=<track public ID>&format=mp3&bitrate=192000
```

`format` is `opus`, `aac` or `mp3`. `bitrate` is optional, in bits per second, and requires `format`. Baander checks the listener's access to the track before it transcodes anything, exactly as for the original file.

### Settings

Two [server settings](configuration.md#server-settings) govern audio transcoding. Baander reads them on every request. Video transcoding is outside both.

| Setting | Default | Effect |
|---------|---------|--------|
| `transcode.enabled` | `false` | While off, a request that names a format gets `403` saying transcoding is turned off, and no encode starts. Requests without a format stream the original either way. |
| `transcode.max_bitrate` | `320` | The highest bitrate, in kbps: `128`, `192`, `256` or `320`. A higher requested bitrate is lowered to it, and a request without a bitrate gets it. |

Baander then fits the bitrate to the format's range, 32 to 256 kbps for Opus and 32 to 320 kbps for AAC and MP3, in whole kilobits. An Opus stream therefore never exceeds 256 kbps, whatever the maximum.

An unsupported format, a bitrate that is not a positive whole number, or a bitrate without a format gets `400`. A failed encode gets `500` and is logged with the track, format and bitrate.

### Cached renditions

Each track, format and bitrate has one cached rendition under `CONVERT_STORAGE_PATH/audio-renditions/<track>/`. Its file name includes a fingerprint of the source file, so a replaced source is encoded again.

The first request for a rendition starts the encode and streams the output while FFmpeg writes it. Its length is not known yet, so that response carries `Accept-Ranges: none` and the listener cannot seek. A second request for the same rendition while it is encoding joins that encode instead of starting another. Once the rendition is complete, later requests get the cached file with full byte-range support, so seeking works. Encodes run in the [CPU process pool](#cpu-process-pool), never in the HTTP worker.

The transcode cache sweep (`app:transcode:cache-sweep`) treats each track's renditions, all formats and bitrates together, as one cache unit under the same age limit and size budget as video segments. A track's renditions count as in use while an encode has written to a partial rendition recently.

### Web player

The web player always asks for the original file first. When the browser rejects it as an unsupported source, the player asks for the same track as Opus, then AAC, then MP3, without a bitrate, so the server applies `transcode.max_bitrate`. While a transcoded stream is still encoding, the player refuses to seek and says so. If the transcoded stream fails as well, playback stops with an error message. With `transcode.enabled` off, a track the browser cannot play therefore does not play in the web player.

## Configuration

Transcoding does not have dedicated environment variables. Audio transcoding is switched on and capped with the [server settings](#settings) above. The rest of the relevant configuration is:

- **CPU process pool worker count** -- set `$workerCount` in `config/services.yaml` (default: 2). See [Configuration](configuration.md) for general server settings.
- **State directory** -- persisted job state is written to `var/transcode_state/` inside the container. Ensure this directory is on a persistent volume if you deploy with ephemeral containers.
- **FFmpeg path** -- hardcoded to `/usr/local/bin/ffmpeg` in `TranscodePoolWorker`.
- **Source media path** -- mounted read-only into the container (see `docker-compose.yml` volumes).
