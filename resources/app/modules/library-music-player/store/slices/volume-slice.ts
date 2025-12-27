import { StateCreator } from 'zustand';

export interface VolumeSlice {
  // State
  volumePercent: number; // 0..100
  isMuted: boolean;

  // Actions
  setVolumePercent: (v: number) => void;
  setMuted: (v: boolean) => void;
  toggleMute: () => void;
}

export const createVolumeSlice: StateCreator<
  VolumeSlice,
  [],
  [],
  VolumeSlice
> = (set, get) => ({
  // Initial state
  volumePercent: 100,
  isMuted: false,

  // Actions
  setVolumePercent: (v) => {
    const level = Math.max(0, Math.min(100, Math.round(v)));
    set({ volumePercent: level });
    // Note: audioEl interaction will be handled in the main store
  },

  setMuted: (v) => {
    set({ isMuted: !!v });
    // Note: audioEl interaction will be handled in the main store
  },

  toggleMute: () => {
    const next = !get().isMuted;
    set({ isMuted: next });
    // Note: audioEl interaction will be handled in the main store
  },
});
