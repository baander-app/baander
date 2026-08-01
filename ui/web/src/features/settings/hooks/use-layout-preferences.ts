import { useCallback } from 'react'
import { type ContextPanelState } from '@/features/layout/stores/context-panel-store'
import { mediator } from '@/shared/lib/mediator/bus'
import { SETTINGS_ACTIONS } from '@/features/settings/settings-actions'
import { usePreferenceSync } from './use-preference-sync'

export function useLayoutPreferences() {

  const sync = usePreferenceSync<ContextPanelState>({
    baseUrl: '/api/user/layout-preferences/',
    toPayload: (state) => ({
      mode: state.mode,
      activeTab: state.activeTab,
    }),
    fromPayload: (payload) => payload as unknown as ContextPanelState,
    onRemoteUpdate: useCallback((data) => {
      mediator.dispatch(SETTINGS_ACTIONS.APPLY_LAYOUT, {
        contextPanelMode: data.mode,
      }, 'settings')
    }, []),
  })

  return sync
}
