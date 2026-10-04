import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { createSelectiveJSONStorage } from '@/shared/stores/persistence'
import { withStoreDebug } from '@/shared/stores/debug'
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
  restorePreferences: (preferences: Pick<ContextPanelState, 'mode' | 'activeTab'>) => void
}

export const useContextPanelStore = create<ContextPanelState>()(
  withStoreDebug('layout.context-panel', persist(
    withNoopGuard((set) => ({
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
      setSelectedItem: (item) => set((state) => state.selectedItem?.type === item?.type && state.selectedItem?.publicId === item?.publicId && state.activeTab === 'details' && state.isOpen ? state : { selectedItem: item, activeTab: 'details', isOpen: true }),
      setOpen: (open) => set({ isOpen: open }),
      setWidth: (width) => set({ width }),
      restorePreferences: ({ mode, activeTab }) => set({ mode, activeTab }),
    })),
    {
      name: 'baander-context-panel',
      storage: createSelectiveJSONStorage(),
      version: 1,
      partialize: (state) => ({
        mode: state.mode,
        width: state.width,
      }),
    },
  )),
)
