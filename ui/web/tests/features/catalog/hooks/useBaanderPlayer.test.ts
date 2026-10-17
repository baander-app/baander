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
    window.__BAANDER_API_URL__ = 'https://media.baander.app'
  })
  afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks() })

  it('waits for authorization and uses the signed manifest', async () => {
    let resolve!: (value: { data: { url: string, ready?: boolean, generation?: number, revision?: number, jobId?: string, sessionId?: string | null, position?: number, status?: string, startTime?: number, duration?: number } }) => void
    post.mockReturnValue(new Promise((done) => { resolve = done }))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    const video = containerRef.current.querySelector('video')!
    expect(video.getAttribute('src')).toBeNull()
    expect(post).toHaveBeenCalledWith('/api/stream/sign', {
      path: '/api/transcode/video/master.m3u8',
    }, { signal: expect.any(AbortSignal), timeout: 5000 })
    await act(async () => resolve({ data: { url: '/api/transcode/video/master.m3u8?sig=proof&exp=100', ready: true, startTime: 0, duration: 100, generation: 1, revision: 1, jobId: 'job', sessionId: null, position: 0, status: 'running' } }))
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
      jobId: 'job', ready: true, startTime: 0, duration: 100, position, status,
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
    const snapshot = (generation: number) => ({ data: { url: `/manifest?generation=${generation}`, jobId: 'job', generation, revision: generation, ready: true, startTime: 0, duration: 100, position: generation * 10, status: 'paused' } })
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
    post.mockResolvedValue({ data: { url: '/manifest?generation=1', jobId: 'job', sessionId: 'owned-session', generation: 1, revision: 1, ready: true, startTime: 0, duration: 100, position: 0, status: 'running' } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 42
    await act(async () => video.dispatchEvent(new Event('seeked')))
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', { position: 42, action: 'seek' }, { signal: expect.any(AbortSignal), timeout: 5000 })
    await act(async () => video.dispatchEvent(new Event('pause')))
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', { position: 42, action: 'pause' }, { signal: expect.any(AbortSignal), timeout: 5000 })
    unmount()
  })

  it('sends bounded playing progress without repeated or paused heartbeats', async () => {
    vi.useFakeTimers()
    const snapshot = { url: '/manifest?generation=1', jobId: 'job', sessionId: 'owned-session', generation: 1, revision: 5, ready: true, startTime: 0, duration: 100, position: 0, status: 'running' }
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
    let finishProgress!: (value: { data: object }) => void
    post.mockImplementation((path: string, payload: { action?: string }) => {
      if (path === '/api/stream/sign') return Promise.resolve({ data: { url: '/manifest', jobId: 'job', sessionId: 'owned-session', generation: 1, revision: 5, ready: true, startTime: 0, duration: 100, position: 0, status: 'running' } })
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
    await act(async () => video.dispatchEvent(new Event('seeked')))
    expect(post.mock.calls.filter((call) => call[1].action === 'seek')).toHaveLength(0)
    await act(async () => finishProgress({ data: {} }))
    expect(post.mock.calls.filter((call) => call[1].action === 'seek').at(-1)?.[1]).toEqual({ position: 42, action: 'seek' })
    unmount()
  })

  it('restores a global seek on the generation local timeline and reports global progress', async () => {
    vi.useFakeTimers()
    post.mockImplementation(async (path: string) => path === '/api/stream/sign' ? { data: {
      url: '/manifest?generation=3', jobId: 'job', sessionId: 'owned-session', generation: 3, revision: 5,
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
    let resolve!: (value: { data: { url: string, ready?: boolean, generation?: number, revision?: number, jobId?: string, sessionId?: string | null, position?: number, status?: string, startTime?: number, duration?: number } }) => void
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
