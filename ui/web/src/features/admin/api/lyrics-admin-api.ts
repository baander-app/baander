import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface LyricsCoverage {
  totalTracks: number
  tracksWithLyrics: number
  tracksWithoutLyrics: number
  coveragePercentage: number
  bySource: { source: string; count: number }[]
}

export interface SyncStatus {
  lastSyncAt: string | null
  pendingJobs: number
  completedJobs: number
  failedJobs: number
}

export const lyricsAdminApi = {
  getCoverage: () =>
    AXIOS_INSTANCE.get<{ data: LyricsCoverage }>('/api/admin/lyrics/coverage').then((r) => r.data.data),

  triggerBulkFetch: () =>
    AXIOS_INSTANCE.post<{ data: { jobsEnqueued: number } }>('/api/admin/lyrics/bulk-fetch').then((r) => r.data.data),

  getSyncStatus: () =>
    AXIOS_INSTANCE.get<{ data: SyncStatus }>('/api/admin/lyrics/sync-status').then((r) => r.data.data),
}
