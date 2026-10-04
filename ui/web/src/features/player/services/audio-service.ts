import { AudioProcessor } from './audio-processor'

class AudioService {
  private processor: AudioProcessor | null = null
  private isInitialized = false
  private connectionGeneration = 0

  public initialize() {
    if (this.isInitialized) return

    try {
      this.processor = new AudioProcessor()
      this.isInitialized = true
    } catch (error) {
      console.error('[AudioService] Failed to create AudioProcessor:', error)
      throw error
    }
  }

  public async connectAudioElement(audioElement: HTMLAudioElement) {
    if (!this.processor) {
      this.initialize()
    }

    const processor = this.processor
    if (!processor || !audioElement.src) return
    const generation = ++this.connectionGeneration
    const isCurrent = () => this.processor === processor && this.connectionGeneration === generation

    try {
      await processor.connectAudioElement(audioElement)
      if (!isCurrent()) return

      const { reapplyAllEqState } = await import('@/features/equalizer/stores/eq-reapply')
      if (!isCurrent()) return
      reapplyAllEqState()
    } catch (error) {
      if (!isCurrent()) return
      if (error instanceof DOMException && error.name === 'InvalidStateError') {
        await processor.initializePassiveMode()
      } else {
        console.error('[AudioService] Failed to connect audio processor:', error)
      }
    }
  }

  public async connectDualAudioElements(elementA: HTMLAudioElement, elementB: HTMLAudioElement) {
    if (!this.processor) {
      this.initialize()
    }
    const processor = this.processor
    if (!processor) return
    const generation = ++this.connectionGeneration
    const isCurrent = () => this.processor === processor && this.connectionGeneration === generation

    try {
      await processor.connectDualAudioElements(elementA, elementB)
      if (!isCurrent()) return
      const { reapplyAllEqState } = await import('@/features/equalizer/stores/eq-reapply')
      if (!isCurrent()) return
      reapplyAllEqState()
    } catch (error) {
      if (!isCurrent()) return
      if (error instanceof DOMException && error.name === 'InvalidStateError') {
        await processor.initializePassiveMode()
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
    this.connectionGeneration++
    this.processor?.destroy()
    this.processor = null
    this.isInitialized = false
  }
}

export const audioService = new AudioService()
