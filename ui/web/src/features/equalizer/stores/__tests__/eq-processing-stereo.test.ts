import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useEqProcessingStore } from '../eq-processing-store'

const { processor, getProcessor } = vi.hoisted(() => {
  const processor = { setStereoWidth: vi.fn() }
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

describe('stereo controls', () => {
  it.each([
    ['normal', 1.4],
    ['mid', 0],
    ['side', 2],
  ] as const)('preserves %s mode across disabling and re-enabling', (mode, effectiveWidth) => {
    const actions = useEqProcessingStore.getState()
    actions.setStereoWidth(1.4)
    actions.setStereoMode(mode)
    expect(processor.setStereoWidth).not.toHaveBeenCalled()

    actions.setStereoEnabled(true)
    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(effectiveWidth, mode)

    actions.setStereoEnabled(false)
    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(1, 'normal')
    expect(useEqProcessingStore.getState()).toMatchObject({ stereoMode: mode, stereoWidth: 1.4 })

    actions.setStereoEnabled(true)
    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(effectiveWidth, mode)
    expect(processor.setStereoWidth).toHaveBeenCalledTimes(3)
  })

  it.each([
    ['mid', 0],
    ['side', 2],
  ] as const)('keeps %s active while storing width changes for normal mode', (mode, effectiveWidth) => {
    const actions = useEqProcessingStore.getState()
    actions.setStereoEnabled(true)
    actions.setStereoMode(mode)
    actions.setStereoWidth(1.7)

    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(effectiveWidth, mode)
    expect(useEqProcessingStore.getState()).toMatchObject({ stereoMode: mode, stereoWidth: 1.7 })

    actions.setStereoMode('normal')
    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(1.7, 'normal')
  })

  it('updates modes and widths while disabled and applies the latest selection on enable', () => {
    const actions = useEqProcessingStore.getState()
    actions.setStereoMode('mid')
    actions.setStereoWidth(0.6)
    actions.setStereoMode('side')
    actions.setStereoWidth(1.8)
    expect(processor.setStereoWidth).not.toHaveBeenCalled()
    expect(getProcessor).not.toHaveBeenCalled()

    actions.setStereoEnabled(true)
    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(2, 'side')
    actions.setStereoMode('normal')
    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(1.8, 'normal')
  })

  it('applies normal width changes immediately when enabled', () => {
    const actions = useEqProcessingStore.getState()
    actions.setStereoEnabled(true)
    actions.setStereoWidth(0.6)
    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(0.6, 'normal')
  })

  it('distinguishes maximum normal width from side-only mode', () => {
    const actions = useEqProcessingStore.getState()
    actions.setStereoWidth(2)
    actions.setStereoEnabled(true)
    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(2, 'normal')

    actions.setStereoMode('side')
    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(2, 'side')

    actions.setStereoMode('normal')
    expect(processor.setStereoWidth).toHaveBeenLastCalledWith(2, 'normal')
  })

  it('stores all stereo settings when no processor exists', () => {
    getProcessor.mockReturnValue(null)
    const actions = useEqProcessingStore.getState()

    expect(() => {
      actions.setStereoEnabled(true)
      actions.setStereoMode('mid')
      actions.setStereoWidth(1.6)
      actions.setStereoMode('side')
      actions.setStereoEnabled(false)
      actions.setStereoEnabled(true)
    }).not.toThrow()

    expect(useEqProcessingStore.getState()).toMatchObject({
      stereoEnabled: true, stereoMode: 'side', stereoWidth: 1.6,
    })
    expect(processor.setStereoWidth).not.toHaveBeenCalled()
  })
})
