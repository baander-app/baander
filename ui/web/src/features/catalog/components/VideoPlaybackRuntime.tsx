import { useEffect, useRef, useState } from 'react'
import type { PlaybackCommandResource } from '@/shared/api-client/gen/endpoints'
import { registerPlaybackAdapter, type PlaybackExecutionAdapter, type PlaybackObservation } from '@/features/session/services/playback-execution'
import { useBaanderPlayer } from '../hooks/useBaanderPlayer'
import { useVideoPresentation } from '../stores/video-presentation-store'

export function VideoPlaybackRuntime() {
  const videoId = useVideoPresentation(state => state.videoId)
  const [host] = useState(() => document.createElement('div'))
  const containerRef = useRef(host)
  const parkingRef = useRef<HTMLDivElement>(null)
  const mountedVideo = useRef('')
  const player = useBaanderPlayer({ videoId, containerRef, coordinated: true })
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
      await execution.ready(signal)
      const position = execution.observe().position
      if (Math.abs(position - payload.position) > 0.05) await execution.execute('seek', payload.position, signal)
      stoppedObservation = null
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
