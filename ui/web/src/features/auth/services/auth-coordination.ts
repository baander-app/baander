/**
 * Cross-tab coordination of the origin's credentials.
 *
 * Tabs of one origin share one IndexedDB record. A Web Lock makes them run one refresh at a time:
 * under the lock a tab re-reads the record and adopts a rotation another tab already made instead of
 * presenting the same refresh token again. A BroadcastChannel carries `rotated` and `cleared` events
 * with the record's epoch, and never a token, so a sibling tab re-reads the record; the lock and the
 * re-read are the correctness mechanism, the events only prompt it. Credentials stay inside the
 * IndexedDB and DPoP lifecycle.
 */

export const AUTH_LOCK_NAME = 'baander-auth-refresh'
export const AUTH_CHANNEL_NAME = 'baander-auth'

export type AuthEvent =
  | { type: 'rotated'; epoch: number }
  | { type: 'cleared'; epoch: number }

export type AuthCoordinationFailure = 'locks-unavailable' | 'lock-timeout'

/** A refresh that could not run: a transient failure, the session is kept. */
export class AuthCoordinationError extends Error {
  readonly reason: AuthCoordinationFailure

  constructor(reason: AuthCoordinationFailure) {
    super(reason === 'locks-unavailable'
      ? 'The Web Locks API is unavailable; the refresh cannot be coordinated across tabs'
      : 'The auth lock was not granted within the refresh budget')
    this.name = 'AuthCoordinationError'
    this.reason = reason
  }
}

function lockManager(): LockManager | undefined {
  return globalThis.navigator?.locks
}

function isAbort(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

/**
 * Runs `work` while holding the origin's auth lock. The wait for the lock is bounded by
 * `budgetMs`; once granted, the lock is held until `work` settles. The lock is not reentrant:
 * a caller that already holds it must not request it again.
 */
export async function withAuthLock<T>(budgetMs: number, work: () => Promise<T>): Promise<T> {
  const locks = lockManager()
  if (!locks) throw new AuthCoordinationError('locks-unavailable')

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), budgetMs)
  let granted = false
  try {
    return await locks.request(AUTH_LOCK_NAME, { signal: controller.signal }, async () => {
      granted = true
      return work()
    })
  } catch (error) {
    if (!granted && isAbort(error)) throw new AuthCoordinationError('lock-timeout')
    throw error
  } finally {
    clearTimeout(timer)
  }
}

export interface RefreshCoordination {
  /** How long to wait for the lock; the same budget bounds the refresh itself. */
  budgetMs: number
  /** Re-reads the stored record; resolves true when a rotation or a clear was adopted from it. */
  adopt: () => Promise<boolean>
  /** Presents the refresh token to the server and persists the answer; runs only under the lock. */
  refresh: () => Promise<void>
}

/**
 * One refresh per origin at a time. Under the lock the tab adopts what another tab rotated, or
 * refreshes itself. A tab whose lock wait aborts re-reads once, adopts or fails as transient, and
 * never calls the server.
 */
export async function coordinateRefresh({ budgetMs, adopt, refresh }: RefreshCoordination): Promise<void> {
  try {
    await withAuthLock(budgetMs, async () => {
      if (await adopt()) return
      await refresh()
    })
  } catch (error) {
    if (error instanceof AuthCoordinationError && error.reason === 'lock-timeout') {
      if (await adopt()) return
    }
    throw error
  }
}

function openChannel(): BroadcastChannel | null {
  if (typeof globalThis.BroadcastChannel !== 'function') return null
  return new globalThis.BroadcastChannel(AUTH_CHANNEL_NAME)
}

function isAuthEvent(data: unknown): data is AuthEvent {
  if (typeof data !== 'object' || data === null) return false
  const { type, epoch } = data as { type?: unknown; epoch?: unknown }
  return (type === 'rotated' || type === 'cleared') && typeof epoch === 'number'
}

/** Tells the other tabs of the origin that the record changed; they re-read it. */
export function publishAuthEvent(event: AuthEvent): void {
  const target = openChannel()
  if (!target) return
  target.postMessage({ type: event.type, epoch: event.epoch })
  target.close()
}

/** Listens for events other tabs publish; the returned function stops listening. */
export function subscribeAuthEvents(listener: (event: AuthEvent) => void): () => void {
  const target = openChannel()
  if (!target) return () => {}
  target.onmessage = (message: MessageEvent) => {
    if (isAuthEvent(message.data)) listener(message.data)
  }
  return () => {
    target.onmessage = null
    target.close()
  }
}
