import { mediator } from '@/shared/lib/mediator/bus'
import { useContextPanelStore } from './context-panel-store'
import { SETTINGS_ACTIONS } from '@/features/settings/settings-actions'
import type { SettingsApplyLayoutPayload } from '@/features/settings/settings-actions'

export function registerContextPanelHandlers() {
  mediator.on(SETTINGS_ACTIONS.APPLY_LAYOUT, function contextPanelApplyLayoutHandler(payload: unknown) {
    const p = payload as SettingsApplyLayoutPayload
    useContextPanelStore.setState({ mode: p.contextPanelMode })
  })
}
