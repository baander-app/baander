import { create } from 'zustand';
import { persist, subscribeWithSelector } from 'zustand/middleware';
import { AppSettings, EQPreset, QueueCompletionBehavior, QueueMode } from './settings-types';
import { DEFAULT_SETTINGS, EQ_PRESETS } from './defaults';
import { migrateSettings } from './migrations';

export interface SettingsActions {
  // Equalizer actions
  setEQEnabled: (enabled: boolean) => void;
  setEQPreset: (preset: EQPreset) => void;
  setEQBand: (bandIndex: number, gain: number) => void;
  setEQBands: (bands: [number, number, number, number, number, number, number, number, number, number]) => void;

  // Volume actions
  setVolume: (level: number) => void;
  setMuted: (muted: boolean) => void;
  toggleMute: () => void;

  // Normalization actions
  setVolumeNormalization: (enabled: boolean) => void;
  setTargetLufs: (target: -14 | -16 | -18 | -23) => void;

  // Effects actions
  setCompressionEnabled: (enabled: boolean) => void;
  setCompressionThreshold: (threshold: number) => void;
  setCompressionRatio: (ratio: number) => void;
  setSpatialEnhancement: (enabled: boolean) => void;
  setMasterGain: (gain: number) => void;

  // UI actions
  setTheme: (theme: 'light' | 'dark') => void;
  setVisualizerMode: (mode: 'spectrum' | 'meters' | 'phase') => void;
  setVisualizerQuality: (quality: 'low' | 'medium' | 'high') => void;

  // Preferences actions
  setCrossfadeDuration: (duration: number) => void;
  setGaplessPlayback: (enabled: boolean) => void;
  setDefaultLibraryView: (view: 'grid' | 'list') => void;
  setDefaultLibrarySort: (sort: string) => void;
  setQueueCompletion: (behavior: QueueCompletionBehavior) => void;
  setQueueMode: (mode: QueueMode) => void;
  setQueueAutoSwitch: (enabled: boolean) => void;
  setQueueRememberPosition: (enabled: boolean) => void;
  setQueueWarnOnReplace: (enabled: boolean) => void;

  // Metadata
  resetToDefaults: () => void;
}

export type SettingsStore = AppSettings & SettingsActions;

