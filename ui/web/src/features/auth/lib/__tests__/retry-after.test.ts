import { AxiosError, AxiosHeaders } from 'axios'
import { describe, expect, it } from 'vitest'
import { retryAfterSeconds } from '../retry-after'

function httpError(status: number, headers: Record<string, string> = {}) {
  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, {
    data: { error: { code: status, message: 'Too many requests.' } },
    status,
    statusText: 'Error',
    headers,
    config: { headers: new AxiosHeaders() },
  })
}

describe('retryAfterSeconds', () => {
  it('reads a delay in seconds from a 429 response', () => {
    expect(retryAfterSeconds(httpError(429, { 'retry-after': '42' }))).toBe(42)
  })

  it('converts an HTTP date into the remaining seconds', () => {
    const now = Date.parse('2026-10-07T12:00:00Z')

    expect(retryAfterSeconds(httpError(429, { 'retry-after': 'Wed, 07 Oct 2026 12:01:30 GMT' }), now)).toBe(90)
  })

  it('waits at least one second', () => {
    expect(retryAfterSeconds(httpError(429, { 'retry-after': '0' }))).toBe(1)
    expect(retryAfterSeconds(httpError(429, { 'retry-after': 'Wed, 07 Oct 2026 11:00:00 GMT' }), Date.parse('2026-10-07T12:00:00Z'))).toBe(1)
  })

  it('falls back to a minute when the header is missing or unreadable', () => {
    expect(retryAfterSeconds(httpError(429))).toBe(60)
    expect(retryAfterSeconds(httpError(429, { 'retry-after': 'soon' }))).toBe(60)
  })

  it('ignores responses that were not rate limited', () => {
    expect(retryAfterSeconds(httpError(400, { 'retry-after': '42' }))).toBeNull()
    expect(retryAfterSeconds(new Error('Network down'))).toBeNull()
  })
})
