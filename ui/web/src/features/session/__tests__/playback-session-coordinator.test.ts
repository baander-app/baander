import { QueryClient } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import type { LiveTransportListener, SharedLiveTransport } from '@/shared/live/transport'
import { advanceAuthorization, synchronizeOwnership } from '@/shared/live/ownership'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { PlaybackLocalRecovery } from '../services/playback-local-recovery'
import { registerPlaybackAdapter, requestPlayback } from '../services/playback-execution'
import { PlaybackSessionCoordinator, getPlaybackClientRequestId, registerPlaybackCoordinator, PLAYBACK_CONNECTION_KEY, PLAYBACK_SESSION_KEY } from '../services/playback-session-coordinator'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@/shared/live/transport', () => ({ authenticatedTransport: {} }))
const endpoint = { destinationId: 'device', incarnationId: 'incarnation', epoch: 1, capability: 'private-capability' }
function snapshot(overrides: Partial<PlaybackSnapshotResource> = {}): PlaybackSnapshotResource {
  return { schemaVersion: 1, publicId: 'session', revision: 1, media: null, queue: [], currentTrackIndex: 0,
    position: 0, playbackState: 'stopped', desiredState: 'stopped', observedState: 'unknown',
    selectedDestinationId: null, activeDestinationId: null, activeIncarnationId: null,
    transfer: null, destinations: [{
      destinationId: endpoint.destinationId, incarnationId: endpoint.incarnationId, name: 'This browser',
      kind: 'browser', epoch: 1, available: true, capabilities: ['song', 'video'],
      observedState: null, position: null, observedVolume: null,
    }, ...(overrides.activeIncarnationId && overrides.activeIncarnationId !== endpoint.incarnationId ? [{
      destinationId: overrides.activeDestinationId ?? 'active-device', incarnationId: overrides.activeIncarnationId,
      name: 'Browser', kind: 'browser' as const, epoch: 1, available: true, capabilities: ['song' as const, 'video' as const],
      observedState: null, position: null, observedVolume: null,
    }] : [])], commands: [], ...overrides }
}
function harness(recovery?: PlaybackLocalRecovery) {
  let listener: LiveTransportListener = {}
  let connected = true
  const transport = { subscribe: vi.fn((value: LiveTransportListener) => { listener = value; return vi.fn() }), retain: vi.fn(() => vi.fn()), isConnected: () => connected, send: vi.fn() }
  const client = new QueryClient()
  const runtime = new PlaybackSessionCoordinator(client, transport as unknown as SharedLiveTransport, recovery)
  const stop = runtime.start()
  return { runtime, client, transport, stop,
    receive: (state: PlaybackSnapshotResource) => listener.onMessage?.({ type: 'session.snapshot', data: state }),
    close: () => { connected = false; listener.onClose?.() },
    open: () => { connected = true; listener.onOpen?.() },
  }
}
let cleanup: (() => void)[] = []
beforeEach(() => {
  synchronizeOwnership(crypto.randomUUID())
  vi.mocked(AXIOS_INSTANCE.post).mockReset().mockImplementation(async (_path, payload) => ({ data: { data: { ...snapshot({ activeIncarnationId: 'remote' }), ...(_path === '/api/session/control' ? { requestResult: { requestId: (payload as { requestId: string }).requestId, commandId: 'command', outcome: 'retained' } } : {}) } } }))
  vi.mocked(AXIOS_INSTANCE.get).mockReset().mockResolvedValue({ data: { data: snapshot() } })
})
afterEach(() => { cleanup.forEach(stop => stop()); cleanup = []; vi.useRealTimers() })

function controlledServer() {
  let state = snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId, activeDestinationId: endpoint.destinationId,
    media: { kind: 'song', publicId: 'first' }, queue: ['first'], desiredState: 'playing', observedState: 'playing' })
  const requests: { payload: Record<string, unknown>; commandId: string; outcome: string | null; rejectionReason?: string }[] = []
  vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, value) => {
    if (path !== '/api/session/control') return { data: { data: state } }
    const payload = value as Record<string, unknown>
    let entry = requests.find(request => request.payload.requestId === payload.requestId)
    if (!entry) {
      entry = { payload, commandId: `command-${requests.length}`, outcome: null }
      requests.push(entry)
      state = { ...state, revision: state.revision + 1,
        ...(Array.isArray(payload.queue) ? { queue: payload.queue as string[], currentTrackIndex: payload.currentTrackIndex as number } : {}),
        ...(payload.action === 'stop' ? { desiredState: 'stopped' as const } : {}),
      }
    }
    return { data: { data: { ...state, requestResult: { requestId: entry.payload.requestId, commandId: entry.commandId, outcome: entry.outcome, rejectionReason: entry.rejectionReason } } } }
  })
  const h = harness()
  cleanup.push(h.stop)
  h.receive(state)
  return { ...h, requests,
    publish: () => { state = { ...state, revision: state.revision + 1 }; h.receive(state) },
    settle: (index: number, outcome = 'applied', rejectionReason?: string) => {
      requests[index].outcome = outcome
      requests[index].rejectionReason = rejectionReason
      state = { ...state, revision: state.revision + 1 }
      h.receive(state)
    },
  }
}

