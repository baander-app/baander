import { useState, useEffect, useMemo, useCallback, useRef } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { AlbumSummary } from '../types'

export interface TimelineYear {
  label: string
  albums: AlbumSummary[]
}

export interface TimelineDecade {
  label: string
  years: TimelineYear[]
}

export interface UseTimelineViewModelReturn {
  decades: TimelineDecade[]
  isLoading: boolean
  error: unknown
  refetch: () => void
}

const PER_PAGE = 100

function groupAlbumsByDecadeAndYear(albums: AlbumSummary[]): TimelineDecade[] {
  const decadeMap = new Map<string, Map<number, AlbumSummary[]>>()

  for (const album of albums) {
    const year = album.year
    if (year == null) continue

    const decadeStart = Math.floor(year / 10) * 10
    const decadeLabel = `${decadeStart}s`

    if (!decadeMap.has(decadeLabel)) {
      decadeMap.set(decadeLabel, new Map())
    }
    const yearMap = decadeMap.get(decadeLabel)!

    if (!yearMap.has(year)) {
      yearMap.set(year, [])
    }
    yearMap.get(year)!.push(album)
  }

  // Collect albums without year
  const unknown: AlbumSummary[] = []
  for (const album of albums) {
    if (album.year == null) {
      unknown.push(album)
    }
  }

  // Build decades, sorted descending
  const decades: TimelineDecade[] = []
  const sortedDecadeLabels = [...decadeMap.keys()].sort((a, b) => {
    const yearA = parseInt(a)
    const yearB = parseInt(b)
    return yearB - yearA
  })

  for (const decadeLabel of sortedDecadeLabels) {
    const yearMap = decadeMap.get(decadeLabel)!
    const years: TimelineYear[] = [...yearMap.entries()]
      .sort(([a], [b]) => b - a)
      .map(([year, albums]) => ({
        label: String(year),
        albums,
      }))

    decades.push({ label: decadeLabel, years })
  }

  // Unknown group at the bottom
  if (unknown.length > 0) {
    decades.push({
      label: 'Unknown',
      years: [{ label: 'Unknown', albums: unknown }],
    })
  }

  return decades
}

export function useTimelineViewModel(): UseTimelineViewModelReturn {
  const [albums, setAlbums] = useState<AlbumSummary[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<unknown>(null)
  const fetchIdRef = useRef(0)

  const refetch = useCallback(() => {
    void ++fetchIdRef.current
    let cancelled = false

    async function fetchAll() {
      setIsLoading(true)
      setError(null)
      try {
        let page = 1
        let results: AlbumSummary[] = []
        while (true) {
          const res = await AXIOS_INSTANCE.get('/api/albums', {
            params: { sort: 'year', order: 'asc', limit: PER_PAGE, page },
          })
          const body = res.data as Record<string, unknown>
          const items = (Array.isArray(body?.data) ? body.data : []) as Record<string, unknown>[]
          const lastPage = typeof body?.lastPage === 'number' ? body.lastPage : 1

          for (const raw of items) {
            const coverImage = raw.coverImage as Record<string, unknown> | null | undefined
            const artists = raw.artists as Array<Record<string, unknown>> | undefined
            results.push({
              uuid: (raw.uuid as string) ?? '',
              publicId: (raw.publicId as string) ?? '',
              title: (raw.title as string) ?? '',
              type: (raw.type as string) ?? '',
              year: typeof raw.year === 'number' ? raw.year : null,
              label: typeof raw.label === 'string' ? raw.label : null,
              barcode: typeof raw.barcode === 'string' ? raw.barcode : null,
              country: typeof raw.country === 'string' ? raw.country : null,
              catalogNumber: typeof raw.catalogNumber === 'string' ? raw.catalogNumber : null,
              language: typeof raw.language === 'string' ? raw.language : null,
              disambiguation: typeof raw.disambiguation === 'string' ? raw.disambiguation : null,
              annotation: typeof raw.annotation === 'string' ? raw.annotation : null,
              mbid: typeof raw.mbid === 'string' ? raw.mbid : null,
              discogsId: typeof raw.discogsId === 'string' ? raw.discogsId : null,
              spotifyId: typeof raw.spotifyId === 'string' ? raw.spotifyId : null,
              createdAt: (raw.createdAt as string) ?? '',
              coverImage: coverImage
                ? {
                    url: (coverImage.url as string) ?? '',
                    blurhash: typeof coverImage.blurhash === 'string' ? coverImage.blurhash : null,
                  }
                : null,
              artists: artists
                ? artists.map((a) => ({
                    name: (a.name as string) ?? '',
                    role: typeof a.role === 'string' ? a.role : null,
                  }))
                : [],
            })
          }

          if (items.length < PER_PAGE || page >= lastPage) break
          page++
        }

        if (!cancelled) {
          setAlbums(results)
        }
      } catch (err) {
        if (!cancelled) {
          setError(err)
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false)
        }
      }
    }

    fetchAll()
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    const cleanup = refetch()
    return cleanup
  }, [refetch])

  const decades = useMemo(() => groupAlbumsByDecadeAndYear(albums), [albums])

  return { decades, isLoading, error, refetch }
}
