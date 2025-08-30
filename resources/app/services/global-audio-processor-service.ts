import { AudioProcessor } from './audio-processor.ts';

class GlobalAudioProcessorService {
  private processor: AudioProcessor | null = null;
  private pendingAudioElement: HTMLAudioElement | null = null;
  private isInitialized = false;
  private connectionAttempted = false;

  public initialize() {
    if (!this.isInitialized) {
      this.processor = new AudioProcessor();
      this.isInitialized = true;

      if (this.pendingAudioElement) {
        this.connectAudioElement(this.pendingAudioElement);
        this.pendingAudioElement = null;
      }
    }
  }

  public async connectAudioElement(audioElement: HTMLAudioElement) {
    if (!this.processor) {
      this.pendingAudioElement = audioElement;
      this.initialize();
      return;
    }

    if (this.connectionAttempted) {
      console.log('Audio processor already connected or connection attempted');
      return;
    }

    this.connectionAttempted = true;

    try {
      await this.processor.connectAudioElement(audioElement);
      console.log('Global audio processor connected to audio element');
    } catch (error) {
      if (error instanceof DOMException && error.name === 'InvalidStateError') {
        console.log('Audio element already connected to another context - using passive monitoring');
        await this.processor.initializePassiveMode();
      } else {
        console.error('Failed to connect audio processor:', error);
        this.connectionAttempted = false;
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
    return this.processor;
  }

  public destroy() {
    if (this.processor) {
      this.processor.destroy();
      this.processor = null;
    }
    this.isInitialized = false;
    this.connectionAttempted = false;
    this.pendingAudioElement = null;
  }


  public reset() {
    this.connectionAttempted = false;
  }
}

// Create a singleton instance
export const globalAudioProcessor = new GlobalAudioProcessorService();
