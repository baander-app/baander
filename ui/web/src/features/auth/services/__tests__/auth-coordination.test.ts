import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  FakeBroadcastChannel,
  FakeLockManager,
  installBroadcastChannel,
  installLocks,
} from '../../../../../tests/shared/auth/auth-fakes'
import {
  AUTH_CHANNEL_NAME,
  AUTH_LOCK_NAME,
  AuthCoordinationError,
  coordinateRefresh,
  publishAuthEvent,
  subscribeAuthEvents,
  withAuthLock,
} from '../auth-coordination'

const BUDGET_MS = 30_000

let locks: FakeLockManager
let restoreLocks: () => void

beforeEach(() => {
  vi.useFakeTimers()
  locks = new FakeLockManager()
  restoreLocks = installLocks(locks)
})

afterEach(() => {
  restoreLocks()
  vi.useRealTimers()
})

describe('coordinateRefresh', () => {
  it('adopts under the lock and never calls the server', async () => {
    const adopt = vi.fn().mockResolvedValue(true)
    const refresh = vi.fn().mockResolvedValue(undefined)

    await coordinateRefresh({ budgetMs: BUDGET_MS, adopt, refresh })

    expect(adopt).toHaveBeenCalledOnce()
    expect(refresh).not.toHaveBeenCalled()
    expect(locks.requests).toEqual([{ name: AUTH_LOCK_NAME, outcome: 'granted' }])
    expect(locks.isHeld()).toBe(false)
  })

  it('refreshes while holding the lock and releases it when the refresh completes', async () => {
    let heldDuringRefresh = false
    const adopt = vi.fn().mockResolvedValue(false)
    const refresh = vi.fn(async () => {
      heldDuringRefresh = locks.isHeld()
    })

    await coordinateRefresh({ budgetMs: BUDGET_MS, adopt, refresh })

    expect(adopt).toHaveBeenCalledOnce()
    expect(refresh).toHaveBeenCalledOnce()
    expect(heldDuringRefresh).toBe(true)
    expect(locks.isHeld()).toBe(false)
  })

  it('releases the lock when the refresh fails and passes the failure on', async () => {
    const failure = new Error('refresh failed')
    const adopt = vi.fn().mockResolvedValue(false)
    const refresh = vi.fn().mockRejectedValue(failure)

    await expect(coordinateRefresh({ budgetMs: BUDGET_MS, adopt, refresh })).rejects.toBe(failure)

    expect(locks.isHeld()).toBe(false)
  })

  it('fails as transient at the budget when the lock is never granted, after one re-read', async () => {
    locks.hold()
    const adopt = vi.fn().mockResolvedValue(false)
    const refresh = vi.fn().mockResolvedValue(undefined)

    const outcome = coordinateRefresh({ budgetMs: BUDGET_MS, adopt, refresh }).catch((error) => error)
    await vi.advanceTimersByTimeAsync(BUDGET_MS - 1)
    expect(adopt).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)

    const error = await outcome
    expect(error).toBeInstanceOf(AuthCoordinationError)
    expect((error as AuthCoordinationError).reason).toBe('lock-timeout')
    expect(adopt).toHaveBeenCalledOnce()
    expect(refresh).not.toHaveBeenCalled()
    expect(locks.requests.at(-1)).toEqual({ name: AUTH_LOCK_NAME, outcome: 'aborted' })
  })

  it('adopts a rotation it reads after its lock wait aborted', async () => {
    locks.hold()
    const adopt = vi.fn().mockResolvedValue(true)
    const refresh = vi.fn().mockResolvedValue(undefined)

    const outcome = coordinateRefresh({ budgetMs: BUDGET_MS, adopt, refresh })
    await vi.advanceTimersByTimeAsync(BUDGET_MS)

    await expect(outcome).resolves.toBeUndefined()
    expect(adopt).toHaveBeenCalledOnce()
    expect(refresh).not.toHaveBeenCalled()
  })

  it('fails as transient without the Web Locks API', async () => {
    restoreLocks()
    const adopt = vi.fn().mockResolvedValue(true)
    const refresh = vi.fn().mockResolvedValue(undefined)

    const error = await coordinateRefresh({ budgetMs: BUDGET_MS, adopt, refresh }).catch((reason) => reason)

    expect(error).toBeInstanceOf(AuthCoordinationError)
    expect((error as AuthCoordinationError).reason).toBe('locks-unavailable')
    expect(adopt).not.toHaveBeenCalled()
    expect(refresh).not.toHaveBeenCalled()
  })
})

describe('withAuthLock', () => {
  it('runs the work under the lock and resolves with its value', async () => {
    const value = await withAuthLock(BUDGET_MS, async () => {
      return locks.isHeld() ? 'held' : 'free'
    })

    expect(value).toBe('held')
    expect(locks.isHeld()).toBe(false)
  })

  it('gives up at the budget when another holder keeps the lock', async () => {
    locks.hold()
    const work = vi.fn().mockResolvedValue('never')

    const outcome = withAuthLock(BUDGET_MS, work).catch((error) => error)
    await vi.advanceTimersByTimeAsync(BUDGET_MS)

    const error = await outcome
    expect(error).toBeInstanceOf(AuthCoordinationError)
    expect((error as AuthCoordinationError).reason).toBe('lock-timeout')
    expect(work).not.toHaveBeenCalled()
  })
})

describe('auth events', () => {
  beforeEach(() => {
    installBroadcastChannel()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('posts the event type and epoch, and nothing else, on the auth channel', () => {
    publishAuthEvent({ type: 'rotated', epoch: 4 })
    publishAuthEvent({ type: 'cleared', epoch: 5 })

    expect(FakeBroadcastChannel.posted).toEqual([
      { name: AUTH_CHANNEL_NAME, message: { type: 'rotated', epoch: 4 } },
      { name: AUTH_CHANNEL_NAME, message: { type: 'cleared', epoch: 5 } },
    ])
  })

  it('delivers events from another tab until the subscription is released', () => {
    const listener = vi.fn()
    const unsubscribe = subscribeAuthEvents(listener)

    FakeBroadcastChannel.deliver(AUTH_CHANNEL_NAME, { type: 'cleared', epoch: 7 })
    FakeBroadcastChannel.deliver('baander-other', { type: 'cleared', epoch: 8 })
    FakeBroadcastChannel.deliver(AUTH_CHANNEL_NAME, { type: 'bogus' })
    unsubscribe()
    FakeBroadcastChannel.deliver(AUTH_CHANNEL_NAME, { type: 'rotated', epoch: 9 })

    expect(listener).toHaveBeenCalledTimes(1)
    expect(listener).toHaveBeenCalledWith({ type: 'cleared', epoch: 7 })
  })
})
