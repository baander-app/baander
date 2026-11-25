import { STATION_PAGE_LIMIT } from '@/features/radio/api/radio-api'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface RadioStation {
  publicId: string
  sourceId: string
  externalId: string
  name: string
  country: string
  language: string | null
  genres: string[]
  tags: string[]
  streams: {
    url: string
    format: string
    bitrate: number
    reliability: number
  }[]
  logo: string | null
  website: string | null
  lastCheckedAt: string | null
  createdAt: string
  updatedAt: string
}

export interface RadioStationPage {
  data: RadioStation[]
  meta: {
    total: number
    limit: number
    offset: number
  }
}

export const radioAdminApi = {
  getStations: (params?: { country?: string; q?: string }, signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<RadioStationPage>('/api/radio/stations', {
      signal,
      // The admin page shows one page and the total.
      params: { ...params, limit: STATION_PAGE_LIMIT },
    }),
  getCountries: (signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<{ data: { code: string; name: string }[] }>('/api/radio/countries', { signal }),
}
