import { beforeEach, describe, expect, it, vi } from 'vitest'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@/shared/api-client/gen/endpoints', () => ({ postActivityPlay: post }))
import { activityService } from '@/features/player/services/activity-service'

describe('ActivityService playback attempts', () => {
  beforeEach(() => { post.mockReset(); post.mockResolvedValue(undefined) })

  it('records each successful playback attempt even for consecutive plays of the same song', async () => {
    const payload = { songId: 'song', albumId: 'album' }
    await activityService.recordPlay(payload)
    await activityService.recordPlay(payload)
    expect(post).toHaveBeenCalledTimes(2)
    expect(post).toHaveBeenLastCalledWith({ songId: 'song', albumId: 'album', artistId: null,
      movieId: null, platform: 'web', player: 'baander-web' })
  })

  it('keeps concurrent playback events independent when an older request fails', async () => {
    let rejectOlder!: (reason: Error) => void
    post.mockReturnValueOnce(new Promise((_, reject) => { rejectOlder = reject }))
    const log = vi.spyOn(console, 'error').mockImplementation(() => {})
    try {
      const older = activityService.recordPlay({ songId: 'older' })
      await activityService.recordPlay({ songId: 'newer' })
      rejectOlder(new Error('Offline'))
      await expect(older).resolves.toBeUndefined()
      await activityService.recordPlay({ songId: 'newer' })
      expect(post.mock.calls.map(([payload]) => payload.songId)).toEqual(['older', 'newer', 'newer'])
      expect(post.mock.calls[0][0].albumId).toBeNull()
      expect(log).toHaveBeenCalledOnce()
    } finally { log.mockRestore() }
  })

  it('does not automatically retry an uncertain request or block later playback', async () => {
    post.mockRejectedValueOnce(new Error('Connection lost after submission'))
    const log = vi.spyOn(console, 'error').mockImplementation(() => {})
    try {
      await expect(activityService.recordPlay({ songId: 'retry' })).resolves.toBeUndefined()
      expect(post).toHaveBeenCalledOnce()
      await activityService.recordPlay({ songId: 'retry' })
      expect(post).toHaveBeenCalledTimes(2)
    } finally { log.mockRestore() }
  })
})

it('does not refresh a replacement account after an older play completes', async () => {
  const { QueryClient } = await import('@tanstack/react-query')
  const { synchronizeOwnership } = await import('@/shared/live/ownership')
  synchronizeOwnership('old-principal')
  const oldClient = new QueryClient()
  const detachOld = activityService.attachRefresh(oldClient)
  let finish!: () => void
  post.mockImplementationOnce(() => new Promise<void>(resolve => { finish = resolve }))
  const pending = activityService.recordPlay({ songId: 'old' })
  synchronizeOwnership('new-principal')
  const nextClient = new QueryClient()
  const nextRefresh = vi.spyOn(nextClient, 'invalidateQueries')
  const detachNext = activityService.attachRefresh(nextClient)
  detachOld()
  finish()
  await pending
  expect(nextRefresh).not.toHaveBeenCalled()
  post.mockResolvedValueOnce(undefined)
  await activityService.recordPlay({ songId: 'new' })
  expect(nextRefresh).toHaveBeenCalledOnce()
  detachNext()
  oldClient.clear()
  nextClient.clear()
  synchronizeOwnership(null)
})

it('refreshes observed history and recommendations after a successful local play without a socket', async () => {
  const { QueryClient, QueryObserver } = await import('@tanstack/react-query')
  const client = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity } } })
  let revision = 'before'
  const keys = ['/api/activity/history', '/api/recommendations/', '/api/recommendations/for-you']
  const observers = keys.map(key => new QueryObserver(client, { queryKey: [key], queryFn: async () => revision }))
  const unsubscribe = observers.map(observer => observer.subscribe(() => undefined))
  await vi.waitFor(() => expect(keys.map(key => client.getQueryData([key]))).toEqual(['before', 'before', 'before']))
  const detach = activityService.attachRefresh(client)
  revision = 'after'
  post.mockResolvedValueOnce(undefined)
  await activityService.recordPlay({ songId: 'song' })
  expect(keys.map(key => client.getQueryData([key]))).toEqual(['after', 'after', 'after'])
  unsubscribe.forEach(stop => stop())
  detach()
  client.clear()
})

it('does not publish a new principal play through the previous provider binding before cleanup', async () => {
  const { QueryClient } = await import('@tanstack/react-query')
  const { synchronizeOwnership } = await import('@/shared/live/ownership')
  synchronizeOwnership('previous-principal')
  const client = new QueryClient()
  const invalidate = vi.spyOn(client, 'invalidateQueries')
  const detach = activityService.attachRefresh(client)
  synchronizeOwnership('replacement-principal')
  post.mockResolvedValueOnce(undefined)
  await activityService.recordPlay({ songId: 'replacement-song' })
  expect(invalidate).not.toHaveBeenCalled()
  detach()
  client.clear()
  synchronizeOwnership(null)
})
