# Transcode

`src/Transcode/` handles CMAF video transcoding via FFmpeg. It is the most infrastructure-heavy context in the codebase — it manages long-running encoding jobs, produces HLS v6 and DASH manifests, implements a seek-aware segment priority queue, and offloads CPU-bound FFmpeg work to the Swoole process pool.

## Domain Models

### Aggregate Roots

| Root | Description |
|------|-------------|
| `TranscodeJob` | A single encoding job with status, progress tracking, and quality tier |
| `TranscodeSession` | A live transcoding session with real-time state, priority, and lifecycle |
| `SegmentMetadata` | Metadata for an encoded segment |

### Value Objects

| Type | Kind | Description |
|------|------|-------------|
| `QualityTier` | Value object | HEVC quality definitions (resolution, bitrate, codec parameters) |
| `AudioProfile` | Value object | Audio encoding settings (codec, bitrate, channels) |
| `LoudnessStandard` | Value object | Audio normalization targets (e.g., EBU R128) |
| `SessionState` | Enum | `Pending`, `Preparing`, `Active`, `Paused`, `Completed`, `Failed`, `Cancelled` |
| `SessionPriority` | Value object | Session priority for the segment queue |
| `TranscodeStatus` | Enum | Job status values |
| `VideoProbeResult` | Value object | Parsed output from FFprobe |

## Domain Services

| Service | Purpose |
|---------|---------|
| `QualityLadder` | Defines the available quality tiers and their encoding parameters |
| `AudioProcessingRules` | Rules for audio encoding, normalization, and loudness targeting |

## Commands & Handlers

| Command | Handler | Purpose |
|---------|---------|---------|
| `CreateTranscodeSessionCommand` | `CreateTranscodeSessionHandler` | Start a new transcoding session |
| `PauseTranscodeSessionCommand` | `PauseTranscodeSessionHandler` | Pause an active session |
| `ResumeTranscodeSessionCommand` | `ResumeTranscodeSessionHandler` | Resume a paused session |
| `CancelTranscodeSessionCommand` | `CancelTranscodeSessionHandler` | Cancel a session and clean up resources |
| `UpdateTranscodeSessionCommand` | `UpdateTranscodeSessionHandler` | Update session state |
| `UpdateTranscodePositionCommand` | `UpdateTranscodePositionHandler` | Update playback position (drives the segment priority queue) |
| `CleanupOrphanedJobsCommand` | `CleanupOrphanedJobsHandler` | Remove stale or incomplete jobs |

## Ports

This context defines the following port interfaces, among others:

| Port | Purpose |
|------|---------|
| `FFmpegPortInterface` | FFmpeg process management (probe, encode, segment) |
| `TranscodeJobPortInterface` | Job lifecycle operations |
| `TranscodeSessionPortInterface` | Session lifecycle operations |
| `SegmentCachePortInterface` | In-memory caching of encoded segments |
| `TranscodeStoragePortInterface` | Persistent storage of segment files |
| `TranscodeStreamingPortInterface` | Streaming segment delivery to clients |
| `StreamAuthPortInterface` | Stream signing/authentication |
| `AudioRenditionPortInterface` | Cached on-the-fly audio transcoding of a track to a format and bitrate; published to Media through the `Transcode Audio Rendition Contract` Deptrac layer with `AudioRenditionFormat`, `AudioRenditionFailedException` and `TranscodeSettingDefinitions` |

## Audio Renditions

Media's `GET /api/stream/track` streams a track transcoded to Opus, AAC or MP3 when the request names a `format`. Media checks library access and reads the system settings `transcode.enabled` (off by default) and `transcode.max_bitrate` (kbps, default `320`) on every request; `TranscodeSettingDefinitions` contributes both. With transcoding off, a request with a format is `403` and no encode starts. Otherwise Media caps the requested bitrate at the maximum, or uses the maximum when none is requested, and calls `AudioRenditionPortInterface::open()`. `AudioRenditionFormat::supportedBitrate()` then snaps the bitrate down to the highest step of `BITRATE_LADDER` that the format supports (seven steps from 64 to 320 kbps; Opus at most 256 kbps), limiting each format to at most seven bitrate choices.

`AudioRenditionCache` admits durable work through `AudioRenditionRuntimePort`.
The runtime key includes the track, format, bitrate and source signature. The
signature fingerprints the resolved source path, modification time and size, so
a repaired source rename selects a separate rendition even when the track ID and
file metadata stay unchanged. The output root is
`audio-renditions/<track>/<signature>/` under the transcode storage root. Repeated
requests for the unchanged source reuse that identity. This is a path and metadata
fingerprint; it does not detect a same-path replacement that preserves both size
and modification time.

The media worker discovers committed pending admissions and claims an execution
generation. `AudioRenditionExecutor` verifies the source signature before encoding,
holds the rendition's filesystem lock and delegates FFmpeg to native execution.
Each generation writes its own `.part` file, then renames it to the final file while
holding durable ownership. A busy filesystem lock defers the attempt. An expired
claim can be recovered in a new generation; stale workers cannot publish its
output. Encoding requires the separately deployed media worker, including for
requests made outside Swoole.

