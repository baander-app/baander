import { StateCreator } from 'zustand';

type ProcessorApi = {
  connect?: (el: HTMLAudioElement) => Promise<void> | void;
  setPlayingState?: (playing: boolean) => void;
  resumeContextIfNeeded?: () => Promise<void> | void;
} | null;

export interface ProcessorSlice {
  // State
  processor: ProcessorApi;
  processorConnected: boolean;
  hasUserInteracted: boolean;

  // Actions
  connectAudioProcessor: (api: ProcessorApi) => Promise<void> | void;
  resumeProcessorContext: () => Promise<void> | void;
  setHasUserInteracted: (v: boolean) => void;
}

export const createProcessorSlice: StateCreator<
  ProcessorSlice,
  [],
  [],
  ProcessorSlice
> = (set) => ({
  // Initial state
  processor: null,
  processorConnected: false,
  hasUserInteracted: false,

  // Actions
  connectAudioProcessor: async (_api) => {
    // Note: This will be overridden in the main store to access audioEl from SourceSlice
    // The actual implementation is in music-player-store.ts
  },

  resumeProcessorContext: async () => {
    // Note: This will be overridden in the main store to access processor from this slice
    // The actual implementation is in music-player-store.ts
  },

  setHasUserInteracted: (v) => set({ hasUserInteracted: !!v }),
});
