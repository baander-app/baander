import { StateCreator } from 'zustand';

export interface PlaybackModeSlice {
  // State
  playbackMode: {
    isShuffleEnabled: boolean;
    isRepeatEnabled: boolean;
  };

  // Actions
  setShuffleEnabled: (enabled: boolean) => void;
  setRepeatEnabled: (enabled: boolean) => void;
}

export const createPlaybackModeSlice: StateCreator<PlaybackModeSlice> = (set) => ({
  // Initial state
  playbackMode: {
    isShuffleEnabled: false,
    isRepeatEnabled: false,
  },

  // Actions
  setShuffleEnabled: (enabled) => set((state) => ({
    playbackMode: {
      ...state.playbackMode,
      isShuffleEnabled: enabled,
      isRepeatEnabled: false, // Mutual exclusive
    },
  })),

  setRepeatEnabled: (enabled) => set((state) => ({
    playbackMode: {
      ...state.playbackMode,
      isRepeatEnabled: enabled,
      isShuffleEnabled: false, // Mutual exclusive
    },
  })),
});
