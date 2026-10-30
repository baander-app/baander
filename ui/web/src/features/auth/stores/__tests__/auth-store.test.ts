import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { FakeBroadcastChannel, FakeLockManager, installLocks } from '../../../../../tests/shared/auth/auth-fakes'
import type { LegacyStoredAuthData, LegacyStoredRecord } from '@/shared/crypto/auth-db'

// The store subscribes to the auth channel when it is created, so the fake must exist before the import.
vi.hoisted(async () => {
  const fakes = await import('../../../../../tests/shared/auth/auth-fakes')
  Object.assign(globalThis, { BroadcastChannel: fakes.FakeBroadcastChannel })
})

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn().mockResolvedValue({ data: {} }),
    delete: vi.fn(),
  },
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  postAuthRegister: vi.fn().mockResolvedValue({ data: {} }),
}))

vi.mock('@/features/player/services/service-worker-bridge', () => ({
  postTokenToWorker: vi.fn().mockResolvedValue(undefined),
  initWorkerApiUrl: vi.fn().mockResolvedValue(undefined),
  initServiceWorkerListener: vi.fn(),
}))

vi.mock('@/shared/crypto/dpop-key-pair', () => ({
  generateDpopKeyPair: vi.fn(),
}))

/** The in-memory DPoP cache, so adoption can compare the held key with the stored one. */
const dpop = vi.hoisted(() => ({
  keyPair: null as { publicKey: CryptoKey; privateKey: CryptoKey; jwk: JsonWebKey; jkt: string } | null,
  nonce: null as string | null,
}))

vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: vi.fn(() => dpop.keyPair),
  setDpopKeyPair: vi.fn((pair) => { dpop.keyPair = pair }),
  clearDpopKeyPair: vi.fn(() => { dpop.keyPair = null }),
  getDpopNonce: vi.fn(() => dpop.nonce),
  setDpopNonce: vi.fn((nonce: string) => { dpop.nonce = nonce }),
}))

/** One origin's record and its pending logouts, with the epoch rules of the IndexedDB adapter. */
const storage = vi.hoisted(() => ({
  record: null as LegacyStoredRecord | null,
  pendingLogouts: [] as Array<{ id: string; keyPair: unknown; accessToken: string; refreshToken: string }>,
}))

vi.mock('@/shared/crypto/auth-db', () => ({
  loadStoredAuth: vi.fn(async () => storage.record),
  saveStoredAuth: vi.fn(async (data: LegacyStoredAuthData, expectedEpoch: number | null) => {
    const currentEpoch = storage.record?.epoch ?? null
    if (currentEpoch !== expectedEpoch) return { ok: false, current: storage.record }
    const epoch = (expectedEpoch ?? 0) + 1
    storage.record = { ...data, epoch }
    return { ok: true, epoch }
  }),
  clearStoredAuth: vi.fn(async () => {
    const epoch = (storage.record?.epoch ?? 0) + 1
    storage.record = { epoch, cleared: true }
    return epoch
  }),
  isClearedRecord: (record: LegacyStoredRecord) => 'cleared' in record && record.cleared === true,
}))

/**
 * The delivery module: recording appends the entry and leaves the tombstone, as the storage does
 * in one transaction; the drain never settles here, since a logout must not wait for it.
 */
vi.mock('@/features/auth/services/pending-logout', () => ({
  recordLogout: vi.fn(async (credentials: { keyPair: unknown; accessToken: string; refreshToken: string }) => {
    const epoch = (storage.record?.epoch ?? 0) + 1
    storage.record = { epoch, cleared: true }
    storage.pendingLogouts.push({ id: `entry-${storage.pendingLogouts.length + 1}`, ...credentials })
    return epoch
  }),
  drainPendingLogouts: vi.fn(() => new Promise<void>(() => {})),
  sendLogoutOnce: vi.fn(async () => {}),
}))

vi.mock('@/shared/lib/logger', () => ({
  createLogger: () => ({
    debug: vi.fn(),
    info: vi.fn(),
    warn: vi.fn(),
    error: vi.fn(),
  }),
}))

import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { drainPendingLogouts, recordLogout, sendLogoutOnce } from '@/features/auth/services/pending-logout'
import { postTokenToWorker } from '@/features/player/services/service-worker-bridge'
import { generateDpopKeyPair } from '@/shared/crypto/dpop-key-pair'
import {
  setDpopKeyPair,
  clearDpopKeyPair,
  setDpopNonce,
} from '@/shared/crypto/dpop-store'
import {
  loadStoredAuth,
  saveStoredAuth,
  clearStoredAuth,
} from '@/shared/crypto/auth-db'
import { AUTH_CHANNEL_NAME, AUTH_LOCK_NAME } from '@/features/auth/services/auth-coordination'
import { useAuthStore } from '../auth-store'

