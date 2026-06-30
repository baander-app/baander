# Transcoding

Baander converts video files into streamable CMAF segments on-the-fly using FFmpeg. Segments are served via HLS v6 and DASH manifests, with per-segment adaptive bitrate selection across multiple quality tiers.

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

## Configuration

Transcoding does not have dedicated environment variables. The relevant configuration is:

- **CPU process pool worker count** -- set `$workerCount` in `config/services.yaml` (default: 2). See [Configuration](configuration.md) for general server settings.
- **State directory** -- persisted job state is written to `var/transcode_state/` inside the container. Ensure this directory is on a persistent volume if you deploy with ephemeral containers.
- **FFmpeg path** -- hardcoded to `/usr/local/bin/ffmpeg` in `TranscodePoolWorker`.
- **Source media path** -- mounted read-only into the container (see `docker-compose.yml` volumes).
