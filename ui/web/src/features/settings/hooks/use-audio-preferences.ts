import { useCallback } from 'react'
import { useEqBandsStore } from '@/features/equalizer/stores/eq-bands-store'
import { useEqProcessingStore } from '@/features/equalizer/stores/eq-processing-store'
import { mediator } from '@/shared/lib/mediator/bus'
import { SETTINGS_ACTIONS, type SettingsApplyEqPayload } from '@/features/settings/settings-actions'
import { validateAudioPreferencePayload } from '../audio-preference-payload'
import { usePreferenceSync } from './use-preference-sync'

type AudioPreferencePayload = SettingsApplyEqPayload

function snapshotAudioPreferences(): AudioPreferencePayload {
  const bandsState = useEqBandsStore.getState()
  const processingState = useEqProcessingStore.getState()
  return {
    enabled: bandsState.enabled,
    bands: bandsState.bands.map((band) => ({ ...band })),
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
    fromPayload: validateAudioPreferencePayload,
    onRemoteUpdate: useCallback((data) => {
      mediator.dispatch(SETTINGS_ACTIONS.APPLY_EQ, data, 'settings')
    }, []),
  })

  return {
    ...sync,
    pushToServer: () => sync.pushToServer(snapshotAudioPreferences()),
    resolveConflict: (resolution: 'mine' | 'theirs') =>
      sync.resolveConflict(resolution, snapshotAudioPreferences()),
  }
}
