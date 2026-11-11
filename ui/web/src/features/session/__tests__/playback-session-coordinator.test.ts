import { QueryClient } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { PlaybackCommandResource, PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import type { LiveTransportListener, SharedLiveTransport } from '@/shared/live/transport'
import { advanceAuthorization, synchronizeOwnership } from '@/shared/live/ownership'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { PlaybackLocalRecovery } from '../services/playback-local-recovery'
import { registerPlaybackAdapter, requestPlayback, type PlaybackObservation } from '../services/playback-execution'
import { PlaybackSessionCoordinator, getPlaybackClientRequestId, overridePlayback, registerPlaybackCoordinator, transferPlayback, PLAYBACK_CONNECTION_KEY, PLAYBACK_SESSION_KEY } from '../services/playback-session-coordinator'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@/shared/live/transport', () => ({ authenticatedTransport: {} }))
const keepalive = vi.hoisted(() => ({ prepare: vi.fn(), send: vi.fn(), tokenListeners: new Set<() => void>() }))
vi.mock('@/shared/api-client/keepalive-request', () => ({
  prepareKeepalivePost: keepalive.prepare,
  sendKeepalivePost: keepalive.send,
  onAccessTokenChange: (listener: () => void) => {
    keepalive.tokenListeners.add(listener)
    return () => { keepalive.tokenListeners.delete(listener) }
  },
}))
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
    refuse: (message: string) => listener.onMessage?.({ type: 'session.error', message }),
    close: () => { connected = false; listener.onClose?.() },
    // Without a close before it, an open is a socket the transport replaced silently.
    open: () => { connected = true; listener.onOpen?.() },
  }
}
const settled = () => new Promise<void>(resolve => { setTimeout(resolve, 0) })
const posts = (path: string) => vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([called]) => called === path)
const registrations = (h: ReturnType<typeof harness>) => h.transport.send.mock.calls.map(([message]) => message as Record<string, unknown>)

/** A player that is as silent or as audible as the commands it executed left it. */
function player(kind: 'song' | 'video' = 'song') {
  const state: PlaybackObservation = { observedState: 'stopped', position: 0 }
  const silence = () => { if (state.observedState === 'playing') state.observedState = 'paused' }
  const adapter = {
    kind, supportsVolume: true,
    prepare: vi.fn(async () => {}),
    execute: vi.fn(async (command: PlaybackCommandResource): Promise<PlaybackObservation> => {
      if (command.type === 'stop') state.observedState = 'stopped'
      else if (command.type === 'pause') state.observedState = 'paused'
      else if (command.type === 'seek') state.position = command.payload.position
      else if (command.type === 'play') state.observedState = 'playing'
      else if (command.type === 'start' || command.type === 'load') {
        state.observedState = command.payload.desiredState
        state.position = command.payload.position
      }
      return { ...state }
    }),
    quiesce: vi.fn(async (): Promise<PlaybackObservation> => { silence(); return { ...state } }),
    observe: vi.fn((): PlaybackObservation => ({ ...state })),
    localRecovery: { eligible: false, fence: vi.fn((): PlaybackObservation | null => { silence(); return null }), rearmAfterRegistration: vi.fn() },
  }
  return { adapter, state }
}

const song = { kind: 'song' as const, publicId: 'song' }
const movie = { kind: 'video' as const, publicId: 'movie' }
function issue(type: PlaybackCommandResource['type'], overrides: Partial<PlaybackCommandResource> = {}): PlaybackCommandResource {
  return { id: type, attemptId: 'attempt', revision: 1, incarnationId: endpoint.incarnationId, epoch: 1, type,
    payload: { media: song, queue: ['song'], currentTrackIndex: 0, position: 20, desiredState: 'playing' },
    expiresAt: new Date(Date.now() + 60_000).toISOString(), ...overrides }
}

