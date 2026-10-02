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
    window.__BAANDER_API_URL__ = 'https://media.example.test'
  })
  afterEach(() => vi.restoreAllMocks())

  it('waits for authorization and uses the signed manifest', async () => {
    let resolve!: (value: { data: { url: string } }) => void
    post.mockReturnValue(new Promise((done) => { resolve = done }))
    const containerRef = { current: document.createElement('div') }
    const { unmount } = renderHook(() => useBaanderPlayer({ videoId: 'video', containerRef, autoPlay: true }))
    const video = containerRef.current.querySelector('video')!
    expect(video.getAttribute('src')).toBeNull()
    expect(post).toHaveBeenCalledWith('/api/stream/sign', {
      path: '/api/transcode/video/master.m3u8',
    }, { signal: expect.any(AbortSignal) })
    await act(async () => resolve({ data: { url: '/api/transcode/video/master.m3u8?sig=proof&exp=100' } }))
    expect(video.src).toBe('https://media.example.test/api/transcode/video/master.m3u8?sig=proof&exp=100')
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

  it('aborts signing and ignores late responses after cleanup', async () => {
    let resolve!: (value: { data: { url: string } }) => void
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
