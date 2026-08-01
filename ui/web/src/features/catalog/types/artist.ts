import type { CoverImage } from './album'

export interface ArtistSummary {
  uuid: string
  publicId: string
  name: string
  country: string | null
  type: string | null
  disambiguation: string | null
  sortName: string | null
  createdAt: string
  /** Gender (present on detail responses). */
  gender?: string | null
  /** Biography (present on detail responses). */
  biography?: string | null
  /** MusicBrainz ID (present on detail responses). */
  mbid?: string | null
  /** Discogs ID (present on detail responses). */
  discogsId?: string | null
  /** Spotify ID (present on detail responses). */
  spotifyId?: string | null
  /** Cover image (present on detail responses). */
  coverImage?: CoverImage | null
  /** Fields locked from automatic updates (present on detail responses). */
  lockedFields?: string[]
}
