import { apiPath } from '@/shared/api-client/api-path'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { readAllPages, type OffsetPage } from '@/shared/utils/read-all-pages'

/** The largest page of the station list. */
const STATION_PAGE_LIMIT = 500

/** The most stations one station-list request names by public ID. */
const STATION_IDS_PER_REQUEST = 100

// --- Types ---

export interface CountryInfo {
  code: string
  name: string
  station_count: number
}

export interface StreamInfo {
  url: string
  format: string
  bitrate: number
  reliability: number
}

export interface RadioStation {
  publicId: string
  sourceId: string
  externalId: string
  name: string
  country: string
  language: string | null
  genres: string[]
  tags: string[]
  streams: StreamInfo[]
  logo: string | null
  website: string | null
  lastCheckedAt: string | null
  createdAt: string
  updatedAt: string
}

export interface CountrySubscription {
  publicId: string
  userId: string
  sourceId: string
  countryCode: string
  lastSyncedAt: string | null
  createdAt: string
}

export interface StarredStation {
  userId: string
  stationId: string
  starredAt: string
}

export interface RadioSession {
  userId: string
  state: 'playing' | 'stopped'
  activeStationId: string | null
  activeStreamUrl: string | null
  createdAt: string
  updatedAt: string
}

// --- Countries ---

export async function getAvailableCountries(signal?: AbortSignal): Promise<CountryInfo[]> {
  const { data } = await AXIOS_INSTANCE.get('/api/radio/countries', { signal })
  return data.data ?? []
}

// --- Subscriptions ---

export async function getSubscriptions(signal?: AbortSignal): Promise<CountrySubscription[]> {
  const { data } = await AXIOS_INSTANCE.get('/api/radio/subscriptions', { signal })
  return data.data ?? []
}

export async function subscribeCountry(sourceId: string | null, countryCode: string): Promise<CountrySubscription> {
  const { data } = await AXIOS_INSTANCE.post('/api/radio/subscriptions', {
    ...(sourceId ? { sourceId } : {}),
    countryCode,
  })
  return data.data
}

export async function unsubscribeCountry(sourceId: string, countryCode: string): Promise<void> {
  await AXIOS_INSTANCE.delete(apiPath`/api/radio/subscriptions/${countryCode}`, { data: { sourceId } })
}

export async function refreshCountry(countryCode: string): Promise<{ synced: number }> {
  const { data } = await AXIOS_INSTANCE.post(apiPath`/api/radio/subscriptions/${countryCode}/refresh`)
  return data.data
}

// --- Stations ---

interface StationFilter {
  country?: string
  q?: string
  publicIds?: string
}

/** One page of the station list and the number of stations that match the filter. */
async function getStationPage(
  filter: StationFilter,
  offset: number,
  limit: number,
  signal?: AbortSignal,
): Promise<OffsetPage<RadioStation>> {
  const { data } = await AXIOS_INSTANCE.get<OffsetPage<RadioStation>>('/api/radio/stations', {
    params: { ...filter, limit, offset },
    signal,
  })

  return data
}

/** Every station that matches the country and search text, read page by page. */
export async function getStations(country?: string, query?: string, signal?: AbortSignal): Promise<RadioStation[]> {
  const filter: StationFilter = {}
  if (country) filter.country = country
  if (query) filter.q = query

  return readAllPages((offset, limit) => getStationPage(filter, offset, limit, signal), STATION_PAGE_LIMIT)
}

/** The stations with these public IDs, a hundred IDs per request; unknown IDs are left out. */
export async function getStationsByIds(publicIds: string[], signal?: AbortSignal): Promise<RadioStation[]> {
  const chunks: string[][] = []
  for (let start = 0; start < publicIds.length; start += STATION_IDS_PER_REQUEST) {
    chunks.push(publicIds.slice(start, start + STATION_IDS_PER_REQUEST))
  }
  const pages = await Promise.all(chunks.map(async (chunk) => {
    const page = await getStationPage({ publicIds: chunk.join(',') }, 0, chunk.length, signal)

    return page.data ?? []
  }))

  return pages.flat()
}

// --- Starred ---

export async function getStarredStations(signal?: AbortSignal): Promise<StarredStation[]> {
  const { data } = await AXIOS_INSTANCE.get('/api/radio/starred', { signal })
  return data.data ?? []
}

export async function starStation(stationId: string): Promise<StarredStation> {
  const { data } = await AXIOS_INSTANCE.post('/api/radio/starred', { stationId })
  return data.data
}

export async function unstarStation(stationId: string): Promise<void> {
  await AXIOS_INSTANCE.delete(apiPath`/api/radio/starred/${stationId}`)
}

// --- Session ---

export async function getRadioSession(signal?: AbortSignal): Promise<RadioSession | null> {
  const { data } = await AXIOS_INSTANCE.get('/api/radio/session', { signal })
  return data.data
}

export async function startRadioSession(stationId: string, streamUrl: string): Promise<RadioSession> {
  const { data } = await AXIOS_INSTANCE.post('/api/radio/session/start', { stationId, streamUrl })
  return data.data
}

export async function stopRadioSession(): Promise<RadioSession> {
  const { data } = await AXIOS_INSTANCE.post('/api/radio/session/stop')
  return data.data
}
