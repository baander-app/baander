import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { negotiateMediaDelivery } from '@/features/player/services/media-delivery'
import { attachHlsPlayback } from '../hls-playback'
import { attachNativePlayback } from '../native-playback'
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
vi.mock('../native-playback', () => ({
  attachNativePlayback: vi.fn((options: { onReady: () => void }) => {
    options.onReady()
    return () => {}
  }),
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
  ready: true, url: 'https://media.baander.app/signed/master.m3u8', startTime: 0, duration: 223,
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

interface Snapshot extends SignSnapshot { jobIds: string[] }
type SignBody = { refresh?: boolean; jobIds?: string[]; attachedSet?: string }
type PositionBody = { action: string; position: number }

function gone(reason: string) {
  return Object.assign(new Error('Gone'), { isAxiosError: true, response: { status: 410, data: { error: 'The playback sessions have ended.', reason } } })
}

function unavailable() {
  return Object.assign(new Error('Unavailable'), { isAxiosError: true, response: { status: 503, data: {} } })
}

describe('useBaanderPlayer sessions and source end', () => {
  let container: HTMLDivElement
  let server: Snapshot
  let signs: SignBody[]
  let controls: Array<PositionBody & { session: string }>
  let sign: (body: SignBody) => Snapshot
  const mounted: Array<{ unmount: () => void }> = []
  const play = HTMLMediaElement.prototype.play
  const pause = HTMLMediaElement.prototype.pause

  const first: Snapshot = {
    jobId: 'job-1', jobIds: ['job-1'], generation: 1, revision: 1, renditionSet: 'job-1.1', sessionId: 'session-1',
    ready: true, url: 'https://media.baander.app/signed/master.m3u8?set=job-1.1', startTime: 0, duration: 223.59,
    position: 0, status: 'running',
  }

  beforeEach(() => {
    server = { ...first }
    signs = []
    controls = []
    sign = () => server
    HTMLMediaElement.prototype.play = vi.fn(async () => {})
    HTMLMediaElement.prototype.pause = vi.fn()
    vi.mocked(attachHlsPlayback).mockClear()
    vi.mocked(attachNativePlayback).mockClear()
    vi.mocked(negotiateMediaDelivery).mockReset().mockResolvedValue({ kind: 'encoded', jobId: 'job-1' } as never)
    vi.mocked(AXIOS_INSTANCE.post).mockReset().mockImplementation(async (path: string, payload?: unknown) => {
      if (path === '/api/stream/sign') {
        signs.push(payload as SignBody)
        return { data: sign(payload as SignBody) }
      }
      const body = payload as PositionBody
      controls.push({ ...body, session: path.split('/')[4] })
      if (body.action === 'seek') {
        // A seek restarts the encoders six seconds before the target, in a new generation.
        const startTime = Math.max(0, Math.floor(body.position / 6) * 6 - 18)
        server = { ...server, generation: server.generation + 1, revision: server.revision + 1, startTime, position: body.position,
          renditionSet: `${server.jobId}.${server.generation + 1}`, url: `https://media.baander.app/signed/master.m3u8?set=${server.jobId}.${server.generation + 1}` }
        return { data: { data: { acceptedGeneration: server.generation } } }
      }
      if (body.action === 'pause' || body.action === 'resume') {
        server = { ...server, revision: server.revision + 1, status: body.action === 'pause' ? 'paused' : 'running', position: body.position }
      }
      return { data: { data: {} } }
    })
    container = document.createElement('div')
    document.body.appendChild(container)
  })

  afterEach(() => {
    // A player left running would keep signing into the next test.
    for (const hook of mounted.splice(0)) hook.unmount()
    HTMLMediaElement.prototype.play = play
    HTMLMediaElement.prototype.pause = pause
    document.body.removeChild(container)
  })

  // A media element whose clock and seek state the test owns, as jsdom plays nothing.
  const mount = async (element: { seekable?: number, staysSeeking?: boolean } = {}) => {
    // One ref object for the hook's lifetime: a new one on every render would remount the player.
    const containerRef = { current: container }
    const rendered = renderHook(() => useBaanderPlayer({ videoId: 'video-1', containerRef, coordinated: true }))
    mounted.push(rendered)
    const video = container.querySelector('video')!
    const state = { time: 0, seeking: false, paused: true, ended: false }
    Object.defineProperty(video, 'readyState', { configurable: true, value: 4 })
    Object.defineProperty(video, 'currentTime', { configurable: true, get: () => state.time, set: (value: number) => {
      state.time = value
      state.seeking = element.staysSeeking === true && value > 0
    } })
    Object.defineProperty(video, 'seeking', { configurable: true, get: () => state.seeking })
    Object.defineProperty(video, 'paused', { configurable: true, get: () => state.paused })
    Object.defineProperty(video, 'ended', { configurable: true, get: () => state.ended })
    video.play = vi.fn(async () => {
      if (!state.paused) return
      state.paused = false
      video.dispatchEvent(new Event('play'))
    })
    video.pause = vi.fn(() => {
      if (state.paused) return
      state.paused = true
      video.dispatchEvent(new Event('pause'))
    })
    // Loading a new source resets the element, as a browser does.
    video.load = vi.fn(() => {
      state.paused = true
      state.seeking = false
    })
    if (element.seekable !== undefined) {
      Object.defineProperty(video, 'seekable', { configurable: true, value: { length: 1, start: () => 0, end: () => element.seekable } })
      Object.defineProperty(video, 'duration', { configurable: true, value: element.seekable })
    }
    await waitFor(() => { expect(signs.length).toBeGreaterThan(0) })
    await act(async () => { video.dispatchEvent(new Event('canplay')) })
    return { rendered, video, state, execution: rendered.result.current.execution }
  }
  const seeks = () => controls.filter(control => control.action === 'seek').map(control => control.position)
  const attachments = () => vi.mocked(attachHlsPlayback).mock.calls.length
  const signal = () => AbortSignal.timeout(4000)

  it('sends the attached rendition set with a refresh, never with a start', async () => {
    await mount()
    expect(signs[0]).not.toHaveProperty('refresh')
    expect(signs[0]).not.toHaveProperty('attachedSet')

    await waitFor(() => { expect(signs.some(body => body.refresh === true)).toBe(true) }, { timeout: 3000 })

    expect(signs.filter(body => body.refresh === true).every(body => body.attachedSet === 'job-1.1')).toBe(true)
  })

  it('keeps its attachment while the signed set stays and replaces it for a new generation', async () => {
    await mount()
    await waitFor(() => { expect(signs.filter(body => body.refresh === true).length).toBeGreaterThan(0) }, { timeout: 3000 })
    expect(attachments()).toBe(1)

    server = { ...server, generation: 2, revision: 2, renditionSet: 'job-1.2', url: 'https://media.baander.app/signed/master.m3u8?set=job-1.2' }

    await waitFor(() => { expect(attachments()).toBe(2) }, { timeout: 3000 })
    await waitFor(() => { expect(signs.at(-1)?.attachedSet).toBe('job-1.2') }, { timeout: 3000 })
  })

  it.each([
    { delivery: 'encoded', start: (body: SignBody) => body.refresh !== true },
    { delivery: 'copy', start: (body: SignBody) => body.jobIds?.[0] === 'job-2' },
  ])('continues at its position on a new session after its $delivery sessions expired', async ({ delivery, start }) => {
    vi.mocked(negotiateMediaDelivery).mockResolvedValueOnce({ kind: delivery, jobId: 'job-1' } as never).mockResolvedValue({ kind: delivery, jobId: 'job-2' } as never)
    const { rendered, state } = await mount()
    state.time = 42
    const second: Snapshot = { ...first, jobId: 'job-2', jobIds: ['job-2'], sessionId: 'session-2', renditionSet: 'job-2.1', url: 'https://media.baander.app/signed/master.m3u8?set=job-2.1' }
    sign = body => {
      if (start(body)) {
        if (server.jobId !== 'job-2') server = second
        return server
      }
      if (body.jobIds?.[0] === 'job-1') throw gone('expired')
      return server
    }

    await waitFor(() => { expect(controls.some(control => control.session === 'session-2' && control.action === 'seek')).toBe(true) }, { timeout: 4000 })

    // The ended delivery is dropped with the ended sessions: a copy delivery names its job.
    expect(vi.mocked(negotiateMediaDelivery)).toHaveBeenCalledTimes(2)
    expect(controls.filter(control => control.session === 'session-2' && control.action === 'seek').map(control => control.position)).toEqual([42])
    expect(signs.filter(start)).toHaveLength(delivery === 'encoded' ? 2 : signs.filter(start).length)
    expect(rendered.result.current.error).toBeNull()
    await waitFor(() => { expect(attachments()).toBe(2) }, { timeout: 3000 })
  })

  // Sessions that expire while the test's player holds them, and one new session set to continue on.
  const expireSessions = () => {
    const second: Snapshot = { ...first, jobId: 'job-2', jobIds: ['job-2'], sessionId: 'session-2', renditionSet: 'job-2.1', url: 'https://media.baander.app/signed/master.m3u8?set=job-2.1' }
    sign = body => {
      if (body.refresh !== true) {
        if (server.jobId !== 'job-2') server = second
        return server
      }
      if (body.jobIds?.[0] === 'job-1') throw gone('expired')
      return server
    }
  }
  const onNewSession = () => controls.filter(control => control.session === 'session-2').map(control => `${control.action} ${control.position}`)

  it('continues paused at its position when its sessions expired while it was paused', async () => {
    const { rendered, video, state, execution } = await mount()
    await act(async () => { await execution.execute('play', undefined, signal()) })
    // Paused on the element, as with its own controls: the player still counts as started.
    state.time = 42
    await act(async () => { video.pause() })
    await waitFor(() => { expect(server.status).toBe('paused') })
    const plays = vi.mocked(video.play).mock.calls.length
    expireSessions()

    await waitFor(() => { expect(onNewSession()).toEqual(['seek 42', 'pause 42']) }, { timeout: 4000 })
    await waitFor(() => { expect(attachments()).toBe(2) }, { timeout: 3000 })
    await waitFor(() => { expect(server.status).toBe('paused') })
    // Several signings later the element has still not started by itself.
    await new Promise(resolve => setTimeout(resolve, 1500))

    expect(vi.mocked(video.play).mock.calls.length).toBe(plays)
    expect(state.paused).toBe(true)
    expect(server).toMatchObject({ sessionId: 'session-2', status: 'paused', position: 42 })
    expect(onNewSession()).toEqual(['seek 42', 'pause 42'])
    expect(rendered.result.current.error).toBeNull()
  })

  it('pauses the new session without a seek when it expired while paused at the start', async () => {
    const { rendered, state, execution } = await mount()
    await act(async () => { await execution.execute('pause', undefined, signal()) })
    await waitFor(() => { expect(server.status).toBe('paused') })
    expireSessions()

    await waitFor(() => { expect(onNewSession()).toEqual(['pause 0']) }, { timeout: 4000 })
    await waitFor(() => { expect(attachments()).toBe(2) }, { timeout: 3000 })

    expect(state.paused).toBe(true)
    expect(server).toMatchObject({ sessionId: 'session-2', status: 'paused' })
    expect(rendered.result.current.error).toBeNull()
  })

  it('continues playing at its position when its sessions expired while it was playing', async () => {
    const { rendered, video, state, execution } = await mount()
    await act(async () => { await execution.execute('play', undefined, signal()) })
    state.time = 42
    const plays = vi.mocked(video.play).mock.calls.length
    expireSessions()

    await waitFor(() => { expect(attachments()).toBe(2) }, { timeout: 4000 })
    await waitFor(() => { expect(state.paused).toBe(false) }, { timeout: 3000 })

    expect(vi.mocked(video.play).mock.calls.length).toBeGreaterThan(plays)
    expect(onNewSession().filter(control => !control.startsWith('progress'))).toEqual(['seek 42'])
    expect(server).toMatchObject({ sessionId: 'session-2', status: 'running' })
    expect(rendered.result.current.error).toBeNull()
  })

  it('fails when a paused restart loses its new session before that session answered a refresh or a control', async () => {
    const { rendered, video, state, execution } = await mount()
    await act(async () => { await execution.execute('play', undefined, signal()) })
    state.time = 42
    await act(async () => { video.pause() })
    await waitFor(() => { expect(server.status).toBe('paused') })
    const second: Snapshot = { ...first, jobId: 'job-2', jobIds: ['job-2'], sessionId: 'session-2', renditionSet: 'job-2.1' }
    // The new sessions are gone at once: they refuse the restart's controls and every refresh.
    const post = vi.mocked(AXIOS_INSTANCE.post).getMockImplementation()!
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path: string, payload?: unknown) => {
      if (path.includes('/session-2/')) throw Object.assign(new Error('Not found'), { isAxiosError: true, response: { status: 404, data: {} } })
      return post(path, payload)
    })
    sign = body => {
      if (body.refresh === true) throw gone('expired')
      server = second
      return server
    }

    await waitFor(() => { expect(rendered.result.current.error).toBe('Playback could not continue on a new session.') }, { timeout: 6000 })

    expect(rendered.result.current.state).toBe('error')
    expect(onNewSession()).toEqual([])
    expect(signs.filter(body => body.refresh !== true)).toHaveLength(2)
  }, 10000)

  it('restarts a paused player again when its held new session expires later', async () => {
    const { rendered, video, state, execution } = await mount()
    await act(async () => { await execution.execute('play', undefined, signal()) })
    state.time = 42
    await act(async () => { video.pause() })
    await waitFor(() => { expect(server.status).toBe('paused') })
    const plays = vi.mocked(video.play).mock.calls.length
    const ended = new Set<string>()
    let started = 1
    sign = body => {
      if (body.refresh !== true) {
        started += 1
        server = { ...first, jobId: `job-${started}`, jobIds: [`job-${started}`], sessionId: `session-${started}`, renditionSet: `job-${started}.1`,
          url: `https://media.baander.app/signed/master.m3u8?set=job-${started}.1` }
        return server
      }
      if (ended.has(body.jobIds?.[0] ?? '')) throw gone('expired')
      return server
    }
    const on = (session: string) => controls.filter(control => control.session === session).map(control => `${control.action} ${control.position}`)

    // The first night: the paused tab's sessions expire and it continues paused on new ones.
    ended.add('job-1')
    await waitFor(() => { expect(on('session-2')).toEqual(['seek 42', 'pause 42']) }, { timeout: 4000 })
    await waitFor(() => { expect(signs.some(body => body.refresh === true && body.jobIds?.[0] === 'job-2')).toBe(true) }, { timeout: 3000 })
    await waitFor(() => { expect(server).toMatchObject({ sessionId: 'session-2', status: 'paused' }) })

    // The second night: the sessions it held since then expire as well.
    ended.add('job-2')
    await waitFor(() => { expect(on('session-3')).toEqual(['seek 42', 'pause 42']) }, { timeout: 5000 })
    await waitFor(() => { expect(server).toMatchObject({ sessionId: 'session-3', status: 'paused', position: 42 }) })

    expect(rendered.result.current.error).toBeNull()
    expect(signs.filter(body => body.refresh !== true)).toHaveLength(3)
    expect(vi.mocked(video.play).mock.calls.length).toBe(plays)
    expect(state.paused).toBe(true)
  }, 15000)

  it('fails when the new session answers its controls with 200 but its next signing says it is gone', async () => {
    const { rendered, video, state, execution } = await mount()
    await act(async () => { await execution.execute('play', undefined, signal()) })
    state.time = 42
    await act(async () => { video.pause() })
    await waitFor(() => { expect(server.status).toBe('paused') })
    const second: Snapshot = { ...first, jobId: 'job-2', jobIds: ['job-2'], sessionId: 'session-2', renditionSet: 'job-2.1' }
    // An ended session still answers a control with 200; it accepts nothing into a generation.
    const post = vi.mocked(AXIOS_INSTANCE.post).getMockImplementation()!
    const answered: string[] = []
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path: string, payload?: unknown) => {
      if (!path.includes('/session-2/')) return post(path, payload)
      answered.push((payload as PositionBody).action)
      return { data: { data: {} } }
    })
    sign = body => {
      if (body.refresh === true) throw gone('expired')
      server = second
      return server
    }

    await waitFor(() => { expect(rendered.result.current.error).toBe('Playback could not continue on a new session.') }, { timeout: 6000 })

    expect(answered).toContain('seek')
    expect(signs.filter(body => body.refresh !== true)).toHaveLength(2)
  }, 10000)

  it('continues at the cursor kept for recovery when its sessions expired after a media failure', async () => {
    // The signed delivery has run out, so a network failure of the media is recovered, not fatal.
    server = { ...first, url: 'https://media.baander.app/signed/master.m3u8?set=job-1.1&exp=1' }
    const { rendered, state } = await mount()
    state.time = 42
    const attachment = vi.mocked(attachHlsPlayback).mock.calls[0][0] as unknown as { onError: (failure?: 'network') => void }
    await act(async () => { attachment.onError('network') })
    expect(rendered.result.current.state).toBe('preparing')
    // The released element has lost its clock; only the kept cursor still knows the position.
    state.time = 0
    expireSessions()

    await waitFor(() => { expect(onNewSession().filter(control => control.startsWith('seek'))).toEqual(['seek 42']) }, { timeout: 4000 })

    expect(rendered.result.current.error).toBeNull()
  })

  it('names no delivery URL as its attached set when a copy replaces an original delivery', async () => {
    const original = 'https://media.baander.app/signed/original.mp4?exp=1'
    vi.mocked(negotiateMediaDelivery).mockReset()
      .mockResolvedValueOnce({ kind: 'original', url: original, jobId: null } as never)
      .mockResolvedValue({ kind: 'copy', url: original, jobId: 'job-1' } as never)
    const containerRef = { current: container }
    mounted.push(renderHook(() => useBaanderPlayer({ videoId: 'video-1', containerRef, coordinated: true })))

    // The original delivery's capability has run out, so the next poll negotiates again.
    await waitFor(() => { expect(vi.mocked(attachNativePlayback)).toHaveBeenCalledTimes(1) }, { timeout: 3000 })
    await waitFor(() => { expect(signs.length).toBeGreaterThan(0) }, { timeout: 4000 })

    expect(signs[0]).toMatchObject({ refresh: true, jobIds: ['job-1'] })
    expect(signs.every(body => body.attachedSet === undefined || body.attachedSet === 'job-1.1')).toBe(true)
    expect(signs[0]).not.toHaveProperty('attachedSet')
  })

  it('ignores a late failure of a replaced session when the new session is prepared', async () => {
    const { rendered, video, execution } = await mount()
    await act(async () => { await execution.execute('play', undefined, signal()) })
    // The old session's pause is still on its way when the sessions expire.
    const post = vi.mocked(AXIOS_INSTANCE.post).getMockImplementation()!
    let failLate: (error: unknown) => void = () => {}
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path: string, payload?: unknown) => {
      if (!path.includes('/session-1/')) return post(path, payload)
      return new Promise((_resolve, reject) => { failLate = reject })
    })
    await act(async () => { video.pause() })
    expireSessions()
    await waitFor(() => { expect(signs.filter(body => body.refresh !== true)).toHaveLength(2) }, { timeout: 4000 })

    await act(async () => { failLate(Object.assign(new Error('Not found'), { isAxiosError: true, response: { status: 404, data: {} } })) })
    await waitFor(() => { expect(onNewSession()).toEqual(['pause 0']) }, { timeout: 3000 })
    await act(async () => { await execution.prepare(0, false, signal()) })

    expect(rendered.result.current.error).toBeNull()
    expect(rendered.result.current.state).not.toBe('error')
  })

  it('continues on a delivery without a session at its position and keeps no restart for later sessions', async () => {
    const original = 'https://media.baander.app/signed/original.mp4?exp=99999999999'
    vi.mocked(negotiateMediaDelivery).mockReset()
      .mockResolvedValueOnce({ kind: 'encoded', url: null, jobId: 'job-1' } as never)
      .mockResolvedValue({ kind: 'original', url: original, jobId: null } as never)
    const { rendered, state } = await mount()
    state.time = 42
    sign = body => {
      if (body.refresh === true) throw gone('expired')
      return server
    }

    await waitFor(() => { expect(vi.mocked(attachNativePlayback)).toHaveBeenCalledTimes(1) }, { timeout: 4000 })

    expect((vi.mocked(attachNativePlayback).mock.calls[0][0] as unknown as { position: number }).position).toBe(42)
    expect(signs.filter(body => body.refresh !== true)).toHaveLength(1)
    expect(rendered.result.current.error).toBeNull()
  })

  it.each([
    { reason: 'failed', message: 'The video could not be converted for playback.' },
    { reason: 'cancelled', message: 'Playback of this video was cancelled.' },
  ])('shows the error and starts nothing when its sessions were $reason', async ({ reason, message }) => {
    const { rendered } = await mount()
    sign = body => {
      if (body.refresh === true) throw gone(reason)
      return server
    }

    await waitFor(() => { expect(rendered.result.current.error).toBe(message) }, { timeout: 3000 })

    expect(rendered.result.current.state).toBe('error')
    expect(signs.filter(body => body.refresh !== true)).toHaveLength(1)
    expect(vi.mocked(negotiateMediaDelivery)).toHaveBeenCalledTimes(1)
  })

  it('fails when the new session is gone before it answered a refresh, instead of starting again', async () => {
    const { rendered } = await mount()
    const second: Snapshot = { ...first, jobId: 'job-2', jobIds: ['job-2'], sessionId: 'session-2', renditionSet: 'job-2.1' }
    sign = body => {
      if (body.refresh === true) throw gone('expired')
      server = second
      return server
    }

    await waitFor(() => { expect(rendered.result.current.state).toBe('error') }, { timeout: 5000 })

    expect(rendered.result.current.error).toBe('Playback could not continue on a new session.')
    expect(signs.filter(body => body.refresh !== true)).toHaveLength(2)
  })

  it('does not loop when the new start is unavailable', async () => {
    const { rendered } = await mount()
    let starts = 0
    sign = body => {
      if (body.refresh === true) throw gone('expired')
      if (++starts > 0) throw unavailable()
      return server
    }

    await waitFor(() => { expect(rendered.result.current.state).toBe('error') }, { timeout: 4000 })
    const sent = signs.length
    await new Promise(resolve => setTimeout(resolve, 1200))

    expect(starts).toBe(1)
    expect(signs).toHaveLength(sent)
  })

  it('begins at zero with one seek when it is prepared to play at the source end', async () => {
    // The tab's transcode session still stands where the finished movie left it.
    server = { ...first, generation: 3, startTime: 204, position: 223.59, renditionSet: 'job-1.3' }
    const { execution } = await mount()

    await act(async () => { await execution.prepare(223.59, true, signal()) })

    expect(seeks()).toEqual([0])
    expect(execution.observe().position).toBe(0)
  })

  it('sends no seek when a fresh session is prepared to play at the source end', async () => {
    const { execution } = await mount()

    await act(async () => { await execution.prepare(223.59, true, signal()) })

    expect(seeks()).toEqual([])
  })

  it('settles a paused start at the source end without restarting it, and replays on Play', async () => {
    server = { ...first, generation: 3, startTime: 204, position: 223.59, status: 'paused', renditionSet: 'job-1.3' }
    // The published media ends just short of the source duration, so the native seek never completes.
    const { rendered, execution, state } = await mount({ seekable: 19.59, staysSeeking: true })
    // The test owns the clock the seek's grace is measured with, so it waits for no real second.
    const now = Date.now.bind(Date)
    let elapsed = 0
    const clock = vi.spyOn(Date, 'now').mockImplementation(() => now() + elapsed)
    try {
      const prepared = execution.prepare(223.59, false, signal())
      await waitFor(() => { expect(state.seeking).toBe(true) }, { timeout: 3000 })
      elapsed = 5000
      await act(async () => { await prepared })

      expect(state.seeking).toBe(true)
      expect(seeks()).toEqual([])
      expect(execution.observe().position).toBe(223.59)
      await waitFor(() => { expect(rendered.result.current.state).toBe('ended') }, { timeout: 3000 })

      state.seeking = false
      await act(async () => {
        execution.execute('play', undefined, signal()).catch(() => {})
        await waitFor(() => { expect(seeks()).toEqual([0]) }, { timeout: 3000 })
      })
      expect(controls.filter(control => control.action === 'resume')).toHaveLength(1)
    } finally {
      clock.mockRestore()
    }
  })

  it('lets a new seek supersede a restore that has not settled', async () => {
    // jsdom offers no seekable range, so the restore to 100 seconds stays pending.
    server = { ...first, generation: 2, startTime: 78, position: 100, renditionSet: 'job-1.2' }
    const { execution } = await mount()

    await act(async () => { await execution.prepare(0, true, signal()) })

    expect(seeks()).toEqual([0])
  })

  it('replays after completion with one seek to zero, one pause and at most one resume', async () => {
    const { rendered, video, state, execution } = await mount()
    await act(async () => { await execution.execute('play', undefined, signal()) })
    expect(rendered.result.current.state).toBe('playing')
    // The movie plays to its end: the element pauses there and reports that it ended.
    state.time = 223.59
    state.ended = true
    state.paused = true
    await act(async () => {
      video.dispatchEvent(new Event('pause'))
      video.dispatchEvent(new Event('ended'))
    })
    await waitFor(() => { expect(controls.filter(control => control.action === 'pause')).toHaveLength(1) })
    await waitFor(() => { expect(rendered.result.current.state).toBe('ended') })
    expect(seeks()).toEqual([])

    state.ended = false
    await act(async () => { await video.play() })

    await waitFor(() => { expect(seeks()).toEqual([0]) })
    await waitFor(() => { expect(attachments()).toBe(2) }, { timeout: 3000 })
    await waitFor(() => { expect(state.paused).toBe(false) })
    expect(controls.filter(control => control.action === 'pause')).toHaveLength(1)
    expect(controls.filter(control => control.action === 'resume').length).toBeLessThanOrEqual(1)
  })
})
