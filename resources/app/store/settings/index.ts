// ============================================================================
// SETTINGS STORE - Public API
// ============================================================================

// Store
export { useSettingsStore } from './settings-store';
export type { SettingsStore } from './settings-store';

// Types
export type {
  AppSettings,
  AudioSettings,
  UISettings,
  UserPreferences,
  EQPreset,
} from './settings-types';

// Defaults
export { DEFAULT_SETTINGS, EQ_PRESETS } from './defaults';

// Selectors & Hooks
export {
  // EQ selectors
  useEQSettings,
  useEQEnabled,
  useEQPreset,
  useEQBands,
  useCustomEQBands,
  // Volume selectors
  useVolumeSettings,
  useVolumeLevel,
  useIsMuted,
  // Normalization selectors
  useVolumeNormalization,
  useNormalizationEnabled,
  useTargetLufs,
  // Effects selectors
  useAudioEffects,
  useCompressionSettings,
  useCompressionEnabled,
  useSpatialEnhancement,
  useMasterGain,
  // Combined selectors
  useAudioSettings,
  useUISettings,
  useTheme,
  useVisualizerMode,
  useVisualizerQuality,
  useUserPreferences,
  usePlaybackPreferences,
  useLibraryPreferences,
  useCrossfadeDuration,
  useGaplessPlayback,
  useDefaultLibraryView,
  useDefaultLibrarySort,
  // Actions
  useSettingsActions,
  // Convenience hooks
  useEqualizer,
} from './selectors';

// Audio Processor Integration
export {
  useAudioProcessorSettingsSubscription,
  applySettingsToProcessor,
  initializeAudioProcessorSettings,
} from './audio-subscriber';

// Migrations (internal, exported for testing)
export { migrateSettings, migrations } from './migrations';
