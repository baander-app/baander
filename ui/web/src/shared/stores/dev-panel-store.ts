import { create } from 'zustand'
import { persist } from 'zustand/middleware'

interface DevPanelState {
  visible: boolean
  setVisible: (visible: boolean) => void
  toggleVisible: () => void
}

export const useDevPanelStore = create<DevPanelState>()(
  persist(
    (set) => ({
      visible: false,
      setVisible: (visible) => set({ visible }),
      toggleVisible: () => set((s) => ({ visible: !s.visible })),
    }),
    {
      name: 'baander-dev-panel',
      version: 1,
    },
  ),
)
