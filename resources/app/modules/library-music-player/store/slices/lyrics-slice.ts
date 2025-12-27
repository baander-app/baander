import { StateCreator } from 'zustand';

export interface LyricsSlice {
  // State
  lyricsOffset: number;

  // Actions
  setLyricsOffset: (offset: number) => void;
}

export const createLyricsSlice: StateCreator<LyricsSlice> = (set) => ({
  // Initial state
  lyricsOffset: -150,

  // Actions
  setLyricsOffset: (offset) => set({ lyricsOffset: offset }),
});
