import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { withStoreDebug } from '@/shared/stores/debug'
import { create } from 'zustand'

interface LyricsFullscreenState {
  isOpen: boolean
  setOpen: (open: boolean) => void
  toggle: () => void
}

export const useLyricsFullscreenStore = create<LyricsFullscreenState>()(withStoreDebug('layout.lyrics-fullscreen', withNoopGuard((set) => ({
  isOpen: false,
  setOpen: (open) => set({ isOpen: open }),
  toggle: () => set((s) => ({ isOpen: !s.isOpen })),
}))))
