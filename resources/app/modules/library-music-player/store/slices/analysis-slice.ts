import { StateCreator } from 'zustand';

export interface AnalysisSlice {
  // State (runtime-only, not persisted)
  analysis: {
    leftChannel: number;
    rightChannel: number;
    frequencies: number[];
    lufs: number;
    bufferSize: number;
  };

  // Actions
  setLeftChannel: (level: number) => void;
  setRightChannel: (level: number) => void;
  setFrequencies: (freqs: number[]) => void;
  setLufs: (lufs: number) => void;
  setBufferSize: (size: number) => void;
}

export const createAnalysisSlice: StateCreator<AnalysisSlice> = (set) => ({
  // Initial state
  analysis: {
    leftChannel: 0,
    rightChannel: 0,
    frequencies: [],
    lufs: 0,
    bufferSize: 0,
  },

  // Actions
  setLeftChannel: (level) => set((state) => ({
    analysis: { ...state.analysis, leftChannel: level },
  })),

  setRightChannel: (level) => set((state) => ({
    analysis: { ...state.analysis, rightChannel: level },
  })),

  setFrequencies: (freqs) => set((state) => ({
    analysis: { ...state.analysis, frequencies: freqs },
  })),

  setLufs: (lufs) => set((state) => ({
    analysis: { ...state.analysis, lufs },
  })),

  setBufferSize: (size) => set((state) => ({
    analysis: { ...state.analysis, bufferSize: size },
  })),
});
