import { validateAudioPreferencePayload } from '@/features/settings/audio-preference-payload'
import type { SettingsApplyEqPayload } from '@/features/settings/settings-actions'
import { useEqBandsStore } from './eq-bands-store'
import { useEqProcessingStore } from './eq-processing-store'

/** One typed snapshot shared by profiles, comparison and preference restoration. */
export function captureEqSettings(): SettingsApplyEqPayload {
  const bands = useEqBandsStore.getState()
  const processing = useEqProcessingStore.getState()
  return {
    enabled: bands.enabled, bands: bands.bands.map((band) => ({ ...band })), preset: bands.preset,
    visualizerMode: bands.visualizerMode,
    compressionEnabled: processing.compressionEnabled,
    compressorThreshold: processing.compressorThreshold, compressorRatio: processing.compressorRatio,
    compressorKnee: processing.compressorKnee, compressorAttack: processing.compressorAttack,
    compressorRelease: processing.compressorRelease, masterGain: processing.masterGain,
    normalizationEnabled: processing.normalizationEnabled, targetLufs: processing.targetLufs,
    stereoEnabled: processing.stereoEnabled, stereoWidth: processing.stereoWidth, stereoMode: processing.stereoMode,
    crossfeedEnabled: processing.crossfeedEnabled, crossfeedPreset: processing.crossfeedPreset,
    loudnessContourEnabled: processing.loudnessContourEnabled, chainOrder: [...processing.chainOrder],
  }
}

/** Validate a partial local snapshot before either store or the audio graph changes. */
export function applyEqSettings(patch: Record<string, unknown>): void {
  const { enabled, bands, preset, visualizerMode, ...processing } = validateAudioPreferencePayload({ ...captureEqSettings(), ...patch })
  useEqBandsStore.getState().applySettings({ enabled, bands, preset, visualizerMode })
  useEqProcessingStore.getState().applySettings(processing)
}
