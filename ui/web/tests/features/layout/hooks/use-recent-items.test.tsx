import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import { expect, it, vi } from 'vitest'
import { useRecentItems } from '@/features/layout/hooks/use-recent-items'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {
    get: vi.fn(),
  },
}))

interface RecentItemsResponse {
  data: {
    data: Array<{
      publicId: string
      songTitle: string
    }>
  }
}

function deferred() {
  let resolve!: (value: RecentItemsResponse) => void
  const promise = new Promise<RecentItemsResponse>((resolvePromise) => {
    resolve = resolvePromise
  })

  return { promise, resolve }
}

it('cancels discarded StrictMode and replaced requests, without stale loading/data updates', async () => {
  const discarded = deferred()
  const music = deferred()
  const movies = deferred()
  const mockedGet = vi.mocked(AXIOS_INSTANCE.get)

  mockedGet
    .mockReturnValueOnce(discarded.promise)
    .mockReturnValueOnce(music.promise)
    .mockReturnValueOnce(movies.promise)

  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const { result, rerender, unmount } = renderHook(
    ({ mediaType }) => useRecentItems({ mediaType }),
    {
      initialProps: { mediaType: 'music' },
      reactStrictMode: true,
      wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
    },
  )

  expect(mockedGet).toHaveBeenCalledTimes(2)

  await act(async () => {
    music.resolve({
      data: {
        data: [{ publicId: 'current', songTitle: 'Current' }],
      },
    })
  })

  await waitFor(() => expect(result.current.items[0]?.title).toBe('Current'))

  rerender({ mediaType: 'movies' })

  expect(result.current).toEqual({ items: [], isLoading: true })

  await act(async () => {
    discarded.resolve({
      data: {
        data: [{ publicId: 'old', songTitle: 'Old' }],
      },
    })
  })

  expect(result.current).toEqual({ items: [], isLoading: true })

  const signal = mockedGet.mock.calls.at(-1)?.[1]?.signal

  unmount()

  expect(signal?.aborted).toBe(true)

  await act(async () => {
    movies.resolve({ data: { data: [] } })
  })
})

it('shares matching Recents reads and refreshes mounted variants after a local play during an outage', async () => {
  const { activityService } = await import('@/features/player/services/activity-service')
  const endpoints = await import('@/shared/api-client/gen/endpoints')
  const play = vi.spyOn(endpoints, 'postActivityPlay').mockResolvedValue(
    {} as Awaited<ReturnType<typeof endpoints.postActivityPlay>>,
  )
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
  const detach = activityService.attachRefresh(client)
  let title = 'Before'
  vi.mocked(AXIOS_INSTANCE.get).mockReset().mockImplementation(async () => ({
    data: { data: [{ publicId: 'song', songTitle: title }] },
  }))
  const hook = renderHook(() => [useRecentItems(), useRecentItems()], {
    wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
  })
  await waitFor(() => expect(hook.result.current[0].items[0]?.title).toBe('Before'))
  expect(AXIOS_INSTANCE.get).toHaveBeenCalledOnce()
  title = 'After'
  await act(async () => {
    await activityService.recordPlay({ songId: 'song' })
  })
  await waitFor(() => expect(hook.result.current.map(result => result.items[0]?.title)).toEqual(['After', 'After']))
  expect(AXIOS_INSTANCE.get).toHaveBeenCalledTimes(2)
  hook.unmount()
  detach()
  client.clear()
  play.mockRestore()
})
