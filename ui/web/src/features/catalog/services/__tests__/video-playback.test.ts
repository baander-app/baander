import { describe, expect, it, vi } from 'vitest'
import { requestPlayback } from '@/features/session/services/playback-execution'
import { requestVideoPlayback } from '../video-playback'

vi.mock('@/features/session/services/playback-execution', () => ({
  requestPlayback: vi.fn(async () => {})
}))

describe('video play intent', () => {
  it.each(['episode', 'movie', 'extra'] as const)('carries the selected file and %s identity through coordinated preparation', async titleKind => {
    vi.mocked(requestPlayback).mockClear()
    await requestVideoPlayback({
      titleKind,
      titleId: 'selectedTitle00000001',
      videoId: 'selectedVideo00000001',
      startSeconds: 83
    })
    expect(requestPlayback).toHaveBeenCalledExactlyOnceWith({
      action: 'load',
      media: {
        kind: 'video',
        publicId: 'selectedVideo00000001',
        viewing: { titleKind, titleId: 'selectedTitle00000001' }
      },
      position: 83,
      desiredState: 'playing'
    })
  })

  it.each([-1, NaN, Infinity])('rejects an invalid start before changing media', async startSeconds => {
    vi.mocked(requestPlayback).mockClear()
    await expect(requestVideoPlayback({
      titleKind: 'movie',
      titleId: 'selectedTitle00000001',
      videoId: 'selectedVideo00000001',
      startSeconds
    })).rejects.toThrow('Video start position is invalid.')
    expect(requestPlayback).not.toHaveBeenCalled()
  })
})
