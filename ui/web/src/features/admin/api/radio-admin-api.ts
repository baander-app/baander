import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface RadioStation {
  id: string
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

export interface RadioSource {
  id: string
  name: string
  type: string
  url: string
  isActive: boolean
}

export const radioAdminApi = {
  getStations: (params?: { country?: string; q?: string }) =>
    AXIOS_INSTANCE.get<{ data: RadioStation[] }>('/api/radio/stations', { params }),
  getCountries: () =>
    AXIOS_INSTANCE.get<{ data: { code: string; name: string }[] }>('/api/radio/countries'),
  getSources: () =>
    AXIOS_INSTANCE.get<{ data: RadioSource[] }>('/api/radio/sources'),
}