/** A registered page that is the session's runner and plays a song, or a movie. */
async function playingPage(recovery?: PlaybackLocalRecovery, kind: 'song' | 'video' = 'song') {
  const local = player(kind)
  cleanup.push(registerPlaybackAdapter(local.adapter))
  const h = harness(recovery)
  cleanup.push(h.stop)
  await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledOnce())
  const media = kind === 'song' ? song : movie
  const queue = kind === 'song' ? ['song'] : []
  const session = snapshot({ activeIncarnationId: endpoint.incarnationId, activeDestinationId: endpoint.destinationId,
    media, queue, position: 20, desiredState: 'playing', observedState: 'playing' })
  vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async () => ({ data: { data: session } }))
  h.receive({ ...session, endpoint, commands: [issue('start', { payload: { media, queue, currentTrackIndex: 0, position: 20, desiredState: 'playing' } })] })
  await vi.waitFor(() => expect(posts('/api/session/observe')).toHaveLength(1))
  expect(local.state.observedState).toBe('playing')
  await vi.waitFor(() => expect(keepalive.prepare).toHaveBeenCalledTimes(2))
  await settled()
  return { ...h, ...local, session }
}
/** The answer to a registration of the same page: the server kept its place under a new incarnation. */
const successor = { ...endpoint, incarnationId: 'next-incarnation', epoch: 2, capability: 'next-capability' }
function succeeded(session: PlaybackSnapshotResource): PlaybackSnapshotResource {
  return { ...session, activeIncarnationId: successor.incarnationId,
    destinations: session.destinations.map(destination => destination.incarnationId === endpoint.incarnationId
      ? { ...destination, incarnationId: successor.incarnationId, epoch: successor.epoch } : destination) }
}
let cleanup: (() => void)[] = []
beforeEach(() => {
  synchronizeOwnership(crypto.randomUUID())
  vi.mocked(AXIOS_INSTANCE.post).mockReset().mockImplementation(async (_path, payload) => ({ data: { data: { ...snapshot({ activeIncarnationId: 'remote' }), ...(_path === '/api/session/control' ? { requestResult: { requestId: (payload as { requestId: string }).requestId, commandId: 'command', outcome: 'retained' } } : {}) } } }))
  vi.mocked(AXIOS_INSTANCE.get).mockReset().mockResolvedValue({ data: { data: snapshot() } })
  let proofs = 0
  keepalive.prepare.mockReset().mockImplementation(async (path: string, headers: Record<string, string>) => ({
    url: `https://baander.app${path}`, headers: { ...headers, DPoP: `proof-${++proofs}` },
  }))
  keepalive.send.mockReset()
  keepalive.tokenListeners.clear()
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
      quiesce: vi.fn(), observe: vi.fn(() => ({ observedState: 'stopped' as const, position: 12 })),
      localRecovery: { eligible: true, fence, rearmAfterRegistration: vi.fn() } }))
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
      .rejects.toThrow('Previous playback is unconfirmed. Stop it in its original browser, or choose Play here anyway in the playback destination menu.')

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

  it.each(['same principal', 'same principal with a refused report', 'different principal'] as const)('reports the incarnation of a replaced runtime closed only across the %s ownership change', async principal => {
    const old = await playingPage()
    old.state.position = 27
    vi.mocked(AXIOS_INSTANCE.post).mockClear()
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    cleanup.push(() => warn.mockRestore())
    if (principal === 'same principal with a refused report') {
      vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async path => {
        if (path === '/api/session/close') throw Object.assign(new Error('Request failed with status code 409'), { response: { status: 409, data: { error: { message: 'Endpoint authority is invalid.' } } } })
        return { data: { data: old.session } }
      })
    }
    if (principal !== 'different principal') advanceAuthorization()
    else synchronizeOwnership('new-login-session')
    const replacement = harness(); cleanup.push(replacement.stop)

    await vi.waitFor(() => expect(replacement.transport.send).toHaveBeenCalled())

    expect(old.state.observedState).not.toBe('playing')
    if (principal !== 'different principal') {
      expect(posts('/api/session/close')).toEqual([[
        '/api/session/close',
        { incarnationId: endpoint.incarnationId, checkpoint: { revision: 1, media: song, currentTrackIndex: 0, position: 27 } },
        expect.objectContaining({ headers: { 'X-Baander-Playback-Endpoint': endpoint.capability } }),
      ]])
    } else expect(posts('/api/session/close')).toEqual([])
    expect(warn).toHaveBeenCalledTimes(principal === 'same principal with a refused report' ? 1 : 0)
    expect(replacement.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', null)
    expect(AXIOS_INSTANCE.get).not.toHaveBeenCalled()
    expect(posts('/api/session/observe')).toEqual([])
    expect(posts('/api/session/ack')).toEqual([])
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

  it('stops its players at a socket close and reports its earlier incarnation closed before it registers again', async () => {
    const h = await playingPage()
    h.state.position = 27
    vi.mocked(AXIOS_INSTANCE.post).mockClear()

    h.close()
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', false)
    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))

    expect(h.adapter.quiesce).toHaveBeenCalledTimes(1)
    expect(h.state.observedState).not.toBe('playing')
    expect(posts('/api/session/close')).toEqual([[
      '/api/session/close',
      { incarnationId: endpoint.incarnationId, checkpoint: { revision: 1, media: song, currentTrackIndex: 0, position: 27 } },
      expect.objectContaining({ headers: { 'X-Baander-Playback-Endpoint': 'private-capability' } }),
    ]])
    const reported = vi.mocked(AXIOS_INSTANCE.post).mock.invocationCallOrder[vi.mocked(AXIOS_INSTANCE.post).mock.calls.findIndex(([path]) => path === '/api/session/close')]
    expect(reported).toBeLessThan(h.transport.send.mock.invocationCallOrder[1])
    // The close report is all the page sends for its earlier incarnation: no snapshot read, observation or acknowledgement.
    expect(AXIOS_INSTANCE.get).not.toHaveBeenCalled()
    expect(posts('/api/session/observe')).toEqual([])
    expect(posts('/api/session/ack')).toEqual([])
    expect(registrations(h)[1].pageId).toBe(registrations(h)[0].pageId)

    // The report released the session: the answer names no runner, and the page has nothing to add.
    h.receive({ ...h.session, revision: 2, activeIncarnationId: null, desiredState: 'paused', observedState: 'paused', endpoint: successor })
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ connected: true, endpoint: { incarnationId: successor.incarnationId } }))
    await settled()
    expect(posts('/api/session/observe')).toEqual([])
    expect(h.adapter.localRecovery.rearmAfterRegistration).toHaveBeenCalledTimes(2)
  })

  it.each(['is refused', 'never arrives'] as const)('still registers when its close report %s, and a runner left without a player reports itself stopped at once', async scenario => {
    const h = await playingPage()
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    cleanup.push(() => warn.mockRestore())
    vi.mocked(AXIOS_INSTANCE.post).mockClear().mockImplementation(async path => {
      if (path !== '/api/session/close') return { data: { data: succeeded(h.session) } }
      throw scenario === 'is refused'
        ? Object.assign(new Error('Request failed with status code 409'), { response: { status: 409, data: { error: { message: 'Endpoint authority is invalid.' } } } })
        : new Error('Network Error')
    })

    h.close()
    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    expect(posts('/api/session/close')).toHaveLength(1)
    expect(warn).toHaveBeenCalledOnce()

    // The registration of the same page kept its place: the session still says this page plays.
    h.receive({ ...succeeded(h.session), endpoint: successor })

    await vi.waitFor(() => expect(posts('/api/session/observe')).toHaveLength(1))
    expect(posts('/api/session/observe')[0].slice(1)).toEqual([
      { incarnationId: successor.incarnationId, expectedRevision: 1, observedState: 'stopped', position: 20 },
      expect.objectContaining({ headers: { 'X-Baander-Playback-Endpoint': successor.capability } }),
    ])
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ connected: true, error: null })
  })

  it('reports nothing closed and keeps its own record when a player survived the stop of a disconnect', async () => {
    const h = await playingPage()
    h.adapter.quiesce.mockImplementation(async () => ({ ...h.state }))
    h.adapter.localRecovery.fence.mockImplementation(() => null)
    h.adapter.execute.mockRejectedValue(new Error('The element is gone.'))
    vi.mocked(AXIOS_INSTANCE.post).mockClear()

    h.close()
    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))

    expect(h.state.observedState).toBe('playing')
    expect(posts('/api/session/close')).toEqual([])
    expect(registrations(h)[1].pageId).toBe(registrations(h)[0].pageId)

    // The page still plays and was kept as the runner: it does not claim to have stopped.
    h.receive({ ...succeeded(h.session), endpoint: successor })
    await vi.waitFor(() => expect(posts('/api/session/observe')).toHaveLength(1))
    expect(posts('/api/session/observe')[0][1]).toMatchObject({ incarnationId: successor.incarnationId, observedState: 'unknown' })
  })

  it('reports nothing closed and publishes no error at its first registration', async () => {
    const h = harness(); cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledOnce())
    h.receive(snapshot({ endpoint }))
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', true))

    expect(posts('/api/session/close')).toEqual([])
    expect(posts('/api/session/observe')).toEqual([])
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', null)
  })

  it('names its document in every registration, with one identity for the whole page and none in storage', async () => {
    const first = harness(); cleanup.push(first.stop)
    const second = harness(); cleanup.push(second.stop)
    await vi.waitFor(() => {
      expect(first.transport.send).toHaveBeenCalledOnce()
      expect(second.transport.send).toHaveBeenCalledOnce()
    })
    first.close()
    first.open()
    await vi.waitFor(() => expect(first.transport.send).toHaveBeenCalledTimes(2))

    const pageId = registrations(first)[0].pageId as string
    expect(registrations(first)[0]).toMatchObject({ type: 'session.endpoint' })
    expect(pageId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/)
    expect(registrations(first)[1].pageId).toBe(pageId)
    expect(registrations(second)[0].pageId).toBe(pageId)
    expect(JSON.stringify([{ ...localStorage }, { ...sessionStorage }])).not.toContain(pageId)
  })

  it('keeps playing and stays the runner when its socket is replaced without a close', async () => {
    const h = await playingPage()
    vi.mocked(AXIOS_INSTANCE.post).mockClear().mockImplementation(async () => ({ data: { data: succeeded(h.session) } }))

    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    expect(registrations(h)[1]).toMatchObject({ type: 'session.endpoint', pageId: registrations(h)[0].pageId })
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ connected: true, error: null, endpoint: { incarnationId: endpoint.incarnationId } })

    h.receive({ ...succeeded(h.session), endpoint: successor })
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ connected: true, endpoint: { incarnationId: successor.incarnationId } }))
    await settled()

    expect(h.state.observedState).toBe('playing')
    expect(h.adapter.localRecovery.fence).not.toHaveBeenCalled()
    expect(h.adapter.quiesce).not.toHaveBeenCalled()
    expect(h.adapter.execute.mock.calls.map(([command]) => command.type)).toEqual(['start'])
    // Only the first registration rearmed the player: rearming would cancel what it executes.
    expect(h.adapter.localRecovery.rearmAfterRegistration).toHaveBeenCalledTimes(1)
    expect(h.transport.send).toHaveBeenCalledTimes(2)
    expect(posts('/api/session/close')).toEqual([])
    expect(posts('/api/session/recover-local')).toEqual([])

    // The runner answers for the new incarnation, and what it reports is its playing player.
    h.receive({ ...succeeded(h.session), commands: [issue('seek', { incarnationId: successor.incarnationId, epoch: successor.epoch })] })
    await vi.waitFor(() => expect(posts('/api/session/observe')).toHaveLength(1))
    expect(posts('/api/session/ack')[0].slice(1)).toEqual([
      expect.objectContaining({ commandId: 'seek', incarnationId: successor.incarnationId, epoch: successor.epoch, outcome: 'applied' }),
      expect.objectContaining({ headers: { 'X-Baander-Playback-Endpoint': successor.capability } }),
    ])
    expect(posts('/api/session/observe')[0][1]).toMatchObject({ incarnationId: successor.incarnationId, observedState: 'playing' })
  })

  it('answers a command carried over to its next incarnation once, although its first answer named the earlier one', async () => {
    const h = await playingPage()
    let refuseEarlierAnswer!: () => void
    vi.mocked(AXIOS_INSTANCE.post).mockClear().mockImplementation(async (path, body) => {
      if (path === '/api/session/ack' && (body as { incarnationId: string }).incarnationId === endpoint.incarnationId) {
        await new Promise<void>(resolve => { refuseEarlierAnswer = resolve })
        throw Object.assign(new Error('Request failed with status code 409'), { response: { status: 409, data: { error: { message: 'Endpoint authority is invalid.' } } } })
      }
      return { data: { data: succeeded(h.session) } }
    })
    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))

    // Shown to the earlier incarnation while the registration was on its way.
    h.receive({ ...h.session, commands: [issue('seek')] })
    await vi.waitFor(() => expect(refuseEarlierAnswer).toBeDefined())
    h.receive({ ...succeeded(h.session), endpoint: successor })
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('endpoint.incarnationId', successor.incarnationId))
    refuseEarlierAnswer()
    await settled()
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', null)

    h.receive({ ...succeeded(h.session), commands: [issue('seek', { incarnationId: successor.incarnationId, epoch: successor.epoch })] })
    await vi.waitFor(() => expect(posts('/api/session/ack')).toHaveLength(2))

    expect(h.adapter.execute.mock.calls.filter(([command]) => command.type === 'seek')).toHaveLength(1)
    expect(posts('/api/session/ack')[1].slice(1)).toEqual([
      { commandId: 'seek', attemptId: 'attempt', revision: 1, incarnationId: successor.incarnationId, epoch: successor.epoch, outcome: 'applied', position: 20 },
      expect.objectContaining({ headers: { 'X-Baander-Playback-Endpoint': successor.capability } }),
    ])
  })

  it('executes a command for its next incarnation that was relayed while the answer was being bound', async () => {
    const recovery = new PlaybackLocalRecovery('https://baander.app', 'listener')
    let bind!: () => void
    const spies = [
      vi.spyOn(recovery, 'initialize').mockResolvedValue(),
      vi.spyOn(recovery, 'isEligible').mockReturnValue(false),
      vi.spyOn(recovery, 'registered').mockResolvedValue(),
    ]
    cleanup.push(() => spies.forEach(spy => spy.mockRestore()))
    const h = await playingPage(recovery)
    vi.mocked(AXIOS_INSTANCE.post).mockClear().mockImplementation(async () => ({ data: { data: succeeded(h.session) } }))
    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    vi.mocked(recovery.registered).mockImplementationOnce(() => new Promise<void>(resolve => { bind = resolve }))

    h.receive({ ...succeeded(h.session), endpoint: successor })
    h.receive({ ...succeeded(h.session), commands: [issue('pause', { incarnationId: successor.incarnationId, epoch: successor.epoch })] })
    await settled()
    expect(h.state.observedState).toBe('playing')
    bind()

    await vi.waitFor(() => expect(posts('/api/session/ack')).toHaveLength(1))
    expect(h.state.observedState).toBe('paused')
    expect(posts('/api/session/ack')[0][1]).toMatchObject({ commandId: 'pause', incarnationId: successor.incarnationId, outcome: 'paused' })
  })

  it('abandons a registration sent on a socket that was replaced before it answered, and registers once on the new one', async () => {
    const h = harness(); cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledOnce())

    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    await settled()
    expect(h.transport.send).toHaveBeenCalledTimes(2)

    h.receive(snapshot({ endpoint }))
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ connected: true, error: null }))
  })

  it.each(['fails before it is sent', 'is refused', 'stays unanswered'] as const)('sends its registration again on a replaced socket when the first %s, and keeps playing', async scenario => {
    const h = await playingPage()
    vi.useFakeTimers()
    if (scenario === 'fails before it is sent') {
      vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async path => {
        if (path === '/api/devices' && h.transport.send.mock.calls.length === 1 && posts('/api/devices').length === 2) throw new Error('Temporary registration failure')
        return { data: { data: h.session } }
      })
    }

    h.open()
    await vi.advanceTimersByTimeAsync(0)
    if (scenario === 'fails before it is sent') {
      expect(h.transport.send).toHaveBeenCalledTimes(1)
      expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ connected: true, error: 'Temporary registration failure' })
    } else {
      expect(h.transport.send).toHaveBeenCalledTimes(2)
      if (scenario === 'is refused') h.refuse('Playback registration is unavailable.')
    }
    const sent = h.transport.send.mock.calls.length
    await vi.advanceTimersByTimeAsync(scenario === 'stays unanswered' ? 14_999 : 999)
    expect(h.transport.send).toHaveBeenCalledTimes(sent)
    await vi.advanceTimersByTimeAsync(1)
    expect(h.transport.send).toHaveBeenCalledTimes(sent + 1)

    h.receive({ ...succeeded(h.session), endpoint: successor })
    await vi.advanceTimersByTimeAsync(0)
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ connected: true, error: null, endpoint: { incarnationId: successor.incarnationId } })
    await vi.advanceTimersByTimeAsync(20_000)
    expect(h.transport.send).toHaveBeenCalledTimes(sent + 1)
    expect(h.state.observedState).toBe('playing')
    expect(h.adapter.localRecovery.fence).not.toHaveBeenCalled()
    expect(h.adapter.quiesce).not.toHaveBeenCalled()
  })

  it('registers at once on an explicit retry while it plays through a replaced socket, although it is ready', async () => {
    const h = await playingPage()
    vi.useFakeTimers()
    vi.mocked(AXIOS_INSTANCE.post).mockRejectedValueOnce(new Error('Temporary registration failure'))
    h.open()
    await vi.advanceTimersByTimeAsync(0)
    expect(h.transport.send).toHaveBeenCalledTimes(1)
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', true)

    await h.runtime.retry()

    expect(h.transport.send).toHaveBeenCalledTimes(2)
    expect(h.state.observedState).toBe('playing')
    // The retry replaced the scheduled attempt instead of adding to it.
    await vi.advanceTimersByTimeAsync(1_000)
    expect(h.transport.send).toHaveBeenCalledTimes(2)
  })

  it('stops its players and registers as a disconnected page when a replaced socket carries no registration after a command lifetime', async () => {
    const h = await playingPage()
    h.state.position = 33
    vi.useFakeTimers()
    vi.mocked(AXIOS_INSTANCE.post).mockClear()

    h.open()
    await vi.advanceTimersByTimeAsync(19_999)
    expect(h.state.observedState).toBe('playing')
    expect(h.adapter.localRecovery.fence).not.toHaveBeenCalled()
    expect(posts('/api/session/close')).toEqual([])
    const sent = h.transport.send.mock.calls.length

    await vi.advanceTimersByTimeAsync(1)

    expect(h.adapter.localRecovery.fence).toHaveBeenCalledOnce()
    expect(h.state.observedState).not.toBe('playing')
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ connected: false, endpoint: null })
    expect(posts('/api/session/close')).toEqual([[
      '/api/session/close',
      { incarnationId: endpoint.incarnationId, checkpoint: { revision: 1, media: song, currentTrackIndex: 0, position: 33 } },
      expect.objectContaining({ headers: { 'X-Baander-Playback-Endpoint': endpoint.capability } }),
    ]])
    expect(h.transport.send).toHaveBeenCalledTimes(sent + 1)
    // It is a disconnected page now: nothing sends its registration again by itself.
    await vi.advanceTimersByTimeAsync(60_000)
    expect(h.transport.send).toHaveBeenCalledTimes(sent + 1)
  })

  it('sends its prepared close report as it is hidden for unloading, before it fences its players, and reports again when restored', async () => {
    const h = await playingPage()
    expect(keepalive.prepare).toHaveBeenLastCalledWith('/api/session/close', { 'X-Baander-Playback-Endpoint': endpoint.capability })
    const prepared = await keepalive.prepare.mock.results[1].value
    h.state.position = 31
    vi.mocked(AXIOS_INSTANCE.post).mockClear()

    window.dispatchEvent(new Event('pagehide'))

    expect(keepalive.send).toHaveBeenCalledExactlyOnceWith(prepared, {
      incarnationId: endpoint.incarnationId, checkpoint: { revision: 1, media: song, currentTrackIndex: 0, position: 31 },
    })
    expect(keepalive.send.mock.invocationCallOrder[0]).toBeLessThan(h.adapter.localRecovery.fence.mock.invocationCallOrder[0])
    expect(h.state.observedState).not.toBe('playing')
    await settled()
    expect(h.transport.send).toHaveBeenCalledTimes(1)
    expect(posts('/api/session/close')).toEqual([])

    // Restored from the back/forward cache: the page cannot know whether its report arrived.
    window.dispatchEvent(new Event('pageshow'))
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    expect(posts('/api/session/close')).toEqual([[
      '/api/session/close',
      { incarnationId: endpoint.incarnationId, checkpoint: { revision: 1, media: song, currentTrackIndex: 0, position: 31 } },
      expect.objectContaining({ headers: { 'X-Baander-Playback-Endpoint': endpoint.capability } }),
    ]])
    expect(registrations(h)[1].pageId).toBe(registrations(h)[0].pageId)
    await settled()
    expect(h.transport.send).toHaveBeenCalledTimes(2)
    expect(keepalive.send).toHaveBeenCalledOnce()
  })

  it('sends one report for each time it is hidden, each with its own proof and incarnation', async () => {
    const h = await playingPage()
    window.dispatchEvent(new Event('pagehide'))
    window.dispatchEvent(new Event('pageshow'))
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    h.receive({ ...h.session, revision: 2, activeIncarnationId: null, desiredState: 'paused', observedState: 'paused', position: 20, endpoint: successor })
    await vi.waitFor(() => expect(keepalive.prepare).toHaveBeenLastCalledWith('/api/session/close', { 'X-Baander-Playback-Endpoint': successor.capability }))
    await settled()

    window.dispatchEvent(new Event('pagehide'))

    expect(keepalive.send).toHaveBeenCalledTimes(2)
    const [[first, firstBody], [second, secondBody]] = keepalive.send.mock.calls as [{ headers: Record<string, string> }, { incarnationId: string }][]
    expect([firstBody.incarnationId, secondBody.incarnationId]).toEqual([endpoint.incarnationId, successor.incarnationId])
    expect(first.headers['X-Baander-Playback-Endpoint']).toBe(endpoint.capability)
    expect(second.headers['X-Baander-Playback-Endpoint']).toBe(successor.capability)
    expect(second.headers.DPoP).not.toBe(first.headers.DPoP)
  })

  it('replaces its prepared close report after each presence report and each new access token', async () => {
    vi.useFakeTimers()
    const h = harness(); cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledOnce())
    const session = snapshot({ media: song, queue: ['song'], position: 12 })
    vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async () => ({ data: { data: session } }))
    h.receive({ ...session, endpoint })
    await vi.advanceTimersByTimeAsync(0)
    expect(keepalive.prepare).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(10_000)
    expect(posts('/api/session/observe')).toHaveLength(1)
    expect(keepalive.prepare).toHaveBeenCalledTimes(2)

    keepalive.tokenListeners.forEach(listener => listener())
    await vi.advanceTimersByTimeAsync(0)
    expect(keepalive.prepare).toHaveBeenCalledTimes(3)

    window.dispatchEvent(new Event('pagehide'))
    expect(keepalive.send).toHaveBeenCalledExactlyOnceWith(
      expect.objectContaining({ headers: expect.objectContaining({ DPoP: 'proof-3' }) }),
      { incarnationId: endpoint.incarnationId, checkpoint: { revision: 1, media: song, currentTrackIndex: 0, position: 12 } },
    )
  })

  it('keeps the report it has when a presence report fails', async () => {
    vi.useFakeTimers()
    const h = harness(); cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledOnce())
    h.receive(snapshot({ endpoint }))
    await vi.advanceTimersByTimeAsync(0)
    vi.mocked(AXIOS_INSTANCE.post).mockRejectedValue(new Error('Presence failed'))

    await vi.advanceTimersByTimeAsync(10_000)
    expect(keepalive.prepare).toHaveBeenCalledTimes(1)

    window.dispatchEvent(new Event('pagehide'))
    expect(keepalive.send).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({ headers: expect.objectContaining({ DPoP: 'proof-1' }) }), expect.anything())
  })

  it.each(['is not registered yet', 'has no report prepared yet', 'has lost its connection'] as const)('sends nothing as it is hidden while it %s, and still fences its players', async scenario => {
    const local = player()
    cleanup.push(registerPlaybackAdapter(local.adapter))
    if (scenario === 'has no report prepared yet') keepalive.prepare.mockImplementation(() => new Promise(() => {}))
    const h = harness(); cleanup.push(h.stop)
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledOnce())
    if (scenario !== 'is not registered yet') {
      h.receive(snapshot({ endpoint }))
      await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', true))
      await settled()
    }
    if (scenario === 'has lost its connection') h.close()
    local.adapter.localRecovery.fence.mockClear()

    window.dispatchEvent(new Event('pagehide'))

    expect(keepalive.send).not.toHaveBeenCalled()
    expect(local.adapter.localRecovery.fence).toHaveBeenCalledOnce()
  })

  it('reports nothing and keeps playing when the tab is only hidden', async () => {
    const h = await playingPage()
    vi.mocked(AXIOS_INSTANCE.post).mockClear()
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    cleanup.push(() => visibility.mockRestore())

    document.dispatchEvent(new Event('visibilitychange'))
    await settled()

    expect(keepalive.send).not.toHaveBeenCalled()
    expect(posts('/api/session/close')).toEqual([])
    expect(h.adapter.localRecovery.fence).not.toHaveBeenCalled()
    expect(h.state.observedState).toBe('playing')
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', true)
  })

  /** The session as another browser runs it. */
  const elsewhere = (session: PlaybackSnapshotResource, revision: number): PlaybackSnapshotResource => ({
    ...session, revision, activeIncarnationId: 'remote', activeDestinationId: 'remote-device',
    destinations: [...session.destinations, { destinationId: 'remote-device', incarnationId: 'remote', name: 'Living room', kind: 'browser',
      epoch: 1, available: true, capabilities: ['song', 'video'], observedState: 'playing', position: 20, observedVolume: null }],
  })
  const stops = (h: Awaited<ReturnType<typeof playingPage>>) => h.adapter.execute.mock.calls.filter(([command]) => command.type === 'stop')

  it.each([
    ['song', 'another runner'], ['video', 'another runner'], ['song', 'no runner'], ['video', 'no runner'],
  ] as const)('silences a playing %s once when a snapshot names %s', async (kind, runner) => {
    const h = await playingPage(undefined, kind)
    const lost = (revision: number) => runner === 'another runner' ? elsewhere(h.session, revision)
      : { ...h.session, revision, activeIncarnationId: null, activeDestinationId: null, desiredState: 'paused' as const, observedState: 'paused' as const }

    h.receive(lost(2))
    await vi.waitFor(() => expect(h.state.observedState).toBe('paused'))

    expect(h.adapter.quiesce).toHaveBeenCalledOnce()
    // Silenced where it stands: a stop would close a movie the viewer still has open.
    expect(stops(h)).toEqual([])
    expect(posts('/api/session/ack')).toHaveLength(1)

    h.receive(lost(3))
    await settled()
    expect(h.adapter.quiesce).toHaveBeenCalledOnce()
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ connected: true, error: null })
  })

  it.each(['song', 'video'] as const)('leaves a paused %s alone when a snapshot names no runner, and plays it again at its cursor', async kind => {
    const h = await playingPage(undefined, kind)
    const payload = { ...issue('start').payload, media: h.session.media, queue: h.session.queue, position: 44 }
    h.receive({ ...h.session, revision: 2, desiredState: 'paused', commands: [issue('pause', { revision: 2, payload: { ...payload, desiredState: 'paused' } })] })
    await vi.waitFor(() => expect(h.state.observedState).toBe('paused'))
    await vi.waitFor(() => expect(posts('/api/session/ack')).toHaveLength(2))
    h.adapter.execute.mockClear()

    // The paused tab's presence lapsed and the session was released from it while it stayed open.
    h.receive({ ...h.session, revision: 3, activeIncarnationId: null, activeDestinationId: null, desiredState: 'paused', observedState: 'paused', position: 44 })
    await settled()
    expect(h.adapter.quiesce).not.toHaveBeenCalled()
    expect(h.adapter.execute).not.toHaveBeenCalled()
    expect(h.state.observedState).toBe('paused')

    h.receive({ ...h.session, revision: 4, activeIncarnationId: null, activeDestinationId: null, position: 44,
      transfer: { attemptId: 'resume', state: 'starting', sourceIncarnationId: null, targetIncarnationId: endpoint.incarnationId, position: 44, desiredState: 'playing' },
      commands: [issue('start', { id: 'resume', attemptId: 'resume', revision: 4, payload })] })
    await vi.waitFor(() => expect(h.state).toEqual({ observedState: 'playing', position: 44 }))
    expect(h.adapter.quiesce).not.toHaveBeenCalled()
    expect(stops(h)).toEqual([])
  })

  it.each(['the target of a transfer that is starting', 'the source of a transfer', 'the runner'] as const)('leaves a playing player alone while the session names the tab as %s', async role => {
    const h = await playingPage()
    const transfer = { attemptId: 'transfer', position: 20, desiredState: 'playing' as const }
    const named = (revision: number): PlaybackSnapshotResource => role === 'the runner' ? { ...h.session, revision }
      : role === 'the source of a transfer'
        ? { ...elsewhere(h.session, revision), transfer: { ...transfer, state: 'starting', sourceIncarnationId: endpoint.incarnationId, targetIncarnationId: 'remote' } }
        // It answered its start and plays; the snapshot that commits the transfer has not arrived.
        : { ...elsewhere(h.session, revision), transfer: { ...transfer, state: 'starting', sourceIncarnationId: 'remote', targetIncarnationId: endpoint.incarnationId } }

    h.receive(named(2))
    h.receive(named(3))
    await settled()

    expect(h.adapter.quiesce).not.toHaveBeenCalled()
    expect(h.state.observedState).toBe('playing')
  })

  it('ignores a snapshot older than the one it holds when it decides whether it lost the session', async () => {
    const h = await playingPage()
    h.receive({ ...h.session, revision: 3 })

    h.receive(elsewhere(h.session, 2))
    await settled()

    expect(h.adapter.quiesce).not.toHaveBeenCalled()
    expect(h.state.observedState).toBe('playing')
  })

  it('silences nothing while it registers again as the same page, whichever incarnation a snapshot names meanwhile', async () => {
    const h = await playingPage()
    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))

    // Relayed after the server kept the page's place and before its answer arrived here.
    h.receive(succeeded(h.session))
    await settled()
    expect(h.adapter.quiesce).not.toHaveBeenCalled()

    // The answer itself is applied while the page still holds its earlier incarnation.
    h.receive({ ...succeeded(h.session), endpoint: successor })
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('endpoint.incarnationId', successor.incarnationId))

    // An answer computed before the server kept the page's place carries the same revision and
    // names the earlier incarnation; it arrives after the new one is bound.
    h.receive(h.session)
    h.receive({ ...h.session, commands: [] })
    await settled()

    expect(h.adapter.quiesce).not.toHaveBeenCalled()
    expect(h.state.observedState).toBe('playing')
  })

  it('still silences a page that registered again as itself once the session names somebody else', async () => {
    const h = await playingPage()
    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
    h.receive({ ...succeeded(h.session), endpoint: successor })
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('endpoint.incarnationId', successor.incarnationId))

    h.receive(elsewhere(succeeded(h.session), 2))

    await vi.waitFor(() => expect(h.state.observedState).toBe('paused'))
    expect(h.adapter.quiesce).toHaveBeenCalledOnce()
  })

  it.each(['another runner', 'no runner'] as const)('silences a playing page once when the answer to its registration as itself names %s', async runner => {
    const h = await playingPage()
    // The page lost its network while it played and was overridden meanwhile, so the server kept no
    // place for it: its new incarnation is an ordinary destination of a session it does not run.
    const lost = runner === 'another runner' ? elsewhere(h.session, 2)
      : { ...h.session, revision: 2, activeIncarnationId: null, activeDestinationId: null, desiredState: 'paused' as const, observedState: 'paused' as const }
    const answer = { ...lost, destinations: lost.destinations.map(destination => destination.incarnationId === endpoint.incarnationId
      ? { ...destination, incarnationId: successor.incarnationId, epoch: successor.epoch } : destination) }
    vi.mocked(AXIOS_INSTANCE.post).mockClear().mockImplementation(async () => ({ data: { data: answer } }))
    h.open()
    await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))

    h.receive({ ...answer, endpoint: successor })
    await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('endpoint.incarnationId', successor.incarnationId))
    await settled()

    expect(h.state.observedState).toBe('paused')
    expect(h.adapter.quiesce).toHaveBeenCalledOnce()
    expect(stops(h)).toEqual([])
    expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toMatchObject({ connected: true, error: null })

    // Silenced once: the next snapshot that does not name it leaves it as it is.
    h.receive({ ...answer, revision: 3 })
    await settled()
    expect(h.adapter.quiesce).toHaveBeenCalledOnce()
    expect(h.state.observedState).toBe('paused')
  })

  it('executes a preparation that follows the loss of the session after the player is silenced, and keeps it', async () => {
    const h = await playingPage()
    let silence!: () => void
    h.adapter.quiesce.mockImplementationOnce(() => new Promise<PlaybackObservation>(resolve => {
      silence = () => { h.state.observedState = 'paused'; resolve({ ...h.state }) }
    }))
    h.receive(elsewhere(h.session, 2))
    await vi.waitFor(() => expect(silence).toBeDefined())

    const transfer = { attemptId: 'back', state: 'preparing' as const, sourceIncarnationId: 'remote', targetIncarnationId: endpoint.incarnationId, position: 50, desiredState: 'playing' as const }
    const prepare = issue('prepare', { id: 'prepare', attemptId: 'back', revision: 3, payload: { ...issue('start').payload, position: 50 } })
    h.receive({ ...elsewhere(h.session, 3), transfer, commands: [prepare] })
    await settled()
    expect(h.adapter.prepare).not.toHaveBeenCalled()

    silence()
    await vi.waitFor(() => expect(h.adapter.prepare).toHaveBeenCalledOnce())
    await vi.waitFor(() => expect(posts('/api/session/ack').at(-1)?.[1]).toMatchObject({ commandId: 'prepare', outcome: 'prepared' }))

    h.receive({ ...elsewhere(h.session, 4), transfer: { ...transfer, state: 'quiescing' } })
    await settled()
    expect(h.adapter.quiesce).toHaveBeenCalledOnce()
    expect(stops(h)).toEqual([])
  })

  describe('Play here anyway', () => {
    const gone = { destinationId: 'gone-device', incarnationId: 'gone-tab', name: 'Firefox on Linux', kind: 'browser' as const,
      epoch: 1, available: false, capabilities: ['song' as const, 'video' as const], observedState: 'unknown' as const, position: 77, observedVolume: null }
    const refusal = (message: string) => Object.assign(new Error('Request failed with status code 409'), { response: { status: 409, data: { error: { message } } } })

    /**
     * A registered tab beside a session whose runner has gone without confirming that it stopped.
     * The server here accepts an override, which leaves the session paused without a runner, and
     * then lets the tab play through the ordinary control and transfer.
     */
    async function refusedTab(left: Partial<PlaybackSnapshotResource> = {}) {
      const local = player()
      cleanup.push(registerPlaybackAdapter(local.adapter))
      const h = harness()
      cleanup.push(h.stop, registerPlaybackCoordinator(h.runtime))
      await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledOnce())
      const own = snapshot().destinations
      let state = snapshot({ revision: 5, media: { kind: 'song', publicId: 'held-song' }, queue: ['held-song'], position: 77,
        desiredState: 'playing', observedState: 'unknown', activeIncarnationId: gone.incarnationId, activeDestinationId: gone.destinationId,
        destinations: [...own, gone] })
      const server = { override: (): void => {}, overrides: [] as Record<string, unknown>[] }
      vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body) => {
        const request = body as Record<string, unknown>
        if (path === '/api/session/override') {
          server.override()
          server.overrides.push(request)
          state = { ...state, revision: state.revision + 1, activeIncarnationId: null, activeDestinationId: null,
            desiredState: 'paused', observedState: 'paused', ...left }
          return { data: { data: { ...state, requestResult: { requestId: request.requestId, commandId: 'override', outcome: 'overridden', rejectionReason: null } } } }
        }
        if (path === '/api/session/control') {
          state = { ...state, revision: state.revision + 1 }
          return { data: { data: { ...state, requestResult: { requestId: request.requestId, commandId: 'control', outcome: 'retained', rejectionReason: null } } } }
        }
        if (path === '/api/session/transfer') {
          state = { ...state, revision: state.revision + 1, activeIncarnationId: endpoint.incarnationId, activeDestinationId: endpoint.destinationId }
          return { data: { data: { ...state, transferResult: { requestId: request.requestId, attemptId: 'attempt', state: 'committed', rejectionReason: null } } } }
        }
        return { data: { data: state } }
      })
      h.receive({ ...state, endpoint })
      await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', true))
      return { ...h, ...local, server, current: () => state, hold: (next: PlaybackSnapshotResource) => { state = next; h.receive(next) } }
    }
    const controls = () => posts('/api/session/control').map(([, body]) => body as Record<string, unknown>)
    const selection = { action: 'load' as const, media: { kind: 'song' as const, publicId: 'chosen-song' }, position: 0, desiredState: 'playing' as const }

    it('posts one override for this tab at the current revision, after it renewed its presence', async () => {
      const h = await refusedTab()

      await overridePlayback()

      const calls = vi.mocked(AXIOS_INSTANCE.post).mock.calls.map(([path]) => path)
      expect(calls.filter(path => path === '/api/session/override')).toHaveLength(1)
      expect(calls.indexOf('/api/session/observe')).toBeGreaterThanOrEqual(0)
      expect(calls.indexOf('/api/session/observe')).toBeLessThan(calls.indexOf('/api/session/override'))
      const [, body, config] = posts('/api/session/override')[0]
      expect(body).toEqual({ requestId: expect.stringMatching(/^[0-9a-f-]{36}$/), expectedRevision: 5, incarnationId: endpoint.incarnationId })
      expect(config).toMatchObject({ headers: { 'X-Baander-Playback-Endpoint': endpoint.capability } })
      expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toMatchObject({ activeIncarnationId: null, desiredState: 'paused', revision: 6 })

      await overridePlayback().catch(() => {})
      const identities = posts('/api/session/override').map(([, request]) => (request as { requestId: string }).requestId)
      expect(new Set(identities).size).toBe(identities.length)
    })

    it('plays the song selection it was refused half a minute before the override was accepted', async () => {
      const now = Date.now()
      const clock = vi.spyOn(Date, 'now').mockReturnValue(now)
      cleanup.push(() => clock.mockRestore())
      const h = await refusedTab()
      await expect(requestPlayback(selection)).rejects.toThrow('Previous playback is unconfirmed.')
      expect(controls()).toEqual([])
      h.server.override = () => { clock.mockReturnValue(now + 30_000) }

      await overridePlayback()

      expect(controls()).toEqual([expect.objectContaining({ action: 'load', media: selection.media, desiredState: 'playing', queue: ['held-song', 'chosen-song'], currentTrackIndex: 1 })])
      expect(posts('/api/session/transfer')[0][1]).toMatchObject({ targetIncarnationId: endpoint.incarnationId })
      expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toHaveProperty('activeIncarnationId', endpoint.incarnationId)
      expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', null)
    })

    it.each([
      [119_000, 'plays'], [120_000, 'plays'], [120_001, 'only takes over'], [600_000, 'only takes over'], [-1, 'only takes over'],
    ] as const)('with a play refused %i ms before the accepted override, it %s', async (age, outcome) => {
      const now = Date.now()
      const clock = vi.spyOn(Date, 'now').mockReturnValue(now)
      cleanup.push(() => clock.mockRestore())
      const h = await refusedTab()
      await expect(requestPlayback({ action: 'play' })).rejects.toThrow('Previous playback is unconfirmed.')
      h.server.override = () => { clock.mockReturnValue(now + age) }

      await overridePlayback()

      expect(controls().map(control => control.action)).toEqual(outcome === 'plays' ? ['play'] : [])
      expect(posts('/api/session/transfer')).toHaveLength(outcome === 'plays' ? 1 : 0)
      expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', null)

      // An old refusal is not kept for a later override either, and the next Play starts by itself.
      h.hold({ ...h.current(), revision: h.current().revision + 1, activeIncarnationId: gone.incarnationId, activeDestinationId: gone.destinationId })
      await overridePlayback()
      expect(controls().map(control => control.action)).toEqual(outcome === 'plays' ? ['play'] : [])
      await requestPlayback({ action: 'play' })
      expect(controls().map(control => control.action)).toEqual(outcome === 'plays' ? ['play', 'play'] : ['play'])
    })

    it.each(['a movie selection', 'a paused queue restore', 'a local transfer'] as const)('starts nothing after an override when %s was what it was refused, and clears the refusal', async refused => {
      const h = await refusedTab()
      await requestPlayback({ action: 'play' }).catch(() => {})
      if (refused === 'a movie selection') {
        await expect(requestPlayback({ action: 'load', media: { kind: 'video', publicId: 'movie' }, desiredState: 'playing' })).rejects.toThrow('Previous playback is unconfirmed.')
      } else if (refused === 'a paused queue restore') {
        await expect(requestPlayback({ ...selection, desiredState: 'paused' })).rejects.toThrow('Previous playback is unconfirmed.')
      } else {
        await expect(transferPlayback(endpoint.incarnationId)).rejects.toThrow('Previous playback is unconfirmed.')
      }
      expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', expect.stringContaining('Play here anyway'))

      await overridePlayback()

      expect(controls()).toEqual([])
      expect(posts('/api/session/transfer')).toEqual([])
      expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', null)
    })

    it('starts nothing when the override left the session with the source of a failed transfer', async () => {
      const source = { ...gone, destinationId: 'source-device', incarnationId: 'source-tab', name: 'Living room', available: true, observedState: 'paused' as const }
      const h = await refusedTab({ activeIncarnationId: source.incarnationId, activeDestinationId: source.destinationId })
      await requestPlayback({ action: 'play' }).catch(() => {})

      await overridePlayback()

      expect(controls()).toEqual([])
      expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toHaveProperty('activeIncarnationId', source.incarnationId)
    })

    it('forgets the refused request when the session is released without an override', async () => {
      const h = await refusedTab()
      await requestPlayback({ action: 'play' }).catch(() => {})

      // The gone runner's own proof released the session; later another runner goes missing.
      h.hold({ ...h.current(), revision: 6, activeIncarnationId: null, activeDestinationId: null, desiredState: 'paused', observedState: 'paused' })
      h.hold({ ...h.current(), revision: 7, activeIncarnationId: gone.incarnationId, activeDestinationId: gone.destinationId })
      await overridePlayback()

      expect(controls()).toEqual([])
    })

    it('forgets the refused request after a later request of the tab succeeded', async () => {
      await refusedTab()
      await requestPlayback({ action: 'play' }).catch(() => {})
      await requestPlayback({ action: 'pause' })

      await overridePlayback()

      expect(controls().map(control => control.action)).toEqual(['pause'])
    })

    it('forgets the refused request when the tab loses its endpoint', async () => {
      const h = await refusedTab()
      await requestPlayback({ action: 'play' }).catch(() => {})
      h.close()
      h.open()
      await vi.waitFor(() => expect(h.transport.send).toHaveBeenCalledTimes(2))
      const registeredAgain = { ...h.current(), destinations: [...succeeded(snapshot()).destinations, gone] }
      h.hold(registeredAgain)
      h.receive({ ...registeredAgain, endpoint: successor })
      await vi.waitFor(() => expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', true))

      await overridePlayback()

      expect(posts('/api/session/override').at(-1)?.[1]).toMatchObject({ incarnationId: successor.incarnationId })
      expect(controls()).toEqual([])
    })

    it('reports the refused request as refused, once, when an earlier result of the tab is still unconfirmed', async () => {
      const h = await refusedTab()
      await requestPlayback({ action: 'play' }).catch(() => {})
      const server = vi.mocked(AXIOS_INSTANCE.post).getMockImplementation()!
      vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async (path, body, config) => {
        if (path === '/api/session/control' && (body as { action: string }).action === 'pause') throw new Error('Connection interrupted')
        return server(path, body, config)
      })
      await expect(requestPlayback({ action: 'pause' })).rejects.toThrow('Connection interrupted')

      await expect(overridePlayback()).rejects.toThrow('A previous playback result is unconfirmed.')

      expect(posts('/api/session/override')).toHaveLength(1)
      expect(h.client.getQueryData(PLAYBACK_SESSION_KEY)).toHaveProperty('activeIncarnationId', null)
      await settled()
      expect(posts('/api/session/override')).toHaveLength(1)
      expect(controls().filter(control => control.action === 'play')).toEqual([])
    })

    it.each([
      'The destination is still connected. Stop playback there.',
      'Playback revision conflict.',
      'No playback is waiting to be overridden.',
    ])('shows the reason the server refused the override with: %s', async message => {
      const h = await refusedTab()
      await requestPlayback({ action: 'play' }).catch(() => {})
      h.server.override = () => { throw refusal(message) }

      await expect(overridePlayback()).rejects.toThrow(message)

      expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', message)
      expect(controls()).toEqual([])
      // The refused Play is still wanted: a second press that the server accepts starts it.
      h.server.override = () => {}
      await overridePlayback()
      expect(controls().map(control => control.action)).toEqual(['play'])
    })

    it('shows that the route is missing when the server does not know the override', async () => {
      const h = await refusedTab()
      h.server.override = () => { throw Object.assign(new Error('Request failed with status code 404'), { response: { status: 404, data: { error: { message: 'Not Found', code: 404 } } } }) }

      await expect(overridePlayback()).rejects.toThrow('Not Found')
      expect(h.client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('error', 'Not Found')
    })

    it('does not take an answer without its own overridden result for an accepted override', async () => {
      const h = await refusedTab()
      await requestPlayback({ action: 'play' }).catch(() => {})
      vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async path => ({ data: { data: path === '/api/session/override'
        ? { ...h.current(), requestResult: { requestId: 'another-request', commandId: 'override', outcome: 'overridden', rejectionReason: null } } : h.current() } }))

      await expect(overridePlayback()).rejects.toThrow('The server did not confirm the override.')
      expect(controls()).toEqual([])
    })

    it('is unavailable without a registered endpoint', async () => {
      const h = harness(); cleanup.push(h.stop, registerPlaybackCoordinator(h.runtime))
      await expect(overridePlayback()).rejects.toThrow('Playback connection is unavailable.')
      expect(posts('/api/session/override')).toEqual([])
    })
  })
})
