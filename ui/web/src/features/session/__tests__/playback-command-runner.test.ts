import { afterEach, describe, expect, it, vi } from 'vitest'
import type { PlaybackCommandResource } from '@/shared/api-client/gen/endpoints'
import { registerPlaybackAdapter, type PlaybackObservation } from '../services/playback-execution'
import { PlaybackCommandRunner } from '../services/playback-command-runner'
import { MediaCompatibilityError } from '@/features/player/services/media-delivery'

const command = (type: PlaybackCommandResource['type'] = 'start'): PlaybackCommandResource => ({
  id: 'command', attemptId: 'attempt', revision: 4, incarnationId: 'endpoint', epoch: 2,
  type, expiresAt: new Date(Date.now() + 60_000).toISOString(),
  payload: { media: { kind: 'song', publicId: 'song' }, queue: ['song'], currentTrackIndex: 0, position: 12, desiredState: 'playing' },
})
const observation: PlaybackObservation = { observedState: 'playing', position: 12 }
let remove: (() => void) | undefined
afterEach(() => remove?.())
function setup() {
  const adapter = { kind: 'song' as const, supportsVolume: true, prepare: vi.fn(async () => {}), execute: vi.fn(async () => observation), quiesce: vi.fn(async (): Promise<PlaybackObservation> => ({ observedState: 'stopped', position: 14 })), observe: vi.fn(() => observation) }
  remove = registerPlaybackAdapter(adapter)
  const ack = vi.fn(async () => {})
  const runner = new PlaybackCommandRunner(ack)
  runner.bind({ incarnationId: 'endpoint', epoch: 2, destinationId: 'device' })
  return { adapter, ack, runner }
}