const mockAxios = vi.mocked(AXIOS_INSTANCE)
const mockGenerateDpopKeyPair = vi.mocked(generateDpopKeyPair)
const mockSaveStoredAuth = vi.mocked(saveStoredAuth)
const mockClearStoredAuth = vi.mocked(clearStoredAuth)
const mockLoadStoredAuth = vi.mocked(loadStoredAuth)
const mockPostTokenToWorker = vi.mocked(postTokenToWorker)
const mockSetDpopKeyPair = vi.mocked(setDpopKeyPair)
const mockClearDpopKeyPair = vi.mocked(clearDpopKeyPair)
const mockSetDpopNonce = vi.mocked(setDpopNonce)
const mockRecordLogout = vi.mocked(recordLogout)
const mockSendLogoutOnce = vi.mocked(sendLogoutOnce)
const mockDrainPendingLogouts = vi.mocked(drainPendingLogouts)

const FAKE_KEY_PAIR = {
  publicKey: {} as CryptoKey,
  privateKey: {} as CryptoKey,
  jwk: { kty: 'EC' } as JsonWebKey,
  jkt: 'fake-jkt',
}

const VALID_USER = {
  email: 'test@baander.app',
  publicId: 'usr_abc123',
  name: null,
  roles: ['ROLE_USER'],
}

const LOGIN_PAGE = 'https://baander.app/library'

function validLoginResponse() {
  return {
    data: {
      data: {
        accessToken: 'access-token-123',
        refreshToken: 'refresh-token-456',
        user: VALID_USER,
      },
    },
  }
}

/** A live record as another tab would have written it. */
function liveRecord(epoch: number, accessToken: string, overrides: Partial<LegacyStoredAuthData> = {}) {
  return {
    epoch,
    cryptoKeyPair: { publicKey: FAKE_KEY_PAIR.publicKey, privateKey: FAKE_KEY_PAIR.privateKey },
    publicJwk: FAKE_KEY_PAIR.jwk,
    jkt: FAKE_KEY_PAIR.jkt,
    accessToken,
    refreshToken: `${accessToken}-refresh`,
    user: VALID_USER,
    nonce: null,
    ...overrides,
  }
}

/** Signs the tab in from a stored record, as a page load does. */
async function hydrateFrom(record: LegacyStoredRecord) {
  storage.record = record
  await useAuthStore.getState().initAuth()
  expect(useAuthStore.getState().isAuthenticated).toBe(true)
  vi.clearAllMocks()
}

function postedEvents() {
  return FakeBroadcastChannel.posted.filter((entry) => entry.name === AUTH_CHANNEL_NAME).map((entry) => entry.message)
}

function deliverFromOtherTab(message: object) {
  FakeBroadcastChannel.deliver(AUTH_CHANNEL_NAME, message)
}

let locks: FakeLockManager
let restoreLocks: () => void
let location: { href: string; origin: string; pathname: string }

function resetStore() {
  useAuthStore.getState().clearAuth()
  useAuthStore.setState({
    accessToken: null,
    refreshToken: null,
    user: null,
    isAuthenticated: false,
    isLoading: false,
  })
}

beforeEach(async () => {
  resetStore()
  await vi.waitFor(() => expect(mockClearStoredAuth).toHaveBeenCalled())
  vi.clearAllMocks()
  storage.record = null
  storage.pendingLogouts = []
  dpop.keyPair = null
  dpop.nonce = null
  FakeBroadcastChannel.posted = []
  locks = new FakeLockManager()
  restoreLocks = installLocks(locks)
  location = { href: LOGIN_PAGE, origin: 'https://baander.app', pathname: '/library' }
  vi.stubGlobal('location', location)

  mockAxios.post.mockResolvedValue({ data: {} })
  mockGenerateDpopKeyPair.mockResolvedValue(FAKE_KEY_PAIR)
})

afterEach(() => {
  restoreLocks()
  vi.unstubAllGlobals()
})

