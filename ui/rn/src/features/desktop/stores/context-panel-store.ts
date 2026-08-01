import { create } from 'zustand';

export type ContextPanelMode = 'collapsed' | 'compact' | 'expanded';
export type ContextPanelTab = 'queue' | 'lyrics' | 'details';

interface ContextPanelState {
  mode: ContextPanelMode;
  activeTab: ContextPanelTab;
  setMode: (mode: ContextPanelMode) => void;
  setActiveTab: (tab: ContextPanelTab) => void;
  toggleMode: () => void;
}

export const useContextPanelStore = create<ContextPanelState>()((set) => ({
  mode: 'collapsed',
  activeTab: 'queue',
  setMode: (mode) => set({ mode }),
  setActiveTab: (tab) => set({ activeTab: tab }),
  toggleMode: () =>
    set((s) => ({
      mode: s.mode === 'collapsed' ? 'compact' : s.mode === 'compact' ? 'expanded' : 'collapsed',
    })),
}));
