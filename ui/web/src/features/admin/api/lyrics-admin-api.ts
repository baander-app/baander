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

/**
 * The outcomes of a bulk fetch run. A queued fetch counts once a worker has handled it, so
 * while fetches are still due the outcome counts add up to less than `queued`.
 */
export interface BulkRunTally {
  /** The job-monitor job that ran it, or null for a run outside a job. */
  jobId: string | null
  /** When the run finished queueing. */
  finishedAt: string
  queued: number
  found: number
  noMatch: number
  /** Songs not looked up because they have no primary artist credit. */
  skippedNoArtist: number
  /** Songs not looked up because their length is missing or not positive. */
  skippedNoLength: number
  rejected: number
  unavailable: number
}

/** The lyrics fetch jobs the job monitor recorded, and the latest bulk fetch run. */
export interface SyncStatus {
  lastSyncAt: string | null
  /** Lyrics jobs created in the past 7 days. */
  recentJobs: number
  completedJobs: number
  failedJobs: number
  /** Null once the latest run has expired, a day after its last fetch was due. */
  latestRun: BulkRunTally | null
}

/** What a bulk fetch queued, and the songs without lyrics it could not look up. */
export interface BulkFetchResult {
  jobsEnqueued: number
  skippedNoArtist: number
  skippedNoLength: number
}

export const lyricsAdminApi = {
  getCoverage: (signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<{ data: LyricsCoverage }>('/api/admin/lyrics/coverage', { signal }).then((r) => r.data.data),

  triggerBulkFetch: () =>
    AXIOS_INSTANCE.post<{ data: BulkFetchResult }>('/api/admin/lyrics/bulk-fetch').then((r) => r.data.data),

  getSyncStatus: (signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<{ data: SyncStatus }>('/api/admin/lyrics/sync-status', { signal }).then((r) => r.data.data),
}
