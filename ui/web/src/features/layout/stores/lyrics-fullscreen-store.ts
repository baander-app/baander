import { create } from 'zustand'

interface LyricsFullscreenState {
  isOpen: boolean
  setOpen: (open: boolean) => void
  toggle: () => void
}

export const useLyricsFullscreenStore = create<LyricsFullscreenState>()((set) => ({
  isOpen: false,
  setOpen: (open) => set({ isOpen: open }),
  toggle: () => set((s) => ({ isOpen: !s.isOpen })),
}))
