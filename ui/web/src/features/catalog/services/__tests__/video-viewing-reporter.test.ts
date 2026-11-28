import { afterEach, describe, expect, it, vi } from 'vitest'
import type { RecordVideoProgressRequest } from '@/shared/api-client/gen/endpoints'
import { VideoViewingReporter, VIEWING_FLUSH_TIMEOUT_MS } from '../video-viewing-reporter'

function fixture() {
  let clock = Date.parse('2026-11-28T13:00:00Z')
  let current = true
  const report = vi.fn<
    (videoId: string, body: RecordVideoProgressRequest, signal: AbortSignal) => Promise<void>
  >(async () => {})
  const isWatching = vi.fn<(videoId: string, signal: AbortSignal) => Promise<boolean>>(async () => false)
  const reporter = new VideoViewingReporter('selectedVideo00000001', {
    titleKind: 'episode',
    titleId: 'selectedEpisode000001'
  }, {
    current: () => current,
    now: () => clock,
    isWatching,
    report
  })
  return {
    reporter,
    report,
    isWatching,
    advance: (milliseconds: number) => { clock += milliseconds },
    replace: () => { current = false }
  }
}

afterEach(() => vi.useRealTimers())

describe('video viewing reports', () => {
  it('reports every thirty seconds of playback without counting paused time', async () => {
    const value = fixture()
    value.reporter.tick(10, true)
    value.advance(29_000)
    value.reporter.tick(39, true)
    await value.reporter.settled()
    expect(value.report).not.toHaveBeenCalled()
    value.advance(1_000)
    value.reporter.tick(40, true)
    await value.reporter.settled()
    expect(value.report).toHaveBeenCalledTimes(1)
    expect(value.report).toHaveBeenLastCalledWith('selectedVideo00000001', expect.objectContaining({ action: 'progress', positionSeconds: 40 }), expect.any(AbortSignal))
    value.reporter.tick(40, false)
    value.advance(90_000)
    value.reporter.tick(40, false)
    value.reporter.tick(40, true)
    value.advance(30_000)
    value.reporter.tick(70, true)
    await value.reporter.settled()
    expect(value.report).toHaveBeenCalledTimes(2)
  })

  it('serializes pause, seek and end with the observed source cursor and increasing timestamps', async () => {
    const value = fixture()
    let finishFirst!: (watching: boolean) => void
    value.isWatching.mockImplementationOnce(() => new Promise(resolve => { finishFirst = resolve }))
    value.reporter.action('pause', 123)
    value.reporter.action('seek', 170)
    value.reporter.action('end', 200)
    await Promise.resolve()
    expect(value.isWatching).toHaveBeenCalledTimes(1)
    expect(value.report).not.toHaveBeenCalled()
    finishFirst(false)
    await value.reporter.settled()
    expect(value.report.mock.calls.map(call => call[1])).toEqual([
      {
        titleKind: 'episode',
        titleId: 'selectedEpisode000001',
        action: 'pause',
        positionSeconds: 123,
        reportedAt: '2026-11-28T13:00:00.000Z'
      },
      {
        titleKind: 'episode',
        titleId: 'selectedEpisode000001',
        action: 'seek',
        positionSeconds: 170,
        reportedAt: '2026-11-28T13:00:00.001Z'
      },
      {
        titleKind: 'episode',
        titleId: 'selectedEpisode000001',
        action: 'end',
        positionSeconds: 200,
        reportedAt: '2026-11-28T13:00:00.002Z'
      }
    ])
  })

  it('checks fresh party membership for every action and never replays suppressed reports after leaving', async () => {
    const value = fixture()
    value.isWatching.mockResolvedValueOnce(false).mockResolvedValueOnce(true).mockResolvedValueOnce(false)
    value.reporter.action('pause', 10)
    await value.reporter.settled()
    value.reporter.action('seek', 40)
    await value.reporter.settled()
    value.reporter.action('pause', 60)
    await value.reporter.settled()
    expect(value.isWatching).toHaveBeenCalledTimes(3)
    expect(value.report.mock.calls.map(call => call[1].positionSeconds)).toEqual([10, 60])
  })

  it('drops a stale media action after its membership request resolves', async () => {
    const value = fixture()
    let finish!: (watching: boolean) => void
    value.isWatching.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    value.reporter.action('pause', 50)
    await Promise.resolve()
    value.replace()
    finish(false)
    await value.reporter.settled()
    expect(value.report).not.toHaveBeenCalled()
  })

  it('aborts pending work and ignores queued actions on disposal', async () => {
    const value = fixture()
    value.reporter.action('pause', 50)
    await value.reporter.settled()
    const signal = value.report.mock.calls[0][2]
    value.reporter.dispose()
    value.reporter.action('end', 200)
    await value.reporter.settled()
    expect(signal.aborted).toBe(true)
    expect(value.report).toHaveBeenCalledTimes(1)
  })

  it('skips a failed membership read and permits a later fresh observation', async () => {
    const value = fixture()
    value.isWatching.mockRejectedValueOnce(new Error('Membership unavailable'))
    value.reporter.action('pause', 30)
    await value.reporter.settled()
    expect(value.report).not.toHaveBeenCalled()
    value.reporter.action('pause', 40)
    await value.reporter.settled()
    expect(value.report).toHaveBeenCalledTimes(1)
  })
})


