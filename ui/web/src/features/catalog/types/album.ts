/** Shared song entry type used by catalog components and player store. */
export interface SongEntry {
  publicId: string
  title: string
  artistName?: string
  albumName?: string
  albumPublicId?: string
  duration?: number
  year?: number
}

export interface CoverImage {
  url: string
  blurhash: string | null
}

export interface ArtistCredit {
  name: string
  role: string | null
}

export interface AlbumSummary {
  uuid: string
  publicId: string
  title: string
  type: string
  year: number | null
  label: string | null
  barcode: string | null
  country: string | null
  catalogNumber: string | null
  language: string | null
  disambiguation: string | null
  annotation: string | null
  mbid: string | null
  discogsId: string | null
  spotifyId: string | null
  createdAt: string
  coverImage: CoverImage | null
  artists: ArtistCredit[]
  /** Fields locked from automatic updates (present on detail responses). */
  lockedFields?: string[]
}

export interface SongSummary {
  uuid: string
  publicId: string
  albumId: string
  title: string
  path: string
  length: number | null
  track: number | null
  disc: number | null
  bitrate: number | null
  explicit: boolean
  year: number | null
  lyrics: string | null
  artistName: string | null
  albumName: string | null
  createdAt: string
  /** Free-text comment (present on detail responses). */
  comment?: string | null
  /** File MIME type (present on detail responses). */
  mimeType?: string | null
  /** MusicBrainz ID (present on detail responses). */
  mbid?: string | null
  /** Discogs ID (present on detail responses). */
  discogsId?: string | null
  /** Spotify ID (present on detail responses). */
  spotifyId?: string | null
  /** Fields locked from automatic updates (present on detail responses). */
  lockedFields?: string[]
}

export interface AlbumDetail extends AlbumSummary {
  songs: SongSummary[]
}
