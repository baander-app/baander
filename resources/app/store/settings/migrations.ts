import { AppSettings, QueueCompletionBehavior, QueueMode } from './settings-types';
import { DEFAULT_SETTINGS } from './defaults';

/**
 * Migration function type
 * Transforms settings from one version to the next
 */
export type Migration = (settings: Partial<AppSettings>) => Partial<AppSettings>;

/**
 * Settings version migrations
 * Add new migrations as the settings schema evolves
 */
export const migrations: Record<number, Migration> = {
  // Version 1: Initial schema
  1: (settings) => ({
    ...settings,
    version: 1,
  }),

  // Version 2: Add queue management settings
  2: (settings) => ({
    ...settings,
    version: 2,
    preferences: {
      ...settings.preferences,
      playback: {
        ...settings.preferences?.playback,
        queueCompletion: QueueCompletionBehavior.STOP,
      },
      queue: {
        mode: QueueMode.SIMPLE,
        rememberPosition: true,
        autoSwitch: true,
        warnOnQueueReplace: true,
      },
    },
  }),
};

/**
 * Migrate settings from an older version to the current version
 * @param persistedSettings - Settings loaded from localStorage (may be outdated)
 * @returns Migrated settings merged with defaults
 */
export function migrateSettings(persistedSettings: unknown): AppSettings {
  // Handle null/undefined
  if (!persistedSettings || typeof persistedSettings !== 'object') {
    return { ...DEFAULT_SETTINGS };
  }

  const settings = persistedSettings as Partial<AppSettings>;
  const currentVersion = settings.version || 0;

  // Start with a copy of the persisted settings
  let migratedSettings = { ...settings } as Partial<AppSettings>;

  // Apply migrations sequentially from current version to latest
  const latestVersion = Math.max(...Object.keys(migrations).map(Number));
  for (let version = currentVersion + 1; version <= latestVersion; version++) {
    const migration = migrations[version];
    if (migration) {
      try {
        migratedSettings = {
          ...migratedSettings,
          ...migration(migratedSettings),
        };
      } catch (error) {
        console.error(`Settings migration failed for version ${version}:`, error);
        // If migration fails, continue with current state
        // Defaults will be merged in the next step
      }
    }
  }

  // Merge with defaults to fill in missing fields
  // This ensures new settings added in future versions have default values
  return deepMergeSettings(DEFAULT_SETTINGS, migratedSettings);
}

/**
 * Deep merge settings with defaults
 * Ensures nested objects are properly merged
 */
function deepMergeSettings(defaults: AppSettings, settings: Partial<AppSettings>): AppSettings {
  return {
    ...defaults,
    ...settings,
    audio: {
      ...defaults.audio,
      ...settings.audio,
      equalizer: {
        ...defaults.audio.equalizer,
        ...settings.audio?.equalizer,
      },
      volume: {
        ...defaults.audio.volume,
        ...settings.audio?.volume,
        normalization: {
          ...defaults.audio.volume.normalization,
          ...settings.audio?.volume?.normalization,
        },
      },
      effects: {
        ...defaults.audio.effects,
        ...settings.audio?.effects,
        compression: {
          ...defaults.audio.effects.compression,
          ...settings.audio?.effects?.compression,
        },
      },
    },
    ui: {
      ...defaults.ui,
      ...settings.ui,
      display: {
        ...defaults.ui.display,
        ...settings.ui?.display,
      },
    },
    preferences: {
      ...defaults.preferences,
      ...settings.preferences,
      playback: {
        ...defaults.preferences.playback,
        ...settings.preferences?.playback,
      },
      queue: {
        ...defaults.preferences.queue,
        ...settings.preferences?.queue,
      },
      library: {
        ...defaults.preferences.library,
        ...settings.preferences?.library,
      },
    },
  };
}
