import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface TranscodeSession {
  id: string
  trackId: string
  trackName: string
  status: 'queued' | 'processing' | 'completed' | 'failed'
  progress: number | null
  bitrate: number
  codec: string
  startedAt: string | null
  finishedAt: string | null
}

export interface TranscodeStats {
  active: number
  queued: number
  completedToday: number
  failedToday: number
  avgProcessingTime: number | null
}

export const transcodeAdminApi = {
  getSessions: (params?: { status?: string }) =>
    AXIOS_INSTANCE.get<{ data: TranscodeSession[] }>('/api/admin/transcode/sessions', { params }).then(
      (r) => r.data.data ?? r.data,
    ),

  getSession: (id: string) =>
    AXIOS_INSTANCE.get<TranscodeSession>(`/api/admin/transcode/sessions/${id}`).then(
      (r) => r.data,
    ),

  getStats: () =>
    AXIOS_INSTANCE.get<TranscodeStats>('/api/admin/transcode/stats').then(
      (r) => r.data,
    ),
}
