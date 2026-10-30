import { useAdminMutationTools } from './use-admin-mutation'
import { useQuery, useMutation } from '@tanstack/react-query'
import { getRateLimiters, clearAllRateLimiters } from '../api/rate-limiter-api'

export function useRateLimiters() {
  return useQuery({
    queryKey: ['rate-limiters'],
    queryFn: ({ signal }) => getRateLimiters(signal),
    retry: false,
  })
}

export function useClearRateLimiters() {
  const { run, invalidate } = useAdminMutationTools(['admin-security'])

  return useMutation({
    mutationFn: () => run(() => clearAllRateLimiters()),
    onSuccess: () => invalidate(['rate-limiters']),
  })
}
