import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import type { PlaybackCommandResource } from '@/shared/api-client/gen/endpoints'
import type { PlaybackExecutionAdapter, PlaybackObservation } from '@/features/session/services/playback-execution'
import { useVideoPresentation } from '../../stores/video-presentation-store'
import { VideoPlaybackRuntime } from '../VideoPlaybackRuntime'

const { execution, adapters } = vi.hoisted(() => ({
  adapters: [] as PlaybackExecutionAdapter[],
  execution: {
    localRecovery: { eligible: false, fence: () => null, rearmAfterRegistration: () => {} },
    ready: vi.fn(),
    prepare: vi.fn(),
    execute: vi.fn(),
    quiesce: vi.fn(),
    observe: vi.fn(),
  },
}))
vi.mock('../../hooks/useBaanderPlayer', () => ({
  useBaanderPlayer: () => ({ execution, duration: 0, currentTime: 0, pendingSeekPosition: null, state: null, error: null }),
}))
vi.mock('@/features/session/services/playback-execution', () => ({
  registerPlaybackAdapter: (adapter: PlaybackExecutionAdapter) => {
    adapters.push(adapter)
    return () => { adapters.splice(adapters.indexOf(adapter), 1) }
  },
}))

const paused: PlaybackObservation = { observedState: 'paused', position: 0 }
const playing: PlaybackObservation = { observedState: 'playing', position: 0 }

function command(type: PlaybackCommandResource['type'], position: number, desiredState: 'playing' | 'paused'): PlaybackCommandResource {
  return {
    id: 'command', attemptId: 'attempt', revision: 1, incarnationId: 'incarnation', epoch: 1, type, expiresAt: '2026-11-10T12:00:20Z',
    payload: { media: { kind: 'video', publicId: 'video-1' }, queue: [], currentTrackIndex: 0, position, desiredState },
  } as PlaybackCommandResource
}

describe('VideoPlaybackRuntime', () => {
  beforeEach(() => {
    useVideoPresentation.getState().select('')
    execution.ready.mockReset().mockRejectedValue(new Error('A prepare must not wait for the previous restore.'))
    execution.prepare.mockReset().mockResolvedValue(undefined)
    execution.quiesce.mockReset().mockResolvedValue(paused)
    execution.observe.mockReset().mockReturnValue(paused)
    execution.execute.mockReset().mockImplementation(async (action: string) => action === 'play' ? playing : paused)
    render(<VideoPlaybackRuntime />)
  })

  afterEach(() => { cleanup() })

  const run = async (type: PlaybackCommandResource['type'], position: number, desiredState: 'playing' | 'paused') => {
    let observation: PlaybackObservation | undefined
    await act(async () => { observation = await adapters[0].execute(command(type, position, desiredState), AbortSignal.timeout(2000)) })
    return observation
  }

  it('leaves reaching the cursor to the player, which may supersede a pending restore', async () => {
    const observation = await run('prepare', 223.59, 'playing')

    expect(useVideoPresentation.getState().videoId).toBe('video-1')
    expect(execution.prepare).toHaveBeenCalledTimes(1)
    expect(execution.prepare.mock.calls[0].slice(0, 2)).toEqual([223.59, true])
    // Neither a wait for the previous restore nor a seek of its own.
    expect(execution.ready).not.toHaveBeenCalled()
    expect(execution.execute).not.toHaveBeenCalled()
    expect(observation).toEqual(paused)
  })

  it('prepares a paused cursor as paused, so it stays where it is', async () => {
    await run('start', 223.59, 'paused')

    expect(execution.prepare.mock.calls[0].slice(0, 2)).toEqual([223.59, false])
    expect(execution.execute.mock.calls.map(call => call[0])).toEqual(['pause'])
  })

  it('starts playback after preparing the cursor', async () => {
    const observation = await run('start', 0, 'playing')

    expect(execution.prepare.mock.calls[0].slice(0, 2)).toEqual([0, true])
    expect(execution.execute.mock.calls.map(call => call[0])).toEqual(['play'])
    expect(observation).toEqual(playing)
  })

  it('quiesces the mounted video before it prepares the next command', async () => {
    await run('prepare', 10, 'paused')
    execution.prepare.mockClear()

    await run('prepare', 0, 'playing')

    expect(execution.quiesce).toHaveBeenCalledTimes(1)
    expect(execution.quiesce.mock.invocationCallOrder[0]).toBeLessThan(execution.prepare.mock.invocationCallOrder[0])
  })
})
