import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { audioService } from '@/features/player/services/audio-service'

export type LufsTarget = -14 | -16 | -18 | -23
export type ProcessingModule = 'eq' | 'compressor' | 'stereo' | 'crossfeed' | 'loudness' | 'masterGain'

export const DEFAULT_CHAIN_ORDER: ProcessingModule[] = [
  'eq', 'compressor', 'stereo', 'crossfeed', 'loudness', 'masterGain',
]

export const CROSSFEED_PRESETS: Record<'light' | 'normal' | 'heavy', number> = {
  light: 0.2,
  normal: 0.4,
  heavy: 0.7,
}

export interface EqProcessingState {
  // Compressor
  compressionEnabled: boolean
  compressorThreshold: number
  compressorRatio: number
  compressorKnee: number
  compressorAttack: number
  compressorRelease: number

  // Master gain
  masterGain: number

  // Normalization
  normalizationEnabled: boolean
  targetLufs: LufsTarget

  // Stereo
  stereoEnabled: boolean
  stereoWidth: number
  stereoMode: 'normal' | 'mid' | 'side'

  // Crossfeed
  crossfeedEnabled: boolean
  crossfeedPreset: 'light' | 'normal' | 'heavy'

  // Loudness contour
  loudnessContourEnabled: boolean

  // Chain order
  chainOrder: ProcessingModule[]

  // Actions
  setCompressionEnabled: (enabled: boolean) => void
  setCompressorParams: (params: { threshold?: number; ratio?: number; knee?: number; attack?: number; release?: number }) => void
  setMasterGain: (gain: number) => void
  setNormalizationEnabled: (enabled: boolean) => void
  setTargetLufs: (target: LufsTarget) => void
  setStereoEnabled: (enabled: boolean) => void
  setStereoWidth: (width: number) => void
  setStereoMode: (mode: 'normal' | 'mid' | 'side') => void
  setCrossfeedEnabled: (enabled: boolean) => void
  setCrossfeedPreset: (preset: 'light' | 'normal' | 'heavy') => void
  setLoudnessContourEnabled: (enabled: boolean) => void
  setChainOrder: (order: ProcessingModule[]) => void
}

export const getEffectiveStereoWidth = (
  state: Pick<EqProcessingState, 'stereoEnabled' | 'stereoMode' | 'stereoWidth'>,
): number => {
  if (!state.stereoEnabled) return 1
  if (state.stereoMode === 'mid') return 0
  if (state.stereoMode === 'side') return 2
  return state.stereoWidth
}

export const reapplyProcessingState = () => {
  const state = useEqProcessingStore.getState()
  const processor = audioService.getProcessor()
  if (!processor) return

  processor.setCompression(state.compressionEnabled)
  processor.setMasterGain(state.masterGain)
}

export const useEqProcessingStore = create<EqProcessingState>()(
  persist(
    (set) => ({
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

      setCompressionEnabled: (enabled) => {
        set({ compressionEnabled: enabled })
        const processor = audioService.getProcessor()
        processor?.setCompression(enabled)
        if (enabled) {
          const state = useEqProcessingStore.getState()
          processor?.setCompressorParams({
            threshold: state.compressorThreshold,
            ratio: state.compressorRatio,
            knee: state.compressorKnee,
            attack: state.compressorAttack,
            release: state.compressorRelease,
          })
        }
      },

      setCompressorParams: (params) => {
        set((s) => ({
          compressorThreshold: params.threshold ?? s.compressorThreshold,
          compressorRatio: params.ratio ?? s.compressorRatio,
          compressorKnee: params.knee ?? s.compressorKnee,
          compressorAttack: params.attack ?? s.compressorAttack,
          compressorRelease: params.release ?? s.compressorRelease,
        }))
        if (useEqProcessingStore.getState().compressionEnabled) {
          audioService.getProcessor()?.setCompressorParams(params)
        }
      },

      setMasterGain: (gain) => {
        set({ masterGain: gain })
        audioService.getProcessor()?.setMasterGain(gain)
      },

      setNormalizationEnabled: (enabled) => {
        set({ normalizationEnabled: enabled })
        if (!enabled) {
          audioService.getProcessor()?.applyVolumeNormalization(0, 0)
        }
      },

      setTargetLufs: (target) => {
        set({ targetLufs: target })
      },

      setStereoEnabled: (enabled) => {
        set({ stereoEnabled: enabled })
        const state = useEqProcessingStore.getState()
        audioService.getProcessor()?.setStereoWidth(
          getEffectiveStereoWidth(state), state.stereoEnabled ? state.stereoMode : 'normal',
        )
      },

      setStereoWidth: (width) => {
        set({ stereoWidth: width })
        const state = useEqProcessingStore.getState()
        if (state.stereoEnabled) {
          audioService.getProcessor()?.setStereoWidth(getEffectiveStereoWidth(state), state.stereoMode)
        }
      },

      setStereoMode: (mode) => {
        set({ stereoMode: mode })
        const state = useEqProcessingStore.getState()
        if (state.stereoEnabled) {
          audioService.getProcessor()?.setStereoWidth(getEffectiveStereoWidth(state), state.stereoMode)
        }
      },

      setCrossfeedEnabled: (enabled) => {
        set({ crossfeedEnabled: enabled })
        const amount = enabled ? CROSSFEED_PRESETS[useEqProcessingStore.getState().crossfeedPreset] : 0
        audioService.getProcessor()?.setCrossfeed(amount)
      },

      setCrossfeedPreset: (preset) => {
        set({ crossfeedPreset: preset })
        if (useEqProcessingStore.getState().crossfeedEnabled) {
          audioService.getProcessor()?.setCrossfeed(CROSSFEED_PRESETS[preset])
        }
      },

      setLoudnessContourEnabled: (enabled) => {
        set({ loudnessContourEnabled: enabled })
        audioService.getProcessor()?.setLoudnessContour(enabled)
      },

      setChainOrder: (order) => {
        set({ chainOrder: order })
        audioService.getProcessor()?.rebuildChain(order)
      },
    }),
    {
      name: 'baander-eq-processing',
      version: 1,
      partialize: (state) => ({
        compressionEnabled: state.compressionEnabled,
        compressorThreshold: state.compressorThreshold,
        compressorRatio: state.compressorRatio,
        compressorKnee: state.compressorKnee,
        compressorAttack: state.compressorAttack,
        compressorRelease: state.compressorRelease,
        masterGain: state.masterGain,
        normalizationEnabled: state.normalizationEnabled,
        targetLufs: state.targetLufs,
        stereoEnabled: state.stereoEnabled,
        stereoWidth: state.stereoWidth,
        stereoMode: state.stereoMode,
        crossfeedEnabled: state.crossfeedEnabled,
        crossfeedPreset: state.crossfeedPreset,
        loudnessContourEnabled: state.loudnessContourEnabled,
        chainOrder: state.chainOrder,
      }),
    },
  ),
)