describe('useAuthStore', () => {
  describe('login', () => {
    it('sets tokens/user/isAuthenticated and persists auth + posts token to worker on valid response', async () => {
      mockAxios.post.mockResolvedValue(validLoginResponse())

      await useAuthStore.getState().login('test@baander.app', 'secret')

      const state = useAuthStore.getState()
      expect(state.accessToken).toBe('access-token-123')
      expect(state.refreshToken).toBe('refresh-token-456')
      expect(state.user).toEqual(VALID_USER)
      expect(state.isAuthenticated).toBe(true)
      expect(state.isLoading).toBe(false)

      // DPoP key generation ran before the network call.
      expect(mockGenerateDpopKeyPair).toHaveBeenCalledOnce()
      // Key pair was stashed in the DPoP store before persisting.
      expect(mockSetDpopKeyPair).toHaveBeenCalledWith(FAKE_KEY_PAIR)
      // Auth persisted to IndexedDB with token + key material, at the epoch of an empty store.
      expect(mockSaveStoredAuth).toHaveBeenCalledOnce()
      expect(mockSaveStoredAuth).toHaveBeenCalledWith(
        expect.objectContaining({
          accessToken: 'access-token-123',
          refreshToken: 'refresh-token-456',
          publicJwk: FAKE_KEY_PAIR.jwk,
          jkt: FAKE_KEY_PAIR.jkt,
        }),
        null,
      )
      expect(storage.record).toMatchObject({ epoch: 1, accessToken: 'access-token-123' })
      // Token pushed to the service worker.
      expect(mockPostTokenToWorker).toHaveBeenCalledWith('access-token-123')
    })

    it('writes through the auth lock and tells the other tabs', async () => {
      mockAxios.post.mockResolvedValue(validLoginResponse())

      await useAuthStore.getState().login('test@baander.app', 'secret')

      expect(locks.requests).toEqual([{ name: AUTH_LOCK_NAME, outcome: 'granted' }])
      expect(postedEvents()).toEqual([{ type: 'rotated', epoch: 1 }])
    })

    it('writes over the tombstone a logout left, at its epoch plus one', async () => {
      storage.record = { epoch: 5, cleared: true }
      mockAxios.post.mockResolvedValue(validLoginResponse())

      await useAuthStore.getState().login('test@baander.app', 'secret')

      expect(mockSaveStoredAuth).toHaveBeenCalledWith(expect.objectContaining({ accessToken: 'access-token-123' }), 5)
      expect(storage.record).toMatchObject({ epoch: 6, accessToken: 'access-token-123' })
      expect(postedEvents()).toEqual([{ type: 'rotated', epoch: 6 }])
      expect(useAuthStore.getState().isAuthenticated).toBe(true)
    })

    it('hits the re-entrancy guard and refuses a second concurrent login', async () => {
      // First call: never resolves until we want it to, keeping isLoading true.
      let resolveFirst!: (value: unknown) => void
      mockAxios.post.mockReturnValueOnce(
        new Promise((resolve) => {
          resolveFirst = resolve
        }),
      )
      mockGenerateDpopKeyPair.mockResolvedValue(FAKE_KEY_PAIR)

      const firstCall = useAuthStore.getState().login('a@baander.app', 'pw')

      // Let the microtask queue drain so `isLoading` is set before the second call.
      await Promise.resolve()
      expect(useAuthStore.getState().isLoading).toBe(true)

      const beforeSecondCall = mockAxios.post.mock.calls.length

      // Second concurrent login attempt.
      await expect(useAuthStore.getState().login('c@baander.app', 'pw')).rejects.toThrow(
        'Login already in progress',
      )

      // The guard threw before issuing another request.
      expect(mockAxios.post.mock.calls.length).toBe(beforeSecondCall)

      // Cleanup: let the first login finish.
      resolveFirst(validLoginResponse())
      await firstCall
      expect(useAuthStore.getState().isLoading).toBe(false)
    })

    it('throws and resets isLoading when the response is missing token fields', async () => {
      // Missing accessToken + refreshToken.
      mockAxios.post.mockResolvedValue({
        data: { data: { user: VALID_USER } },
      })
      mockGenerateDpopKeyPair.mockResolvedValue(FAKE_KEY_PAIR)

      await expect(
        useAuthStore.getState().login('test@baander.app', 'secret'),
      ).rejects.toThrow('Login response missing required token fields')

      // The finally block resets isLoading even on failure.
      expect(useAuthStore.getState().isLoading).toBe(false)
      expect(useAuthStore.getState().isAuthenticated).toBe(false)
      expect(useAuthStore.getState().accessToken).toBeNull()

      // Auth must not have been persisted for an incomplete response.
      expect(mockSaveStoredAuth).not.toHaveBeenCalled()
    })

    it('resets isLoading when the network request rejects', async () => {
      mockAxios.post.mockRejectedValue(new Error('network down'))
      mockGenerateDpopKeyPair.mockResolvedValue(FAKE_KEY_PAIR)

      await expect(
        useAuthStore.getState().login('test@baander.app', 'secret'),
      ).rejects.toThrow('network down')

      expect(useAuthStore.getState().isLoading).toBe(false)
      expect(useAuthStore.getState().isAuthenticated).toBe(false)
    })
  })

  describe('logout', () => {
    it('records the entry with the tombstone, clears the tab, broadcasts the tombstone epoch and starts a drain it does not wait for', async () => {
      await hydrateFrom(liveRecord(3, 'held-access'))

      await useAuthStore.getState().logout()

      const state = useAuthStore.getState()
      expect(state.accessToken).toBeNull()
      expect(state.refreshToken).toBeNull()
      expect(state.user).toBeNull()
      expect(state.isAuthenticated).toBe(false)
      expect(state.isLoading).toBe(false)

      expect(mockRecordLogout).toHaveBeenCalledOnce()
      expect(mockRecordLogout).toHaveBeenCalledWith({
        keyPair: expect.objectContaining({ jkt: FAKE_KEY_PAIR.jkt }),
        accessToken: 'held-access',
        refreshToken: 'held-access-refresh',
      })
      expect(storage.pendingLogouts).toEqual([expect.objectContaining({ accessToken: 'held-access' })])
      expect(storage.record).toEqual({ epoch: 4, cleared: true })
      expect(mockClearStoredAuth).not.toHaveBeenCalled()
      expect(postedEvents()).toEqual([{ type: 'cleared', epoch: 4 }])
      expect(JSON.stringify(FakeBroadcastChannel.posted)).not.toContain('held-access')

      expect(mockClearDpopKeyPair).toHaveBeenCalledOnce()
      expect(mockPostTokenToWorker).toHaveBeenCalledWith(null)
      expect(mockDrainPendingLogouts).toHaveBeenCalledOnce()
      expect(mockDrainPendingLogouts.mock.invocationCallOrder[0]).toBeGreaterThan(mockRecordLogout.mock.invocationCallOrder[0])
    })

    it('sends the logout once before clearing, tombstone and broadcast included, when the entry could not be recorded', async () => {
      await hydrateFrom(liveRecord(3, 'held-access'))
      mockRecordLogout.mockResolvedValueOnce(null)
      let signedInWhenSent: boolean | null = null
      mockSendLogoutOnce.mockImplementationOnce(async () => {
        signedInWhenSent = useAuthStore.getState().isAuthenticated
      })

      await useAuthStore.getState().logout()

      expect(mockSendLogoutOnce).toHaveBeenCalledOnce()
      expect(mockSendLogoutOnce).toHaveBeenCalledWith({ keyPair: expect.anything(), accessToken: 'held-access' })
      expect(signedInWhenSent).toBe(true)
      expect(useAuthStore.getState().isAuthenticated).toBe(false)
      expect(storage.pendingLogouts).toEqual([])
      await vi.waitFor(() => expect(postedEvents()).toEqual([{ type: 'cleared', epoch: 4 }]))
      expect(mockClearStoredAuth).toHaveBeenCalledOnce()
      expect(storage.record).toEqual({ epoch: 4, cleared: true })
      expect(mockDrainPendingLogouts).toHaveBeenCalledOnce()
    })

    it('records nothing when the tab holds no credentials and clears as before', async () => {
      useAuthStore.setState({ accessToken: null, refreshToken: null, user: null, isAuthenticated: false })

      await useAuthStore.getState().logout()

      expect(mockRecordLogout).not.toHaveBeenCalled()
      expect(storage.pendingLogouts).toEqual([])
      await vi.waitFor(() => expect(mockClearStoredAuth).toHaveBeenCalledOnce())
      expect(useAuthStore.getState().isLoading).toBe(false)
    })

    it('leaves the entry in place when a later login starts a new session', async () => {
      await hydrateFrom(liveRecord(3, 'held-access'))
      await useAuthStore.getState().logout()
      mockAxios.post.mockResolvedValue(validLoginResponse())

      await useAuthStore.getState().login('test@baander.app', 'secret')

      expect(useAuthStore.getState().accessToken).toBe('access-token-123')
      expect(useAuthStore.getState().isAuthenticated).toBe(true)
      expect(storage.record).toMatchObject({ epoch: 5, accessToken: 'access-token-123' })
      expect(storage.pendingLogouts).toEqual([expect.objectContaining({ accessToken: 'held-access' })])
    })

    it('keeps the clear when a sibling tab\'s rotation is adopted while the entry is being recorded', async () => {
      await hydrateFrom(liveRecord(1, 'r1-access'))
      let release!: () => void
      const original = mockRecordLogout.getMockImplementation()!
      mockRecordLogout.mockImplementationOnce(async (credentials) => {
        await new Promise<void>((yes) => { release = yes })
        return original(credentials)
      })
      const logout = useAuthStore.getState().logout()
      await vi.waitFor(() => expect(mockRecordLogout).toHaveBeenCalledOnce())
      storage.record = liveRecord(2, 'r2-access')

      deliverFromOtherTab({ type: 'rotated', epoch: 2 })

      await vi.waitFor(() => expect(useAuthStore.getState().accessToken).toBe('r2-access'))
      release()
      await logout

      const state = useAuthStore.getState()
      expect(state.isAuthenticated).toBe(false)
      expect(state.accessToken).toBeNull()
      expect(state.isLoading).toBe(false)
      expect(storage.pendingLogouts).toEqual([expect.objectContaining({ accessToken: 'r1-access', refreshToken: 'r1-access-refresh' })])
      expect(storage.record).toEqual({ epoch: 3, cleared: true })
      expect(postedEvents()).toContainEqual({ type: 'cleared', epoch: 3 })
    })
  })

  describe('clearAuth', () => {
    it('clears the credentials held by this tab in the service worker', () => {
      useAuthStore.setState({ accessToken: 'old', refreshToken: 'old-refresh' })
      useAuthStore.getState().clearAuth()
      expect(mockPostTokenToWorker).toHaveBeenCalledWith(null)
    })

    it('clears DPoP key pair, state, and IndexedDB', async () => {
      useAuthStore.setState({
        accessToken: 'tok',
        refreshToken: 'ref',
        user: VALID_USER,
        isAuthenticated: true,
      })

      useAuthStore.getState().clearAuth()

      const state = useAuthStore.getState()
      expect(state.accessToken).toBeNull()
      expect(state.refreshToken).toBeNull()
      expect(state.user).toBeNull()
      expect(state.isAuthenticated).toBe(false)

      expect(mockClearDpopKeyPair).toHaveBeenCalledOnce()
      await vi.waitFor(() => expect(mockClearStoredAuth).toHaveBeenCalledOnce())
    })

    it('writes the tombstone without waiting for the lock and broadcasts the epoch, never a token', async () => {
      await hydrateFrom(liveRecord(3, 'held-access'))
      locks.hold()

      useAuthStore.getState().clearAuth()

      await vi.waitFor(() => expect(postedEvents()).toEqual([{ type: 'cleared', epoch: 4 }]))
      expect(storage.record).toEqual({ epoch: 4, cleared: true })
      expect(locks.requests).toEqual([{ name: AUTH_LOCK_NAME, outcome: 'granted' }])
      expect(JSON.stringify(FakeBroadcastChannel.posted)).not.toContain('held-access')
    })
  })

  describe('initAuth', () => {
    it('is a no-op when no auth is stored (leaves state unauthenticated, does not throw)', async () => {
      storage.record = null

      await useAuthStore.getState().initAuth()

      const state = useAuthStore.getState()
      expect(state.accessToken).toBeNull()
      expect(state.refreshToken).toBeNull()
      expect(state.user).toBeNull()
      expect(state.isAuthenticated).toBe(false)

      // No DPoP restoration attempted when nothing is stored.
      expect(mockSetDpopKeyPair).not.toHaveBeenCalled()
      expect(mockSetDpopNonce).not.toHaveBeenCalled()
    })

    it('stays signed out when a logout left a tombstone', async () => {
      storage.record = { epoch: 2, cleared: true }

      await useAuthStore.getState().initAuth()

      expect(useAuthStore.getState().isAuthenticated).toBe(false)
      expect(mockSetDpopKeyPair).not.toHaveBeenCalled()
    })

    it('reconstructs DPoP key pair, restores nonce, and authenticates when stored auth exists', async () => {
      const stored = {
        epoch: 1,
        cryptoKeyPair: {
          publicKey: {} as CryptoKey,
          privateKey: {} as CryptoKey,
        },
        publicJwk: { kty: 'EC' } as JsonWebKey,
        jkt: 'stored-jkt',
        accessToken: 'restored-access',
        refreshToken: 'restored-refresh',
        user: VALID_USER,
        nonce: 'restored-nonce',
      }
      storage.record = stored

      await useAuthStore.getState().initAuth()

      expect(mockSetDpopKeyPair).toHaveBeenCalledWith({
        publicKey: stored.cryptoKeyPair.publicKey,
        privateKey: stored.cryptoKeyPair.privateKey,
        jwk: stored.publicJwk,
        jkt: stored.jkt,
      })
      expect(mockSetDpopNonce).toHaveBeenCalledWith('restored-nonce')

      const state = useAuthStore.getState()
      expect(state.accessToken).toBe('restored-access')
      expect(state.refreshToken).toBe('restored-refresh')
      expect(state.user).toEqual(VALID_USER)
      expect(state.isAuthenticated).toBe(true)
      expect(mockPostTokenToWorker).toHaveBeenCalledWith('restored-access')
    })

    it('does not throw when loadStoredAuth rejects (logs and stays unauthenticated)', async () => {
      mockLoadStoredAuth.mockRejectedValueOnce(new Error('IndexedDB unavailable'))

      await expect(useAuthStore.getState().initAuth()).resolves.toBeUndefined()

      const state = useAuthStore.getState()
      expect(state.isAuthenticated).toBe(false)
      expect(state.accessToken).toBeNull()
    })
  })
})

