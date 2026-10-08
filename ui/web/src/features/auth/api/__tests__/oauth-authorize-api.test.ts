import { AxiosError, AxiosHeaders } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { decideAuthorization, lookupAuthorization } from '../oauth-authorize-api'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: vi.fn(), post: vi.fn() } }))

const mockGet = vi.mocked(AXIOS_INSTANCE.get)
const mockPost = vi.mocked(AXIOS_INSTANCE.post)

const REDIRECT_URI = 'https://app.baander.app/cb'
const QUERY = 'response_type=code&client_id=tv-app&redirect_uri=https%3A%2F%2Fapp.baander.app%2Fcb'
  + '&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256&scope=library+playlist&state=s1'

const REQUEST = {
  client_id: 'tv-app',
  client_name: 'Bånder TV',
  client_type: 'public',
  scopes: ['library', 'playlist'],
  redirect_uri: REDIRECT_URI,
  consent_required: true,
}

function axiosError(status: number, data: unknown) {
  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, {
    data,
    status,
    statusText: 'Error',
    headers: {},
    config: { headers: new AxiosHeaders() },
  })
}

describe('authorization endpoint API', () => {
  beforeEach(() => {
    mockGet.mockReset()
    mockPost.mockReset()
  })

  it('passes the query through unchanged and reads the consent details', async () => {
    mockGet.mockResolvedValue({ data: REQUEST })
    const signal = new AbortController().signal

    await expect(lookupAuthorization(QUERY, signal)).resolves.toEqual({
      kind: 'consent',
      details: {
        clientName: 'Bånder TV',
        clientType: 'public',
        scopes: ['library', 'playlist'],
        redirectUri: REDIRECT_URI,
        consentRequired: true,
      },
    })
    const [url, config] = mockGet.mock.calls[0]
    expect(url).toBe('/api/oauth/authorize')
    expect(config?.signal).toBe(signal)
    expect(String(config?.params)).toBe(new URLSearchParams(QUERY).toString())
  })

  it('reports an invalid client or redirect URI as a rejection, not a redirect', async () => {
    mockGet.mockRejectedValue(axiosError(400, { error: 'invalid_request', error_description: 'Unregistered redirect URI.' }))

    await expect(lookupAuthorization(QUERY)).resolves.toEqual({
      kind: 'rejected',
      error: 'invalid_request',
      description: 'Unregistered redirect URI.',
    })
  })

  it('returns the error redirect for other OAuth errors', async () => {
    const redirect = `${REDIRECT_URI}?error=invalid_scope&error_description=Unknown&state=s1`
    mockGet.mockRejectedValue(axiosError(400, { error: 'invalid_scope', error_description: 'Unknown', redirect_uri: redirect }))

    await expect(lookupAuthorization(QUERY)).resolves.toEqual({ kind: 'redirect', redirectUri: redirect })
  })

  it('rethrows lost sessions, rate limits, server failures, and answers it cannot read', async () => {
    const unauthenticated = axiosError(401, { error: { code: 401, message: 'Authentication required.' } })
    mockGet.mockRejectedValueOnce(unauthenticated)
    await expect(lookupAuthorization(QUERY)).rejects.toBe(unauthenticated)

    const limited = axiosError(429, { error: { code: 429, message: 'Too many requests.' } })
    mockGet.mockRejectedValueOnce(limited)
    await expect(lookupAuthorization(QUERY)).rejects.toBe(limited)

    const failed = axiosError(500, { error: 'server_error' })
    mockGet.mockRejectedValueOnce(failed)
    await expect(lookupAuthorization(QUERY)).rejects.toBe(failed)

    mockGet.mockResolvedValueOnce({ data: { data: REQUEST } })
    await expect(lookupAuthorization(QUERY)).rejects.toThrow('unexpected answer')
  })

  it('sends the request parameters with the decision and returns the redirect', async () => {
    const redirect = `${REDIRECT_URI}?code=c0de&state=s1`
    mockPost.mockResolvedValue({ data: { redirect_uri: redirect } })

    await expect(decideAuthorization(QUERY, 'approve')).resolves.toEqual({ kind: 'redirect', redirectUri: redirect })
    const [url, body, config] = mockPost.mock.calls[0]
    expect(url).toBe('/api/oauth/authorize')
    expect(body).toEqual({ ...Object.fromEntries(new URLSearchParams(QUERY)), decision: 'approve' })
    expect(config).toBeUndefined()
  })

  it('lets the decision override a decision parameter in the query', async () => {
    mockPost.mockResolvedValue({ data: { redirect_uri: `${REDIRECT_URI}?error=access_denied` } })

    await decideAuthorization(`${QUERY}&decision=approve`, 'deny')

    expect(mockPost.mock.calls[0][1]).toMatchObject({ decision: 'deny' })
  })

  it('fails when a decision answer has no redirect URI', async () => {
    mockPost.mockResolvedValue({ data: {} })

    await expect(decideAuthorization(QUERY, 'deny')).rejects.toThrow('no redirect URI')
  })
})
