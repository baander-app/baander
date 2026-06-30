import { useState, useCallback, useMemo } from 'react'
import {
  useGetGenreIndex,
  useGetArtistIndex,
  useGetAlbumIndex,
  useGetSongIndex,
} from '@/shared/api-client/gen/endpoints'
import type { BrowserItem } from '../components/BrowserColumn'
import type { SongEntry } from '../types'
import { asAlbumsFromItems, asGenres } from '../utils/api-adapters'
import type { AlbumSummary } from '../types'

export type ColumnFocus = 'genre' | 'artist' | 'album' | 'song'

export interface UseColumnBrowserReturn {
  genres: BrowserItem[]
  artists: BrowserItem[]
  albums: BrowserItem[]
  songs: SongEntry[]
  selectedGenre: string | null
  selectedArtist: string | null
  selectedAlbum: string | null
  setSelectedGenre: (id: string | null) => void
  setSelectedArtist: (id: string | null) => void
  setSelectedAlbum: (id: string | null) => void
  genresLoading: boolean
  artistsLoading: boolean
  albumsLoading: boolean
  songsLoading: boolean
  focusedColumn: ColumnFocus
  setFocusedColumn: (col: ColumnFocus) => void
}

export function useColumnBrowser(): UseColumnBrowserReturn {
  const [selectedGenre, setSelectedGenreRaw] = useState<string | null>(null)
  const [selectedArtist, setSelectedArtistRaw] = useState<string | null>(null)
  const [selectedAlbum, setSelectedAlbumRaw] = useState<string | null>(null)
  const [focusedColumn, setFocusedColumn] = useState<ColumnFocus>('genre')

  // ── Genres ──────────────────────────────────────────────
  const { data: genreData, isLoading: genresLoading } = useGetGenreIndex()

  const genreItems: BrowserItem[] = useMemo(() => {
    const genres = asGenres(genreData)
    return genres
      .map((g) => ({
        id: g.uuid || g.slug || g.name,
        label: g.name,
      }))
      .sort((a, b) => a.label.localeCompare(b.label))
  }, [genreData])

  // Build genre slug lookup for API filtering
  const genreSlugLookup = useMemo(() => {
    const genres = asGenres(genreData)
    const map = new Map<string, string>()
    for (const g of genres) {
      const id = g.uuid || g.slug || g.name
      map.set(id, g.slug || g.name)
    }
    return map
  }, [genreData])

  const genreSlug = selectedGenre ? genreSlugLookup.get(selectedGenre) : undefined

  // ── Artists (filtered by genre) ─────────────────────────
  const { data: artistData, isLoading: artistsLoading } = useGetArtistIndex({
    limit: 100,
    ...(genreSlug ? { genre: genreSlug } : {}),
  })

  const artistItems: BrowserItem[] = useMemo(() => {
    const response = artistData as Record<string, unknown> | undefined
    const raw = Array.isArray(response?.data)
      ? (response?.data as Record<string, unknown>[])
      : Array.isArray(response)
        ? [response as unknown] as Record<string, unknown>[]
        : []
    return raw
      .map((a) => ({
        id: String(a.publicId ?? ''),
        label: String(a.name ?? ''),
      }))
      .filter((a) => a.id && a.label)
      .sort((a, b) => a.label.localeCompare(b.label))
  }, [artistData])

  // ── Albums (filtered by artist) ─────────────────────────
  const { data: albumData, isLoading: albumsLoading } = useGetAlbumIndex({
    limit: 100,
    ...(selectedArtist ? { artistId: selectedArtist } : {}),
  })

  const allAlbums: AlbumSummary[] = useMemo(() => {
    const response = albumData as Record<string, unknown> | undefined
    const items = Array.isArray(response?.data)
      ? response?.data
      : Array.isArray(response)
        ? [response as unknown]
        : []
    return asAlbumsFromItems(items)
  }, [albumData])

  const albumItems: BrowserItem[] = useMemo(() => {
    return allAlbums
      .map((a) => ({
        id: a.publicId,
        label: a.title,
        sublabel: a.artists.map((ar) => ar.name).join(', ') || (a.year != null ? String(a.year) : undefined),
        coverUrl: a.coverImage?.url ?? undefined,
        blurhash: a.coverImage?.blurhash ?? undefined,
      }))
      .sort((a, b) => a.label.localeCompare(b.label))
  }, [allAlbums])

  // Album lookup: publicId → album metadata
  const albumLookup = useMemo(() => {
    const map = new Map<string, { publicId: string; title: string; artistName: string }>()
    for (const a of allAlbums) {
      map.set(a.publicId, {
        publicId: a.publicId,
        title: a.title,
        artistName: a.artists.map((ar) => ar.name).join(', '),
      })
    }
    return map
  }, [allAlbums])

  // ── Songs (filtered by album) ───────────────────────────
  const { data: songsData, isLoading: songsLoading } = useGetSongIndex({
    limit: 100,
    ...(selectedAlbum ? { albumId: selectedAlbum } : {}),
    ...(genreSlug ? { genres: genreSlug } : {}),
  })

  const songs: SongEntry[] = useMemo(() => {
    const response = songsData as Record<string, unknown> | undefined
    const raw = Array.isArray(response?.data) ? (response.data as Record<string, unknown>[]) : []
    return raw.map((s) => {
      const albumInfo = albumLookup.get(String(s.albumId ?? ''))
      return {
        publicId: String(s.publicId ?? ''),
        title: String(s.title ?? ''),
        artistName: String(s.artistName ?? albumInfo?.artistName ?? ''),
        albumName: String(s.albumName ?? albumInfo?.title ?? ''),
        albumPublicId: String(s.albumId ?? albumInfo?.publicId ?? ''),
        duration: typeof (s.length ?? s.duration) === 'number' ? (s.length ?? s.duration) as number : undefined,
        year: typeof s.year === 'number' ? s.year : undefined,
      }
    })
  }, [songsData, albumLookup])

  // ── Cascading selection handlers ────────────────────────
  const setSelectedGenre = useCallback((id: string | null) => {
    setSelectedGenreRaw(id)
    setSelectedArtistRaw(null)
    setSelectedAlbumRaw(null)
  }, [])

  const setSelectedArtist = useCallback((id: string | null) => {
    setSelectedArtistRaw(id)
    setSelectedAlbumRaw(null)
  }, [])

  const setSelectedAlbum = useCallback((id: string | null) => {
    setSelectedAlbumRaw(id)
  }, [])

  return {
    genres: genreItems,
    artists: artistItems,
    albums: albumItems,
    songs,
    selectedGenre,
    selectedArtist,
    selectedAlbum,
    setSelectedGenre,
    setSelectedArtist,
    setSelectedAlbum,
    genresLoading,
    artistsLoading,
    albumsLoading,
    songsLoading,
    focusedColumn,
    setFocusedColumn,
  }
}
