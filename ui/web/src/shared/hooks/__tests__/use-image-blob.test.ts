import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
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
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:http://localhost/test-blob')
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

    expect(result.current.src).toBe('blob:http://localhost/test-blob')
    expect(mockGet).toHaveBeenCalledWith('/api/image/123', {
      responseType: 'blob',
      signal: expect.any(AbortSignal),
    })
  })

  it('revokes object URL on unmount', async () => {
    const blob = new Blob(['image data'], { type: 'image/jpeg' })
    mockGet.mockResolvedValue({ data: blob })

    const { result, unmount } = renderHook(() => useImageBlob('/api/image/123'))

    await waitFor(() => {
      expect(result.current.src).toBe('blob:http://localhost/test-blob')
    })

    unmount()

    expect(globalThis.URL.revokeObjectURL).toHaveBeenCalledWith('blob:http://localhost/test-blob')
  })

  it('handles fetch error gracefully', async () => {
    mockGet.mockRejectedValue(new Error('Network error'))

    const { result } = renderHook(() => useImageBlob('/api/image/123'))

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    expect(result.current.src).toBeNull()
  })

  it('cancels fetch if imageUrl changes while fetching', async () => {
    let resolveFirst: (value: unknown) => void
    const firstPromise = new Promise((resolve) => { resolveFirst = resolve })
    mockGet.mockImplementationOnce(() => firstPromise)
    mockGet.mockResolvedValue({ data: new Blob(['second'], { type: 'image/jpeg' }) })

    const { result, rerender } = renderHook(
      ({ url }: { url: string }) => useImageBlob(url),
      { initialProps: { url: '/api/image/1' } }
    )

    // Change URL while first is pending
    rerender({ url: '/api/image/2' })

    // Resolve the first (stale) request
    await act(async () => {
      resolveFirst!({ data: new Blob(['stale'], { type: 'image/jpeg' }) })
    })

    await waitFor(() => {
      expect(result.current.isLoading).toBe(false)
    })

    // The second request should be the one that sets the src
    expect(mockGet).toHaveBeenCalledTimes(2)
    expect(mockGet).toHaveBeenCalledWith('/api/image/2', {
      responseType: 'blob',
      signal: expect.any(AbortSignal),
    })
  })
})