describe('playback command execution', () => {
  it('retains a terminal media reason when destination preparation fails and is replayed', async () => {
    const { runner, adapter, ack } = setup()
    adapter.prepare.mockRejectedValue(new MediaCompatibilityError('audio_codec_unsupported'))
    await runner.accept(command('prepare'), 4)
    await runner.accept(command('prepare'), 4)
    expect(adapter.prepare).toHaveBeenCalledTimes(1)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({
      outcome: 'rejected', rejectionReason: 'audio_codec_unsupported',
    }))
    expect(ack.mock.calls[0]).toEqual(ack.mock.calls[1])
  })

  it('prepares and starts an idle stopped destination without starting native media', async () => {
    const { runner, adapter, ack } = setup()
    const idle = { ...command('prepare'), payload: { ...command().payload, media: null, queue: [], position: 0, desiredState: 'stopped' as const } }
    await runner.accept(idle, 4)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'prepared', position: 0 }))
    await runner.accept({ ...idle, id: 'idle-start', type: 'start' }, 4)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'started', position: 0 }))
    expect(adapter.prepare).not.toHaveBeenCalled()
    expect(adapter.execute).not.toHaveBeenCalled()
    expect(runner.observe()).toEqual({ observedState: 'stopped', position: 0 })
  })

  it('retains a non-playing paused idle state without creating native playback', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept({ ...command('start'), payload: { ...command().payload, media: null, queue: [], desiredState: 'paused' } }, 4)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'started' }))
    expect(runner.observe()?.observedState).toBe('paused')
    expect(adapter.execute).not.toHaveBeenCalled()
    expect((await runner.disconnect())?.observedState).toBe('stopped')
  })

  it('quiesces previously owned media before preparing an idle destination', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept(command(), 4)
    const idle = { ...command('prepare'), id: 'idle', payload: { ...command().payload, media: null, queue: [], desiredState: 'stopped' as const } }
    await runner.accept(idle, 4)
    expect(adapter.quiesce).toHaveBeenCalledTimes(1)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'prepared', position: 14 }))
    expect(runner.observe()).toEqual({ observedState: 'stopped', position: 14 })
  })

  it('does not confirm an idle destination if its previously owned media fails to stop', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept(command(), 4)
    adapter.quiesce.mockRejectedValue(new Error('Could not stop'))
    await runner.accept({ ...command('prepare'), id: 'idle', payload: { ...command().payload, media: null, queue: [], desiredState: 'stopped' } }, 4)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'rejected' }))
  })

  it('rejects an idle request that claims media is playing', async () => {
    const { runner, ack } = setup()
    await runner.accept({ ...command('start'), payload: { ...command().payload, media: null, queue: [] } }, 4)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'rejected' }))
  })

  it('deduplicates native effects and replays the exact acknowledgement', async () => {
    const { runner, adapter, ack } = setup()
    await Promise.all([runner.accept(command(), 4), runner.accept(command(), 4)])
    expect(adapter.execute).toHaveBeenCalledTimes(1)
    expect(ack).toHaveBeenCalledTimes(2)
    expect(ack.mock.calls[0]).toEqual(ack.mock.calls[1])
  })

  it('does not acknowledge a pending native play as started', async () => {
    const { runner, adapter, ack } = setup()
    let resolve!: (value: typeof observation) => void
    adapter.execute.mockImplementation(() => new Promise(done => { resolve = done }))
    const execution = runner.accept(command(), 4)
    await vi.waitFor(() => expect(adapter.execute).toHaveBeenCalled())
    expect(ack).not.toHaveBeenCalled()
    resolve(observation)
    await execution
    expect(ack).toHaveBeenCalledWith(expect.objectContaining({ outcome: 'started', position: 12 }))
  })

  it('rejects autoplay failure and preserves the failure on replay', async () => {
    const { runner, adapter, ack } = setup()
    adapter.execute.mockRejectedValue(new DOMException('Gesture required', 'NotAllowedError'))
    await runner.accept(command(), 4)
    await runner.accept(command(), 4)
    expect(adapter.execute).toHaveBeenCalledTimes(1)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'rejected' }))
  })

  it('executes stop through the adapter and requires confirmed silence', async () => {
    const { runner, adapter, ack } = setup()
    adapter.execute.mockResolvedValue({ observedState: 'stopped', position: 12 })
    await runner.accept(command('stop'), 4)
    expect(adapter.execute).toHaveBeenCalledWith(expect.objectContaining({ type: 'stop' }), expect.any(AbortSignal))
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'stopped' }))
  })

  it('rejects volume when the adapter does not report an actual normalized value', async () => {
    const { runner, ack } = setup()
    await runner.accept({ ...command('volume'), payload: { ...command().payload, volume: 0.5 } }, 4)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'rejected' }))
  })

  it('rejects load when the native state does not match its desired state', async () => {
    const { runner, adapter, ack } = setup()
    adapter.execute.mockResolvedValue({ observedState: 'paused', position: 12 })
    await runner.accept(command('load'), 4)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'rejected' }))
  })

  it('fences expired, superseded and different incarnation commands', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept({ ...command(), expiresAt: new Date(0).toISOString() }, 4)
    await runner.accept(command(), 5)
    await runner.accept(command(), 4)
    await runner.accept({ ...command(), incarnationId: 'old' }, 4)
    expect(adapter.execute).not.toHaveBeenCalled()
    expect(ack).not.toHaveBeenCalled()
  })

  it.each([
    { incarnationId: 'another-endpoint' },
    { epoch: 3 },
  ])('rejects another endpoint authority at the current revision: %j', async authority => {
    const { runner, adapter, ack } = setup()
    await runner.accept({ ...command(), ...authority }, 4)
    expect(adapter.execute).not.toHaveBeenCalled()
    expect(ack).not.toHaveBeenCalled()

    await runner.accept(command(), 4)
    expect(adapter.execute).toHaveBeenCalledTimes(1)
    expect(ack).toHaveBeenCalledWith(expect.objectContaining({ outcome: 'started' }))
  })

  it('prepares without play, quiesces with actual cursor, then starts', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept({ ...command('prepare'), id: 'prepare' }, 4)
    expect(adapter.prepare).toHaveBeenCalledTimes(1)
    expect(adapter.execute).not.toHaveBeenCalled()
    await runner.accept({ ...command('quiesce'), id: 'quiesce' }, 4)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ outcome: 'quiesced', position: 14 }))
    await runner.accept(command(), 4)
    expect(adapter.execute).toHaveBeenCalledTimes(1)
  })

  it('drops media it only prepared without acknowledging, and then reports itself stopped', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept({ ...command('prepare'), id: 'prepare' }, 4)
    ack.mockClear()
    adapter.quiesce.mockResolvedValueOnce({ observedState: 'paused', position: 12 })
    adapter.execute.mockResolvedValueOnce({ observedState: 'stopped', position: 12 })

    await runner.dropPreparation()

    expect(adapter.quiesce).toHaveBeenCalledOnce()
    expect(adapter.execute).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({ type: 'stop', id: 'prepare' }), expect.anything())
    expect(ack).not.toHaveBeenCalled()
    expect(runner.observe()).toEqual({ observedState: 'stopped', position: 12 })

    await runner.dropPreparation()
    expect(adapter.quiesce).toHaveBeenCalledOnce()
  })

  it('does not treat media a later command started as a preparation', async () => {
    const { runner, adapter } = setup()
    await runner.accept({ ...command('prepare'), id: 'prepare' }, 4)
    await runner.accept(command('start'), 4)
    adapter.execute.mockClear()

    await runner.dropPreparation()

    expect(adapter.quiesce).not.toHaveBeenCalled()
    expect(adapter.execute).not.toHaveBeenCalled()
    expect(runner.observe()).toEqual(observation)
  })

  it('makes the next command wait until a dropped preparation has let go of the player', async () => {
    const { runner, adapter } = setup()
    await runner.accept({ ...command('prepare'), id: 'prepare' }, 4)
    let release!: (value: PlaybackObservation) => void
    adapter.quiesce.mockImplementationOnce(() => new Promise<PlaybackObservation>(resolve => { release = resolve }))
    const dropped = runner.dropPreparation()
    adapter.prepare.mockClear()

    const next = runner.accept({ ...command('prepare'), id: 'next', revision: 5 }, 5)
    await Promise.resolve()
    expect(adapter.prepare).not.toHaveBeenCalled()

    release({ observedState: 'stopped', position: 12 })
    await dropped
    await next
    expect(adapter.prepare).toHaveBeenCalledOnce()
    expect(runner.observe()).toEqual(observation)
  })

  it('silences media it still plays for a session that moved on, without acknowledging, and lets go of the player', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept(command('play'), 4)
    ack.mockClear()
    adapter.execute.mockClear()
    adapter.quiesce.mockResolvedValueOnce({ observedState: 'paused', position: 14 })

    await runner.silenceLostPlayback()

    expect(adapter.quiesce).toHaveBeenCalledOnce()
    // A quiesce that silenced the player is enough: a stop would close a video that is only paused now.
    expect(adapter.execute).not.toHaveBeenCalled()
    expect(ack).not.toHaveBeenCalled()
    expect(runner.observe()).toEqual({ observedState: 'paused', position: 14 })

    await runner.silenceLostPlayback()
    expect(adapter.quiesce).toHaveBeenCalledOnce()
  })

  it('stops lost media that keeps playing after the quiesce', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept(command('play'), 4)
    ack.mockClear()
    adapter.execute.mockClear()
    adapter.quiesce.mockResolvedValueOnce({ observedState: 'playing', position: 14 })
    adapter.execute.mockResolvedValueOnce({ observedState: 'stopped', position: 14 })

    await runner.silenceLostPlayback()

    expect(adapter.execute).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({ type: 'stop', id: 'command' }), expect.anything())
    expect(ack).not.toHaveBeenCalled()
    expect(runner.observe()).toEqual({ observedState: 'stopped', position: 14 })
  })

  it.each(['paused', 'stopped'] as const)('leaves a %s player it holds for a session that moved on exactly as it is', async observedState => {
    const { runner, adapter } = setup()
    await runner.accept(command('play'), 4)
    adapter.execute.mockClear()
    adapter.observe.mockReturnValue({ observedState, position: 12 })

    await runner.silenceLostPlayback()

    expect(adapter.quiesce).not.toHaveBeenCalled()
    expect(adapter.execute).not.toHaveBeenCalled()
    // The player stays bound: silencing it later still finds it.
    adapter.quiesce.mockResolvedValueOnce({ observedState, position: 12 })
    await expect(runner.silence()).resolves.toEqual({ observedState, position: 12 })
    expect(adapter.quiesce).toHaveBeenCalledOnce()
  })

  it('does not treat a preparation as lost playback', async () => {
    const { runner, adapter } = setup()
    await runner.accept({ ...command('prepare'), id: 'prepare' }, 4)

    await runner.silenceLostPlayback()

    expect(adapter.quiesce).not.toHaveBeenCalled()
    expect(adapter.execute).not.toHaveBeenCalled()
  })

  it('makes the next command wait until lost playback has been silenced, and keeps what that command prepares', async () => {
    const { runner, adapter } = setup()
    await runner.accept(command('play'), 4)
    let release!: (value: PlaybackObservation) => void
    adapter.quiesce.mockImplementationOnce(() => new Promise<PlaybackObservation>(resolve => { release = resolve }))
    const silenced = runner.silenceLostPlayback()

    const next = runner.accept({ ...command('prepare'), id: 'next', revision: 5 }, 5)
    await Promise.resolve()
    expect(adapter.prepare).not.toHaveBeenCalled()

    release({ observedState: 'paused', position: 14 })
    await silenced
    await next
    expect(adapter.prepare).toHaveBeenCalledOnce()
    expect(runner.observe()).toEqual(observation)
  })

  it('silences the bound adapter without acknowledging and keeps it bound', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept(command('play'), 4)
    ack.mockClear()
    adapter.execute.mockClear()
    adapter.quiesce.mockResolvedValueOnce({ observedState: 'paused', position: 14 })

    await expect(runner.silence()).resolves.toEqual({ observedState: 'paused', position: 14 })

    expect(adapter.quiesce).toHaveBeenCalledOnce()
    expect(adapter.execute).not.toHaveBeenCalled()
    expect(ack).not.toHaveBeenCalled()
    expect(runner.observe()).toEqual(observation)
  })

  it('stops media that keeps playing after a silencing quiesce', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept(command('play'), 4)
    ack.mockClear()
    adapter.execute.mockClear()
    adapter.quiesce.mockResolvedValueOnce({ observedState: 'playing', position: 14 })
    adapter.execute.mockResolvedValueOnce({ observedState: 'stopped', position: 14 })

    await expect(runner.silence()).resolves.toEqual({ observedState: 'stopped', position: 14 })

    expect(adapter.execute).toHaveBeenCalledWith(expect.objectContaining({ type: 'stop', id: 'command' }), expect.anything())
    expect(ack).not.toHaveBeenCalled()
  })

  it('has nothing to silence before it has played anything', async () => {
    const { runner, adapter } = setup()

    await expect(runner.silence()).resolves.toBeNull()

    expect(adapter.quiesce).not.toHaveBeenCalled()
  })

  it('answers a command delivered again for the next incarnation from the effect it already has', async () => {
    const { runner, adapter, ack } = setup()
    await runner.accept(command('play'), 4)
    expect(ack).toHaveBeenLastCalledWith(expect.objectContaining({ commandId: 'command', incarnationId: 'endpoint', epoch: 2, outcome: 'playing' }))

    runner.bind({ incarnationId: 'next-endpoint', epoch: 3, destinationId: 'device' })
    await runner.accept({ ...command('play'), incarnationId: 'next-endpoint', epoch: 3 }, 4)

    expect(adapter.execute).toHaveBeenCalledTimes(1)
    expect(ack).toHaveBeenCalledTimes(2)
    expect(ack).toHaveBeenLastCalledWith({
      commandId: 'command', attemptId: 'attempt', revision: 4, incarnationId: 'next-endpoint', epoch: 3, outcome: 'playing', position: 12,
    })
  })

  it('does not answer for the earlier incarnation once the next one is bound', async () => {
    const { runner, adapter, ack } = setup()
    let resolve!: (value: typeof observation) => void
    adapter.execute.mockImplementation(() => new Promise(done => { resolve = done }))
    const first = runner.accept(command('play'), 4)
    await vi.waitFor(() => expect(adapter.execute).toHaveBeenCalled())

    runner.bind({ incarnationId: 'next-endpoint', epoch: 3, destinationId: 'device' })
    const again = runner.accept({ ...command('play'), incarnationId: 'next-endpoint', epoch: 3 }, 4)
    resolve(observation)
    await Promise.all([first, again])

    expect(adapter.execute).toHaveBeenCalledTimes(1)
    expect(ack).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({ incarnationId: 'next-endpoint', epoch: 3, outcome: 'playing' }))
  })

  it('reports the cursor of a silenced player whose stop is refused at a disconnect', async () => {
    const { runner, adapter } = setup()
    await runner.accept(command('play'), 4)
    adapter.quiesce.mockResolvedValueOnce({ observedState: 'paused', position: 14 })
    adapter.execute.mockRejectedValueOnce(new DOMException('Playback execution was cancelled.', 'AbortError'))

    await expect(runner.disconnect()).resolves.toEqual({ observedState: 'paused', position: 14 })
  })

  it('reports a refused stop of a player that keeps playing at a disconnect', async () => {
    const { runner, adapter } = setup()
    await runner.accept(command('play'), 4)
    adapter.quiesce.mockResolvedValueOnce({ observedState: 'playing', position: 14 })
    adapter.execute.mockRejectedValueOnce(new Error('The element is gone.'))

    await expect(runner.disconnect()).rejects.toThrow('The element is gone.')
  })

  it('stops owned media on connection replacement and suppresses late success', async () => {
    const { runner, adapter, ack } = setup()
    let resolve!: (value: typeof observation) => void
    adapter.execute.mockImplementation(() => new Promise(done => { resolve = done }))
    const execution = runner.accept(command(), 4)
    await vi.waitFor(() => expect(adapter.execute).toHaveBeenCalled())
    await runner.disconnect()
    resolve(observation)
    await execution
    expect(adapter.quiesce).toHaveBeenCalled()
    expect(ack).not.toHaveBeenCalled()
  })
})
