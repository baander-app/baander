import { useQuery } from '@tanstack/react-query'
import { transcodeAdminApi } from '../api/transcode-admin-api'

export function useTranscodeSessions(status?: string) {
  return useQuery({
    queryKey: ['admin-transcode-sessions', status],
    queryFn: () => transcodeAdminApi.getSessions(status ? { status } : undefined),
    refetchInterval: (q) => (q.state.data ? 5_000 : false),
    retry: false,
  })
}

export function useTranscodeStats() {
  return useQuery({
    queryKey: ['admin-transcode-stats'],
    queryFn: transcodeAdminApi.getStats,
    refetchInterval: (q) => (q.state.data ? 5_000 : false),
    retry: false,
  })
}
