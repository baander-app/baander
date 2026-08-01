import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

// --- Types ---

export interface RadioSource {
  id: string
  name: string
  type: string
  syncUrl: string
  syncConfig: Record<string, unknown>
  syncSchedule: string | null
  isActive: boolean
  createdAt: string
  updatedAt: string
}

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
  id: string
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
  id: string
  userId: string
  sourceId: string
  countryCode: string
  lastSyncedAt: string | null
  createdAt: string
}

export interface StarredStation {
  id: string
  userId: string
  stationId: string
  starredAt: string
}

export interface RadioSession {
  id: string
  userId: string
  state: 'playing' | 'stopped'
  activeStationId: string | null
  activeStreamUrl: string | null
  createdAt: string
  updatedAt: string
}

// --- Sources ---

export async function getRadioSources(): Promise<RadioSource[]> {
  const { data } = await AXIOS_INSTANCE.get('/api/radio/sources')
  return data.data ?? []
}

// --- Countries ---

export async function getAvailableCountries(): Promise<CountryInfo[]> {
  const { data } = await AXIOS_INSTANCE.get('/api/radio/countries')
  return data.data ?? []
}

// --- Subscriptions ---

export async function getSubscriptions(): Promise<CountrySubscription[]> {
  const { data } = await AXIOS_INSTANCE.get('/api/radio/subscriptions')
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
  await AXIOS_INSTANCE.delete(`/api/radio/subscriptions/${countryCode}`, { data: { sourceId } })
}

export async function refreshCountry(countryCode: string): Promise<{ synced: number }> {
  const { data } = await AXIOS_INSTANCE.post(`/api/radio/subscriptions/${countryCode}/refresh`)
  return data.data
}

// --- Stations ---

export async function getStations(country?: string, query?: string): Promise<RadioStation[]> {
  const params: Record<string, string> = {}
  if (country) params.country = country
  if (query) params.q = query
  const { data } = await AXIOS_INSTANCE.get('/api/radio/stations', { params })
  return data.data ?? []
}

// --- Starred ---

export async function getStarredStations(): Promise<StarredStation[]> {
  const { data } = await AXIOS_INSTANCE.get('/api/radio/starred')
  return data.data ?? []
}

export async function starStation(stationId: string): Promise<StarredStation> {
  const { data } = await AXIOS_INSTANCE.post('/api/radio/starred', { stationId })
  return data.data
}

export async function unstarStation(stationId: string): Promise<void> {
  await AXIOS_INSTANCE.delete(`/api/radio/starred/${stationId}`)
}

// --- Session ---

export async function getRadioSession(): Promise<RadioSession | null> {
  const { data } = await AXIOS_INSTANCE.get('/api/radio/session')
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
