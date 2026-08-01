/**
 * @module types
 * @description Core type definitions for the Baander immersive video player.
 *
 * Every type here maps directly to the Symfony backend's API surface:
 *   - QualityTier ← App\Transcode\Domain\ValueObject\QualityTier
 *   - AudioProfile ← App\Transcode\Domain\ValueObject\AudioProfile
 *   - Segment endpoints ← StreamSegmentController
 *   - Manifest endpoints ← StreamManifestController
 *   - Transcode sessions ← TranscodeSessionController
 *
 * @see docs/architecture.md for the full mapping table
 */

// ---------------------------------------------------------------------------
// Network / Transport
// ---------------------------------------------------------------------------

/** Transport protocols the player can use, in priority order. */
export type TransportProtocol = 'moq' | 'webtransport' | 'http3' | 'fetch';

/** Result of a segment or init-segment fetch. */
export interface FetchResult {
  ok: true;
  data: ArrayBuffer;
  /** Milliseconds to first byte. */
  ttfb: number;
  /** Total download time in milliseconds (from request start to last byte). */
  downloadMs: number;
  /** Byte length of the response body. */
  byteLength: number;
  /** Cache hit from offline store. */
  fromCache: boolean;
}

export interface FetchError {
  ok: false;
  status: number;
  /** For 202 responses: seconds to wait before retrying (from Retry-After header). */
  retryAfter?: number;
  reason: string;
}

export type FetchOutcome = FetchResult | FetchError;

// ---------------------------------------------------------------------------
// Backend Domain: Quality Tier
// ---------------------------------------------------------------------------

/**
 * Mirrors App\Transcode\Domain\ValueObject\QualityTier.
 *
 * Returned by:
 *   GET /api/stream/{videoId}/quality-ladder → { data: QualityTierInfo[] }
 *
 * Backend presets:
 *   360p:  640×360   800kbps
 *   480p:  854×480  1400kbps
 *   720p: 1280×720  2800kbps
 *   1080p: 1920×1080 5000kbps
 *   1440p: 2560×1440 10000kbps
 *   4K: 3840×2160 20000kbps
 *
 * All renditions use HEVC (hvc1) with RFC 6381 codec string "hvc1.1.6.L93.B0"
 * and AAC-LC audio (mp4a.40.2).
 */
export interface QualityTierInfo {
  name: string;
  height: number;
  width: number;
  bitrate: number;
  /** VBR ceiling — important for ABR to avoid stalls on complex scenes. */
  maxBitrate: number;
  codec: string;
}

// ---------------------------------------------------------------------------
// Backend Domain: Audio Profile
// ---------------------------------------------------------------------------

/**
 * Mirrors App\Transcode\Domain\ValueObject\AudioProfile.
 *
 * Available profiles: mobile_mono, mobile_stereo, streaming_stereo,
 * streaming_5.1, broadcast_stereo, broadcast_5.1, hifi_stereo, opus_stereo
 */
export type AudioProfileName =
  | 'mobile_mono'
  | 'mobile_stereo'
  | 'streaming_stereo'
  | 'streaming_5.1'
  | 'broadcast_stereo'
  | 'broadcast_5.1'
  | 'hifi_stereo'
  | 'opus_stereo';

// ---------------------------------------------------------------------------
// Backend Domain: Transcode Session
// ---------------------------------------------------------------------------

export type SessionPriority = 'critical' | 'high' | 'normal' | 'low' | 'bulk';

export type SessionStatus =
  | 'pending'
  | 'queued'
  | 'running'
  | 'paused'
  | 'completed'
  | 'failed'
  | 'cancelled';

/** Request body for POST /api/transcode/sessions/ */
export interface CreateTranscodeSessionPayload {
  videoId: string;
  qualityTier?: string;
  audioProfile?: AudioProfileName;
  priority?: SessionPriority;
}

/** Response from GET /api/transcode/sessions/{uuid} */
export interface TranscodeSessionInfo {
  uuid: string;
  status: SessionStatus;
  qualityTier: string;
}

// ---------------------------------------------------------------------------
// Manifest Model (normalized internal representation)
// ---------------------------------------------------------------------------

/**
 * A single segment in the playlist.
 * Maps from both HLS #EXTINF lines and DASH <S> timeline elements.
 */
