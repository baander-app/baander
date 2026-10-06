import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { getRateLimiters, clearAllRateLimiters } from '../api/rate-limiter-api'

export function useRateLimiters() {
  return useQuery({
    queryKey: ['rate-limiters'],
    queryFn: getRateLimiters,
    retry: false,
  })
}

export function useClearRateLimiters() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: clearAllRateLimiters,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rate-limiters'] }),
  })
}
