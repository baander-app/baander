/**
 * Pending logouts: the delivery of a logout the tab already ended locally.
 *
 * A logout ends the session in the browser at once; the server request is recorded as an entry in
 * IndexedDB, beside the credential tombstone and in the same transaction, and delivered afterwards.
 * The entry holds the ended session's DPoP key pair and tokens, so the delivery can sign a fresh
 * proof whatever the tab holds now; it never uses the tab's current session and never adopts an
 * entry as one. The drain runs on start, on `online`, on becoming visible, on a backoff timer while
 * entries remain, and right after a logout.
 *
 * Per entry the drain takes the origin's auth lock with a short wait, re-reads the entry under it,
 * and sends bare Axios requests: the shared instance would sign with the tab's key, not the entry's.
 * The access token is refreshed first when its stored expiry passed or when the logout answered 401;
 * the authenticator answers the same 401 for an expired, a revoked and a malformed access token, so
 * only the refresh's answer can tell that the chain is gone. The rotated tokens are persisted in the
 * entry before the logout is sent, so a lost answer cannot leave the entry with a consumed refresh
 * token. An entry is dropped when the server confirmed the logout or refused the refresh, when it
 * belongs to another API origin, or when its key is unusable; it is kept on every other answer and
 * on a transport failure, until the server refuses it. The oldest entry beyond the list's limit is
 * sent once more and then dropped whatever the answer.
 */

import Axios from 'axios'
import { createDpopProof } from '@/shared/crypto/dpop-proof'
import {
  PENDING_LOGOUT_LIMIT,
  beginStoredLogout,
  listPendingLogouts,
  removePendingLogout,
  updatePendingLogout,
  type PendingLogoutEntry,
} from '@/shared/crypto/auth-db'
import { AuthCoordinationError, withAuthLock } from '@/features/auth/services/auth-coordination'
import { apiOrigin, buildHtu, challengedNonce, isRefreshRejected, outageWaitFor, wait } from '@/shared/api-client/axios-instance'
import { retryAfterSeconds } from '@/features/auth/lib/retry-after'
import {
  PENDING_LOGOUT_LOCK_WAIT_MS,
  PENDING_LOGOUT_TIMER_ATTEMPTS,
  REFRESH_ATTEMPT_TIMEOUT_MS,
  pendingLogoutBackoffMs,
} from '@/shared/auth/refresh-budget'
import { createLogger } from '@/shared/lib/logger'
import type { DpopKeyPair } from '../../../../../shared/dpop/key-pair'

export type { PendingLogoutEntry } from '@/shared/crypto/auth-db'

const logger = createLogger('PendingLogout')

/** The credentials of the session a logout ends. */
export interface LogoutCredentials {
  keyPair: DpopKeyPair
  accessToken: string
  refreshToken: string
}

type Tokens = Pick<PendingLogoutEntry, 'accessToken' | 'refreshToken' | 'accessTokenExpiresAt'>

/** What one delivery attempt decided for its entry. */
type Verdict =
  | { kind: 'drop'; reason: string }
  | { kind: 'keep'; retryAfterMs: number }

type RefreshAnswer =
  | { kind: 'rotated'; tokens: Tokens }
  | { kind: 'refused' }
  | { kind: 'failed'; retryAfterMs: number }

type LogoutAnswer =
  | { kind: 'confirmed' }
  | { kind: 'unauthorized' }
  | { kind: 'failed'; retryAfterMs: number }

/** The nonce the server asked for, carried from one request of an attempt to the next. */
interface NonceState {
  nonce: string | null
}

/** A key that cannot produce a proof; the entry is dropped, since no request could ever carry it. */
class UnusableKeyError extends Error {
  constructor(cause: unknown) {
    super('The pending logout key cannot sign', { cause })
    this.name = 'UnusableKeyError'
  }
}

function apiUrl(path: string): string {
  return new URL(path, apiOrigin()).href
}

/** The claims of a JWT, read without checking its signature, or null when it is not one. */
function jwtClaims(token: string): Record<string, unknown> | null {
  const parts = token.split('.')
  if (parts.length !== 3) return null
  try {
    const padded = parts[1].replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(parts[1].length / 4) * 4, '=')
    const claims: unknown = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(padded), (char) => char.charCodeAt(0))))
    return typeof claims === 'object' && claims !== null ? claims as Record<string, unknown> : null
  } catch {
    return null
  }
}

