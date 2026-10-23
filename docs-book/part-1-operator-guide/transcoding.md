# Transcoding

Baander converts video files into streamable CMAF segments on-the-fly using FFmpeg. Segments are served via HLS v6 and DASH manifests, with per-segment adaptive bitrate selection across multiple quality tiers.

Music tracks can also be transcoded to Opus, AAC or MP3 for clients that cannot play the original. That is off until you turn it on; see [Audio Transcoding](#audio-transcoding).

## How It Works

When a client requests a video stream, web commits the session and its durable execution intent together. The independent worker claims that intent, probes the source and starts FFmpeg under its media role. Web serves the published files and keeps the client connections.

1. The worker probes the source and resolves its encoder capabilities.
2. It publishes generation metadata, including the selected codecs, source duration and timeline start.
3. It writes the init and six-second CMAF media segments into that generation's directory.
4. Web serves HLS or DASH manifests and segments only for the current execution generation.

Video jobs are scoped to the playback owner, video and quality tier. Their output
paths include the job UUID and execution generation, so two users watching the
same source do not share controls or generation directories. Adaptive HLS and DASH
requests sign an immutable rendition set containing each owned job and its own
generation; a manifest cannot substitute another user's job or assume all tiers
share one generation.

Encoder selection belongs to the worker. Manifest codec declarations come from the worker's published metadata; web does not probe devices or choose a different encoder while serving a request.

For completed DASH output, the Period duration covers the latest published end
across the selected video and audio tracks, keeping the final audio samples
within the presentation.

Every resource URL in an HLS or DASH manifest inherits the requested manifest's
absolute expiry. HLS subtitle playlists and their VTT files inherit the same
deadline. Repeated playlist reads keep that deadline. Continuing delivery after
it requires a fresh top-level URL from the authenticated signing endpoint.
Requests remain valid during the integer second named by the expiry and are
refused from the following second. Expiry does not interrupt a response admitted
before the deadline or remove bytes already buffered by the player.

The web video player obtains a newer signed URL near expiry while preserving its
local position and play or pause intent. Renewal waits for an active seek or
position restoration to finish and does not start a new execution. A media network
failure after expiry or an observed signing outage permits one replacement of
the affected attachment, preserving its position when signing succeeds again.
Temporary signing failures preserve accepted playback state, including pending
restoration. Decoder errors, failed play requests and explicit signing refusals
remain playback failures.

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

The table describes the HEVC ladder. Published manifests describe the actual worker-selected video and audio codecs for that generation.

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

## Worker execution capacity

The media role admits at most three video jobs and one audio rendition at once. Each video job has a fresh PHP kernel and one private CPU helper, started before any database or Redis connection opens. FFmpeg processes and their process groups belong to the worker deployment. Web has no CPU pool or encoder fallback.

Private media jobs use one asynchronous file-I/O thread, configured before helper
forks or reactor startup. The shipped Swoole build uses its thread backend for
coroutine file reads under the worker's default Docker security restrictions.
AIO threads and encoder threads both count toward the container PID ceiling.

The version 4 host manifest reserves control at least 2944 MiB and 129 PID slots.
Execution requires at least `4160 + 320 * (maximumConcurrency - 1)` MiB plus any
scheduled console reservation, and `256 + maximumConcurrency - 1` PID slots,
including encoder threads. Ordinary admission can change within the profile's
bounds; all ordinary consumers remain resident and fully reserved when parked.
The three video slots and one audio slot remain independent of that target.
These are admission reservations, not measured capacity guarantees. See [Worker deployment](commands/README.md#worker-deployment) for the complete memory and storage recipe.

## Job Monitoring and State

### Segment-by-Segment Tracking

Each transcode job tracks progress per-segment. When a segment completes, the job records the segment index, output file path, file size, and duration. This data is persisted to the database and used by the manifest generator to build accurate playlists.

State transitions for a transcode job: `pending` -> `in_progress` -> `completed` (or `failed` / `cancelled`).

### Playback controls and generations

Pause, resume, seek, audio-profile changes, progress and cancellation update
durable desired state synchronously. The transaction applies each change to
sibling renditions of the same playback owner and video. Other users' sessions
remain independent. Durable per-session/request receipts prevent a repeated
request from applying again after an intervening control. Controls have ordered revisions; playback progress includes the currently attached generation and revision so an old player cannot overwrite a later seek. Cancellation is terminal. Seeking while paused preserves the paused state.

Native seek events carry the generation captured when the media element was
attached. The server checks it under the existing playback locks and ignores an
old event without changing the generation, revision or desired position. Explicit
seek requests remain new user intent. Progress separately requires both the
current generation and revision; it cannot overwrite a later explicit seek.

When native seek gestures arrive while a seek is still in flight, the player
retains the latest queued target and keeps pause and resume commands in order.
Each following native seek uses only the generation returned by its preceding
freshly accepted seek. Stale, replayed, terminal or otherwise unproven responses
cannot continue that chain. Same-session polling leaves the attachment in place
until the chain settles. Replacing the source, session or player component
invalidates pending intent; generation checks and the latest pause/resume intent
still apply.

A seek or recovered execution uses a new generation. The worker retires superseded work before acknowledging its effects. Manifests, init segments, media segments and subtitles carry that generation; stale files cannot satisfy current delivery requests.

Signing returns the current job, generation, revision, readiness, position and desired status. Once ready, it also returns `startTime` and source `duration`. A generation may begin at a nonzero source position. The player adds `startTime` to the media element's local clock for progress and subtracts it when restoring a source position. After a generation change it obtains the current signed manifest and restores position and paused state.

Readiness requires a closed, available fragment at the desired playback cursor,
for running and paused sessions alike. Resuming a still-encoding rendition does
not bypass this check. A pause event caused by a media error does not persist a
new user pause.

### Worker learning state

Each video child restores the worker's quality-governor learning, starts resource
sampling and monitoring, then persists its new samples when it stops. Learning
state lives under `CONVERT_STORAGE_PATH/qol-state`. A bounded file lock merges
new samples with stored learning so independent children do not overwrite each
other's updates.

### Restarts

A web restart [drains client connections](upgrading.md#stopping-and-restarting-the-web-server) while the independent worker keeps encoding. Reconnecting clients read persisted desired state and obtain the current generation; attaching again does not create another encoder for the same owned execution.

A worker stop closes admission and stops its job children and encoder groups. A replacement requires explicit deployment recovery and retirement of the old container. Execution claims use database-clock leases, ownership tokens and generation checks; replacement work cannot publish through an old claim. PostgreSQL runtime rows are authoritative. Files under `var/transcode_state/` do not authorize execution.

See [Monitoring](monitoring.md) for job status and [Worker deployment](commands/README.md#worker-deployment) for recovery.

## Hardware Acceleration

The worker probes encoder capabilities and publishes its selected profile for media execution. The current admitted deployment recipe rejects devices and device requests, so it does not provide GPU passthrough. Do not add GPU access to the web container to enable background encoding.

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

Baander then rounds the bitrate down to the highest step of a fixed ladder that does not exceed it: 64, 96, 128, 160, 192, 256 and 320 kbps. A request below 64 kbps gets 64 kbps. The Opus ladder stops at 256 kbps, so an Opus stream never exceeds 256 kbps, whatever the maximum. Because only these steps exist, each track has at most seven renditions per format.

An unsupported format, a bitrate that is not a positive whole number, or a bitrate without a format gets `400`. A failed encode gets `500` and is logged with the track, format and bitrate.

Renditions are admitted through durable PostgreSQL runtime state. The media role runs one audio encode at a time, independently of its three video slots. Requests for the same source fingerprint, format and bitrate join the same accepted work. Web waits up to 60 seconds for output to start; an unavailable worker or failed generation produces an encode failure rather than an inline web encode.

### Cached renditions

Each track, format and bitrate has one cached rendition under `CONVERT_STORAGE_PATH/audio-renditions/<track>/`. Each execution writes a generation directory; its file name includes a fingerprint of the source file, so a replaced source is encoded again.

The first request for a rendition starts the encode and streams the output while FFmpeg writes it. Its length is not known yet, so that response carries `Accept-Ranges: none` and the listener cannot seek. A second request for the same rendition while it is encoding joins that encode instead of starting another. Once the rendition is complete, later requests get the cached file with full byte-range support, so seeking works. Each encode has a worker-owned claim and generation. Web follows only that accepted generation, checks live ownership while output is growing and accepts its final file only after completion is committed. Lost ownership or a changed generation cuts the stream as an error.

If a completed cache file has been evicted, a compare-and-swap against its
completed generation resets the same canonical runtime row to pending. The next
worker claim advances its generation and rebuilds the file; concurrent readers do
not create another canonical row.

The transcode cache sweep (`app:transcode:cache-sweep`) treats each track's renditions, all formats and bitrates together, as one cache unit under the same age limit and size budget as video segments. A track's renditions count as in use while an encode has written to a partial rendition recently.

### Web player

The web player always asks for the original file first. When the browser rejects it as an unsupported source, the player asks for the same track as Opus, then AAC, then MP3, without a bitrate, so the server applies `transcode.max_bitrate`. While a transcoded stream is still encoding, the player refuses to seek and says so. If the transcoded stream fails as well, playback stops with an error message. With `transcode.enabled` off, a track the browser cannot play therefore does not play in the web player.

When the web server restarts during a transcoded stream, the connection is cut as an error while the worker continues encoding. The next request joins the accepted rendition or reads its completed file. The player keeps the listener's position and continues once byte-range seeking is available; see [Listeners during a restart](upgrading.md#listeners-during-a-restart).

## Configuration

Audio transcoding is switched on and capped with the [server settings](#settings) above. Execution uses the worker deployment's admitted resources and shared storage:

- **Derived output** uses `CONVERT_STORAGE_PATH`, default `/storage/conversions`, mounted at the same path in web and worker.
- **Application storage and state** require shared writable worker volumes covering `/var/www/html/storage` and `/var/www/html/var/transcode_state`.
- **Source media** uses an explicit `MEDIA_STORAGE_PATH`, for example `/media/library`, covered by a read-only worker source volume. Use the identical source root in both roles. Preserve web access needed for authorized library deletion.
- **Capacity** is fixed at three video jobs and one audio rendition in the current media role; configure its explicit memory and descendant reservations in the [host manifest](commands/README.md#worker-deployment).
