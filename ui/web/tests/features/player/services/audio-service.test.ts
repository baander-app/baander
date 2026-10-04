import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// Mock the audio-processor module with a proper class constructor
vi.mock('@/features/player/services/audio-processor', () => ({
  AudioProcessor: vi.fn().mockImplementation(function () {
    return {
      connectAudioElement: vi.fn(),
      connectDualAudioElements: vi.fn(),
      setPlayingState: vi.fn(),
      resumeContextIfNeeded: vi.fn().mockResolvedValue(undefined),
      destroy: vi.fn(),
      initializePassiveMode: vi.fn(),
      isActive: false,
    }
  }),
}))

vi.mock('@/features/equalizer/stores/eq-reapply', () => ({ reapplyAllEqState: vi.fn() }))

import { audioService } from '@/features/player/services/audio-service'
import { AudioProcessor } from '@/features/player/services/audio-processor'
import { reapplyAllEqState } from '@/features/equalizer/stores/eq-reapply'

function deferred() {
  let resolve!: () => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<void>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })
  return { promise, resolve, reject }
}

const audioElement = () => ({ src: 'https://baander.app/audio.mp3' }) as HTMLAudioElement

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
    const mockElement = audioElement()

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
    expect(vi.mocked(AudioProcessor).mock.results[0]!.value.destroy).toHaveBeenCalled()
    expect(audioService.getProcessor()).toBeNull()
  })

  describe.each(['connectAudioElement', 'connectDualAudioElements'] as const)('%s ownership', (method) => {
    function connect() {
      return method === 'connectAudioElement'
        ? audioService.connectAudioElement(audioElement())
        : audioService.connectDualAudioElements(audioElement(), audioElement())
    }

    it.each(['destroyed', 'replaced', 'superseded'] as const)('ignores success from a %s connection', async (owner) => {
      audioService.initialize()
      const processor = audioService.getProcessor()!
      const pending = deferred()
      vi.mocked(processor[method]).mockReturnValueOnce(pending.promise)
      const oldConnection = connect()

      if (owner !== 'superseded') audioService.destroy()
      if (owner === 'replaced') audioService.initialize()
      if (owner === 'superseded') await connect()
      vi.mocked(reapplyAllEqState).mockClear()

      pending.resolve()
      await oldConnection

      expect(reapplyAllEqState).not.toHaveBeenCalled()
    })

    it.each(['destroyed', 'replaced', 'superseded'] as const)('ignores InvalidStateError from a %s connection', async (owner) => {
      audioService.initialize()
      const processor = audioService.getProcessor()!
      const pending = deferred()
      vi.mocked(processor[method]).mockReturnValueOnce(pending.promise)
      const oldConnection = connect()

      if (owner !== 'superseded') audioService.destroy()
      if (owner === 'replaced') audioService.initialize()
      if (owner === 'superseded') await connect()
      const currentProcessor = audioService.getProcessor()

      pending.reject(new DOMException('Source already owned', 'InvalidStateError'))
      await expect(oldConnection).resolves.toBeUndefined()

      expect(processor.initializePassiveMode).not.toHaveBeenCalled()
      if (currentProcessor) expect(currentProcessor.initializePassiveMode).not.toHaveBeenCalled()
    })

    it('uses passive mode for an owned InvalidStateError', async () => {
      audioService.initialize()
      const processor = audioService.getProcessor()!
      vi.mocked(processor[method]).mockRejectedValueOnce(new DOMException('Source already owned', 'InvalidStateError'))

      await connect()

      expect(processor.initializePassiveMode).toHaveBeenCalledTimes(1)
    })

    it('ignores a connection replaced while EQ import is pending', async () => {
      const importStarted = deferred()
      const importReady = deferred()
      vi.resetModules()
      vi.doMock('@/features/equalizer/stores/eq-reapply', async () => {
        importStarted.resolve()
        await importReady.promise
        return { reapplyAllEqState }
      })
      const freshService = (await import('@/features/player/services/audio-service')).audioService
      const connectFresh = () => method === 'connectAudioElement'
        ? freshService.connectAudioElement(audioElement())
        : freshService.connectDualAudioElements(audioElement(), audioElement())

      try {
        const oldConnection = connectFresh()
        await importStarted.promise
        freshService.destroy()
        const newConnection = connectFresh()

        importReady.resolve()
        await Promise.all([oldConnection, newConnection])

        expect(reapplyAllEqState).toHaveBeenCalledTimes(1)
      } finally {
        freshService.destroy()
        vi.doMock('@/features/equalizer/stores/eq-reapply', () => ({ reapplyAllEqState }))
      }
    })
  })
})
