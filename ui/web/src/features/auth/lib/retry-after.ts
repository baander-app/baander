import { isAxiosError } from 'axios'

/** Used when a response carries no usable Retry-After header. */
const DEFAULT_RETRY_SECONDS = 60

export interface RetryAfterOptions {
  /** The status the response must carry: 429 (rate limited) by default, 503 for an outage. */
  status?: number
  /** The current time, for a Retry-After given as an HTTP date. */
  now?: number
}

/**
 * Seconds a response asked the client to wait before retrying, or null when the error is not a
 * response with the expected status. Retry-After may be a number of seconds or an HTTP date; a
 * missing or unreadable header counts as a minute.
 */
export function retryAfterSeconds(err: unknown, { status = 429, now = Date.now() }: RetryAfterOptions = {}): number | null {
  if (!isAxiosError(err) || err.response?.status !== status) return null

  const header: unknown = err.response.headers?.['retry-after']
  if (typeof header !== 'string' || header.trim() === '') return DEFAULT_RETRY_SECONDS

  const value = header.trim()
  if (/^\d+$/.test(value)) return Math.max(1, Number.parseInt(value, 10))

  const date = Date.parse(value)
  if (Number.isNaN(date)) return DEFAULT_RETRY_SECONDS

  return Math.max(1, Math.ceil((date - now) / 1000))
}
