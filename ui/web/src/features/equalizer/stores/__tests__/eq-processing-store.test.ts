import { describe, it, expect, beforeEach, vi } from 'vitest'
import {
  useEqProcessingStore,
  DEFAULT_CHAIN_ORDER,
  CROSSFEED_PRESETS,
  reapplyProcessingState,
  type ProcessingModule,
} from '../eq-processing-store'
import { audioService } from '@/features/player/services/audio-service'

// Mock the audio service so reapplyProcessingState / setters do not touch real audio.
const mockProcessor = {
  setCompression: vi.fn(),
  setMasterGain: vi.fn(),
  setCompressorParams: vi.fn(),
  applyVolumeNormalization: vi.fn(),
  setStereoWidth: vi.fn(),
  setCrossfeed: vi.fn(),
  setLoudnessContour: vi.fn(),
  rebuildChain: vi.fn(),
}

vi.mock('@/features/player/services/audio-service', () => ({
  audioService: {
    getProcessor: () => mockProcessor,
  },
}))

// Reset both the store and the mocked processor between tests.
beforeEach(() => {
  useEqProcessingStore.setState({
    compressionEnabled: false,
    compressorThreshold: -24,
    compressorRatio: 3,
    compressorKnee: 30,
    compressorAttack: 3,
    compressorRelease: 250,
    masterGain: 0,
    normalizationEnabled: false,
    targetLufs: -14,
    stereoEnabled: false,
    stereoWidth: 1.0,
    stereoMode: 'normal',
    crossfeedEnabled: false,
    crossfeedPreset: 'normal',
    loudnessContourEnabled: false,
    chainOrder: [...DEFAULT_CHAIN_ORDER],
  })
  Object.values(mockProcessor).forEach((fn) => fn.mockReset())
})

describe('useEqProcessingStore — defaults', () => {
  it('exposes the documented default state', () => {
    const state = useEqProcessingStore.getState()
    expect(state.compressionEnabled).toBe(false)
    expect(state.compressorThreshold).toBe(-24)
    expect(state.compressorRatio).toBe(3)
    expect(state.compressorKnee).toBe(30)
    expect(state.compressorAttack).toBe(3)
    expect(state.compressorRelease).toBe(250)
    expect(state.masterGain).toBe(0)
    expect(state.normalizationEnabled).toBe(false)
    expect(state.targetLufs).toBe(-14)
    expect(state.stereoEnabled).toBe(false)
    expect(state.stereoWidth).toBe(1.0)
    expect(state.stereoMode).toBe('normal')
    expect(state.crossfeedEnabled).toBe(false)
    expect(state.crossfeedPreset).toBe('normal')
    expect(state.loudnessContourEnabled).toBe(false)
  })

  it('defaults chainOrder to DEFAULT_CHAIN_ORDER as a copy', () => {
    const { chainOrder } = useEqProcessingStore.getState()
    expect(chainOrder).toEqual(DEFAULT_CHAIN_ORDER)
    // Mutating the exported default must not leak into the store.
    const snapshot = [...chainOrder]
    DEFAULT_CHAIN_ORDER.push('masterGain')
    expect(useEqProcessingStore.getState().chainOrder).toEqual(snapshot)
    DEFAULT_CHAIN_ORDER.pop()
  })

  it('CROSSFEED_PRESETS has the documented amounts', () => {
    expect(CROSSFEED_PRESETS.light).toBe(0.2)
    expect(CROSSFEED_PRESETS.normal).toBe(0.4)
    expect(CROSSFEED_PRESETS.heavy).toBe(0.7)
  })
})

describe('useEqProcessingStore — setCompressorParams partial merge', () => {
  it('updates only the provided compressor fields and preserves the rest', () => {
    useEqProcessingStore.getState().setCompressorParams({ threshold: -10, ratio: 4 })

    const state = useEqProcessingStore.getState()
    expect(state.compressorThreshold).toBe(-10)
    expect(state.compressorRatio).toBe(4)
    // Untouched fields keep their prior values.
    expect(state.compressorKnee).toBe(30)
    expect(state.compressorAttack).toBe(3)
    expect(state.compressorRelease).toBe(250)
  })

  it('forwards the partial params to the processor', () => {
    useEqProcessingStore.getState().setCompressionEnabled(true)
    mockProcessor.setCompressorParams.mockClear()
    const params = { attack: 20, release: 300 }
    useEqProcessingStore.getState().setCompressorParams(params)
    expect(mockProcessor.setCompressorParams).toHaveBeenCalledWith(params)
  })

  it('does not mutate fields when given an empty object', () => {
    const before = useEqProcessingStore.getState()
    useEqProcessingStore.getState().setCompressorParams({})
    const after = useEqProcessingStore.getState()
    expect(after.compressorThreshold).toBe(before.compressorThreshold)
    expect(after.compressorRatio).toBe(before.compressorRatio)
    expect(after.compressorKnee).toBe(before.compressorKnee)
    expect(after.compressorAttack).toBe(before.compressorAttack)
    expect(after.compressorRelease).toBe(before.compressorRelease)
  })
})

