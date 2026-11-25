import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

/** The largest page of the station list; the admin page shows one page and the total. */
export const RADIO_ADMIN_STATION_LIMIT = 500

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
      params: { ...params, limit: RADIO_ADMIN_STATION_LIMIT },
    }),
  getCountries: (signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<{ data: { code: string; name: string }[] }>('/api/radio/countries', { signal }),
}