describe('adoptStoredAuth under the refresh lock', () => {
  it('adopts the tokens and nonce another tab rotated and keeps the shared key', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = liveRecord(2, 'r2-access', { nonce: 'nonce-after-rotation' })

    await expect(useAuthStore.getState().adoptStoredAuth()).resolves.toBe(true)

    const state = useAuthStore.getState()
    expect(state.accessToken).toBe('r2-access')
    expect(state.refreshToken).toBe('r2-access-refresh')
    expect(state.isAuthenticated).toBe(true)
    expect(mockSetDpopNonce).toHaveBeenCalledWith('nonce-after-rotation')
    expect(mockSetDpopKeyPair).not.toHaveBeenCalled()
    expect(mockPostTokenToWorker).toHaveBeenCalledWith('r2-access')
    expect(mockAxios.post).not.toHaveBeenCalled()
    expect(mockSaveStoredAuth).not.toHaveBeenCalled()
  })

  it('adopts nothing when the stored epoch is the one it holds', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))

    await expect(useAuthStore.getState().adoptStoredAuth()).resolves.toBe(false)

    expect(useAuthStore.getState().accessToken).toBe('r1-access')
    expect(mockPostTokenToWorker).not.toHaveBeenCalled()
  })

  it('takes over another account signed in elsewhere, key pair included', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    const otherKey = { publicKey: {} as CryptoKey, privateKey: {} as CryptoKey }
    storage.record = liveRecord(2, 'other-access', {
      cryptoKeyPair: otherKey,
      jkt: 'other-jkt',
      user: { ...VALID_USER, publicId: 'user-2', email: 'other@baander.app' },
    })

    await expect(useAuthStore.getState().adoptStoredAuth()).resolves.toBe(true)

    expect(mockSetDpopKeyPair).toHaveBeenCalledWith({
      publicKey: otherKey.publicKey,
      privateKey: otherKey.privateKey,
      jwk: FAKE_KEY_PAIR.jwk,
      jkt: 'other-jkt',
    })
    expect(useAuthStore.getState().user?.email).toBe('other@baander.app')
  })

  it('syncs its epoch but reports no rotation when the stored tokens are its own', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = liveRecord(2, 'r1-access', { user: { ...VALID_USER, name: 'Renamed' } })

    await expect(useAuthStore.getState().adoptStoredAuth()).resolves.toBe(false)

    expect(useAuthStore.getState().user?.name).toBe('Renamed')
    await useAuthStore.getState().setTokens('r2-access', 'r2-refresh')
    expect(mockSaveStoredAuth).toHaveBeenCalledWith(expect.objectContaining({ accessToken: 'r2-access' }), 2)
  })

  it('adopts the tombstone another tab left: signs out without calling logout and lands on the login page', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = { epoch: 2, cleared: true }

    await expect(useAuthStore.getState().adoptStoredAuth()).resolves.toBe(true)

    const state = useAuthStore.getState()
    expect(state.isAuthenticated).toBe(false)
    expect(state.accessToken).toBeNull()
    expect(mockClearDpopKeyPair).toHaveBeenCalledOnce()
    expect(mockRecordLogout).not.toHaveBeenCalled()
    expect(mockClearStoredAuth).not.toHaveBeenCalled()
    expect(mockPostTokenToWorker).toHaveBeenCalledWith(null)
    expect(location.href).toBe('/login')
  })

  it('treats a missing record as newer than the epoch it holds', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = null

    await expect(useAuthStore.getState().adoptStoredAuth()).resolves.toBe(true)

    expect(useAuthStore.getState().isAuthenticated).toBe(false)
    expect(location.href).toBe('/login')
  })

  it('adopts the login tab A made after a logout this tab never heard of, calling neither refresh nor logout', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = liveRecord(3, 'after-relogin-access')

    await expect(useAuthStore.getState().adoptStoredAuth()).resolves.toBe(true)

    expect(useAuthStore.getState().accessToken).toBe('after-relogin-access')
    expect(mockAxios.post).not.toHaveBeenCalled()
    expect(mockRecordLogout).not.toHaveBeenCalled()
    await useAuthStore.getState().setTokens('next-access', 'next-refresh')
    expect(mockSaveStoredAuth).toHaveBeenCalledWith(expect.objectContaining({ accessToken: 'next-access' }), 3)
  })
})

