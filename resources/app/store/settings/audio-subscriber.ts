import { useEffect } from 'react';
import { useSettingsStore } from './settings-store';
import { AudioSettings } from './settings-types';
import { globalAudioProcessor } from '@/app/services/global-audio-processor-service';
import { createLogger } from '@/app/services/logger';

const logger = createLogger('SettingsAudioSubscriber');

/**
 * Hook that subscribes the audio processor to settings changes
 * Call this in your app initialization (e.g., App.tsx) to keep the processor in sync
 *
 * @example
 * function App() {
 *   useAudioProcessorSettingsSubscription();
 *   return <div>...</div>;
 * }
 */
export function useAudioProcessorSettingsSubscription() {
  const audioSettings = useSettingsStore((state) => state.audio);

  useEffect(() => {
    const processor = globalAudioProcessor.getProcessor();
    if (!processor) {
      logger.debug('No audio processor available, skipping settings application');
      return;
    }

    // Apply all audio settings to processor
    applySettingsToProcessor(processor, audioSettings);
  }, [
    audioSettings.equalizer.enabled,
    audioSettings.equalizer.bands,
    audioSettings.volume.level,
    audioSettings.volume.muted,
    audioSettings.volume.normalization.enabled,
    audioSettings.effects.compression.enabled,
    audioSettings.effects.spatialEnhancement,
    audioSettings.effects.masterGain,
  ]);
}

/**
 * Apply settings to audio processor
 * Can be called independently (e.g., during processor initialization)
 *
 * @param processor - The AudioProcessor instance
 * @param settings - Audio settings to apply
 */
export function applySettingsToProcessor(processor: any, settings: AudioSettings) {
  try {
    logger.debug('Applying settings to processor:', {
      eqEnabled: settings.equalizer.enabled,
      preset: settings.equalizer.preset,
      volume: settings.volume.level,
      muted: settings.volume.muted,
      normalization: settings.volume.normalization.enabled,
      compression: settings.effects.compression.enabled,
      spatial: settings.effects.spatialEnhancement,
      masterGain: settings.effects.masterGain,
    });

    // Apply EQ enabled state and bands
    if (settings.equalizer.enabled) {
      processor.setEnabled?.();
    }
    processor.updateEQBands?.(settings.equalizer.bands);

    // Apply volume and mute
    processor.setVolume?.(settings.volume.level);
    processor.setMuted?.(settings.volume.muted);

    // Apply compression
    processor.setCompression?.(settings.effects.compression.enabled);

    // Apply spatial enhancement
    processor.setSpatialEnhancement?.(settings.effects.spatialEnhancement);

    // Apply master gain
    processor.setMasterGain?.(settings.effects.masterGain);

    logger.debug('Settings applied successfully');
  } catch (error) {
    logger.error('Failed to apply settings to processor:', error);
  }
}

/**
 * Initialize audio processor with current settings
 * Call this when the audio processor is first created
 *
 * @example
 * // In global-audio-processor-service.ts
 * public initialize() {
 *   this.processor = new AudioProcessor();
 *   this.isInitialized = true;
 *
 *   // Apply saved settings immediately
 *   initializeAudioProcessorSettings();
 * }
 */
export function initializeAudioProcessorSettings() {
  const processor = globalAudioProcessor.getProcessor();
  if (!processor) {
    logger.warn('Cannot initialize settings: no processor available');
    return;
  }

  const settings = useSettingsStore.getState().audio;
  applySettingsToProcessor(processor, settings);
  logger.info('Audio processor initialized with settings');
}