describe('useEqProcessingStore — setChainOrder', () => {
  it('reorders the chain to the provided order', () => {
    const order: ProcessingModule[] = ['masterGain', 'eq', 'compressor', 'stereo', 'crossfeed', 'loudness']
    useEqProcessingStore.getState().setChainOrder(order)
    expect(useEqProcessingStore.getState().chainOrder).toEqual(order)
  })

  it('triggers a processor chain rebuild with the new order', () => {
    const order: ProcessingModule[] = ['eq', 'masterGain']
    useEqProcessingStore.getState().setChainOrder(order)
    expect(mockProcessor.rebuildChain).toHaveBeenCalledWith(order)
    expect(mockProcessor.rebuildChain).toHaveBeenCalledTimes(1)
  })
})

describe('useEqProcessingStore — enable toggles', () => {
  it('setCompressionEnabled flips the flag and calls processor.setCompression', () => {
    useEqProcessingStore.getState().setCompressionEnabled(true)
    expect(useEqProcessingStore.getState().compressionEnabled).toBe(true)
    expect(mockProcessor.setCompression).toHaveBeenCalledWith(true)
  })

  it('setStereoEnabled updates state and applies the stored width when enabling', () => {
    useEqProcessingStore.getState().setStereoWidth(1.5)
    mockProcessor.setStereoWidth.mockClear()

    useEqProcessingStore.getState().setStereoEnabled(true)
    expect(useEqProcessingStore.getState().stereoEnabled).toBe(true)
    // Enabling pushes the stored width (1.5), not the default.
    expect(mockProcessor.setStereoWidth).toHaveBeenCalledWith(1.5)
  })

  it('setStereoEnabled resets width to 1 when disabling', () => {
    useEqProcessingStore.getState().setStereoEnabled(true)
    useEqProcessingStore.getState().setStereoWidth(2)
    mockProcessor.setStereoWidth.mockClear()

    useEqProcessingStore.getState().setStereoEnabled(false)
    expect(useEqProcessingStore.getState().stereoEnabled).toBe(false)
    expect(mockProcessor.setStereoWidth).toHaveBeenCalledWith(1)
  })

  it('setCrossfeedEnabled maps the preset to an amount via CROSSFEED_PRESETS', () => {
    useEqProcessingStore.getState().setCrossfeedPreset('heavy')
    useEqProcessingStore.getState().setCrossfeedEnabled(true)
    expect(useEqProcessingStore.getState().crossfeedEnabled).toBe(true)
    expect(mockProcessor.setCrossfeed).toHaveBeenCalledWith(CROSSFEED_PRESETS.heavy)
  })

  it('setCrossfeedEnabled sets amount to 0 when disabling', () => {
    useEqProcessingStore.getState().setCrossfeedEnabled(true)
    mockProcessor.setCrossfeed.mockClear()
    useEqProcessingStore.getState().setCrossfeedEnabled(false)
    expect(mockProcessor.setCrossfeed).toHaveBeenCalledWith(0)
  })

  it('setCrossfeedPreset only reaches the processor when crossfeed is enabled', () => {
    // Disabled by default -> no processor call.
    useEqProcessingStore.getState().setCrossfeedPreset('light')
    expect(mockProcessor.setCrossfeed).not.toHaveBeenCalled()
    expect(useEqProcessingStore.getState().crossfeedPreset).toBe('light')

    // Enable, then changing the preset forwards the mapped amount.
    useEqProcessingStore.getState().setCrossfeedEnabled(true)
    mockProcessor.setCrossfeed.mockClear()
    useEqProcessingStore.getState().setCrossfeedPreset('light')
    expect(mockProcessor.setCrossfeed).toHaveBeenCalledWith(CROSSFEED_PRESETS.light)
  })

  it('setLoudnessContourEnabled flips the flag and forwards to the processor', () => {
    useEqProcessingStore.getState().setLoudnessContourEnabled(true)
    expect(useEqProcessingStore.getState().loudnessContourEnabled).toBe(true)
    expect(mockProcessor.setLoudnessContour).toHaveBeenCalledWith(true)
  })

  it('setNormalizationEnabled clears normalization on the processor when disabling', () => {
    useEqProcessingStore.getState().setNormalizationEnabled(true)
    expect(useEqProcessingStore.getState().normalizationEnabled).toBe(true)

    useEqProcessingStore.getState().setNormalizationEnabled(false)
    expect(useEqProcessingStore.getState().normalizationEnabled).toBe(false)
    expect(mockProcessor.applyVolumeNormalization).toHaveBeenLastCalledWith(0, 0)
  })

  it('setMasterGain updates state and forwards to the processor', () => {
    useEqProcessingStore.getState().setMasterGain(-3)
    expect(useEqProcessingStore.getState().masterGain).toBe(-3)
    expect(mockProcessor.setMasterGain).toHaveBeenCalledWith(-3)
  })

  it('setTargetLufs updates state', () => {
    useEqProcessingStore.getState().setTargetLufs(-23)
    expect(useEqProcessingStore.getState().targetLufs).toBe(-23)
  })

  it('setStereoMode translates mid->0 and side->2 when stereo is enabled', () => {
    useEqProcessingStore.getState().setStereoEnabled(true)
    mockProcessor.setStereoWidth.mockClear()

    useEqProcessingStore.getState().setStereoMode('mid')
    expect(useEqProcessingStore.getState().stereoMode).toBe('mid')
    expect(mockProcessor.setStereoWidth).toHaveBeenLastCalledWith(0)

    useEqProcessingStore.getState().setStereoMode('side')
    expect(mockProcessor.setStereoWidth).toHaveBeenLastCalledWith(2)
  })

  it('setStereoMode does not reach the processor when stereo is disabled', () => {
    useEqProcessingStore.getState().setStereoMode('mid')
    expect(useEqProcessingStore.getState().stereoMode).toBe('mid')
    expect(mockProcessor.setStereoWidth).not.toHaveBeenCalled()
  })
})