describe('bounded final viewing reports', () => {
  it.each(['membership', 'report'] as const)('releases media transport when the %s request never settles', async blocked => {
    vi.useFakeTimers()
    const value = fixture()
    if (blocked === 'membership') {
      value.isWatching.mockImplementationOnce(() => new Promise(() => {}))
    } else {
      value.report.mockImplementationOnce(() => new Promise(() => {}))
    }
    value.reporter.action('pause', 30)
    await vi.advanceTimersByTimeAsync(0)
    const blockedSignal = blocked === 'membership'
      ? value.isWatching.mock.calls[0][1]
      : value.report.mock.calls[0][2]
    let released = false
    const flushing = value.reporter.flush().then(() => { released = true })
    await vi.advanceTimersByTimeAsync(VIEWING_FLUSH_TIMEOUT_MS - 1)
    expect(released).toBe(false)
    await vi.advanceTimersByTimeAsync(1)
    await flushing
    await value.reporter.settled()
    expect(released).toBe(true)
    expect(blockedSignal.aborted).toBe(true)
    expect(vi.getTimerCount()).toBe(0)
    value.reporter.action('seek', 50)
    await value.reporter.settled()
    expect(value.report).toHaveBeenLastCalledWith(
      'selectedVideo00000001',
      expect.objectContaining({
        action: 'seek',
        positionSeconds: 50,
        reportedAt: '2026-11-28T13:00:00.001Z'
      }),
      expect.any(AbortSignal)
    )
  })

  it('releases an aborted media switch immediately and keeps future observations usable', async () => {
    vi.useFakeTimers()
    const value = fixture()
    value.isWatching.mockImplementationOnce(() => new Promise(() => {}))
    value.reporter.action('pause', 30)
    await vi.advanceTimersByTimeAsync(0)
    const switchRequest = new AbortController()
    const flushing = value.reporter.flush(switchRequest.signal)
    switchRequest.abort()
    await flushing
    await value.reporter.settled()
    expect(vi.getTimerCount()).toBe(0)
    value.reporter.action('pause', 40)
    await value.reporter.settled()
    expect(value.report).toHaveBeenCalledTimes(1)
  })

  it('cancels its flush deadline and pending work on disposal', async () => {
    vi.useFakeTimers()
    const value = fixture()
    value.isWatching.mockImplementationOnce(() => new Promise(() => {}))
    value.reporter.action('pause', 30)
    await vi.advanceTimersByTimeAsync(0)
    const flushing = value.reporter.flush()
    expect(vi.getTimerCount()).toBe(1)
    value.reporter.dispose()
    await flushing
    await value.reporter.settled()
    expect(value.isWatching.mock.calls[0][1].aborted).toBe(true)
    expect(value.report).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })
})
