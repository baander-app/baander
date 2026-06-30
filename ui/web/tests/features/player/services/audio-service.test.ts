import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// Mock the audio-processor module with a proper class constructor
vi.mock('@/features/player/services/audio-processor', () => ({
  AudioProcessor: vi.fn().mockImplementation(function () {
    return {
      connectAudioElement: vi.fn(),
      setPlayingState: vi.fn(),
      resumeContextIfNeeded: vi.fn().mockResolvedValue(undefined),
      destroy: vi.fn(),
      initializePassiveMode: vi.fn(),
      isActive: false,
    }
  }),
}))

import { audioService } from '@/features/player/services/audio-service'
import { AudioProcessor } from '@/features/player/services/audio-processor'

describe('AudioService', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    audioService.destroy()
  })

  it('initializes AudioProcessor lazily on first use', () => {
    expect(audioService.getProcessor()).toBeNull()

    audioService.initialize()

    expect(audioService.getProcessor()).not.toBeNull()
    expect(AudioProcessor).toHaveBeenCalledTimes(1)
  })

  it('does not double-initialize', () => {
    audioService.initialize()
    audioService.initialize()

    expect(AudioProcessor).toHaveBeenCalledTimes(1)
  })

  it('queues audio element connection if not yet initialized', async () => {
    const mockElement = { src: 'http://example.com/audio.mp3' } as HTMLAudioElement

    await audioService.connectAudioElement(mockElement)

    expect(audioService.getProcessor()).not.toBeNull()
  })

  it('connects audio element directly when already initialized', async () => {
    audioService.initialize()

    const mockElement = { src: 'http://example.com/audio.mp3' } as HTMLAudioElement
    await audioService.connectAudioElement(mockElement)

    expect(audioService.getProcessor()!.connectAudioElement).toHaveBeenCalledWith(mockElement)
  })

  it('skips connection when audio element has no source', async () => {
    audioService.initialize()

    const mockElement = { src: '' } as unknown as HTMLAudioElement
    await audioService.connectAudioElement(mockElement)

    expect(audioService.getProcessor()!.connectAudioElement).not.toHaveBeenCalled()
  })

  it('delegates setPlayingState to processor', () => {
    audioService.initialize()
    audioService.setPlayingState(true)
    expect(audioService.getProcessor()!.setPlayingState).toHaveBeenCalledWith(true)
  })

  it('delegates resumeContextIfNeeded to processor', async () => {
    audioService.initialize()
    await audioService.resumeContextIfNeeded()
    expect(audioService.getProcessor()!.resumeContextIfNeeded).toHaveBeenCalled()
  })

  it('destroy cleans up processor', () => {
    audioService.initialize()
    audioService.destroy()
    expect(AudioProcessor.mock.results[0]!.value.destroy).toHaveBeenCalled()
    expect(audioService.getProcessor()).toBeNull()
  })
})
