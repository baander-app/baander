export interface ActivityEntry {
  uuid: string
  publicId: string
  userId: string
  activityType: string
  songId: string | null
  albumId: string | null
  artistId: string | null
  movieId: string | null
  playCount: number
  love: boolean
  lastPlayedAt: string | null
  lastPlatform: string | null
  lastPlayer: string | null
  createdAt: string
  /** Song title — available when API enriches the response */
  songTitle?: string | null
  /** Artist name — available when API enriches the response */
  artistName?: string | null
  /** Album name — available when API enriches the response */
  albumName?: string | null
}
