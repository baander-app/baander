import { useSettingsStore, SettingsActions } from './settings-store';
<<<<<<< HEAD
import { shallow } from 'zustand/shallow';
=======
>>>>>>> private/master
import { useMemo } from 'react';

// ============================================================================
// EQUALIZER SELECTORS
// ============================================================================

export const useEQSettings = () => useSettingsStore((state) => state.audio.equalizer);
export const useEQEnabled = () => useSettingsStore((state) => state.audio.equalizer.enabled);
export const useEQPreset = () => useSettingsStore((state) => state.audio.equalizer.preset);
export const useEQBands = () => useSettingsStore((state) => state.audio.equalizer.bands);
export const useCustomEQBands = () => useSettingsStore((state) => state.audio.equalizer.customBands);

// ============================================================================
// VOLUME SELECTORS
// ============================================================================

export const useVolumeSettings = () => useSettingsStore((state) => state.audio.volume);
export const useVolumeLevel = () => useSettingsStore((state) => state.audio.volume.level);
export const useIsMuted = () => useSettingsStore((state) => state.audio.volume.muted);

// ============================================================================
// NORMALIZATION SELECTORS
// ============================================================================

export const useVolumeNormalization = () => useSettingsStore((state) => state.audio.volume.normalization);
export const useNormalizationEnabled = () => useSettingsStore((state) => state.audio.volume.normalization.enabled);
export const useTargetLufs = () => useSettingsStore((state) => state.audio.volume.normalization.targetLufs);

// ============================================================================
// EFFECTS SELECTORS
// ============================================================================

export const useAudioEffects = () => useSettingsStore((state) => state.audio.effects);
export const useCompressionSettings = () => useSettingsStore((state) => state.audio.effects.compression);
export const useCompressionEnabled = () => useSettingsStore((state) => state.audio.effects.compression.enabled);
export const useSpatialEnhancement = () => useSettingsStore((state) => state.audio.effects.spatialEnhancement);
export const useMasterGain = () => useSettingsStore((state) => state.audio.effects.masterGain);

// ============================================================================
// AUDIO SETTINGS (COMBINED)
// ============================================================================

export const useAudioSettings = () => useSettingsStore((state) => state.audio);

// ============================================================================
// UI SELECTORS
// ============================================================================

export const useUISettings = () => useSettingsStore((state) => state.ui);
export const useTheme = () => useSettingsStore((state) => state.ui.theme);
export const useVisualizerMode = () => useSettingsStore((state) => state.ui.display.visualizerMode);
export const useVisualizerQuality = () => useSettingsStore((state) => state.ui.display.visualizerQuality);

// ============================================================================
// PREFERENCES SELECTORS
// ============================================================================

export const useUserPreferences = () => useSettingsStore((state) => state.preferences);
export const usePlaybackPreferences = () => useSettingsStore((state) => state.preferences.playback);
export const useLibraryPreferences = () => useSettingsStore((state) => state.preferences.library);
export const useQueuePreferences = () => useSettingsStore((state) => state.preferences.queue);
export const useCrossfadeDuration = () => useSettingsStore((state) => state.preferences.playback.crossfadeDuration);
export const useGaplessPlayback = () => useSettingsStore((state) => state.preferences.playback.gaplessPlayback);
export const useQueueCompletionBehavior = () => useSettingsStore((state) => state.preferences.playback.queueCompletion);
export const useQueueMode = () => useSettingsStore((state) => state.preferences.queue.mode);
export const useDefaultLibraryView = () => useSettingsStore((state) => state.preferences.library.defaultView);
export const useDefaultLibrarySort = () => useSettingsStore((state) => state.preferences.library.defaultSort);

// ============================================================================
// ACTIONS (stable references)
// ============================================================================

export const useSettingsActions = (): SettingsActions =>
  useSettingsStore(
    (state) => ({
      // Equalizer
      setEQEnabled: state.setEQEnabled,
      setEQPreset: state.setEQPreset,
      setEQBand: state.setEQBand,
      setEQBands: state.setEQBands,

      // Volume
      setVolume: state.setVolume,
      setMuted: state.setMuted,
      toggleMute: state.toggleMute,

      // Normalization
      setVolumeNormalization: state.setVolumeNormalization,
      setTargetLufs: state.setTargetLufs,

      // Effects
      setCompressionEnabled: state.setCompressionEnabled,
      setCompressionThreshold: state.setCompressionThreshold,
      setCompressionRatio: state.setCompressionRatio,
      setSpatialEnhancement: state.setSpatialEnhancement,
      setMasterGain: state.setMasterGain,

      // UI
      setTheme: state.setTheme,
      setVisualizerMode: state.setVisualizerMode,
      setVisualizerQuality: state.setVisualizerQuality,

      // Preferences
      setCrossfadeDuration: state.setCrossfadeDuration,
      setGaplessPlayback: state.setGaplessPlayback,
      setDefaultLibraryView: state.setDefaultLibraryView,
      setDefaultLibrarySort: state.setDefaultLibrarySort,
      setQueueCompletion: state.setQueueCompletion,
      setQueueMode: state.setQueueMode,
      setQueueAutoSwitch: state.setQueueAutoSwitch,
      setQueueRememberPosition: state.setQueueRememberPosition,
      setQueueWarnOnReplace: state.setQueueWarnOnReplace,

      // Metadata
      resetToDefaults: state.resetToDefaults,
    })
  );

// ============================================================================
// CONVENIENCE HOOKS (common combinations)
// ============================================================================

/**
 * Hook for equalizer component - gets all EQ state and actions
 */
export const useEqualizer = () => {
  const equalizer = useEQSettings();
  const volume = useVolumeSettings();
  const effects = useAudioEffects();
  const actions = useSettingsActions();

  return useMemo(() => ({
    // State
    enabled: equalizer.enabled,
    preset: equalizer.preset,
    bands: equalizer.bands,
    volume: volume.level,
    muted: volume.muted,
    compression: effects.compression.enabled,
    spatialEnhancement: effects.spatialEnhancement,
    masterGain: effects.masterGain,

    // Actions
    setEnabled: actions.setEQEnabled,
    setPreset: actions.setEQPreset,
    setBand: actions.setEQBand,
    setVolume: actions.setVolume,
    setMuted: actions.setMuted,
    toggleMute: actions.toggleMute,
    setCompression: actions.setCompressionEnabled,
    setSpatialEnhancement: actions.setSpatialEnhancement,
    setMasterGain: actions.setMasterGain,
  }), [equalizer, volume, effects, actions]);
};
