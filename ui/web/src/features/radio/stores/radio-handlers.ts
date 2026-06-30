import { mediator } from '@/shared/lib/mediator/bus'
import { useRadioStore } from './radio-store'
import { PLAYER_ACTIONS } from '@/features/player/player-actions'
import { CATALOG_ACTIONS } from '@/features/catalog/catalog-actions'

export function registerRadioHandlers() {
  // Radio reacts to player starting — stop itself
  mediator.on(PLAYER_ACTIONS.PLAY, function radioStopForPlayerHandler() {
    const state = useRadioStore.getState()
    if (state.isPlaying) {
      state.stopRadio()
    }
  })

  mediator.on(CATALOG_ACTIONS.PLAY_TRACK, function radioStopForCatalogHandler() {
    const state = useRadioStore.getState()
    if (state.isPlaying) {
      state.stopRadio()
    }
  })
}
