import { AudioProcessor } from './audio-processor'

class AudioService {
  private processor: AudioProcessor | null = null
  private pendingAudioElement: HTMLAudioElement | null = null
  private isInitialized = false

  public initialize() {
    if (this.isInitialized) return

    try {
      this.processor = new AudioProcessor()
      this.isInitialized = true

      if (this.pendingAudioElement) {
        this.connectAudioElement(this.pendingAudioElement)
        this.pendingAudioElement = null
      }
    } catch (error) {
      console.error('[AudioService] Failed to create AudioProcessor:', error)
      throw error
    }
  }

  public async connectAudioElement(audioElement: HTMLAudioElement) {
    if (!this.processor) {
      this.pendingAudioElement = audioElement
      this.initialize()
      return
    }

    if (!audioElement.src) return

    try {
      await this.processor.connectAudioElement(audioElement)

      const { reapplyAllEqState } = await import('@/features/equalizer/stores/eq-reapply')
      reapplyAllEqState()
    } catch (error) {
      if (error instanceof DOMException && error.name === 'InvalidStateError') {
        await this.processor!.initializePassiveMode()
      } else {
        console.error('[AudioService] Failed to connect audio processor:', error)
      }
    }
  }

  public async connectDualAudioElements(elementA: HTMLAudioElement, elementB: HTMLAudioElement) {
    if (!this.processor) {
      this.initialize()
    }
    if (!this.processor) return

    try {
      await this.processor.connectDualAudioElements(elementA, elementB)
      const { reapplyAllEqState } = await import('@/features/equalizer/stores/eq-reapply')
      reapplyAllEqState()
    } catch (error) {
      if (error instanceof DOMException && error.name === 'InvalidStateError') {
        await this.processor!.initializePassiveMode()
      } else {
        console.error('[AudioService] Failed to connect dual audio elements:', error)
      }
    }
  }

  public setPlayingState(isPlaying: boolean) {
    this.processor?.setPlayingState(isPlaying)
  }

  public async resumeContextIfNeeded(): Promise<void> {
    return this.processor?.resumeContextIfNeeded()
  }

  public getProcessor(): AudioProcessor | null {
    return this.processor
  }

  public destroy() {
    this.processor?.destroy()
    this.processor = null
    this.isInitialized = false
    this.pendingAudioElement = null
  }
}

export const audioService = new AudioService()
