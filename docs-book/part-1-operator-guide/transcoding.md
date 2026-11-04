# Transcoding

Baander selects a delivery path from the receiving client's media capabilities and
the deployment's startup profile. Compatible files can play directly; other sources
may use an existing artifact, stream copy or encoding.

Under `full`, video encoding produces CMAF segments served through HLS v6 and DASH.
Music can be encoded to Opus, AAC or MP3 when audio transcoding is enabled.
Under `household`, audio and video re-encoding are prohibited. See
[Deployment profiles](configuration.md#deployment-profiles) and
[Audio Transcoding](#audio-transcoding).

Before testing conversion, confirm that the media is in the catalog, its source path is readable, and the independent worker deployment is running with shared output storage. A healthy web endpoint alone does not establish encoder availability. [Storage and Data](storage-and-data.md#shared-media-paths) explains the mounts; [Background Work](background-work.md) explains the deployment and its recovery boundary.

For a playback failure, first identify the selected delivery path. Original audio
and video need no encoder; active copy or encoding jobs need an admitted worker,
source access and writable derived storage. A compatibility refusal differs from a
job waiting for capacity. Use [Monitoring](monitoring.md) to check the responsible
job and worker before changing playback settings or restarting services.

## How It Works

The receiving browser reports supported containers, codecs and transports before
it receives a media URL. A remote destination uses its own capabilities. Negotiation
checks the source and access rights, then returns one of these outcomes:

| Outcome | Playback behavior |
|---------|-------------------|
| Original | A signed source file plays through the native media element. |
| Cached | A compatible completed artifact plays without starting a producer. |
| Copy | An owned job repackages selected streams without audio or video re-encoding. Video uses adaptive delivery. |
| Encoded | The `full` profile may start the existing encoding path, subject to audio settings where applicable. |
| Incompatible | Playback stops with an explanation; capacity retries cannot enable a prohibited conversion. |

A cached artifact authorizes delivery only. Missing, partial or evicted output
returns to negotiation; it does not grant permission to regenerate encoded media
in `household`. Text subtitles keep their adaptive delivery path. A subtitle
burn-in request requires re-encoding and is incompatible with `household`.

For an active video job, web commits the session and its durable execution intent
together. The independent worker claims that intent, probes the source and starts
FFmpeg under its media role. Web serves the published files and keeps the client
connections. The worker publishes the selected codecs, source duration and timeline
start, then writes init and media fragments into the execution generation's
directory. Encoded output uses six-second CMAF segments; copied output retains
source timestamps and keyframe boundaries.

Video jobs are scoped to the playback user, client session, video, selected
presentation and quality tier. Their output paths include the job UUID and
execution generation. Two clients keep independent controls and jobs even when
they use the same account. The client-session request ID remains stable through
negotiation, signing, refresh and reconnect. New seek commands use fresh request
identities; a retry retains that command's identity. Adaptive HLS and DASH
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
local position and play or pause intent. For an active job, renewal waits for an
active seek or position restoration to finish and refreshes the same owned job.
Original and cached delivery renew through negotiation, which may select another
permitted path if the prior artifact is no longer available. A media network
failure after expiry or an observed signing outage permits one replacement of
the affected attachment. An authenticated report of unavailable media also
permits one replacement after a media network failure, provided the attachment
was ready and its position restoration had finished. Recovery preserves the
cursor for the same execution; a new generation restores its canonical position
and play or pause intent.
Temporary signing failures preserve accepted playback state, including pending
restoration. Decoder errors, failed play requests and explicit signing refusals
remain playback failures.

## Quality Tiers

The `full` profile's encoded quality ladder defines six HEVC tiers. The client selects a tier at session creation time based on its capabilities and available bandwidth. Original and copied video retain the source's encoded quality.

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

An encoded session is assigned an audio profile that controls codec, bitrate, channel layout, sample rate, loudness target, and dynamic range compression. Stream copy preserves the selected source audio instead of applying these transformations.

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

Under `full`, the media role admits as many video jobs and audio renditions at
once as the profile's [media slots](configuration.md#media-slots), 8 video and 12
audio by default; stream copy uses the slots of its kind, and a kind with zero
slots is refused. Under `household`, audio and video copy jobs share two slots,
and encoding is refused. Each video job has a fresh PHP kernel and one private CPU helper,
started before any database or Redis connection opens. FFmpeg processes and their
process groups belong to the worker deployment. Web has no CPU pool or encoder
fallback.

Private media jobs use one asynchronous file-I/O thread, configured before helper
forks or reactor startup. The shipped Swoole build uses its thread backend for
coroutine file reads under the worker's default Docker security restrictions.
The worker containers carry no CPU, memory or PID limit, so the slots are the only
bound on media work. The native daemon starts each FFmpeg command at nice 10 and
with a higher out-of-memory score than the worker's own processes. Ordinary
admission can change within the profile's bounds; all ordinary consumers remain
resident when parked. Media slots remain independent of the ordinary target.
[System Requirements](system-requirements.md#choose-media-slots) gives the measured
cost of each job and how to choose the slots; see
[Worker deployment](commands/README.md#worker-deployment) for the storage recipe.

## Job Monitoring and State

### Segment-by-Segment Tracking

Each transcode job tracks progress per-segment. When a segment completes, the job records the segment index, output file path, file size, and duration. This data is persisted to the database and used by the manifest generator to build accurate playlists.

State transitions for a transcode job: `pending` -> `in_progress` -> `completed` (or `failed` / `cancelled`).

### Playback controls and generations

Pause, resume, seek, audio-profile changes, progress and cancellation update
durable desired state synchronously. The transaction applies each change to
sibling renditions of the same client session and video. Other clients' sessions
remain independent, including clients signed in to the same account. Durable per-session/request receipts prevent a repeated
request from applying again after an intervening control. Controls have ordered revisions; playback progress includes the currently attached generation and revision so an old player cannot overwrite a later seek. Cancellation is terminal. Seeking while paused preserves the paused state.

Native seek events carry the generation captured when the media element was
attached. The server checks it under the existing playback locks and ignores an
old event without changing the generation, revision or desired position. Explicit
seek requests remain new user intent. Progress separately requires both the
current generation and revision; it cannot overwrite a later explicit seek.

When native seek gestures arrive while a seek is still in flight, the player
retains the latest queued target and keeps pause and resume commands in order.
A following native seek normally uses the generation acknowledged by its
predecessor. If a seek encounters a temporary network, timeout or server failure,
the player may resend that exact request once with its original identity and
generation. A successful resend without an accepted generation requires a fresh
authenticated read started after that response before the latest unsent target
can use the canonical generation. A read after a timeout alone cannot establish that delivery has
settled. A successful first response without an accepted generation still
abandons the queued tail. Stale, terminal or unproven responses cannot continue
the chain.

If both transport attempts fail, the player abandons the unsent tail and restores
the current execution and cursor from fresh authenticated state, preserving pause
intent. It sends no third copy. A new gesture immediately reserves its place in
the control queue. Consecutive unsent seeks coalesce to the latest target, while
pause and resume commands keep their order between seeks. Submission waits for
a fresh authenticated generation and uses a new request identity. This resend
policy applies only to transient seek failures; pause, resume and progress retain
their separate recovery rules.

Replacing the source, session or player component invalidates pending intent;
generation checks and the latest pause/resume intent still apply.

Temporary network, timeout or server failures while the player reports progress
automatically leave the media attachment, local position and play or pause intent
in place. The next eligible report sends the current position rather than
replaying the failed update.

Temporary network, timeout or server failures during pause or resume preserve the
media and its current local position. The player keeps the requested status until
a fresh authenticated read can reconcile it with the server's accepted status;
newer local pause or resume intent takes precedence. Reconciliation changes only
play or pause status, without replaying the command, rewinding to an older server
position or submitting another seek. Initial signing failures and nontransient
control failures retain their existing handling.

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

The `full` worker probes encoder capabilities and publishes its selected profile for media execution. The household profile skips encoder qualification because it cannot admit re-encoding. The current admitted deployment recipe rejects devices and device requests, so it does not provide GPU passthrough. Do not add GPU access to the web container to enable background encoding.

## Audio Transcoding

Music playback first negotiates original, cached or copied delivery. If encoding
is needed, `full` and the audio setting must both allow it. Direct API clients can
request an encoded format explicitly:

```
GET /api/stream/track?id=<track public ID>&format=opus
GET /api/stream/track?id=<track public ID>&format=mp3&bitrate=192000
```

`format` is `opus`, `aac` or `mp3`. `bitrate` is optional, in bits per second, and requires `format`. Baander checks the listener's access to the track before it transcodes anything, exactly as for the original file.

### Settings

Two [server settings](configuration.md#server-settings) govern audio transcoding. Baander reads them on every request. Video transcoding is outside both. In `full`, a direct format request while `transcode.enabled` is off receives `403`; enabling it still cannot authorize encoding in `household`.

| Setting | Default | Effect |
|---------|---------|--------|
| `transcode.enabled` | `false` | Allows audio encoding only when the startup profile also permits it. Original and negotiated stream-copy delivery do not enable encoding. |
| `transcode.max_bitrate` | `320` | The highest bitrate, in kbps: `128`, `192`, `256` or `320`. A higher requested bitrate is lowered to it, and a request without a bitrate gets it. |

Baander then rounds the bitrate down to the highest step of a fixed ladder that does not exceed it: 64, 96, 128, 160, 192, 256 and 320 kbps. A request below 64 kbps gets 64 kbps. The Opus ladder stops at 256 kbps, so an Opus stream never exceeds 256 kbps, whatever the maximum. Because only these steps exist, each track has at most seven renditions per format.

An unsupported format, a bitrate that is not a positive whole number, or a bitrate without a format gets `400`. A failed encode gets `500` and is logged with the track, format and bitrate.

Renditions are admitted through durable PostgreSQL runtime state. Under `full`, the media role runs as many audio encodes at once as its audio slots, 12 by default, independently of its video slots. Requests for the same source fingerprint, format and bitrate join the same accepted work. Web waits up to 60 seconds for output to start; an unavailable worker or failed generation produces an encode failure rather than an inline web encode. When every audio slot stays busy for that whole wait, `/api/stream/track` and `/api/stream/original/track` answer `503` with `Retry-After: 5` and the reason `audio_slots_busy`. The work keeps its place in the queue, and the web player's retry follows it once a slot frees.

### Cached renditions

Each track, format and bitrate has one cached rendition under `CONVERT_STORAGE_PATH/audio-renditions/<track>/`. Each execution writes a generation directory; its file name includes a fingerprint of the source file, so a replaced source is encoded again.

The first request for a rendition starts the encode and streams the output while FFmpeg writes it. Its length is not known yet, so that response carries `Accept-Ranges: none` and the listener cannot seek. A second request for the same rendition while it is encoding joins that encode instead of starting another. Once the rendition is complete, later requests get the cached file with full byte-range support, so seeking works. Each encode has a worker-owned claim and generation. Web follows only that accepted generation, checks live ownership while output is growing and accepts its final file only after completion is committed. Lost ownership or a changed generation cuts the stream as an error.

If a completed cache file has been evicted, a compare-and-swap against its
completed generation resets the same canonical runtime row to pending when the
requested execution is permitted. The next worker claim advances its generation
and rebuilds the file; concurrent readers do not create another canonical row.
Completed-artifact reuse in `household` does not authorize an encoded rebuild.

The transcode cache sweep (`app:transcode:cache-sweep`) treats each track's renditions, all formats and bitrates together, as one cache unit under the same age limit and size budget as video segments. A track's renditions count as in use while an encode has written to a partial rendition recently.

### Web player

The web player negotiates with the receiving browser's capabilities before loading
a track. If that source fails, it reports the failure to negotiation before trying
an encoding fallback. An incompatible result stops playback with its explanation.
When encoding is allowed, the existing Opus, AAC and MP3 fallback uses the server's
`transcode.max_bitrate`. A growing encoded rendition has no byte-range seeking;
the player restores position after reconnect once a seekable source is available. Original and
completed-file playback use native seeking; copied playback follows the actual
output's seeking support.

When the web server restarts during a transcoded stream, the connection is cut as an error while the worker continues encoding. The next request joins the accepted rendition or reads its completed file. The player keeps the listener's position and continues once byte-range seeking is available; see [Listeners during a restart](upgrading.md#listeners-during-a-restart).

## Configuration

Audio transcoding is switched on and capped with the [server settings](#settings) above. Execution uses the worker deployment's admitted resources and shared storage:

- **Derived output** uses `CONVERT_STORAGE_PATH`, default `/storage/conversions`, mounted at the same path in web and worker.
- **Application storage and state** require shared writable worker volumes covering `APP_HOME/storage` and `APP_HOME/var/transcode_state`, where `APP_HOME` is the image application path generated during [installation](getting-started.md).
- **Source media** uses an explicit `MEDIA_STORAGE_PATH`, for example `/media/library`, covered by a read-only worker source volume. Use the identical source root in both roles. Preserve web access needed for authorized library deletion.
- **Capacity** comes from the startup profile: `full` permits as many video jobs and audio renditions as its [media slots](configuration.md#media-slots), 8 and 12 by default; `household` permits two shared copy jobs and no encoding. The [host manifest](commands/README.md#worker-deployment) carries the same profile; it declares no memory or process reservations.

## Checking playback

Start with one known catalog item and check its negotiated delivery kind. For an
active copy or encoding job, confirm that the job leaves `pending`, output becomes
available and the player's source position advances. For original or cached delivery,
check playback progress without expecting a live job. A missing artifact, a pending
job and a terminal compatibility refusal require different fixes.

If playback stops during a restart, identify which role restarted. Web owns the client connection; the worker owns FFmpeg. Restarting web does not restart encoders or repair an unavailable worker. Follow [Troubleshooting](troubleshooting.md) for evidence collection and [Background Work](background-work.md) for worker recovery. Do not remove runtime rows or generation files to force replacement work.

Derived output can be regenerated when the source remains readable, but application storage also holds uploads and extracted assets. Follow [Storage and Data](storage-and-data.md#what-must-be-preserved) and [Backup and Recovery](backup-and-recovery.md) before removing storage directories.
