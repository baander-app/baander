import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { create } from 'zustand'
import { withStoreDebug } from '@/shared/stores/debug'
import { type LoginRequest, postAuthLogout, postAuthRegister } from '@/shared/api-client/gen/endpoints'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { AxiosRequestConfig } from 'axios'
import { postTokenToWorker } from '@/features/player/services/service-worker-bridge'
import { generateDpopKeyPair } from '@/shared/crypto/dpop-key-pair'
import { getDpopKeyPair, setDpopKeyPair, clearDpopKeyPair, getDpopNonce, setDpopNonce } from '@/shared/crypto/dpop-store'
import { loadStoredAuth, saveStoredAuth, clearStoredAuth } from '@/shared/crypto/auth-db'
import { createLogger } from '@/shared/lib/logger'

interface User {
  uuid: string
  email: string
  publicId: string
  name: string | null
  roles: string[]
}

interface AuthState {
  accessToken: string | null
  refreshToken: string | null
  user: User | null
  isAuthenticated: boolean
  isLoading: boolean
  login: (email: string, password: string, totpCode?: string, honeypot?: string) => Promise<void>
  register: (email: string, password: string, name?: string) => Promise<void>
  logout: () => Promise<void>
  setTokens: (accessToken: string, refreshToken: string) => void
  updateUser: (updates: Partial<User>) => void
  clearAuth: () => void
  initAuth: () => Promise<void>
}

const logger = createLogger('AuthStore')
function isMockAuth() { return import.meta.env.VITE_MOCK_AUTH === 'true' }