/** The access token's `exp` claim in milliseconds since the epoch, or null when it carries none. */
function accessTokenExpiry(accessToken: string): number | null {
  const exp = jwtClaims(accessToken)?.exp
  return typeof exp === 'number' && Number.isFinite(exp) ? exp * 1000 : null
}

/** The thumbprint the access token is bound to (`cnf.jkt`), or null when it names none. */
function boundThumbprint(accessToken: string): string | null {
  const cnf = jwtClaims(accessToken)?.cnf
  const jkt = typeof cnf === 'object' && cnf !== null ? (cnf as { jkt?: unknown }).jkt : undefined
  return typeof jkt === 'string' && jkt !== '' ? jkt : null
}

/**
 * Records the logout of the session `credentials` belong to and leaves the credential tombstone in
 * the same transaction. Resolves the tombstone's epoch, or null when the store could not be written:
 * the caller then clears as it would without a pending logout.
 */
export async function recordLogout(credentials: LogoutCredentials): Promise<number | null> {
  const entry: PendingLogoutEntry = {
    id: crypto.randomUUID(),
    createdAt: Date.now(),
    keyPair: credentials.keyPair,
    accessToken: credentials.accessToken,
    refreshToken: credentials.refreshToken,
    accessTokenExpiresAt: accessTokenExpiry(credentials.accessToken),
    origin: apiOrigin(),
    attempts: 0,
  }
  try {
    return await beginStoredLogout(entry)
  } catch (error) {
    logger.warn('Failed to record the pending logout; the logout proceeds without one:', error)
    return null
  }
}

async function sign(keyPair: DpopKeyPair, htu: string, options: { accessToken?: string; nonce: string | null }): Promise<string> {
  try {
    return await createDpopProof(keyPair, 'POST', htu, {
      accessToken: options.accessToken,
      nonce: options.nonce ?? undefined,
    })
  } catch (error) {
    throw new UnusableKeyError(error)
  }
}

function serverRetryAfterMs(error: unknown): number {
  const seconds = retryAfterSeconds(error, { status: 503 })
  return seconds === null ? 0 : seconds * 1000
}

/**
 * Presents the entry's refresh token with a proof from its key, without touching the tab's session.
 * One nonce challenge and one 503 wait each buy one more attempt, as the tab's own refresh has them.
 */
async function refreshWithPair(keyPair: DpopKeyPair, refreshToken: string, state: NonceState): Promise<RefreshAnswer> {
  const refreshUrl = apiUrl('/api/auth/refresh')
  const htu = buildHtu(refreshUrl)
  const retried = { nonce: false, outage: false }
  for (;;) {
    const proof = await sign(keyPair, htu, { nonce: state.nonce })
    try {
      const response = await Axios.post(refreshUrl, { refreshToken }, {
        headers: { DPoP: proof, Accept: 'application/json' },
        timeout: REFRESH_ATTEMPT_TIMEOUT_MS,
      })
      const data = response.data?.data ?? response.data
      if (typeof data?.accessToken !== 'string' || !data.accessToken ||
          typeof data?.refreshToken !== 'string' || !data.refreshToken) {
        logger.warn('The refresh for a pending logout returned no tokens; the entry is kept')
        return { kind: 'failed', retryAfterMs: 0 }
      }
      const nonce = response.headers['dpop-nonce']
      if (typeof nonce === 'string' && nonce) state.nonce = nonce
      return {
        kind: 'rotated',
        tokens: {
          accessToken: data.accessToken,
          refreshToken: data.refreshToken,
          accessTokenExpiresAt: accessTokenExpiry(data.accessToken),
        },
      }
    } catch (error) {
      const nonce = challengedNonce(error)
      if (nonce !== null && !retried.nonce) {
        retried.nonce = true
        state.nonce = nonce
        continue
      }
      if (isRefreshRejected(error)) return { kind: 'refused' }
      const outageWait = outageWaitFor(error)
      if (outageWait !== null && !retried.outage) {
        retried.outage = true
        await wait(outageWait)
        continue
      }
      return { kind: 'failed', retryAfterMs: serverRetryAfterMs(error) }
    }
  }
}

