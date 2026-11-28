import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import {
  getAdminMetadataProviders,
  patchAdminMetadataProviderUpdate,
  type MetadataProviderResource,
} from '@/shared/api-client/gen/endpoints'

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

export type MetadataProvider = MetadataProviderResource

export const metadataAdminApi = {
  getSyncStatus: (signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<{ data: MetadataSyncStatus }>('/api/admin/metadata/sync-status', { signal }).then(
      (r) => r.data.data,
    ),

  triggerSync: (source?: string) =>
    AXIOS_INSTANCE.post<{ data: { jobsDispatched: number } }>('/api/admin/metadata/trigger-sync', {
      source,
    }).then((r) => r.data.data),

  triggerGenreSync: () =>
    AXIOS_INSTANCE.post<{ data: { jobsDispatched: number } }>('/api/admin/metadata/trigger-sync', {
      source: 'genres',
    }).then((r) => r.data.data),

  getProviders: async (signal?: AbortSignal) => {
    const { data } = await getAdminMetadataProviders({ signal })
    if (!data) throw new Error('No metadata providers were returned.')
    return data
  },

  setProviderEnabled: async (provider: string, enabled: boolean) => {
    const { data } = await patchAdminMetadataProviderUpdate(provider, { enabled })
    if (!data) throw new Error('No updated metadata provider was returned.')
    return data
  },
}
