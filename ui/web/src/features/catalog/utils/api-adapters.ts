import type { AlbumSummary, SongSummary, CoverImage, ArtistCredit } from '../types'
import type { ArtistSummary } from '../types'
import type { Genre } from '../types'
import type { MovieSummary, MovieDetail, MovieVideo } from '../types'

// ── Recent (activity) items ─────────────────────────────

export interface RecentItem {
  songTitle: string | null
  songPublicId: string | null
  albumPublicId: string | null
  albumTitle: string | null
  coverImage: CoverImage | null
  artistName: string | null
}

function asRecentItem(raw: unknown): RecentItem {
  const item = (raw ?? {}) as Record<string, unknown>
  return {
    songTitle: asStringOrNull(item.songTitle),
    songPublicId: asStringOrNull(item.songPublicId),
    albumPublicId: asStringOrNull(item.albumPublicId),
    albumTitle: asStringOrNull(item.albumTitle),
    coverImage: asCoverImage(item.coverImage),
    artistName: asStringOrNull(item.artistName),
  }
}

export function asRecentItems(data: unknown): RecentItem[] {
  if (!data || typeof data !== 'object') return []
  const response = data as Record<string, unknown>
  const items = Array.isArray(response?.data) ? response.data : []
  return items
    .filter((item: unknown) => {
      const i = (item ?? {}) as Record<string, unknown>
      return i.songTitle || i.albumTitle
    })
    .map(asRecentItem)
}

// ── Helpers ──────────────────────────────────────────────

function asString(val: unknown): string {
  return typeof val === 'string' ? val : ''
}

function asNumber(val: unknown): number | undefined {
  return typeof val === 'number' ? val : undefined
}

function asStringOrNull(val: unknown): string | null {
  return typeof val === 'string' ? val : null
}

function asStringArray(val: unknown): string[] {
  return Array.isArray(val) ? val.filter((v): v is string => typeof v === 'string') : []
}

// ── Cover Image ──────────────────────────────────────────

function asCoverImage(raw: unknown): CoverImage | null {
  if (!raw || typeof raw !== 'object') return null
  const img = raw as Record<string, unknown>
  return {
    url: asString(img.url),
    blurhash: asStringOrNull(img.blurhash),
  }
}

// ── Artists ──────────────────────────────────────────────

function asArtistCredits(raw: unknown): ArtistCredit[] {
  if (!Array.isArray(raw)) return []
  return raw.map((a: unknown) => {
    const artist = a as Record<string, unknown>
    return {
      name: asString(artist.name),
      role: asStringOrNull(artist.role),
    }
  })
}

// ── Albums ───────────────────────────────────────────────

function asAlbum(raw: unknown): AlbumSummary {
  const item = (raw ?? {}) as Record<string, unknown>
  return {
    uuid: asString(item.uuid),
    publicId: asString(item.publicId),
    title: asString(item.title) || 'Untitled',
    type: asString(item.type) || 'album',
    year: typeof item.year === 'number' ? item.year : null,
    label: asStringOrNull(item.label),
    barcode: asStringOrNull(item.barcode),
    country: asStringOrNull(item.country),
    catalogNumber: asStringOrNull(item.catalogNumber),
    language: asStringOrNull(item.language),
    disambiguation: asStringOrNull(item.disambiguation),
    annotation: asStringOrNull(item.annotation),
    mbid: asStringOrNull(item.mbid),
    discogsId: asStringOrNull(item.discogsId),
    spotifyId: asStringOrNull(item.spotifyId),
    createdAt: asString(item.createdAt),
    coverImage: asCoverImage(item.coverImage),
    artists: asArtistCredits(item.artists),
    lockedFields: asStringArray(item.lockedFields),
  }
}

export function asAlbums(data: unknown): AlbumSummary[] {
  if (!data || typeof data !== 'object') return []
  const response = data as Record<string, unknown>
  const items = Array.isArray(response?.data) ? response.data : []
  return items.map(asAlbum)
}

export function asAlbumsFromItems(items: unknown): AlbumSummary[] {
  if (!Array.isArray(items)) return []
  return items.map(asAlbum)
}

export function asAlbumFromData(data: unknown): AlbumSummary | null {
  if (!data || typeof data !== 'object') return null
  const response = data as Record<string, unknown>
  const item = response?.data ?? data
  return asAlbum(item)
}

// ── Songs ────────────────────────────────────────────────

function asSong(raw: unknown): SongSummary {
  const item = (raw ?? {}) as Record<string, unknown>
  return {
    uuid: asString(item.uuid),
    publicId: asString(item.publicId),
    albumId: asString(item.albumId),
    title: asString(item.title),
    path: asString(item.path),
    length: asNumber(item.length ?? item.duration) ?? null,
    track: asNumber(item.track) ?? null,
    disc: asNumber(item.disc) ?? null,
    bitrate: asNumber(item.bitrate) ?? null,
    explicit: item.explicit === true,
    year: typeof item.year === 'number' ? item.year : null,
    lyrics: asStringOrNull(item.lyrics),
    artistName: asStringOrNull(item.artistName),
    albumName: asStringOrNull(item.albumName),
    createdAt: asString(item.createdAt),
    comment: asStringOrNull(item.comment),
    mimeType: asStringOrNull(item.mimeType),
    mbid: asStringOrNull(item.mbid),
    discogsId: asStringOrNull(item.discogsId),
    spotifyId: asStringOrNull(item.spotifyId),
    lockedFields: asStringArray(item.lockedFields),
  }
}