describe('browser playback coordination', () => {
  it('shows the receiving browser compatibility reason for its exact control request', async () => {
    const h = controlledServer()
    const result = requestPlayback({ action: 'play' }).then(() => null, error => error as Error)
    await vi.waitFor(() => expect(h.requests).toHaveLength(1))
    h.settle(0, 'rejected', 'audio_codec_unsupported')
    expect((await result)?.message).toContain('audio codec')
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', expect.stringContaining('audio codec'))
  })

  it('reads the exact failed transfer reason even after another transfer becomes current', async () => {
    const h = harness()
    cleanup.push(h.stop)
    let state = snapshot({ endpoint, activeIncarnationId: 'source' })
    h.receive(state)
    let original: Record<string, unknown> | undefined
    let failed = false
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => {
      if (path !== '/api/session/transfer') return { data: { data: state } }
      if (!original) original = body as Record<string, unknown>
      else expect(body).toEqual(original)
      return { data: { data: { ...state, transferResult: {
        requestId: original.requestId, attemptId: 'our-attempt', state: failed ? 'failed' : 'preparing',
        rejectionReason: failed ? 'video_codec_unsupported' : null,
      } } } }
    })
    const result = h.runtime.transfer('target').then(() => null, error => error as Error)
    await vi.waitFor(() => expect(original).toBeDefined())
    await Promise.resolve()
    failed = true
    state = snapshot({ endpoint, revision: 3, activeIncarnationId: 'source', transfer: {
      attemptId: 'other-attempt', state: 'preparing', sourceIncarnationId: 'source', targetIncarnationId: 'other-target', position: 0, desiredState: 'playing',
    } })
    h.receive(state)
    expect((await result)?.message).toContain('video codec')
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toHaveProperty('activeIncarnationId', 'source')
  })

  it.each(['request', 'attempt'] as const)('rejects a transfer result with a different %s identity', async mismatch => {
    const h = harness()
    cleanup.push(h.stop)
    const state = snapshot({ endpoint, activeIncarnationId: 'source' })
    h.receive(state)
    let terminal = false
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (_path, body) => ({ data: { data: {
      ...state, revision: terminal ? 2 : 1,
      transferResult: {
        requestId: terminal && mismatch === 'request' ? 'another-request' : (body as { requestId: string }).requestId,
        attemptId: terminal && mismatch === 'attempt' ? 'another-attempt' : 'our-attempt',
        state: terminal ? 'committed' : 'preparing', rejectionReason: null,
      },
    } } }))
    const result = h.runtime.transfer('target').then(() => null, error => error as Error)
    await vi.waitFor(() => expect(AXIOS_INSTANCE.post).toHaveBeenCalled())
    await Promise.resolve()
    terminal = true
    h.receive({ ...state, revision: 2 })
    expect((await result)?.message).toContain('this playback transfer')
  })

  it('waits for the final recovery identity before exposing it, without waiting for registration', async () => {
    const recovery = new PlaybackLocalRecovery('https://baander.app', 'listener')
    let finish!: () => void
    vi.spyOn(recovery, 'initialize').mockImplementation(() => new Promise(resolve => {
      finish = () => { recovery.tabId = 'rotated-client'; resolve() }
    }))
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(() => new Promise(() => {}))
    const h = harness(recovery)
    cleanup.push(h.stop, registerPlaybackCoordinator(h.runtime))
    const controller = new AbortController()
    const received = vi.fn()
    const identity = getPlaybackClientRequestId(controller.signal).then(value => { received(value); return value })
    await vi.waitFor(() => expect(recovery.initialize).toHaveBeenCalledOnce())
    expect(received).not.toHaveBeenCalled()
    finish()
    await expect(identity).resolves.toBe('rotated-client')
    expect(h.transport.send).not.toHaveBeenCalled()
    await expect(getPlaybackClientRequestId(controller.signal)).resolves.toBe('rotated-client')
  })

  it('cancels identity waits and rejects disposed or replaced ownership', async () => {
    const recovery = new PlaybackLocalRecovery('https://baander.app', 'listener')
    vi.spyOn(recovery, 'initialize').mockImplementation(() => new Promise(() => {}))
    const h = harness(recovery)
    cleanup.push(h.stop, registerPlaybackCoordinator(h.runtime))
    const aborted = new AbortController()
    const waiting = expect(getPlaybackClientRequestId(aborted.signal)).rejects.toMatchObject({ name: 'AbortError' })
    aborted.abort()
    await waiting
    const stale = expect(getPlaybackClientRequestId(new AbortController().signal)).rejects.toThrow('Playback account or runtime changed.')
    advanceAuthorization()
    await stale
    await expect(getPlaybackClientRequestId(new AbortController().signal)).rejects.toThrow('Playback account or runtime changed.')
  })

  it('rechecks ownership after an identity wait resolves', async () => {
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async () => {
      advanceAuthorization()
      return { data: {} }
    })
    const h = harness()
    cleanup.push(h.stop, registerPlaybackCoordinator(h.runtime))
    const identity = getPlaybackClientRequestId(new AbortController().signal)
    await expect(identity).rejects.toThrow('Playback account or runtime changed.')
  })

  it('retains the receiving client identity through reconnect despite a foreign active destination', async () => {
    const h = harness()
    cleanup.push(h.stop, registerPlaybackCoordinator(h.runtime))
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalled())
    h.receive(snapshot({ endpoint, activeIncarnationId: 'remote', activeDestinationId: 'remote-device' }))
    const identity = await getPlaybackClientRequestId(new AbortController().signal)
    expect(identity).not.toBe('remote-device')
    h.close()
    const reconnected = getPlaybackClientRequestId(new AbortController().signal)
    h.open()
    await expect(reconnected).resolves.toBe(identity)
  })

  it('switches songs through the control response without a presence probe or relay', async () => {
    const execute = vi.fn(async () => ({ observedState: 'playing' as const, position: 0 }))
    cleanup.push(registerPlaybackAdapter({ kind: 'song', supportsVolume: true, prepare: vi.fn(), execute, quiesce: vi.fn(), observe: vi.fn() }))
    const h = harness()
    cleanup.push(h.stop)
    const initial = snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId, queue: ['first', 'second'],
      media: { kind: 'song', publicId: 'first' } })
    h.receive(initial)
    let delivered: PlaybackSnapshotResource
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => {
      const request = body as { requestId: string }
      if (path === '/api/session/control') {
        delivered = { ...initial, endpoint: undefined, revision: 2, currentTrackIndex: 1,
          commands: [{ id: 'switch', attemptId: 'attempt', revision: 2, incarnationId: endpoint.incarnationId, epoch: 1,
            type: 'load', payload: { media: { kind: 'song', publicId: 'second' }, queue: initial.queue,
              currentTrackIndex: 1, position: 0, desiredState: 'playing' }, expiresAt: new Date(Date.now() + 60_000).toISOString() }],
          requestResult: { requestId: request.requestId, commandId: 'switch', outcome: 'applied', rejectionReason: null } }
        return { data: { data: delivered } }
      }
      return { data: { data: snapshot({ revision: 2, activeIncarnationId: endpoint.incarnationId }) } }
    })

    await requestPlayback({ action: 'load', media: { kind: 'song', publicId: 'second' } })
    await vi.waitFor(() => expect(execute).toHaveBeenCalledTimes(1))
    const calls = vi.mocked(AXIOS_INSTANCE.post).mock.calls
    expect(calls.find(([path]) => path === '/api/session/control')).toEqual([
      '/api/session/control', expect.objectContaining({ incarnationId: endpoint.incarnationId, queue: ['first', 'second'], currentTrackIndex: 1 }),
      expect.objectContaining({ headers: { 'X-Baander-Playback-Endpoint': endpoint.capability } }),
    ])
    expect(calls.slice(0, calls.findIndex(([path]) => path === '/api/session/control')).some(([path]) => path === '/api/session/observe')).toBe(false)
    h.receive(delivered!)
    await Promise.resolve()
    expect(execute).toHaveBeenCalledTimes(1)
  })

  it.each(['stale snapshot', 'wrong incarnation', 'wrong epoch', 'wrong revision', 'expired'] as const)('does not execute a control response with %s', async scenario => {
    const execute = vi.fn()
    cleanup.push(registerPlaybackAdapter({ kind: 'song', supportsVolume: true, prepare: vi.fn(), execute, quiesce: vi.fn(), observe: vi.fn() }))
    const h = harness()
    cleanup.push(h.stop)
    h.receive(snapshot({ endpoint, revision: 3, activeIncarnationId: endpoint.incarnationId }))
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (_path, body) => ({ data: { data: snapshot({
      revision: scenario === 'stale snapshot' ? 2 : 3, activeIncarnationId: endpoint.incarnationId,
      commands: [{ id: 'invalid', attemptId: 'attempt', revision: scenario === 'wrong revision' ? 2 : 3,
        incarnationId: scenario === 'wrong incarnation' ? 'foreign' : endpoint.incarnationId,
        epoch: scenario === 'wrong epoch' ? 2 : 1, type: 'load',
        payload: { media: { kind: 'song', publicId: 'second' }, queue: ['second'], currentTrackIndex: 0, position: 0, desiredState: 'playing' },
        expiresAt: new Date(Date.now() + (scenario === 'expired' ? -1000 : 60_000)).toISOString() }],
      requestResult: { requestId: (body as { requestId: string }).requestId, commandId: 'invalid', outcome: 'retained', rejectionReason: null },
    }) } }))
    await requestPlayback({ action: 'load', media: { kind: 'song', publicId: 'second' } })
    expect(execute).not.toHaveBeenCalled()
  })

  it.each(['ordinary snapshot', 'failed probe'] as const)('checks stale cached availability after an %s', async scenario => {
    const now = Date.now()
    const date = vi.spyOn(Date, 'now').mockReturnValue(now)
    cleanup.push(() => date.mockRestore())
    const h = harness()
    cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledOnce())
    const state = snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId })
    h.receive(state)
    date.mockReturnValue(now + 10_001)
    h.receive({ ...state, endpoint: undefined, revision: 2 })
    let probes = 0
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => {
      if (path === '/api/session/observe') {
        probes++
        if (scenario === 'failed probe' && probes === 1) throw new Error('Presence failed')
      }
      return { data: { data: { ...state, endpoint: undefined, revision: 2,
        ...(path === '/api/session/control' ? { requestResult: { requestId: (body as { requestId: string }).requestId, commandId: 'load', outcome: 'retained' } } : {}),
      } } }
    })
    if (scenario === 'failed probe') await expect(requestPlayback({ action: 'play' })).rejects.toThrow('Presence failed')
    await requestPlayback({ action: 'play' })
    expect(probes).toBe(scenario === 'failed probe' ? 2 : 1)
  })

  it('collapses seeks committed while an earlier intent executes into the latest position', async () => {
    const h = controlledServer()
    const first = requestPlayback({ action: 'seek', position: 22 })
    await vi.waitFor(() => expect(h.requests).toHaveLength(1))
    const second = requestPlayback({ action: 'seek', position: 26 })
    const third = requestPlayback({ action: 'seek', position: 30 })
    h.settle(0)
    h.publish()
    await first
    await vi.waitFor(() => expect(h.requests).toHaveLength(2))
    expect(h.requests[1].payload).toMatchObject({ action: 'seek', position: 30 })
    h.settle(1)
    h.publish()
    await Promise.all([second, third])
    expect(h.requests).toHaveLength(2)
  })

  it('keeps a seek behind a later non-seek intent instead of collapsing across it', async () => {
    const h = controlledServer()
    const first = requestPlayback({ action: 'seek', position: 22 })
    await vi.waitFor(() => expect(h.requests).toHaveLength(1))
    const second = requestPlayback({ action: 'seek', position: 26 })
    const pause = requestPlayback({ action: 'pause' })
    const third = requestPlayback({ action: 'seek', position: 30 })
    for (let index = 0; index < 4; index++) {
      await vi.waitFor(() => expect(h.requests).toHaveLength(index + 1))
      h.settle(index)
      h.publish()
    }
    await Promise.all([first, second, pause, third])
    expect(h.requests.map(request => [request.payload.action, request.payload.position])).toEqual([
      ['seek', 22], ['seek', 26], ['pause', undefined], ['seek', 30],
    ])
  })

  it('retries the same logical request with the replacement endpoint delivery identity', async () => {
    const h = controlledServer()
    const pending = requestPlayback({ action: 'pause' })
    await vi.waitFor(() => expect(h.requests).toHaveLength(1))
    h.close()
    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    const replacement = { ...endpoint, incarnationId: 'replacement', epoch: 2, capability: 'new-capability' }
    h.receive(snapshot({ endpoint: replacement, revision: 3, activeIncarnationId: endpoint.incarnationId }))
    await vi.waitFor(() => {
      const controls = vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/control')
      expect(controls).toHaveLength(2)
      const originalPayload = controls[0][1] as Record<string, unknown>
      expect(controls[1][1]).toEqual({ ...originalPayload, incarnationId: replacement.incarnationId })
      expect(controls[1][2]).toMatchObject({ headers: { 'X-Baander-Playback-Endpoint': replacement.capability } })
    })
    h.settle(0)
    h.publish()
    await pending
  })

  it('measures confirmed presence from the observation request start', async () => {
    const now = Date.now()
    const date = vi.spyOn(Date, 'now').mockReturnValue(now)
    cleanup.push(() => date.mockRestore())
    const h = harness()
    cleanup.push(h.stop)
    const state = snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId })
    h.receive(state)
    date.mockReturnValue(now + 10_001)
    let release!: () => void
    let probes = 0
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => {
      if (path === '/api/session/observe') {
        probes++
        if (probes === 1) await new Promise<void>(resolve => { release = resolve })
      }
      return { data: { data: { ...state, endpoint: undefined,
        ...(path === '/api/session/control' ? { requestResult: { requestId: (body as { requestId: string }).requestId, commandId: 'play', outcome: 'retained' } } : {}),
      } } }
    })
    const first = requestPlayback({ action: 'play' })
    await vi.waitFor(() => expect(release).toBeDefined())
    date.mockReturnValue(now + 20_002)
    release()
    await first
    await requestPlayback({ action: 'play' })
    expect(probes).toBe(2)
  })

  it('still executes stop commands after recovery checkpoint storage fails', async () => {
    const recovery = new PlaybackLocalRecovery('https://baander.app', 'listener')
    const spies = [
      vi.spyOn(recovery, 'initialize').mockResolvedValue(),
      vi.spyOn(recovery, 'isEligible').mockReturnValue(true),
      vi.spyOn(recovery, 'registered').mockResolvedValue(),
      vi.spyOn(recovery, 'checkpoint').mockRejectedValue(new Error('Storage unavailable')),
    ]
    cleanup.push(() => spies.forEach(spy => spy.mockRestore()))
    const execute = vi.fn(async () => ({ observedState: 'stopped' as const, position: 0 }))
    cleanup.push(registerPlaybackAdapter({ kind: 'song', supportsVolume: true, prepare: vi.fn(), execute,
      quiesce: vi.fn(), observe: vi.fn(), localRecovery: { eligible: true,
        fence: () => ({ observedState: 'stopped', position: 0 }), rearmAfterRegistration: vi.fn() } }))
    const h = harness(recovery)
    cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledOnce())
    const command = { id: 'load', attemptId: 'attempt', revision: 1, incarnationId: endpoint.incarnationId, epoch: 1,
      type: 'load' as const, payload: { media: { kind: 'song' as const, publicId: 'song' }, queue: ['song'],
        currentTrackIndex: 0, position: 0, desiredState: 'playing' as const }, expiresAt: new Date(Date.now() + 60_000).toISOString() }
    h.receive(snapshot({ endpoint, commands: [command] }))
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', 'Storage unavailable'))
    h.receive(snapshot({ revision: 2, commands: [{ ...command, id: 'stop', revision: 2, type: 'stop' }] }))
    await vi.waitFor(() => expect(execute).toHaveBeenCalledOnce())
    expect(execute).toHaveBeenCalledWith(expect.objectContaining({ type: 'stop' }), expect.anything())
  })

  it('does not retarget a queued local video close after playback moves to another browser', async () => {
    const h = harness()
    cleanup.push(h.stop)
    let current = snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId, media: { kind: 'video', publicId: 'movie' } })
    h.receive(current)
    let completePause!: () => void
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => {
      if (path !== '/api/session/control') return { data: { data: current } }
      const request = body as { action: string; requestId: string }
      if (request.action === 'pause') await new Promise<void>(resolve => { completePause = resolve })
      return { data: { data: { ...current, requestResult: { requestId: request.requestId, commandId: request.action, outcome: 'paused' } } } }
    })
    const pause = requestPlayback({ action: 'pause' })
    const close = requestPlayback({ action: 'stop-local-video', incarnationId: endpoint.incarnationId, videoId: 'movie' })
    await vi.waitFor(() => expect(completePause).toBeDefined())
    current = snapshot({ revision: 2, activeIncarnationId: 'remote', media: current.media })
    h.receive(current)
    completePause()
    await Promise.all([pause, close])
    const controls = vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/control')
    expect(controls.map(([, body]) => (body as { action: string }).action)).toEqual(['pause'])
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toHaveProperty('activeIncarnationId', 'remote')
  })

  it.each(['owned video', 'different video', 'transfer pending'] as const)('limits a local video close to its %s scope', async scenario => {
    const h = harness()
    cleanup.push(h.stop)
    const mark = vi.spyOn(performance, 'mark')
    cleanup.push(() => mark.mockRestore())
    const current = snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId,
      media: { kind: 'video', publicId: scenario === 'different video' ? 'another-movie' : 'movie' },
      ...(scenario === 'transfer pending' ? { transfer: { attemptId: 'transfer', state: 'preparing' as const,
        sourceIncarnationId: endpoint.incarnationId, targetIncarnationId: 'remote', position: 12, desiredState: 'paused' as const } } : {}) })
    h.receive(current)
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => ({ data: { data: {
      ...current,
      ...(path === '/api/session/control' ? { requestResult: {
        requestId: (body as { requestId: string }).requestId, commandId: 'stop', outcome: 'stopped',
      } } : {}),
    } } }))
    await requestPlayback({ action: 'stop-local-video', incarnationId: endpoint.incarnationId, videoId: 'movie' })
    const controls = vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/control')
    expect(controls.map(([, body]) => (body as { action: string }).action)).toEqual(scenario === 'owned video' ? ['stop'] : [])
    expect(mark.mock.calls.some(([name]) => name === 'baander:intent-refused')).toBe(scenario !== 'owned video')
  })

  it('closes a local video during an uncertain transfer by silencing it without a session control', async () => {
    const quiesce = vi.fn(async () => ({ observedState: 'paused' as const, position: 12 }))
    const execute = vi.fn(async () => ({ observedState: 'playing' as const, position: 12 }))
    cleanup.push(registerPlaybackAdapter({ kind: 'video', supportsVolume: true, prepare: vi.fn(), execute, quiesce,
      observe: vi.fn(() => ({ observedState: 'playing' as const, position: 12 })) }))
    const h = harness()
    cleanup.push(h.stop)
    const mark = vi.spyOn(performance, 'mark')
    cleanup.push(() => mark.mockRestore())
    const media = { kind: 'video' as const, publicId: 'movie' }
    const play = { id: 'play', attemptId: 'attempt', revision: 1, incarnationId: endpoint.incarnationId, epoch: 1,
      type: 'play' as const, payload: { media, queue: [], currentTrackIndex: 0, position: 12, desiredState: 'playing' as const },
      expiresAt: new Date(Date.now() + 60_000).toISOString() }
    h.receive(snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId, media, commands: [play] }))
    await vi.waitFor(() => expect(execute).toHaveBeenCalledOnce())
    const current = snapshot({ revision: 2, activeIncarnationId: endpoint.incarnationId, media,
      transfer: { attemptId: 'transfer', state: 'uncertain' as const, sourceIncarnationId: endpoint.incarnationId,
        targetIncarnationId: 'remote', position: 12, desiredState: 'paused' as const } })
    h.receive(current)
    vi.mocked(AXIOS_INSTANCE.post).mockClear().mockImplementation(async () => ({ data: { data: current } }))

    await requestPlayback({ action: 'stop-local-video', incarnationId: endpoint.incarnationId, videoId: 'movie' })

    expect(quiesce).toHaveBeenCalledOnce()
    expect(execute).toHaveBeenCalledOnce()
    expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.some(([path]) => path === '/api/session/control' || path === '/api/session/ack')).toBe(false)
    expect(mark.mock.calls.some(([name, options]) => name === 'baander:intent-refused'
      && (options as { detail?: { reason?: string } } | undefined)?.detail?.reason === 'destination uncertain')).toBe(true)
    expect(mark.mock.calls.some(([name]) => name === 'baander:intent-error')).toBe(false)
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toHaveProperty('transfer.state', 'uncertain')
  })

  it('still closes a local video during an uncertain transfer when silencing it fails, and reports why', async () => {
    const quiesce = vi.fn(async () => { throw new Error('The video element is gone.') })
    const execute = vi.fn(async () => ({ observedState: 'playing' as const, position: 12 }))
    cleanup.push(registerPlaybackAdapter({ kind: 'video', supportsVolume: true, prepare: vi.fn(), execute, quiesce,
      observe: vi.fn(() => ({ observedState: 'playing' as const, position: 12 })) }))
    const h = harness()
    cleanup.push(h.stop)
    const media = { kind: 'video' as const, publicId: 'movie' }
    const play = { id: 'play', attemptId: 'attempt', revision: 1, incarnationId: endpoint.incarnationId, epoch: 1,
      type: 'play' as const, payload: { media, queue: [], currentTrackIndex: 0, position: 12, desiredState: 'playing' as const },
      expiresAt: new Date(Date.now() + 60_000).toISOString() }
    h.receive(snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId, media, commands: [play] }))
    await vi.waitFor(() => expect(execute).toHaveBeenCalledOnce())
    h.receive(snapshot({ revision: 2, activeIncarnationId: endpoint.incarnationId, media,
      transfer: { attemptId: 'transfer', state: 'uncertain' as const, sourceIncarnationId: endpoint.incarnationId,
        targetIncarnationId: 'remote', position: 12, desiredState: 'paused' as const } }))

    await requestPlayback({ action: 'stop-local-video', incarnationId: endpoint.incarnationId, videoId: 'movie' })

    expect(quiesce).toHaveBeenCalledOnce()
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', 'The video element is gone.')
  })

  it.each(['failed', 'still starting', 'committed'] as const)('handles its own preparation once the transfer has %s', async outcome => {
    const prepare = vi.fn(async () => {})
    const quiesce = vi.fn(async () => ({ observedState: 'paused' as const, position: 12 }))
    const execute = vi.fn(async (issued: { type: string }) => ({ observedState: issued.type === 'stop' ? 'stopped' as const : 'paused' as const, position: 12 }))
    cleanup.push(registerPlaybackAdapter({ kind: 'video', supportsVolume: true, prepare, execute, quiesce,
      observe: vi.fn(() => ({ observedState: 'paused' as const, position: 12 })) }))
    const h = harness()
    cleanup.push(h.stop)
    const media = { kind: 'video' as const, publicId: 'movie' }
    const transfer = { attemptId: 'transfer', state: 'preparing' as const, sourceIncarnationId: 'source',
      targetIncarnationId: endpoint.incarnationId, position: 12, desiredState: 'playing' as const }
    const command = { id: 'prepare', attemptId: 'transfer', revision: 1, incarnationId: endpoint.incarnationId, epoch: 1,
      type: 'prepare' as const, payload: { media, queue: [], currentTrackIndex: 0, position: 12, desiredState: 'playing' as const },
      expiresAt: new Date(Date.now() + 60_000).toISOString() }
    let current = snapshot({ activeIncarnationId: 'source', media, transfer })
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async () => ({ data: { data: current } }))
    h.receive({ ...current, endpoint, commands: [command] })
    await vi.waitFor(() => expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.some(([path]) => path === '/api/session/ack')).toBe(true))
    expect(prepare).toHaveBeenCalledOnce()
    expect(quiesce).not.toHaveBeenCalled()

    current = outcome === 'failed' ? snapshot({ revision: 2, activeIncarnationId: 'source', media })
      : outcome === 'committed' ? snapshot({ revision: 2, activeIncarnationId: endpoint.incarnationId, media })
        : snapshot({ revision: 2, activeIncarnationId: 'source', media, transfer: { ...transfer, state: 'starting' } })
    h.receive(current)
    await Promise.resolve()
    await Promise.resolve()

    // A failed or expired transfer sends its target nothing more: the tab lets its prepared player go.
    expect(quiesce).toHaveBeenCalledTimes(outcome === 'failed' ? 1 : 0)
    expect(execute.mock.calls.filter(([issued]) => issued.type === 'stop')).toHaveLength(outcome === 'failed' ? 1 : 0)
    expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/ack')).toHaveLength(1)
  })

  it('surfaces the server refusal reason when a transfer blocks a control', async () => {
    const h = harness()
    cleanup.push(h.stop)
    const current = snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId, media: { kind: 'video', publicId: 'movie' },
      transfer: { attemptId: 'transfer', state: 'uncertain' as const, sourceIncarnationId: endpoint.incarnationId,
        targetIncarnationId: 'remote', position: 12, desiredState: 'paused' as const } })
    h.receive(current)
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async path => {
      if (path === '/api/session/control') {
        throw Object.assign(new Error('Request failed with status code 409'), {
          response: { status: 409, data: { error: { message: 'Playback controls wait for transfer reconciliation.' } } },
        })
      }
      return { data: { data: current } }
    })

    const play = requestPlayback({ action: 'play' }).then(() => null, error => error as Error)

    expect(await play).toBeInstanceOf(Error)
    expect((await play)?.message).toBe('Playback controls wait for transfer reconciliation.')
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', 'Playback controls wait for transfer reconciliation.')
  })

  it.each(['load', 'play'] as const)('renews the owned endpoint before local %s after its lease expires', async action => {
    const h = harness()
    cleanup.push(h.stop)
    const expired = snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId, media: { kind: 'song', publicId: 'song' },
      destinations: [{ destinationId: endpoint.destinationId, incarnationId: endpoint.incarnationId, name: 'Browser', kind: 'browser',
        epoch: 1, available: false, capabilities: ['song'], observedState: 'paused', position: 12, observedVolume: null }] })
    h.receive(expired)
    const renewed = { ...expired, destinations: expired.destinations.map(destination => ({ ...destination, available: true })) }
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => ({ data: { data: {
      ...renewed,
      ...(path === '/api/session/control' ? { requestResult: {
        requestId: (body as { requestId: string }).requestId, commandId: action, outcome: 'retained',
      } } : {}),
    } } }))

    await requestPlayback(action === 'load' ? { action, media: { kind: 'song', publicId: 'song' } } : { action })

    const calls = vi.mocked(AXIOS_INSTANCE.post).mock.calls
    const observation = calls.findIndex(([path]) => path === '/api/session/observe')
    const control = calls.findIndex(([path]) => path === '/api/session/control')
    expect(observation).toBeGreaterThanOrEqual(0)
    expect(control).toBeGreaterThan(observation)
    expect(calls[observation][2]).toMatchObject({ headers: { 'X-Baander-Playback-Endpoint': endpoint.capability } })
  })

  it.each(['play', 'return'] as const)('registers a fresh local endpoint before %s when its former incarnation was retired', async action => {
    const fence = vi.fn(() => ({ observedState: 'stopped' as const, position: 12 }))
    cleanup.push(registerPlaybackAdapter({ kind: 'song', supportsVolume: true, prepare: vi.fn(), execute: vi.fn(),
      quiesce: vi.fn(), observe: vi.fn(), localRecovery: { eligible: true, fence, rearmAfterRegistration: vi.fn() } }))
    const h = harness()
    cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(1))
    const retired = snapshot({ endpoint, media: { kind: 'song', publicId: 'song' }, queue: ['song'], position: 12,
      activeIncarnationId: action === 'return' ? 'remote' : null,
      destinations: [{ destinationId: endpoint.destinationId, incarnationId: endpoint.incarnationId, name: 'Browser', kind: 'browser',
        epoch: 1, available: false, capabilities: ['song'], observedState: 'paused', position: 12, observedVolume: null },
      ...(action === 'return' ? [{ destinationId: 'remote-device', incarnationId: 'remote', name: 'Remote', kind: 'browser' as const,
        epoch: 1, available: true, capabilities: ['song' as const], observedState: 'paused' as const, position: 12, observedVolume: null }] : [])] })
    h.receive(retired)
    const replacement = { ...endpoint, incarnationId: 'replacement', epoch: 2, capability: 'replacement-capability' }
    let current = retired
    h.transport.send.mockImplementation(() => {
      expect(fence).toHaveBeenCalled()
      current = { ...retired, endpoint: replacement,
        destinations: retired.destinations.map(destination => destination.incarnationId === endpoint.incarnationId
          ? { ...destination, incarnationId: replacement.incarnationId, epoch: replacement.epoch, available: true } : destination) }
      h.receive(current)
    })
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => ({ data: { data: {
      ...current,
      ...(path === '/api/session/transfer' ? { activeIncarnationId: replacement.incarnationId,
        transferResult: { requestId: (body as { requestId: string }).requestId, attemptId: 'attempt', state: 'committed', rejectionReason: null } } : {}),
      ...(path === '/api/session/control' ? { requestResult: {
        requestId: (body as { requestId: string }).requestId, commandId: 'play', outcome: 'retained',
      } } : {}),
    } } }))

    await (action === 'return' ? h.runtime.transfer(endpoint.incarnationId) : requestPlayback({ action: 'play' }))

    expect(h.transport.send).toHaveBeenCalledTimes(2)
    const transfer = vi.mocked(AXIOS_INSTANCE.post).mock.calls.find(([path]) => path === '/api/session/transfer')
    expect(transfer?.[1]).toMatchObject({ targetIncarnationId: replacement.incarnationId })
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('endpoint.incarnationId', replacement.incarnationId)
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toMatchObject({ queue: ['song'], position: 12 })
  })

  it.each(['dispose', 'account'] as const)('cancels local registration readiness when ownership ends through %s', async cause => {
    const h = harness()
    cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(1))
    const retired = snapshot({ endpoint, destinations: [], media: { kind: 'song', publicId: 'song' } })
    h.receive(retired)
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async () => ({ data: { data: retired } }))
    const pending = requestPlayback({ action: 'play' }).then(() => null, error => error as Error)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    if (cause === 'dispose') h.stop()
    else synchronizeOwnership('another-account')
    expect(await pending).toBeInstanceOf(Error)
    expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.some(([path]) => path === '/api/session/control')).toBe(false)
  })

  it('bounds the wait for a replacement registration without sending playback controls', async () => {
    vi.useFakeTimers()
    const h = harness()
    cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(1))
    const retired = snapshot({ endpoint, destinations: [], media: { kind: 'song', publicId: 'song' } })
    h.receive(retired)
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async () => ({ data: { data: retired } }))
    const pending = requestPlayback({ action: 'play' }).then(() => null, error => error as Error)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    await vi.advanceTimersByTimeAsync(15_000)
    expect((await pending)?.message).toContain('timed out')
    expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.some(([path]) => path === '/api/session/control')).toBe(false)
    await h.runtime.retry()
    expect(h.transport.send).toHaveBeenCalledTimes(3)
  })

  it('does not renew or take over an unconfirmed foreign runner when returning locally', async () => {
    const h = harness()
    cleanup.push(h.stop)
    h.receive(snapshot({ endpoint, activeIncarnationId: 'foreign', destinations: [] }))
    await expect(h.runtime.transfer(endpoint.incarnationId)).rejects.toThrow('Previous playback is unconfirmed.')
    expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.some(([path]) => path === '/api/session/observe' || path === '/api/session/transfer')).toBe(false)
  })

  it.each(['load', 'play'] as const)('explains why %s cannot resume unavailable playback without sending a conflicting request', async action => {
    const h = harness()
    cleanup.push(h.stop)
    const retained = snapshot({ endpoint, media: { kind: 'song', publicId: 'retained-song' }, queue: ['retained-song'],
      position: 77, desiredState: 'paused', observedState: 'paused', activeIncarnationId: 'legacy-tab',
      destinations: [{ destinationId: 'legacy', incarnationId: 'legacy-tab', name: 'Chrome on Linux', kind: 'browser',
        epoch: 1, available: false, capabilities: ['song'], observedState: 'paused', position: 77, observedVolume: null }] })
    h.receive(retained)

    await expect(requestPlayback(action === 'load' ? { action, media: { kind: 'song', publicId: 'another-song' } } : { action }))
      .rejects.toThrow('Stop playback in its original browser or recover that browser before resuming here.')

    expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.some(([path]) => path === '/api/session/control')).toBe(false)
    expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.some(([path]) => path === '/api/session/observe')).toBe(false)
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toMatchObject({
      activeIncarnationId: 'legacy-tab', media: retained.media, queue: retained.queue, position: 77,
    })
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', expect.stringContaining('unconfirmed'))
  })

  it('clears the unconfirmed-playback refusal once the session has no unconfirmed playback, and nothing else', async () => {
    const h = harness()
    cleanup.push(h.stop)
    const legacy = { destinationId: 'legacy', incarnationId: 'legacy-tab', name: 'Chrome on Linux', kind: 'browser' as const,
      epoch: 1, available: false, capabilities: ['song' as const], observedState: 'stopped' as const, position: 77, observedVolume: null }
    const held = snapshot({ desiredState: 'stopped', observedState: 'stopped', activeIncarnationId: 'legacy-tab', destinations: [legacy] })
    h.receive({ ...held, endpoint })
    await expect(requestPlayback({ action: 'play' })).rejects.toThrow('Previous playback is unconfirmed.')
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', expect.stringContaining('unconfirmed'))

    // The runner is still held: the refusal still describes the session.
    h.receive({ ...held, revision: 2 })
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', expect.stringContaining('unconfirmed'))

    // The server released the runner.
    h.receive({ ...held, revision: 3, activeIncarnationId: null })
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ error: null, connected: true })

    // Another error is not the session's to clear.
    h.receive({ ...held, revision: 4 })
    await expect(requestPlayback({ action: 'play' })).rejects.toThrow('Previous playback is unconfirmed.')
    await expect(requestPlayback({ action: 'pause' })).resolves.toBeUndefined()
    vi.mocked(AXIOS_INSTANCE.post).mockRejectedValueOnce(Object.assign(new Error('Request failed with status code 409'), {
      response: { status: 409, data: { error: { message: 'A runner control is awaiting acknowledgement.' } } },
    }))
    await expect(requestPlayback({ action: 'pause' })).rejects.toThrow('A runner control is awaiting acknowledgement.')
    h.receive({ ...held, revision: 5, activeIncarnationId: null })
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', 'A runner control is awaiting acknowledgement.')
  })

  it('keeps stop available when the active runner is absent from the destination list', async () => {
    const h = harness()
    cleanup.push(h.stop)
    const retained = snapshot({ endpoint, activeIncarnationId: 'missing-runner', destinations: [],
      media: { kind: 'song', publicId: 'retained-song' }, queue: ['retained-song'], position: 77 })
    h.receive(retained)
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => ({ data: { data: {
      ...retained,
      ...(path === '/api/session/control' ? { requestResult: {
        requestId: (body as { requestId: string }).requestId, commandId: 'stop', outcome: 'retained',
      } } : {}),
    } } }))

    await expect(requestPlayback({ action: 'play' })).rejects.toThrow('Previous playback is unconfirmed.')
    await requestPlayback({ action: 'stop' })

    const controls = vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/control')
    expect(controls).toHaveLength(1)
    expect(controls[0][1]).toMatchObject({ action: 'stop' })
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toMatchObject({
      activeIncarnationId: 'missing-runner', queue: retained.queue, position: 77,
    })
  })

  it('waits for an exact command outcome and serializes rapid queue additions', async () => {
    const h = controlledServer()
    const first = requestPlayback({ action: 'queue', operation: { type: 'append', tracks: ['second'] } })
    const second = requestPlayback({ action: 'queue', operation: { type: 'append', tracks: ['third'] } })
    let completed = false
    first.then(() => { completed = true })
    await vi.waitFor(() => expect(h.requests).toHaveLength(1))
    expect(h.requests[0].payload.queue).toEqual(['first', 'second'])
    h.publish()
    await vi.waitFor(() => expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/control')).toHaveLength(2))
    expect(completed).toBe(false)
    const posts = vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/control')
    expect(posts[0][1]).toEqual(posts[1][1])
    h.settle(0)
    await first
    await vi.waitFor(() => expect(h.requests).toHaveLength(2))
    expect(h.requests[1].payload.queue).toEqual(['first', 'second', 'third'])
    h.settle(1)
    await second
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).not.toHaveProperty('requestResult')
  })

  it('rejects an unconfirmed intent when its result read fails instead of hanging the queue', async () => {
    const h = controlledServer()
    const pending = requestPlayback({ action: 'play' }).then(() => null, error => error as Error)
    await vi.waitFor(() => expect(h.requests).toHaveLength(1))
    vi.mocked(AXIOS_INSTANCE.post).mockRejectedValueOnce(new Error('Connection interrupted'))
    h.publish()
    expect(await pending).toBeInstanceOf(Error)
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', 'Connection interrupted')
    const next = requestPlayback({ action: 'pause' }).then(() => null, error => error as Error)
    expect((await next)?.message).toContain('unconfirmed')
    expect(h.requests).toHaveLength(1)
    h.settle(0, 'playing')
    await h.runtime.retry()
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toHaveProperty('revision', 4))
  })

  it('waits for stopped acknowledgement before clearing the queue', async () => {
    const h = controlledServer()
    const clearing = requestPlayback({ action: 'queue', operation: { type: 'clear' } })
    const appended = requestPlayback({ action: 'queue', operation: { type: 'append', tracks: ['after-clear'] } })
    await vi.waitFor(() => expect(h.requests).toHaveLength(1))
    expect(h.requests[0].payload.action).toBe('stop')
    h.publish()
    await Promise.resolve()
    expect(h.requests).toHaveLength(1)
    h.settle(0, 'stopped')
    await vi.waitFor(() => expect(h.requests).toHaveLength(2))
    expect(h.requests[1].payload).toMatchObject({ action: 'queue', queue: [], currentTrackIndex: 0 })
    h.settle(1)
    await clearing
    await vi.waitFor(() => expect(h.requests).toHaveLength(3))
    expect(h.requests[2].payload.queue).toEqual(['after-clear'])
    h.settle(2)
    await appended
  })

  it('preserves a queued append when a later song selection omits an explicit queue', async () => {
    const h = controlledServer()
    const append = requestPlayback({ action: 'queue', operation: { type: 'append', tracks: ['second'] } })
    const selection = requestPlayback({ action: 'load', media: { kind: 'song', publicId: 'third' } })
    await vi.waitFor(() => expect(h.requests).toHaveLength(1))
    h.settle(0)
    await append
    await vi.waitFor(() => expect(h.requests).toHaveLength(2))
    expect(h.requests[1].payload).toMatchObject({ queue: ['first', 'second', 'third'], currentTrackIndex: 2 })
    h.settle(1)
    await selection
  })

  it.each(['rejected', 'superseded'])('rejects a settled %s request without inferring success from playback state', async outcome => {
    const h = controlledServer()
    const result = requestPlayback({ action: 'play' }).then(() => 'unexpected success', error => error as Error)
    await vi.waitFor(() => expect(h.requests).toHaveLength(1))
    h.settle(0, outcome)
    expect(await result).toBeInstanceOf(Error)
  })

  it('tells the viewer that a request its destination never answered changed nothing there', async () => {
    const h = controlledServer()
    const result = requestPlayback({ action: 'play' }).then(() => 'unexpected success', error => error as Error)
    await vi.waitFor(() => expect(h.requests).toHaveLength(1))
    h.settle(0, 'never_effective')
    expect(await result).toBeInstanceOf(Error)
    expect((await result as Error).message).toBe('The destination did not answer the playback request. Nothing changed there; try again.')
  })

  it.each(['committed', 'uncertain'] as const)('settles a transfer only from its acknowledged %s snapshot', async outcome => {
    const h = harness(); cleanup.push(h.stop)
    const state = snapshot({ endpoint, activeIncarnationId: 'source' })
    h.receive(state)
    const transfer = { attemptId: 'attempt', state: 'preparing' as const, sourceIncarnationId: 'source', targetIncarnationId: 'target', position: 12, desiredState: 'playing' as const }
    let response = { ...state, revision: 2, transfer: transfer as PlaybackSnapshotResource['transfer'] }
    let phase: string = 'preparing'
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (_path, body) => ({ data: { data: {
      ...response, transferResult: { requestId: (body as { requestId: string }).requestId,
        attemptId: 'attempt', state: phase, rejectionReason: null },
    } } }))
    let completed = false
    const pending = h.runtime.transfer('target').then(() => { completed = true; return null }, error => error as Error)
    await vi.waitFor(() => expect(AXIOS_INSTANCE.post).toHaveBeenCalledWith('/api/session/transfer', expect.anything(), expect.objectContaining({ signal: expect.any(AbortSignal) })))
    await Promise.resolve()
    expect(completed).toBe(false)
    phase = outcome
    response = outcome === 'committed'
      ? { ...state, revision: 3, transfer: null, activeIncarnationId: 'target' }
      : { ...state, revision: 3, transfer: { ...transfer, state: 'uncertain' } }
    h.receive(response)
    if (outcome === 'committed') expect(await pending).toBeNull()
    else expect(await pending).toBeInstanceOf(Error)
  })

  it('bounds a control request and reconciles its exact identity after timeout', async () => {
    vi.useFakeTimers()
    const h = harness(); cleanup.push(h.stop)
    h.receive(snapshot({ endpoint, activeIncarnationId: endpoint.incarnationId }))
    let sent: Record<string, unknown> | undefined
    let signal: AbortSignal | undefined
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body, config) => {
      if (path === '/api/session/control') {
        sent = body as Record<string, unknown>
        signal = config?.signal as AbortSignal
        return new Promise(() => {})
      }
      return { data: { data: snapshot() } }
    })
    const pending = requestPlayback({ action: 'play' }).then(() => null, error => error as Error)
    await vi.waitFor(() => expect(sent).toBeDefined())
    await vi.advanceTimersByTimeAsync(15_000)
    expect((await pending)?.message).toContain('timed out')
    expect(signal?.aborted).toBe(true)
    const original = sent
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => {
      if (path === '/api/session/control') expect(body).toEqual(original)
      return { data: { data: { ...snapshot({ revision: 3 }), requestResult: { requestId: original?.requestId, commandId: 'original-command', outcome: 'playing' } } } }
    })
    h.receive(snapshot({ revision: 3 }))
    await vi.waitFor(() => expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/control')).toHaveLength(2))
  })

  it.each(['account replacement', 'connection loss'] as const)('aborts unresolved control HTTP on %s and ignores its late result', async reason => {
    const h = harness(); cleanup.push(h.stop)
    h.receive(snapshot({ endpoint }))
    let signal: AbortSignal | undefined
    let finish: ((response: { data: { data: PlaybackSnapshotResource } }) => void) | undefined
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, _body, config) => {
      if (path !== '/api/session/control') return { data: { data: snapshot() } }
      signal = config?.signal as AbortSignal
      return new Promise(resolve => { finish = resolve })
    })
    const pending = requestPlayback({ action: 'play' }).then(() => null, error => error as Error)
    await vi.waitFor(() => expect(finish).toBeDefined())
    if (reason === 'account replacement') synchronizeOwnership('replacement-login')
    else h.close()
    expect(signal?.aborted).toBe(true)
    expect(await pending).toBeInstanceOf(Error)
    finish?.({ data: { data: snapshot({ revision: 99, position: 99 }) } })
    await Promise.resolve()
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toHaveProperty('revision', 1)
  })

  it('retries a timed-out acknowledgement without replaying native execution', async () => {
    vi.useFakeTimers()
    const execute = vi.fn(async () => ({ observedState: 'playing' as const, position: 20 }))
    cleanup.push(registerPlaybackAdapter({ kind: 'song', supportsVolume: true, prepare: vi.fn(async () => {}), execute,
      quiesce: vi.fn(async () => ({ observedState: 'stopped' as const, position: 20 })), observe: () => ({ observedState: 'playing', position: 20 }) }))
    const h = harness(); cleanup.push(h.stop)
    let signal: AbortSignal | undefined
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, _body, config) => {
      if (path === '/api/session/ack') { signal = config?.signal as AbortSignal; return new Promise(() => {}) }
      return { data: { data: snapshot() } }
    })
    const state = snapshot({ endpoint, commands: [{ id: 'playing', attemptId: 'attempt', revision: 1,
      incarnationId: endpoint.incarnationId, epoch: 1, type: 'start',
      payload: { media: { kind: 'song', publicId: 'song' }, queue: ['song'], currentTrackIndex: 0, position: 20, desiredState: 'playing' },
      expiresAt: new Date(Date.now() + 60_000).toISOString() }] })
    h.receive(state)
    await vi.waitFor(() => expect(signal).toBeDefined())
    await vi.advanceTimersByTimeAsync(15_000)
    expect(signal?.aborted).toBe(true)
    vi.mocked(AXIOS_INSTANCE.post).mockResolvedValue({ data: { data: snapshot() } })
    h.receive(state)
    await vi.waitFor(() => expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/ack')).toHaveLength(2))
    const acknowledgements = vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/ack')
    expect(acknowledgements[0][1]).toEqual(acknowledgements[1][1])
    expect(execute).toHaveBeenCalledTimes(1)
  })

  it('bounds an unresolved device registration and allows explicit retry on the open socket', async () => {
    vi.useFakeTimers()
    let signal: AbortSignal | undefined
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (_path, _body, config) => {
      signal = config?.signal as AbortSignal
      return new Promise(() => {})
    })
    const h = harness(); cleanup.push(h.stop)
    await vi.waitFor(() => expect(signal).toBeDefined())
    await vi.advanceTimersByTimeAsync(15_000)
    expect(signal?.aborted).toBe(true)
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', false)
    expect(h.transport.send).not.toHaveBeenCalled()
    vi.mocked(AXIOS_INSTANCE.post).mockResolvedValue({ data: { data: snapshot() } })
    await h.runtime.retry()
    expect(h.transport.send).toHaveBeenCalledWith(expect.objectContaining({ type: 'session.endpoint' }))
  })

  it('allows an explicit retry after device registration fails while the shared socket remains open', async () => {
    vi.mocked(AXIOS_INSTANCE.post).mockRejectedValueOnce(new Error('Temporary registration failure'))
    const h = harness(); cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', 'Temporary registration failure'))
    expect(h.transport.send).not.toHaveBeenCalled()
    await h.runtime.retry()
    expect(h.transport.send).toHaveBeenCalledWith(expect.objectContaining({ type: 'session.endpoint' }))
  })

  it.each(['same principal', 'same principal recovery failure', 'different principal'] as const)('keeps stopped-incarnation proof only across the %s ownership change', async principal => {
    cleanup.push(registerPlaybackAdapter({ kind: 'song', supportsVolume: true,
      prepare: vi.fn(async () => {}), execute: vi.fn(async () => ({ observedState: 'playing' as const, position: 20 })),
      quiesce: vi.fn(async () => ({ observedState: 'stopped' as const, position: 27 })),
      observe: () => ({ observedState: 'playing', position: 20 }),
    }))
    const old = harness(); cleanup.push(old.stop)
    old.receive(snapshot({ endpoint, commands: [{ id: 'playing', attemptId: 'attempt', revision: 1,
      incarnationId: endpoint.incarnationId, epoch: 1, type: 'start',
      payload: { media: { kind: 'song', publicId: 'song' }, queue: ['song'], currentTrackIndex: 0, position: 20, desiredState: 'playing' },
      expiresAt: new Date(Date.now() + 60_000).toISOString() }] }))
    await vi.waitFor(() => expect(AXIOS_INSTANCE.post).toHaveBeenCalledWith('/api/session/ack', expect.objectContaining({ outcome: 'started' }), expect.anything()))
    if (principal !== 'different principal') advanceAuthorization()
    else synchronizeOwnership('new-login-session')
    if (principal === 'same principal recovery failure') vi.mocked(AXIOS_INSTANCE.get).mockRejectedValueOnce(new Error('Stop proof temporarily unavailable'))
    const replacement = harness(); cleanup.push(replacement.stop)
    if (principal === 'same principal recovery failure') {
      await vi.waitFor(() => expect(replacement.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', 'Stop proof temporarily unavailable'))
      expect(replacement.transport.send).not.toHaveBeenCalled()
      await replacement.runtime.retry()
      expect(AXIOS_INSTANCE.get).toHaveBeenCalledTimes(2)
    }
    await vi.waitFor(() => expect(replacement.transport.send).toHaveBeenCalled())
    if (principal !== 'different principal') {
      expect(AXIOS_INSTANCE.get).toHaveBeenCalledWith('/api/session/snapshot', expect.objectContaining({ headers: { 'X-Baander-Playback-Endpoint': endpoint.capability } }))
      expect(AXIOS_INSTANCE.post).toHaveBeenCalledWith('/api/session/observe', expect.objectContaining({ observedState: 'stopped', position: 27 }), expect.objectContaining({ headers: { 'X-Baander-Playback-Endpoint': endpoint.capability } }))
    } else expect(AXIOS_INSTANCE.get).not.toHaveBeenCalled()
  })

  it('registers on the retained socket and keeps its capability out of shared state', async () => {
    const h = harness(); cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledWith(expect.objectContaining({ type: 'session.endpoint' })))
    h.receive(snapshot({ endpoint }))
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).not.toHaveProperty('endpoint')
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toEqual({ endpoint: { destinationId: 'device', incarnationId: 'incarnation', epoch: 1 }, connected: true, error: null })
    expect(AXIOS_INSTANCE.get).not.toHaveBeenCalled()
  })

  it('loads initial intent before requesting a local acknowledged transfer', async () => {
    const h = harness(); cleanup.push(h.stop)
    h.receive(snapshot({ endpoint }))
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => ({ data: { data: {
      ...snapshot(path === '/api/session/transfer' ? { activeIncarnationId: endpoint.incarnationId } : {}),
      ...(path === '/api/session/transfer' ? { transferResult: { requestId: (body as { requestId: string }).requestId,
        attemptId: 'attempt', state: 'committed', rejectionReason: null } } : {}),
      ...(path === '/api/session/control' ? { requestResult: { requestId: (body as { requestId: string }).requestId, commandId: 'load', outcome: 'retained' } } : {}),
    } } }))
    await requestPlayback({ action: 'load', media: { kind: 'song', publicId: 'song' }, desiredState: 'paused' })
    const calls = vi.mocked(AXIOS_INSTANCE.post).mock.calls
    const control = calls.findIndex(([path]) => path === '/api/session/control')
    const transfer = calls.findIndex(([path]) => path === '/api/session/transfer')
    expect(control).toBeGreaterThanOrEqual(0)
    expect(transfer).toBeGreaterThan(control)
    expect(calls[transfer][1]).toEqual(expect.objectContaining({ targetIncarnationId: 'incarnation' }))
  })

  it.each(['song', 'video'] as const)('resumes recovered paused %s through an acknowledged local transfer', async kind => {
    const h = harness()
    cleanup.push(h.stop)
    const recovered = snapshot({ endpoint, media: { kind, publicId: 'retained-media' },
      queue: kind === 'song' ? ['retained-media'] : [], position: 12, desiredState: 'paused', observedState: 'stopped' })
    h.receive(recovered)
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => ({ data: { data: {
      ...recovered,
      desiredState: 'playing',
      ...(path === '/api/session/transfer' ? { activeIncarnationId: endpoint.incarnationId,
        transferResult: { requestId: (body as { requestId: string }).requestId, attemptId: 'attempt', state: 'committed', rejectionReason: null } } : {}),
      ...(path === '/api/session/control' ? { requestResult: {
        requestId: (body as { requestId: string }).requestId, commandId: 'resume', outcome: 'retained',
      } } : {}),
    } } }))

    await requestPlayback({ action: 'play' })

    const calls = vi.mocked(AXIOS_INSTANCE.post).mock.calls
    const control = calls.findIndex(([path]) => path === '/api/session/control')
    const transfer = calls.findIndex(([path]) => path === '/api/session/transfer')
    expect(control).toBeGreaterThanOrEqual(0)
    expect(transfer).toBeGreaterThan(control)
    expect(calls[transfer][1]).toMatchObject({ targetIncarnationId: endpoint.incarnationId })
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toMatchObject({
      media: recovered.media, queue: recovered.queue, position: 12, activeIncarnationId: endpoint.incarnationId,
    })
  })

  it.each(['load', 'play'] as const)('routes a remote %s without starting or transferring this browser', async action => {
    const execute = vi.fn()
    cleanup.push(registerPlaybackAdapter({ kind: 'song', supportsVolume: true, prepare: vi.fn(), execute, quiesce: vi.fn(), observe: vi.fn() }))
    const h = harness(); cleanup.push(h.stop)
    const state = snapshot({ endpoint, activeIncarnationId: 'remote', activeDestinationId: 'remote-device' })
    h.receive(state)
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (_path, payload) => ({ data: { data: { ...state, requestResult: { requestId: (payload as { requestId: string }).requestId, commandId: 'command', outcome: 'retained' } } } }))
    await requestPlayback(action === 'load' ? { action, media: { kind: 'video', publicId: 'movie' } } : { action })
    expect(execute).not.toHaveBeenCalled()
    expect(vi.mocked(AXIOS_INSTANCE.post).mock.calls.some(([path]) => path === '/api/session/transfer')).toBe(false)
  })

  it.each([
    { action: 'next' as const, position: 2, repeat: 'off' as const, shuffle: false, unavailable: ['third'], expected: { action: 'stop' } },
    { action: 'next' as const, position: 2, repeat: 'all' as const, shuffle: false, unavailable: ['third'], expected: { action: 'load', currentTrackIndex: 0 } },
    { action: 'next' as const, position: 2, repeat: 'one' as const, shuffle: true, unavailable: [], expected: { action: 'load', currentTrackIndex: 0 } },
    { action: 'previous' as const, position: 8, repeat: 'off' as const, shuffle: false, unavailable: [], expected: { action: 'seek', position: 0 } },
    { action: 'previous' as const, position: 2, repeat: 'off' as const, shuffle: false, unavailable: ['first'], expected: { action: 'load', currentTrackIndex: 2 } },
  ])('preserves navigation preferences for $action at $position seconds', async test => {
    const h = harness(); cleanup.push(h.stop)
    h.receive(snapshot({ endpoint, queue: ['first', 'second', 'third'], currentTrackIndex: 1,
      media: { kind: 'song', publicId: 'second' }, activeIncarnationId: 'remote', position: test.position }))
    await requestPlayback({ action: test.action, navigation: {
      shuffle: test.shuffle, repeat: test.repeat, shuffleBag: [1, 0, 2], unavailablePublicIds: test.unavailable,
    } })
    const call = vi.mocked(AXIOS_INSTANCE.post).mock.calls.find(([path]) => path === '/api/session/control')
    expect(call?.[1]).toEqual(expect.objectContaining(test.expected))
    expect(call?.[1]).not.toHaveProperty('navigation')
    if (test.expected.action === 'load') expect(call?.[1]).toHaveProperty('desiredState', 'playing')
  })

  it('ignores stale state and old account continuations and removes intent authority', async () => {
    const h = harness(); cleanup.push(h.stop)
    h.receive(snapshot({ endpoint, revision: 4, position: 44 }))
    h.receive(snapshot({ revision: 3, position: 1 }))
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toHaveProperty('position', 44)
    synchronizeOwnership('replacement-account')
    h.receive(snapshot({ revision: 9, position: 99 }))
    expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toHaveProperty('position', 44)
    await expect(requestPlayback({ action: 'play' })).rejects.toThrow('unavailable')
  })

  it('stops owned playback and reconciles old capability before registering again', async () => {
    const quiesce = vi.fn(async () => ({ observedState: 'stopped' as const, position: 27 }))
    cleanup.push(registerPlaybackAdapter({ kind: 'song', supportsVolume: true,
      prepare: vi.fn(async () => {}), execute: vi.fn(async () => ({ observedState: 'playing' as const, position: 20 })),
      quiesce, observe: () => ({ observedState: 'playing', position: 20 }) }))
    const h = harness(); cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalled())
    const command = { id: 'start', attemptId: 'attempt', revision: 1, incarnationId: endpoint.incarnationId, epoch: 1,
      type: 'start' as const, payload: { media: { kind: 'song' as const, publicId: 'song' }, queue: ['song'], currentTrackIndex: 0, position: 20, desiredState: 'playing' as const }, expiresAt: new Date(Date.now() + 60_000).toISOString() }
    h.receive(snapshot({ endpoint, commands: [command] }))
    await vi.waitFor(() => expect(AXIOS_INSTANCE.post).toHaveBeenCalledWith('/api/session/ack', expect.objectContaining({ outcome: 'started' }), expect.anything()))
    vi.mocked(AXIOS_INSTANCE.get).mockResolvedValue({ data: { data: snapshot({ commands: [{ ...command, id: 'stop', type: 'stop' }] }) } })
    h.close()
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', false)
    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    expect(quiesce).toHaveBeenCalledTimes(1)
    expect(AXIOS_INSTANCE.get).toHaveBeenCalledWith('/api/session/snapshot', expect.objectContaining({ params: { incarnationId: endpoint.incarnationId }, headers: { 'X-Baander-Playback-Endpoint': 'private-capability' } }))
    expect(AXIOS_INSTANCE.post).toHaveBeenCalledWith('/api/session/observe', expect.objectContaining({ observedState: 'stopped', position: 27 }), expect.anything())
    expect(AXIOS_INSTANCE.post).toHaveBeenCalledWith('/api/session/ack', expect.objectContaining({ commandId: 'stop', attemptId: 'attempt', outcome: 'stopped', position: 27, epoch: 1 }), expect.anything())
  })
})
