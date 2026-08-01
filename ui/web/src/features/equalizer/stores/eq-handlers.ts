import { mediator } from '@/shared/lib/mediator/bus'
import { useEqBandsStore, DEFAULT_Q } from './eq-bands-store'
import { useEqProcessingStore } from './eq-processing-store'
import { SETTINGS_ACTIONS } from '@/features/settings/settings-actions'
import type { SettingsApplyEqPayload } from '@/features/settings/settings-actions'
import { ENGINE_MODES, LEGACY_MODES } from '@/features/visualizer/types'

const PROCESSING_KEYS = [
  'compressionEnabled', 'compressorThreshold', 'compressorRatio',
  'compressorKnee', 'compressorAttack', 'compressorRelease',
  'masterGain', 'normalizationEnabled', 'targetLufs',
  'stereoEnabled', 'stereoWidth', 'stereoMode',
  'crossfeedEnabled', 'crossfeedPreset', 'loudnessContourEnabled',
] as const

export function registerEqHandlers() {
  mediator.on(SETTINGS_ACTIONS.APPLY_EQ, function eqApplySettingsHandler(payload: unknown) {
    const p = payload as SettingsApplyEqPayload
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const bandUpdates: Record<string, any> = {}

    // Band properties
    if (p.enabled !== undefined) bandUpdates.enabled = p.enabled
    if (p.preset !== undefined) bandUpdates.preset = p.preset
    const VALID_VISUALIZER_MODES = [...LEGACY_MODES, ...ENGINE_MODES] as const
    if (p.visualizerMode !== undefined) {
      bandUpdates.visualizerMode = VALID_VISUALIZER_MODES.includes(p.visualizerMode as any)
        ? p.visualizerMode
        : 'spectrum'
    }

    // v1 bands: number[] → convert to BandConfig[]
    if (p.bands !== undefined) {
      bandUpdates.bands = p.bands.map((gain: number) => ({ gain, q: DEFAULT_Q }))
    }

    // v2 bands: BandConfig[] — direct passthrough
    if (p.bandsV2 !== undefined) {
      bandUpdates.bands = p.bandsV2
    }

    if (Object.keys(bandUpdates).length > 0) {
      useEqBandsStore.setState(bandUpdates)
    }

    // Processing properties
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const processingUpdates: Record<string, any> = {}
    for (const key of PROCESSING_KEYS) {
      if (p[key] !== undefined) {
        processingUpdates[key] = p[key]
      }
    }

    if (Object.keys(processingUpdates).length > 0) {
      useEqProcessingStore.setState(processingUpdates)
    }
  })
}
