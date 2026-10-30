import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { StrictMode, createElement, type ReactNode } from 'react'
import { renderHook as renderBareHook, render, screen, waitFor, act } from '@testing-library/react'

// Mock AXIOS_INSTANCE before importing the hook
const mockGet = vi.fn()
vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: (...args: unknown[]) => mockGet(...args),
  },
}))

import { QueryClientProvider } from '@tanstack/react-query'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { catalogLiveDescriptors } from '@/features/catalog/live-descriptors'
import { advanceAuthorization } from '@/shared/live/ownership'
import type { LiveSnapshot } from '@/shared/live/recovery'

import { CoverArt } from '@/shared/components/cover-art'
import { useImageBlob } from '../use-image-blob'

const clients: OwnedQueryClient[] = []
function makeClient() {
  const client = new OwnedQueryClient()
  client.setDefaultOptions({ queries: { retry: false } })
  clients.push(client)
  return client
}
const renderHook: typeof renderBareHook = (callback, options) => {
  const client = makeClient()
  const Wrapper = options?.wrapper
  return renderBareHook(callback, {
    ...options,
    wrapper: ({ children }) => createElement(QueryClientProvider, { client },
      Wrapper ? createElement(Wrapper, null, children) : children),
  })
}

describe('useImageBlob', () => {
  beforeEach(() => {
    mockGet.mockReset()

    // Mock URL.createObjectURL / revokeObjectURL
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:https://baander.app/test-blob')
    globalThis.URL.revokeObjectURL = vi.fn()
  })

  afterEach(() => {
    clients.splice(0).forEach(client => client.dispose())
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
    await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(1))
    const signal = mockGet.mock.calls[0][1].signal as AbortSignal
    rerender({ url: '/api/image/2' })
    expect(signal.aborted).toBe(true)
    await act(async () => { first.resolve({ data: new Blob(['stale']) }) })
    expect(result.current).toEqual({ src: null, isLoading: true })
    expect(URL.createObjectURL).not.toHaveBeenCalled()
    await act(async () => { second.resolve({ data: new Blob(['current']) }) })
    await waitFor(() => expect(result.current.isLoading).toBe(false))
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
    await waitFor(() => expect(result.current).toEqual({ src: null, isLoading: false }))
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
    await waitFor(() => expect(clients.at(-1)?.getQueryCache().findAll({ queryKey: ['catalog', 'binary-image', '/api/image/1'] })).toHaveLength(0))
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
    const live = deferredImage()
    mockGet.mockReturnValue(live.promise)
    const { result, unmount } = renderHook(() => useImageBlob('/api/image/1'), {
      wrapper: ({ children }: { children: ReactNode }) => createElement(StrictMode, null, children),
    })
    await act(async () => { live.resolve({ data: new Blob(['live']) }) })
    expect(mockGet).toHaveBeenCalledTimes(1)
    await waitFor(() => expect(result.current.isLoading).toBe(false))
    expect(URL.createObjectURL).toHaveBeenCalledTimes(1)
    unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1)
  })
  it('refreshes a mounted stable source after a catalog hint and retains it during a failed refresh', async () => {
    const client = makeClient()
    const snapshot: LiveSnapshot = {
      status: 'current', authorityReady: true, families: ['catalog'],
      authorizationFingerprint: 'a'.repeat(64),
      health: { state: 'current', oldestPendingAgeMs: null },
    }
    const recovery = {
      getSnapshot: () => snapshot, subscribe: () => () => undefined,
      subscribeEvents: () => () => undefined, completeRecovery: () => undefined,
      resourceReadFailed: vi.fn(),
    }
    const coordinator = new LiveQueryCoordinator(client, recovery, catalogLiveDescriptors)
    client.setCoordinator(coordinator)
    const stop = coordinator.start()
    const first = new Blob(['first'])
    const second = new Blob(['second'])
    mockGet.mockResolvedValueOnce({ data: first })
    vi.mocked(URL.createObjectURL).mockReturnValueOnce('blob:first').mockReturnValueOnce('blob:second')
    const hook = renderBareHook(() => useImageBlob('/api/albums/album/cover'), {
      wrapper: ({ children }) => createElement(QueryClientProvider, { client }, children),
    })
    await waitFor(() => expect(hook.result.current.src).toBe('blob:first'))
    mockGet.mockRejectedValueOnce(new Error('offline'))
    act(() => coordinator.hint(['catalog']))
    await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(2))
    expect(hook.result.current.src).toBe('blob:first')
    mockGet.mockResolvedValueOnce({ data: second })
    act(() => coordinator.hint(['catalog']))
    await waitFor(() => expect(hook.result.current.src).toBe('blob:second'), { timeout: 3000 })
    expect(URL.createObjectURL).toHaveBeenLastCalledWith(second)
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:first')
    hook.unmount()
    stop()
  })

  it('purges the loaded source on same-principal authorization reset and ignores late old bytes', async () => {
    mockGet.mockResolvedValueOnce({ data: new Blob(['first']) })
    const client = makeClient()
    const detach = client.attach()
    const hook = renderBareHook(() => useImageBlob('/api/albums/album/cover'), {
      wrapper: ({ children }) => createElement(QueryClientProvider, { client }, children),
    })
    await waitFor(() => expect(hook.result.current.src).not.toBeNull())
    const pending = deferredImage()
    mockGet.mockReturnValue(pending.promise)
    await act(async () => { advanceAuthorization() })
    expect(hook.result.current.src).toBeNull()
    expect(URL.revokeObjectURL).toHaveBeenCalled()
    hook.unmount()
    await act(async () => { pending.resolve({ data: new Blob(['late']) }) })
    expect(URL.createObjectURL).toHaveBeenCalledTimes(1)
    detach()
  })

  it('updates rendered album artwork from a catalog hint without changing the selected source', async () => {
    const client = makeClient()
    const snapshot: LiveSnapshot = {
      status: 'current', authorityReady: true, families: ['catalog'],
      authorizationFingerprint: 'a'.repeat(64),
      health: { state: 'current', oldestPendingAgeMs: null },
    }
    const recovery = {
      getSnapshot: () => snapshot, subscribe: () => () => undefined,
      subscribeEvents: () => () => undefined, completeRecovery: () => undefined,
      resourceReadFailed: vi.fn(),
    }
    const coordinator = new LiveQueryCoordinator(client, recovery, catalogLiveDescriptors)
    client.setCoordinator(coordinator)
    const stop = coordinator.start()
    mockGet.mockResolvedValueOnce({ data: new Blob(['old artwork']) })
      .mockResolvedValueOnce({ data: new Blob(['new artwork']) })
    vi.mocked(URL.createObjectURL).mockReturnValueOnce('blob:old-artwork').mockReturnValueOnce('blob:new-artwork')
    const view = render(createElement(QueryClientProvider, { client }, createElement(CoverArt, { albumPublicId: 'album' })))
    await waitFor(() => expect(screen.getByAltText('Album cover')).toHaveAttribute('src', 'blob:old-artwork'))
    act(() => coordinator.hint(['catalog']))
    await waitFor(() => expect(screen.getByAltText('Album cover')).toHaveAttribute('src', 'blob:new-artwork'))
    expect(mockGet.mock.calls.map(call => call[0])).toEqual(['/api/albums/album/cover', '/api/albums/album/cover'])
    view.unmount()
    stop()
  })

  it('shares bytes across mounted observers while each renderer owns its object URL', async () => {
    const client = makeClient()
    const blob = new Blob(['shared'])
    mockGet.mockResolvedValue({ data: blob })
    vi.mocked(URL.createObjectURL).mockReturnValueOnce('blob:one').mockReturnValueOnce('blob:two')
    const wrapper = ({ children }: { children: ReactNode }) => createElement(QueryClientProvider, { client }, children)
    const first = renderBareHook(() => useImageBlob('/api/image/shared'), { wrapper })
    const second = renderBareHook(() => useImageBlob('/api/image/shared'), { wrapper })
    await waitFor(() => expect(second.result.current.src).toBe('blob:two'))
    expect(mockGet).toHaveBeenCalledTimes(1)
    first.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:one')
    expect(URL.revokeObjectURL).not.toHaveBeenCalledWith('blob:two')
    expect(second.result.current.src).toBe('blob:two')
    second.unmount()
    await waitFor(() => expect(client.getQueryCache().getAll()).toHaveLength(0))
  })

  it('revokes retained bytes when a refresh reports that the image is forbidden', async () => {
    const client = makeClient()
    mockGet.mockResolvedValueOnce({ data: new Blob(['protected']) })
    const hook = renderBareHook(() => useImageBlob('/api/image/protected'), {
      wrapper: ({ children }) => createElement(QueryClientProvider, { client }, children),
    })
    await waitFor(() => expect(hook.result.current.src).not.toBeNull())
    mockGet.mockRejectedValueOnce({ response: { status: 403 } })
    await act(async () => { await client.invalidateQueries({ queryKey: ['catalog', 'binary-image'] }) })
    await waitFor(() => expect(hook.result.current.src).toBeNull())
    expect(URL.revokeObjectURL).toHaveBeenCalled()
    hook.unmount()
  })

  it('hides denied image bytes before the production retry delay elapses', async () => {
    const client = new OwnedQueryClient()
    clients.push(client)
    mockGet.mockResolvedValueOnce({ data: new Blob(['protected']) })
    const hook = renderBareHook(() => useImageBlob('/api/image/protected'), {
      wrapper: ({ children }) => createElement(QueryClientProvider, { client }, children),
    })
    await waitFor(() => expect(hook.result.current.src).not.toBeNull())
    const retry = deferredImage()
    mockGet.mockRejectedValueOnce({ response: { status: 403 } }).mockReturnValue(retry.promise)
    let refresh: Promise<void> | undefined
    act(() => { refresh = client.invalidateQueries({ queryKey: ['catalog', 'binary-image'] }) })
    await waitFor(() => expect(hook.result.current.src).toBeNull(), { timeout: 500 })
    expect(mockGet).toHaveBeenCalledTimes(2)
    expect(URL.revokeObjectURL).toHaveBeenCalled()
    hook.unmount()
    await refresh
  })

  it('treats absent album artwork as successful data and reconciles artwork added then removed', async () => {
    const client = new OwnedQueryClient()
    clients.push(client)
    const snapshot: LiveSnapshot = {
      status: 'current', authorityReady: true, families: ['catalog'],
      authorizationFingerprint: 'a'.repeat(64),
      health: { state: 'current', oldestPendingAgeMs: null },
    }
    const recovery = {
      getSnapshot: () => snapshot, subscribe: () => () => undefined,
      subscribeEvents: () => () => undefined, completeRecovery: vi.fn(),
      resourceReadFailed: vi.fn(),
    }
    const coordinator = new LiveQueryCoordinator(client, recovery, catalogLiveDescriptors)
    client.setCoordinator(coordinator)
    const stop = coordinator.start()
    mockGet.mockRejectedValue({ response: { status: 404 } })
    vi.mocked(URL.createObjectURL).mockReturnValue('blob:added-artwork')
    const view = render(createElement(QueryClientProvider, { client }, createElement(CoverArt, { albumPublicId: 'album' })))
    const imageQuery = () => client.getQueryCache().getAll()[0]
    try {
      await waitFor(() => expect(imageQuery()?.state.status).toBe('success'), { timeout: 500 })
      expect(imageQuery().state.data).toBeNull()
      expect(imageQuery().state.fetchFailureCount).toBe(0)
      expect(screen.queryByAltText('Album cover')).not.toBeInTheDocument()
      expect(mockGet).toHaveBeenCalledTimes(1)
      expect(recovery.resourceReadFailed).not.toHaveBeenCalled()

      mockGet.mockResolvedValueOnce({ data: new Blob(['added artwork']) })
      act(() => coordinator.hint(['catalog']))
      await waitFor(() => expect(screen.getByAltText('Album cover')).toHaveAttribute('src', 'blob:added-artwork'))
      expect(mockGet).toHaveBeenCalledTimes(2)

      act(() => coordinator.hint(['catalog']))
      await waitFor(() => expect(screen.queryByAltText('Album cover')).not.toBeInTheDocument())
      expect(imageQuery().state.status).toBe('success')
      expect(imageQuery().state.data).toBeNull()
      expect(imageQuery().state.fetchFailureCount).toBe(0)
      expect(imageQuery().state.isInvalidated).toBe(false)
      expect(mockGet).toHaveBeenCalledTimes(3)
      expect(recovery.resourceReadFailed).not.toHaveBeenCalled()
      expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:added-artwork')
    } finally {
      view.unmount()
      stop()
    }
  })

})
