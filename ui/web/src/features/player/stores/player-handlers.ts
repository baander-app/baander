import { mediator } from '@/shared/lib/mediator/bus'
import { usePlayerStore } from './player-store'
import { updateTime } from './player-time-tracker'
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
    const currentTrack = p.queue[p.currentIndex] ?? null
    usePlayerStore.setState({
      queue: p.queue,
      currentIndex: p.currentIndex,
      currentTrack,
    })
    updateTime(p.currentTime)
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
    const updates: Partial<{ volume: number; shuffle: boolean; repeat: string; crossfadeEnabled: boolean; crossfadeDuration: number }> = {}
    if (p.volume !== undefined) updates.volume = p.volume
    if (p.shuffle !== undefined) updates.shuffle = p.shuffle
    if (p.repeat !== undefined) updates.repeat = p.repeat
    if (p.crossfadeEnabled !== undefined) updates.crossfadeEnabled = p.crossfadeEnabled
    if (p.crossfadeDuration !== undefined) updates.crossfadeDuration = p.crossfadeDuration
    if (Object.keys(updates).length > 0) {
      usePlayerStore.setState(updates as any)
    }
  })
}
