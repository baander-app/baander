import { StateCreator } from 'zustand';

export interface TimingSlice {
  // State
  duration: number;
  currentTime: number;
  buffered: number;
  lastTimeUpdateMs: number;

  // Actions
  setDuration: (s: number) => void;
  setCurrentTime: (s: number) => void;
  setBuffered: (s: number) => void;
}

export const createTimingSlice: StateCreator<TimingSlice> = (set) => ({
  // Initial state
  duration: 0,
  currentTime: 0,
  buffered: 0,
  lastTimeUpdateMs: 0,

  // Actions
  setDuration: (s) => set({ duration: Number.isFinite(s) ? s : 0 }),

  setCurrentTime: (s) => set({
    currentTime: Number.isFinite(s) ? s : 0,
    lastTimeUpdateMs: performance.now()
  }),

  setBuffered: (s) => set({ buffered: Number.isFinite(s) ? s : 0 }),
});