describe('setTokens', () => {
  it('writes at the held epoch, moves to the new one and tells the other tabs', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))

    await useAuthStore.getState().setTokens('r2-access', 'r2-refresh')

    expect(mockSaveStoredAuth).toHaveBeenCalledWith(
      expect.objectContaining({ accessToken: 'r2-access', refreshToken: 'r2-refresh', jkt: FAKE_KEY_PAIR.jkt }),
      1,
    )
    expect(storage.record).toMatchObject({ epoch: 2, accessToken: 'r2-access' })
    expect(postedEvents()).toEqual([{ type: 'rotated', epoch: 2 }])
    expect(mockPostTokenToWorker).toHaveBeenCalledWith('r2-access')

    await useAuthStore.getState().setTokens('r3-access', 'r3-refresh')
    expect(mockSaveStoredAuth).toHaveBeenLastCalledWith(expect.objectContaining({ accessToken: 'r3-access' }), 2)
  })

  it('cannot resurrect a session another tab cleared: the write fails against the tombstone and the tab clears', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = { epoch: 2, cleared: true }

    await useAuthStore.getState().setTokens('r2-access', 'r2-refresh')

    expect(storage.record).toEqual({ epoch: 2, cleared: true })
    expect(useAuthStore.getState().isAuthenticated).toBe(false)
    expect(useAuthStore.getState().accessToken).toBeNull()
    expect(postedEvents()).toEqual([])
    expect(mockPostTokenToWorker).toHaveBeenLastCalledWith(null)
  })

  it('cannot overwrite a newer record with an older epoch: it adopts the stored one instead', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = liveRecord(4, 'newer-access')

    await useAuthStore.getState().setTokens('stale-access', 'stale-refresh')

    expect(storage.record).toMatchObject({ epoch: 4, accessToken: 'newer-access' })
    expect(useAuthStore.getState().accessToken).toBe('newer-access')
    expect(postedEvents()).toEqual([])
  })
})

