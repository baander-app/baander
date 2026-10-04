import { useCallback } from 'react'
import { type ContextPanelState } from '@/features/layout/stores/context-panel-store'
import { mediator } from '@/shared/lib/mediator/bus'
import { SETTINGS_ACTIONS } from '@/features/settings/settings-actions'
import { usePreferenceSync } from './use-preference-sync'

type LayoutPreferences = Pick<ContextPanelState, 'mode' | 'activeTab'>

export function useLayoutPreferences(isActive?: () => boolean) {

  const sync = usePreferenceSync<LayoutPreferences>({
    isActive,
    baseUrl: '/api/user/layout-preferences/',
    toPayload: (state) => ({
      mode: state.mode,
      activeTab: state.activeTab,
    }),
    fromPayload: (payload) => {
      const { mode, activeTab } = payload
      if ((mode !== 'compact' && mode !== 'expanded')
        || (activeTab !== 'queue' && activeTab !== 'lyrics' && activeTab !== 'details' && activeTab !== 'info')) {
        throw new Error('Invalid layout preferences')
      }
      return { mode, activeTab }
    },
    onRemoteUpdate: useCallback((data) => {
      mediator.dispatch(SETTINGS_ACTIONS.APPLY_LAYOUT, {
        contextPanelMode: data.mode,
        activeTab: data.activeTab,
      }, 'settings')
    }, []),
  })

  return sync
}
