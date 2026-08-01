/**
 * Catalog context action definitions.
 * Dispatched by catalog when the user triggers playback.
 */
export const CATALOG_ACTIONS = {
  PLAY_TRACK: 'catalog:play-track',
} as const

import type { Track } from '@/features/player/stores/player-store'

export interface CatalogPlayTrackPayload { track: Track; queue?: Track[] }
