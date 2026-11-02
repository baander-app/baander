import { act, cleanup, render, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { VideoPlaybackRuntime } from '@/features/catalog/components/VideoPlaybackRuntime'
import { useVideoPresentation } from '@/features/catalog/stores/video-presentation-store'
import { getPlaybackAdapter } from '@/features/session/services/playback-execution'
import type { PlaybackCommandResource } from '@/shared/api-client/gen/endpoints'

const { execution } = vi.hoisted(() => ({ execution: {
  ready: vi.fn(), execute: vi.fn(), quiesce: vi.fn(),
  observe: vi.fn(() => ({ observedState: 'paused' as const, position: 0 })),
} }))
vi.mock('@/features/catalog/hooks/useBaanderPlayer', () => ({
  useBaanderPlayer: () => ({ execution, currentTime: 0, duration: 180, pendingSeekPosition: null, state: 'paused' }),
}))
const payload: PlaybackCommandResource['payload'] = {
  media: { kind: 'video', publicId: 'video' }, queue: [], currentTrackIndex: 0, position: 0, desiredState: 'playing',
}
const signal = () => new AbortController().signal

beforeEach(() => {
  vi.clearAllMocks()
  execution.ready.mockResolvedValue(undefined)
  execution.quiesce.mockResolvedValue({ observedState: 'paused', position: 0 })
  execution.execute.mockResolvedValue({ observedState: 'playing', position: 0 })
  useVideoPresentation.getState().select('')
})
afterEach(cleanup)

describe('persistent video execution', () => {
  it('registers one shell adapter and unregisters the same owner on teardown', () => {
    const view = render(<VideoPlaybackRuntime />)
    expect(getPlaybackAdapter('video')).toBeDefined()
    expect(useVideoPresentation.getState().host).toBeInstanceOf(HTMLDivElement)
    view.unmount()
    expect(getPlaybackAdapter('video')).toBeUndefined()
    expect(useVideoPresentation.getState().host).toBeNull()
  })

  it('prepares without play and waits for the actual source readiness', async () => {
    let ready!: () => void
    execution.ready.mockImplementation(() => new Promise<void>(resolve => { ready = resolve }))
    render(<VideoPlaybackRuntime />)
    const prepared = vi.fn()
    let preparation!: Promise<void>
    act(() => { preparation = getPlaybackAdapter('video')!.prepare(payload, signal()).then(prepared) })
    await waitFor(() => expect(execution.ready).toHaveBeenCalledOnce())
    expect(prepared).not.toHaveBeenCalled()
    expect(execution.execute).not.toHaveBeenCalled()
    await act(async () => { ready(); await preparation })
    expect(prepared).toHaveBeenCalledOnce()
  })

  it('observes a queue-only update without changing the video execution', async () => {
    render(<VideoPlaybackRuntime />)
    const observation = execution.observe()
    execution.observe.mockClear()
    const command: PlaybackCommandResource = {
      id: 'queue-command', attemptId: 'queue-attempt', revision: 1, incarnationId: 'browser', epoch: 1,
      type: 'queue', payload: { ...payload, queue: ['song-one', 'song-two'] }, expiresAt: '2099-01-01T00:00:00Z',
    }
    await expect(getPlaybackAdapter('video')!.execute(command, signal())).resolves.toEqual(observation)
    expect(execution.observe).toHaveBeenCalledOnce()
    expect(execution.ready).not.toHaveBeenCalled()
    expect(execution.execute).not.toHaveBeenCalled()
    expect(execution.quiesce).not.toHaveBeenCalled()
  })

  it.each(['load', 'start'] as const)('settles a %s with stopped intent by releasing the prepared source', async type => {
    execution.execute.mockResolvedValue({ observedState: 'stopped', position: 0 })
    render(<VideoPlaybackRuntime />)
    const command: PlaybackCommandResource = {
      id: 'stopped-command', attemptId: 'stopped-attempt', revision: 1, incarnationId: 'browser', epoch: 1,
      type, payload: { ...payload, desiredState: 'stopped' }, expiresAt: '2099-01-01T00:00:00Z',
    }
    let result!: Promise<unknown>
    act(() => { result = getPlaybackAdapter('video')!.execute(command, signal()) })
    await act(async () => { await expect(result).resolves.toEqual({ observedState: 'stopped', position: 0 }) })
    expect(execution.execute).toHaveBeenCalledExactlyOnceWith('stop', undefined, expect.any(AbortSignal))
    expect(useVideoPresentation.getState().videoId).toBe('')
  })

  it('retains a stopped observation and prepares the selected video again before replay', async () => {
    execution.execute.mockImplementation(async action => ({ observedState: action === 'stop' ? 'stopped' : 'playing', position: action === 'stop' ? 42 : 0 }))
    render(<VideoPlaybackRuntime />)
    const adapter = getPlaybackAdapter('video')!
    const command: PlaybackCommandResource = {
      id: 'stopped-command', attemptId: 'stopped-attempt', revision: 1, incarnationId: 'browser', epoch: 1,
      type: 'start', payload: { ...payload, desiredState: 'stopped' }, expiresAt: '2099-01-01T00:00:00Z',
    }
    let stopped!: Promise<unknown>
    act(() => { stopped = adapter.execute(command, signal()) })
    await act(async () => { await stopped })
    expect(adapter.observe()).toMatchObject({ observedState: 'stopped', position: 42 })
    await expect(adapter.quiesce(signal())).resolves.toMatchObject({ observedState: 'stopped', position: 42 })
    await adapter.execute({ ...command, type: 'stop' }, signal())
    expect(execution.execute).toHaveBeenCalledTimes(1)
    execution.ready.mockClear()
    let replay!: Promise<unknown>
    act(() => { replay = adapter.execute({ ...command, type: 'play', payload }, signal()) })
    await act(async () => { await replay })
    expect(execution.ready).toHaveBeenCalledOnce()
    expect(execution.execute).toHaveBeenLastCalledWith('play', undefined, expect.any(AbortSignal))
    expect(useVideoPresentation.getState().videoId).toBe('video')
  })

  it('does not acknowledge a rejected native start', async () => {
    execution.execute.mockRejectedValue(new Error('Native play rejected'))
    render(<VideoPlaybackRuntime />)
    const command: PlaybackCommandResource = {
      id: 'command', attemptId: 'attempt', revision: 1, incarnationId: 'browser', epoch: 1,
      type: 'start', payload, expiresAt: '2099-01-01T00:00:00Z',
    }
    let start!: Promise<unknown>
    act(() => { start = getPlaybackAdapter('video')!.execute(command, signal()) })
    await act(async () => { await expect(start).rejects.toThrow('Native play rejected') })
  })
})