/** Sends the logout with the entry's key and `accessToken`; one nonce challenge buys one more attempt. */
async function sendLogout(keyPair: DpopKeyPair, accessToken: string, state: NonceState): Promise<LogoutAnswer> {
  const logoutUrl = apiUrl('/api/auth/logout')
  const htu = buildHtu(logoutUrl)
  let retriedNonce = false
  for (;;) {
    const proof = await sign(keyPair, htu, { accessToken, nonce: state.nonce })
    try {
      await Axios.post(logoutUrl, undefined, {
        headers: { Authorization: `DPoP ${accessToken}`, DPoP: proof, Accept: 'application/json' },
        timeout: REFRESH_ATTEMPT_TIMEOUT_MS,
      })
      return { kind: 'confirmed' }
    } catch (error) {
      const nonce = challengedNonce(error)
      if (nonce !== null && !retriedNonce) {
        retriedNonce = true
        state.nonce = nonce
        continue
      }
      if (Axios.isAxiosError(error) && error.response?.status === 401) return { kind: 'unauthorized' }
      return { kind: 'failed', retryAfterMs: serverRetryAfterMs(error) }
    }
  }
}

/** Stores the rotated tokens in the entry before the logout goes out; a failed write is logged, the logout still goes. */
async function persistRotation(entry: PendingLogoutEntry, tokens: Tokens): Promise<void> {
  try {
    const found = await updatePendingLogout(entry.id, tokens)
    if (!found) logger.warn('A pending logout was removed while its tokens rotated; sending the logout anyway')
  } catch (error) {
    logger.warn('Failed to store the rotated tokens of a pending logout; sending the logout anyway:', error)
  }
}

/** One delivery attempt for `entry`, under the auth lock. */
async function deliver(entry: PendingLogoutEntry): Promise<Verdict> {
  if (entry.origin !== apiOrigin()) return { kind: 'drop', reason: 'it belongs to another API origin' }
  const { keyPair } = entry
  if (!keyPair?.privateKey || !keyPair.jwk || !keyPair.jkt) return { kind: 'drop', reason: 'its key read back empty' }
  const bound = boundThumbprint(entry.accessToken)
  if (bound !== null && bound !== keyPair.jkt) return { kind: 'drop', reason: 'its key is not the one the access token is bound to' }

  const state: NonceState = { nonce: null }
  let tokens: Tokens = entry
  let refreshed = false
  const refresh = async (): Promise<Verdict | null> => {
    const answer = await refreshWithPair(keyPair, tokens.refreshToken, state)
    if (answer.kind === 'refused') return { kind: 'drop', reason: 'the server refused its refresh token' }
    if (answer.kind === 'failed') return { kind: 'keep', retryAfterMs: answer.retryAfterMs }
    tokens = answer.tokens
    refreshed = true
    await persistRotation(entry, tokens)
    return null
  }

  try {
    if (entry.accessTokenExpiresAt !== null && entry.accessTokenExpiresAt <= Date.now()) {
      const verdict = await refresh()
      if (verdict) return verdict
    }
    let answer = await sendLogout(keyPair, tokens.accessToken, state)
    if (answer.kind === 'unauthorized' && !refreshed) {
      const verdict = await refresh()
      if (verdict) return verdict
      answer = await sendLogout(keyPair, tokens.accessToken, state)
    }
    if (answer.kind === 'confirmed') return { kind: 'drop', reason: 'the server confirmed it' }
    return { kind: 'keep', retryAfterMs: answer.kind === 'failed' ? answer.retryAfterMs : 0 }
  } catch (error) {
    if (error instanceof UnusableKeyError) return { kind: 'drop', reason: 'its key cannot sign' }
    throw error
  }
}

type Outcome =
  | { kind: 'dropped' }
  | { kind: 'kept'; retryAfterMs: number }
  | { kind: 'skipped' }

