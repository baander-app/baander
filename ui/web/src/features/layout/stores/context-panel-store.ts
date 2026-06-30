import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export type ContextPanelMode = 'compact' | 'expanded'
export type ContextPanelTab = 'queue' | 'lyrics' | 'details' | 'info'
export type SelectedItemType = 'album' | 'artist' | 'song' | 'genre' | 'playlist' | null

export interface ContextPanelState {
  mode: ContextPanelMode
  activeTab: ContextPanelTab
  selectedItem: {
    type: SelectedItemType
    publicId: string
  } | null
  isOpen: boolean
  width: number

  setMode: (mode: ContextPanelMode) => void
  toggleMode: () => void
  setActiveTab: (tab: ContextPanelTab) => void
  setSelectedItem: (item: { type: SelectedItemType; publicId: string } | null) => void
  setOpen: (open: boolean) => void
  setWidth: (width: number) => void
}

export const useContextPanelStore = create<ContextPanelState>()(
  persist(
    (set) => ({
      mode: 'expanded',
      activeTab: 'queue',
      selectedItem: null,
      isOpen: true,
      width: 360,

      setMode: (mode) => set({ mode }),
      toggleMode: () =>
        set((s) => ({
          mode: s.mode === 'compact' ? 'expanded' : 'compact',
        })),
      setActiveTab: (tab) => set({ activeTab: tab, isOpen: true }),
      setSelectedItem: (item) => set({ selectedItem: item, activeTab: 'details', isOpen: true }),
      setOpen: (open) => set({ isOpen: open }),
      setWidth: (width) => set({ width }),
    }),
    {
      name: 'baander-context-panel',
      version: 1,
      partialize: (state) => ({
        mode: state.mode,
        width: state.width,
      }),
    },
  ),
)