export interface SegmentInfo {
  /** Zero-based segment index. */
  index: number;
  /** Duration in seconds. */
  duration: number;
  /** Full URL or relative path to the .m4s file. */
  uri: string;
  /** Byte offset within the segment (for byte-range serving). */
  byteRangeStart?: number;
  byteRangeEnd?: number;
}

/**
 * A single rendition (e.g. "1080p" or "720p").
 * Normalised from both HLS #EXT-X-STREAM-INF and DASH <Representation>.
 */
export interface Rendition {
  /** Unique ID within the manifest — matches jobPublicId from the backend. */
  id: string;
  /** Human-readable name (e.g. "1080p"). */
  name: string;
  /** Video width in pixels. */
  width: number;
  /** Video height in pixels. */
  height: number;
  /** Average bitrate in bits per second. */
  bitrate: number;
  /** Maximum bitrate (may differ for VBR). */
  maxBitrate: number;
  /** RFC 6381 codec string, e.g. "hvc1.1.6.L93.B0,mp4a.40.2". */
  codecs: string;
  /** URL of the init segment (fMP4 moov box). */
  initSegmentUrl: string;
  /** Ordered list of media segments. */
  segments: SegmentInfo[];
  /** Target segment duration in seconds (from EXT-X-TARGETDURATION). */
  targetDuration: number;
  /** Total duration in seconds. */
  totalDuration: number;
  /** HLS v6 AUDIO group reference (e.g. "aac"). */
  audioGroupId?: string;
  /** HLS v6 SUBTITLES group reference (e.g. "subs"). */
  subtitleGroupId?: string;
}

/**
 * A single audio track (language variant) from an HLS v6 EXT-X-MEDIA:TYPE=AUDIO group.
 */
export interface AudioTrack {
  /** BCP-47 language tag (e.g. "en", "es"). */
  language: string;
  /** Human-readable name (e.g. "English"). */
  name: string;
  /** HLS GROUP-ID (e.g. "aac"). */
  groupId: string;
  /** Channel count as string (e.g. "2", "6"). */
  channels: string;
  /** URI of the audio media playlist. */
  uri: string;
  /** Whether this is the default track. */
  isDefault: boolean;
  /** URL of the audio init segment. */
  initSegmentUrl: string;
  /** Ordered list of audio media segments. */
  segments: SegmentInfo[];
  /** Target segment duration in seconds. */
  targetDuration: number;
}

/**
 * A single subtitle track from an HLS v6 EXT-X-MEDIA:TYPE=SUBTITLES group.
 */
export interface SubtitleTrack {
  /** BCP-47 language tag. */
  language: string;
  /** Human-readable name. */
  name: string;
  /** HLS GROUP-ID (e.g. "subs"). */
  groupId: string;
  /** URI of the subtitle media playlist. */
  uri: string;
  /** Whether this is the default track. */
  isDefault: boolean;
  /** Ordered list of subtitle segments (.vtt files). */
  segments: SegmentInfo[];
  /** Target segment duration in seconds. */
  targetDuration: number;
}

/** Video content type hint for content-aware ABR heuristics. */
export type ContentHint = 'static' | 'motion' | 'sport' | 'gaming' | 'animation' | 'unknown';

/**
 * Normalised manifest model — the output of UnifiedManifestEngine.
 *
 * Both HLS master.m3u8 and DASH manifest.mpd are parsed into this shape
 * so that downstream modules (buffer, ABR, transport) are format-agnostic.
 */
export interface Manifest {
  /** The video UUID from the backend. */
  videoId: string;
  /** Which manifest format was parsed. */
  sourceFormat: 'hls' | 'dash';
  /** All available renditions, sorted ascending by bitrate. */
  renditions: Rendition[];
  /** Quality ladder from the backend (may differ from manifest renditions). */
  qualityLadder: QualityTierInfo[];
  /** Content hint for ABR heuristics. */
  contentHint: ContentHint;
  /** When this manifest object was created (epoch ms). */
  fetchedAt: number;
  /** Duration in seconds of the longest rendition. */
  duration: number;
  /** Available audio tracks (from EXT-X-MEDIA audio groups). */
  audioTracks: AudioTrack[];
  /** Available subtitle tracks (from EXT-X-MEDIA subtitle groups). */
  subtitleTracks: SubtitleTrack[];
}

