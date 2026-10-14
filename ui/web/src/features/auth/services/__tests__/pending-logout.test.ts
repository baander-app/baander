import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Axios, { AxiosError, type AxiosAdapter, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { FakeIndexedDb, FakeLockManager, installIndexedDb, installLocks } from '../../../../../tests/shared/auth/auth-fakes'
import { generateDpopKeyPair } from '@/shared/crypto/dpop-key-pair'
import { getDpopKeyPair } from '@/shared/crypto/dpop-store'
import { beginStoredLogout, listPendingLogouts, type PendingLogoutEntry } from '@/shared/crypto/auth-db'
import {
  PENDING_LOGOUT_BACKOFF_CAP_MS,
  PENDING_LOGOUT_BACKOFF_MS,
  PENDING_LOGOUT_LOCK_WAIT_MS,
  PENDING_LOGOUT_TIMER_ATTEMPTS,
  REFRESH_ATTEMPT_TIMEOUT_MS,
} from '@/shared/auth/refresh-budget'
import type { DpopKeyPair } from '../../../../../../shared/dpop/key-pair'

// The shared Axios instance reads the auth store; the drain never does, so a bare stub suffices.
vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))

const log = vi.hoisted(() => ({ warnings: [] as unknown[][] }))

vi.mock('@/shared/lib/logger', () => ({
  createLogger: () => ({
    trace: vi.fn(),
    debug: vi.fn(),
    info: vi.fn(),
    warn: (...args: unknown[]) => { log.warnings.push(args) },
    error: vi.fn(),
  }),
}))

const API_ORIGIN = 'https://api.baander.app'
const LOGOUT_URL = `${API_ORIGIN}/api/auth/logout`
const REFRESH_URL = `${API_ORIGIN}/api/auth/refresh`
const NOW = Date.parse('2026-10-13T12:00:00Z')

type Drain = typeof import('../pending-logout')

interface RecordedRequest {
  url: string
  method: string
  authorization: string | undefined
  proof: string | undefined
  body: unknown
  timeout: number | undefined
}

interface ScriptedAnswer {
  status: number
  data?: unknown
  headers?: Record<string, string>
}

type Script = (request: RecordedRequest, index: number) => Promise<ScriptedAnswer | 'network'> | ScriptedAnswer | 'network'

const requests: RecordedRequest[] = []
let script: Script
const realSetTimeout = globalThis.setTimeout.bind(globalThis)

/** Lets the drain's real asynchronous work, the key signing, run on; the fake clock does not move. */
async function settle(turns = 20): Promise<void> {
  for (let turn = 0; turn < turns; turn++) {
    await new Promise((resolve) => realSetTimeout(resolve, 0))
  }
}

/** Waits until `count` requests were sent, then lets the pass that sent the last one finish. */
async function untilRequests(count: number): Promise<void> {
  for (let turn = 0; turn < 500 && requests.length < count; turn++) {
    await new Promise((resolve) => realSetTimeout(resolve, 1))
  }
  await settle()
}
let db: FakeIndexedDb
let locks: FakeLockManager
let restoreLocks: () => void
let drain: Drain
let keyPair: DpopKeyPair
const originalAdapter = Axios.defaults.adapter

/** Answers every request from `script`; failures become the AxiosError the real adapter would raise. */
const scriptedAdapter: AxiosAdapter = async (config: InternalAxiosRequestConfig) => {
  const request: RecordedRequest = {
    url: config.url ?? '',
    method: (config.method ?? 'get').toUpperCase(),
    authorization: config.headers.get('Authorization') as string | undefined,
    proof: config.headers.get('DPoP') as string | undefined,
    body: typeof config.data === 'string' ? JSON.parse(config.data) : config.data,
    timeout: config.timeout,
  }
  requests.push(request)
  const answer = await script(request, requests.length - 1)
  if (answer === 'network') {
    throw new AxiosError('Network Error', AxiosError.ERR_NETWORK, config)
  }
  const response: AxiosResponse = {
    data: answer.data ?? {},
    status: answer.status,
    statusText: '',
    headers: answer.headers ?? {},
    config,
    request: {},
  }
  if (answer.status >= 200 && answer.status < 300) return response
  throw new AxiosError(
    `Request failed with status code ${answer.status}`,
    answer.status >= 500 ? AxiosError.ERR_BAD_RESPONSE : AxiosError.ERR_BAD_REQUEST,
    config,
    {},
    response,
  )
}

