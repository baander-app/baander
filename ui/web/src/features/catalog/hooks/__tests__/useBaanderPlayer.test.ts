import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { useBaanderPlayer } from '../useBaanderPlayer'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@/features/session/services/playback-session-coordinator', () => ({
  getPlaybackClientRequestId: vi.fn(async () => 'client-request'),
}))
vi.mock('@/features/player/services/media-delivery', () => ({
  detectDestinationCapabilities: vi.fn(async () => []),
  negotiateMediaDelivery: vi.fn(async () => ({ kind: 'encoded', jobId: 'job-1' })),
  MediaCompatibilityError: class MediaCompatibilityError extends Error {},
}))
vi.mock('../hls-playback', () => ({
  attachHlsPlayback: vi.fn((options: { onReady: () => void }) => {
    options.onReady()
    return () => {}
  }),
}))

interface SignSnapshot {
  jobId: string
  generation: number
  revision: number
  renditionSet: string
  sessionId: string
  ready: boolean
  url: string
  startTime: number
  duration: number
  position: number
  status: string
}

const signSnapshot: SignSnapshot = {
  jobId: 'job-1', generation: 1, revision: 1, renditionSet: 'tier-720p', sessionId: 'session-1',
  ready: true, url: 'https://media.example/signed/master.m3u8', startTime: 0, duration: 223,
  position: 0, status: 'running',
}

const positionPosts: Array<{ path: string; payload: Record<string, unknown> }> = []
let serverSnapshot: SignSnapshot = { ...signSnapshot }

describe('useBaanderPlayer seek handling', () => {
  let container: HTMLDivElement

  beforeEach(() => {
    positionPosts.length = 0
    serverSnapshot = { ...signSnapshot }
    vi.mocked(AXIOS_INSTANCE.post).mockReset().mockImplementation(async (path: string, payload?: unknown) => {
      if (path === '/api/stream/sign') return { data: serverSnapshot }
      if (typeof path === 'string' && path.includes('/position')) {
        const body = payload as Record<string, unknown>
        positionPosts.push({ path, payload: body })
        if (body.action === 'seek' && typeof body.position === 'number') {
          // A server seek restarts the encoder at a boundary at or before the target.
          serverSnapshot = {
            ...serverSnapshot, generation: serverSnapshot.generation + 1, revision: serverSnapshot.revision + 1,
            startTime: body.position, position: body.position,
          }
        }
        return { data: { data: { acceptedGeneration: serverSnapshot.generation } } }
      }
      return { data: {} }
    })
    container = document.createElement('div')
    document.body.appendChild(container)
  })

  afterEach(() => {
    document.body.removeChild(container)
  })

  const settleAttachment = async () => {
    const rendered = renderHook(({ coordinated }) => useBaanderPlayer({
      videoId: 'video-1', containerRef: { current: container }, coordinated,
    }), { initialProps: { coordinated: true } })
    await waitFor(() => {
      expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.some(([path]) => path === '/api/stream/sign')).toBe(true)
    })
    const video = container.querySelector('video')
    expect(video).not.toBeNull()
    Object.defineProperty(video, 'readyState', { configurable: true, value: 4 })
    await act(async () => { video!.dispatchEvent(new Event('canplay')) })
    return { rendered, video: video! }
  }

  it('does not convert a player-internal seek into a server seek', async () => {
    const { rendered, video } = await settleAttachment()
    positionPosts.length = 0

    await act(async () => {
      video.currentTime = 30
      video.dispatchEvent(new Event('seeking'))
    })

    expect(positionPosts.filter(post => post.payload.action === 'seek')).toEqual([])
    rendered.result.current.destroy()
  })

  it('still submits a user seek to the session', async () => {
    const { rendered } = await settleAttachment()
    positionPosts.length = 0

    rendered.result.current.seekTo(100)
    for (let index = 0; index < 10; index += 1) {
      await Promise.resolve()
      if (positionPosts.some(post => post.payload.action === 'seek')) break
    }

    expect(positionPosts.some(post => post.payload.action === 'seek' && post.payload.position === 100)).toBe(true)
    rendered.result.current.destroy()
  })
})
