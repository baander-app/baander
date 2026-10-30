import { CanceledError } from 'axios'

export interface OwnershipStamp {
  principal: string | null
  generation: number
}

let current: OwnershipStamp = { principal: null, generation: 0 }
const listeners = new Set<() => void>()

export function captureOwnership(): OwnershipStamp {
  return current
}

export function isCurrentOwnership(stamp: OwnershipStamp): boolean {
  return stamp.principal === current.principal && stamp.generation === current.generation
}

export function assertCurrentOwnership(stamp: OwnershipStamp): void {
  if (!isCurrentOwnership(stamp)) throw new CanceledError('Client ownership changed')
}

export function synchronizeOwnership(principal: string | null): void {
  if (current.principal === principal) return
  authorityPending = false
  current = { principal, generation: current.generation + 1 }
  listeners.forEach(listener => listener())
}

export function advanceAuthorization(): OwnershipStamp {
  current = { ...current, generation: current.generation + 1 }
  listeners.forEach(listener => listener())
  return current
}

export function subscribeOwnership(listener: () => void): () => void {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}

/** Capture before starting a continuation, and recheck after every awaited side effect. */
export function guardMutationCallbacks<T extends object>(
  callbacks: T,
  stamp = captureOwnership(),
): T {
  return Object.fromEntries(Object.entries(callbacks).map(([key, callback]) => [
    key,
    typeof callback === 'function'
      ? (...args: unknown[]) => isCurrentOwnership(stamp) ? callback(...args) : undefined
      : callback,
  ])) as T
}

let authorityPending = false
const authorityListeners = new Set<() => void>()

type OwnershipAbortSignal = {
  aborted: boolean
  addEventListener?: (type: 'abort', listener: () => void) => void
  removeEventListener?: (type: 'abort', listener: () => void) => void
}

export function setAuthorityPending(pending: boolean): void {
  authorityPending = pending
  authorityListeners.forEach(listener => listener())
}

/** Recovery/ticket/auth controls bypass this barrier; protected reads await current authority. */
export function waitForOwnershipAuthority(signal?: OwnershipAbortSignal): Promise<void> {
  const stamp = captureOwnership()
  if (signal?.aborted) return Promise.reject(new CanceledError('Request aborted'))
  if (!authorityPending) return Promise.resolve()
  return new Promise((resolve, reject) => {
    const cleanup = () => {
      authorityListeners.delete(check)
      unsubscribe()
      signal?.removeEventListener?.('abort', check)
    }
    const check = () => {
      if (signal?.aborted || !isCurrentOwnership(stamp)) {
        cleanup()
        reject(new CanceledError('Client ownership changed while awaiting authority'))
      } else if (!authorityPending) {
        cleanup()
        resolve()
      }
    }
    const unsubscribe = subscribeOwnership(check)
    authorityListeners.add(check)
    signal?.addEventListener?.('abort', check)
    check()
  })
}

const queryOwners = new WeakMap<AbortSignal, OwnershipStamp>()

export function bindOwnershipToSignal(signal: AbortSignal, stamp: OwnershipStamp): void {
  queryOwners.set(signal, stamp)
}

export function ownershipForSignal(signal: object): OwnershipStamp | undefined {
  return queryOwners.get(signal as AbortSignal)
}
