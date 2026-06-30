/**
 * @module index
 * @description Public API surface for @baander/player.
 *
 * Import the player and types:
 * ```ts
 * import { BaanderPlayer } from '@baander/player';
 * import type { PlayerConfig, FeatureFlags, Manifest } from '@baander/player';
 * ```
 */

// Main class
export { BaanderPlayer } from './BaanderPlayer';
export type { PlayerEvents } from './BaanderPlayer';

// Core types
export type {
  // Transport
  TransportProtocol,
  FetchOutcome,
  FetchResult,
  FetchError,

  // Backend Domain
  QualityTierInfo,
  AudioProfileName,
  SessionPriority,
  SessionStatus,
  CreateTranscodeSessionPayload,
  TranscodeSessionInfo,

  // Manifest
  Manifest,
  Rendition,
  SegmentInfo,
  ContentHint,

  // Playback
  PlaybackState,
  PlaybackError,

  // Buffer
  BufferBackend,
  BufferStats,
  TimeRange,

  // ABR
  ABRState,
  ABRStrategy,

  // Party
  PartyState,
  PartyParticipant,
  PartyEvent,
  SpatialAnnotation,

  // Immersive
  ProjectionType,
  SpatialState,
  Viewport,

  // AI
  AIMode,
  SceneClassification,
  Highlight,
  AIRemixRequest,

  // Offline
  OfflineStatus,
  OfflineEntry,

  // Config
  FeatureFlags,
  PlayerConfig,

  // Telemetry
  TelemetryEvent,
  TelemetryBatch,
} from './types';

export { DEFAULT_FEATURE_FLAGS, DEFAULT_CONFIG } from './types';

// Core subsystems (for advanced usage)
export { UnifiedManifestEngine } from './core/manifest/UnifiedManifestEngine';
export { AdaptiveTransportLayer } from './core/transport/AdaptiveTransportLayer';
export { HybridBufferEngine } from './core/buffer/HybridBufferEngine';
export { SmartABRController } from './core/abr/SmartABRController';
export { PlaybackStateMachine } from './core/state/PlaybackStateMachine';
export { SegmentScheduler } from './core/scheduler/SegmentScheduler';
export { TelemetryReporter } from './core/telemetry/TelemetryReporter';

// Optional subsystems
export { ImmersiveRenderer } from './immersive/ImmersiveRenderer';
export { AIOrchestrator } from './ai/AIOrchestrator';
export { PartySyncBus } from './party/PartySyncBus';
export { OfflineStore } from './offline/OfflineStore';
