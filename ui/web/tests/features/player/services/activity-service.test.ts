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