// ---------------------------------------------------------------------------
// Playback State Machine
// ---------------------------------------------------------------------------

export type PlaybackState =
  | 'idle'
  | 'loading'
  | 'buffering'
  | 'ready'
  | 'playing'
  | 'paused'
  | 'seeking'
  | 'ended'
  | 'error';

export interface PlaybackError {
  code: string;
  message: string;
  fatal: boolean;
  /** Optional retry strategy suggestion. */
  retry?: 'reload-manifest' | 'retry-segment' | 'switch-rendition' | 'switch-transport';
}

// ---------------------------------------------------------------------------
// Buffer Engine
// ---------------------------------------------------------------------------

export type BufferBackend = 'mse' | 'webcodecs';

export interface BufferStats {
  /** Current buffer length ahead of playhead, in seconds. */
  forwardBuffer: number;
  /** Total buffered ranges. */
  bufferedRanges: TimeRange[];
  /** Number of segments currently in the buffer. */
  segmentCount: number;
  /** Bytes consumed. */
  bytesBuffered: number;
}

export interface TimeRange {
  start: number;
  end: number;
}

// ---------------------------------------------------------------------------
// ABR (Adaptive Bitrate)
// ---------------------------------------------------------------------------

export type ABRStrategy = 'throughput' | 'buffer' | 'content-aware' | 'manual';

export interface ABRState {
  currentRenditionId: string;
  strategy: ABRStrategy;
  /** Measured throughput in bps. */
  throughput: number;
  /** Buffer health in seconds. */
  bufferHealth: number;
  /** Content hint affecting ABR aggressiveness. */
  contentHint: ContentHint;
  /** Manual override rendition ID (when strategy is 'manual'). */
  manualRenditionId?: string;
}

// ---------------------------------------------------------------------------
// Party / Sync
// ---------------------------------------------------------------------------

export interface PartyState {
  sessionId: string;
  participants: PartyParticipant[];
  hostId: string;
  syncOffsetMs: number;
}

export interface PartyParticipant {
  id: string;
  displayName: string;
  /** Their current playback position in seconds. */
  position: number;
  isHost: boolean;
}

export type PartyEvent =
  | { type: 'play'; position: number }
  | { type: 'pause'; position: number }
  | { type: 'seek'; position: number }
  | { type: 'position-update'; position: number }
  | { type: 'rendition-change'; renditionId: string }
  | { type: 'participant-joined'; participant: PartyParticipant }
  | { type: 'participant-left'; participantId: string }
  | { type: 'annotation'; annotation: SpatialAnnotation };

export interface SpatialAnnotation {
  id: string;
  userId: string;
  /** 3D position on the sphere surface (theta, phi). */
  position: { theta: number; phi: number };
  text?: string;
  timestamp: number;
}

// ---------------------------------------------------------------------------
// Immersive / Spatial
// ---------------------------------------------------------------------------

export type ProjectionType = 'equirectangular' | 'cubemap' | 'half-equirectangular' | 'flat';

export interface SpatialState {
  projection: ProjectionType;
  /** Yaw in radians [0, 2π]. */
  yaw: number;
  /** Pitch in radians [-π/2, π/2]. */
  pitch: number;
  /** Field of view in radians. */
  fov: number;
  /** Whether WebXR session is active. */
  xrActive: boolean;
}

export interface Viewport {
  id: string;
  label: string;
  /** Rendition ID this viewport displays. */
  renditionId: string;
  /** Spatial orientation for this viewport. */
  spatial: SpatialState;
}

// ---------------------------------------------------------------------------
// AI Layer
// ---------------------------------------------------------------------------

export type AIMode = 'idle' | 'classifying' | 'highlighting' | 'prefetching';

export interface SceneClassification {
  timestamp: number;
  labels: string[];
  confidence: number;
  contentHint: ContentHint;
}

export interface Highlight {
  startTime: number;
  endTime: number;
  confidence: number;
  label: string;
}

export interface AIRemixRequest {
  /** Segment timestamps to remix. */
  startTime: number;
  endTime: number;
  /** Quality tier for the remixed output. */
  qualityTier: string;
}

