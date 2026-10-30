import { useAdminMutationTools } from './use-admin-mutation'
import { useQuery, useMutation } from '@tanstack/react-query'
import { getCoroutineStats, getWorkerStats, getSpans, clearSpans } from '../api/debug-api'

export function useCoroutineStats() {
  return useQuery({
    queryKey: ['debug', 'coroutines'],
    queryFn: ({ signal }) => getCoroutineStats(signal),

    retry: false,
  })
}

export function useWorkerStats() {
  return useQuery({
    queryKey: ['debug', 'workers'],
    queryFn: ({ signal }) => getWorkerStats(signal),

    retry: false,
  })
}

export function useSpans(limit = 50) {
  return useQuery({
    queryKey: ['debug', 'spans', limit],
    queryFn: ({ signal }) => getSpans(limit, signal),

    retry: false,
  })
}

export function useClearSpans() {
  const { run, invalidate } = useAdminMutationTools(['admin-runtime'])

  return useMutation({
    mutationFn: () => run(() => clearSpans()),
    onSuccess: () => invalidate(['debug', 'spans']),
  })
}
