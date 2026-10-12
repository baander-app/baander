import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { create } from 'zustand'
import { withStoreDebug } from '@/shared/stores/debug'
import { type LoginRequest, postAuthLogout, postAuthRegister } from '@/shared/api-client/gen/endpoints'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { AxiosRequestConfig } from 'axios'
import { postTokenToWorker } from '@/features/player/services/service-worker-bridge'
import { generateDpopKeyPair } from '@/shared/crypto/dpop-key-pair'
import { getDpopKeyPair, setDpopKeyPair, clearDpopKeyPair, getDpopNonce, setDpopNonce } from '@/shared/crypto/dpop-store'
import {
  clearStoredAuth,
  isClearedRecord,
  loadStoredAuth,
  saveStoredAuth,
  type LegacyStoredAuth,
  type LegacyStoredAuthData,
  type LegacyStoredRecord,
} from '@/shared/crypto/auth-db'
import { publishAuthEvent, subscribeAuthEvents, withAuthLock, type AuthEvent } from '@/features/auth/services/auth-coordination'
import { REFRESH_BUDGET_MS } from '@/shared/auth/refresh-budget'
import { createLogger } from '@/shared/lib/logger'

export interface User {
  uuid: string
  email: string
  publicId: string
  name: string | null
  roles: string[]
  /**
   * When the current address was verified; null while it is unverified. Absent when not yet
   * known, as for a mock login, until the profile is read from /api/auth/me.
   */
  emailVerifiedAt?: string | null
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
  /**
   * Adopts a rotation and persists it. The caller holds the auth lock (the refresh runs inside it);
   * the returned promise settles when the record's write has completed, so the lock is held until then.
   */
  setTokens: (accessToken: string, refreshToken: string) => Promise<void>
  updateUser: (updates: Partial<User>) => void
  clearAuth: () => void
  /**
   * Re-reads the stored record under the auth lock and adopts it when its epoch differs from the held
   * one. Resolves true when the tab now holds other tokens, or none; false when a refresh is still due.
   */
  adoptStoredAuth: () => Promise<boolean>
  initAuth: () => Promise<void>
}

const logger = createLogger('AuthStore')
function isMockAuth() { return import.meta.env.VITE_MOCK_AUTH === 'true' }

const CLEARED_STATE = { accessToken: null, refreshToken: null, user: null, isAuthenticated: false, isLoading: false }

/** Where a tab goes once another tab ended the session; the server was already told by that tab. */
function landOnLoginPage() {
  window.location.href = '/login'
}

