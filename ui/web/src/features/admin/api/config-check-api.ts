import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface ConfigCheckResult {
  component: string
  status: 'healthy' | 'unhealthy' | 'not_available'
  responseTimeMs: number
  details: {
    severity?: 'error' | 'warning' | 'ok'
    message?: string
    suggestion?: string
    [key: string]: unknown
  }
}

export interface ConfigCheckSummary {
  errors: number
  warnings: number
  passed: number
}

export interface ConfigCheckResponse {
  results: ConfigCheckResult[]
  summary: ConfigCheckSummary
}

export async function getConfigCheck(): Promise<ConfigCheckResponse> {
  const { data } = await AXIOS_INSTANCE.get('/api/debug/config-check')
  return data.data
}
