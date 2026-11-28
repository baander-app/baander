import { StrictMode, type ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { getPartySessionIndex, postActivityVideoProgress, type PartySessionResource } from '@/shared/api-client/gen/endpoints'
import { useVideoViewingReporter } from '../use-video-viewing-reporter'

vi.mock('@/shared/api-client/gen/endpoints', () => ({
  getGetPartySessionIndexQueryKey: () => ['/api/party/sessions/'],
  getPartySessionIndex: vi.fn(),
  postActivityVideoProgress: vi.fn(async () => ({}))
}))

afterEach(() => vi.clearAllMocks())

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const wrapper = ({ children }: { children: ReactNode }) => (
    <StrictMode>
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    </StrictMode>
  )
  const hook = renderHook(({ videoId }) => useVideoViewingReporter(videoId, {
    titleKind: 'extra',
    titleId: 'selectedTitle00000001'
  }, () => true), {
    initialProps: { videoId: 'selectedVideo00000001' },
    wrapper
  })
  return { ...hook, client }
}

function party(videoId: string): PartySessionResource {
  return {
    publicId: 'selectedParty00000001',
    hostUserId: 'selectedHost000000010',
    videoId,
    maxMembers: 10,
    playbackState: 'playing',
    isActive: true,
    createdAt: '2026-11-28T13:00:00Z',
    updatedAt: '2026-11-28T13:00:00Z'
  }
}

describe('fresh party viewing preflight', () => {
  it('observes joining and leaving the selected video without a cached membership grant', async () => {
    const hook = mount()
    vi.mocked(getPartySessionIndex)
      .mockResolvedValueOnce({ data: [party('anotherVideo000000001')] })
      .mockResolvedValueOnce({ data: [party('selectedVideo00000001')] })
      .mockResolvedValueOnce({ data: [] })
    act(() => hook.result.current.action('pause', 30, 'selectedVideo00000001'))
    await waitFor(() => expect(postActivityVideoProgress).toHaveBeenCalledTimes(1))
    act(() => hook.result.current.action('seek', 50, 'selectedVideo00000001'))
    await waitFor(() => expect(getPartySessionIndex).toHaveBeenCalledTimes(2))
    act(() => hook.result.current.action('pause', 80, 'selectedVideo00000001'))
    await waitFor(() => expect(postActivityVideoProgress).toHaveBeenCalledTimes(2))
    expect(getPartySessionIndex).toHaveBeenCalledTimes(3)
    expect(vi.mocked(postActivityVideoProgress).mock.calls.map(call => call[1].positionSeconds)).toEqual([30, 80])
    expect(vi.mocked(getPartySessionIndex).mock.calls.every(call => Object.keys(call[0] ?? {}).join() === 'signal')).toBe(true)
    hook.unmount()
    hook.client.clear()
  })

  it('ignores a previous file callback and a membership response after media changes', async () => {
    const hook = mount()
    let resolve!: (value: Awaited<ReturnType<typeof getPartySessionIndex>>) => void
    vi.mocked(getPartySessionIndex).mockImplementationOnce(() => new Promise(done => { resolve = done }))
    act(() => hook.result.current.action('pause', 30, 'selectedVideo00000001'))
    await waitFor(() => expect(getPartySessionIndex).toHaveBeenCalledTimes(1))
    hook.rerender({ videoId: 'replacedVideo00000001' })
    act(() => hook.result.current.action('pause', 50, 'selectedVideo00000001'))
    await act(async () => resolve({ data: [] }))
    expect(postActivityVideoProgress).not.toHaveBeenCalled()
    hook.unmount()
    hook.client.clear()
  })

  it('suppresses reports if current membership cannot be read', async () => {
    const hook = mount()
    vi.mocked(getPartySessionIndex).mockRejectedValueOnce(new Error('No response'))
    act(() => hook.result.current.action('end', 100, 'selectedVideo00000001'))
    await waitFor(() => expect(getPartySessionIndex).toHaveBeenCalledTimes(1))
    expect(postActivityVideoProgress).not.toHaveBeenCalled()
    hook.unmount()
    hook.client.clear()
  })
})
