import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface MetadataProviderStats {
  name: string
  synced: number
  failed: number
}

export interface MetadataSyncStatus {
  lastSyncAt: string | null
  totalTracks: number
  syncedTracks: number
  pendingTracks: number
  failedTracks: number
  sources: MetadataProviderStats[]
}

export interface MetadataProvider {
  name: string
  enabled: boolean
  configured: boolean
}

export const metadataAdminApi = {
  getSyncStatus: () =>
    AXIOS_INSTANCE.get<MetadataSyncStatus>('/api/admin/metadata/sync-status').then(
      (r) => r.data,
    ),

  triggerSync: (source?: string) =>
    AXIOS_INSTANCE.post('/api/admin/metadata/trigger-sync', { source }).then(
      (r) => r.data,
    ),

  triggerGenreSync: () =>
    AXIOS_INSTANCE.post('/api/admin/metadata/trigger-sync', {
      source: 'genres',
    }).then((r) => r.data),

  getProviders: () =>
    AXIOS_INSTANCE.get<MetadataProvider[]>('/api/admin/metadata/providers').then(
      (r) => r.data,
    ),
}
