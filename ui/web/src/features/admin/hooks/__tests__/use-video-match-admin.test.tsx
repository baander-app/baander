import type { ReactNode } from 'react'
import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import { useApplyVideoMatch, useVideoMatchChoices, useVideoMatchReviews, videoMatchKeys } from '../use-video-match-admin'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

const barrier = vi.hoisted(() => ({ run: vi.fn(async (operation: () => Promise<unknown>) => operation()) }))
vi.mock('../use-admin-mutation', () => ({
  useAdminMutationTools: () => ({ run: barrier.run, invalidate: vi.fn() }),
}))

const request = vi.mocked(customInstance)
let client: QueryClient

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

const firstReview = {
  items: [],
  pausedProviders: [],
  hasNextPage: true,
  nextCursor: 'next-page',
  perPage: 50,
}

beforeEach(() => {
  client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  request.mockReset()
  barrier.run.mockClear()
})

afterEach(() => {
  cleanup()
  client.clear()
})

describe('video match admin queries', () => {
  it('sends the review filters and follows only the returned cursor', async () => {
    request.mockResolvedValueOnce({ data: firstReview }).mockResolvedValueOnce({ data: { ...firstReview, hasNextPage: false, nextCursor: null } })
    const { result } = renderHook(() => ({ ...useVideoMatchReviews({ kind: 'file', library: 'library-public', reason: 'no_candidates' }) }), { wrapper })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    await act(() => result.current.fetchNextPage())

    const initial = new URL(request.mock.calls[0][0], 'https://baander.app')
    const next = new URL(request.mock.calls[1][0], 'https://baander.app')
    expect(initial.searchParams.get('kind')).toBe('file')
    expect(initial.searchParams.get('library')).toBe('library-public')
    expect(initial.searchParams.get('reason')).toBe('no_candidates')
    expect(initial.searchParams.get('limit')).toBe('50')
    expect(next.searchParams.get('cursor')).toBe('next-page')
    await waitFor(() => expect(result.current.hasNextPage).toBe(false))
  })

  it('requests only the active paged choice collection and keeps inactive choices idle', async () => {
    request.mockResolvedValue({ data: { kind: 'series', publicId: 'show-public', view: 'choices', collection: 'episodes', provider: 'tvdb', currentOrdering: 'aired', orderingProblem: null, orderings: [], episodes: { items: [], total: 100, page: 2, perPage: 50, hasNextPage: false }, files: null } })
    const { result, rerender } = renderHook(({ enabled }) => useVideoMatchChoices('series', 'show-public', 'episodes', 2, enabled), {
      wrapper,
      initialProps: { enabled: false },
    })
    expect(request).not.toHaveBeenCalled()
    rerender({ enabled: true })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))

    const url = new URL(request.mock.calls[0][0], 'https://baander.app')
    expect(url.searchParams.get('collection')).toBe('episodes')
    expect(url.searchParams.get('view')).toBe('choices')
    expect(url.searchParams.get('page')).toBe('2')
    expect(result.current.data?.episodes?.page).toBe(2)
  })

  it('awaits authoritative review refresh after apply through the mutation barrier', async () => {
    let reads = 0
    request.mockImplementation(async (_url, options) => {
      if (options?.method === 'POST') return { data: { kind: 'series', publicId: 'show-public', provider: 'tvdb', providerId: '10', ordering: 'aired' } }
      reads++
      return { data: { ...firstReview, hasNextPage: false, nextCursor: null } }
    })
    const { result } = renderHook(() => ({ reviews: useVideoMatchReviews({}), apply: useApplyVideoMatch() }), { wrapper })
    await waitFor(() => expect(result.current.reviews.isSuccess).toBe(true))
    await act(() => result.current.apply.mutateAsync({ kind: 'series', publicId: 'show-public', provider: 'tvdb', candidateId: '10' }))

    expect(barrier.run).toHaveBeenCalledTimes(1)
    expect(reads).toBe(2)
    expect(client.getQueryState(videoMatchKeys.reviews({}))?.isInvalidated).toBe(false)
    const write = request.mock.calls.find(([, options]) => options?.method === 'POST')
    expect(write?.[0]).toBe('/api/admin/video-matches/series/show-public/apply')
    expect(JSON.parse(String(write?.[1]?.body))).toEqual({ provider: 'tvdb', candidateId: '10' })
  })
})