export function asSongs(data: unknown): SongSummary[] {
  if (!data || typeof data !== 'object') return []
  const response = data as Record<string, unknown>
  const items = Array.isArray(response?.data) ? response.data : []
  return items.map(asSong)
}

export function asSongsFromItems(items: unknown): SongSummary[] {
  if (!Array.isArray(items)) return []
  return items.map(asSong)
}

export function asSongFromData(data: unknown): SongSummary | null {
  if (!data || typeof data !== 'object') return null
  const response = data as Record<string, unknown>
  const item = response?.data ?? data
  return asSong(item)
}

// ── Artists ──────────────────────────────────────────────

function asArtist(raw: unknown): ArtistSummary {
  const item = (raw ?? {}) as Record<string, unknown>
  return {
    uuid: asString(item.uuid),
    publicId: asString(item.publicId),
    name: asString(item.name),
    country: asStringOrNull(item.country),
    type: asStringOrNull(item.type),
    disambiguation: asStringOrNull(item.disambiguation),
    sortName: asStringOrNull(item.sortName),
    createdAt: asString(item.createdAt),
    gender: asStringOrNull(item.gender),
    biography: asStringOrNull(item.biography),
    mbid: asStringOrNull(item.mbid),
    discogsId: asStringOrNull(item.discogsId),
    spotifyId: asStringOrNull(item.spotifyId),
    coverImage: asCoverImage(item.coverImage),
    lockedFields: asStringArray(item.lockedFields),
  }
}

export function asArtists(data: unknown): ArtistSummary[] {
  if (!data || typeof data !== 'object') return []
  const response = data as Record<string, unknown>
  const items = Array.isArray(response?.data) ? response.data : []
  return items.map(asArtist)
}

export function asArtistFromData(data: unknown): ArtistSummary | null {
  if (!data || typeof data !== 'object') return null
  const response = data as Record<string, unknown>
  const item = response?.data ?? data
  return asArtist(item)
}

// ── Genres ───────────────────────────────────────────────

function asGenre(raw: unknown): Genre {
  const item = (raw ?? {}) as Record<string, unknown>
  return {
    uuid: asString(item.uuid),
    name: asString(item.name),
    slug: asString(item.slug),
    parentId: asStringOrNull(item.parentId),
    mbid: asString(item.mbid),
  }
}

export function asGenres(data: unknown): Genre[] {
  if (!data || typeof data !== 'object') return []
  const response = data as Record<string, unknown>
  const items = Array.isArray(response?.data) ? response.data : []
  return items.map(asGenre)
}

// ── Movies ───────────────────────────────────────────────

function asMovieVideo(raw: unknown): MovieVideo {
  const item = (raw ?? {}) as Record<string, unknown>
  return {
    uuid: asString(item.uuid),
    publicId: asStringOrNull(item.publicId) ?? undefined,
    width: asNumber(item.width) ?? undefined,
    height: asNumber(item.height) ?? undefined,
    duration: asNumber(item.duration) ?? undefined,
  }
}

function asMovieVideos(raw: unknown): MovieVideo[] {
  return Array.isArray(raw) ? raw.map(asMovieVideo) : []
}

function asMovie(raw: unknown): MovieSummary {
  const item = (raw ?? {}) as Record<string, unknown>
  return {
    publicId: asString(item.publicId),
    title: asString(item.title) || 'Untitled',
    year: typeof item.year === 'number' ? item.year : null,
    posterUrl: asStringOrNull(item.posterUrl),
    rating: asNumber(item.rating) ?? null,
    runtime: asNumber(item.runtime) ?? null,
    videos: asMovieVideos(item.videos),
  }
}

export function asMovies(data: unknown): MovieSummary[] {
  if (!data || typeof data !== 'object') return []
  const response = data as Record<string, unknown>
  const items = Array.isArray(response?.data) ? response.data : []
  return items.map(asMovie)
}

export function asMovieFromData(data: unknown): MovieDetail | null {
  if (!data || typeof data !== 'object') return null
  const response = data as Record<string, unknown>
  const item = (response?.data ?? data) as Record<string, unknown>
  return {
    ...asMovie(item),
    uuid: asStringOrNull(item.uuid) ?? undefined,
    tagline: asStringOrNull(item.tagline),
    backdropUrl: asStringOrNull(item.backdropUrl),
    overview: asStringOrNull(item.overview),
    originalLanguage: asStringOrNull(item.originalLanguage),
    tmdbId: asNumber(item.tmdbId) ?? null,
    imdbId: asStringOrNull(item.imdbId),
  }
}

// ── Paginated response helpers ───────────────────────────

export interface PaginatedMeta {
  currentPage: number
  lastPage: number
  perPage: number
  total: number
}

export function extractPaginatedMeta(data: unknown): PaginatedMeta {
  if (!data || typeof data !== 'object') {
    return { currentPage: 1, lastPage: 1, perPage: 0, total: 0 }
  }
  const response = data as Record<string, unknown>
  return {
    currentPage: typeof response.currentPage === 'number' ? response.currentPage : 1,
    lastPage: typeof response.lastPage === 'number' ? response.lastPage : 1,
    perPage: typeof response.perPage === 'number' ? response.perPage : 0,
    total: typeof response.total === 'number' ? response.total : 0,
  }
}
