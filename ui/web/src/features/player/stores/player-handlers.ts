import { mediator } from '@/shared/lib/mediator/bus'
import { usePlayerStore } from './player-store'
import { PLAYER_ACTIONS, SETTINGS_PLAYER_ACTIONS } from '../player-actions'
import type {
  PlayerPlayPayload,
  PlayerQueueAddPayload,
  PlayerQueueInsertPayload,
  PlayerStateRestorePayload,
  SettingsApplyPlayerPayload,
} from '../player-actions'
import { RADIO_ACTIONS } from '@/features/radio/radio-actions'
import { CATALOG_ACTIONS } from '@/features/catalog/catalog-actions'
import type { CatalogPlayTrackPayload } from '@/features/catalog/catalog-actions'

function pausePlayerIfPlaying() {
  const state = usePlayerStore.getState()
  if (state.isPlaying) {
    state.setIsPlaying(false)
  }
}

export function registerPlayerHandlers() {
  mediator.on(PLAYER_ACTIONS.PAUSE, function playerPauseHandler() {
    pausePlayerIfPlaying()
  })

  mediator.on(PLAYER_ACTIONS.PLAY, function playerPlayHandler(payload: unknown) {
    const p = payload as PlayerPlayPayload
    if (p.track) {
      usePlayerStore.getState().playTrack(p.track)
    }
  })

  mediator.on(PLAYER_ACTIONS.QUEUE_ADD, function playerQueueAddHandler(payload: unknown) {
    const p = payload as PlayerQueueAddPayload
    usePlayerStore.getState().addToQueue(p.track)
  })

  mediator.on(PLAYER_ACTIONS.QUEUE_INSERT, function playerQueueInsertHandler(payload: unknown) {
    const p = payload as PlayerQueueInsertPayload
    usePlayerStore.getState().insertAfterCurrent(p.tracks)
  })

  mediator.on(PLAYER_ACTIONS.STATE_RESTORE, function playerStateRestoreHandler(payload: unknown) {
    const p = payload as PlayerStateRestorePayload
    usePlayerStore.getState().restoreQueue(p.queue, p.currentIndex, p.currentTime)
  })

  mediator.on(RADIO_ACTIONS.STARTED, function playerPauseForRadioHandler() {
    pausePlayerIfPlaying()
  })

  mediator.on(CATALOG_ACTIONS.PLAY_TRACK, function playerCatalogPlayTrackHandler(payload: unknown) {
    const p = payload as CatalogPlayTrackPayload
    usePlayerStore.getState().playTrack(p.track, p.queue)
  })

  mediator.on(SETTINGS_PLAYER_ACTIONS.APPLY, function playerApplySettingsHandler(payload: unknown) {
    const p = payload as SettingsApplyPlayerPayload
    usePlayerStore.getState().applyPreferences(p)
  })
}