export const useAuthStore = create<AuthState>()(withStoreDebug('auth.auth', withNoopGuard((set, get) => {
  let generation = 0
  // The epoch of the record this tab's credentials came from; null while it holds none.
  let heldEpoch: number | null = null

  const pushToken = (token: string | null) => postTokenToWorker(token).catch((error) => {
    logger.warn('Failed to push token to service worker:', error)
  })
  const holdsSession = () => heldEpoch !== null || get().isAuthenticated

  /** Takes the stored record as this tab's credentials; the shared key is kept when it is the same key. */
  const applyRecord = (record: LegacyStoredAuth) => {
    generation++
    const held = getDpopKeyPair()
    if (!held || held.jkt !== record.jkt) {
      setDpopKeyPair({ publicKey: record.cryptoKeyPair.publicKey, privateKey: record.cryptoKeyPair.privateKey,
        jwk: record.publicJwk, jkt: record.jkt })
    }
    if (record.nonce) setDpopNonce(record.nonce)
    heldEpoch = record.epoch
    set({ accessToken: record.accessToken, refreshToken: record.refreshToken, user: record.user as User, isAuthenticated: true })
    pushToken(record.accessToken)
  }

  /** Another tab ended the session: drop the credentials without writing and leave the app. */
  const applyCleared = () => {
    generation++
    heldEpoch = null
    clearDpopKeyPair()
    set(CLEARED_STATE)
    pushToken(null)
    landOnLoginPage()
  }

  /**
   * Adopts the stored record when its epoch differs from the held one, in either direction; a missing
   * record is newer than anything held. A tombstone from a `cleared` event only counts at that event's
   * epoch or later. Resolves true when this tab's access token changed or its session ended.
   */
  const adoptRecord = (record: LegacyStoredRecord | null, clearedAtOrAfter?: number): boolean => {
    if (record === null || isClearedRecord(record)) {
      if (!holdsSession()) return false
      if (record !== null && clearedAtOrAfter !== undefined && record.epoch < clearedAtOrAfter) return false
      applyCleared()
      return true
    }
    if (record.epoch === heldEpoch) return false
    const rotated = record.accessToken !== get().accessToken
    applyRecord(record)
    return rotated
  }

  /** Compare-and-put at `expectedEpoch`; on a mismatch the stored record wins and is adopted. */
  const writeRecord = async (expectedEpoch: number | null, data: LegacyStoredAuthData): Promise<boolean> => {
    const result = await saveStoredAuth(data, expectedEpoch)
    if (result.ok) {
      heldEpoch = result.epoch
      publishAuthEvent({ type: 'rotated', epoch: result.epoch })
      return true
    }
    adoptRecord(result.current)
    return false
  }

  /** Writes this tab's current credentials at the held epoch; the caller holds the auth lock. */
  const persistCurrent = async (owner: number) => {
    if (owner !== generation) return
    const keyPair = getDpopKeyPair(), state = get()
    if (!keyPair || !state.user || !state.accessToken || !state.refreshToken) return
    await writeRecord(heldEpoch, {
      cryptoKeyPair: { publicKey: keyPair.publicKey, privateKey: keyPair.privateKey },
      publicJwk: keyPair.jwk, jkt: keyPair.jkt, accessToken: state.accessToken,
      refreshToken: state.refreshToken, user: state.user, nonce: getDpopNonce(),
    })
  }

  const onAuthEvent = async (event: AuthEvent) => {
    if (event.type === 'cleared' && !holdsSession()) return
    const record = await loadStoredAuth()
    adoptRecord(record, event.type === 'cleared' ? event.epoch : undefined)
  }
  subscribeAuthEvents((event) => {
    onAuthEvent(event).catch((error) => { logger.warn('Failed to re-read stored auth after a tab event:', error) })
  })

  return {
    ...CLEARED_STATE,

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
        // A login writes over whatever the origin holds, a tombstone included, at its epoch plus one.
        // Use this request's key rather than relying on a later global key read.
        const accepted = await withAuthLock(REFRESH_BUDGET_MS, async () => {
          if (owner !== generation) return true
          const current = await loadStoredAuth()
          if (owner !== generation) return true
          return writeRecord(current?.epoch ?? null, {
            cryptoKeyPair: { publicKey: keyPair.publicKey, privateKey: keyPair.privateKey },
            publicJwk: keyPair.jwk, jkt: keyPair.jkt, accessToken: tokens.accessToken,
            refreshToken: tokens.refreshToken, user: tokens.user as User, nonce: getDpopNonce(),
          })
        })
        if (!accepted) throw new Error('Signed out in another tab while logging in')
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
      if (state.accessToken === accessToken && state.refreshToken === refreshToken && state.isAuthenticated) return Promise.resolve()
      // A refresh within the same session does not cancel an in-flight logout.
      if (!state.isAuthenticated) generation++
      set({ accessToken, refreshToken, isAuthenticated: true })
      pushToken(accessToken)
      return persistCurrent(generation)
    },

    updateUser: (updates) => {
      const user = get().user
      if (!user || Object.entries(updates).every(([key, value]) => user[key as keyof User] === value)) return
      set({ user: { ...user, ...updates } })
      const owner = generation
      withAuthLock(REFRESH_BUDGET_MS, () => persistCurrent(owner))
        .catch((error) => { logger.warn('Failed to persist updated user:', error) })
    },

    clearAuth: () => {
      generation++
      heldEpoch = null
      clearDpopKeyPair()
      set((state) => !state.isAuthenticated && !state.isLoading && state.accessToken === null && state.refreshToken === null && state.user === null
        ? state : CLEARED_STATE)
      // The tombstone does not wait for the lock: a refresh holding it fails its write against the tombstone.
      clearStoredAuth()
        .then((epoch) => { publishAuthEvent({ type: 'cleared', epoch }) })
        .catch((error) => { logger.warn('Failed to clear stored auth:', error) })
      pushToken(null)
    },

    adoptStoredAuth: async () => adoptRecord(await loadStoredAuth()),

    initAuth: async () => {
      if (get().isLoading || get().isAuthenticated) return
      const owner = generation
      try {
        const stored = await loadStoredAuth()
        if (!stored || isClearedRecord(stored) || owner !== generation || get().isAuthenticated) return
        applyRecord(stored)
      } catch (error) {
        if (owner === generation) logger.warn('Failed to load stored auth, starting unauthenticated:', error)
      }
    },
  }
})))

/** Read tokens outside React for interceptors and service worker messaging. */
export function getAuthSnapshot() { return useAuthStore.getState() }
