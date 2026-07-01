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
}

export interface AlbumDetail extends AlbumSummary {
  songs: SongSummary[]
}
