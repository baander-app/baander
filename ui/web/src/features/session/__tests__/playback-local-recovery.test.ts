import { QueryClient } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import type { LiveTransportListener, SharedLiveTransport } from '@/shared/live/transport'
import { synchronizeOwnership } from '@/shared/live/ownership'
import { registerPlaybackAdapter } from '../services/playback-execution'
import { PlaybackSessionCoordinator, PLAYBACK_CONNECTION_KEY } from '../services/playback-session-coordinator'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { FakeIndexedDb, installIndexedDb } from '../../../../tests/shared/auth/auth-fakes'
import { IndexedDbPlaybackRecoveryStorage, type PlaybackRecoveryRecord, type PlaybackRecoveryStorage } from '../services/playback-recovery-store'
import { PlaybackLocalRecovery } from '../services/playback-local-recovery'
import { acquirePlaybackLock, playbackLockName } from '../services/playback-execution-lock'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@/shared/live/transport', () => ({ authenticatedTransport: {} }))

const checkpoint = { revision: 4, media: { kind: 'song' as const, publicId: 'song' }, currentTrackIndex: 0, position: 12 }
const record: PlaybackRecoveryRecord = { server: 'https://baander.app/', account: 'listener', tabId: 'old-tab', incarnationId: 'old-incarnation', recoveryCredential: 'secret', recoveryExpiresAt: '2099-01-01T00:00:00Z', eligible: true, checkpoint }
const held = new Set<string>()
const released: (() => void)[] = []
function locks() {
  Object.defineProperty(navigator, 'locks', { configurable: true, value: {
    request: vi.fn(async (name: string, options: { ifAvailable: boolean; steal?: boolean }, callback: (lock: object | null) => Promise<void> | void) => {
      expect(options).toEqual({ mode: 'exclusive', ifAvailable: true })
      if (held.has(name)) return callback(null)
      held.add(name)
      try { await callback({ name }) } finally { held.delete(name) }
    }),
  } })
}
function memory(records: PlaybackRecoveryRecord[] = []): PlaybackRecoveryStorage {
  return {
    list: vi.fn(async (server, account) => records.filter(value => value.server === server && value.account === account)),
    put: vi.fn(async value => { records = [...records.filter(old => old.incarnationId !== value.incarnationId), value] }),
    remove: vi.fn(async value => { records = records.filter(old => old.incarnationId !== value.incarnationId) }),
  }
}
beforeEach(() => { held.clear(); sessionStorage.clear(); locks(); synchronizeOwnership(crypto.randomUUID()); vi.mocked(AXIOS_INSTANCE.post).mockReset(); vi.mocked(AXIOS_INSTANCE.get).mockReset() })
afterEach(() => { released.splice(0).forEach(release => release()); vi.useRealTimers(); vi.unstubAllGlobals(); delete (navigator as { locks?: unknown }).locks })

