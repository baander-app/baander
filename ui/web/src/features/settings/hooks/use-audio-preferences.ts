import { useCallback } from 'react'
import { useEqBandsStore, DEFAULT_Q } from '@/features/equalizer/stores/eq-bands-store'
import { useEqProcessingStore } from '@/features/equalizer/stores/eq-processing-store'
import { mediator } from '@/shared/lib/mediator/bus'
import { SETTINGS_ACTIONS } from '@/features/settings/settings-actions'
import { usePreferenceSync } from './use-preference-sync'

interface AudioPreferencePayload {
  enabled: boolean
  bands: number[]
  bandsV2?: Array<{ gain: number; q: number }>
  preset: string
  compressionEnabled: boolean
  compressorThreshold: number
  compressorRatio: number
  compressorKnee: number
  compressorAttack: number
  compressorRelease: number
  masterGain: number
  normalizationEnabled: boolean
  targetLufs: number
  visualizerMode: string
  stereoEnabled: boolean
  stereoWidth: number
  stereoMode: string
  crossfeedEnabled: boolean
  crossfeedPreset: string
  loudnessContourEnabled: boolean
  chainOrder: string[]
}

function snapshotAudioPreferences(): AudioPreferencePayload {
  const bandsState = useEqBandsStore.getState()
  const processingState = useEqProcessingStore.getState()
  return {
    enabled: bandsState.enabled,
    bands: bandsState.bands.map((b) => b.gain),
    bandsV2: bandsState.bands.map((band) => ({ ...band })),
    preset: bandsState.preset,
    compressionEnabled: processingState.compressionEnabled,
    compressorThreshold: processingState.compressorThreshold,
    compressorRatio: processingState.compressorRatio,
    compressorKnee: processingState.compressorKnee,
    compressorAttack: processingState.compressorAttack,
    compressorRelease: processingState.compressorRelease,
    masterGain: processingState.masterGain,
    normalizationEnabled: processingState.normalizationEnabled,
    targetLufs: processingState.targetLufs,
    visualizerMode: bandsState.visualizerMode,
    stereoEnabled: processingState.stereoEnabled,
    stereoWidth: processingState.stereoWidth,
    stereoMode: processingState.stereoMode,
    crossfeedEnabled: processingState.crossfeedEnabled,
    crossfeedPreset: processingState.crossfeedPreset,
    loudnessContourEnabled: processingState.loudnessContourEnabled,
    chainOrder: [...processingState.chainOrder],
  }
}

export function useAudioPreferences(isActive?: () => boolean) {

  const sync = usePreferenceSync<AudioPreferencePayload>({
    isActive,
    baseUrl: '/api/user/audio-preferences/',
    toPayload: (state) => ({ ...state }),
    fromPayload: (payload) => payload as unknown as AudioPreferencePayload,
    onRemoteUpdate: useCallback((data) => {
      mediator.dispatch(SETTINGS_ACTIONS.APPLY_EQ, {
        enabled: data.enabled,
        bands: data.bandsV2 ? undefined : data.bands,
        bandsV2: data.bandsV2 ?? data.bands?.map((gain: number) => ({ gain, q: DEFAULT_Q })),
        preset: data.preset,
        compressionEnabled: data.compressionEnabled,
        masterGain: data.masterGain,
        normalizationEnabled: data.normalizationEnabled,
        targetLufs: data.targetLufs,
        visualizerMode: data.visualizerMode,
        stereoEnabled: data.stereoEnabled,
        stereoWidth: data.stereoWidth,
        stereoMode: data.stereoMode,
        crossfeedEnabled: data.crossfeedEnabled,
        crossfeedPreset: data.crossfeedPreset,
        loudnessContourEnabled: data.loudnessContourEnabled,
      }, 'settings')
    }, []),
  })

  return {
    ...sync,
    pushToServer: () => sync.pushToServer(snapshotAudioPreferences()),
    resolveConflict: (resolution: 'mine' | 'theirs') =>
      sync.resolveConflict(resolution, snapshotAudioPreferences()),
  }
}
