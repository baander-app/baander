import { useQuery } from '@tanstack/react-query'
import { transcodeAdminApi } from '../api/transcode-admin-api'

export function useTranscodeSessions() {
  return useQuery({
    queryKey: ['admin-transcode-sessions'],
    queryFn: () => transcodeAdminApi.getSessions(),
    refetchInterval: (q) => (q.state.data ? 5_000 : false),
    retry: false,
  })
}
