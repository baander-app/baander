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

Media's `GET /api/stream/track` streams a track transcoded to Opus, AAC or MP3 when the request names a `format`. Media checks library access and reads the system settings `transcode.enabled` (off by default) and `transcode.max_bitrate` (kbps, default `320`) on every request; `TranscodeSettingDefinitions` contributes both. With transcoding off, a request with a format is `403` and no encode starts. Otherwise Media caps the requested bitrate at the maximum, or uses the maximum when none is requested, and calls `AudioRenditionPortInterface::open()`. `AudioRenditionFormat::supportedBitrate()` then snaps the bitrate down to `BITRATE_LADDER` (64 to 320 kbps in seven steps, Opus at most 256 kbps), so a track has at most seven renditions per format.

`AudioRenditionCache` keeps one rendition per track, format and bitrate in `audio-renditions/<track>/` under the transcode storage root, named with a fingerprint of the source file. A complete rendition is served as a file with Range support. Otherwise the call starts the encode, or joins the one already running, and returns a progressive stream that Media sends with `Accept-Ranges: none`. Coordination goes through the filesystem, so it holds across HTTP workers: `AudioRenditionPoolWorker` holds an exclusive lock on the rendition's lock file for the whole encode, FFmpeg writes a `.part` file that listeners read while it grows, and the encode renames it to the final file under the lock. A pool job that finds the lock held returns at once and its request follows the running encode, so a duplicate job never holds a pool worker. Before starting an encode, `open()` removes a `.part` left by an encoder that died, so listeners only read bytes from the encode they wait for. Encodes run in the CPU process pool through `CpuProcessPoolInterface::dispatchWithinLimit()`: the pool counts queued and running rendition jobs in a `Swoole\Table` shared by every HTTP worker, and `open()` throws `AudioRenditionBusyException` (Media answers `503` with `Retry-After`) instead of dispatching beyond `$maxConcurrentEncodes`. Outside the Swoole server (CLI, tests) the encode runs in the calling process before streaming. `SweepTranscodeCacheHandler` treats each track's rendition directory as one cache unit (`audio-renditions/<track>`) under the same TTL and size budget as video directories.

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
| POST | `/api/stream/sign` | Sign a stream URL for playback |
| GET | `/api/stream/{videoId}/master.m3u8` | HLS master manifest |
| GET | `/api/stream/{jobPublicId}/media.m3u8` | HLS media playlist |
| GET | `/api/stream/{videoId}/manifest.mpd` | DASH manifest |
| GET | `/api/stream/{videoId}/quality-ladder` | Available quality tiers |
| GET | `/api/stream/{jobPublicId}/audio/{language}/media.m3u8` | HLS audio manifest |
| GET | `/api/stream/{jobPublicId}/subtitles/{language}/media.m3u8` | HLS subtitle manifest |
| GET | `/api/stream/{jobPublicId}/init.mp4` | HLS init segment |
| GET | `/api/stream/{jobPublicId}/seg_{index}.m4s` | HLS media segment |

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
