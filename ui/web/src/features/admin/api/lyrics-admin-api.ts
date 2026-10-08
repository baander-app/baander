import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

/**
 * Stored lyrics per source, most first. The API sends a JSON array instead of an
 * object while no lyrics are stored; `Object.entries` reads both as no sources.
 */
export type LyricsCountBySource = Record<string, number>

export interface LyricsCoverage {
  totalTracks: number
  tracksWithLyrics: number
  tracksWithoutLyrics: number
  coveragePercentage: number
  bySource: LyricsCountBySource
}

/** The lyrics fetch jobs the job monitor recorded. */
export interface SyncStatus {
  lastSyncAt: string | null
  /** Lyrics jobs created in the past 7 days. */
  recentJobs: number
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