function base64url(input: string | ArrayBuffer): string {
  const bytes = typeof input === 'string' ? new TextEncoder().encode(input) : new Uint8Array(input)
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function fromBase64url(input: string): Uint8Array<ArrayBuffer> {
  const binary = atob(input.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(input.length / 4) * 4, '='))
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index)
  return bytes
}

/** An access token as the server mints it: only the claims matter here, the signature is never checked. */
function accessToken(claims: Record<string, unknown>): string {
  return `${base64url(JSON.stringify({ alg: 'RS256', typ: 'JWT' }))}.${base64url(JSON.stringify(claims))}.signature`
}

function liveToken(jkt = keyPair.jkt, id = 'live'): string {
  return accessToken({ jti: id, exp: Math.floor(NOW / 1000) + 3600, cnf: { jkt } })
}

function expiredToken(jkt = keyPair.jkt): string {
  return accessToken({ jti: 'expired', exp: Math.floor(NOW / 1000) - 60, cnf: { jkt } })
}

async function decodeProof(proof: string) {
  const [header, payload, signature] = proof.split('.')
  const verified = await crypto.subtle.verify(
    { name: 'ECDSA', hash: 'SHA-256' },
    keyPair.publicKey,
    fromBase64url(signature),
    new TextEncoder().encode(`${header}.${payload}`),
  )
  return {
    header: JSON.parse(new TextDecoder().decode(fromBase64url(header))),
    payload: JSON.parse(new TextDecoder().decode(fromBase64url(payload))),
    verified,
  }
}

async function ath(token: string): Promise<string> {
  return base64url(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(token)))
}

function refreshed(accessToken: string, refreshToken: string, headers: Record<string, string> = {}): ScriptedAnswer {
  return { status: 200, data: { data: { accessToken, refreshToken } }, headers }
}

const ok: ScriptedAnswer = { status: 200, data: { data: { message: 'Token revoked.' } } }
const unauthorized: ScriptedAnswer = { status: 401, data: { message: 'Not authenticated' } }

function entry(overrides: Partial<PendingLogoutEntry> = {}): PendingLogoutEntry {
  return {
    id: 'entry-1',
    createdAt: NOW - 1_000,
    keyPair,
    accessToken: liveToken(),
    refreshToken: 'refresh-1',
    accessTokenExpiresAt: NOW + 3_600_000,
    origin: API_ORIGIN,
    attempts: 0,
    ...overrides,
  }
}

async function storedIds(): Promise<string[]> {
  return (await listPendingLogouts()).map((stored) => stored.id)
}

beforeEach(async () => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
  vi.spyOn(Math, 'random').mockReturnValue(0)
  window.__BAANDER_API_URL__ = API_ORIGIN
  db = installIndexedDb().db
  locks = new FakeLockManager()
  restoreLocks = installLocks(locks)
  requests.length = 0
  log.warnings.length = 0
  script = () => ok
  Axios.defaults.adapter = scriptedAdapter
  keyPair = await generateDpopKeyPair()
  vi.resetModules()
  drain = await import('../pending-logout')
})

afterEach(async () => {
  // A pass started by a timer may still be signing; let it finish before the globals change.
  await settle()
  Axios.defaults.adapter = originalAdapter
  restoreLocks()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  vi.useRealTimers()
})

