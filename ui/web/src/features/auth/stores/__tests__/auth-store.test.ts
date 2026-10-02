import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn().mockResolvedValue({ data: {} }),
    delete: vi.fn(),
  },
}))

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  postAuthLogout: vi.fn().mockResolvedValue({ data: {} }),
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

vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: vi.fn(() => null),
  setDpopKeyPair: vi.fn(),
  clearDpopKeyPair: vi.fn(),
  getDpopNonce: vi.fn(() => null),
  setDpopNonce: vi.fn(),
}))

vi.mock('@/shared/crypto/auth-db', () => ({
  loadStoredAuth: vi.fn().mockResolvedValue(null),
  saveStoredAuth: vi.fn().mockResolvedValue(undefined),
  clearStoredAuth: vi.fn().mockResolvedValue(undefined),
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
import { postAuthLogout } from '@/shared/api-client/gen/endpoints'
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
const mockPostAuthLogout = vi.mocked(postAuthLogout)

const FAKE_KEY_PAIR = {
  publicKey: {} as CryptoKey,
  privateKey: {} as CryptoKey,
  jwk: { kty: 'EC' } as JsonWebKey,
  jkt: 'fake-jkt',
}

const VALID_USER = {
  uuid: 'user-1',
  email: 'test@example.com',
  publicId: 'usr_abc123',
  name: null,
  roles: ['ROLE_USER'],
}

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

describe('useAuthStore', () => {
  beforeEach(() => {
    vi.clearAllMocks()

    // Reset store state between tests — drives every action via getState().
    useAuthStore.setState({
      accessToken: null,
      refreshToken: null,
      user: null,
      isAuthenticated: false,
      isLoading: false,
    })

    // Defaults
    mockAxios.post.mockResolvedValue({ data: {} })
    mockGenerateDpopKeyPair.mockResolvedValue(FAKE_KEY_PAIR)
    mockPostAuthLogout.mockResolvedValue({ data: {} } as any)
    mockLoadStoredAuth.mockResolvedValue(null)
  })

  describe('login', () => {
    it('sets tokens/user/isAuthenticated and persists auth + posts token to worker on valid response', async () => {
      mockAxios.post.mockResolvedValue(validLoginResponse())

      await useAuthStore.getState().login('test@example.com', 'secret')

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
      // Auth persisted to IndexedDB with token + key material.
      expect(mockSaveStoredAuth).toHaveBeenCalledOnce()
      expect(mockSaveStoredAuth).toHaveBeenCalledWith(
        expect.objectContaining({
          accessToken: 'access-token-123',
          refreshToken: 'refresh-token-456',
          publicJwk: FAKE_KEY_PAIR.jwk,
          jkt: FAKE_KEY_PAIR.jkt,
        }),
      )
      // Token pushed to the service worker.
      expect(mockPostTokenToWorker).toHaveBeenCalledWith('access-token-123')
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

      const firstCall = useAuthStore.getState().login('a@b.com', 'pw')

      // Let the microtask queue drain so `isLoading` is set before the second call.
      await Promise.resolve()
      expect(useAuthStore.getState().isLoading).toBe(true)

      const beforeSecondCall = mockAxios.post.mock.calls.length

      // Second concurrent login attempt.
      await expect(useAuthStore.getState().login('c@d.com', 'pw')).rejects.toThrow(
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
        useAuthStore.getState().login('test@example.com', 'secret'),
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
        useAuthStore.getState().login('test@example.com', 'secret'),
      ).rejects.toThrow('network down')

      expect(useAuthStore.getState().isLoading).toBe(false)
      expect(useAuthStore.getState().isAuthenticated).toBe(false)
    })
  })

  describe('logout', () => {
    it('clears auth (tokens/user/isAuthenticated) via clearAuth', async () => {
      // Seed authenticated state.
      useAuthStore.setState({
        accessToken: 'stale',
        refreshToken: 'stale-r',
        user: VALID_USER,
        isAuthenticated: true,
      })

      await useAuthStore.getState().logout()

      const state = useAuthStore.getState()
      expect(state.accessToken).toBeNull()
      expect(state.refreshToken).toBeNull()
      expect(state.user).toBeNull()
      expect(state.isAuthenticated).toBe(false)
      expect(state.isLoading).toBe(false)

      // clearAuth cleared both the DPoP key pair and IndexedDB.
      expect(mockClearDpopKeyPair).toHaveBeenCalledOnce()
      expect(mockClearStoredAuth).toHaveBeenCalledOnce()
    })

    it('clears auth even when the logout network call rejects', async () => {
      useAuthStore.setState({
        accessToken: 'stale',
        refreshToken: 'stale-r',
        user: VALID_USER,
        isAuthenticated: true,
      })
      mockPostAuthLogout.mockRejectedValue(new Error('logout endpoint down'))

      // logout's inner try/finally clears auth even when postAuthLogout rejects;
      // the rejection still propagates to the caller, so expect it.
      await expect(useAuthStore.getState().logout()).rejects.toThrow('logout endpoint down')

      const state = useAuthStore.getState()
      expect(state.accessToken).toBeNull()
      expect(state.refreshToken).toBeNull()
      expect(state.user).toBeNull()
      expect(state.isAuthenticated).toBe(false)
      expect(state.isLoading).toBe(false)

      expect(mockClearDpopKeyPair).toHaveBeenCalledOnce()
      expect(mockClearStoredAuth).toHaveBeenCalledOnce()
    })
  })

  describe('clearAuth', () => {
    it('clears the credentials held by this tab in the service worker', () => {
      useAuthStore.setState({ accessToken: 'old', refreshToken: 'old-refresh' })
      useAuthStore.getState().clearAuth()
      expect(mockPostTokenToWorker).toHaveBeenCalledWith(null)
    })
    it('clears DPoP key pair, state, and IndexedDB', () => {
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
      expect(mockClearStoredAuth).toHaveBeenCalledOnce()
    })
  })

  describe('initAuth', () => {
    it('is a no-op when no auth is stored (leaves state unauthenticated, does not throw)', async () => {
      mockLoadStoredAuth.mockResolvedValue(null)

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

    it('reconstructs DPoP key pair, restores nonce, and authenticates when stored auth exists', async () => {
      const stored = {
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
      mockLoadStoredAuth.mockResolvedValue(stored)

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
      mockLoadStoredAuth.mockRejectedValue(new Error('IndexedDB unavailable'))

      await expect(useAuthStore.getState().initAuth()).resolves.toBeUndefined()

      const state = useAuthStore.getState()
      expect(state.isAuthenticated).toBe(false)
      expect(state.accessToken).toBeNull()
    })
  })
})
