import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export type ViewMode = 'grid' | 'list' | 'columns' | 'timeline' | 'activity' | 'discover'

export const VIEW_MODES: ViewMode[] = ['grid', 'list', 'columns', 'timeline', 'activity', 'discover']

export interface ViewModeState {
  viewMode: ViewMode
  setViewMode: (mode: ViewMode) => void
  /** Column browser split: top panel height in px (null = 50/50 default) */
  columnSplitPx: number | null
  setColumnSplitPx: (px: number | null) => void
}

export const useViewModeStore = create<ViewModeState>()(
  persist(
    (set) => ({
      viewMode: 'grid',
      columnSplitPx: null,

      setViewMode: (mode) => set({ viewMode: mode }),
      setColumnSplitPx: (px) => set({ columnSplitPx: px }),
    }),
    {
      name: 'baander-view-mode',
      version: 1,
    },
  ),
)