describe('browser-local recovery', () => {
  it('does not steal a copied tab identity from a live executor', async () => {
    const first = new PlaybackLocalRecovery(record.server, record.account, memory())
    await first.initialize(vi.fn())
    released.push(() => first.dispose())
    const second = new PlaybackLocalRecovery(record.server, record.account, memory())
    await second.initialize(vi.fn())
    released.push(() => second.dispose())
    expect(second.tabId).not.toBe(first.tabId)
    expect(held.size).toBe(2)
    expect(held.has(playbackLockName(record.server, record.account, first.tabId))).toBe(true)
  })

  it('keeps the client identity after reconnect and after releasing the previous page lock', async () => {
    const first = new PlaybackLocalRecovery(record.server, record.account, memory())
    await first.initialize(vi.fn())
    const id = first.tabId
    await first.initialize(vi.fn())
    expect(first.tabId).toBe(id)
    first.dispose()
    const reloaded = new PlaybackLocalRecovery(record.server, record.account, memory())
    released.push(() => reloaded.dispose())
    await reloaded.initialize(vi.fn())
    expect(reloaded.tabId).toBe(id)
  })

  it('keeps a private runtime identity without locks instead of trusting copied storage', async () => {
    delete (navigator as { locks?: unknown }).locks
    sessionStorage.setItem('baander-playback-tab', crypto.randomUUID())
    const first = new PlaybackLocalRecovery(record.server, record.account, memory())
    const second = new PlaybackLocalRecovery(record.server, record.account, memory())
    await first.initialize(vi.fn())
    await second.initialize(vi.fn())
    const id = first.tabId
    await first.initialize(vi.fn())
    expect(first.tabId).toBe(id)
    expect(first.tabId).not.toBe(second.tabId)
    expect(first.tabId).not.toBe(sessionStorage.getItem('baander-playback-tab'))
    expect(first.isEligible()).toBe(false)
    expect(second.isEligible()).toBe(false)
  })

  it('recovers only eligible records whose exact execution lock is acquired', async () => {
    const existing = await acquirePlaybackLock(record.server, record.account, record.tabId)
    released.push(() => existing?.release())
    const storage = memory([record])
    const recover = vi.fn(async () => {})
    const context = new PlaybackLocalRecovery(record.server, record.account, storage)
    released.push(() => context.dispose())
    await context.initialize(recover)
    expect(recover).not.toHaveBeenCalled()
    existing?.release()
    await Promise.resolve()
    await context.initialize(recover)
    expect(recover).toHaveBeenCalledWith(record)
    expect(storage.remove).toHaveBeenCalledWith(record)
    expect(context.getState().status).toBe('recovered')
  })

  it('awaits the recovery record commit before registration can enable execution', async () => {
    let commit!: () => void
    const storage = memory()
    vi.mocked(storage.put).mockImplementation(() => new Promise(resolve => { commit = resolve }))
    const context = new PlaybackLocalRecovery(record.server, record.account, storage)
    released.push(() => context.dispose())
    await context.initialize(vi.fn())
    let enabled = false
    const registration = context.registered({ destinationId: 'destination', incarnationId: 'new', epoch: 2,
      recoveryTabId: context.tabId, recoveryCredential: 'credential', recoveryExpiresAt: record.recoveryExpiresAt }, checkpoint, true).then(() => { enabled = true })
    expect(enabled).toBe(false)
    commit()
    await registration
    expect(enabled).toBe(true)
    vi.mocked(storage.put).mockResolvedValue()
    await context.checkpoint({ ...checkpoint, revision: 5, media: { kind: 'video', publicId: 'movie' }, position: 0 })
  })

  it('retains a consumed record for exact replay if cleanup cannot commit', async () => {
    const storage = memory([record])
    vi.mocked(storage.remove).mockRejectedValue(new Error('Storage unavailable'))
    const context = new PlaybackLocalRecovery(record.server, record.account, storage)
    released.push(() => context.dispose())
    const recover = vi.fn(async () => {})
    await context.initialize(recover)
    expect(context.isEligible()).toBe(false)
    expect(context.getState().status).toBe('unavailable')
    expect(await storage.list(record.server, record.account)).toEqual([record])
  })

  it('does not turn missing lock or storage support into stop proof', async () => {
    delete (navigator as { locks?: unknown }).locks
    const context = new PlaybackLocalRecovery(record.server, record.account, memory([record]))
    const recover = vi.fn(async () => {})
    await context.initialize(recover)
    expect(context.isEligible()).toBe(false)
    expect(recover).not.toHaveBeenCalled()
    expect(context.getState().status).toBe('unavailable')
  })

  it('leaves execution ineligible when a recovery record cannot commit', async () => {
    const storage = memory()
    vi.mocked(storage.put).mockRejectedValue(new Error('Quota exceeded'))
    const context = new PlaybackLocalRecovery(record.server, record.account, storage)
    released.push(() => context.dispose())
    await context.initialize(vi.fn())
    await expect(context.registered({ destinationId: 'device', incarnationId: 'new', epoch: 1,
      recoveryTabId: context.tabId, recoveryCredential: 'secret', recoveryExpiresAt: record.recoveryExpiresAt }, checkpoint, true)).rejects.toThrow('Quota exceeded')
    expect(context.isEligible()).toBe(false)
    expect(context.getState().status).toBe('unavailable')
  })

  it('does not use expired recovery authority', async () => {
    const expired = { ...record, recoveryExpiresAt: '2000-01-01T00:00:00Z' }
    const storage = memory([expired])
    const context = new PlaybackLocalRecovery(record.server, record.account, storage)
    released.push(() => context.dispose())
    const recover = vi.fn(async () => {})
    await context.initialize(recover)
    expect(recover).not.toHaveBeenCalled()
    expect(storage.remove).not.toHaveBeenCalled()
    expect(context.getState().status).toBe('unavailable')
  })

  it('cancels initialization without recovering another account after disposal', async () => {
    let finish!: (records: PlaybackRecoveryRecord[]) => void
    const storage = memory()
    vi.mocked(storage.list).mockImplementation(() => new Promise(resolve => { finish = resolve }))
    const context = new PlaybackLocalRecovery(record.server, record.account, storage)
    const recover = vi.fn(async () => {})
    const initializing = context.initialize(recover).catch(error => error)
    await vi.waitFor(() => expect(finish).toBeDefined())
    context.dispose()
    finish([record])
    expect(await initializing).toBeInstanceOf(Error)
    expect(recover).not.toHaveBeenCalled()
  })

  it('keeps failed network recovery retryable without deleting its credential', async () => {
    const storage = memory([record])
    const context = new PlaybackLocalRecovery(record.server, record.account, storage)
    released.push(() => context.dispose())
    await expect(context.initialize(async () => { throw new Error('Connection lost') })).rejects.toThrow('Connection lost')
    expect(context.getState().status).toBe('retryable')
    expect(storage.remove).not.toHaveBeenCalled()
  })
})