A complete rendition is served as a file with Range support. Otherwise the request
waits for its accepted generation to produce bytes and returns a progressive stream
with `Accept-Ranges: none`. The reader remains pinned to that generation and rejects
ownership loss. A failed encode or startup timeout raises
`AudioRenditionFailedException`, which Media maps to HTTP `500`.

`SweepTranscodeCacheHandler` treats `audio-renditions/<track>` as one cache unit
under the same TTL and size budget as video directories. A rename can leave the
previous completed output until that sweep removes it. If a completed file has
been evicted, the next request resets the observed completed generation and waits
for a worker to rebuild it from the same source.

## Domain Events

| Event | Trigger |
|-------|--------|
| `TranscodeJobCreated` | A new encoding job is created |
| `TranscodeJobCompleted` | An encoding job finishes successfully |
| `TranscodeJobFailed` | An encoding job fails |
| `TranscodeSessionAttached` | A client attaches to a transcoding session |
| `PlaybackPositionChanged` | The playback position in a session changes (drives the segment priority queue) |

## API Endpoints

Endpoints are split between session management and streaming delivery.

### Session Management

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/transcode/sessions` | List transcoding sessions |
| POST | `/api/transcode/sessions` | Create a new session |
| GET | `/api/transcode/sessions/{uuid}` | Get a single session |
| PATCH | `/api/transcode/sessions/{uuid}` | Update a session |
| PATCH | `/api/transcode/sessions/{uuid}/pause` | Pause a session |
| PATCH | `/api/transcode/sessions/{uuid}/resume` | Resume a paused session |
| POST | `/api/transcode/sessions/{uuid}/position` | Update playback position |
| DELETE | `/api/transcode/sessions/{uuid}` | Cancel a session |

### Job Management

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/transcode/jobs` | List transcoding jobs |
| GET | `/api/transcode/jobs/{publicId}` | Get a single job status |
| GET | `/api/transcode/jobs/{publicId}/metrics` | Job encoding metrics |
| POST | `/api/transcode/jobs/cleanup` | Trigger orphaned-job cleanup |

### Streaming

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/stream/sign` | Sign a master manifest URL for playback (token and DPoP proof required; library membership checked) |
| GET | `/api/transcode/{videoId}/quality-ladder` | Available quality tiers (token and DPoP proof required; library membership checked) |
| GET | `/api/transcode/{videoId}/master.m3u8` | HLS master manifest (signed URL) |
| GET | `/api/transcode/{videoId}/manifest.mpd` | DASH manifest (signed URL) |
| GET | `/api/transcode/{jobPublicId}/media.m3u8` | HLS media playlist (signed URL) |
| GET | `/api/transcode/{jobPublicId}/subtitles/{language}/media.m3u8` | HLS subtitle manifest (signed URL) |
| GET | `/api/transcode/{jobPublicId}/init` | Init segment (signed URL) |
| GET | `/api/transcode/{jobPublicId}/segment?index={index}` | Media segment (signed URL) |
| GET | `/api/transcode/{jobPublicId}/subtitles/{language}/{segment}.vtt` | Subtitle segment (signed URL) |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `ProcessPool`, `Async`, `JobMonitoringMiddleware` |
| Depended on by | Party | References transcode jobs for synchronized playback |
| Depended on by | Media | The track stream transcodes audio through `AudioRenditionPortInterface` and reads `TranscodeSettingDefinitions` keys (the `Transcode Audio Rendition Contract` Deptrac layer) |
| Depended on by | Notification | Listens to transcode domain events for user notifications |
| Depends on | QoL | QoL contracts only. `StreamAdmissionListener` (on `TranscodeSessionAttached`, priority 1) and `StreamCompletionListener` (on `TranscodeJobCompleted`) call the stream-admission contract; `QualityFilteringStreamingDecorator` filters manifests by the allowed-tier contract (decoration priority -1, below the cache decorator); `QualityLadderPort` and `EncoderProfileFingerprintAdapter` implement QoL contracts |

## Infrastructure

### FFmpeg

| Component | Purpose |
|-----------|---------|
| `AudioEncoder` | Audio-only encoding |
| `VideoEncoder` | Video encoding with quality tier parameters |
| `SegmentEncoder` | CMAF segment encoding |
| `FFmpegProbeAdapter` | Media file probing (duration, codecs, resolution) |

### Manifest Generation

| Component | Purpose |
|-----------|---------|
| `HlsSegmentWriter` | Writes HLS segments to storage |
| `HlsManifestGenerator` | Generates HLS v6 playlists |
| `QualityLadderRenderer` | Renders quality tier information for clients |
| `DashManifestGenerator` | Generates DASH manifests |

### Caching and Storage

| Component | Purpose |
|-----------|---------|
| `InMemorySegmentCache` | Hot cache for recently encoded segments |
| `TranscodeFileStorage` | Persistent file storage for segments and manifests |
| `SegmentFileResolver` | Resolves segment paths between cache and storage |

### Swoole Integration

| Component | Purpose |
|-----------|---------|
| `ProcessPool` integration | CPU-bound FFmpeg workers via Unix sockets |
| Graceful restart handling | Ensures in-flight transcodes survive worker restarts |
| Job persistence | Jobs survive process restarts by persisting state to the database |
