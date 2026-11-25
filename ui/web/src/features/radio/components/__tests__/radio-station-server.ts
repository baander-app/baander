import type MockAdapter from 'axios-mock-adapter'
import type { RadioStation } from '@/features/radio/api/radio-api'

export function station(publicId: string, name: string, country = 'DK'): RadioStation {
  return {
    publicId,
    sourceId: 'source000000000000001',
    externalId: publicId,
    name,
    country,
    language: null,
    genres: [],
    tags: [],
    streams: [],
    logo: null,
    website: null,
    lastCheckedAt: null,
    createdAt: '2026-11-25T10:00:00+00:00',
    updatedAt: '2026-11-25T10:00:00+00:00',
  }
}

/** 501 Danish stations, one more than the station list's largest page. */
export const DANISH_STATIONS: RadioStation[] = Array.from({ length: 501 }, (_, index) =>
  station(`dk${String(index).padStart(19, '0')}`, `Station ${String(index + 1).padStart(3, '0')}`),
)

/** Answers the station list as the server does: filtered, then one page of at most `limit`. */
export function serveStations(api: MockAdapter, stations: RadioStation[]): void {
  api.onGet('/api/radio/stations').reply((config) => {
    const params = config.params as { country?: string; publicIds?: string; limit: number; offset: number }
    const publicIds = params.publicIds?.split(',')
    const matching = stations.filter((candidate) =>
      (params.country === undefined || candidate.country === params.country)
      && (publicIds === undefined || publicIds.includes(candidate.publicId)),
    )

    return [200, {
      data: matching.slice(params.offset, params.offset + params.limit),
      meta: { total: matching.length, limit: params.limit, offset: params.offset },
    }]
  })
}
