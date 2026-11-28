import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import type { PlaybackCommandResource } from '@/shared/api-client/gen/endpoints'
import type {
  PlaybackExecutionAdapter,
  PlaybackObservation
} from '@/features/session/services/playback-execution'
import { useVideoPresentation } from '../../stores/video-presentation-store'
import { VideoPlaybackRuntime } from '../VideoPlaybackRuntime'

const {
  execution,
  reports,
  adapters,
  playerOptions,
  session,
  request,
  presentationCommands
} = vi.hoisted(() => ({
  reports: { tick: vi.fn(), action: vi.fn(), flush: vi.fn(async () => {}) },
  adapters: [] as PlaybackExecutionAdapter[],
  playerOptions: [] as Array<{ onCompleted?: () => void }>,
  request: vi.fn(),
  presentationCommands: {
    setQuality: vi.fn(),
    setSubtitle: vi.fn(),
    setSubtitleOffset: vi.fn(),
    setAudio: vi.fn(async () => {})
  },
  session: {
    snapshot: {
      activeIncarnationId: 'local',
      transfer: null,
      media: { kind: 'video', publicId: 'video-1' },
      desiredState: 'playing'
    } as Record<string, unknown> | undefined,
    endpoint: { incarnationId: 'local' } as { incarnationId: string } | null
  },
  execution: {
    localRecovery: {
      eligible: false,
      fence: () => null,
      rearmAfterRegistration: () => {}
    },
    ready: vi.fn(),
    prepare: vi.fn(),
    execute: vi.fn(),
    quiesce: vi.fn(),
    observe: vi.fn()
  }
}))
vi.mock('../../hooks/use-video-viewing-reporter', () => ({
  useVideoViewingReporter: () => reports
}))
vi.mock('../../hooks/useBaanderPlayer', () => ({
  useBaanderPlayer: (options: { onCompleted?: () => void }) => {
    playerOptions.push(options)
    return {
      execution,
      presentationCommands,
      duration: 0,
      currentTime: 0,
      pendingSeekPosition: null,
      state: null,
      error: null
    }
  }
}))
vi.mock('@/features/session/hooks/use-playback-session', () => ({
  usePlaybackSession: () => session
}))
vi.mock('@/features/session/services/playback-execution', () => ({
  requestPlayback: request,
  registerPlaybackAdapter: (adapter: PlaybackExecutionAdapter) => {
    adapters.push(adapter)
    return () => {
      adapters.splice(adapters.indexOf(adapter), 1)
    }
  }
}))

const paused: PlaybackObservation = { observedState: 'paused', position: 0 }
const playing: PlaybackObservation = { observedState: 'playing', position: 0 }

function command(
  type: PlaybackCommandResource['type'],
  position: number,
  desiredState: 'playing' | 'paused'
): PlaybackCommandResource {
  return {
    id: 'command',
    attemptId: 'attempt',
    revision: 1,
    incarnationId: 'incarnation',
    epoch: 1,
    type,
    expiresAt: '2026-11-10T12:00:20Z',
    payload: {
      media: { kind: 'video', publicId: 'video-1' },
      queue: [],
      currentTrackIndex: 0,
      position,
      desiredState
    }
  } as PlaybackCommandResource
}

