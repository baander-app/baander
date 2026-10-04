import { create, type StateCreator } from 'zustand'
import { persist } from 'zustand/middleware'
import { createSelectiveJSONStorage } from '@/shared/stores/persistence'
import { withStoreDebug } from '@/shared/stores/debug'
import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { createPlayerQueueSlice } from './player-queue-slice'
import { createPlayerPlaybackSlice } from './player-playback-slice'
import { createPlayerPreferencesSlice, normalizePlayerPreferences } from './player-preferences-slice'
import { generateShuffleBag } from './player-queue-navigation'
import type { PlayerState, PersistedPlayerState } from './player-types'

export type { Track, RepeatMode, PlayerState, PlayerPreferences, PersistedPlayerState } from './player-types'
export { buildStreamUrl, syncPlaybackVolume, getPlaybackSelectionGeneration } from './player-playback-runtime'
export { generateShuffleBag, resolveNextIndex } from './player-queue-navigation'

const createPlayerState: StateCreator<PlayerState> = (set, get) => {
  const commit = (patch: Partial<PlayerState>) => set(patch)
  const context = { get, commit }
  return { ...createPlayerQueueSlice(context), ...createPlayerPlaybackSlice(context), ...createPlayerPreferencesSlice(context) }
}

function persistedPlayerState(state: PlayerState): PersistedPlayerState {
  return {
    queue: state.queue, currentIndex: state.currentIndex, shuffle: state.shuffle, repeat: state.repeat,
    volume: state.volume, muted: state.muted, crossfadeEnabled: state.crossfadeEnabled, crossfadeDuration: state.crossfadeDuration,
  }
}

export const usePlayerStore = create<PlayerState>()(withStoreDebug('player', persist(withNoopGuard(createPlayerState), {
  name: 'baander-player', version: 1,
  storage: createSelectiveJSONStorage<PersistedPlayerState>(),
  partialize: persistedPlayerState,
  merge: (persisted, current) => {
    const saved = persisted as Partial<PersistedPlayerState> | undefined
    const queue = Array.isArray(saved?.queue) ? saved.queue : current.queue
    const index = saved?.currentIndex
    const currentIndex = queue.length === 0 ? -1 : index !== undefined && Number.isInteger(index)
      ? Math.max(0, Math.min(queue.length - 1, index)) : 0
    const preferences = normalizePlayerPreferences(saved ?? {}, current)
    return { ...current, ...preferences, queue, currentIndex, currentTrack: queue[currentIndex] ?? null,
      shuffleBag: preferences.shuffle ? generateShuffleBag(queue.length, currentIndex) : [] }
  },
})))

/** Read state outside React; mutate it through the named transition actions. */
export function getPlayerSnapshot(): PlayerState {
  return usePlayerStore.getState()
}
