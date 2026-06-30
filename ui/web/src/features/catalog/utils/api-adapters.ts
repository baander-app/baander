import type { AlbumSummary, SongSummary, CoverImage, ArtistCredit } from '../types'
import type { ArtistSummary } from '../types'
import type { Genre } from '../types'

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
  }
}

export function asArtists(data: unknown): ArtistSummary[] {
  if (!data || typeof data !== 'object') return []
  const response = data as Record<string, unknown>
  const items = Array.isArray(response?.data) ? response.data : []
  return items.map(asArtist)
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
