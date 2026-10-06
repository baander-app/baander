import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

// Types

export interface RateLimiterConfig {
  policy: string
  limit: number
  interval: string | null
  description: string | null
  cachePool: string
}

export interface RateLimitersResponse {
  limiters: Record<string, RateLimiterConfig>
  count: number
}

export interface ClearAllRateLimitersResponse {
  cleared: boolean
  limiters: string[]
}

// API Functions

export async function getRateLimiters(): Promise<RateLimitersResponse> {
  const { data } = await AXIOS_INSTANCE.get('/api/monitor/rate-limiters')
  return data.data
}

export async function clearAllRateLimiters(): Promise<ClearAllRateLimitersResponse> {
  const { data } = await AXIOS_INSTANCE.delete('/api/monitor/rate-limiters/clear', {
    params: { confirm: 'true' },
  })
  return data.data
}
