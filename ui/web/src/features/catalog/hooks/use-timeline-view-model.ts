import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { assertCurrentOwnership, captureOwnership } from '@/shared/live/ownership'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { AlbumSummary } from '../types'
import { extractPaginatedMeta } from '../utils/api-adapters'

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
  const query = useQuery({
    queryKey: ['catalog', 'timeline'],
    queryFn: async ({ signal }) => {
      const owner = captureOwnership()
      let page = 1
      const results: AlbumSummary[] = []
      while (true) {
        signal.throwIfAborted()
        assertCurrentOwnership(owner)
        const res = await AXIOS_INSTANCE.get('/api/albums', {
          signal,
          params: { sort: 'year', order: 'asc', limit: PER_PAGE, page },
        })
        signal.throwIfAborted()
        assertCurrentOwnership(owner)
        const body = res.data as Record<string, unknown>
        const items = (Array.isArray(body?.data) ? body.data : []) as Record<string, unknown>[]
        const lastPage = extractPaginatedMeta(body).lastPage

        for (const raw of items) {
          const coverImage = raw.coverImage as Record<string, unknown> | null | undefined
          const artists = raw.artists as Array<Record<string, unknown>> | undefined
          results.push({
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

        if (page >= lastPage) break
        page++
      }

      return results
    },
  })
  const decades = useMemo(() => groupAlbumsByDecadeAndYear(query.data ?? []), [query.data])
  const refetch = () => {
    query.refetch({ cancelRefetch: false }).catch(() => { /* Query exposes read failures. */ })
  }
  return { decades, isLoading: query.isLoading, error: query.error, refetch }
}