/** Attempts `entry` under the auth lock; `lastAttempt` drops it afterwards whatever the server said. */
async function attempt(entry: PendingLogoutEntry, lastAttempt: boolean): Promise<Outcome> {
  try {
    return await withAuthLock(PENDING_LOGOUT_LOCK_WAIT_MS, async (): Promise<Outcome> => {
      const current = (await listPendingLogouts()).find((stored) => stored.id === entry.id)
      if (!current) return { kind: 'dropped' }
      const verdict = await deliver(current)
      if (verdict.kind === 'drop') {
        logger.debug(`Pending logout ${current.id} dropped: ${verdict.reason}`)
        await removePendingLogout(current.id)
        return { kind: 'dropped' }
      }
      if (lastAttempt) {
        logger.warn(`More than ${PENDING_LOGOUT_LIMIT} pending logouts; the oldest, ${current.id}, is dropped after one last attempt`)
        await removePendingLogout(current.id)
        return { kind: 'dropped' }
      }
      await updatePendingLogout(current.id, { attempts: current.attempts + 1 })
      return { kind: 'kept', retryAfterMs: verdict.retryAfterMs }
    })
  } catch (error) {
    if (error instanceof AuthCoordinationError) {
      logger.debug(`Pending logout ${entry.id} skipped this round: ${error.reason}`)
      return { kind: 'skipped' }
    }
    logger.warn(`The attempt for pending logout ${entry.id} failed; the entry is kept:`, error)
    return { kind: 'kept', retryAfterMs: 0 }
  }
}

interface DrainPass {
  remaining: boolean
  retryAfterMs: number
}

/** One pass over the list, oldest first, one lock per entry. */
async function drainOnce(): Promise<DrainPass> {
  let entries: PendingLogoutEntry[]
  try {
    entries = await listPendingLogouts()
  } catch (error) {
    logger.warn('Failed to read the pending logouts:', error)
    return { remaining: false, retryAfterMs: 0 }
  }
  const overflow = Math.max(0, entries.length - PENDING_LOGOUT_LIMIT)
  const pass: DrainPass = { remaining: false, retryAfterMs: 0 }
  for (const [index, entry] of entries.entries()) {
    const outcome = await attempt(entry, index < overflow)
    if (outcome.kind === 'dropped') continue
    pass.remaining = true
    if (outcome.kind === 'kept') pass.retryAfterMs = Math.max(pass.retryAfterMs, outcome.retryAfterMs)
  }
  return pass
}

// One drain at a time per page; a trigger during a drain asks for one more pass afterwards.
let running: Promise<void> | null = null
let passRequested = false
let timer: ReturnType<typeof setTimeout> | null = null
let backoffStep = 0
let timerAttempts = 0

function cancelTimer(): void {
  if (timer !== null) clearTimeout(timer)
  timer = null
}

/** Reschedules while entries remain: the backoff, or the server's Retry-After when it is longer. */
function schedule(pass: DrainPass): void {
  if (!pass.remaining) {
    backoffStep = 0
    return
  }
  if (timerAttempts >= PENDING_LOGOUT_TIMER_ATTEMPTS) {
    logger.debug('Pending logouts remain; the timer made its attempts for this page, the next trigger retries')
    return
  }
  const delay = Math.max(pendingLogoutBackoffMs(backoffStep), pass.retryAfterMs)
  backoffStep++
  timer = setTimeout(() => {
    timer = null
    timerAttempts++
    start()
  }, delay)
}

function start(): Promise<void> {
  cancelTimer()
  if (running) {
    passRequested = true
    return running
  }
  running = (async () => {
    let pass: DrainPass
    do {
      passRequested = false
      pass = await drainOnce()
    } while (passRequested)
    return pass
  })().then(schedule, (error) => {
    logger.warn('The pending logout drain failed:', error)
  }).finally(() => {
    running = null
  })
  return running
}

/**
 * Delivers the pending logouts: every entry once, oldest first, each under the auth lock. Resolves
 * once the pass is over, never rejects. Entries that remain are retried on the backoff timer, which
 * this call restarts from its first delay.
 */
export function drainPendingLogouts(): Promise<void> {
  backoffStep = 0
  return start()
}

/**
 * Drains when the browser comes back online and when the page becomes visible. The returned
 * function removes the listeners and stops the timer.
 */
export function installPendingLogoutTriggers(): () => void {
  const onOnline = () => {
    drainPendingLogouts()
  }
  const onVisibilityChange = () => {
    if (document.visibilityState === 'visible') drainPendingLogouts()
  }
  window.addEventListener('online', onOnline)
  document.addEventListener('visibilitychange', onVisibilityChange)
  return () => {
    window.removeEventListener('online', onOnline)
    document.removeEventListener('visibilitychange', onVisibilityChange)
    cancelTimer()
  }
}