export const useAuthStore = create<AuthState>()(withStoreDebug('auth.auth', withNoopGuard((set, get) => {
  let generation = 0
  // Serialize IndexedDB transactions so a clear cannot be overtaken by an older
  // credential write. Keys remain non-exportable and are stored only in auth-db.
  let persistence = Promise.resolve()
  const enqueue = (work: () => Promise<void>) => {
    const result = persistence.then(work)
    persistence = result.catch(() => {})
    return result
  }
  const persistCurrent = (owner: number) => enqueue(async () => {
    if (owner !== generation) return
    const keyPair = getDpopKeyPair(), state = get()
    if (!keyPair || !state.user || !state.accessToken || !state.refreshToken) return
    await saveStoredAuth({
      cryptoKeyPair: { publicKey: keyPair.publicKey, privateKey: keyPair.privateKey },
      publicJwk: keyPair.jwk, jkt: keyPair.jkt, accessToken: state.accessToken,
      refreshToken: state.refreshToken, user: state.user, nonce: getDpopNonce(),
    })
  })
  const pushToken = (token: string | null) => postTokenToWorker(token).catch((error) => {
    logger.warn('Failed to push token to service worker:', error)
  })
  return {
    accessToken: null, refreshToken: null, user: null, isAuthenticated: false, isLoading: false,

    login: async (email, password, totpCode, honeypot) => {
      if (get().isLoading) throw new Error('Login already in progress')
      const owner = ++generation
      set({ isLoading: true })
      try {
        if (isMockAuth()) {
          await new Promise((resolve) => setTimeout(resolve, 300))
          if (owner !== generation) return
          set({ accessToken: 'mock-access-token', refreshToken: 'mock-refresh-token',
            user: { uuid: '1', email, publicId: 'usr_abc123', name: null, roles: ['ROLE_USER'] }, isAuthenticated: true })
          return
        }
        const keyPair = await generateDpopKeyPair()
        if (owner !== generation) return
        setDpopKeyPair(keyPair)
        const request = { email, password, totpCode: totpCode ?? '', ...(honeypot ? { username: honeypot } : {}) } satisfies LoginRequest
        const response = await AXIOS_INSTANCE.post('/api/auth/login', request, {
          headers: { 'Content-Type': 'application/json' }, _skipAuth: true,
        } as AxiosRequestConfig)
        if (owner !== generation) return
        const tokens = response.data?.data
        if (!tokens?.accessToken || !tokens?.refreshToken) throw new Error('Login response missing required token fields')
        set({ accessToken: tokens.accessToken, refreshToken: tokens.refreshToken, user: tokens.user as User, isAuthenticated: true })
        // Use this request's key rather than relying on a later global key read.
        await enqueue(async () => {
          if (owner !== generation) return
          await saveStoredAuth({
            cryptoKeyPair: { publicKey: keyPair.publicKey, privateKey: keyPair.privateKey },
            publicJwk: keyPair.jwk, jkt: keyPair.jkt, accessToken: tokens.accessToken,
            refreshToken: tokens.refreshToken, user: tokens.user as User, nonce: getDpopNonce(),
          })
        })
        if (owner === generation) pushToken(tokens.accessToken)
      } finally {
        if (owner === generation) set({ isLoading: false })
      }
    },

    register: async (email, password, name) => {
      if (get().isLoading) throw new Error('Authentication already in progress')
      const owner = ++generation
      set({ isLoading: true })
      try {
        if (isMockAuth()) await new Promise((resolve) => setTimeout(resolve, 300))
        else await postAuthRegister({ email, password, name: name ?? '' })
      } finally {
        if (owner === generation) set({ isLoading: false })
      }
    },

    logout: async () => {
      const owner = ++generation
      set({ isLoading: true })
      try {
        if (isMockAuth()) await new Promise((resolve) => setTimeout(resolve, 100))
        else await postAuthLogout()
      } finally {
        if (owner === generation) get().clearAuth()
      }
    },

    setTokens: (accessToken, refreshToken) => {
      const state = get()
      if (state.accessToken === accessToken && state.refreshToken === refreshToken && state.isAuthenticated) return
      // A refresh within the same session does not cancel an in-flight logout.
      if (!state.isAuthenticated) generation++
      set({ accessToken, refreshToken, isAuthenticated: true })
      persistCurrent(generation).catch((error) => { logger.warn('Failed to persist updated tokens:', error) })
      pushToken(accessToken)
    },

    updateUser: (updates) => {
      const user = get().user
      if (!user || Object.entries(updates).every(([key, value]) => user[key as keyof User] === value)) return
      set({ user: { ...user, ...updates } })
      persistCurrent(generation).catch((error) => { logger.warn('Failed to persist updated user:', error) })
    },

    clearAuth: () => {
      generation++
      clearDpopKeyPair()
      set((state) => !state.isAuthenticated && !state.isLoading && state.accessToken === null && state.refreshToken === null && state.user === null
        ? state : { accessToken: null, refreshToken: null, user: null, isAuthenticated: false, isLoading: false })
      enqueue(clearStoredAuth).catch((error) => { logger.warn('Failed to clear stored auth:', error) })
      pushToken(null)
    },

    initAuth: async () => {
      if (get().isLoading || get().isAuthenticated) return
      const owner = generation
      try {
        // A queued clear must finish before reading durable credentials.
        await persistence
        if (owner !== generation || get().isLoading || get().isAuthenticated) return
        const stored = await loadStoredAuth()
        if (!stored || owner !== generation || get().isAuthenticated) return
        setDpopKeyPair({ publicKey: stored.cryptoKeyPair.publicKey, privateKey: stored.cryptoKeyPair.privateKey,
          jwk: stored.publicJwk, jkt: stored.jkt })
        if (stored.nonce) setDpopNonce(stored.nonce)
        set({ accessToken: stored.accessToken, refreshToken: stored.refreshToken, user: stored.user as User, isAuthenticated: true })
        await pushToken(stored.accessToken)
      } catch (error) {
        if (owner === generation) logger.warn('Failed to load stored auth, starting unauthenticated:', error)
      }
    },
  }
})))

/** Read tokens outside React for interceptors and service worker messaging. */
export function getAuthSnapshot() { return useAuthStore.getState() }
