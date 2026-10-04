import { act, renderHook } from '@testing-library/react'
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

  const { result, rerender, unmount } = renderHook(
    ({ mediaType }) => useRecentItems({ mediaType }),
    {
      initialProps: { mediaType: 'music' },
      reactStrictMode: true,
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

  expect(result.current.items[0].title).toBe('Current')

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
