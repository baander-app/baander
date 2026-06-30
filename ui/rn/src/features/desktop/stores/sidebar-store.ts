import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import AsyncStorage from '@react-native-async-storage/async-storage';

interface SidebarState {
  isCollapsed: boolean;
  editorOpen: boolean;
  setCollapsed: (collapsed: boolean) => void;
  toggleCollapsed: () => void;
  setEditorOpen: (open: boolean) => void;
}

export const useSidebarStore = create<SidebarState>()(
  persist(
    (set) => ({
      isCollapsed: false,
      editorOpen: false,
      setCollapsed: (collapsed) => set({ isCollapsed: collapsed }),
      toggleCollapsed: () => set((s) => ({ isCollapsed: !s.isCollapsed })),
      setEditorOpen: (open) => set({ editorOpen: open }),
    }),
    {
      name: 'baander-sidebar',
      storage: createJSONStorage(() => AsyncStorage),
      partialize: (state) => ({ isCollapsed: state.isCollapsed }),
    },
  ),
);
