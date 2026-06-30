import { useQuery, useMutation } from '@tanstack/react-query'
import { getRateLimiters, clearRateLimiters } from '../api/rate-limiter-api'

export function useRateLimiters() {
  return useQuery({
    queryKey: ['rate-limiters'],
    queryFn: getRateLimiters,
    retry: false,
  })
}

export function useClearRateLimiters() {
  return useMutation({
    mutationFn: clearRateLimiters,
  })
}
