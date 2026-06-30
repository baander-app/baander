# Transcode

`src/Transcode/` handles CMAF video transcoding via FFmpeg. It is the most infrastructure-heavy context in the codebase — it manages long-running encoding jobs, produces HLS v6 and DASH manifests, implements a seek-aware segment priority queue, and offloads CPU-bound FFmpeg work to the Swoole process pool.

## Domain Models

### Aggregate Roots

| Root | Description |
|------|-------------|
| `TranscodeJob` | A single encoding job with status, progress tracking, and quality tier |
| `TranscodeSession` | A live transcoding session with real-time state, priority, and lifecycle |

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
| `CleanupOrphanedJobsCommand` | `CleanupOrphanedJobsHandler` | Remove stale or incomplete jobs |

## Ports

This context defines six port interfaces, making it the most port-heavy in the codebase:

| Port | Purpose |
|------|---------|
| `FFmpegPortInterface` | FFmpeg process management (probe, encode, segment) |
| `TranscodeJobPortInterface` | Job lifecycle operations |
| `TranscodeSessionPortInterface` | Session lifecycle operations |
| `SegmentCachePortInterface` | In-memory caching of encoded segments |
| `TranscodeStoragePortInterface` | Persistent storage of segment files |
| `TranscodeStreamingPortInterface` | Streaming segment delivery to clients |

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
| PATCH | `/api/transcode/sessions/{uuid}/pause` | Pause a session |
| PATCH | `/api/transcode/sessions/{uuid}/resume` | Resume a paused session |
| DELETE | `/api/transcode/sessions/{uuid}` | Cancel a session |
| GET | `/api/transcode/jobs/{publicId}` | Get a single job status |

### Streaming

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/stream/{videoId}/master.m3u8` | HLS master manifest |
| GET | `/api/stream/{jobPublicId}/media.m3u8` | HLS media playlist |
| GET | `/api/stream/{videoId}/manifest.mpd` | DASH manifest |
| GET | `/api/stream/{videoId}/quality-ladder` | Available quality tiers |
| GET | `/api/stream/media` | Direct media file streaming |

## Cross-Context Relationships

| Direction | Context | Details |
|-----------|---------|---------|
| Depends on | Shared | `Uuid`, `PublicId`, `ProcessPool`, `Async`, `JobMonitoringMiddleware` |
| Depended on by | Party | References transcode jobs for synchronized playback |
| Depended on by | Notification | Listens to transcode domain events for user notifications |

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
