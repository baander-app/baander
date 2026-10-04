import { EQ_PRESETS, type EqPresetName } from '@/features/equalizer/stores/eq-bands-store'
import { DEFAULT_CHAIN_ORDER, type ProcessingModule } from '@/features/equalizer/stores/eq-processing-store'
import { ENGINE_MODES, LEGACY_MODES } from '@/features/visualizer/types'
import type { SettingsApplyEqPayload } from './settings-actions'

const FIELDS = [
  'enabled', 'bands', 'preset', 'visualizerMode', 'compressionEnabled',
  'compressorThreshold', 'compressorRatio', 'compressorKnee', 'compressorAttack',
  'compressorRelease', 'masterGain', 'normalizationEnabled', 'targetLufs',
  'stereoEnabled', 'stereoWidth', 'stereoMode', 'crossfeedEnabled',
  'crossfeedPreset', 'loudnessContourEnabled', 'chainOrder',
]
const VISUALIZER_MODES = [...ENGINE_MODES, ...LEGACY_MODES]

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function record(value: unknown): Record<string, unknown> {
  if (!isRecord(value)) throw new Error('Invalid audio preferences object')
  return value
}

function exactFields(value: Record<string, unknown>, fields: readonly string[]) {
  const keys = Object.keys(value)
  if (keys.length !== fields.length || fields.some((key) => !Object.hasOwn(value, key))) {
    throw new Error('Invalid audio preference fields')
  }
}

function boolean(value: unknown): boolean {
  if (typeof value !== 'boolean') throw new Error('Invalid audio preference boolean')
  return value
}

function number(value: unknown, min: number, max: number): number {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < min || value > max) throw new Error('Invalid audio preference number')
  return value
}

function choice<T extends string | number>(value: unknown, choices: readonly T[]): T {
  const result = choices.find((item) => item === value)
  if (result === undefined) throw new Error('Invalid audio preference choice')
  return result
}

function isPreset(value: string): value is EqPresetName {
  return Object.hasOwn(EQ_PRESETS, value)
}

function bands(value: unknown): Array<{ gain: number; q: number }> {
  if (!Array.isArray(value) || value.length !== 10) throw new Error('Invalid audio preference bands')
  return Array.from(value, (item: unknown) => {
    const band = record(item)
    exactFields(band, ['gain', 'q'])
    return { gain: number(band.gain, -12, 12), q: number(band.q, 0.1, 10) }
  })
}

function chain(value: unknown): ProcessingModule[] {
  if (!Array.isArray(value) || value.length !== DEFAULT_CHAIN_ORDER.length) throw new Error('Invalid audio preference chain')
  const modules = Array.from(value, (item: unknown) => choice(item, DEFAULT_CHAIN_ORDER))
  if (new Set(modules).size !== DEFAULT_CHAIN_ORDER.length) throw new Error('Invalid audio preference chain permutation')
  return modules
}

/** Validate the complete remote snapshot before any store is changed. */
export function validateAudioPreferencePayload(value: unknown): SettingsApplyEqPayload {
  const p = record(value)
  exactFields(p, FIELDS)
  return {
    enabled: boolean(p.enabled), bands: bands(p.bands),
    preset: choice(p.preset, Object.keys(EQ_PRESETS).filter(isPreset)),
    visualizerMode: choice(p.visualizerMode, VISUALIZER_MODES),
    compressionEnabled: boolean(p.compressionEnabled),
    compressorThreshold: number(p.compressorThreshold, -50, 0),
    compressorRatio: number(p.compressorRatio, 1, 20),
    compressorKnee: number(p.compressorKnee, 0, 40),
    compressorAttack: number(p.compressorAttack, 0.1, 100),
    compressorRelease: number(p.compressorRelease, 10, 1000),
    masterGain: number(p.masterGain, -12, 12),
    normalizationEnabled: boolean(p.normalizationEnabled),
    targetLufs: choice(p.targetLufs, [-14, -16, -18, -23]),
    stereoEnabled: boolean(p.stereoEnabled), stereoWidth: number(p.stereoWidth, 0, 2),
    stereoMode: choice(p.stereoMode, ['normal', 'mid', 'side']),
    crossfeedEnabled: boolean(p.crossfeedEnabled),
    crossfeedPreset: choice(p.crossfeedPreset, ['light', 'normal', 'heavy']),
    loudnessContourEnabled: boolean(p.loudnessContourEnabled), chainOrder: chain(p.chainOrder),
  }
}
