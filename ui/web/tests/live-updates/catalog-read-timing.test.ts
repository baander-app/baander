import { describe, expect, it } from 'vitest'
import { CatalogReadTiming } from '../e2e/live-updates/catalog-read-timing'

describe('catalog request timing', () => {
  it('retains reordered host notifications while measuring serial native transfers', () => {
    const observer = new CatalogReadTiming()
    observer.start(1, '/api/albums/?page=1', 2000)
    observer.start(2, '/api/albums/?page=2', 2011)
    observer.finish(1, { startSeconds: 1000, endSeconds: 1010 }, false, 2012)
    observer.finish(2, { startSeconds: 1010, endSeconds: 1020 }, false, 2021)
    expect(observer.hostMaxInFlight).toBe(2)
    expect(observer.hostEvents.map(event => [event.requestId, event.phase])).toEqual([
      [1, 'request'], [2, 'request'], [1, 'requestfinished'], [2, 'requestfinished'],
    ])
    expect(observer.concurrency).toEqual({ maxInFlight: 1, errors: [] })
    expect(observer.pendingRequests).toBe(0)
  })

  it('detects genuinely overlapping native transfers despite serial host notifications', () => {
    const observer = new CatalogReadTiming()
    observer.start(1, '/api/albums/?page=1', 2000)
    observer.finish(1, { startSeconds: 1000, endSeconds: 1010 }, false, 2010)
    observer.start(2, '/api/albums/?page=2', 2011)
    observer.finish(2, { startSeconds: 1005, endSeconds: 1015 }, false, 2021)
    expect(observer.hostMaxInFlight).toBe(1)
    expect(observer.concurrency).toEqual({ maxInFlight: 2, errors: [] })
  })

  it('counts a route-held request once using absolute CDP completion timestamps', () => {
    const observer = new CatalogReadTiming()
    observer.start(1, '/api/albums/?page=25', 2000)
    observer.finish(1, { startSeconds: 100, endSeconds: 100.6 }, false, 2601)
    observer.start(2, '/api/albums/?page=26', 2602)
    observer.finish(2, { startSeconds: 100.601, endSeconds: 100.7 }, false, 2701)
    expect(observer.concurrency).toEqual({ maxInFlight: 1, errors: [] })
  })

  it('rejects missing completion timings instead of omitting pending requests', () => {
    const observer = new CatalogReadTiming()
    observer.start(1, '/api/albums/?page=1', 2000)
    expect(observer.concurrency.maxInFlight).toBeNull()
    expect(observer.concurrency.errors).toEqual([expect.stringContaining('request 1')])
    expect(observer.pendingRequests).toBe(1)
  })

  it.each([
    { startSeconds: 0, endSeconds: 10 },
    { startSeconds: Number.NaN, endSeconds: 10 },
    { startSeconds: 1000, endSeconds: -1 },
    { startSeconds: 1000, endSeconds: 0 },
    { startSeconds: 1000, endSeconds: Number.POSITIVE_INFINITY },
  ])('rejects invalid native timing %j while preserving the failed attempt', timing => {
    const observer = new CatalogReadTiming()
    observer.start(1, '/api/albums/?page=1', 2000)
    observer.finish(1, timing, true, 2010)
    expect(observer.requests).toEqual([{ requestId: 1, path: '/api/albums/?page=1', timing, failed: true }])
    expect(observer.hostEvents[1].phase).toBe('requestfailed')
    expect(observer.concurrency.maxInFlight).toBeNull()
    expect(observer.concurrency.errors).toEqual([expect.stringContaining('request 1')])
  })
})
