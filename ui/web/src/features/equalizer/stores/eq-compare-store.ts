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

export const useEqCompareStore = create<EqCompareState>()((set) => ({
  slotA: null,
  slotB: null,
  activeSlot: null,

  captureSlot: (slot, snapshot) => {
    set({ [slot === 'A' ? 'slotA' : 'slotB']: snapshot, activeSlot: slot })
  },

  setActiveSlot: (slot) => {
    set({ activeSlot: slot })
  },

  clearSlot: (slot) => {
    set({ [slot === 'A' ? 'slotA' : 'slotB']: null, activeSlot: null })
  },

  clearAll: () => {
    set({ slotA: null, slotB: null, activeSlot: null })
  },
}))
