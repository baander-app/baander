import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { withStoreDebug } from '@/shared/stores/debug'
import { create } from 'zustand'
import type { PaletteColors } from '../types'

interface PaletteState {
  palettes: Record<string, PaletteColors>
  extractions: Record<string, symbol>
  getPalette: (albumPublicId: string) => PaletteColors | null
  beginExtraction: (albumPublicId: string) => symbol
  finishExtraction: (albumPublicId: string, token: symbol, palette: PaletteColors | null) => void
  cancelExtraction: (albumPublicId: string, token: symbol) => void
  clearAll: () => void
}

export const usePaletteStore = create<PaletteState>()(withStoreDebug('visualizer.palette', withNoopGuard((set, get) => ({
  palettes: {},
  extractions: {},
  getPalette: (albumPublicId) => get().palettes[albumPublicId] ?? null,
  beginExtraction: (albumPublicId) => {
    const token = Symbol(albumPublicId)
    set((state) => {
      const palettes = { ...state.palettes }
      delete palettes[albumPublicId]
      return { palettes, extractions: { ...state.extractions, [albumPublicId]: token } }
    })
    return token
  },
  finishExtraction: (albumPublicId, token, palette) => {
    if (get().extractions[albumPublicId] !== token) return
    set((state) => {
      const extractions = { ...state.extractions }
      delete extractions[albumPublicId]
      return {
        extractions,
        palettes: palette ? { ...state.palettes, [albumPublicId]: palette } : state.palettes,
      }
    })
  },
  cancelExtraction: (albumPublicId, token) => {
    if (get().extractions[albumPublicId] !== token) return
    set((state) => {
      const extractions = { ...state.extractions }
      delete extractions[albumPublicId]
      return { extractions }
    })
  },
  clearAll: () => {
    if (Object.keys(get().palettes).length === 0 && Object.keys(get().extractions).length === 0) return
    set({ palettes: {}, extractions: {} })
  },
}))))