// ---------------------------------------------------------------------------
// Offline
// ---------------------------------------------------------------------------

export type OfflineStatus = 'idle' | 'downloading' | 'paused' | 'complete' | 'error';

export interface OfflineEntry {
  videoId: string;
  manifest: Manifest;
  /** Map of segment URI → stored ArrayBuffer. */
  segments: Map<string, ArrayBuffer>;
  initSegment: ArrayBuffer | null;
  status: OfflineStatus;
  /** 0..1 progress. */
  progress: number;
  totalBytes: number;
  downloadedAt: number;
}

// ---------------------------------------------------------------------------
// Feature Flags
// ---------------------------------------------------------------------------

export interface FeatureFlags {
  /** Enable WebCodecs fallback when MSE unavailable. */
  webcodecs: boolean;
  /** Enable WebTransport transport. */
  webtransport: boolean;
  /** Enable MoQ (Media over QUIC) transport. */
  moq: boolean;
  /** Enable 360° immersive rendering. */
  immersive: boolean;
  /** Enable WebXR VR/AR sessions. */
  webxr: boolean;
  /** Enable 3D Gaussian Splatting track. */
  splats: boolean;
  /** Enable AI scene classification + highlight detection. */
  ai: boolean;
  /** Enable party sync. */
  party: boolean;
  /** Enable offline caching. */
  offline: boolean;
  /** Enable telemetry reporting. */
  telemetry: boolean;
  /** Enable predictive prefetch. */
  predictivePrefetch: boolean;
  /** Enable multi-viewport / director cuts. */
  multiViewport: boolean;
}

export const DEFAULT_FEATURE_FLAGS: FeatureFlags = {
  webcodecs: true,
  webtransport: true,
  moq: false,
  immersive: false,
  webxr: false,
  splats: false,
  ai: false,
  party: false,
  offline: true,
  telemetry: true,
  predictivePrefetch: true,
  multiViewport: false,
};

// ---------------------------------------------------------------------------
// Player Configuration
// ---------------------------------------------------------------------------

export interface PlayerConfig {
  /** Backend base URL (e.g. "https://baander.local"). */
  baseUrl: string;
  /** Auth token for DPoP / Bearer header. */
  authToken?: string;
  /** Which manifest format to prefer. */
  preferredFormat: 'hls' | 'dash';
  /** Starting rendition by quality name (e.g. "720p"). */
  initialQuality: string;
  /** Max buffer length in seconds. */
  maxBufferLength: number;
  /** Max playback rate for ABR catch-up. */
  maxPlaybackRate: number;
  /** Feature flags. */
  features: FeatureFlags;
  /** Custom headers for all API requests. */
  customHeaders?: Record<string, string>;
  /** WebXR reference space type. */
  xrReferenceSpace: 'local' | 'local-floor' | 'viewer';
}

export const DEFAULT_CONFIG: PlayerConfig = {
  baseUrl: '',
  preferredFormat: 'hls',
  initialQuality: '720p',
  maxBufferLength: 30,
  maxPlaybackRate: 1.1,
  features: DEFAULT_FEATURE_FLAGS,
  xrReferenceSpace: 'local-floor',
};

// ---------------------------------------------------------------------------
// Telemetry
// ---------------------------------------------------------------------------

export interface TelemetryEvent {
  type: string;
  timestamp: number;
  videoId: string;
  renditionId?: string;
  value?: number;
  metadata?: Record<string, unknown>;
}

export interface TelemetryBatch {
  sessionId: string;
  videoId: string;
  events: TelemetryEvent[];
}

// ---------------------------------------------------------------------------
// Worker Messages
// ---------------------------------------------------------------------------

/** Messages sent from main thread → workers. */
export type WorkerCommand =
  | { type: 'init'; config: PlayerConfig }
  | { type: 'parse-manifest'; manifestText: string; url: string; videoId: string }
  | { type: 'fetch-segment'; url: string; retries: number }
  | { type: 'classify-frame'; frameData: ImageBitmap; timestamp: number }
  | { type: 'terminate' };

/** Messages sent from workers → main thread. */
export type WorkerResponse<T = unknown> =
  | { type: 'ready' }
  | { type: 'result'; payload: T }
  | { type: 'error'; error: string }
  | { type: 'progress'; percent: number };
