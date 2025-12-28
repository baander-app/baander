// Main store
export { useMusicPlayerStore } from './music-player-store';
export type { MusicPlayerState } from './music-player-store';

// Slice types (for advanced usage)
export type { QueueSlice } from './slices/queue-slice';
export type { PlaybackSlice } from './slices/playback-slice';
export type { AnalysisSlice } from './slices/analysis-slice';
export type { LyricsSlice } from './slices/lyrics-slice';
export type { PlaybackModeSlice } from './slices/playback-mode-slice';
export type { VolumeSlice } from './slices/volume-slice';
export type { TimingSlice } from './slices/timing-slice';
export type { SourceSlice } from './slices/source-slice';
export type { ProcessorSlice } from './slices/processor-slice';

// Utilities, selectors, and helper functions
export {
  TIME_UPDATE_THROTTLE_MS,
  attachAudioElement,
  autoplayIfAllowed,
  initializeGlobalAudioProcessor,
  resetGlobalAudioProcessor,
  usePlayerDuration,
  usePlayerCurrentTime,
  usePlayerBuffered,
  usePlayerIsPlaying,
  usePlayerIsReady,
  usePlayerVolumePercent,
  usePlayerIsMuted,
  usePlayerSong,
  usePlayerAudioElement,
  usePlayerHasUserInteracted,
  usePlayerQueue,
  usePlayerCurrentSongIndex,
  usePlayerCurrentSongPublicId,
  usePlayerCurrentSong,
  usePlayerShuffleEnabled,
  usePlayerRepeatEnabled,
  usePlayerProgress,
  usePlayerSource,
  usePlayerLyricsOffset,
  usePlayerAnalysis,
  usePlayerLufs,
  usePlayerActions,
} from './utilities';

// Types for consumers
export type { Song, ProcessorApi, PlayerEventHandlers } from './utilities';