describe('events from other tabs', () => {
  it('clears on `cleared` when the record is tombstoned at that epoch, without calling the server', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = { epoch: 2, cleared: true }

    deliverFromOtherTab({ type: 'cleared', epoch: 2 })

    await vi.waitFor(() => expect(useAuthStore.getState().isAuthenticated).toBe(false))
    expect(useAuthStore.getState().accessToken).toBeNull()
    expect(mockRecordLogout).not.toHaveBeenCalled()
    expect(mockAxios.post).not.toHaveBeenCalled()
    expect(mockClearStoredAuth).not.toHaveBeenCalled()
    expect(mockPostTokenToWorker).toHaveBeenCalledWith(null)
    expect(location.href).toBe('/login')
  })

  it('clears on `cleared` when the record is gone', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = null

    deliverFromOtherTab({ type: 'cleared', epoch: 2 })

    await vi.waitFor(() => expect(useAuthStore.getState().isAuthenticated).toBe(false))
    expect(location.href).toBe('/login')
  })

  it('ignores a `cleared` whose epoch is older than the stored record and adopts the newer login', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = liveRecord(3, 'relogin-access')

    deliverFromOtherTab({ type: 'cleared', epoch: 2 })

    await vi.waitFor(() => expect(useAuthStore.getState().accessToken).toBe('relogin-access'))
    expect(useAuthStore.getState().isAuthenticated).toBe(true)
    expect(location.href).toBe(LOGIN_PAGE)
  })

  it('keeps a tombstone older than the `cleared` epoch from clearing the tab', async () => {
    await hydrateFrom(liveRecord(5, 'r1-access'))
    storage.record = { epoch: 3, cleared: true }

    deliverFromOtherTab({ type: 'cleared', epoch: 6 })

    await vi.waitFor(() => expect(mockLoadStoredAuth).toHaveBeenCalled())
    await Promise.resolve()
    expect(useAuthStore.getState().isAuthenticated).toBe(true)
    expect(location.href).toBe(LOGIN_PAGE)
  })

  it('ignores `cleared` when it holds no session', async () => {
    storage.record = { epoch: 2, cleared: true }

    deliverFromOtherTab({ type: 'cleared', epoch: 2 })
    await Promise.resolve()

    expect(mockPostTokenToWorker).not.toHaveBeenCalled()
    expect(location.href).toBe(LOGIN_PAGE)
  })

  it('adopts the rotation a `rotated` event announces', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))
    storage.record = liveRecord(2, 'r2-access')

    deliverFromOtherTab({ type: 'rotated', epoch: 2 })

    await vi.waitFor(() => expect(useAuthStore.getState().accessToken).toBe('r2-access'))
    expect(mockSaveStoredAuth).not.toHaveBeenCalled()
    expect(mockPostTokenToWorker).toHaveBeenCalledWith('r2-access')
  })
})