describe('recordLogout', () => {
  it('writes the entry and the tombstone in one transaction and resolves the tombstone epoch', async () => {
    const token = liveToken()
    db.seed('auth', { id: 'current', epoch: 3, cleared: false, accessToken: 'old' })
    db.log.length = 0

    await expect(drain.recordLogout({ keyPair, accessToken: token, refreshToken: 'refresh-1' })).resolves.toBe(4)

    expect(new Set(db.log.map((operation) => operation.transaction)).size).toBe(1)
    expect(db.log.map((operation) => [operation.operation, operation.key])).toEqual([
      ['get', 'current'],
      ['get', 'pending-logouts'],
      ['put', 'pending-logouts'],
      ['put', 'current'],
    ])
    expect(db.records('auth').get('current')).toEqual({ id: 'current', epoch: 4, cleared: true })
    const [stored] = await listPendingLogouts()
    expect(stored).toMatchObject({
      createdAt: NOW,
      accessToken: token,
      refreshToken: 'refresh-1',
      accessTokenExpiresAt: (Math.floor(NOW / 1000) + 3600) * 1000,
      origin: API_ORIGIN,
      attempts: 0,
    })
    expect(stored.id).toEqual(expect.any(String))
    expect(stored.keyPair.jkt).toBe(keyPair.jkt)
  })

  it('stores no expiry for a token without an exp claim', async () => {
    await drain.recordLogout({ keyPair, accessToken: accessToken({ jti: 'no-exp' }), refreshToken: 'refresh-1' })

    const [stored] = await listPendingLogouts()
    expect(stored.accessTokenExpiresAt).toBeNull()
  })

  it('resolves null and warns when the store cannot be written, so the logout still proceeds', async () => {
    vi.stubGlobal('indexedDB', {
      open: () => {
        const request = { error: new DOMException('Storage is unavailable', 'UnknownError'), onerror: null as null | (() => void), onsuccess: null }
        queueMicrotask(() => request.onerror?.())
        return request
      },
    })

    await expect(drain.recordLogout({ keyPair, accessToken: liveToken(), refreshToken: 'refresh-1' })).resolves.toBeNull()

    expect(log.warnings).toHaveLength(1)
  })
})

