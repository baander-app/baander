# Video transcoding and playback state

Use this reference to interpret video quality, job state, playback controls and restart behavior. Start with [Transcoding](transcoding.md) to identify the delivery path and confirm worker and storage prerequisites.

## Quality Tiers

The `transcode` capability's encoded quality ladder defines six HEVC tiers. The client selects a tier at session creation time based on its capabilities and available bandwidth. Original and copied video retain the source's encoded quality.

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

## Job Monitoring and State

### Segment-by-Segment Tracking

Each transcode job tracks progress per-segment. When a segment completes, the job records the segment index, output file path, file size, and duration, and this data is persisted to the database. Manifests are built from the generation's published index on storage, not from these rows.

State transitions for a transcode job: `pending` -> `in_progress` -> `completed` (or `failed` / `cancelled`).

### Playback controls and generations

Each client session has independent controls and execution generations. A seek preserves pause intent, and temporary control failures need not detach the media. A worker publishes output only for its current generation. See [Playback controls and generations](../../part-2-developer-guide/development/video-playback-controls.md#playback-controls-and-generations) for the client recovery rules, cursor mapping, readiness thresholds and ended-session responses.

### Worker learning state

Each video child restores the worker's quality-governor learning, starts resource
sampling and monitoring, then persists its new samples when it stops. Learning
state lives under `CONVERT_STORAGE_PATH/qol-state`. A bounded file lock merges
new samples with stored learning so independent children do not overwrite each
other's updates.

### Restarts

A web restart [drains client connections](../maintenance/web-server-restarts.md#stopping-and-restarting-the-web-server) while the independent worker keeps encoding. Reconnecting clients read persisted desired state and obtain the current generation; attaching again does not create another encoder for the same owned execution.

A worker stop closes admission and stops its job children and encoder groups. A replacement requires explicit deployment recovery and retirement of the old container. Execution claims use database-clock leases, ownership tokens and generation checks; replacement work cannot publish through an old claim. PostgreSQL runtime rows are authoritative. Files under `var/transcode_state/` do not authorize execution.

See [Monitoring](../operations/README.md) for job status and [Worker deployment](../workers/worker-deployment.md) for recovery.