describe('auth request ownership', () => {
  it('does not restore keys or tokens from hydration after clearAuth', async () => {
    let resolve!: (value: Awaited<ReturnType<typeof loadStoredAuth>>) => void
    mockLoadStoredAuth.mockReturnValueOnce(new Promise((yes) => { resolve = yes }))
    const hydrate = useAuthStore.getState().initAuth()
    await vi.waitFor(() => expect(mockLoadStoredAuth).toHaveBeenCalled())
    useAuthStore.getState().clearAuth()
    resolve(liveRecord(1, 'old'))
    await hydrate
    expect(useAuthStore.getState().isAuthenticated).toBe(false)
    expect(mockSetDpopKeyPair).not.toHaveBeenCalled()
    expect(mockPostTokenToWorker).not.toHaveBeenCalledWith('old')
  })

  it('does not restore a cleared session from an older login response', async () => {
    let resolve!: (value: unknown) => void
    mockAxios.post.mockReturnValueOnce(new Promise((yes) => { resolve = yes }))
    const login = useAuthStore.getState().login('user@baander.app', 'secret')
    await vi.waitFor(() => expect(mockAxios.post).toHaveBeenCalled())
    useAuthStore.getState().clearAuth()
    resolve(validLoginResponse())
    await login
    expect(useAuthStore.getState().accessToken).toBeNull()
    expect(useAuthStore.getState().isLoading).toBe(false)
    expect(mockSaveStoredAuth).not.toHaveBeenCalled()
    expect(mockPostTokenToWorker).not.toHaveBeenCalledWith('access-token-123')
  })

  it('lets a clear win over an in-flight credential write: the write fails against the tombstone', async () => {
    let release!: () => void
    const original = mockSaveStoredAuth.getMockImplementation()!
    mockSaveStoredAuth.mockImplementationOnce(async (data, expected) => {
      await new Promise<void>((yes) => { release = yes })
      return original(data, expected)
    })
    mockAxios.post.mockResolvedValue(validLoginResponse())
    const login = useAuthStore.getState().login('user@baander.app', 'secret')
    await vi.waitFor(() => expect(mockSaveStoredAuth).toHaveBeenCalledOnce())
    useAuthStore.getState().clearAuth()
    await vi.waitFor(() => expect(mockClearStoredAuth).toHaveBeenCalledOnce())
    release()
    await login.catch(() => {})
    expect(storage.record).toEqual({ epoch: 1, cleared: true })
    expect(useAuthStore.getState().accessToken).toBeNull()
    expect(mockPostTokenToWorker).not.toHaveBeenCalledWith('access-token-123')
  })

  it('keeps repeated token and user updates notification-free', async () => {
    useAuthStore.setState({ accessToken: 'access', refreshToken: 'refresh', user: VALID_USER, isAuthenticated: true })
    const notify = vi.fn(), unsubscribe = useAuthStore.subscribe(notify)
    await useAuthStore.getState().setTokens('access', 'refresh')
    useAuthStore.getState().updateUser({ email: VALID_USER.email })
    expect(notify).not.toHaveBeenCalled()
    expect(mockPostTokenToWorker).not.toHaveBeenCalled()
    unsubscribe()
  })

  it('does not leave a login loading when a rotation is adopted before its credentials are written', async () => {
    let resolve!: (value: unknown) => void
    mockAxios.post.mockReturnValueOnce(new Promise((yes) => { resolve = yes }))
    const login = useAuthStore.getState().login('user@baander.app', 'secret')
    await vi.waitFor(() => expect(mockAxios.post).toHaveBeenCalled())
    storage.record = liveRecord(2, 'r2-access')

    deliverFromOtherTab({ type: 'rotated', epoch: 2 })

    await vi.waitFor(() => expect(useAuthStore.getState().accessToken).toBe('r2-access'))
    resolve(validLoginResponse())
    await login.catch(() => {})

    const state = useAuthStore.getState()
    expect(state.isLoading).toBe(false)
    expect(state.accessToken).toBe('r2-access')
    expect(state.isAuthenticated).toBe(true)
  })

  it('persists a user update under the lock at the held epoch', async () => {
    await hydrateFrom(liveRecord(1, 'r1-access'))

    useAuthStore.getState().updateUser({ name: 'Renamed' })

    await vi.waitFor(() => expect(mockSaveStoredAuth).toHaveBeenCalledOnce())
    expect(mockSaveStoredAuth).toHaveBeenCalledWith(expect.objectContaining({ user: { ...VALID_USER, name: 'Renamed' } }), 1)
    expect(locks.requests).toEqual([{ name: AUTH_LOCK_NAME, outcome: 'granted' }])
  })
})


