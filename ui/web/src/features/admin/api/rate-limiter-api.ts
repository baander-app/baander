import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

// Types

export interface RateLimiterConfig {
  policy: string
  limit: number
  interval: string
  description: string
}

export interface RateLimitersResponse {
  limiters: Record<string, RateLimiterConfig>
  count: number
  cachePool: string
}

export interface ClearRateLimitersResponse {
  cleared: boolean
  limiter: string
  pool: string
}

// API Functions

export async function getRateLimiters(): Promise<RateLimitersResponse> {
  const { data } = await AXIOS_INSTANCE.get('/api/monitor/rate-limiters')
  return data.data
}

export async function clearRateLimiters(name: string): Promise<ClearRateLimitersResponse> {
  const { data } = await AXIOS_INSTANCE.delete(`/api/monitor/rate-limiters/${name}/clear`, {
    params: { confirm: 'true' },
  })
  return data.data
}
