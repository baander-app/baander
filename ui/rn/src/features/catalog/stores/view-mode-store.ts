/**
 * View mode store -- platform-agnostic Zustand store.
 *
 * TV: simplified to grid/list (column, timeline, activity, discover are web-only).
 * Mobile/desktop: can extend with additional modes.
 */

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import AsyncStorage from '@react-native-async-storage/async-storage';

export type ViewMode = 'grid' | 'list';

export const VIEW_MODES: ViewMode[] = ['grid', 'list'];

export interface ViewModeState {
  viewMode: ViewMode;
  setViewMode: (mode: ViewMode) => void;
}

export const useViewModeStore = create<ViewModeState>()(
  persist(
    (set) => ({
      viewMode: 'grid',
      setViewMode: (mode) => set({ viewMode: mode }),
    }),
    {
      name: 'baander-view-mode',
      storage: createJSONStorage(() => AsyncStorage),
    },
  ),
);
