import { useQuery, useQueryClient } from '@tanstack/react-query'
import { getCoroutineStats, getWorkerStats, getSpans, clearSpans } from '../api/debug-api'

export function useCoroutineStats() {
  return useQuery({
    queryKey: ['debug', 'coroutines'],
    queryFn: getCoroutineStats,
    refetchInterval: 5_000,
    retry: false,
  })
}

export function useWorkerStats() {
  return useQuery({
    queryKey: ['debug', 'workers'],
    queryFn: getWorkerStats,
    refetchInterval: 5_000,
    retry: false,
  })
}

export function useSpans(limit = 50) {
  return useQuery({
    queryKey: ['debug', 'spans', limit],
    queryFn: () => getSpans(limit),
    refetchInterval: 10_000,
    retry: false,
  })
}

export function useClearSpans() {
  const queryClient = useQueryClient()
  return async () => {
    await clearSpans()
    queryClient.invalidateQueries({ queryKey: ['debug', 'spans'] })
  }
}
