import type { Track, RepeatMode } from './stores/player-store'

/**
 * Player context action definitions.
 * Other contexts dispatch these to coordinate with the player.
 */
export const PLAYER_ACTIONS = {
  PAUSE: 'player:pause',
  PLAY: 'player:play',
  QUEUE_ADD: 'player:queue-add',
  QUEUE_INSERT: 'player:queue-insert',
  STATE_RESTORE: 'player:state-restore',
} as const

export interface PlayerPausePayload { reason?: string }
export interface PlayerPlayPayload { track?: Track }
export interface PlayerQueueAddPayload { track: Track }
export interface PlayerQueueInsertPayload { tracks: Track[] }
export interface PlayerStateRestorePayload { queue: Track[]; currentIndex: number; currentTime: number }

/**
 * Settings action definitions targeting the player context.
 * Dispatched by settings, handled by player.
 */
export const SETTINGS_PLAYER_ACTIONS = {
  APPLY: 'settings:apply-player',
} as const

export interface SettingsApplyPlayerPayload {
  volume?: number
  shuffle?: boolean
  repeat?: RepeatMode
  crossfadeEnabled?: boolean
  crossfadeDuration?: number
}
