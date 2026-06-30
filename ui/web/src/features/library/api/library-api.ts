import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export const LIBRARY_TYPES = ['music', 'podcast', 'audiobook', 'movie', 'tv_show'] as const
export type LibraryType = (typeof LIBRARY_TYPES)[number]

export interface Library {
  id: string
  name: string
  slug: string
  path: string
  type: LibraryType
  sortOrder: number
  lastScan: string | null
  scanStatus: 'scanning' | 'completed' | 'failed' | null
  createdAt: string
  updatedAt: string
}

export interface CreateLibraryPayload {
  name: string
  path: string
  type: string
  sortOrder?: number
  slug?: string | null
}

export interface UpdateLibraryPayload {
  name?: string
  sortOrder?: number
}

export interface LibraryStats {
  songs: number
  albums: number
  artists: number
  genres: number
  totalSize: number
  totalDuration: number
}

export interface PathValidationResult {
  valid: boolean
  error: string | null
  resolvedPath: string | null
  exists: boolean
  readable: boolean
}

export interface ScanAllResult {
  dispatched: number
  skipped: number
}

export async function getLibraries(type?: string): Promise<Library[]> {
  const params = type ? { type } : undefined
  const { data } = await AXIOS_INSTANCE.get('/api/libraries', { params })
  return data.data
}

export async function getLibrary(id: string): Promise<Library> {
  const { data } = await AXIOS_INSTANCE.get(`/api/libraries/${id}`)
  return data.data
}

export async function createLibrary(payload: CreateLibraryPayload): Promise<Library> {
  const { data } = await AXIOS_INSTANCE.post('/api/libraries', payload)
  return data.data
}

export async function updateLibrary(id: string, payload: UpdateLibraryPayload): Promise<Library> {
  const { data } = await AXIOS_INSTANCE.patch(`/api/libraries/${id}`, payload)
  return data.data
}

export async function deleteLibrary(id: string): Promise<void> {
  await AXIOS_INSTANCE.delete(`/api/libraries/${id}`)
}

export async function scanLibrary(id: string, options?: { rescan?: boolean }): Promise<Library> {
  const { data } = await AXIOS_INSTANCE.post(`/api/libraries/${id}/scan`, {
    rescan: options?.rescan ?? false,
  })
  return data.data
}

export async function getLibraryStats(id: string): Promise<LibraryStats> {
  const { data } = await AXIOS_INSTANCE.get(`/api/libraries/${id}/stats`)
  return data.data
}

export async function validatePath(path: string): Promise<PathValidationResult> {
  const { data } = await AXIOS_INSTANCE.post('/api/libraries/validate-path', { path })
  return data.data
}

export async function scanAllLibraries(): Promise<ScanAllResult> {
  const { data } = await AXIOS_INSTANCE.post('/api/libraries/scan-all')
  return data.data
}