describe('VideoPlaybackRuntime', () => {
  beforeEach(() => {
    useVideoPresentation.getState().select('')
    playerOptions.length = 0
    reports.flush.mockReset().mockResolvedValue(undefined)
    request.mockReset().mockResolvedValue(undefined)
    session.snapshot = {
      activeIncarnationId: 'local',
      transfer: null,
      media: { kind: 'video', publicId: 'video-1' },
      desiredState: 'playing'
    }
    session.endpoint = { incarnationId: 'local' }
    execution.ready
      .mockReset()
      .mockRejectedValue(
        new Error('A prepare must not wait for the previous restore.')
      )
    execution.prepare.mockReset().mockResolvedValue(undefined)
    execution.quiesce.mockReset().mockResolvedValue(paused)
    execution.observe.mockReset().mockReturnValue(paused)
    execution.execute
      .mockReset()
      .mockImplementation(async (action: string) =>
        action === 'play' ? playing : paused
      )
    render(<VideoPlaybackRuntime />)
  })

  afterEach(() => {
    cleanup()
  })

  const run = async (
    type: PlaybackCommandResource['type'],
    position: number,
    desiredState: 'playing' | 'paused'
  ) => {
    let observation: PlaybackObservation | undefined
    await act(async () => {
      observation = await adapters[0].execute(
        command(type, position, desiredState),
        AbortSignal.timeout(2000)
      )
    })
    return observation
  }

  it('restores the opened title from the handoff command with its exact file cursor', async () => {
    const supplied = command('start', 83, 'playing')
    const viewing = { titleKind: 'extra' as const, titleId: 'selectedTitle00000001' }
    supplied.payload.media = { kind: 'video', publicId: 'video-1', viewing }
    await act(async () => {
      await adapters[0].execute(supplied, AbortSignal.timeout(2000))
    })
    expect(useVideoPresentation.getState().viewing).toEqual(viewing)
    expect(execution.prepare).toHaveBeenCalledWith(83, true, expect.any(AbortSignal))
  })

  it('flushes the final file observation before clearing the stopped viewing context', async () => {
    await run('start', 0, 'playing')
    let finish!: () => void
    reports.flush.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    let stopping!: Promise<PlaybackObservation>
    await act(async () => {
      stopping = adapters[0].execute(command('stop', 0, 'paused'), AbortSignal.timeout(2000))
      await Promise.resolve()
    })
    expect(useVideoPresentation.getState().videoId).toBe('video-1')
    await act(async () => {
      finish()
      await stopping
    })
    expect(useVideoPresentation.getState().videoId).toBe('')
    expect(useVideoPresentation.getState().viewing).toBeNull()
  })

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
    expect(execution.execute.mock.calls.map((call) => call[0])).toEqual([
      'pause'
    ])
  })

  it('starts playback after preparing the cursor', async () => {
    const observation = await run('start', 0, 'playing')

    expect(execution.prepare.mock.calls[0].slice(0, 2)).toEqual([0, true])
    expect(execution.execute.mock.calls.map((call) => call[0])).toEqual([
      'play'
    ])
    expect(observation).toEqual(playing)
  })

  it('stops a failed next movie before retrying after the previous movie stopped', async () => {
    await run('prepare', 10, 'paused')
    execution.execute.mockResolvedValueOnce({ observedState: 'stopped', position: 10 })
    await run('stop', 10, 'paused')
    expect(useVideoPresentation.getState().videoId).toBe('')

    const nextCommand = command('start', 0, 'playing')
    nextCommand.payload.media = { kind: 'video', publicId: 'video-2' }
    execution.prepare.mockRejectedValueOnce(new Error('No video encoder slot is available.'))
    await act(async () => {
      await expect(adapters[0].execute(nextCommand, AbortSignal.timeout(2000)))
        .rejects.toThrow('No video encoder slot is available.')
    })
    expect(useVideoPresentation.getState().videoId).toBe('video-2')

    execution.execute.mockResolvedValueOnce({ observedState: 'stopped', position: 0 })
    await run('stop', 0, 'paused')
    expect(execution.execute.mock.calls.map(call => call[0])).toEqual(['stop', 'stop'])
    expect(useVideoPresentation.getState().videoId).toBe('')

    await act(async () => {
      expect(await adapters[0].execute(nextCommand, AbortSignal.timeout(2000))).toEqual(playing)
    })
    expect(useVideoPresentation.getState().videoId).toBe('video-2')
    expect(execution.execute.mock.calls.map(call => call[0])).toEqual(['stop', 'stop', 'play'])
  })

  it('quiesces the mounted video before it prepares the next command', async () => {
    await run('prepare', 10, 'paused')
    execution.prepare.mockClear()

    await run('prepare', 0, 'playing')

    expect(execution.quiesce).toHaveBeenCalledTimes(1)
    expect(execution.quiesce.mock.invocationCallOrder[0]).toBeLessThan(
      execution.prepare.mock.invocationCallOrder[0]
    )
  })

  // The player reports that its video played to the end.
  const complete = async () => {
    await run('start', 0, 'playing')
    await act(async () => {
      playerOptions.at(-1)!.onCompleted!()
    })
  }

  it('asks the session to pause once when its video ends as the active runner of a playing session', async () => {
    await complete()

    expect(request.mock.calls).toEqual([[{ action: 'pause' }]])
  })

  // Mounts the runtime anew on the session as arranged, with the video selected, and ends the video.
  const completeOn = async (arrange: () => void) => {
    await run('start', 0, 'playing')
    arrange()
    cleanup()
    render(<VideoPlaybackRuntime />)
    useVideoPresentation.getState().select('video-1')
    await act(async () => {
      await Promise.resolve()
    })
    await act(async () => {
      playerOptions.at(-1)!.onCompleted!()
    })
  }

  it('asks once per reported end', async () => {
    await completeOn(() => {})
    expect(request).toHaveBeenCalledTimes(1)

    await act(async () => {
      playerOptions.at(-1)!.onCompleted!()
    })
    expect(request).toHaveBeenCalledTimes(2)
  })

  it.each([
    [
      'another endpoint is the active runner',
      () => {
        session.snapshot!.activeIncarnationId = 'remote'
      }
    ],
    [
      'no runner is active',
      () => {
        session.snapshot!.activeIncarnationId = null
      }
    ],
    [
      'this browser has no endpoint',
      () => {
        session.endpoint = null
      }
    ],
    [
      'the session is already paused',
      () => {
        session.snapshot!.desiredState = 'paused'
      }
    ],
    [
      'the session plays another video',
      () => {
        session.snapshot!.media = { kind: 'video', publicId: 'video-2' }
      }
    ],
    [
      'the session plays music',
      () => {
        session.snapshot!.media = { kind: 'song', publicId: 'video-1' }
      }
    ],
    [
      'a transfer is in flight',
      () => {
        session.snapshot!.transfer = { state: 'preparing' }
      }
    ],
    [
      'there is no session snapshot',
      () => {
        session.snapshot = undefined
      }
    ]
  ])('sends nothing at the end when %s', async (_reason, arrange) => {
    await completeOn(arrange)

    expect(request).not.toHaveBeenCalled()
  })

  it('shows a refused pause like any other playback request and leaves the completion alone', async () => {
    request.mockRejectedValue(
      new Error('A previous playback result is unconfirmed.')
    )

    await complete()
    await act(async () => {
      await Promise.resolve()
    })

    expect(useVideoPresentation.getState().error).toBe(
      'A previous playback result is unconfirmed.'
    )
    expect(execution.execute.mock.calls.map((call) => call[0])).toEqual([
      'play'
    ])
  })

  it('fences previously published presentation commands after local ownership changes', async () => {
    await run('prepare', 0, 'paused')
    const commands = useVideoPresentation.getState().commands!
    expect(commands).not.toBeNull()
    commands.setSubtitleOffset(100)
    expect(presentationCommands.setSubtitleOffset).toHaveBeenCalledWith(100)
    session.snapshot!.activeIncarnationId = 'remote'
    expect(() => commands.setSubtitleOffset(200)).toThrow(
      'local video destination'
    )
    await expect(commands.setAudio(3, 100)).rejects.toThrow(
      'local video destination'
    )
  })

  it.each(['audio', 'quality'] as const)(
    'rejects an in-flight %s completion after ownership changes',
    async (selection) => {
      await run('prepare', 0, 'paused')
      const commands = useVideoPresentation.getState().commands!
      let complete!: () => void
      const operation = new Promise<void>((resolve) => {
        complete = resolve
      })
      if (selection === 'quality') {
        presentationCommands.setQuality.mockImplementationOnce(() => operation)
      } else {
        presentationCommands.setAudio.mockImplementationOnce(() => operation)
      }
      const change = selection === 'quality'
        ? commands.setQuality('480p')
        : commands.setAudio(3, 100)
      session.snapshot!.activeIncarnationId = 'remote'
      complete()
      await expect(change).rejects.toMatchObject({ name: 'AbortError' })
    }
  )

  it('fences commands belonging to a video that was replaced', async () => {
    await run('prepare', 0, 'paused')
    const commands = useVideoPresentation.getState().commands!
    act(() => useVideoPresentation.getState().select('video-2'))
    await expect(commands.setQuality('auto')).rejects.toThrow('local video destination')
  })
})