describe('drainPendingLogouts', () => {
  it('sends the logout once, signed with the entry key, and removes the entry on a 200', async () => {
    const token = liveToken()
    await beginStoredLogout(entry({ accessToken: token }))

    await drain.drainPendingLogouts()

    expect(requests).toHaveLength(1)
    expect(requests[0]).toMatchObject({ url: LOGOUT_URL, method: 'POST', authorization: `DPoP ${token}`, timeout: REFRESH_ATTEMPT_TIMEOUT_MS })
    const proof = await decodeProof(requests[0].proof!)
    expect(proof.verified).toBe(true)
    expect(proof.header).toMatchObject({ typ: 'dpop+jwt', alg: 'ES256', jwk: keyPair.jwk })
    expect(proof.payload).toMatchObject({ htm: 'POST', htu: LOGOUT_URL, ath: await ath(token) })
    await expect(storedIds()).resolves.toEqual([])
    expect(vi.getTimerCount()).toBe(0)
    expect(locks.isHeld()).toBe(false)
  })

  it('refreshes an expired access token first, persists the rotated tokens before the logout, and removes the entry', async () => {
    const stale = expiredToken()
    const fresh = liveToken(keyPair.jkt, 'fresh')
    await beginStoredLogout(entry({ accessToken: stale, accessTokenExpiresAt: NOW - 60_000 }))
    let storedAtLogout: PendingLogoutEntry | undefined
    script = async (request) => {
      if (request.url === REFRESH_URL) return refreshed(fresh, 'refresh-2', { 'dpop-nonce': 'nonce-1' })
      ;[storedAtLogout] = await listPendingLogouts()
      return ok
    }

    await drain.drainPendingLogouts()

    expect(requests.map((request) => request.url)).toEqual([REFRESH_URL, LOGOUT_URL])
    expect(requests[0]).toMatchObject({ body: { refreshToken: 'refresh-1' }, authorization: undefined, timeout: REFRESH_ATTEMPT_TIMEOUT_MS })
    const refreshProof = await decodeProof(requests[0].proof!)
    expect(refreshProof.verified).toBe(true)
    expect(refreshProof.payload).toMatchObject({ htm: 'POST', htu: REFRESH_URL })
    expect(refreshProof.payload.ath).toBeUndefined()
    expect(storedAtLogout).toMatchObject({ accessToken: fresh, refreshToken: 'refresh-2', accessTokenExpiresAt: (Math.floor(NOW / 1000) + 3600) * 1000 })
    expect(requests[1].authorization).toBe(`DPoP ${fresh}`)
    const logoutProof = await decodeProof(requests[1].proof!)
    expect(logoutProof.payload).toMatchObject({ ath: await ath(fresh), nonce: 'nonce-1' })
    await expect(storedIds()).resolves.toEqual([])
  })

  it('answers a logout 401 with a refresh and sends the logout again with the new token', async () => {
    const fresh = liveToken(keyPair.jkt, 'fresh')
    await beginStoredLogout(entry())
    script = (request, index) => {
      if (request.url === REFRESH_URL) return refreshed(fresh, 'refresh-2')
      return index === 0 ? unauthorized : ok
    }

    await drain.drainPendingLogouts()

    expect(requests.map((request) => request.url)).toEqual([LOGOUT_URL, REFRESH_URL, LOGOUT_URL])
    expect(requests[2].authorization).toBe(`DPoP ${fresh}`)
    await expect(storedIds()).resolves.toEqual([])
  })

  it('drops the entry without a warning when the chain is gone: logout 401, then refresh 401', async () => {
    await beginStoredLogout(entry())
    script = () => unauthorized

    await drain.drainPendingLogouts()

    expect(requests.map((request) => request.url)).toEqual([LOGOUT_URL, REFRESH_URL])
    await expect(storedIds()).resolves.toEqual([])
    expect(log.warnings).toEqual([])
    expect(vi.getTimerCount()).toBe(0)
  })

  it('drops the entry when the refresh answers invalid_grant', async () => {
    await beginStoredLogout(entry({ accessToken: expiredToken(), accessTokenExpiresAt: NOW - 1 }))
    script = () => ({ status: 400, data: { error: 'invalid_grant' } })

    await drain.drainPendingLogouts()

    expect(requests.map((request) => request.url)).toEqual([REFRESH_URL])
    await expect(storedIds()).resolves.toEqual([])
  })

  it('retries a refresh once after a nonce challenge, carrying the nonce', async () => {
    const fresh = liveToken(keyPair.jkt, 'fresh')
    await beginStoredLogout(entry({ accessToken: expiredToken(), accessTokenExpiresAt: NOW - 1 }))
    script = (request, index) => {
      if (request.url === REFRESH_URL && index === 0) {
        return { status: 400, data: { error: 'use_dpop_nonce' }, headers: { 'dpop-nonce': 'server-nonce' } }
      }
      return request.url === REFRESH_URL ? refreshed(fresh, 'refresh-2') : ok
    }

    await drain.drainPendingLogouts()

    expect(requests.map((request) => request.url)).toEqual([REFRESH_URL, REFRESH_URL, LOGOUT_URL])
    expect((await decodeProof(requests[1].proof!)).payload.nonce).toBe('server-nonce')
    await expect(storedIds()).resolves.toEqual([])
  })

  it('keeps the entry on a network failure, counts the attempt and retries on the backoff timer', async () => {
    await beginStoredLogout(entry())
    script = () => 'network'

    await drain.drainPendingLogouts()

    expect(requests).toHaveLength(1)
    await expect(listPendingLogouts()).resolves.toMatchObject([{ id: 'entry-1', attempts: 1 }])
    expect(vi.getTimerCount()).toBe(1)

    await vi.advanceTimersByTimeAsync(PENDING_LOGOUT_BACKOFF_MS - 1)
    await settle()
    expect(requests).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(1)
    await untilRequests(2)
    expect(requests).toHaveLength(2)
    await expect(listPendingLogouts()).resolves.toMatchObject([{ id: 'entry-1', attempts: 2 }])
  })

  it('keeps the entry on a logout 503 and waits out its Retry-After before the next attempt', async () => {
    await beginStoredLogout(entry())
    script = () => ({ status: 503, headers: { 'retry-after': '30' } })

    await drain.drainPendingLogouts()

    expect(requests).toHaveLength(1)
    await expect(storedIds()).resolves.toEqual(['entry-1'])
    await vi.advanceTimersByTimeAsync(29_999)
    await settle()
    expect(requests).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(1)
    await untilRequests(2)
    expect(requests).toHaveLength(2)
  })

  it('waits out a refresh 503 once under the lock and then logs out', async () => {
    const fresh = liveToken(keyPair.jkt, 'fresh')
    await beginStoredLogout(entry({ accessToken: expiredToken(), accessTokenExpiresAt: NOW - 1 }))
    script = (request, index) => {
      if (request.url === REFRESH_URL) {
        return index === 0 ? { status: 503, headers: { 'retry-after': '2' } } : refreshed(fresh, 'refresh-2')
      }
      return ok
    }

    const draining = drain.drainPendingLogouts()
    await untilRequests(1)
    expect(requests).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(2_000)
    await draining

    expect(requests.map((request) => request.url)).toEqual([REFRESH_URL, REFRESH_URL, LOGOUT_URL])
    await expect(storedIds()).resolves.toEqual([])
  })

  it('keeps the entry on a logout 400, 403, or a 401 that persists after a successful refresh', async () => {
    const fresh = liveToken(keyPair.jkt, 'fresh')
    await beginStoredLogout(entry({ id: 'bad-request', accessToken: liveToken(keyPair.jkt, 'a') }))
    await beginStoredLogout(entry({ id: 'forbidden', createdAt: NOW - 900, accessToken: liveToken(keyPair.jkt, 'b') }))
    await beginStoredLogout(entry({ id: 'still-401', createdAt: NOW - 800, accessToken: liveToken(keyPair.jkt, 'c') }))
    script = (request) => {
      if (request.url === REFRESH_URL) return refreshed(fresh, 'refresh-2')
      if (request.authorization === `DPoP ${liveToken(keyPair.jkt, 'a')}`) return { status: 400 }
      if (request.authorization === `DPoP ${liveToken(keyPair.jkt, 'b')}`) return { status: 403 }
      return unauthorized
    }

    await drain.drainPendingLogouts()

    expect(requests.map((request) => request.url)).toEqual([LOGOUT_URL, LOGOUT_URL, LOGOUT_URL, REFRESH_URL, LOGOUT_URL])
    await expect(listPendingLogouts()).resolves.toMatchObject([
      { id: 'bad-request', attempts: 1 },
      { id: 'forbidden', attempts: 1 },
      { id: 'still-401', attempts: 1, accessToken: fresh, refreshToken: 'refresh-2' },
    ])
    expect(vi.getTimerCount()).toBe(1)
  })

  it('drops an entry of another origin without a request', async () => {
    await beginStoredLogout(entry({ origin: 'https://other.baander.app' }))

    await drain.drainPendingLogouts()

    expect(requests).toEqual([])
    await expect(storedIds()).resolves.toEqual([])
  })

  it('drops an entry whose key read back empty', async () => {
    db.seed('auth', {
      id: 'pending-logouts',
      entries: [{
        id: 'keyless', createdAt: NOW - 1_000, publicJwk: keyPair.jwk, jkt: keyPair.jkt,
        accessToken: liveToken(), refreshToken: 'refresh-1', accessTokenExpiresAt: null, origin: API_ORIGIN, attempts: 0,
      }],
    })

    await drain.drainPendingLogouts()

    expect(requests).toEqual([])
    await expect(storedIds()).resolves.toEqual([])
  })

  it('drops an entry whose key cannot sign', async () => {
    await beginStoredLogout(entry({ keyPair: { ...keyPair, privateKey: keyPair.publicKey } }))

    await drain.drainPendingLogouts()

    expect(requests).toEqual([])
    await expect(storedIds()).resolves.toEqual([])
  })

  it('drops an entry whose key thumbprint differs from the access token binding', async () => {
    await beginStoredLogout(entry({ accessToken: liveToken('another-thumbprint') }))

    await drain.drainPendingLogouts()

    expect(requests).toEqual([])
    await expect(storedIds()).resolves.toEqual([])
  })

  it('sends the oldest entry beyond the limit once and drops it with a warning, whatever the answer', async () => {
    for (let index = 0; index < 21; index++) {
      await beginStoredLogout(entry({ id: `entry-${index}`, createdAt: NOW - 100_000 + index, accessToken: liveToken(keyPair.jkt, `t${index}`) }))
    }
    script = () => ({ status: 503, headers: { 'retry-after': '1' } })

    await drain.drainPendingLogouts()

    expect(requests).toHaveLength(21)
    expect(requests[0].authorization).toBe(`DPoP ${liveToken(keyPair.jkt, 't0')}`)
    const remaining = await storedIds()
    expect(remaining).toHaveLength(20)
    expect(remaining).not.toContain('entry-0')
    expect(log.warnings).toHaveLength(1)
  })

  it('never writes the credential record and never adopts an entry as the session', async () => {
    const fresh = liveToken(keyPair.jkt, 'fresh')
    await beginStoredLogout(entry({ accessToken: expiredToken(), accessTokenExpiresAt: NOW - 1 }))
    const tombstone = structuredClone(db.records('auth').get('current'))
    db.log.length = 0
    script = (request) => request.url === REFRESH_URL ? refreshed(fresh, 'refresh-2') : ok

    await drain.drainPendingLogouts()

    expect(db.log.filter((operation) => operation.key === 'current').map((operation) => operation.operation)).toEqual([])
    expect(db.records('auth').get('current')).toEqual(tombstone)
    expect(getDpopKeyPair()).toBeNull()
  })

  it('skips an entry while another page holds the auth lock, and does not send it twice', async () => {
    await beginStoredLogout(entry())
    const release = locks.hold()

    const draining = drain.drainPendingLogouts()
    await vi.advanceTimersByTimeAsync(PENDING_LOGOUT_LOCK_WAIT_MS)
    await draining

    expect(requests).toEqual([])
    await expect(listPendingLogouts()).resolves.toMatchObject([{ id: 'entry-1', attempts: 0 }])
    release()
  })

  it('re-reads the entry under the lock and sends nothing when another page removed it meanwhile', async () => {
    await beginStoredLogout(entry())
    const release = locks.hold()

    const draining = drain.drainPendingLogouts()
    await vi.advanceTimersByTimeAsync(PENDING_LOGOUT_LOCK_WAIT_MS / 2)
    db.records('auth').set('pending-logouts', { id: 'pending-logouts', entries: [] })
    release()
    await draining

    expect(requests).toEqual([])
    expect(vi.getTimerCount()).toBe(0)
  })

  it('makes at most eight timer-driven attempts per page session and stops the timer once the list is empty', async () => {
    await beginStoredLogout(entry())
    script = () => 'network'

    await drain.drainPendingLogouts()
    for (let attempt = 0; attempt < PENDING_LOGOUT_TIMER_ATTEMPTS; attempt++) {
      expect(vi.getTimerCount()).toBe(1)
      await vi.advanceTimersByTimeAsync(PENDING_LOGOUT_BACKOFF_MS * 2 ** attempt)
      await untilRequests(2 + attempt)
    }
    await vi.advanceTimersByTimeAsync(30 * 60 * 1000)
    await settle()

    expect(requests).toHaveLength(1 + PENDING_LOGOUT_TIMER_ATTEMPTS)
    expect(vi.getTimerCount()).toBe(0)
    await expect(storedIds()).resolves.toEqual(['entry-1'])

    script = () => ok
    await drain.drainPendingLogouts()
    await expect(storedIds()).resolves.toEqual([])
    expect(vi.getTimerCount()).toBe(0)
  })

  it('resets the backoff when a trigger runs a drain', async () => {
    await beginStoredLogout(entry())
    script = () => 'network'

    await drain.drainPendingLogouts()
    await vi.advanceTimersByTimeAsync(PENDING_LOGOUT_BACKOFF_MS)
    await untilRequests(2)
    expect(requests).toHaveLength(2)
    // The timer now backs off to twice the base delay; a trigger starts over at the base.
    await drain.drainPendingLogouts()
    expect(requests).toHaveLength(3)
    await vi.advanceTimersByTimeAsync(PENDING_LOGOUT_BACKOFF_MS)
    await untilRequests(4)
    expect(requests).toHaveLength(4)
  })
  it('caps a logout 503 Retry-After at the backoff cap, so a proxy delay of hours cannot park the timer', async () => {
    await beginStoredLogout(entry())
    script = () => ({ status: 503, headers: { 'retry-after': '86400' } })

    await drain.drainPendingLogouts()

    expect(requests).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(1)
    await vi.advanceTimersByTimeAsync(PENDING_LOGOUT_BACKOFF_CAP_MS)
    await untilRequests(2)
    expect(requests).toHaveLength(2)
  })

  it('starts the timer budget over for a later logout once the list emptied', async () => {
    await beginStoredLogout(entry())
    script = () => 'network'
    await drain.drainPendingLogouts()
    for (let attempt = 0; attempt < PENDING_LOGOUT_TIMER_ATTEMPTS; attempt++) {
      await vi.advanceTimersByTimeAsync(PENDING_LOGOUT_BACKOFF_MS * 2 ** attempt)
      await untilRequests(2 + attempt)
    }
    expect(vi.getTimerCount()).toBe(0)
    script = () => ok
    await drain.drainPendingLogouts()
    await expect(storedIds()).resolves.toEqual([])

    // A new logout after the exhausted run gets its own timer-driven retries.
    await beginStoredLogout(entry({ id: 'entry-2' }))
    script = () => 'network'
    await drain.drainPendingLogouts()

    expect(vi.getTimerCount()).toBe(1)
    const sent = requests.length
    await vi.advanceTimersByTimeAsync(PENDING_LOGOUT_BACKOFF_MS)
    await untilRequests(sent + 1)
    expect(requests).toHaveLength(sent + 1)
  })

  it('runs a fresh pass for a trigger that lands while the previous pass is being scheduled', async () => {
    await beginStoredLogout(entry())
    script = () => 'network'

    const first = drain.drainPendingLogouts()
    await untilRequests(1)
    // The pass has made its request and is settling; a trigger now must not be lost.
    const second = drain.drainPendingLogouts()
    await first
    await second
    await settle()

    expect(requests.length).toBeGreaterThanOrEqual(2)
    expect(vi.getTimerCount()).toBe(1)
  })
})