describe('recovery IndexedDB transactions', () => {
  let database: FakeIndexedDb
  beforeEach(() => { database = installIndexedDb().db })
  it('keeps account records isolated and preserves other incarnations on updates', async () => {
    const storage = new IndexedDbPlaybackRecoveryStorage()
    await storage.put(record)
    await storage.put({ ...record, incarnationId: 'second' })
    await storage.put({ ...record, incarnationId: 'other-account', account: 'another-listener' })
    await storage.remove(record)
    expect(await storage.list(record.server, record.account)).toEqual([expect.objectContaining({ incarnationId: 'second' })])
    expect(await storage.list(record.server, 'another-listener')).toHaveLength(1)
    expect(database.log.filter(entry => entry.operation === 'put')).toHaveLength(4)
  })
})

function state(overrides: Partial<PlaybackSnapshotResource> = {}): PlaybackSnapshotResource {
  return { schemaVersion: 1, publicId: 'session', revision: 1, media: null, queue: [], currentTrackIndex: 0,
    position: 0, playbackState: 'stopped', desiredState: 'stopped', observedState: 'stopped',
    selectedDestinationId: null, activeDestinationId: null, activeIncarnationId: null,
    transfer: null, destinations: [], commands: [], ...overrides }
}

it.each([1, 2])('discards an observation captured at revision %i before activation while its recovery checkpoint commits', async revision => {
  vi.useFakeTimers()
  let listener: LiveTransportListener = {}
  const transport = { subscribe: (value: LiveTransportListener) => { listener = value; return () => {} },
    retain: () => () => {}, isConnected: () => true, send: vi.fn() }
  const storage = memory()
  const context = new PlaybackLocalRecovery(record.server, record.account, storage)
  const unregister = registerPlaybackAdapter({ kind: 'video', supportsVolume: true, prepare: vi.fn(async () => {}),
    execute: vi.fn(async () => ({ observedState: 'paused' as const, position: 12 })),
    quiesce: vi.fn(async () => ({ observedState: 'stopped' as const, position: 12 })),
    observe: () => ({ observedState: 'paused', position: 12 }),
    localRecovery: { eligible: true, fence: () => ({ observedState: 'stopped', position: 12 }), rearmAfterRegistration: vi.fn() } })
  const client = new QueryClient()
  const runtime = new PlaybackSessionCoordinator(client, transport as unknown as SharedLiveTransport, context)
  let current = state({ revision, media: revision === 1 ? { kind: 'song', publicId: 'previous-song' }
    : { kind: 'video', publicId: 'next-video' }, desiredState: 'paused', position: 0 })
  vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async () => ({ data: { data: current } }))
  const stop = runtime.start()
  released.push(() => { stop(); context.dispose(); unregister() })
  await vi.waitFor(() => expect(transport.send).toHaveBeenCalled())
  const endpoint = { destinationId: 'destination', incarnationId: 'registered', epoch: 1, capability: 'execution-secret',
    recoveryTabId: context.tabId, recoveryCredential: 'recovery-secret', recoveryExpiresAt: record.recoveryExpiresAt }
  listener.onMessage?.({ type: 'session.snapshot', data: { ...current, endpoint } })
  await vi.waitFor(() => expect(client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', true))

  const persist = vi.mocked(storage.put).getMockImplementation()!
  let commit!: () => void
  vi.mocked(storage.put).mockImplementationOnce(value => new Promise<void>(resolve => { commit = resolve }).then(() => persist(value)))
  await vi.advanceTimersByTimeAsync(10_000)
  expect(commit).toBeDefined()
  const command = { id: 'start-video', attemptId: 'transfer', revision: 2, incarnationId: endpoint.incarnationId,
    epoch: endpoint.epoch, type: 'start' as const, expiresAt: new Date(Date.now() + 60_000).toISOString(),
    payload: { media: { kind: 'video' as const, publicId: 'next-video' }, queue: [], currentTrackIndex: 0, position: 12, desiredState: 'paused' as const } }
  current = state({ revision: 2, media: command.payload.media, position: 12, desiredState: 'paused', activeIncarnationId: endpoint.incarnationId })
  listener.onMessage?.({ type: 'session.snapshot', data: { ...current, commands: [command] } })
  commit()
  await vi.waitFor(() => expect(AXIOS_INSTANCE.post).toHaveBeenCalledWith('/api/session/ack', expect.anything(), expect.anything()))
  await vi.waitFor(() => expect(AXIOS_INSTANCE.post).toHaveBeenCalledWith('/api/session/observe',
    expect.objectContaining({ expectedRevision: 2, position: 12 }), expect.anything()))

  const observations = vi.mocked(AXIOS_INSTANCE.post).mock.calls.filter(([path]) => path === '/api/session/observe')
  expect(observations.every(([, body]) => (body as { position: number }).position === 12)).toBe(true)
  expect(await storage.list(record.server, record.account)).toEqual([
    expect.objectContaining({ checkpoint: expect.objectContaining({ revision: 2, media: command.payload.media, position: 12 }) }),
  ])
})

it('holds execution until its registration record commits, then fences pagehide before releasing its lock', async () => {
  let listener: LiveTransportListener = {}
  const send = vi.fn()
  const transport = { subscribe: (value: LiveTransportListener) => { listener = value; return () => {} }, retain: () => () => {}, isConnected: () => true, send }
  const storage = memory()
  let commit!: () => void
  const persist = vi.mocked(storage.put).getMockImplementation()!
  vi.mocked(storage.put).mockImplementationOnce(value => new Promise<void>(resolve => { commit = resolve }).then(() => persist(value)))
  const context = new PlaybackLocalRecovery(record.server, record.account, storage)
  const fence = vi.fn(() => ({ observedState: 'stopped' as const, position: 12 }))
  const rearm = vi.fn()
  const execute = vi.fn(async () => ({ observedState: 'playing' as const, position: 12 }))
  const unregister = registerPlaybackAdapter({ kind: 'song', supportsVolume: true, prepare: vi.fn(async () => {}), execute,
    quiesce: vi.fn(async () => ({ observedState: 'stopped' as const, position: 12 })),
    observe: () => ({ observedState: 'playing', position: 12 }), localRecovery: { eligible: true, fence, rearmAfterRegistration: rearm } })
  const client = new QueryClient()
  const runtime = new PlaybackSessionCoordinator(client, transport as unknown as SharedLiveTransport, context)
  vi.mocked(AXIOS_INSTANCE.post).mockResolvedValue({ data: { data: state() } })
  const stop = runtime.start()
  released.push(() => { stop(); context.dispose(); unregister() })
  await vi.waitFor(() => expect(send).toHaveBeenCalledWith(expect.objectContaining({ localRecoveryEligible: true, tabId: context.tabId })))
  const endpoint = { destinationId: 'destination', incarnationId: 'registered', epoch: 1, capability: 'execution-secret',
    recoveryTabId: context.tabId, recoveryCredential: 'recovery-secret', recoveryExpiresAt: record.recoveryExpiresAt }
  listener.onMessage?.({ type: 'session.snapshot', data: state({ endpoint }) })
  await vi.waitFor(() => expect(commit).toBeDefined())
  expect(client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', false)
  expect(rearm).not.toHaveBeenCalled()
  commit()
  await vi.waitFor(() => expect(rearm).toHaveBeenCalledTimes(1))
  expect(client.getQueryData(PLAYBACK_CONNECTION_KEY)).not.toHaveProperty('endpoint.recoveryCredential')
  const command = { id: 'start', attemptId: 'attempt', revision: 1, incarnationId: 'registered', epoch: 1, type: 'start' as const,
    expiresAt: new Date(Date.now() + 60_000).toISOString(), payload: { media: { kind: 'song' as const, publicId: 'new-song' }, queue: ['new-song'], currentTrackIndex: 0, position: 12, desiredState: 'playing' as const } }
  const playing = state({ media: command.payload.media, queue: command.payload.queue, desiredState: 'playing', observedState: 'playing' })
  vi.mocked(AXIOS_INSTANCE.post).mockResolvedValue({ data: { data: playing } })
  listener.onMessage?.({ type: 'session.snapshot', data: { ...playing, commands: [command] } })
  await vi.waitFor(() => expect(execute).toHaveBeenCalledTimes(1))
  expect(storage.put).toHaveBeenLastCalledWith(expect.objectContaining({ checkpoint: expect.objectContaining({ media: { kind: 'song', publicId: 'new-song' } }) }))
  window.dispatchEvent(new Event('pagehide'))
  expect(fence).toHaveBeenCalled()
  expect(held.has(playbackLockName(record.server, record.account, context.tabId))).toBe(true)
  await vi.waitFor(() => expect(held.has(playbackLockName(record.server, record.account, context.tabId))).toBe(false))
  expect(client.getQueryData(PLAYBACK_CONNECTION_KEY)).toHaveProperty('connected', false)
  const recovered = state({ ...playing, revision: 2, desiredState: 'paused', observedState: 'stopped' })
  vi.mocked(AXIOS_INSTANCE.post).mockImplementation(async path => ({ data: { data: path === '/api/session/recover-local'
    ? { ...recovered, recoveryResult: { incarnationId: 'registered', outcome: 'recovered', revision: 2 } } : recovered } }))
  window.dispatchEvent(new Event('pageshow'))
  await vi.waitFor(() => expect(send).toHaveBeenCalledTimes(2))
  expect(rearm).toHaveBeenCalledTimes(1)
  expect(execute).toHaveBeenCalledTimes(1)
  listener.onMessage?.({ type: 'session.snapshot', data: { ...recovered, endpoint: { ...endpoint, incarnationId: 'replacement', epoch: 2, recoveryCredential: 'replacement-secret' } } })
  await vi.waitFor(() => expect(rearm).toHaveBeenCalledTimes(2))
  expect(execute).toHaveBeenCalledTimes(1)
})
