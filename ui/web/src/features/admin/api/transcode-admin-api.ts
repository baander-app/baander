import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

/**
 * Mirrors App\Transcode\Interface\Resource\TranscodeSessionResource.
 * The backend `GET /api/transcode/sessions` (index) returns the active
 * sessions for the authenticated user wrapped in the `{ data: ... }` envelope.
 */
export interface TranscodeSession {
  uuid: string
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
  getSessions: () =>
    AXIOS_INSTANCE.get<{ data: TranscodeSession[] }>('/api/transcode/sessions').then(
      (r) => r.data.data ?? [],
    ),

  getSession: (uuid: string) =>
    AXIOS_INSTANCE.get<{ data: TranscodeSession }>(`/api/transcode/sessions/${uuid}`).then(
      (r) => r.data.data,
    ),
}