describe('sendLogoutOnce', () => {
  it('sends one logout signed with the session key and resolves on a 200', async () => {
    const token = liveToken()

    await drain.sendLogoutOnce({ keyPair, accessToken: token })

    expect(requests).toHaveLength(1)
    expect(requests[0]).toMatchObject({ url: LOGOUT_URL, method: 'POST', authorization: `DPoP ${token}`, timeout: REFRESH_ATTEMPT_TIMEOUT_MS })
    const proof = await decodeProof(requests[0].proof!)
    expect(proof.verified).toBe(true)
    expect(proof.payload).toMatchObject({ htm: 'POST', htu: LOGOUT_URL, ath: await ath(token) })
    expect(log.warnings).toHaveLength(0)
    await expect(storedIds()).resolves.toEqual([])
    expect(vi.getTimerCount()).toBe(0)
  })

  it('warns and resolves when the one attempt fails, keeping no entry and no timer', async () => {
    script = () => 'network'

    await drain.sendLogoutOnce({ keyPair, accessToken: liveToken() })

    expect(requests).toHaveLength(1)
    expect(log.warnings).toHaveLength(1)
    await expect(storedIds()).resolves.toEqual([])
    expect(vi.getTimerCount()).toBe(0)
  })

  it('answers one nonce challenge and then resolves', async () => {
    script = (_request, index) => index === 0
      ? { status: 400, data: { error: 'use_dpop_nonce' }, headers: { 'dpop-nonce': 'n-1' } }
      : ok

    await drain.sendLogoutOnce({ keyPair, accessToken: liveToken() })

    expect(requests).toHaveLength(2)
    const proof = await decodeProof(requests[1].proof!)
    expect(proof.payload).toMatchObject({ nonce: 'n-1' })
    expect(log.warnings).toHaveLength(0)
  })
})

describe('installPendingLogoutTriggers', () => {
  it('drains on online and on becoming visible, and stops after the disposer ran', async () => {
    await beginStoredLogout(entry())
    script = () => 'network'
    const dispose = drain.installPendingLogoutTriggers()

    window.dispatchEvent(new Event('online'))
    await untilRequests(1)
    expect(requests).toHaveLength(1)

    Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true })
    document.dispatchEvent(new Event('visibilitychange'))
    await settle()
    expect(requests).toHaveLength(1)

    Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
    document.dispatchEvent(new Event('visibilitychange'))
    await untilRequests(2)
    expect(requests).toHaveLength(2)

    dispose()
    expect(vi.getTimerCount()).toBe(0)
    window.dispatchEvent(new Event('online'))
    await settle()
    expect(requests).toHaveLength(2)
  })
})
