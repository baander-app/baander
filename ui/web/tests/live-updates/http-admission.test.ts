import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LiveHttpAdmission } from '@/shared/live/http-admission'

const flush = async () => {
  await Promise.resolve()
  await Promise.resolve()
}

describe('resource HTTP admission', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('admits four background attempts and releases each permit exactly once', async () => {
    const admission = new LiveHttpAdmission()
    const releases = await Promise.all(Array.from({ length: 4 }, () => admission.acquire(undefined, 'background')))
    const fifth = vi.fn()
    const pending = admission.acquire(undefined, 'background').then(release => {
      fifth()
      return release
    })
    await flush()
    expect(fifth).not.toHaveBeenCalled()
    releases[0]()
    const releaseFifth = await pending
    releases[0]()
    const sixth = vi.fn()
    const sixthPending = admission.acquire(undefined, 'background').then(release => {
      sixth()
      return release
    })
    await flush()
    expect(sixth).not.toHaveBeenCalled()
    releaseFifth()
    const releaseSixth = await sixthPending
    releases.forEach(release => release())
    releaseSixth()
  })

  it('counts every page or retry attempt in the sliding second and prioritizes foreground work', async () => {
    const admission = new LiveHttpAdmission()
    for (let attempt = 0; attempt < 20; attempt++) {
      const release = await admission.acquire(undefined, 'background')
      release()
    }
    const order: string[] = []
    const background = admission.acquire(undefined, 'background').then(release => {
      order.push('background')
      release()
    })
    const foreground = admission.acquire(undefined, 'foreground').then(release => {
      order.push('foreground')
      release()
    })
    await vi.advanceTimersByTimeAsync(999)
    expect(order).toEqual([])
    await vi.advanceTimersByTimeAsync(1)
    await Promise.all([background, foreground])
    expect(order).toEqual(['foreground', 'background'])
  })

  it('removes cancelled queued work and releases cancelled active attempts', async () => {
    const admission = new LiveHttpAdmission()
    const active = new AbortController()
    const releases = await Promise.all([
      admission.acquire(active.signal, 'background'),
      ...Array.from({ length: 3 }, () => admission.acquire(undefined, 'background')),
    ])
    const queued = new AbortController()
    const rejected = expect(admission.acquire(queued.signal, 'background')).rejects.toThrow('aborted')
    queued.abort()
    await rejected
    const replacement = admission.acquire(undefined, 'background')
    active.abort()
    const release = await replacement
    releases.forEach(done => done())
    release()
  })

  it('bounds active and queued attempts even when one reader fans out requests', async () => {
    const admission = new LiveHttpAdmission()
    const controllers = Array.from({ length: 256 }, () => new AbortController())
    const attempts = controllers.map(controller => admission.acquire(controller.signal, 'background'))
    const settled = Promise.allSettled(attempts)
    expect(admission.getSnapshot()).toEqual({ active: 4, queued: 252, background: 4 })
    await expect(admission.acquire(undefined, 'background')).rejects.toThrow('capacity')
    expect(admission.getSnapshot().queued).toBe(252)
    controllers.forEach(controller => controller.abort())
    const results = await settled
    results.forEach(result => {
      if (result.status === 'fulfilled') result.value()
    })
    expect(admission.getSnapshot()).toEqual({ active: 0, queued: 0, background: 0 })
  })

})
