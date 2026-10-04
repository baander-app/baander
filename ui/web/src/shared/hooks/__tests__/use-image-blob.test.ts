import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { StrictMode, createElement, type ReactNode } from 'react'
import { renderHook, waitFor, act } from '@testing-library/react'

// Mock AXIOS_INSTANCE before importing the hook
const mockGet = vi.fn()
vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: (...args: unknown[]) => mockGet(...args),
  },
}))

import { useImageBlob } from '../use-image-blob'

describe('useImageBlob', () => {
  beforeEach(() => {
    mockGet.mockReset()

    // Mock URL.createObjectURL / revokeObjectURL
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:https://baander.app/test-blob')
    globalThis.URL.revokeObjectURL = vi.fn()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('returns null src when imageUrl is null', () => {
    const { result } = renderHook(() => useImageBlob(null))
    expect(result.current.src).toBeNull()
    expect(result.current.isLoading).toBe(false)
  })

  it('returns null src when imageUrl is undefined', () => {
    const { result } = renderHook(() => useImageBlob(undefined))
    expect(result.current.src).toBeNull()
    expect(result.current.isLoading).toBe(false)
  })

  it('fetches blob and creates object URL on success', async () => {
    const blob = new Blob(['image data'], { type: 'image/jpeg' })
    mockGet.mockResolvedValue({ data: blob })

    const { result } = renderHook(() => useImageBlob('/api/image/123'))

    // Initially loading
    expect(result.current.isLoading).toBe(true)

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    expect(result.current.src).toBe('blob:https://baander.app/test-blob')
    expect(mockGet).toHaveBeenCalledWith('/api/image/123', {
      responseType: 'blob',
      signal: expect.any(AbortSignal),
    })
  })

  it('retains the loaded cover without another fetch or allocation on unchanged renders', async () => {
    mockGet.mockResolvedValue({ data: new Blob(['image']) })
    const { result, rerender } = renderHook(() => useImageBlob('/api/image/123'))
    await waitFor(() => expect(result.current.isLoading).toBe(false))
    const src = result.current.src
    rerender()
    rerender()
    expect(result.current.src).toBe(src)
    expect(mockGet).toHaveBeenCalledOnce()
    expect(URL.createObjectURL).toHaveBeenCalledOnce()
    expect(URL.revokeObjectURL).not.toHaveBeenCalled()
  })

  it('revokes object URL on unmount', async () => {
    const blob = new Blob(['image data'], { type: 'image/jpeg' })
    mockGet.mockResolvedValue({ data: blob })

    const { result, unmount } = renderHook(() => useImageBlob('/api/image/123'))

    await waitFor(() => {
      expect(result.current.src).toBe('blob:https://baander.app/test-blob')
    })

    unmount()

    expect(globalThis.URL.revokeObjectURL).toHaveBeenCalledWith('blob:https://baander.app/test-blob')
  })

  it('handles fetch error gracefully', async () => {
    mockGet.mockRejectedValue(new Error('Network error'))

    const { result } = renderHook(() => useImageBlob('/api/image/123'))

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    expect(result.current.src).toBeNull()
  })

  function deferredImage() {
    let resolve!: (value: { data: Blob }) => void
    let reject!: (reason: Error) => void
    const promise = new Promise<{ data: Blob }>((yes, no) => { resolve = yes; reject = no })
    return { promise, resolve, reject }
  }

  it('ignores a canceled response while the replacement is still loading', async () => {
    const first = deferredImage()
    const second = deferredImage()
    mockGet.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)
    const { result, rerender } = renderHook(({ url }) => useImageBlob(url), {
      initialProps: { url: '/api/image/1' },
    })
    const signal = mockGet.mock.calls[0][1].signal as AbortSignal
    rerender({ url: '/api/image/2' })
    expect(signal.aborted).toBe(true)
    await act(async () => { first.resolve({ data: new Blob(['stale']) }) })
    expect(result.current).toEqual({ src: null, isLoading: true })
    expect(URL.createObjectURL).not.toHaveBeenCalled()
    await act(async () => { second.resolve({ data: new Blob(['current']) }) })
    expect(result.current.isLoading).toBe(false)
    expect(URL.createObjectURL).toHaveBeenCalledTimes(1)
  })

  it('hides and revokes the previous cover immediately when its URL changes', async () => {
    mockGet.mockResolvedValueOnce({ data: new Blob(['first']) })
    const second = deferredImage()
    mockGet.mockReturnValueOnce(second.promise)
    const { result, rerender } = renderHook(({ url }) => useImageBlob(url), {
      initialProps: { url: '/api/image/1' },
    })
    await waitFor(() => expect(result.current.src).not.toBeNull())
    rerender({ url: '/api/image/2' })
    expect(result.current).toEqual({ src: null, isLoading: true })
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:https://baander.app/test-blob')
    await act(async () => { second.reject(new Error('Missing cover')) })
    expect(result.current).toEqual({ src: null, isLoading: false })
  })

  it('does not resurrect a revoked cover when returning to the same URL', async () => {
    mockGet.mockResolvedValueOnce({ data: new Blob(['first']) })
    const pending = deferredImage()
    mockGet.mockReturnValue(pending.promise)
    const initialProps: { url: string | null } = { url: '/api/image/1' }
    const { result, rerender } = renderHook(({ url }) => useImageBlob(url), { initialProps })
    await waitFor(() => expect(result.current.src).not.toBeNull())
    rerender({ url: null })
    expect(result.current).toEqual({ src: null, isLoading: false })
    rerender({ url: '/api/image/1' })
    expect(result.current).toEqual({ src: null, isLoading: true })
  })

  it('does not allocate a blob URL after unmount even if cancellation is ignored', async () => {
    const pending = deferredImage()
    mockGet.mockReturnValue(pending.promise)
    const { unmount } = renderHook(() => useImageBlob('/api/image/1'))
    unmount()
    await act(async () => { pending.resolve({ data: new Blob(['late']) }) })
    expect(URL.createObjectURL).not.toHaveBeenCalled()
  })

  it('keeps only the live StrictMode request and releases its blob exactly once', async () => {
    const discarded = deferredImage()
    const live = deferredImage()
    mockGet.mockReturnValueOnce(discarded.promise).mockReturnValueOnce(live.promise)
    const { result, unmount } = renderHook(() => useImageBlob('/api/image/1'), {
      wrapper: ({ children }: { children: ReactNode }) => createElement(StrictMode, null, children),
    })
    await act(async () => { live.resolve({ data: new Blob(['live']) }) })
    await act(async () => { discarded.resolve({ data: new Blob(['discarded']) }) })
    expect(result.current.isLoading).toBe(false)
    expect(URL.createObjectURL).toHaveBeenCalledTimes(1)
    unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1)
  })
})
