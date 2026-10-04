import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { withStoreDebug } from './debug'
import { createSelectiveJSONStorage } from './persistence'
import { withNoopGuard } from './with-noop-guard'

interface DevPanelState {
  visible: boolean
  setVisible: (visible: boolean) => void
  toggleVisible: () => void
}

export const useDevPanelStore = create<DevPanelState>()(
  withStoreDebug('developerPanel', persist(
    withNoopGuard<DevPanelState>((set) => ({
      visible: false,
      setVisible: (visible) => set(state => state.visible === visible ? state : { visible }),
      toggleVisible: () => set((s) => ({ visible: !s.visible })),
    })),
    {
      name: 'baander-dev-panel',
      version: 1,
      storage: createSelectiveJSONStorage(),
      partialize: state => ({ visible: state.visible }),
    },
  )),
)
