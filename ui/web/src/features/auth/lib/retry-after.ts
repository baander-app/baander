import { AxiosError } from 'axios'

/** Used when a 429 response carries no usable Retry-After header. */
const DEFAULT_RETRY_SECONDS = 60

/**
 * Seconds to wait before retrying a request the server rate limited, or null when the
 * error is not a 429 response. Retry-After may be a number of seconds or an HTTP date.
 */
export function retryAfterSeconds(err: unknown, now: number = Date.now()): number | null {
  if (!(err instanceof AxiosError) || err.response?.status !== 429) return null

  const header: unknown = err.response.headers?.['retry-after']
  if (typeof header !== 'string' || header.trim() === '') return DEFAULT_RETRY_SECONDS

  const value = header.trim()
  if (/^\d+$/.test(value)) return Math.max(1, Number.parseInt(value, 10))

  const date = Date.parse(value)
  if (Number.isNaN(date)) return DEFAULT_RETRY_SECONDS

  return Math.max(1, Math.ceil((date - now) / 1000))
}
