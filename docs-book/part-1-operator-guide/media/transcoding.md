# Transcoding

Baander selects a delivery path from the receiving client's media capabilities and
whether the deployment is `transcode` or `remux`. Compatible files can play directly; other sources
may use an existing artifact, stream copy or encoding.

Under `transcode`, video encoding produces CMAF segments served through HLS v6 and DASH.
Music can be encoded to Opus, AAC or MP3 when audio transcoding is enabled.
Under `remux`, audio and video re-encoding are prohibited. See
[Deployment capability and size](../configuration/deployment-profiles.md#deployment-capability-and-size) and
[Audio Transcoding](audio-transcoding.md#audio-transcoding).

Before testing conversion, confirm that the media is in the catalog, its source path is readable, and the independent worker deployment is running with shared output storage. A healthy web endpoint alone does not establish encoder availability. [Storage and Data](../getting-started/storage-and-data.md#shared-media-paths) explains the mounts; [Background Work](../workers/background-work.md) explains the deployment and its recovery boundary.

For a playback failure, first identify the selected delivery path. Original audio
and video need no encoder; active copy or encoding jobs need an admitted worker,
source access and writable derived storage. A compatibility refusal differs from a
job waiting for capacity. Use [Monitoring](../operations/README.md) to check the responsible
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
| Encoded | A `transcode` deployment may start the existing encoding path, subject to audio settings where applicable. |
| Incompatible | Playback stops with an explanation; capacity retries cannot enable a prohibited conversion. |

A cached artifact authorizes delivery only. Missing, partial or evicted output
returns to negotiation; it does not grant permission to regenerate encoded media
under `remux`. Text subtitles keep their adaptive delivery path. A subtitle
burn-in request requires re-encoding and is incompatible with `remux`.

For an active video job, web commits the session and its durable execution intent
together. The independent worker claims that intent, probes the source and starts
FFmpeg under its media role. Web serves the published files and keeps the client
connections. The worker publishes the selected codecs, source duration and timeline
start, then writes init and media fragments into the execution generation's
directory. Encoded output uses six-second CMAF segments; copied output retains
source timestamps and keyframe boundaries.

Video jobs are scoped to the playback user, client session, video, selected presentation and quality tier. Their output paths include the job UUID and execution generation. Two clients keep independent controls and jobs even when they use the same account. The client-session request ID remains stable through negotiation, signing, refresh and reconnect. New seek commands use fresh request identities; a retry retains that command's identity.

Adaptive HLS and DASH requests sign an immutable rendition set containing each owned job and its own generation; a manifest cannot substitute another user's job or assume all tiers share one generation.

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

The web video player obtains a newer signed URL near expiry while preserving its local position and play or pause intent. For an active job, renewal waits for an active seek or position restoration to finish and refreshes the same owned job. Original and cached delivery renew through negotiation, which may select another permitted path if the prior artifact is no longer available. A media network failure after expiry or an observed signing outage permits one replacement of the affected attachment.

An authenticated report of unavailable media also permits one replacement after a media network failure, provided the attachment was ready and its position restoration had finished. Recovery preserves the cursor for the same execution; a new generation restores its canonical position and play or pause intent. Temporary signing failures preserve accepted playback state, including pending restoration. Decoder errors, failed play requests and explicit signing refusals remain playback failures.

## Playback reference

- [Video quality and state](video-transcoding.md): quality tiers, audio profiles, job progress and restart behavior.
- [Audio transcoding](audio-transcoding.md): enablement, bitrate limits, AAC implementation and cached renditions.
- [Hardware acceleration](hardware-acceleration.md): device configuration, encoder qualification and measured capacity limits.

## Worker execution capacity

Under `transcode`, the media role admits as many video jobs and audio renditions at once as the profile has [media slots](../configuration/deployment-profiles.md#media-slots): 8 video and 12 audio by default. Stream copy uses the slots of its kind, and a kind with zero slots is refused. Under `remux`, audio and video copy jobs share the configured copy slots, 1 to 8, and encoding is refused. Each video job has a fresh PHP kernel and runs at most one helper command at a time through the native daemon.

FFmpeg processes and their process groups belong to the worker deployment. Web has no CPU pool or encoder fallback.

Private media jobs use one asynchronous file-I/O thread, configured before the job connects to the native daemon or the database. The shipped Swoole build uses its thread backend for coroutine file reads under the worker's default Docker security restrictions. The worker containers carry no CPU, memory or PID limit, so the slots are the only bound on media work. The native daemon starts each FFmpeg command at nice 10 and with a higher out-of-memory score than the worker's own processes.

Ordinary admission can change within the profile's bounds; all ordinary consumers remain resident when parked. Media slots remain independent of the ordinary target. [System Requirements](../getting-started/system-requirements.md#choose-media-slots) gives the measured cost of each job and how to choose the slots; see [Worker deployment](../workers/worker-deployment.md) for the storage recipe.

## Configuration

Enable and cap audio transcoding with its [server settings](audio-transcoding.md#settings). Execution uses the worker deployment's admitted resources and shared storage:

- **Derived output** uses `CONVERT_STORAGE_PATH`, default `/storage/conversions`, mounted at the same path in web and worker.
- **Application storage and state** require shared writable worker volumes covering `APP_HOME/storage` and `APP_HOME/var/transcode_state`, where `APP_HOME` is the image application path generated during [installation](../getting-started/installation.md).
- **Source media** uses an explicit `MEDIA_STORAGE_PATH`, for example `/media/library`, covered by a read-only worker source volume. Use the identical source root in both roles. Preserve web access needed for authorized library deletion.
- **Capacity** comes from the startup profile: `transcode` permits as many video jobs and audio renditions as its [media slots](../configuration/deployment-profiles.md#media-slots), 8 and 12 by default; `remux` permits as many copy jobs as its shared copy slots and no encoding. The [host manifest](../workers/worker-deployment.md#manifest-format) carries the same profile; it declares no memory or process reservations.

## Checking playback

Start with one known catalog item and check its negotiated delivery kind. For an
active copy or encoding job, confirm that the job leaves `pending`, output becomes
available and the player's source position advances. For original or cached delivery,
check playback progress without expecting a live job. A missing artifact, a pending
job and a terminal compatibility refusal require different fixes.

If playback stops during a restart, identify which role restarted. Web owns the client connection; the worker owns FFmpeg. Restarting web does not restart encoders or repair an unavailable worker. Follow [Troubleshooting](../operations/troubleshooting.md) for evidence collection and [Background Work](../workers/background-work.md) for worker recovery. Do not remove runtime rows or generation files to force replacement work.

Derived output can be regenerated when the source remains readable, but application storage also holds uploads and extracted assets. Follow [Storage and Data](../getting-started/storage-and-data.md#what-must-be-preserved) and [Backup and Recovery](../maintenance/backup-and-recovery.md) before removing storage directories.
