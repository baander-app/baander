import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useEqProcessingStore } from '../eq-processing-store'

const { processor, getProcessor } = vi.hoisted(() => {
  const processor = { setCompression: vi.fn(), setCompressorParams: vi.fn() }
  return { processor, getProcessor: vi.fn<() => typeof processor | null>() }
})

vi.mock('@/features/player/services/audio-service', () => ({
  audioService: { getProcessor },
}))

beforeEach(() => {
  useEqProcessingStore.setState(useEqProcessingStore.getInitialState(), true)
  vi.clearAllMocks()
  getProcessor.mockReturnValue(processor)
})

describe('compressor controls', () => {
  it('stores disabled parameter edits and restores all saved parameters after enabling', () => {
    const actions = useEqProcessingStore.getState()
    const params = { threshold: -12, ratio: 6, knee: 14, attack: 9, release: 470 }
    actions.setCompressorParams(params)
    expect(processor.setCompressorParams).not.toHaveBeenCalled()
    expect(getProcessor).not.toHaveBeenCalled()

    actions.setCompressionEnabled(true)
    expect(processor.setCompression).toHaveBeenLastCalledWith(true)
    expect(processor.setCompressorParams).toHaveBeenLastCalledWith(params)
    expect(processor.setCompression.mock.invocationCallOrder[0]).toBeLessThan(
      processor.setCompressorParams.mock.invocationCallOrder[0],
    )
  })

  it('preserves saved parameters across disabling, editing and re-enabling', () => {
    const actions = useEqProcessingStore.getState()
    actions.setCompressionEnabled(true)
    actions.setCompressorParams({ threshold: -9, ratio: 8 })
    actions.setCompressionEnabled(false)
    expect(processor.setCompression).toHaveBeenLastCalledWith(false)
    processor.setCompressorParams.mockClear()

    actions.setCompressorParams({ attack: 7 })
    expect(processor.setCompressorParams).not.toHaveBeenCalled()
    expect(useEqProcessingStore.getState().compressionEnabled).toBe(false)

    actions.setCompressionEnabled(true)
    expect(processor.setCompressorParams).toHaveBeenLastCalledWith({
      threshold: -9, ratio: 8, knee: 30, attack: 7, release: 250,
    })
  })

  it('forwards partial edits immediately when enabled', () => {
    const actions = useEqProcessingStore.getState()
    actions.setCompressionEnabled(true)
    processor.setCompressorParams.mockClear()
    actions.setCompressorParams({ release: 510 })
    expect(processor.setCompressorParams).toHaveBeenCalledExactlyOnceWith({ release: 510 })
    expect(useEqProcessingStore.getState()).toMatchObject({ compressorRelease: 510, compressorRatio: 3 })
  })

  it('stores parameters and enable state when no processor exists', () => {
    getProcessor.mockReturnValue(null)
    const actions = useEqProcessingStore.getState()
    expect(() => {
      actions.setCompressorParams({ threshold: -15, ratio: 5 })
      actions.setCompressionEnabled(true)
      actions.setCompressorParams({ attack: 12 })
    }).not.toThrow()
    expect(useEqProcessingStore.getState()).toMatchObject({
      compressionEnabled: true, compressorThreshold: -15, compressorRatio: 5, compressorAttack: 12,
    })
    expect(processor.setCompression).not.toHaveBeenCalled()
    expect(processor.setCompressorParams).not.toHaveBeenCalled()
  })
})
