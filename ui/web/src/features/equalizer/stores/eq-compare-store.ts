import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { withStoreDebug } from '@/shared/stores/debug'
import { create } from 'zustand'

export interface EqSnapshot {
  id: string
  label: string
  timestamp: number
  bands: Array<{ gain: number; q: number }>
  processing: Record<string, unknown>
}

export interface EqCompareState {
  slotA: EqSnapshot | null
  slotB: EqSnapshot | null
  activeSlot: 'A' | 'B' | null

  // Actions
  captureSlot: (slot: 'A' | 'B', snapshot: EqSnapshot) => void
  setActiveSlot: (slot: 'A' | 'B' | null) => void
  clearSlot: (slot: 'A' | 'B') => void
  clearAll: () => void
}

export const useEqCompareStore = create<EqCompareState>()(withStoreDebug('equalizer.eq-compare', withNoopGuard((set) => ({
  slotA: null,
  slotB: null,
  activeSlot: null,

  captureSlot: (slot, snapshot) => {
    set((state) => state[slot === 'A' ? 'slotA' : 'slotB'] === snapshot && state.activeSlot === slot ? state : { [slot === 'A' ? 'slotA' : 'slotB']: snapshot, activeSlot: slot })
  },

  setActiveSlot: (slot) => {
    set((state) => state.activeSlot === slot ? state : { activeSlot: slot })
  },

  clearSlot: (slot) => {
    set((state) => state[slot === 'A' ? 'slotA' : 'slotB'] === null && state.activeSlot !== slot ? state : {
      [slot === 'A' ? 'slotA' : 'slotB']: null, activeSlot: state.activeSlot === slot ? null : state.activeSlot,
    })
  },

  clearAll: () => {
    set((state) => state.slotA === null && state.slotB === null && state.activeSlot === null ? state : { slotA: null, slotB: null, activeSlot: null })
  },
}))))
