import { AxiosError, AxiosHeaders } from 'axios'
import { describe, expect, it } from 'vitest'
import { parseApiError } from '../parse-api-error'

function apiError(data: unknown) {
  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, {
    data,
    status: 400,
    statusText: 'Bad Request',
    headers: {},
    config: { headers: new AxiosHeaders() },
  })
}

describe('parseApiError', () => {
  it('preserves a shared numeric code and backend message', () => {
    expect(parseApiError(apiError({ error: { code: 422, message: 'Email already in use.', details: { email: ['Taken.'] } } }), 'Fallback'))
      .toEqual({ code: 422, message: 'Email already in use.' })
  })

  it('preserves the string code used for the second-factor challenge', () => {
    expect(parseApiError(apiError({ error: { code: 'AUTH_TOTP_REQUIRED', message: 'Enter your code.' } }), 'Fallback'))
      .toEqual({ code: 'AUTH_TOTP_REQUIRED', message: 'Enter your code.' })
  })

  it('extracts OAuth descriptions and falls back to the OAuth code when absent', () => {
    expect(parseApiError(apiError({ error: 'invalid_grant', error_description: 'Session expired.' }), 'Fallback'))
      .toEqual({ code: 'invalid_grant', message: 'Session expired.' })
    expect(parseApiError(apiError({ error: 'invalid_grant' }), 'Fallback'))
      .toEqual({ code: 'invalid_grant', message: 'invalid_grant' })
  })

  it.each([
    null, [], 'Failure', {}, { error: null }, { error: [] },
    { error: { code: 422, message: {} } },
    { error: { code: null, message: 'Invalid' } },
    { error: { code: 4.5, message: 'Invalid' } },
    { error: { code: Number.NaN, message: 'Invalid' } },
    { error: { code: Infinity, message: 'Invalid' } },
    { error: 'invalid_grant', error_description: { message: 'Invalid' } },
  ])('uses the fallback for malformed payload %j', (payload) => {
    expect(parseApiError(apiError(payload), 'Fallback')).toEqual({ code: null, message: 'Fallback' })
  })

  it('uses the fallback for network and non-Axios failures', () => {
    for (const error of [new AxiosError('Network Error'), new Error('Local failure'), null]) {
      expect(parseApiError(error, 'Fallback')).toEqual({ code: null, message: 'Fallback' })
    }
  })
})
