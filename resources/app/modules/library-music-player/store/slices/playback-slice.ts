import { StateCreator } from 'zustand';

export interface PlaybackSlice {
  // State
  isPlaying: boolean;
  isReady: boolean;
  progress: number;

  // Actions
  setIsPlaying: (v: boolean) => void;
  setIsReady: (v: boolean) => void;
  setProgress: (progress: number) => void;
  play: () => Promise<void> | void;
  pause: () => void;
  togglePlayPause: () => Promise<void> | void;
}

export const createPlaybackSlice: StateCreator<
  PlaybackSlice,
  [],
  [],
  PlaybackSlice
> = (set) => ({
  // Initial state
  isPlaying: false,
  isReady: false,
  progress: 0,

  // Actions
  setIsPlaying: (v) => set({ isPlaying: !!v }),

  setIsReady: (v) => set({ isReady: !!v }),

  setProgress: (progress) => set({ progress }),

  play: async () => {
    // Note: This will be overridden in the main store to access audioEl from SourceSlice
    // The actual implementation is in music-player-store.ts
  },

  pause: () => {
    // Note: This will be overridden in the main store to access audioEl from SourceSlice
    // The actual implementation is in music-player-store.ts
  },

  togglePlayPause: async () => {
    // Note: This will be overridden in the main store
    // The actual implementation is in music-player-store.ts
  },
});
