import { useCallback } from 'react'
import { type PlayerState } from '@/features/player/stores/player-store'
import { mediator } from '@/shared/lib/mediator/bus'
import { SETTINGS_ACTIONS } from '@/features/settings/settings-actions'
import { usePreferenceSync } from './use-preference-sync'

const VOLUME_SCALE = 100

export function usePlayerPreferences() {

  const sync = usePreferenceSync<PlayerState>({
    baseUrl: '/api/user/player-preferences/',
    toPayload: (state) => ({
      shuffle: state.shuffle,
      repeat: state.repeat,
      volume: state.volume / VOLUME_SCALE,
      muted: state.muted,
      crossfadeEnabled: state.crossfadeEnabled,
      crossfadeDuration: state.crossfadeDuration,
      replayGainEnabled: false,
      replayGainMode: 'track',
      replayGainPreAmp: 0.0,
    }),
    fromPayload: (payload) => ({
      shuffle: payload.shuffle as boolean,
      repeat: payload.repeat as 'off' | 'all' | 'one',
      volume: Math.round((payload.volume as number) * VOLUME_SCALE),
      muted: payload.muted as boolean,
      crossfadeEnabled: payload.crossfadeEnabled as boolean,
      crossfadeDuration: payload.crossfadeDuration as number,
    }) as unknown as PlayerState,
    onRemoteUpdate: useCallback((data) => {
      mediator.dispatch(SETTINGS_ACTIONS.APPLY_PLAYER, {
        shuffle: data.shuffle,
        repeat: data.repeat,
        volume: data.volume,
        crossfadeEnabled: data.crossfadeEnabled,
        crossfadeDuration: data.crossfadeDuration,
      }, 'settings')
    }, []),
  })

  return sync
}