describe('reapplyProcessingState', () => {
  it('forwards the current compression and masterGain to the processor', () => {
    useEqProcessingStore.getState().setCompressionEnabled(true)
    useEqProcessingStore.getState().setMasterGain(-6)
    // Clear the per-setter calls so we observe only reapply traffic.
    mockProcessor.setCompression.mockClear()
    mockProcessor.setMasterGain.mockClear()

    reapplyProcessingState()

    expect(mockProcessor.setCompression).toHaveBeenCalledWith(true)
    expect(mockProcessor.setMasterGain).toHaveBeenCalledWith(-6)
  })

  it('returns early and does not touch the processor when getProcessor() is null', () => {
    // Temporarily swap the mocked singleton to return null.
    const realGetProcessor = audioService.getProcessor
    ;(audioService as { getProcessor: () => unknown }).getProcessor = () => null

    expect(() => reapplyProcessingState()).not.toThrow()
    expect(mockProcessor.setCompression).not.toHaveBeenCalled()
    expect(mockProcessor.setMasterGain).not.toHaveBeenCalled()

    ;(audioService as { getProcessor: () => unknown }).getProcessor = realGetProcessor
  })
})

describe('useEqProcessingStore — persist partialize', () => {
  // Zustand's persist middleware exposes its options via .persist.getOptions().
  const getPartializedSnapshot = () =>
    useEqProcessingStore.persist.getOptions().partialize!(useEqProcessingStore.getState())

  it('partialize includes all persisted data fields', () => {
    // Set non-default values so we can confirm they round-trip through partialize.
    useEqProcessingStore.setState({
      compressionEnabled: true,
      compressorThreshold: -10,
      compressorRatio: 6,
      compressorKnee: 20,
      compressorAttack: 10,
      compressorRelease: 500,
      masterGain: -2,
      normalizationEnabled: true,
      targetLufs: -23,
      stereoEnabled: true,
      stereoWidth: 1.8,
      stereoMode: 'side',
      crossfeedEnabled: true,
      crossfeedPreset: 'heavy',
      loudnessContourEnabled: true,
      chainOrder: ['masterGain', 'eq'],
    })

    const snapshot = getPartializedSnapshot() as Record<string, unknown>

    expect(snapshot.compressionEnabled).toBe(true)
    expect(snapshot.compressorThreshold).toBe(-10)
    expect(snapshot.compressorRatio).toBe(6)
    expect(snapshot.compressorKnee).toBe(20)
    expect(snapshot.compressorAttack).toBe(10)
    expect(snapshot.compressorRelease).toBe(500)
    expect(snapshot.masterGain).toBe(-2)
    expect(snapshot.normalizationEnabled).toBe(true)
    expect(snapshot.targetLufs).toBe(-23)
    expect(snapshot.stereoEnabled).toBe(true)
    expect(snapshot.stereoWidth).toBe(1.8)
    expect(snapshot.stereoMode).toBe('side')
    expect(snapshot.crossfeedEnabled).toBe(true)
    expect(snapshot.crossfeedPreset).toBe('heavy')
    expect(snapshot.loudnessContourEnabled).toBe(true)
    expect(snapshot.chainOrder).toEqual(['masterGain', 'eq'])
  })

  it('partialize excludes every action setter', () => {
    const snapshot = getPartializedSnapshot() as Record<string, unknown>

    const actions = [
      'setCompressionEnabled',
      'setCompressorParams',
      'setMasterGain',
      'setNormalizationEnabled',
      'setTargetLufs',
      'setStereoEnabled',
      'setStereoWidth',
      'setStereoMode',
      'setCrossfeedEnabled',
      'setCrossfeedPreset',
      'setLoudnessContourEnabled',
      'setChainOrder',
    ]
    for (const action of actions) {
      expect(snapshot[action]).toBeUndefined()
    }
  })

  it('persisted store name is baander-eq-processing', () => {
    expect(useEqProcessingStore.persist.getOptions().name).toBe('baander-eq-processing')
  })
})
