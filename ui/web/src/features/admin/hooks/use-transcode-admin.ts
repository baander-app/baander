import { useQuery } from '@tanstack/react-query'
import { transcodeAdminApi } from '../api/transcode-admin-api'

export function useTranscodeSessions() {
  return useQuery({
    queryKey: ['admin-transcode-sessions'],
    queryFn: ({ signal }) => transcodeAdminApi.getSessions(signal),

    retry: false,
  })
}
