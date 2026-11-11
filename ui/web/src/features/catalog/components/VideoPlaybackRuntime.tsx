import { useCallback, useEffect, useRef, useState } from 'react'
import type { PlaybackCommandResource } from '@/shared/api-client/gen/endpoints'
import { registerPlaybackAdapter, requestPlayback, type PlaybackExecutionAdapter, type PlaybackObservation } from '@/features/session/services/playback-execution'
import { usePlaybackSession } from '@/features/session/hooks/use-playback-session'
import { useBaanderPlayer } from '../hooks/useBaanderPlayer'
import { useVideoPresentation } from '../stores/video-presentation-store'

export function VideoPlaybackRuntime() {
  const videoId = useVideoPresentation(state => state.videoId)
  const [host] = useState(() => document.createElement('div'))
  const containerRef = useRef(host)
  const parkingRef = useRef<HTMLDivElement>(null)
  const mountedVideo = useRef('')
  const { snapshot, endpoint } = usePlaybackSession()
  const session = useRef({ snapshot, endpoint })
  useEffect(() => { session.current = { snapshot, endpoint } }, [snapshot, endpoint])
  // A video that plays to its end stops by itself. The playback session knows a runner stopped
  // playing only from a command that runner acknowledged, so the runner asks for the pause it has
  // already carried out. Without it the session stays "playing" on a paused runner, and once the
  // tab has gone no other destination may take the session over.
  const pauseSessionAtEnd = useCallback(() => {
    const { snapshot: current, endpoint: local } = session.current
    if (!local || !current || current.activeIncarnationId !== local.incarnationId || current.transfer
      || current.media?.kind !== 'video' || current.media.publicId !== mountedVideo.current
      || current.desiredState !== 'playing') return
    requestPlayback({ action: 'pause' }).catch(useVideoPresentation.getState().reportError)
  }, [])
  const player = useBaanderPlayer({ videoId, containerRef, coordinated: true, onCompleted: pauseSessionAtEnd })
  const { execution } = player

  useEffect(() => {
    mountedVideo.current = videoId
    return () => { mountedVideo.current = '' }
  }, [videoId])

  useEffect(() => {
    const parking = parkingRef.current
    parking?.appendChild(host)
    useVideoPresentation.getState().publish({ host })
    return () => {
      useVideoPresentation.getState().publish({ host: null })
      useVideoPresentation.getState().select('')
      host.remove()
    }
  }, [host])

  useEffect(() => {
    useVideoPresentation.getState().publish({
      duration: player.duration, currentTime: player.currentTime,
      pendingSeekPosition: player.pendingSeekPosition, state: player.state, error: player.error,
    })
  }, [player.duration, player.currentTime, player.pendingSeekPosition, player.state, player.error])

  useEffect(() => {
    let stoppedObservation: PlaybackObservation | null = null
    // A volume set while no video is mounted has no element to hold it; the next mount takes it.
    let volumeForNextMount: number | null = null
    const prepare = async (payload: PlaybackCommandResource['payload'], signal: AbortSignal) => {
      signal.throwIfAborted()
      if (payload.media?.kind !== 'video') throw new Error('A video selection is required.')
      const next = payload.media.publicId
      if (mountedVideo.current) await execution.quiesce(signal)
      useVideoPresentation.getState().select(next)
      while (mountedVideo.current !== next) {
        signal.throwIfAborted()
        await new Promise<void>(resolve => window.setTimeout(resolve, 20))
      }
      // The player decides how to reach the cursor: a newer target supersedes a restore it is still
      // waiting for, and playing from the source end starts again at zero.
      await execution.prepare(payload.position, payload.desiredState === 'playing', signal)
      stoppedObservation = null
      if (volumeForNextMount !== null) {
        await execution.execute('volume', volumeForNextMount, signal)
        volumeForNextMount = null
      }
    }
    // A stopped player has closed its video. A volume change is no reason to open it again: that
    // would mount and prepare a movie nobody asked to see, and the player would stop reporting
    // itself stopped.
    const setStoppedVolume = (stopped: PlaybackObservation, volume: number | null | undefined) => {
      if (volume == null || !Number.isFinite(volume) || volume < 0 || volume > 1) throw new Error('Video volume is invalid.')
      volumeForNextMount = volume
      stoppedObservation = { ...stopped, volume }
      return stoppedObservation
    }
    const stop = async (signal: AbortSignal) => {
      signal.throwIfAborted()
      if (stoppedObservation) return stoppedObservation
      const observed = await execution.execute('stop', undefined, signal)
      useVideoPresentation.getState().select('')
      stoppedObservation = observed
      return observed
    }
    const adapter: PlaybackExecutionAdapter = {
      kind: 'video', supportsVolume: true, prepare,
      localRecovery: execution.localRecovery,
      observe: () => stoppedObservation ?? execution.observe(),
      quiesce: async signal => {
        signal.throwIfAborted()
        return stoppedObservation ?? execution.quiesce(signal)
      },
      execute: async (command, signal) => {
        const { type, payload } = command
        if (type === 'observe' || type === 'queue') return stoppedObservation ?? execution.observe()
        if (type === 'quiesce') return execution.quiesce(signal)
        if (type === 'stop') return stop(signal)
        if (type === 'prepare') {
          await prepare(payload, signal)
          return execution.observe()
        }
        if (type === 'load' || type === 'start') {
          await prepare(payload, signal)
          if (payload.desiredState === 'stopped') return stop(signal)
          return execution.execute(payload.desiredState === 'playing' ? 'play' : 'pause', undefined, signal)
        }
        if (type === 'volume' && stoppedObservation) return setStoppedVolume(stoppedObservation, payload.volume)
        if (stoppedObservation) await prepare(payload, signal)
        if (type === 'seek') return execution.execute('seek', payload.position, signal)
        if (type === 'volume') return execution.execute('volume', payload.volume ?? undefined, signal)
        return execution.execute(type, undefined, signal)
      },
    }
    return registerPlaybackAdapter(adapter)
  }, [execution])

  return <div hidden ref={parkingRef} />
}