describe('principal ownership', () => {
  it('keeps the principal session across token and stored-record rotations', async () => {
    await hydrateFrom(liveRecord(1, 'initial'))
    const principal = useAuthStore.getState().principalSessionId
    expect(principal).toEqual(expect.any(String))

    await useAuthStore.getState().setTokens('rotated', 'rotated-refresh')
    expect(useAuthStore.getState().principalSessionId).toBe(principal)
    storage.record = liveRecord(3, 'sibling-rotation')
    await useAuthStore.getState().adoptStoredAuth()
    expect(useAuthStore.getState().principalSessionId).toBe(principal)
  })

  it('replaces ownership for a different user even when the DPoP key is retained', async () => {
    await hydrateFrom(liveRecord(1, 'initial'))
    const principal = useAuthStore.getState().principalSessionId
    storage.record = liveRecord(2, 'replacement', {
      user: { ...VALID_USER, publicId: 'usr_other', email: 'other@baander.app' },
    })
    await useAuthStore.getState().adoptStoredAuth()
    expect(useAuthStore.getState().principalSessionId).toEqual(expect.any(String))
    expect(useAuthStore.getState().principalSessionId).not.toBe(principal)
    expect(mockSetDpopKeyPair).not.toHaveBeenCalled()
  })

  it('clears ownership on logout and creates a new session for a later login', async () => {
    await hydrateFrom(liveRecord(1, 'initial'))
    const principal = useAuthStore.getState().principalSessionId
    useAuthStore.getState().clearAuth()
    expect(useAuthStore.getState().principalSessionId).toBeNull()
    await vi.waitFor(() => expect(mockClearStoredAuth).toHaveBeenCalled())
    mockAxios.post.mockResolvedValue(validLoginResponse())
    await useAuthStore.getState().login(VALID_USER.email, 'secret')
    expect(useAuthStore.getState().principalSessionId).toEqual(expect.any(String))
    expect(useAuthStore.getState().principalSessionId).not.toBe(principal)
  })
})


it('does not adopt a stored session that finishes loading after local logout', async () => {
  await hydrateFrom(liveRecord(1, 'initial'))
  let finish!: (record: LegacyStoredRecord) => void
  mockLoadStoredAuth.mockReturnValueOnce(new Promise(resolve => { finish = resolve }))
  const adoption = useAuthStore.getState().adoptStoredAuth()
  useAuthStore.getState().clearAuth()
  finish(liveRecord(2, 'late-rotation'))

  await expect(adoption).resolves.toBe(false)
  expect(useAuthStore.getState().isAuthenticated).toBe(false)
  expect(useAuthStore.getState().principalSessionId).toBeNull()
})
