import type { EqPresetName } from '@/features/equalizer/stores/eq-bands-store'
import type { LufsTarget, ProcessingModule } from '@/features/equalizer/stores/eq-processing-store'
import type { ContextPanelMode, ContextPanelTab } from '@/features/layout/stores/context-panel-store'

/**
 * Settings action definitions.
 * Dispatched by settings when remote preferences are applied.
 */
export const SETTINGS_ACTIONS = {
  APPLY_EQ: 'settings:apply-eq',
  APPLY_PLAYER: 'settings:apply-player',
  APPLY_LAYOUT: 'settings:apply-layout',
  APPLY_THEME_MOOD: 'settings:apply-theme-mood',
  APPLY_ACCENT_COLOR: 'settings:apply-accent-color',
  PREFERENCES_LOADED: 'preferences-loaded',
} as const

export interface SettingsApplyEqPayload {
  enabled?: boolean
  /** @deprecated Use bandsV2 instead. v1 flat gain array. */
  bands?: number[]
  /** v2 bands with per-band gain + Q */
  bandsV2?: Array<{ gain: number; q: number }>
  preset?: EqPresetName
  visualizerMode?: string
  compressionEnabled?: boolean
  compressorThreshold?: number
  compressorRatio?: number
  compressorKnee?: number
  compressorAttack?: number
  compressorRelease?: number
  masterGain?: number
  normalizationEnabled?: boolean
  targetLufs?: LufsTarget
  stereoEnabled?: boolean
  stereoWidth?: number
  stereoMode?: 'normal' | 'mid' | 'side'
  crossfeedEnabled?: boolean
  crossfeedPreset?: 'light' | 'normal' | 'heavy'
  chainOrder?: ProcessingModule[]
  loudnessContourEnabled?: boolean
}

export interface SettingsApplyPlayerPayload {
  volume?: number
  shuffle?: boolean
  repeat?: 'off' | 'all' | 'one'
}

export interface SettingsApplyLayoutPayload {
  contextPanelMode: ContextPanelMode
  activeTab: ContextPanelTab
}
