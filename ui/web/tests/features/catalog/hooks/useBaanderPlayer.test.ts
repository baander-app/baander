import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useBaanderPlayer } from '@/features/catalog/hooks/useBaanderPlayer'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { post } }))

describe('useBaanderPlayer authorization', () => {
  beforeEach(() => {
    post.mockReset()
    vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {})
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue()
    vi.spyOn(HTMLMediaElement.prototype, 'duration', 'get').mockReturnValue(1200)
    vi.spyOn(HTMLMediaElement.prototype, 'readyState', 'get').mockReturnValue(HTMLMediaElement.HAVE_METADATA)
    window.__BAANDER_API_URL__ = 'https://media.baander.app'
  })
  afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks() })

  it.each([
    { position: 0, startTime: 0, status: 'running', nativeTime: 0, shouldSeek: false },
    { position: 0, startTime: 0, status: 'running', nativeTime: 0.002, shouldSeek: false },
    { position: 162, startTime: 162, status: 'paused', nativeTime: 0.002, shouldSeek: false },
    { position: 0, startTime: 0, status: 'paused', nativeTime: 1, shouldSeek: true },
    { position: 180, startTime: 162, status: 'paused', nativeTime: 0, shouldSeek: true },
  ])('restores $status global position $position from native time $nativeTime without unnecessary seeking', async ({ position, startTime, status, nativeTime, shouldSeek }) => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 1, revision: 1, ready: true, startTime, duration: 180, position, status } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    let seeking = false
    const setTime = vi.fn(() => { seeking = true })
    Object.defineProperty(video, 'currentTime', { configurable: true, get: () => nativeTime, set: setTime })
    Object.defineProperty(video, 'seeking', { configurable: true, get: () => seeking })
    vi.mocked(video.pause).mockClear()
    vi.mocked(video.play).mockClear()
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    if (shouldSeek) expect(setTime).toHaveBeenCalledExactlyOnceWith(position - startTime)
    else expect(setTime).not.toHaveBeenCalled()
    expect(video.seeking).toBe(shouldSeek)
    if (status === 'paused') expect(video.pause).toHaveBeenCalled()
    else expect(video.play).toHaveBeenCalled()
    unmount()
  })

  it('waits for authorization and uses the signed manifest', async () => {
    let resolve!: (value: { data: { url: string, ready?: boolean, generation?: number, revision?: number, jobId?: string, sessionId?: string | null, position?: number, status?: string, startTime?: number, duration?: number, renditionSet?: string } }) => void
    post.mockReturnValue(new Promise((done) => { resolve = done }))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    const video = containerRef.current.querySelector('video')!
    expect(video.getAttribute('src')).toBeNull()
    expect(post).toHaveBeenCalledWith('/api/stream/sign', {
      path: '/api/transcode/video/master.m3u8',
    }, { signal: expect.any(AbortSignal), timeout: 5000 })
    await act(async () => resolve({ data: { url: '/api/transcode/video/master.m3u8?sig=proof&exp=100', ready: true, startTime: 0, duration: 100, generation: 1, revision: 1, jobId: 'job', renditionSet: 'set', sessionId: null, position: 0, status: 'running' } }))
    expect(video.src).toBe('https://media.baander.app/api/transcode/video/master.m3u8?sig=proof&exp=100')
    expect(video.play).toHaveBeenCalledOnce()
    unmount()
  })

  it('does not load a manifest when authorization fails', async () => {
    post.mockRejectedValue(new Error('Forbidden'))
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await waitFor(() => expect(result.current.state).toBe('error'))
    expect(containerRef.current.querySelector('video')!.getAttribute('src')).toBeNull()
    unmount()
  })

  it('reloads the committed generation and preserves paused position', async () => {
    vi.useFakeTimers()
    const snapshot = (generation: number, position: number, status: string) => ({ data: {
      url: `/manifest?generation=${generation}`, generation, revision: generation,
      jobId: 'job', renditionSet: 'set', ready: true, startTime: 0, duration: 100, position, status,
    } })
    post.mockResolvedValueOnce(snapshot(1, 0, 'running'))
      .mockResolvedValueOnce(snapshot(2, 42, 'paused'))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).toContain('generation=2')
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(42)
    expect(video.pause).toHaveBeenCalled()
    expect(post.mock.calls[1][1]).toEqual({ path: '/api/transcode/video/master.m3u8', refresh: true })
    unmount()
    vi.useRealTimers()
  })

  it('ignores an older refresh that completes after a newer generation', async () => {
    vi.useFakeTimers()
    const snapshot = (generation: number) => ({ data: { url: `/manifest?generation=${generation}`, jobId: 'job', renditionSet: 'set', generation, revision: generation, ready: true, startTime: 0, duration: 100, position: generation * 10, status: 'paused' } })
    let resolveOld!: (value: ReturnType<typeof snapshot>) => void
    post.mockResolvedValueOnce(snapshot(1))
      .mockReturnValueOnce(new Promise((done) => { resolveOld = done }))
      .mockResolvedValueOnce(snapshot(3))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    const video = containerRef.current.querySelector('video')!
    expect(video.src).toContain('generation=3')
    await act(async () => resolveOld(snapshot(2)))
    expect(video.src).toContain('generation=3')
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(30)
    unmount()
    vi.useRealTimers()
  })

  it('persists native seek and pause through the authorized current session', async () => {
    post.mockResolvedValue({ data: { url: '/manifest?generation=1', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 1, revision: 1, ready: true, startTime: 0, duration: 100, position: 0, status: 'running' } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 42
    await act(async () => {
      video.dispatchEvent(new Event('seeking'))
      video.dispatchEvent(new Event('seeked'))
    })
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', { position: 42, action: 'seek', generation: 1 }, { signal: expect.any(AbortSignal), timeout: 5000 })
    await act(async () => video.dispatchEvent(new Event('pause')))
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', { position: 42, action: 'pause' }, { signal: expect.any(AbortSignal), timeout: 5000 })
    unmount()
  })

  it('keeps a decoder failure as an error without committing its native pause as user intent', async () => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 1, revision: 1, ready: true, startTime: 0, duration: 100, position: 0, status: 'running' } })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    Object.defineProperty(video, 'error', { configurable: true, value: { code: 4, message: 'Media source is unavailable' } })
    await act(async () => video.dispatchEvent(new Event('error')))
    expect(result.current.state).toBe('error')
    await act(async () => video.dispatchEvent(new Event('pause')))
    expect(result.current.state).toBe('error')
    expect(onError).toHaveBeenCalledWith(video.error)
    expect(post.mock.calls.filter((call) => call[1].action === 'pause')).toHaveLength(0)
    unmount()
  })

  it('sends bounded playing progress without repeated or paused heartbeats', async () => {
    vi.useFakeTimers()
    const snapshot = { url: '/manifest?generation=1', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 1, revision: 5, ready: true, startTime: 0, duration: 100, position: 0, status: 'running' }
    post.mockImplementation(async (path: string, payload: { action?: string, position?: number }) => {
      if (path === '/api/stream/sign') return { data: { ...snapshot } }
      if (payload.action === 'pause') snapshot.status = 'paused'
      return { data: {} }
    })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 10
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    const progress = () => post.mock.calls.filter((call) => call[1].action === 'progress')
    expect(progress()).toHaveLength(1)
    expect(progress()[0][1]).toEqual({ action: 'progress', position: 10, generation: 1, revision: 5 })
    await act(async () => vi.advanceTimersByTimeAsync(3000))
    expect(progress()).toHaveLength(1)
    Object.defineProperty(video, 'paused', { configurable: true, value: true })
    await act(async () => video.dispatchEvent(new Event('pause')))
    video.currentTime = 11
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    expect(progress()).toHaveLength(1)
    unmount()
  })

  it('keeps one heartbeat in flight and orders a later seek after it', async () => {
    vi.useFakeTimers()
    let revision = 5
    let finishProgress!: (value: { data: object }) => void
    post.mockImplementation((path: string, payload: { action?: string }) => {
      if (path === '/api/stream/sign') return Promise.resolve({ data: { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 1, revision, ready: true, startTime: 0, duration: 100, position: 0, status: 'running' } })
      if (payload.action === 'progress') return new Promise((resolve) => { finishProgress = resolve })
      return Promise.resolve({ data: {} })
    })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 10
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    video.currentTime = 20
    await act(async () => vi.advanceTimersByTimeAsync(3000))
    expect(post.mock.calls.filter((call) => call[1].action === 'progress')).toHaveLength(1)
    video.currentTime = 42
    await act(async () => {
      video.dispatchEvent(new Event('seeking'))
      video.dispatchEvent(new Event('seeked'))
    })
    expect(post.mock.calls.filter((call) => call[1].action === 'seek')).toHaveLength(0)
    revision = 6
    await act(async () => finishProgress({ data: {} }))
    expect(post.mock.calls.filter((call) => call[1].action === 'seek').at(-1)?.[1]).toEqual({ position: 42, action: 'seek', generation: 1 })
    unmount()
  })

  it('keeps a queued native seek bound to the generation that emitted it', async () => {
    vi.useFakeTimers()
    let generation = 1
    let finishProgress!: (value: { data: object }) => void
    post.mockImplementation((path: string, payload: { action?: string }) => {
      if (path === '/api/stream/sign') return Promise.resolve({ data: { url: `/manifest?generation=${generation}`, jobId: 'job', renditionSet: `job.${generation}`, sessionId: 'owned-session', generation, revision: generation + 4, ready: true, startTime: 0, duration: 100, position: 0, status: 'running' } })
      if (payload.action === 'progress') return new Promise((resolve) => { finishProgress = resolve })
      return Promise.resolve({ data: {} })
    })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 10
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    video.currentTime = 42
    await act(async () => {
      video.dispatchEvent(new Event('seeking'))
      video.dispatchEvent(new Event('seeked'))
    })
    generation = 2
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).toContain('generation=2')
    await act(async () => finishProgress({ data: {} }))
    expect(post.mock.calls.filter((call) => call[1].action === 'seek').at(-1)?.[1]).toEqual({ position: 42, action: 'seek', generation: 1 })
    unmount()
  })

  it.each([7, undefined])('retains only the latest native target through its own accepted generation %s', async (acceptedGeneration) => {
    vi.useFakeTimers()
    const snapshot = (generation: number) => ({ data: {
      url: `/manifest?generation=${generation}`, jobId: 'job', renditionSet: `job.${generation}`,
      sessionId: 'owned-session', generation, revision: generation, ready: true,
      position: generation === 4 ? 30 : 45, status: 'running', startTime: 12, duration: 180,
    } })
    let finishOldRefresh!: (value: ReturnType<typeof snapshot>) => void
    let finishPause!: (value: { data: object }) => void
    let finishSeek!: (value: { data: { data: { acceptedGeneration?: number } } }) => void
    let refreshes = 0
    post.mockImplementation((path: string, payload: { action?: string, position?: number }) => {
      if (path === '/api/stream/sign') {
        refreshes++
        if (refreshes === 2) return new Promise((resolve) => { finishOldRefresh = resolve })
        return Promise.resolve(snapshot(refreshes <= 3 ? 4 : 9))
      }
      if (payload.action === 'pause') return new Promise((resolve) => { finishPause = resolve })
      if (payload.position === 45) return new Promise((resolve) => { finishSeek = resolve })
      return Promise.resolve({ data: { data: {} } })
    })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    await act(async () => video.dispatchEvent(new Event('pause')))
    video.currentTime = 33
    await act(async () => video.dispatchEvent(new Event('seeking')))
    video.currentTime = 43
    await act(async () => video.dispatchEvent(new Event('seeking')))
    video.currentTime = 53
    await act(async () => video.dispatchEvent(new Event('seeking')))
    await act(async () => finishPause({ data: {} }))
    await act(async () => finishOldRefresh(snapshot(7)))
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).toContain('generation=4')
    const seeks = () => post.mock.calls.filter((call) => call[1].action === 'seek').map((call) => call[1])
    expect(seeks()).toEqual([{ action: 'seek', position: 45, generation: 4 }])
    await act(async () => finishSeek({ data: { data: { acceptedGeneration } } }))
    expect(seeks()).toEqual([
      { action: 'seek', position: 45, generation: 4 },
      ...(acceptedGeneration === undefined ? [] : [{ action: 'seek', position: 65, generation: acceptedGeneration }]),
    ])
    expect(video.src).toContain('generation=9')
    unmount()
  })

  it('keeps explicit seek intent independent of the attached generation', async () => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', renditionSet: 'job.4', sessionId: 'owned-session', generation: 4, revision: 8, ready: true, position: 0, status: 'paused', startTime: 0, duration: 180 } })
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => result.current.seekTo(60))
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', { position: 60, action: 'seek' }, { signal: expect.any(AbortSignal), timeout: 5000 })
    unmount()
  })

  it('restores a global seek on the generation local timeline and reports global progress', async () => {
    vi.useFakeTimers()
    post.mockImplementation(async (path: string) => path === '/api/stream/sign' ? { data: {
      url: '/manifest?generation=3', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 3, revision: 5,
      ready: true, position: 180, status: 'running', startTime: 162, duration: 600,
    } } : { data: {} })
    const timeUpdate = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onTimeUpdate: timeUpdate }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(18)
    video.currentTime = 30
    await act(async () => video.dispatchEvent(new Event('timeupdate')))
    expect(result.current.currentTime).toBe(192)
    expect(result.current.duration).toBe(600)
    expect(timeUpdate).toHaveBeenLastCalledWith(192, 600)
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(post.mock.calls.find((call) => call[1].action === 'progress')?.[1].position).toBe(192)
    unmount()
  })

  it.each([0, 180])('keeps paused restoration authoritative when the native duration is %s and its seek clamps', async (initialDuration) => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 4, revision: 8, ready: true, position: 30, status: 'paused', startTime: 12, duration: 180 } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    let nativeTime = 0
    let availableDuration = initialDuration
    let mediaAvailable = false
    const setTime = vi.fn((value: number) => { nativeTime = mediaAvailable ? value : 0 })
    Object.defineProperty(video, 'currentTime', { configurable: true, get: () => nativeTime, set: setTime })
    Object.defineProperty(video, 'duration', { configurable: true, get: () => availableDuration })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => video.dispatchEvent(new Event('seeked')))
    expect(post.mock.calls.filter((call) => call[1].action)).toHaveLength(0)
    expect(setTime).toHaveBeenCalledTimes(initialDuration === 0 ? 0 : 1)
    expect(nativeTime).toBe(0)
    availableDuration = 180
    mediaAvailable = true
    await act(async () => video.dispatchEvent(new Event('durationchange')))
    expect(nativeTime).toBe(18)
    expect(video.pause).toHaveBeenCalled()
    await act(async () => video.dispatchEvent(new Event('seeked')))
    expect(post.mock.calls.filter((call) => call[1].action)).toHaveLength(0)
    nativeTime = 20
    await act(async () => {
      video.dispatchEvent(new Event('seeking'))
      video.dispatchEvent(new Event('seeked'))
    })
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', { position: 32, action: 'seek', generation: 4 }, { signal: expect.any(AbortSignal), timeout: 5000 })
    unmount()
  })

  it('ignores old media seek completion while the committed generation is preparing', async () => {
    vi.useFakeTimers()
    const initial = { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 1, revision: 1, ready: true, position: 0, status: 'running', startTime: 0, duration: 180 }
    post.mockResolvedValueOnce({ data: initial }).mockResolvedValue({ data: { ...initial, generation: 2, revision: 3, ready: false, position: 30, status: 'paused', startTime: null, duration: null, url: null, renditionSet: '' } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    await act(async () => video.dispatchEvent(new Event('seeked')))
    expect(post.mock.calls.filter((call) => call[1].action)).toHaveLength(0)
    unmount()
  })

  it('commits an explicit user seek while native restoration is waiting for media', async () => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 4, revision: 8, ready: true, position: 30, status: 'paused', startTime: 12, duration: 180 } })
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'duration', { configurable: true, value: 0 })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => result.current.seekTo(60))
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', { position: 60, action: 'seek' }, { signal: expect.any(AbortSignal), timeout: 5000 })
    unmount()
  })

  it('waits for metadata and a known native timeline before restoring a nonzero cursor', async () => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 4, revision: 8, ready: true, position: 30, status: 'paused', startTime: 12, duration: 180 } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    let duration = Number.NaN
    let ready = HTMLMediaElement.HAVE_NOTHING
    let nativeTime = 0
    const setTime = vi.fn((value: number) => { nativeTime = value })
    Object.defineProperty(video, 'currentTime', { configurable: true, get: () => nativeTime, set: setTime })
    Object.defineProperty(video, 'duration', { configurable: true, get: () => duration })
    Object.defineProperty(video, 'readyState', { configurable: true, get: () => ready })
    await act(async () => video.dispatchEvent(new Event('progress')))
    expect(setTime).not.toHaveBeenCalled()
    ready = HTMLMediaElement.HAVE_METADATA
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(setTime).not.toHaveBeenCalled()
    duration = 180
    await act(async () => video.dispatchEvent(new Event('durationchange')))
    expect(setTime).toHaveBeenCalledExactlyOnceWith(18)
    unmount()
  })

  it('keeps a backward seek authoritative through pending and claimed generations', async () => {
    vi.useFakeTimers()
    const snapshot = (generation: number, position: number, ready = true) => ({ data: {
      url: `/manifest?generation=${generation}`, jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation, revision: generation,
      ready, position, status: 'paused', startTime: ready ? (generation === 1 ? 0 : 42) : null, duration: ready ? 1200 : null,
    } })
    post.mockResolvedValueOnce(snapshot(1, 600)).mockResolvedValueOnce(snapshot(2, 60, false)).mockResolvedValueOnce(snapshot(3, 60))
      .mockResolvedValueOnce(snapshot(4, 60, false)).mockResolvedValueOnce(snapshot(5, 60))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(600)
    await act(async () => vi.advanceTimersByTimeAsync(4000))
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(18)
    unmount()
  })

  it('keeps an unchanged committed paused cursor authoritative over an advanced old media clock', async () => {
    vi.useFakeTimers()
    const initial = { url: '/manifest', jobId: 'job', renditionSet: 'first', sessionId: 'owned-session', generation: 1, revision: 1, ready: true, position: 30, status: 'running', startTime: 0, duration: 180 }
    let committed: Omit<typeof initial, 'url' | 'startTime' | 'duration'> & { url: string | null, startTime: number | null, duration: number | null } = initial
    post.mockImplementation(async (path: string) => ({ data: path === '/api/stream/sign' ? { ...committed } : {} }))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => video.dispatchEvent(new Event('seeked')))
    video.currentTime = 33.246624
    vi.mocked(video.pause).mockClear()
    committed = { ...committed, generation: 2, revision: 3, status: 'paused', ready: false, renditionSet: '', url: null, startTime: null, duration: null }
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.pause).toHaveBeenCalled()
    committed = { ...committed, generation: 3, ready: true, renditionSet: 'recovered', url: '/recovered', startTime: 12, duration: 180 }
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(18)
    await act(async () => {
      video.dispatchEvent(new Event('seeked'))
      video.dispatchEvent(new Event('seeked'))
    })
    expect(post.mock.calls.filter((call) => call[1].action)).toHaveLength(0)
    unmount()
  })

  it('keeps a rapid resume queued while the preceding pause is committing', async () => {
    const snapshot = { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 1, revision: 1, ready: true, position: 0, status: 'running', startTime: 0, duration: 100 }
    let commitPause!: () => void
    post.mockImplementation((path: string, payload: { action?: string }) => {
      if (path === '/api/stream/sign') return Promise.resolve({ data: { ...snapshot } })
      if (payload.action === 'pause') return new Promise((resolve) => { commitPause = () => { snapshot.status = 'paused'; snapshot.revision++; resolve({ data: {} }) } })
      if (payload.action === 'resume') { snapshot.status = 'running'; snapshot.revision++ }
      return Promise.resolve({ data: {} })
    })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    Object.defineProperty(video, 'paused', { configurable: true, value: true })
    await act(async () => video.dispatchEvent(new Event('pause')))
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => video.dispatchEvent(new Event('play')))
    await act(async () => commitPause())
    expect(post.mock.calls.filter((call) => call[1].action).map((call) => call[1].action)).toEqual(['pause', 'resume'])
    unmount()
  })

  it.each([['running', 'paused'], ['paused', 'running']])('applies the latest %s to %s control when metadata arrives', async (initial, latest) => {
    vi.useFakeTimers()
    const snapshot = (status: string, revision: number) => ({ data: { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 1, revision, ready: true, position: 0, status, startTime: 0, duration: 100 } })
    post.mockResolvedValueOnce(snapshot(initial, 1)).mockResolvedValue(snapshot(latest, 2))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, writable: true, value: initial === 'paused' })
    vi.mocked(video.play).mockImplementation(async () => { Object.defineProperty(video, 'paused', { value: false }) })
    vi.mocked(video.pause).mockImplementation(() => { Object.defineProperty(video, 'paused', { value: true }) })
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.paused).toBe(latest === 'paused')
    await act(async () => video.dispatchEvent(new Event(latest === 'running' ? 'pause' : 'play')))
    expect(post.mock.calls.filter((call) => call[1].action)).toHaveLength(0)
    unmount()
  })

  it('does not restore playback after a control response arrives past destroy', async () => {
    let finishPause!: (value: { data: object }) => void
    post.mockImplementation((path: string) => path === '/api/stream/sign'
      ? Promise.resolve({ data: { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session', generation: 1, revision: 1, ready: true, position: 0, status: 'running', startTime: 0, duration: 100 } })
      : new Promise((resolve) => { finishPause = resolve }))
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('pause')))
    act(() => result.current.destroy())
    vi.mocked(video.play).mockClear()
    await act(async () => finishPause({ data: {} }))
    expect(video.play).not.toHaveBeenCalled()
    unmount()
  })

  it('does not treat an in-flight programmatic restoration as native seek intent', async () => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', sessionId: 'owned-session',
      generation: 4, revision: 11, ready: true, position: 33, status: 'paused',
      startTime: 12, duration: 180, renditionSet: 'job.4' } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    let nativeTime = 0
    let seeking = false
    Object.defineProperty(video, 'currentTime', { configurable: true,
      get: () => nativeTime, set: (value: number) => { nativeTime = value; seeking = true } })
    Object.defineProperty(video, 'seeking', { configurable: true, get: () => seeking })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(nativeTime).toBe(21)
    expect(seeking).toBe(true)
    await act(async () => video.dispatchEvent(new Event('seeking')))
    expect(post.mock.calls.filter((call) => call[1].action)).toHaveLength(0)
    seeking = false
    await act(async () => video.dispatchEvent(new Event('seeked')))
    expect(post.mock.calls.filter((call) => call[1].action)).toHaveLength(0)
    unmount()
  })

  it('submits a paused native seek before decoding completes and does not duplicate its completion', async () => {
    const snapshot = { url: '/manifest', jobId: 'job', sessionId: 'owned-session',
      generation: 4, revision: 11, ready: true, position: 33, status: 'paused',
      startTime: 12, duration: 180, renditionSet: 'job.4' }
    post.mockImplementation((path: string) => path === '/api/stream/sign'
      ? Promise.resolve({ data: snapshot })
      : new Promise(() => {}))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => video.dispatchEvent(new Event('seeking')))
    expect(post.mock.calls.filter((call) => call[1].action)).toHaveLength(0)
    video.currentTime = 23
    Object.defineProperty(video, 'seeking', { configurable: true, writable: true, value: true })
    await act(async () => video.dispatchEvent(new Event('seeking')))
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', {
      position: 35, action: 'seek', generation: 4,
    }, { signal: expect.any(AbortSignal), timeout: 5000 })
    Object.defineProperty(video, 'seeking', { value: false })
    await act(async () => video.dispatchEvent(new Event('seeked')))
    expect(post.mock.calls.filter((call) => call[1].action === 'seek')).toHaveLength(1)
    unmount()
  })

  it('commits a native seek when sibling renditions arrive before seeked', async () => {
    vi.useFakeTimers()
    const snapshot = (renditionSet: string) => ({ data: {
      url: `/manifest?set=${renditionSet}`, jobId: 'job', sessionId: 'owned-session',
      generation: 4, revision: 11, ready: true, position: 33, status: 'paused',
      startTime: 12, duration: 180, renditionSet,
    } })
    post.mockResolvedValueOnce(snapshot('job.4')).mockResolvedValue(snapshot('job.4,sibling.3'))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    Object.defineProperty(video, 'seeking', { configurable: true, writable: true, value: true })
    video.currentTime = 23
    await act(async () => video.dispatchEvent(new Event('seeking')))
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).not.toContain('sibling.3')
    Object.defineProperty(video, 'seeking', { value: false })
    await act(async () => video.dispatchEvent(new Event('seeked')))
    expect(post.mock.calls.filter((call) => call[1].action === 'seek')).toHaveLength(1)
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', {
      position: 35, action: 'seek', generation: 4,
    }, { signal: expect.any(AbortSignal), timeout: 5000 })
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).toContain('sibling.3')
    unmount()
  })

  it.each([
    { generation: 5, revision: 11, status: 'paused' },
    { generation: 4, revision: 12, status: 'running' },
  ])('applies committed generation $generation revision $revision while native seeking', async (committed) => {
    vi.useFakeTimers()
    const initial = { url: '/manifest?set=job.4', jobId: 'job', sessionId: 'owned-session',
      generation: 4, revision: 11, ready: true, position: 33, status: 'paused',
      startTime: 12, duration: 180, renditionSet: 'job.4' }
    post.mockResolvedValueOnce({ data: initial }).mockResolvedValue({ data: {
      ...initial, ...committed, position: 42, renditionSet: `job.${committed.generation},sibling.3`,
      url: `/manifest?set=job.${committed.generation},sibling.3`,
    } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    Object.defineProperty(video, 'seeking', { configurable: true, writable: true, value: true })
    video.currentTime = 23
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).toContain('sibling.3')
    Object.defineProperty(video, 'seeking', { value: false })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(30)
    await act(async () => {
      video.dispatchEvent(new Event('seeking'))
      video.dispatchEvent(new Event('seeked'))
    })
    expect(post.mock.calls.filter((call) => call[1].action)).toHaveLength(0)
    unmount()
  })

  it('attaches a prepared replacement while the retired native source is still seeking', async () => {
    vi.useFakeTimers()
    const initial = { url: '/manifest?generation=4', jobId: 'job', sessionId: 'owned-session',
      generation: 4, revision: 11, ready: true, position: 33, status: 'paused',
      startTime: 12, duration: 180, renditionSet: 'job.4' }
    post.mockResolvedValueOnce({ data: initial })
      .mockResolvedValueOnce({ data: { ...initial, generation: 5, revision: 12, position: 42,
        ready: false, url: null, startTime: null, duration: null, renditionSet: '' } })
      .mockResolvedValue({ data: { ...initial, generation: 5, revision: 12, position: 42,
        url: '/manifest?generation=5', startTime: 24, renditionSet: 'job.5,sibling.3' } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    Object.defineProperty(video, 'seeking', { configurable: true, writable: true, value: true })
    video.currentTime = 30
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).toContain('generation=4')
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).toContain('generation=5')
    Object.defineProperty(video, 'seeking', { value: false })
    video.currentTime = 0
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(18)
    await act(async () => {
      video.dispatchEvent(new Event('seeking'))
      video.dispatchEvent(new Event('seeked'))
    })
    expect(post.mock.calls.filter((call) => call[1].action)).toHaveLength(0)
    unmount()
  })

  it('reloads a changed rendition set while preserving the attached cursor and pause state', async () => {
    vi.useFakeTimers()
    const snapshot = (renditionSet: string) => ({ data: { url: `/manifest?set=${renditionSet}`, jobId: 'job', sessionId: 'owned-session', generation: 1, revision: 5, ready: true, position: 60, status: 'paused', startTime: 42, duration: 600, renditionSet } })
    post.mockResolvedValueOnce(snapshot('primary.1')).mockResolvedValueOnce(snapshot('primary.1,sibling.4'))
      .mockResolvedValue(snapshot('primary.1,sibling.4,third.7'))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 30
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).toContain('sibling.4')
    video.currentTime = 0
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).toContain('third.7')
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(30)
    expect(video.play).not.toHaveBeenCalled()
    unmount()
  })

  it.each([['running', 'pause'], ['paused', 'resume']])('reconciles rejected %s to %s intent with a committed generation', async (committed, action) => {
    vi.useFakeTimers()
    let generation = 1
    post.mockImplementation((path: string) => path === '/api/stream/sign'
      ? Promise.resolve({ data: { url: `/manifest?generation=${generation}`, jobId: 'job', renditionSet: `job.${generation}`, sessionId: 'owned-session', generation, revision: generation, ready: true, position: 0, status: committed, startTime: 0, duration: 100 } })
      : Promise.reject(new Error('Control rejected')))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, writable: true, value: committed === 'paused' })
    vi.mocked(video.play).mockImplementation(async () => { Object.defineProperty(video, 'paused', { value: false }) })
    vi.mocked(video.pause).mockImplementation(() => { Object.defineProperty(video, 'paused', { value: true }) })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    Object.defineProperty(video, 'paused', { value: action === 'pause' })
    await act(async () => video.dispatchEvent(new Event(action === 'pause' ? 'pause' : 'play')))
    generation = 2
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.paused).toBe(committed === 'paused')
    unmount()
  })

  it('does not discard a newer pause when an older resume is rejected', async () => {
    vi.useFakeTimers()
    let generation = 1
    let rejectResume!: (error: Error) => void
    post.mockImplementation((path: string, payload: { action?: string }) => {
      if (path === '/api/stream/sign') return Promise.resolve({ data: { url: `/manifest?generation=${generation}`, jobId: 'job', renditionSet: `job.${generation}`, sessionId: 'owned-session', generation, revision: generation, ready: true, position: 0, status: generation === 1 ? 'paused' : 'running', startTime: 0, duration: 100 } })
      if (payload.action === 'resume') return new Promise((_resolve, reject) => { rejectResume = reject })
      return new Promise(() => {})
    })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, writable: true, value: true })
    vi.mocked(video.play).mockImplementation(async () => { Object.defineProperty(video, 'paused', { value: false }) })
    vi.mocked(video.pause).mockImplementation(() => { Object.defineProperty(video, 'paused', { value: true }) })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    Object.defineProperty(video, 'paused', { value: false })
    await act(async () => video.dispatchEvent(new Event('play')))
    Object.defineProperty(video, 'paused', { value: true })
    await act(async () => video.dispatchEvent(new Event('pause')))
    await act(async () => rejectResume(new Error('Old resume rejected')))
    generation = 2
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.paused).toBe(true)
    expect(post.mock.calls.filter((call) => call[1].action).map((call) => call[1].action)).toEqual(['resume', 'pause'])
    unmount()
  })

  it('destroy aborts requests and stops polling before unmount', async () => {
    vi.useFakeTimers()
    post.mockReturnValue(new Promise(() => {}))
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    const signal = post.mock.calls[0][2].signal as AbortSignal
    act(() => result.current.destroy())
    await act(async () => vi.advanceTimersByTimeAsync(3000))
    expect(signal.aborted).toBe(true)
    expect(post).toHaveBeenCalledTimes(1)
    expect(containerRef.current.querySelector('video')).toBeNull()
    unmount()
  })

  it('aborts signing and ignores late responses after cleanup', async () => {
    let resolve!: (value: { data: { url: string, ready?: boolean, generation?: number, revision?: number, jobId?: string, sessionId?: string | null, position?: number, status?: string, startTime?: number, duration?: number, renditionSet?: string } }) => void
    post.mockReturnValue(new Promise((done) => { resolve = done }))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    const video = containerRef.current.querySelector('video')!
    const signal = post.mock.calls[0][2].signal as AbortSignal
    unmount()
    await act(async () => resolve({ data: { url: '/signed' } }))
    expect(signal.aborted).toBe(true)
    expect(video.getAttribute('src')).toBe('')
    expect(video.play).not.toHaveBeenCalled()
  })
})
