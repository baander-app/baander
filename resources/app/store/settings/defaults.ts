import { AppSettings, EQPreset, QueueMode, QueueCompletionBehavior } from './settings-types';

/**
 * Default application settings
 * Used when initializing the store or when settings are missing/corrupted
 */
export const DEFAULT_SETTINGS: AppSettings = {
  version: 2,
  audio: {
    equalizer: {
      enabled: true,
      preset: 'FLAT',
      bands: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
      customBands: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
    },
    volume: {
      level: 100,
      muted: false,
      normalization: {
        enabled: false,
        targetLufs: -16,
      },
    },
    effects: {
      compression: {
        enabled: true,
        threshold: -24,
        ratio: 3,
      },
      spatialEnhancement: false,
      masterGain: 0,
    },
  },
  ui: {
    theme: 'light',
    display: {
      visualizerMode: 'spectrum',
      visualizerQuality: 'medium',
    },
  },
  preferences: {
    playback: {
      crossfadeDuration: 0,
      gaplessPlayback: true,
      queueCompletion: QueueCompletionBehavior.STOP,
    },
    queue: {
      mode: QueueMode.SIMPLE,
      rememberPosition: true,
      autoSwitch: true,
      warnOnQueueReplace: true,
    },
    library: {
      defaultSort: 'title',
      defaultView: 'grid',
    },
  },
};

/**
 * EQ Presets
 * 10-band EQ values for different music genres
 * Frequencies: 31.5, 63, 125, 250, 500, 1k, 2k, 4k, 8k, 16k Hz
 */
export const EQ_PRESETS: Record<EQPreset, [number, number, number, number, number, number, number, number, number, number]> = {
  FLAT: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
  ROCK: [4, 3, -1, -2, -1, 2, 4, 5, 5, 5],
  POP: [2, 3, 4, 3, 0, -1, -2, -1, 2, 3],
  JAZZ: [3, 2, 1, 2, 3, 3, 2, 1, 2, 3],
  CLASSICAL: [4, 3, 2, 1, 0, 0, 2, 3, 4, 5],
  ELECTRONIC: [5, 4, 2, 0, -1, 2, 3, 4, 5, 6],
  'HIP-HOP': [5, 4, 2, 3, -1, -1, 2, 3, 4, 5],
  VOCAL: [2, 1, -1, 2, 4, 4, 3, 2, 1, -1],
  ACOUSTIC: [3, 2, 1, 2, 3, 2, 3, 4, 3, 2],
  BASS_BOOST: [7, 5, 3, 2, 0, 0, 0, 0, 0, 0],
  TREBLE_BOOST: [0, 0, 0, 0, 0, 2, 4, 6, 8, 9],
  CUSTOM: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
};
