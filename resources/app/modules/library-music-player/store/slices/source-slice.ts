import { StateCreator } from 'zustand';

type Song = { publicId: string; title?: string; } | null;

export interface SourceSlice {
  // State
  song: Song;
  src: string | null;
  audioEl: HTMLAudioElement | null;

  // Actions
  setSong: (song: Song) => void;
  setSource: (src: string | null) => void;
  setAudioEl: (el: HTMLAudioElement | null) => void;
  seekTo: (s: number) => void;
}

export const createSourceSlice: StateCreator<
  SourceSlice,
  [],
  [],
  SourceSlice
> = (set, get) => ({
  // Initial state
  song: null,
  src: null,
  audioEl: null,

  // Actions
  setSong: (song) => set({ song }),

  setSource: (src) => {
    set({ src });
    const el = get().audioEl;
    if (el) {
      el.src = src || '';
      if (src) {
        el.preload = 'auto';
      } else {
        try { el.removeAttribute('src'); } catch {}
      }
    }
  },

  setAudioEl: (el) => set({ audioEl: el }),

  seekTo: (_s) => {
    // Note: This will be overridden in the main store to access duration from TimingSlice
    // The actual implementation is in music-player-store.ts
  },
});
