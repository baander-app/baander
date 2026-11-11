import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MockAdapter from 'axios-mock-adapter'

interface AuthState {
  accessToken: string | null
  refreshToken: string | null
}
const auth = vi.hoisted(() => {
  let state: AuthState = { accessToken: 'access-token', refreshToken: 'refresh-token' }
  const listeners = new Set<(state: AuthState, previous: AuthState) => void>()
  return {
    getState: () => state,
    setState: (next: Partial<AuthState>) => {
      const previous = state
      state = { ...state, ...next }
      listeners.forEach(listener => listener(state, previous))
    },
    subscribe: (listener: (state: AuthState, previous: AuthState) => void) => {
      listeners.add(listener)
      return () => { listeners.delete(listener) }
    },
  }
})
const dpop = vi.hoisted(() => ({
  keyPair: { privateKey: {}, jwk: {} } as object | null,
  nonce: 'server-nonce' as string | null,
}))
vi.mock('@/features/auth/stores/auth-store', () => ({ useAuthStore: auth }))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => dpop.keyPair,
  getDpopNonce: () => dpop.nonce,
  setDpopNonce: vi.fn(),
}))
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: vi.fn() }))

import { createDpopProof } from '@/shared/crypto/dpop-proof'
import { synchronizeOwnership } from '@/shared/live/ownership'
import { AXIOS_INSTANCE } from '../axios-instance'
import { onAccessTokenChange, prepareKeepalivePost, sendKeepalivePost } from '../keepalive-request'

const capability = { 'X-Baander-Playback-Endpoint': 'private-capability' }
const lowercased = (headers: Record<string, unknown>) => Object.fromEntries(
  Object.entries(headers).map(([name, value]) => [name.toLowerCase(), value]),
)

beforeEach(() => {
  synchronizeOwnership(crypto.randomUUID())
  auth.setState({ accessToken: 'access-token' })
  dpop.keyPair = { privateKey: {}, jwk: {} }
  dpop.nonce = 'server-nonce'
  let proofs = 0
  vi.mocked(createDpopProof).mockReset().mockImplementation(async () => `proof-${++proofs}`)
})
afterEach(() => { vi.unstubAllGlobals() })

describe('a request prepared ahead of an unload', () => {
  it('carries the headers the API client sends for the same request, each with its own proof', async () => {
    const mock = new MockAdapter(AXIOS_INSTANCE)
    let sent: Record<string, unknown> = {}
    mock.onPost('/api/session/close').reply(config => {
      sent = lowercased({ ...config.headers })
      return [200, {}]
    })
    try {
      await AXIOS_INSTANCE.post('/api/session/close', { incarnationId: 'incarnation' }, { headers: capability })
      const prepared = await prepareKeepalivePost('/api/session/close', capability)

      const { dpop: clientProof, ...clientHeaders } = sent
      const { dpop: preparedProof, ...preparedHeaders } = lowercased({ ...prepared!.headers })
      expect(preparedHeaders).toEqual(clientHeaders)
      expect(preparedHeaders).toMatchObject({
        authorization: 'DPoP access-token', 'x-baander-playback-endpoint': 'private-capability', 'content-type': 'application/json',
      })
      expect([clientProof, preparedProof]).toEqual(['proof-1', 'proof-2'])
    } finally {
      mock.restore()
    }
  })

  it('signs a proof for a POST to the request URL with the access token and the current nonce', async () => {
    const prepared = await prepareKeepalivePost('/api/session/close')

    const url = new URL('/api/session/close', window.location.href).href
    expect(prepared?.url).toBe(url)
    expect(createDpopProof).toHaveBeenCalledExactlyOnceWith(dpop.keyPair, 'POST', url, { accessToken: 'access-token', nonce: 'server-nonce' })
  })

  it.each(['access token', 'key pair'] as const)('prepares nothing without the %s', async missing => {
    if (missing === 'access token') auth.setState({ accessToken: null })
    else dpop.keyPair = null

    await expect(prepareKeepalivePost('/api/session/close')).resolves.toBeNull()
    expect(createDpopProof).not.toHaveBeenCalled()
  })

  it('prepares nothing when the access token is replaced while the proof is signed', async () => {
    vi.mocked(createDpopProof).mockImplementation(async () => {
      auth.setState({ accessToken: 'rotated-token' })
      return 'proof-for-the-old-token'
    })

    await expect(prepareKeepalivePost('/api/session/close')).resolves.toBeNull()
  })

  it('is sent with keepalive, its prepared headers and a JSON body', async () => {
    const fetched = vi.fn<typeof fetch>(async () => new Response(null, { status: 200 }))
    vi.stubGlobal('fetch', fetched)
    const prepared = (await prepareKeepalivePost('/api/session/close', capability))!

    sendKeepalivePost(prepared, { incarnationId: 'incarnation' })

    expect(fetched).toHaveBeenCalledExactlyOnceWith(prepared.url, {
      method: 'POST', keepalive: true, headers: prepared.headers, body: '{"incarnationId":"incarnation"}',
    })
  })

  // The run fails on an unhandled rejection, so a dropped request that was left unhandled fails here.
  it('handles the rejection of a request the browser dropped', async () => {
    const fetched = vi.fn(async () => { throw new TypeError('Failed to fetch') })
    vi.stubGlobal('fetch', fetched)
    const prepared = (await prepareKeepalivePost('/api/session/close'))!

    expect(() => sendKeepalivePost(prepared, {})).not.toThrow()
    await new Promise(resolve => setTimeout(resolve, 0))

    expect(fetched).toHaveBeenCalledOnce()
  })

  it('announces a replaced access token and nothing else, until it is unsubscribed', () => {
    const changed = vi.fn()
    const unsubscribe = onAccessTokenChange(changed)

    auth.setState({ refreshToken: 'another-refresh-token' })
    expect(changed).not.toHaveBeenCalled()
    auth.setState({ accessToken: 'rotated-token' })
    expect(changed).toHaveBeenCalledOnce()

    unsubscribe()
    auth.setState({ accessToken: 'rotated-again' })
    expect(changed).toHaveBeenCalledOnce()
  })
})
