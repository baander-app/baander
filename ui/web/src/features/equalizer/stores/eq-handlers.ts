import { mediator } from '@/shared/lib/mediator/bus'
import { useEqBandsStore } from './eq-bands-store'
import { useEqProcessingStore } from './eq-processing-store'
import { SETTINGS_ACTIONS } from '@/features/settings/settings-actions'
import { validateAudioPreferencePayload } from '@/features/settings/audio-preference-payload'
import { reapplyAllEqState } from './eq-reapply'

export function registerEqHandlers() {
  mediator.on(SETTINGS_ACTIONS.APPLY_EQ, function eqApplySettingsHandler(payload: unknown) {
    const { enabled, preset, visualizerMode, bands, ...processing } = validateAudioPreferencePayload(payload)
    useEqBandsStore.setState({ enabled, preset, visualizerMode, bands })
    useEqProcessingStore.setState(processing)
    reapplyAllEqState()
  })
}
