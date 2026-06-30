import { useQuery } from '@tanstack/react-query'
import { getServerStats } from '../api/server-stats-api'

export function useServerStats() {
  return useQuery({
    queryKey: ['server-stats'],
    queryFn: getServerStats,
    refetchInterval: 5_000,
    retry: false,
  })
}
