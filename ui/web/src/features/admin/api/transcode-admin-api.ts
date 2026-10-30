import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

/**
 * Mirrors App\Transcode\Interface\Resource\TranscodeSessionResource.
 * The backend `GET /api/transcode/sessions` (index) returns the active
 * sessions for the authenticated user wrapped in the `{ data: ... }` envelope.
 */
export interface TranscodeSession {
  publicId: string
  userId: string
  jobId: string
  videoId: string
  state: 'pending' | 'preparing' | 'active' | 'paused' | 'completed' | 'failed' | 'cancelled'
  priority: 'critical' | 'high' | 'normal' | 'low' | 'bulk'
  audioProfile: Record<string, unknown>
  currentSegmentIndex: number | null
  wallClockOffset: number | null
  metrics: Record<string, unknown> | null
  createdAt: string
  updatedAt: string
}

export const transcodeAdminApi = {
  getSessions: (signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<{ data: TranscodeSession[] }>('/api/transcode/sessions/', { signal }).then(
      (r) => r.data.data ?? [],
    ),

  getSession: (publicId: string, signal?: AbortSignal) =>
    AXIOS_INSTANCE.get<{ data: TranscodeSession }>(`/api/transcode/sessions/${publicId}`, { signal }).then(
      (r) => r.data.data,
    ),
}
