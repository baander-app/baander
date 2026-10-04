import { useCallback } from 'react'
import { type PlayerState } from '@/features/player/stores/player-store'
import { mediator } from '@/shared/lib/mediator/bus'
import { SETTINGS_ACTIONS } from '@/features/settings/settings-actions'
import type { SettingsApplyPlayerPayload } from '@/features/player/player-actions'
import { usePreferenceSync } from './use-preference-sync'

const VOLUME_SCALE = 100
type PlayerPreferenceState = Pick<PlayerState,
  'shuffle' | 'repeat' | 'volume' | 'muted' | 'crossfadeEnabled' | 'crossfadeDuration'
>

function isNumberInRange(value: unknown, minimum: number, maximum: number): value is number {
  return typeof value === 'number' && Number.isFinite(value) && value >= minimum && value <= maximum
}

function readPlayerPreferences(payload: Record<string, unknown>): PlayerPreferenceState {
  const { shuffle, repeat, volume, muted, crossfadeEnabled, crossfadeDuration } = payload
  if (
    typeof shuffle !== 'boolean' || typeof muted !== 'boolean' || typeof crossfadeEnabled !== 'boolean'
    || (repeat !== 'off' && repeat !== 'all' && repeat !== 'one')
    || !isNumberInRange(volume, 0, 1) || !isNumberInRange(crossfadeDuration, 0, 12)
  ) {
    throw new Error('Invalid player preference payload')
  }
  return {
    shuffle, repeat, volume: Math.round(volume * VOLUME_SCALE), muted, crossfadeEnabled, crossfadeDuration,
  }
}

export function usePlayerPreferences(isActive?: () => boolean) {

  const sync = usePreferenceSync<PlayerPreferenceState>({
    isActive,
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
    fromPayload: readPlayerPreferences,
    onRemoteUpdate: useCallback((data) => {
      mediator.dispatch(SETTINGS_ACTIONS.APPLY_PLAYER, {
        shuffle: data.shuffle,
        repeat: data.repeat,
        volume: data.volume,
        muted: data.muted,
        crossfadeEnabled: data.crossfadeEnabled,
        crossfadeDuration: data.crossfadeDuration,
      } satisfies SettingsApplyPlayerPayload, 'settings')
    }, []),
  })

  return sync
}
