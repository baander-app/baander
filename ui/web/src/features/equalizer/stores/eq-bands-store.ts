import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { audioService } from '@/features/player/services/audio-service'

import type { VisualizerMode } from '@/features/visualizer/types'

export type { VisualizerMode }
export type EqPresetName = keyof typeof EQ_PRESETS

export interface BandConfig {
  gain: number
  q: number
}

export const EQ_BANDS = [
  { frequency: 31.5, label: '31.5' },
  { frequency: 63, label: '63' },
  { frequency: 125, label: '125' },
  { frequency: 250, label: '250' },
  { frequency: 500, label: '500' },
  { frequency: 1000, label: '1K' },
  { frequency: 2000, label: '2K' },
  { frequency: 4000, label: '4K' },
  { frequency: 8000, label: '8K' },
  { frequency: 16000, label: '16K' },
] as const

export const DEFAULT_Q = 0.7

export const EQ_PRESETS = {
  FLAT: new Array(10).fill(0) as number[],
  ROCK: [4, 3, 1, -1, -2, 0, 1, 3, 4, 4],
  POP: [-1, 1, 3, 4, 3, 0, -1, -1, 1, 2],
  JAZZ: [3, 2, 0, 2, -2, -2, 0, 1, 3, 3],
  CLASSICAL: [4, 3, 2, 1, -1, -1, 0, 2, 3, 4],
  BASS: [6, 5, 4, 2, 0, -1, -2, -2, -1, 0],
  TREBLE: [0, -1, -2, -1, 0, 1, 3, 4, 5, 6],
  VOCAL: [-2, -1, 0, 2, 4, 4, 2, 0, -1, -2],
  LOUDNESS: [6, 5, 4, 2, 0, -1, -2, -3, -4, -5],
} as const

export function flatBands(): BandConfig[] {
  return EQ_PRESETS.FLAT.map((gain) => ({ gain, q: DEFAULT_Q }))
}

export function presetToBands(preset: EqPresetName): BandConfig[] {
  return EQ_PRESETS[preset].map((gain) => ({ gain, q: DEFAULT_Q }))
}

export interface EqBandsState {
  enabled: boolean
  bands: BandConfig[]
  preset: EqPresetName
  visualizerMode: VisualizerMode
  showSystemPanel: boolean

  // Actions
  setEnabled: (enabled: boolean) => void
  setBandGain: (index: number, gain: number) => void
  setBandQ: (index: number, q: number) => void
  setBand: (index: number, gain: number, q: number) => void
  setPreset: (preset: EqPresetName) => void
  cyclePreset: () => void
  setVisualizerMode: (mode: VisualizerMode) => void
  toggleSystemPanel: () => void
}

function applyBandsToProcessor(bands: BandConfig[]) {
  const processor = audioService.getProcessor()
  processor?.updateEQBands(bands)
}

export const reapplyBandsState = () => {
  const { enabled, bands } = useEqBandsStore.getState()
  const processor = audioService.getProcessor()
  if (!processor) return

  applyBandsToProcessor(enabled ? bands : flatBands().map((b) => ({ ...b, gain: 0 })))
}

export const useEqBandsStore = create<EqBandsState>()(
  persist(
    (set, get) => ({
      enabled: true,
      bands: flatBands(),
      preset: 'FLAT',
      visualizerMode: 'spectrum',
      showSystemPanel: false,

      setEnabled: (enabled) => {
        set({ enabled })
        if (!enabled) {
          applyBandsToProcessor(flatBands().map((b) => ({ ...b, gain: 0 })))
        } else {
          applyBandsToProcessor(get().bands)
        }
      },

      setBandGain: (index, gain) => {
        const newBands = get().bands.map((b, i) => (i === index ? { ...b, gain } : b))
        set({ bands: newBands, preset: 'FLAT' })
        if (get().enabled) applyBandsToProcessor(newBands)
      },

      setBandQ: (index, q) => {
        const newBands = get().bands.map((b, i) => (i === index ? { ...b, q } : b))
        set({ bands: newBands, preset: 'FLAT' })
        if (get().enabled) applyBandsToProcessor(newBands)
      },

      setBand: (index, gain, q) => {
        const newBands = get().bands.map((b, i) => (i === index ? { gain, q } : b))
        set({ bands: newBands, preset: 'FLAT' })
        if (get().enabled) applyBandsToProcessor(newBands)
      },

      setPreset: (preset) => {
        const bands = presetToBands(preset)
        set({ bands, preset })
        if (get().enabled) applyBandsToProcessor(bands)
      },

      cyclePreset: () => {
        const presetOrder: EqPresetName[] = [
          'FLAT', 'ROCK', 'POP', 'JAZZ', 'CLASSICAL',
          'BASS', 'TREBLE', 'VOCAL', 'LOUDNESS',
        ]
        const { preset } = get()
        const currentIndex = presetOrder.indexOf(preset)
        const nextPreset = presetOrder[(currentIndex + 1) % presetOrder.length]
        get().setPreset(nextPreset)
      },

      setVisualizerMode: (mode) => {
        set({ visualizerMode: mode })
      },

      toggleSystemPanel: () => {
        set((s) => ({ showSystemPanel: !s.showSystemPanel }))
      },
    }),
    {
      name: 'baander-eq-bands',
      version: 3,
      partialize: (state) => ({
        enabled: state.enabled,
        bands: state.bands,
        preset: state.preset,
        visualizerMode: state.visualizerMode,
        showSystemPanel: state.showSystemPanel,
      }),
      migrate: (persisted, version) => {
        if (version < 2) {
          // v1: { enabled, bands: number[], preset, compressionEnabled, masterGain, ... }
          const old = persisted as Record<string, unknown>
          const oldBands = old.bands as number[] | undefined
          const migratedBands = oldBands
            ? oldBands.map((gain) => ({ gain, q: DEFAULT_Q }))
            : flatBands()

          persisted = { ...old, bands: migratedBands }
        }
        if (version < 3) {
          // v3: added new visualizer modes — reset unknown modes to 'spectrum'
          const VALID_MODES = ['spectrum', 'meters', 'phase', 'enhanced-spectrum', 'circular', 'spectrogram', 'particles']
          const old = persisted as Record<string, unknown>
          const mode = old.visualizerMode as string | undefined
          if (!mode || !VALID_MODES.includes(mode)) {
            persisted = { ...old, visualizerMode: 'spectrum' }
          }
        }
        return persisted
      },
    },
  ),
)
