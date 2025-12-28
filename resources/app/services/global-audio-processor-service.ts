import { AudioProcessor } from './audio-processor.ts';
import { createLogger } from '@/app/services/logger';
import { initializeAudioProcessorSettings } from '@/app/store/settings';

const logger = createLogger('GlobalAudioProcessor');

class GlobalAudioProcessorService {
  private processor: AudioProcessor | null = null;
  private pendingAudioElement: HTMLAudioElement | null = null;
  private isInitialized = false;

  public initialize() {
    logger.debug('initialize called, isInitialized:', this.isInitialized);
    if (!this.isInitialized) {
      try {
        logger.debug('creating new AudioProcessor...');
        this.processor = new AudioProcessor();
        this.isInitialized = true;
        logger.debug('AudioProcessor created successfully');

        // Apply saved settings immediately after processor creation
        initializeAudioProcessorSettings();

        if (this.pendingAudioElement) {
          this.connectAudioElement(this.pendingAudioElement);
          this.pendingAudioElement = null;
        }
      } catch (error) {
        logger.error('Failed to create AudioProcessor:', error);
        throw error;
      }
    }
  }

  public async connectAudioElement(audioElement: HTMLAudioElement) {
    if (!this.processor) {
      this.pendingAudioElement = audioElement;
      this.initialize();
      return;
    }

    // Check if element has a source - if not, don't connect yet
    if (!audioElement.src) {
      logger.debug('Element has no source, skipping connection');
      return;
    }

    try {
      await this.processor.connectAudioElement(audioElement);
      logger.info('Global audio processor connected to audio element');
    } catch (error) {
      if (error instanceof DOMException && error.name === 'InvalidStateError') {
        logger.info('Audio element already connected to another context - using passive monitoring');
        await this.processor.initializePassiveMode();
      } else {
        logger.error('Failed to connect audio processor:', error);
      }
    }
  }

  public setPlayingState(isPlaying: boolean) {
    if (this.processor) {
      this.processor.setPlayingState(isPlaying);
    }
  }

  public async resumeContextIfNeeded(): Promise<void> {
    if (this.processor) {
      return this.processor.resumeContextIfNeeded();
    }
  }

  public getProcessor(): AudioProcessor | null {
    logger.debug('getProcessor called, returning:', !!this.processor);
    return this.processor;
  }

  public destroy() {
    if (this.processor) {
      this.processor.destroy();
      this.processor = null;
    }
    this.isInitialized = false;
    this.pendingAudioElement = null;
  }

  public reset() {
    // Reset is no longer needed since we allow reconnection
  }
}

// Create a singleton instance
export const globalAudioProcessor = new GlobalAudioProcessorService();