export const useSettingsStore = create<SettingsStore>()(
  subscribeWithSelector(
    persist(
      (set, _get) => ({
        // Initial state
        ...DEFAULT_SETTINGS,

        // Equalizer actions
        setEQEnabled: (enabled) =>
          set((state) => ({
            audio: {
              ...state.audio,
              equalizer: {
                ...state.audio.equalizer,
                enabled,
              },
            },
          })),

        setEQPreset: (preset) =>
          set((state) => {
            const bands = EQ_PRESETS[preset];
            return {
              audio: {
                ...state.audio,
                equalizer: {
                  ...state.audio.equalizer,
                  preset,
                  bands: preset === 'CUSTOM' ? state.audio.equalizer.customBands : bands,
                },
              },
            };
          }),

        setEQBand: (bandIndex, gain) =>
          set((state) => {
            const newBands = [...state.audio.equalizer.bands] as typeof state.audio.equalizer.bands;
            newBands[bandIndex] = gain;
            const newCustomBands = [...state.audio.equalizer.customBands] as typeof state.audio.equalizer.customBands;
            newCustomBands[bandIndex] = gain;

            return {
              audio: {
                ...state.audio,
                equalizer: {
                  ...state.audio.equalizer,
                  preset: 'CUSTOM',
                  bands: newBands,
                  customBands: newCustomBands,
                },
              },
            };
          }),

        setEQBands: (bands) =>
          set((state) => ({
            audio: {
              ...state.audio,
              equalizer: {
                ...state.audio.equalizer,
                preset: 'CUSTOM',
                bands,
                customBands: bands,
              },
            },
          })),

        // Volume actions
        setVolume: (level) =>
          set((state) => ({
            audio: {
              ...state.audio,
              volume: {
                ...state.audio.volume,
                level: Math.max(0, Math.min(100, level)),
              },
            },
          })),

        setMuted: (muted) =>
          set((state) => ({
            audio: {
              ...state.audio,
              volume: {
                ...state.audio.volume,
                muted,
              },
            },
          })),

        toggleMute: () =>
          set((state) => ({
            audio: {
              ...state.audio,
              volume: {
                ...state.audio.volume,
                muted: !state.audio.volume.muted,
              },
            },
          })),

        // Normalization actions
        setVolumeNormalization: (enabled) =>
          set((state) => ({
            audio: {
              ...state.audio,
              volume: {
                ...state.audio.volume,
                normalization: {
                  ...state.audio.volume.normalization,
                  enabled,
                },
              },
            },
          })),

        setTargetLufs: (targetLufs) =>
          set((state) => ({
            audio: {
              ...state.audio,
              volume: {
                ...state.audio.volume,
                normalization: {
                  ...state.audio.volume.normalization,
                  targetLufs,
                },
              },
            },
          })),

        // Effects actions
        setCompressionEnabled: (enabled) =>
          set((state) => ({
            audio: {
              ...state.audio,
              effects: {
                ...state.audio.effects,
                compression: {
                  ...state.audio.effects.compression,
                  enabled,
                },
              },
            },
          })),

        setCompressionThreshold: (threshold) =>
          set((state) => ({
            audio: {
              ...state.audio,
              effects: {
                ...state.audio.effects,
                compression: {
                  ...state.audio.effects.compression,
                  threshold,
                },
              },
            },
          })),

        setCompressionRatio: (ratio) =>
          set((state) => ({
            audio: {
              ...state.audio,
              effects: {
                ...state.audio.effects,
                compression: {
                  ...state.audio.effects.compression,
                  ratio,
                },
              },
            },
          })),

        setSpatialEnhancement: (enabled) =>
          set((state) => ({
            audio: {
              ...state.audio,
              effects: {
                ...state.audio.effects,
                spatialEnhancement: enabled,
              },
            },
          })),

        setMasterGain: (gain) =>
          set((state) => ({
            audio: {
              ...state.audio,
              effects: {
                ...state.audio.effects,
                masterGain: gain,
              },
            },
          })),

        // UI actions
        setTheme: (theme) =>
          set((state) => ({
            ui: {
              ...state.ui,
              theme,
            },
          })),

        setVisualizerMode: (mode) =>
          set((state) => ({
            ui: {
              ...state.ui,
              display: {
                ...state.ui.display,
                visualizerMode: mode,
              },
            },
          })),

        setVisualizerQuality: (quality) =>
          set((state) => ({
            ui: {
              ...state.ui,
              display: {
                ...state.ui.display,
                visualizerQuality: quality,
              },
            },
          })),

        // Preferences actions
        setCrossfadeDuration: (duration) =>
          set((state) => ({
            preferences: {
              ...state.preferences,
              playback: {
                ...state.preferences.playback,
                crossfadeDuration: duration,
              },
            },
          })),

        setGaplessPlayback: (enabled) =>
          set((state) => ({
            preferences: {
              ...state.preferences,
              playback: {
                ...state.preferences.playback,
                gaplessPlayback: enabled,
              },
            },
          })),

        setDefaultLibraryView: (view) =>
          set((state) => ({
            preferences: {
              ...state.preferences,
              library: {
                ...state.preferences.library,
                defaultView: view,
              },
            },
          })),

        setDefaultLibrarySort: (sort) =>
          set((state) => ({
            preferences: {
              ...state.preferences,
              library: {
                ...state.preferences.library,
                defaultSort: sort,
              },
            },
          })),

        setQueueCompletion: (behavior) =>
          set((state) => ({
            preferences: {
              ...state.preferences,
              playback: {
                ...state.preferences.playback,
                queueCompletion: behavior,
              },
            },
          })),

        setQueueMode: (mode) =>
          set((state) => ({
            preferences: {
              ...state.preferences,
              queue: {
                ...state.preferences.queue,
                mode: mode,
              },
            },
          })),

        setQueueAutoSwitch: (enabled) =>
          set((state) => ({
            preferences: {
              ...state.preferences,
              queue: {
                ...state.preferences.queue,
                autoSwitch: enabled,
              },
            },
          })),

        setQueueRememberPosition: (enabled) =>
          set((state) => ({
            preferences: {
              ...state.preferences,
              queue: {
                ...state.preferences.queue,
                rememberPosition: enabled,
              },
            },
          })),

        setQueueWarnOnReplace: (enabled) =>
          set((state) => ({
            preferences: {
              ...state.preferences,
              queue: {
                ...state.preferences.queue,
                warnOnQueueReplace: enabled,
              },
            },
          })),

        // Metadata
        resetToDefaults: () => set(DEFAULT_SETTINGS),
      }),
      {
        name: 'baander-settings',
        version: 1,

        // Custom migration handler
        migrate: (persistedState: unknown, _version: number) => {
          return migrateSettings(persistedState);
        },

        // Don't persist runtime-only data (if any in future)
        partialize: (state) => state,

        // Handle hydration errors gracefully
        onRehydrateStorage: () => (state) => {
          if (state) {
            // Ensure settings are valid after rehydration
            const migrated = migrateSettings(state);
            Object.assign(state, migrated);
          }
        },
      }
    )
  )
);
