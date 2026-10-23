import { createElement, StrictMode } from 'react'
import { AxiosError, CanceledError } from 'axios'
import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useBaanderPlayer } from '@/features/catalog/hooks/useBaanderPlayer'

const { post, attachments, loader } = vi.hoisted(() => ({ post: vi.fn(), loader: { ready: true },
  attachments: [] as Array<{ onError: (failure?: 'network') => void, onReady: () => void, position: number, dispose: ReturnType<typeof vi.fn> }> }))
vi.mock('@/features/catalog/hooks/hls-playback', () => ({
  attachHlsPlayback: ({ video, url, position, onReady, onError }: { video: HTMLVideoElement, url: string, position: number, onReady: () => void, onError: (failure?: 'network') => void }) => {
    const dispose = vi.fn()
    attachments.push({ onError, onReady, position, dispose })
    video.src = url
    if (loader.ready) onReady()
    return dispose
  },
}))
vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { post } }))

describe('useBaanderPlayer authorization', () => {
  beforeEach(() => {
    post.mockReset()
    attachments.length = 0
    loader.ready = true
    vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {})
    vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {})
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue()
    vi.spyOn(HTMLMediaElement.prototype, 'duration', 'get').mockReturnValue(1200)
    vi.spyOn(HTMLMediaElement.prototype, 'readyState', 'get').mockReturnValue(HTMLMediaElement.HAVE_METADATA)
    vi.spyOn(HTMLMediaElement.prototype, 'seekable', 'get').mockReturnValue({ length: 1, start: () => 0, end: () => 1200 })
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

  it.each(['paused', 'running'])('renews the same execution near expiry while preserving its %s cursor', async status => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const initialExpiry = Math.floor(Date.now() / 1000) + 20
    post.mockImplementation(() => Promise.resolve({ data: {
      url: `/manifest?exp=${Math.floor(Date.now() / 1000) + 20}`, jobId: 'job',
      generation: 1, revision: 1, renditionSet: 'job.1', ready: true,
      position: 42, startTime: 24, duration: 180, status,
    } }))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 30
    vi.mocked(video.play).mockClear()
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(1)
    await act(async () => vi.advanceTimersByTimeAsync(15000))
    expect(attachments).toHaveLength(2)
    expect(attachments[0].dispose).toHaveBeenCalledOnce()
    expect(attachments[1].position).toBe(30)
    expect(Number(new URL(video.src).searchParams.get('exp'))).toBeGreaterThan(initialExpiry)
    if (status === 'paused') expect(video.play).not.toHaveBeenCalled()
    else expect(video.play).toHaveBeenCalled()
    video.currentTime = 0
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(30)
    expect(post.mock.calls.filter(call => call[1]?.action)).toHaveLength(0)
    unmount()
  })

  it.each(['', 'exp=invalid', 'exp=-1', 'exp=1.5', 'exp=9007199254740992'])('does not loop when an attached expiry is missing or malformed: %s', async query => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    post.mockResolvedValueOnce({ data: { url: `/manifest?${query}`, jobId: 'job', generation: 1,
      revision: 1, renditionSet: 'job.1', ready: true, position: 0, startTime: 0, duration: 100, status: 'paused' } })
      .mockImplementation(() => Promise.resolve({ data: { url: `/manifest?exp=${Math.floor(Date.now() / 1000) + 20}`,
        jobId: 'job', generation: 1, revision: 1, renditionSet: 'job.1', ready: true,
        position: 0, startTime: 0, duration: 100, status: 'paused' } }))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    await act(async () => containerRef.current.querySelector('video')!.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => vi.advanceTimersByTimeAsync(30000))
    expect(attachments).toHaveLength(1)
    unmount()
  })

  it.each(['restoration', 'native seek'])('defers capability renewal until %s releases the attached cursor', async owner => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    post.mockImplementation(() => Promise.resolve({ data: {
      url: `/manifest?exp=${Math.floor(Date.now() / 1000) + 20}`, jobId: 'job', generation: 1,
      revision: 1, renditionSet: 'job.1', ready: true, position: 42, startTime: 24, duration: 180, status: 'paused',
    } }))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    if (owner === 'native seek') {
      await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
      video.currentTime = 30
      Object.defineProperty(video, 'seeking', { configurable: true, value: true })
    }
    await act(async () => vi.advanceTimersByTimeAsync(16000))
    expect(attachments).toHaveLength(1)
    if (owner === 'restoration') await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    else Object.defineProperty(video, 'seeking', { value: false })
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(2)
    expect(attachments[1].position).toBe(owner === 'restoration' ? 18 : 30)
    unmount()
  })

  it('renews a short capability before its last valid second ends and rejects older deadlines', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const seconds = Math.floor(Date.now() / 1000)
    let expiry = seconds + 3
    post.mockImplementation(() => Promise.resolve({ data: { url: `/manifest?exp=${expiry}`, jobId: 'job',
      generation: 1, revision: 1, renditionSet: 'job.1', ready: true,
      position: 0, startTime: 0, duration: 180, status: 'paused' } }))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expiry--
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    expect(attachments).toHaveLength(1)
    expiry = seconds + 10
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(2)
    expect(Date.now()).toBeLessThan((seconds + 4) * 1000)
    expect(Number(new URL(video.src).searchParams.get('exp'))).toBe(expiry)
    unmount()
  })

  it.each([false, true])('recovers an expired network attachment through an outage and keeps its cursor with expanded renditions: %s', async expanded => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const expiry = Math.floor(Date.now() / 1000) + 20
    const snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', sessionId: 'owned', generation: 1,
      revision: 1, renditionSet: 'job.1', ready: true, position: 42, startTime: 24, duration: 180, status: 'running' }
    post.mockResolvedValue({ data: snapshot })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 30
    vi.setSystemTime((expiry + 2) * 1000)
    await act(async () => attachments[0].onError('network'))
    expect(attachments[0].dispose).toHaveBeenCalledOnce()
    expect(result.current.state).toBe('preparing')
    video.currentTime = 0
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => attachments[0].onError('network'))
    post.mockRejectedValueOnce(new AxiosError('Offline', 'ERR_NETWORK')).mockResolvedValue({ data: {
      ...snapshot, url: `/manifest?exp=${expiry + 60}`, position: 90,
      renditionSet: expanded ? 'job.1,sibling.1' : 'job.1',
    } })
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(onError).not.toHaveBeenCalled()
    expect(post.mock.calls.filter(call => call[1]?.action)).toHaveLength(0)
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(2)
    expect(attachments[1].position).toBe(30)
    await act(async () => {
      attachments[0].onReady()
      attachments[0].onError()
      video.dispatchEvent(new Event('loadedmetadata'))
    })
    expect(result.current.currentTime).toBe(54)
    expect(onError).not.toHaveBeenCalled()
    unmount()
    await act(async () => vi.advanceTimersByTimeAsync(30000))
    expect(attachments[1].dispose).toHaveBeenCalledOnce()
    expect(attachments).toHaveLength(2)
  })

  it('submits user pause with the preserved recovery cursor after the native clock resets', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const expiry = Math.floor(Date.now() / 1000) + 20
    const snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', sessionId: 'owned', generation: 1,
      revision: 1, renditionSet: 'job.1', ready: true, position: 42, startTime: 24, duration: 180, status: 'running' }
    post.mockImplementation((path: string) => path === '/api/stream/sign'
      ? Promise.resolve({ data: snapshot }) : new Promise(() => {}))
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 30
    vi.setSystemTime((expiry + 2) * 1000)
    await act(async () => attachments[0].onError('network'))
    video.currentTime = 0
    await act(async () => result.current.pause())
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned/position',
      { position: 54, action: 'pause' }, { signal: expect.any(AbortSignal), timeout: 5000 })
    unmount()
  })

  it.each([
    { expired: false, failure: 'network' as const },
    { expired: true, failure: undefined },
  ])('fences terminal media failure against future signing (expired=$expired, failure=$failure)', async ({ expired, failure }) => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const expiry = Math.floor(Date.now() / 1000) + 20
    const snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 0, startTime: 0, duration: 180, status: 'running' }
    post.mockResolvedValue({ data: snapshot })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    if (expired) vi.setSystemTime((expiry + 2) * 1000)
    await act(async () => attachments[0].onError(failure))
    post.mockResolvedValue({ data: { ...snapshot, url: `/manifest?exp=${expiry + 60}`, renditionSet: 'job.1,sibling.1' } })
    await act(async () => vi.advanceTimersByTimeAsync(30000))
    await act(async () => { attachments[0].onReady(); result.current.play() })
    expect(attachments).toHaveLength(1)
    expect(onError).toHaveBeenCalledOnce()
    expect(result.current.state).toBe('error')
    expect(post).toHaveBeenCalledTimes(1)
    unmount()
  })

  it('keeps signing refusal terminal during expired network recovery', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const expiry = Math.floor(Date.now() / 1000) + 20
    const snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 0, startTime: 0, duration: 180, status: 'paused' }
    post.mockResolvedValue({ data: snapshot })
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    vi.setSystemTime((expiry + 2) * 1000)
    await act(async () => attachments[0].onError('network'))
    post.mockRejectedValueOnce(Object.assign(new AxiosError('Refused'), { response: { status: 403 } }))
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    post.mockResolvedValue({ data: { ...snapshot, url: `/manifest?exp=${expiry + 60}` } })
    await act(async () => vi.advanceTimersByTimeAsync(30000))
    expect(result.current.state).toBe('error')
    expect(attachments).toHaveLength(1)
    unmount()
  })

  it('keeps renewal behind a source seek acknowledgement and restores the accepted target first', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    let accept!: (value: unknown) => void
    let position = 42
    let revision = 1
    post.mockImplementation((path: string) => {
      if (path !== '/api/stream/sign') return new Promise(resolve => { accept = resolve })
      return Promise.resolve({ data: { url: `/manifest?exp=${Math.floor(Date.now() / 1000) + 20}`,
        jobId: 'job', sessionId: 'owned', generation: 1, revision, renditionSet: 'job.1',
        ready: true, position, startTime: 24, duration: 180, status: 'paused' } })
    })
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => result.current.seekTo(90))
    await act(async () => vi.advanceTimersByTimeAsync(16000))
    expect(attachments).toHaveLength(1)
    expect(attachments[0].dispose).not.toHaveBeenCalled()
    position = 90
    revision = 2
    await act(async () => accept({ data: { data: { acceptedGeneration: 1 } } }))
    expect(video.currentTime).toBe(66)
    expect(attachments).toHaveLength(1)
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(2)
    expect(attachments[1].position).toBe(66)
    expect(post.mock.calls.filter(call => call[1]?.action === 'seek')).toHaveLength(1)
    unmount()
  })

  it.each(['native network', 'native decode', 'play rejection'])('limits expired capability recovery to delivery failures: %s', async failure => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const expiry = Math.floor(Date.now() / 1000) + 20
    const snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 42, startTime: 24, duration: 180, status: 'running' }
    post.mockResolvedValue({ data: snapshot })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 30
    vi.setSystemTime((expiry + 2) * 1000)
    if (failure === 'play rejection') {
      vi.mocked(video.play).mockRejectedValueOnce(new Error('Playback refused'))
      await act(async () => result.current.play())
    } else {
      Object.defineProperty(video, 'error', { configurable: true, value: { code: failure === 'native network' ? 2 : 3 } })
      await act(async () => video.dispatchEvent(new Event('error')))
      video.currentTime = 0
      await act(async () => video.dispatchEvent(new Event('pause')))
      Object.defineProperty(video, 'error', { value: null })
    }
    post.mockResolvedValue({ data: { ...snapshot, url: `/manifest?exp=${expiry + 60}` } })
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    if (failure === 'native network') {
      expect(attachments).toHaveLength(2)
      expect(attachments[1].position).toBe(30)
      expect(onError).not.toHaveBeenCalled()
    } else {
      expect(result.current.state).toBe('error')
      expect(attachments).toHaveLength(1)
      expect(onError).toHaveBeenCalledOnce()
    }
    unmount()
  })

  it.each([false, true])('recovers an unexpired attachment after a signing outage, including delayed media failure after a successful poll: %s', async successfulPoll => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const expiry = Math.floor(Date.now() / 1000) + 3600
    const snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 30, startTime: 12, duration: 180, status: 'paused' }
    post.mockResolvedValue({ data: snapshot })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(18)
    post.mockRejectedValueOnce(new AxiosError('Web unavailable', 'ERR_NETWORK'))
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    if (successfulPoll) await act(async () => vi.advanceTimersByTimeAsync(1000))
    await act(async () => attachments[0].onError('network'))
    expect(result.current.state).toBe('preparing')
    expect(onError).not.toHaveBeenCalled()
    video.currentTime = 0
    post.mockRejectedValueOnce(new AxiosError('Still unavailable', 'ERR_NETWORK'))
      .mockResolvedValue({ data: { ...snapshot, url: `/manifest?exp=${expiry + 60}`, position: 90 } })
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(onError).not.toHaveBeenCalled()
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(2)
    expect(attachments[1].position).toBe(18)
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(result.current.currentTime).toBe(30)
    expect(video.play).not.toHaveBeenCalled()
    await act(async () => attachments[1].onError('network'))
    expect(result.current.state).toBe('error')
    expect(onError).toHaveBeenCalledOnce()
    expect(post.mock.calls.filter(call => call[1]?.action === 'seek')).toHaveLength(0)
    unmount()
  })

  it.each(['older', 'same', 'expired', 'malformed', 'expired original'])('waits for a newer valid capability when outage recovery expands renditions: %s', async candidate => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const seconds = Math.floor(Date.now() / 1000)
    const expiry = seconds + 3600
    const snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 30, startTime: 12, duration: 180, status: 'paused' }
    post.mockResolvedValue({ data: snapshot })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    post.mockRejectedValueOnce(new AxiosError('Web unavailable', 'ERR_NETWORK'))
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    if (candidate === 'expired original') vi.setSystemTime((expiry + 2) * 1000)
    await act(async () => attachments[0].onError('network'))
    video.currentTime = 0
    const invalidExpiry = candidate === 'older' || candidate === 'expired original' ? expiry - 1 : candidate === 'same' ? expiry
      : candidate === 'expired' ? seconds - 1 : 'invalid'
    post.mockResolvedValue({ data: { ...snapshot, url: `/manifest?exp=${invalidExpiry}`,
      position: 90, renditionSet: 'job.1,sibling.1' } })
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(1)
    expect(result.current.state).toBe('preparing')
    expect(onError).not.toHaveBeenCalled()
    post.mockResolvedValue({ data: { ...snapshot, url: `/manifest?exp=${expiry + 60}`,
      position: 90, renditionSet: 'job.1,sibling.1' } })
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(2)
    expect(attachments[1].position).toBe(18)
    await act(async () => attachments[1].onError('network'))
    expect(result.current.state).toBe('error')
    expect(onError).toHaveBeenCalledOnce()
    unmount()
  })

  it.each(['preparing', 'attachment loading'])('preserves accepted %s intent across transient signing failures', async phase => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const expiry = Math.floor(Date.now() / 1000) + 3600
    const snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 30, startTime: 12, duration: 180, status: 'paused' }
    loader.ready = false
    post.mockResolvedValueOnce({ data: phase === 'preparing'
      ? { ...snapshot, url: null, ready: false, startTime: null, duration: null, renditionSet: '' } : snapshot })
      .mockRejectedValueOnce(new AxiosError('Network unavailable', 'ERR_NETWORK'))
      .mockRejectedValueOnce(Object.assign(new AxiosError('Server unavailable'), { response: { status: 503 } }))
      .mockResolvedValue({ data: snapshot })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    await act(async () => { result.current.play(); result.current.pause() })
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    expect(onError).not.toHaveBeenCalled()
    expect(attachments).toHaveLength(phase === 'preparing' ? 0 : 1)
    if (attachments[0]) expect(attachments[0].dispose).not.toHaveBeenCalled()
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    const video = containerRef.current.querySelector('video')!
    await act(async () => { attachments[0].onReady(); video.dispatchEvent(new Event('loadedmetadata')) })
    expect(video.currentTime).toBe(18)
    expect(video.play).not.toHaveBeenCalled()
    expect(onError).not.toHaveBeenCalled()
    unmount()
  })

  it.each(['decoder', 'play rejection', 'signing refusal'])('keeps %s terminal after an observed signing outage', async failure => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const expiry = Math.floor(Date.now() / 1000) + 3600
    const snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 30, startTime: 12, duration: 180, status: 'running' }
    post.mockResolvedValue({ data: snapshot })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    post.mockRejectedValueOnce(new AxiosError('Web unavailable', 'ERR_NETWORK'))
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    if (failure === 'play rejection') {
      vi.mocked(video.play).mockRejectedValueOnce(new Error('Playback refused'))
      await act(async () => result.current.play())
    } else if (failure === 'decoder') await act(async () => attachments[0].onError())
    else {
      await act(async () => attachments[0].onError('network'))
      post.mockRejectedValueOnce(Object.assign(new AxiosError('Refused'), { response: { status: 403 } }))
      await act(async () => vi.advanceTimersByTimeAsync(1000))
    }
    post.mockResolvedValue({ data: { ...snapshot, url: `/manifest?exp=${expiry + 60}` } })
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    expect(result.current.state).toBe('error')
    expect(attachments).toHaveLength(1)
    expect(onError).toHaveBeenCalledOnce()
    unmount()
  })

  it('consumes an observed outage when a committed generation gets a fresh attachment', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const expiry = Math.floor(Date.now() / 1000) + 3600
    const snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 30, startTime: 12, duration: 180, status: 'paused' }
    post.mockResolvedValue({ data: snapshot })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    post.mockRejectedValueOnce(new AxiosError('Web unavailable', 'ERR_NETWORK'))
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    post.mockResolvedValue({ data: { ...snapshot, generation: 2, revision: 2, renditionSet: 'job.2' } })
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(2)
    await act(async () => attachments[1].onError('network'))
    expect(result.current.state).toBe('error')
    expect(onError).toHaveBeenCalledOnce()
    unmount()
  })

  it.each([false, true])('restores an acknowledged same-generation source seek submitted while outage recovery owns the released attachment (later expanded set: %s)', async laterExpandedSet => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
    const expiry = Math.floor(Date.now() / 1000) + 3600
    let snapshot = { url: `/manifest?exp=${expiry}`, jobId: 'job', sessionId: 'owned', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 30, startTime: 12, duration: 180, status: 'paused' }
    post.mockImplementation((path: string) => Promise.resolve(path === '/api/stream/sign'
      ? { data: snapshot } : { data: { data: { acceptedGeneration: 1 } } }))
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    post.mockRejectedValueOnce(new AxiosError('Web unavailable', 'ERR_NETWORK'))
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    await act(async () => attachments[0].onError('network'))
    video.currentTime = 0
    snapshot = { ...snapshot, url: `/manifest?exp=${expiry + (laterExpandedSet ? 0 : 60)}`, position: 60, revision: 2 }
    await act(async () => result.current.seekTo(60))
    if (laterExpandedSet) {
      expect(attachments).toHaveLength(1)
      snapshot = { ...snapshot, url: `/manifest?exp=${expiry + 60}`, renditionSet: 'job.1,sibling.1' }
      await act(async () => vi.advanceTimersByTimeAsync(1000))
    }
    expect(attachments).toHaveLength(2)
    expect(attachments[1].position).toBe(48)
    expect(result.current.pendingSeekPosition).toBe(60)
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(result.current.currentTime).toBe(60)
    expect(result.current.pendingSeekPosition).toBeNull()
    expect(onError).not.toHaveBeenCalled()
    expect(post.mock.calls.filter(call => call[1]?.action === 'seek')).toHaveLength(1)
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

  it('ignores stale attachment failures and rejected play promises after replacement', async () => {
    vi.useFakeTimers()
    let rejectPlay: (error: Error) => void = () => {}
    vi.mocked(HTMLMediaElement.prototype.play).mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectPlay = reject }))
    const snapshot = (generation: number) => ({ data: { url: `/manifest?generation=${generation}`, jobId: 'job',
      sessionId: 'owned', generation, revision: generation, renditionSet: `job.${generation}`,
      ready: true, position: 0, startTime: 0, duration: 100, status: 'running' } })
    post.mockResolvedValueOnce(snapshot(1)).mockResolvedValue(snapshot(2))
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const oldAttachment = attachments[0]
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(2)
    await act(async () => {
      oldAttachment.onError()
      rejectPlay(new Error('Old source removed'))
    })
    expect(result.current.state).not.toBe('error')
    expect(onError).not.toHaveBeenCalled()
    await act(async () => attachments[1].onError())
    expect(result.current.state).toBe('error')
    expect(onError).toHaveBeenCalledOnce()
    unmount()
  })

  it('releases the active attachment through StrictMode replay and final cleanup', async () => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 0, startTime: 0, duration: 100, status: 'paused' } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }), {
      wrapper: ({ children }) => createElement(StrictMode, null, children),
    })
    await act(async () => {})
    expect(containerRef.current.querySelectorAll('video')).toHaveLength(1)
    expect(attachments).toHaveLength(1)
    unmount()
    expect(attachments[0].dispose).toHaveBeenCalledOnce()
    expect(containerRef.current.querySelectorAll('video')).toHaveLength(0)
  })

  it('keeps a fatal restoration error terminal when an expanded snapshot arrives', async () => {
    vi.useFakeTimers()
    vi.spyOn(HTMLMediaElement.prototype, 'seekable', 'get').mockReturnValue({ length: 0, start: () => 0, end: () => 0 })
    const snapshot = (renditionSet: string) => ({ data: { url: `/manifest?set=${renditionSet}`, jobId: 'job',
      generation: 1, revision: 1, renditionSet, ready: true, position: 18, startTime: 0, duration: 100, status: 'running' } })
    post.mockResolvedValueOnce(snapshot('job.1')).mockResolvedValue(snapshot('job.1,sibling.1'))
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    await act(async () => containerRef.current.querySelector('video')!.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => attachments[0].onError())
    expect(result.current.state).toBe('error')
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(1)
    expect(result.current.state).toBe('error')
    unmount()
  })

  it('preserves play then pause before a delayed attachment is ready', async () => {
    loader.ready = false
    post.mockImplementation((path: string) => Promise.resolve(path === '/api/stream/sign' ? { data: {
      url: '/manifest', jobId: 'job', sessionId: 'owned', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 0, startTime: 0, duration: 100, status: 'running',
    } } : { data: {} }))
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    expect(video.paused).toBe(true)
    await act(async () => { result.current.play(); result.current.pause() })
    await act(async () => attachments[0].onReady())
    expect(video.play).not.toHaveBeenCalled()
    expect(post.mock.calls.filter(call => call[1].action === 'pause')).toHaveLength(1)
    unmount()
  })

  it('uses the new canonical origin after the retiring attachment fails during a backwards seek', async () => {
    vi.useFakeTimers()
    let generation = 1
    let ready = true
    post.mockImplementation((path: string) => {
      if (path !== '/api/stream/sign') {
        generation = 2
        ready = false
        return Promise.resolve({ data: { data: { acceptedGeneration: 2 } } })
      }
      return Promise.resolve({ data: { url: ready ? `/manifest?generation=${generation}` : null,
        jobId: 'job', sessionId: 'owned', generation, revision: generation, renditionSet: ready ? `job.${generation}` : '',
        ready, position: generation === 1 ? 120 : 0, startTime: ready ? generation === 1 ? 102 : 0 : null,
        duration: ready ? 180 : null, status: 'paused' } })
    })
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(18)
    await act(async () => result.current.seekTo(0))
    expect(result.current.state).toBe('preparing')
    await act(async () => attachments[0].onError())
    video.currentTime = 0
    ready = true
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments.at(-1)?.position).toBe(0)
    unmount()
  })

  it.each([undefined, 503])('preserves an attached cursor across a transient sign poll failure (%s)', async status => {
    vi.useFakeTimers()
    const snapshot = { data: { url: '/manifest', jobId: 'job', sessionId: 'owned', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 42, startTime: 24, duration: 180, status: 'paused' } }
    const outage = Object.assign(new AxiosError('Temporarily unavailable', 'ERR_NETWORK'), status ? { response: { status } } : {})
    post.mockResolvedValueOnce(snapshot).mockRejectedValueOnce(outage).mockResolvedValue(snapshot)
    const containerRef = { current: document.createElement('div') }
    const onError = vi.fn()
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(18)
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments[0].dispose).not.toHaveBeenCalled()
    expect(onError).not.toHaveBeenCalled()
    expect(video.currentTime).toBe(18)
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments).toHaveLength(1)
    await act(async () => result.current.play())
    expect(video.play).toHaveBeenCalled()
    unmount()
  })

  it.each([403, 404])('disposes attached playback when sign polling refuses access (%s)', async status => {
    vi.useFakeTimers()
    const snapshot = { data: { url: '/manifest', jobId: 'job', generation: 1, revision: 1,
      renditionSet: 'job.1', ready: true, position: 0, startTime: 0, duration: 180, status: 'paused' } }
    post.mockResolvedValueOnce(snapshot).mockRejectedValue(Object.assign(new AxiosError('Refused'), { response: { status } }))
    const containerRef = { current: document.createElement('div') }
    const onError = vi.fn()
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, onError }))
    await act(async () => {})
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments[0].dispose).toHaveBeenCalledOnce()
    expect(result.current.state).toBe('error')
    expect(onError).toHaveBeenCalledOnce()
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

  it.each(['ERR_NETWORK', 'ECONNABORTED', 'ETIMEDOUT', '503'])('preserves running playback through a failed automatic progress request: %s', async code => {
    vi.useFakeTimers()
    const snapshot = { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session',
      generation: 1, revision: 5, ready: true, startTime: 24, duration: 180, position: 24, status: 'running' }
    let attempts = 0
    post.mockImplementation((path: string, payload: { action?: string, position?: number }) => {
      if (path === '/api/stream/sign') return Promise.resolve({ data: { ...snapshot } })
      if (payload.action === 'progress' && ++attempts === 1) return Promise.reject(code === '503'
        ? Object.assign(new AxiosError('Server unavailable'), { response: { status: 503 } })
        : new AxiosError('Connection unavailable', code))
      snapshot.position = payload.position!
      return Promise.resolve({ data: {} })
    })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 10
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments[0].dispose).not.toHaveBeenCalled()
    expect(onError).not.toHaveBeenCalled()
    expect(result.current.state).not.toBe('error')
    expect(video.currentTime).toBe(10)
    video.currentTime = 20
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    const progress = post.mock.calls.filter(call => call[1].action === 'progress')
    expect(progress.map(call => call[1])).toEqual([
      { action: 'progress', position: 34, generation: 1, revision: 5 },
      { action: 'progress', position: 44, generation: 1, revision: 5 },
    ])
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    expect(post.mock.calls.filter(call => call[1].action === 'progress')).toHaveLength(2)
    expect(attachments).toHaveLength(1)
    expect(attachments[0].dispose).not.toHaveBeenCalled()
    expect(onError).not.toHaveBeenCalled()
    unmount()
  })

  it('reconciles accepted progress after its response is lost and sends the latest eligible cursor next', async () => {
    vi.useFakeTimers()
    const snapshot = { url: '/manifest', jobId: 'job', renditionSet: 'set', sessionId: 'owned-session',
      generation: 1, revision: 5, ready: true, startTime: 0, duration: 180, position: 0, status: 'running' }
    let rejectProgress!: (error: Error) => void
    let attempts = 0
    post.mockImplementation((path: string, payload: { action?: string, position?: number }) => {
      if (path === '/api/stream/sign') return Promise.resolve({ data: { ...snapshot } })
      snapshot.position = payload.position!
      snapshot.revision++
      if (payload.action === 'progress' && ++attempts === 1) return new Promise((_resolve, reject) => { rejectProgress = reject })
      return Promise.resolve({ data: {} })
    })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 10
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    expect(snapshot.position).toBe(10)
    expect(attachments).toHaveLength(1)
    await act(async () => rejectProgress(new AxiosError('Response lost', 'ERR_NETWORK')))
    expect(attachments[0].dispose).not.toHaveBeenCalled()
    expect(onError).not.toHaveBeenCalled()
    video.currentTime = 20
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(post.mock.calls.filter(call => call[1].action === 'progress').map(call => call[1])).toEqual([
      { action: 'progress', position: 10, generation: 1, revision: 5 },
      { action: 'progress', position: 20, generation: 1, revision: 6 },
    ])
    expect(snapshot.position).toBe(20)
    expect(attachments).toHaveLength(1)
    unmount()
  })

  it('retains queued source seeks when their predecessor progress update loses its response', async () => {
    vi.useFakeTimers()
    let snapshot = { url: '/manifest?generation=1', jobId: 'job', renditionSet: 'job.1', sessionId: 'owned-session',
      generation: 1, revision: 5, ready: true, startTime: 0, duration: 180, position: 0, status: 'running' }
    let rejectProgress!: (error: Error) => void
    post.mockImplementation((path: string, payload: { action?: string, position?: number }) => {
      if (path === '/api/stream/sign') return Promise.resolve({ data: { ...snapshot } })
      if (payload.action === 'progress') return new Promise((_resolve, reject) => { rejectProgress = reject })
      const generation = snapshot.generation + 1
      snapshot = { ...snapshot, generation, revision: snapshot.revision + 1, position: payload.position!,
        url: `/manifest?generation=${generation}`, renditionSet: `job.${generation}` }
      return Promise.resolve({ data: { data: { acceptedGeneration: generation } } })
    })
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 10
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    await act(async () => { result.current.seekTo(42); result.current.seekTo(60) })
    expect(post.mock.calls.filter(call => call[1].action === 'seek')).toHaveLength(0)
    await act(async () => rejectProgress(new AxiosError('Response lost', 'ERR_NETWORK')))
    expect(post.mock.calls.filter(call => call[1].action === 'seek').map(call => call[1])).toEqual([
      { action: 'seek', position: 42, generation: 1 },
      { action: 'seek', position: 60, generation: 2 },
    ])
    expect(result.current.pendingSeekPosition).toBe(60)
    expect(attachments.at(-1)?.position).toBe(60)
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(result.current.currentTime).toBe(60)
    expect(result.current.pendingSeekPosition).toBeNull()
    expect(onError).not.toHaveBeenCalled()
    unmount()
  })

  it.each(['unmounted', 'session replaced', 'generation replaced', 'terminal'])('fences a failed progress completion after playback is %s', async outcome => {
    vi.useFakeTimers()
    let snapshot = { url: '/manifest?generation=1', jobId: 'job', renditionSet: 'job.1', sessionId: 'owned-session',
      generation: 1, revision: 5, ready: true, startTime: 0, duration: 180, position: 0, status: 'running' }
    let rejectProgress!: (error: Error) => void
    post.mockImplementation((path: string) => path === '/api/stream/sign'
      ? Promise.resolve({ data: { ...snapshot } }) : new Promise((_resolve, reject) => { rejectProgress = reject }))
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 10
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    if (outcome === 'unmounted') unmount()
    else if (outcome === 'terminal') await act(async () => attachments[0].onError())
    else {
      snapshot = { ...snapshot, generation: 2, revision: 6, url: '/manifest?generation=2', renditionSet: 'job.2', status: 'paused',
        ...(outcome === 'session replaced' ? { jobId: 'replacement-job', sessionId: 'replacement-session', renditionSet: 'replacement-job.2' } : {}) }
      await act(async () => vi.advanceTimersByTimeAsync(1000))
      await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    }
    const requestCount = post.mock.calls.length
    await act(async () => rejectProgress(new AxiosError('Old progress failed', 'ERR_NETWORK')))
    if (outcome === 'unmounted') {
      await act(async () => vi.advanceTimersByTimeAsync(2000))
      expect(post).toHaveBeenCalledTimes(requestCount)
      expect(containerRef.current.querySelector('video')).toBeNull()
    } else if (outcome === 'terminal') {
      await act(async () => vi.advanceTimersByTimeAsync(2000))
      expect(result.current.state).toBe('error')
      expect(attachments).toHaveLength(1)
      expect(onError).toHaveBeenCalledOnce()
    } else {
      expect(attachments).toHaveLength(2)
      expect(attachments[1].dispose).not.toHaveBeenCalled()
      expect(result.current.state).not.toBe('error')
      expect(onError).not.toHaveBeenCalled()
    }
    if (outcome !== 'unmounted') unmount()
  })

  it.each(['403', 'ERR_BAD_OPTION', 'ERR_BAD_REQUEST', 'no Axios code', 'unrelated exception', 'canceled'])('retains existing progress failure behavior for %s', async kind => {
    vi.useFakeTimers()
    const failure = kind === '403' ? Object.assign(new AxiosError('Forbidden'), { response: { status: 403 } })
      : kind === 'unrelated exception' ? new Error('Unexpected failure')
        : kind === 'canceled' ? new CanceledError('Canceled')
          : new AxiosError('Invalid request', kind === 'no Axios code' ? undefined : kind)
    post.mockImplementation((path: string) => path === '/api/stream/sign'
      ? Promise.resolve({ data: { url: '/manifest', jobId: 'job', renditionSet: 'job.1', sessionId: 'owned-session',
        generation: 1, revision: 5, ready: true, startTime: 0, duration: 180, position: 0, status: 'running' } })
      : Promise.reject(failure))
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, value: false })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 10
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(attachments[0].dispose).toHaveBeenCalledOnce()
    expect(result.current.state).toBe('error')
    expect(onError).toHaveBeenCalledOnce()
    unmount()
  })

  it.each(['pause', 'resume', 'seek'])('retains existing explicit %s failure behavior for transport errors', async action => {
    post.mockImplementation((path: string) => path === '/api/stream/sign'
      ? Promise.resolve({ data: { url: '/manifest', jobId: 'job', renditionSet: 'job.1', sessionId: 'owned-session',
        generation: 1, revision: 5, ready: true, startTime: 0, duration: 180, position: 0, status: action === 'resume' ? 'paused' : 'running' } })
      : Promise.reject(new AxiosError('Connection lost', 'ERR_NETWORK')))
    const onError = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    if (action === 'pause') await act(async () => result.current.pause())
    else if (action === 'resume') await act(async () => result.current.play())
    else await act(async () => result.current.seekTo(42))
    expect(attachments[0].dispose).toHaveBeenCalledOnce()
    expect(result.current.state).toBe('error')
    expect(onError).toHaveBeenCalledOnce()
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

  it('orders a pause between native targets and retains its intent until fresh reconciliation', async () => {
    let generation = 4
    let position = 30
    let status = 'running'
    const snapshot = () => ({ data: {
      url: `/manifest?generation=${generation}`, jobId: 'job', renditionSet: `job.${generation}`,
      sessionId: 'owned-session', generation, revision: generation, ready: true,
      position, status, startTime: 12, duration: 180,
    } })
    let finishFirst!: (value: { data: { data: { acceptedGeneration: number } } }) => void
    let finishLast!: (value: { data: { data: { acceptedGeneration: number } } }) => void
    post.mockImplementation((path: string, payload: { action?: string, position?: number }) => {
      if (path === '/api/stream/sign') return Promise.resolve(snapshot())
      position = payload.position!
      if (payload.action === 'pause') status = 'paused'
      if (payload.action === 'seek') {
        generation++
        if (position === 45) return new Promise((resolve) => { finishFirst = resolve })
        return new Promise((resolve) => { finishLast = resolve })
      }
      return Promise.resolve({ data: { data: {} } })
    })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    Object.defineProperty(video, 'paused', { configurable: true, writable: true, value: false })
    vi.mocked(video.play).mockImplementation(async () => { Object.defineProperty(video, 'paused', { value: false }) })
    vi.mocked(video.pause).mockImplementation(() => { Object.defineProperty(video, 'paused', { value: true }) })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 33
    await act(async () => video.dispatchEvent(new Event('seeking')))
    video.currentTime = 43
    Object.defineProperty(video, 'paused', { value: true })
    await act(async () => video.dispatchEvent(new Event('pause')))
    video.currentTime = 53
    await act(async () => video.dispatchEvent(new Event('seeking')))
    await act(async () => finishFirst({ data: { data: { acceptedGeneration: 5 } } }))
    const commands = () => post.mock.calls.filter((call) => call[1].action).map((call) => call[1])
    expect(commands()).toEqual([
      { action: 'seek', position: 45, generation: 4 },
      { action: 'pause', position: 55 },
      { action: 'seek', position: 65, generation: 5 },
    ])
    expect(video.paused).toBe(true)
    await act(async () => finishLast({ data: { data: { acceptedGeneration: 6 } } }))
    expect(position).toBe(65)
    expect(status).toBe('paused')
    expect(video.paused).toBe(true)
    unmount()
  })

  it('retains accepted native seek authority while its fresh reconciliation is pending', async () => {
    let generation = 4
    let position = 30
    const snapshot = () => ({ data: {
      url: `/manifest?generation=${generation}`, jobId: 'job', renditionSet: `job.${generation}`,
      sessionId: 'owned-session', generation, revision: generation, ready: true,
      position, status: 'paused', startTime: 12, duration: 180,
    } })
    let finishRefresh!: (value: ReturnType<typeof snapshot>) => void
    let finishLast!: (value: { data: { data: { acceptedGeneration: number } } }) => void
    let refreshes = 0
    post.mockImplementation((path: string, payload: { position?: number }) => {
      if (path === '/api/stream/sign') {
        refreshes++
        if (refreshes === 2) return new Promise((resolve) => { finishRefresh = resolve })
        return Promise.resolve(snapshot())
      }
      position = payload.position!
      generation++
      if (position === 65) return new Promise((resolve) => { finishLast = resolve })
      return Promise.resolve({ data: { data: { acceptedGeneration: generation } } })
    })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    video.currentTime = 33
    await act(async () => video.dispatchEvent(new Event('seeking')))
    expect(refreshes).toBe(2)
    expect(video.src).toContain('generation=4')
    const committedFirst = snapshot()
    video.currentTime = 53
    await act(async () => video.dispatchEvent(new Event('seeking')))
    await act(async () => finishRefresh(committedFirst))
    expect(post.mock.calls.filter((call) => call[1].action === 'seek').map((call) => call[1])).toEqual([
      { action: 'seek', position: 45, generation: 4 },
      { action: 'seek', position: 65, generation: 5 },
    ])
    expect(video.src).toContain('generation=4')
    await act(async () => finishLast({ data: { data: { acceptedGeneration: 6 } } }))
    expect(video.src).toContain('generation=6')
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(53)
    unmount()
  })

  it('fences explicit source seek intent with the attached generation', async () => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', renditionSet: 'job.4', sessionId: 'owned-session', generation: 4, revision: 8, ready: true, position: 0, status: 'paused', startTime: 0, duration: 180 } })
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => result.current.seekTo(60))
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', { position: 60, action: 'seek', generation: 4 }, { signal: expect.any(AbortSignal), timeout: 5000 })
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
    Object.defineProperty(video, 'seekable', { configurable: true, get: () => ({ length: availableDuration > 0 ? 1 : 0, start: () => 0, end: () => availableDuration }) })
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
    Object.defineProperty(video, 'seekable', { configurable: true, value: { length: 0, start: () => 0, end: () => 0 } })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => result.current.seekTo(60))
    expect(post).toHaveBeenCalledWith('/api/transcode/sessions/owned-session/position', { position: 60, action: 'seek', generation: 4 }, { signal: expect.any(AbortSignal), timeout: 5000 })
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
    Object.defineProperty(video, 'seekable', { configurable: true, get: () => ({ length: Number.isFinite(duration) ? 1 : 0, start: () => 0, end: () => duration }) })
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
    expect(video.src).not.toContain('third.7')
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(30)
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    expect(video.src).toContain('third.7')
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

  it('seeks beyond a growing EVENT window without reporting clamped native feedback', async () => {
    vi.useFakeTimers()
    const initial = { url: '/manifest?generation=4', jobId: 'job', renditionSet: 'job.4', sessionId: 'owned-session',
      generation: 4, revision: 4, ready: true, position: 0, status: 'paused', startTime: 0, duration: 180 }
    let snapshot: { data: Partial<typeof initial> } = { data: initial }
    let accept!: (value: { data: { data: { acceptedGeneration: number } } }) => void
    post.mockImplementation((path: string) => path === '/api/stream/sign'
      ? Promise.resolve(snapshot)
      : new Promise(resolve => { accept = resolve }))
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    let publishedEnd = 6
    Object.defineProperty(video, 'duration', { configurable: true, value: Infinity })
    Object.defineProperty(video, 'seekable', { configurable: true, get: () => ({ length: 1, start: () => 0, end: () => publishedEnd }) })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => result.current.seekTo(120))
    expect(video.currentTime).toBe(0)
    expect(result.current.pendingSeekPosition).toBe(120)
    video.currentTime = 3
    await act(async () => {
      video.dispatchEvent(new Event('seeking'))
      video.dispatchEvent(new Event('timeupdate'))
      video.dispatchEvent(new Event('loadedmetadata'))
      await vi.advanceTimersByTimeAsync(1000)
    })
    expect(result.current.pendingSeekPosition).toBe(120)
    expect(post.mock.calls.filter(call => call[1].action).map(call => call[1])).toEqual([
      { action: 'seek', position: 120, generation: 4 },
    ])
    snapshot = { data: { ...initial, url: undefined, generation: 5, revision: 5, ready: false, position: 120 } }
    await act(async () => accept({ data: { data: { acceptedGeneration: 5 } } }))
    snapshot = { data: { ...initial, url: '/manifest?generation=5', renditionSet: 'job.5', generation: 5, revision: 5, position: 120, startTime: 102 } }
    await act(async () => vi.advanceTimersByTimeAsync(1000))
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(3)
    expect(result.current.duration).toBe(180)
    expect(result.current.pendingSeekPosition).toBe(120)
    publishedEnd = 24
    await act(async () => video.dispatchEvent(new Event('progress')))
    expect(video.currentTime).toBe(18)
    expect(result.current.currentTime).toBe(120)
    expect(result.current.pendingSeekPosition).toBeNull()
    expect(video.pause).toHaveBeenCalled()
    await act(async () => {
      video.dispatchEvent(new Event('seeking'))
      video.dispatchEvent(new Event('seeked'))
      video.dispatchEvent(new Event('durationchange'))
    })
    expect(result.current.duration).toBe(180)
    expect(post.mock.calls.filter(call => call[1].action)).toHaveLength(1)
    unmount()
  })

  it.each([false, true])('coalesces explicit source seeks through predecessor acceptance when its attachment fails: %s', async attachmentFails => {
    const initial = { url: '/manifest?generation=4', jobId: 'job', renditionSet: 'job.4', sessionId: 'owned-session',
      generation: 4, revision: 4, ready: true, position: 0, status: 'paused', startTime: 0, duration: 180 }
    let snapshot = initial
    let accept!: (value: { data: { data: { acceptedGeneration: number } } }) => void
    let seeks = 0
    post.mockImplementation((path: string) => {
      if (path === '/api/stream/sign') return Promise.resolve({ data: snapshot })
      if (++seeks === 1) return new Promise(resolve => { accept = resolve })
      return Promise.resolve({ data: { data: { acceptedGeneration: 6 } } })
    })
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => {
      result.current.seekTo(120)
      result.current.seekTo(130)
      result.current.seekTo(140)
    })
    expect(result.current.pendingSeekPosition).toBe(140)
    if (attachmentFails) {
      await act(async () => attachments[0].onError())
      expect(attachments[0].dispose).toHaveBeenCalledOnce()
      expect(result.current.pendingSeekPosition).toBe(140)
      expect(result.current.state).toBe('preparing')
    }
    snapshot = { ...initial, url: '/manifest?generation=6', renditionSet: 'job.6', generation: 6, revision: 6, position: 140, startTime: 122 }
    await act(async () => accept({ data: { data: { acceptedGeneration: 5 } } }))
    expect(post.mock.calls.filter(call => call[1].action === 'seek').map(call => call[1])).toEqual([
      { action: 'seek', position: 120, generation: 4 },
      { action: 'seek', position: 140, generation: 5 },
    ])
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(video.currentTime).toBe(18)
    expect(result.current.currentTime).toBe(140)
    expect(result.current.pendingSeekPosition).toBeNull()
    expect(video.paused).toBe(true)
    expect(video.play).not.toHaveBeenCalled()
    expect(attachments).toHaveLength(2)
    unmount()
  })

  it.each(['rejected', 'unmounted', 'replaced', 'replaced-rejected'] as const)('abandons queued source seeks when the predecessor is %s after attachment failure', async outcome => {
    vi.useFakeTimers()
    const initial = { url: '/manifest?generation=4', jobId: 'job', renditionSet: 'job.4', sessionId: 'owned-session',
      generation: 4, revision: 4, ready: true, position: 0, status: 'paused', startTime: 0, duration: 180 }
    let snapshot = initial
    let accept!: (value: { data: { data: { acceptedGeneration: number } } }) => void
    let reject!: (error: Error) => void
    post.mockImplementation((path: string) => {
      if (path === '/api/stream/sign') return Promise.resolve({ data: snapshot })
      return new Promise((resolve, rejectRequest) => {
        accept = resolve
        reject = rejectRequest
      })
    })
    const containerRef = { current: document.createElement('div') }
    const onError = vi.fn()
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => {
      result.current.seekTo(120)
      result.current.seekTo(130)
      result.current.seekTo(140)
    })
    await act(async () => attachments[0].onError())
    if (outcome === 'unmounted') unmount()
    if (outcome === 'replaced' || outcome === 'replaced-rejected') {
      snapshot = { ...initial, sessionId: 'replacement-session', jobId: 'replacement-job', renditionSet: 'replacement-job.4',
        url: '/replacement-manifest', position: 30 }
      await act(async () => vi.advanceTimersByTimeAsync(1000))
      await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
      expect(video.currentTime).toBe(30)
    }
    if (outcome === 'rejected' || outcome === 'replaced-rejected') await act(async () => reject(new Error('Seek refused')))
    else await act(async () => accept({ data: { data: { acceptedGeneration: 5 } } }))
    expect(post.mock.calls.filter(call => call[1].action === 'seek').map(call => [call[0], call[1]])).toEqual([
      ['/api/transcode/sessions/owned-session/position', { action: 'seek', position: 120, generation: 4 }],
    ])
    expect(video.play).not.toHaveBeenCalled()
    if (outcome === 'rejected') {
      expect(result.current.state).toBe('error')
      expect(result.current.pendingSeekPosition).toBeNull()
      expect(onError).toHaveBeenCalledOnce()
      expect(attachments).toHaveLength(1)
    } else if (outcome === 'unmounted') {
      expect(containerRef.current.querySelector('video')).toBeNull()
      expect(attachments).toHaveLength(1)
    } else {
      expect(result.current.state).not.toBe('error')
      expect(onError).not.toHaveBeenCalled()
      expect(attachments[1].dispose).not.toHaveBeenCalled()
      expect(video.src).toContain('/replacement-manifest')
      expect(video.currentTime).toBe(30)
      expect(attachments).toHaveLength(2)
    }
    if (outcome !== 'unmounted') unmount()
  })

  it('keeps a current attachment failure terminal without a replacement seek', async () => {
    vi.useFakeTimers()
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', renditionSet: 'job.4', sessionId: 'owned-session',
      generation: 4, revision: 4, ready: true, position: 0, status: 'paused', startTime: 0, duration: 180 } })
    const containerRef = { current: document.createElement('div') }
    const onError = vi.fn()
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, onError }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    await act(async () => attachments[0].onError())
    await act(async () => vi.advanceTimersByTimeAsync(2000))
    expect(result.current.state).toBe('error')
    expect(onError).toHaveBeenCalledOnce()
    expect(attachments[0].dispose).toHaveBeenCalledOnce()
    expect(attachments).toHaveLength(1)
    expect(video.play).not.toHaveBeenCalled()
    expect(post.mock.calls.filter(call => call[1].action === 'seek')).toHaveLength(0)
    unmount()
  })

  it('releases explicit source seek intent when its request fails', async () => {
    post.mockImplementation((path: string) => path === '/api/stream/sign' ? Promise.resolve({ data: {
      url: '/manifest', jobId: 'job', renditionSet: 'job.4', sessionId: 'owned-session',
      generation: 4, revision: 4, ready: true, position: 0, status: 'paused', startTime: 0, duration: 180,
    } }) : Promise.reject(new Error('Unavailable')))
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    await act(async () => result.current.seekTo(120))
    expect(result.current.state).toBe('error')
    expect(result.current.pendingSeekPosition).toBeNull()
    unmount()
  })

  it('destroys a pending source seek without attaching its late accepted generation', async () => {
    let accept!: (value: { data: { data: { acceptedGeneration: number } } }) => void
    post.mockImplementation((path: string) => path === '/api/stream/sign' ? Promise.resolve({ data: {
      url: '/manifest', jobId: 'job', renditionSet: 'job.4', sessionId: 'owned-session',
      generation: 4, revision: 4, ready: true, position: 0, status: 'paused', startTime: 0, duration: 180,
    } }) : new Promise(resolve => { accept = resolve }))
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    await act(async () => result.current.seekTo(120))
    act(() => result.current.destroy())
    expect(result.current.pendingSeekPosition).toBeNull()
    await act(async () => accept({ data: { data: { acceptedGeneration: 5 } } }))
    expect(containerRef.current.querySelector('video')).toBeNull()
    expect(post.mock.calls.filter(call => call[0] === '/api/stream/sign')).toHaveLength(1)
    unmount()
  })

  it('restores exact source EOF to a nearby finite seekable end without changing the global cursor', async () => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', renditionSet: 'job.5', sessionId: 'owned-session',
      generation: 5, revision: 5, ready: true, position: 180, status: 'paused', startTime: 162, duration: 180 } })
    const onTimeUpdate = vi.fn()
    const containerRef = { current: document.createElement('div') }
    const { result, unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, onTimeUpdate }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    let nativeTime = 0
    let seeking = false
    Object.defineProperty(video, 'currentTime', { configurable: true, get: () => nativeTime,
      set: (time: number) => { nativeTime = time; seeking = true } })
    Object.defineProperty(video, 'seeking', { configurable: true, get: () => seeking })
    Object.defineProperty(video, 'duration', { configurable: true, value: 17.92 })
    Object.defineProperty(video, 'seekable', { configurable: true, value: { length: 1, start: () => 0, end: () => 17.92 } })
    await act(async () => video.dispatchEvent(new Event('loadedmetadata')))
    expect(nativeTime).toBe(17.92)
    await act(async () => video.dispatchEvent(new Event('seeking')))
    seeking = false
    await act(async () => {
      video.dispatchEvent(new Event('seeked'))
      video.dispatchEvent(new Event('timeupdate'))
      video.dispatchEvent(new Event('pause'))
    })
    expect(result.current.currentTime).toBe(180)
    expect(result.current.duration).toBe(180)
    expect(onTimeUpdate).toHaveBeenLastCalledWith(180, 180)
    await act(async () => {
      video.dispatchEvent(new Event('seeking'))
      video.dispatchEvent(new Event('seeked'))
    })
    expect(post.mock.calls.filter(call => call[1].action)).toHaveLength(0)
    await act(async () => result.current.seekTo(180))
    expect(result.current.pendingSeekPosition).toBeNull()
    expect(result.current.currentTime).toBe(180)
    expect(post.mock.calls.filter(call => call[1].action).map(call => call[1])).toEqual([
      { action: 'seek', position: 180, generation: 5 },
    ])
    unmount()
  })

  it.each([
    { position: 180, nativeEnd: 17.8, nativeDuration: 17.8 },
    { position: 180, nativeEnd: 17.92, nativeDuration: Infinity },
    { position: 179.95, nativeEnd: 17.92, nativeDuration: 17.92 },
  ])('waits for unavailable source position $position when the native end is $nativeEnd and duration is $nativeDuration', async ({ position, nativeEnd, nativeDuration }) => {
    post.mockResolvedValue({ data: { url: '/manifest', jobId: 'job', renditionSet: 'job.5', sessionId: 'owned-session',
      generation: 5, revision: 5, ready: true, position, status: 'paused', startTime: 162, duration: 180 } })
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef }))
    await act(async () => {})
    const video = containerRef.current.querySelector('video')!
    const setTime = vi.fn()
    Object.defineProperty(video, 'currentTime', { configurable: true, get: () => 0, set: setTime })
    Object.defineProperty(video, 'duration', { configurable: true, value: nativeDuration })
    Object.defineProperty(video, 'seekable', { configurable: true, value: { length: 1, start: () => 0, end: () => nativeEnd } })
    await act(async () => {
      video.dispatchEvent(new Event('loadedmetadata'))
      video.dispatchEvent(new Event('progress'))
    })
    expect(setTime).not.toHaveBeenCalled()
    expect(post.mock.calls.filter(call => call[1].action)).toHaveLength(0)
    unmount()
  })
})
