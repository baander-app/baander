import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { withStoreDebug } from '@/shared/stores/debug'
import { create } from 'zustand'
import type { PaletteColors } from '../types'

interface PaletteState {
  /** Cached palettes keyed by albumPublicId. */
  palettes: Record<string, PaletteColors>
  /** Set of albumPublicIds currently being extracted. */
  extracting: Set<string>

  /** Get palette for an album. Returns null if not yet extracted. */
  getPalette: (albumPublicId: string) => PaletteColors | null
  /** Mark an album as currently being extracted (prevents duplicate work). */
  startExtraction: (albumPublicId: string) => void
  /** Store an extracted palette. */
  setPalette: (albumPublicId: string, palette: PaletteColors) => void
  /** Remove palette for an album (e.g., on error or unmount). */
  removePalette: (albumPublicId: string) => void
  /** Clear all cached palettes. */
  clearAll: () => void
}

export const usePaletteStore = create<PaletteState>()(withStoreDebug('visualizer.palette', withNoopGuard((set, get) => ({
  palettes: {},
  extracting: new Set<string>(),

  getPalette: (albumPublicId: string) => {
    return get().palettes[albumPublicId] ?? null
  },

  startExtraction: (albumPublicId: string) => {
    const current = get().extracting
    if (current.has(albumPublicId)) return
    const next = new Set(current)
    next.add(albumPublicId)
    set({ extracting: next })
  },

  setPalette: (albumPublicId: string, palette: PaletteColors) => {
    if (get().palettes[albumPublicId] === palette && !get().extracting.has(albumPublicId)) return
    const current = get().extracting
    const next = new Set(current)
    next.delete(albumPublicId)
    set((s) => ({
      palettes: { ...s.palettes, [albumPublicId]: palette },
      extracting: next,
    }))
  },

  removePalette: (albumPublicId: string) => {
    if (!(albumPublicId in get().palettes) && !get().extracting.has(albumPublicId)) return
    set((s) => {
      const rest = { ...s.palettes }
      delete rest[albumPublicId]
      const nextExtracting = new Set(s.extracting)
      nextExtracting.delete(albumPublicId)
      return { palettes: rest, extracting: nextExtracting }
    })
  },

  clearAll: () => {
    if (Object.keys(get().palettes).length === 0 && get().extracting.size === 0) return
    set({ palettes: {}, extracting: new Set<string>() })
  },
}))))
