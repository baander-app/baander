/**
 * Global application settings types
 * All app settings are persisted to localStorage and managed by Zustand
 */

// EQ Preset types
export type EQPreset =
  | 'FLAT'
  | 'ROCK'
  | 'POP'
  | 'JAZZ'
  | 'CLASSICAL'
  | 'ELECTRONIC'
  | 'HIP-HOP'
  | 'VOCAL'
  | 'ACOUSTIC'
  | 'BASS_BOOST'
  | 'TREBLE_BOOST'
<<<<<<< HEAD
  | 'CUSTOM';
=======
  | 'CUSTOM'
  | 'CUSTOM_2';
>>>>>>> private/master

// Audio Settings
export interface AudioSettings {
  // Equalizer
  equalizer: {
    enabled: boolean;
    preset: EQPreset;
    bands: [number, number, number, number, number, number, number, number, number, number]; // 10 bands: 31.5Hz to 16kHz
    customBands: [number, number, number, number, number, number, number, number, number, number];
  };

  // Volume & Normalization
  volume: {
    level: number; // 0-100
    muted: boolean;
    normalization: {
      enabled: boolean;
      targetLufs: -14 | -16 | -18 | -23;
    };
  };

  // Audio Effects
  effects: {
    compression: {
      enabled: boolean;
      threshold: number;
      ratio: number;
    };
    spatialEnhancement: boolean;
    masterGain: number; // dB
  };
}

// UI Settings
export interface UISettings {
  theme: 'light' | 'dark';
  display: {
    visualizerMode: 'spectrum' | 'meters' | 'phase';
    visualizerQuality: 'low' | 'medium' | 'high';
  };
}

// User Preferences
export interface UserPreferences {
  playback: {
    crossfadeDuration: number; // seconds
    gaplessPlayback: boolean;
    queueCompletion: QueueCompletionBehavior; // Queue completion behavior
  };
  queue: {
    mode: QueueMode; // Simple or advanced queue mode
    rememberPosition: boolean; // For audiobooks/podcasts
    autoSwitch: boolean; // Auto-switch queues based on library type
    warnOnQueueReplace: boolean; // Warn when replacing non-empty queue
  };
  library: {
    defaultSort: string;
    defaultView: 'grid' | 'list';
  };
}

/**
 * Queue completion behavior
 */
export enum QueueCompletionBehavior {
  STOP = 'stop', // Stop playback when queue ends
  SHUFFLE = 'shuffle', // Shuffle and replay queue
  PLAY_RANDOM = 'play-random', // Play random item from library
}

/**
 * Queue management mode
 */
export enum QueueMode {
  SIMPLE = 'simple', // Queues are isolated, no mixing
  ADVANCED = 'advanced', // Allow mixing with warnings
}

// Complete Settings State
export interface AppSettings {
  version: number; // Settings schema version for migrations
  audio: AudioSettings;
  ui: UISettings;
  preferences: UserPreferences;
}
