import type { PlayerPreferences, PlayerPreferenceActions, PlayerSliceContext } from './player-types'
import { generateShuffleBag } from './player-queue-navigation'
import { syncPlaybackVolume } from './player-playback-runtime'

export const initialPlayerPreferences: PlayerPreferences = {
  shuffle: false, repeat: 'off', crossfadeEnabled: false, crossfadeDuration: 5, volume: 75, muted: false,
}

export function normalizePlayerPreferences(input: Partial<PlayerPreferences>, current: PlayerPreferences): PlayerPreferences {
  return {
    shuffle: typeof input.shuffle === 'boolean' ? input.shuffle : current.shuffle,
    repeat: input.repeat === 'off' || input.repeat === 'all' || input.repeat === 'one' ? input.repeat : current.repeat,
    crossfadeEnabled: typeof input.crossfadeEnabled === 'boolean' ? input.crossfadeEnabled : current.crossfadeEnabled,
    crossfadeDuration: input.crossfadeDuration !== undefined && Number.isFinite(input.crossfadeDuration)
      ? Math.max(0, Math.min(12, input.crossfadeDuration)) : current.crossfadeDuration,
    volume: input.volume !== undefined && Number.isFinite(input.volume)
      ? Math.max(0, Math.min(100, Math.round(input.volume))) : current.volume,
    muted: typeof input.muted === 'boolean' ? input.muted : current.muted,
  }
}

export function createPlayerPreferencesSlice({ get, commit }: PlayerSliceContext): PlayerPreferences & PlayerPreferenceActions {
  const applyPreferences = (input: Partial<PlayerPreferences>) => {
    const current = get()
    const next = normalizePlayerPreferences(input, current)
    const changed = (Object.keys(next) as (keyof PlayerPreferences)[]).some(key => next[key] !== current[key])
    if (!changed) return
    const shuffleBag = next.shuffle !== current.shuffle
      ? next.shuffle ? generateShuffleBag(current.queue.length, current.currentIndex) : []
      : current.shuffleBag
    commit({ ...next, shuffleBag })
    if (next.volume !== current.volume || next.muted !== current.muted) {
      const element = get().audioElement
      syncPlaybackVolume(element ? [element] : [], next.volume, next.muted)
    }
  }
  return {
    ...initialPlayerPreferences,
    applyPreferences,
    setVolume: volume => applyPreferences({ volume }),
    setMuted: muted => applyPreferences({ muted }),
    toggleMute: () => applyPreferences({ muted: !get().muted }),
    setShuffle: shuffle => applyPreferences({ shuffle }),
    setRepeat: repeat => applyPreferences({ repeat }),
    setCrossfadeEnabled: crossfadeEnabled => applyPreferences({ crossfadeEnabled }),
    setCrossfadeDuration: crossfadeDuration => applyPreferences({ crossfadeDuration }),
    toggleShuffle: () => {
      const state = get()
      applyPreferences({ shuffle: !state.shuffle, repeat: state.shuffle ? state.repeat : 'off' })
    },
    toggleRepeat: () => {
      const state = get()
      const repeat = state.repeat === 'off' ? 'all' : state.repeat === 'all' ? 'one' : 'off'
      applyPreferences({ repeat, shuffle: repeat === 'off' ? state.shuffle : false })
    },
  }
}
