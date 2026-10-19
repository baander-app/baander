import { useEffect, useRef, useCallback, useState } from 'react'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { PostTranscodeSessionPosition200 } from '@/shared/api-client/gen/endpoints'

interface UseBaanderPlayerOptions {
  videoId: string
  containerRef: React.RefObject<HTMLDivElement | null>
  autoPlay?: boolean
  onTimeUpdate?: (currentTime: number, duration: number) => void
  onStateChange?: (state: string) => void
  onError?: (error: MediaError | null) => void
}

interface PlaybackSnapshot {
  url: string | null
  renditionSet: string
  jobId: string
  sessionId: string | null
  generation: number
  revision: number
  ready: boolean
  position: number
  status: string
  startTime: number | null
  duration: number | null
}

interface NativeSeekChain {
  sessionId: string
  jobId: string
  attachment: string
  started: boolean
  generation: number
  remaining: number
  pendingTarget: NativeSeekTarget | null
}

interface NativeSeekTarget {
  chain: NativeSeekChain
  position: number
  first: boolean
}

export function useBaanderPlayer({ videoId, containerRef, autoPlay = false, onTimeUpdate, onStateChange, onError }: UseBaanderPlayerOptions) {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const cleanupRef = useRef<(() => void) | null>(null)
  const seekRef = useRef<((time: number) => void) | null>(null)
  const callbacks = useRef({ onTimeUpdate, onStateChange, onError })
  useEffect(() => { callbacks.current = { onTimeUpdate, onStateChange, onError } }, [onTimeUpdate, onStateChange, onError])
  const [state, setState] = useState<string | null>(null)
  const [duration, setDuration] = useState(0)
  const [currentTime, setCurrentTime] = useState(0)

  useEffect(() => {
    const container = containerRef.current
    if (!container || !videoId) return
    const video = document.createElement('video')
    video.style.width = '100%'
    video.style.height = '100%'
    video.controls = true
    container.appendChild(video)
    videoRef.current = video
    const abort = new AbortController()
    let requestRevision = 0
    let appliedRequest = 0
    let snapshot: PlaybackSnapshot | null = null
    let loadedIdentity: string | null = null
    let pendingLoad: { identity: string, position: number } | null = null
    let reloadCursor: number | null = null
    let localStatus: 'running' | 'paused' | null = null
    let localStatusRevision = 0
    let deferredStatusRevision: number | null = null
    let playbackStarted = autoPlay
    let controls = Promise.resolve()
    let pendingControls = 0
    let progressPosition: number | null = null
    let mediaStart = 0
    let restoredPosition: number | null = null
    let nativeSeek: NativeSeekChain | null = null
    const active = () => !abort.signal.aborted && videoRef.current === video
    const changeState = (next: string) => {
      if (!active()) return
      setState(next)
      callbacks.current.onStateChange?.(next)
    }
    const fail = () => {
      if (!active()) return
      changeState('error')
      callbacks.current.onError?.(video.error)
    }
    const playVideo = () => { video.play().catch(fail) }
    const desiredStatus = () => localStatus ?? snapshot?.status
    const applyPlayback = () => {
      if (!active()) return
      if (!playbackStarted || desiredStatus() !== 'running') video.pause()
      else if (video.paused) playVideo()
    }

    const refresh = async (initial = false) => {
      if (!active()) return
      const revision = ++requestRevision
      try {
        const { data } = await AXIOS_INSTANCE.post<PlaybackSnapshot>('/api/stream/sign', {
          path: `/api/transcode/${videoId}/master.m3u8`,
          ...(initial ? {} : { refresh: true }),
        }, { signal: abort.signal, timeout: 5000 })
        if (!active() || revision < appliedRequest) return
        const previous = snapshot
        // A later response cannot move an attached job backwards in durable state.
        if (previous?.jobId === data.jobId && (data.generation < previous.generation || data.revision < previous.revision)) return
        const identity = `${data.jobId}:${data.generation}:${data.renditionSet}`
        if (nativeSeek) {
          // Only this seek's acknowledgement can carry pending intent forward.
          // Even a refresh started before the seek must leave its source attached.
          if (nativeSeek.sessionId !== data.sessionId || nativeSeek.jobId !== data.jobId) nativeSeek = null
          else if (nativeSeek.started && nativeSeek.remaining > 0) return
          else if (nativeSeek.started) nativeSeek = null
          else if (nativeSeek.attachment !== identity) nativeSeek = null
        }
        // Let an active native seek finish before replacing its source to add
        // renditions. Committed execution changes still apply immediately.
        if (previous?.jobId === data.jobId && previous.generation === data.generation
          && previous.revision === data.revision && identity !== loadedIdentity
          && loadedIdentity?.startsWith(`${data.jobId}:${data.generation}:`)
          && video.seeking && !pendingLoad) return
        appliedRequest = revision
        snapshot = data
        const settledStatus = !nativeSeek && deferredStatusRevision === localStatusRevision
        if (settledStatus) {
          localStatus = null
          deferredStatusRevision = null
        }
        const executionChanged = previous?.jobId !== data.jobId || previous.generation !== data.generation
        if (identity !== loadedIdentity && ((data.status === 'paused' && executionChanged) || reloadCursor !== null || previous?.position !== data.position)) {
          reloadCursor = data.position
        }
        if (!data.ready || data.url === null || data.startTime === null || data.duration === null) {
          pendingLoad = null
          if (data.status === 'cancelled') { video.pause(); video.src = ''; changeState('ended'); return }
          if (desiredStatus() !== 'running') applyPlayback()
          changeState('preparing')
          return
        }
        if (identity !== loadedIdentity) {
          progressPosition = data.position
          const attachedPosition = mediaStart + (pendingLoad?.position ?? video.currentTime)
          const position = reloadCursor ?? Math.max(data.position, attachedPosition)
          mediaStart = data.startTime
          setDuration(data.duration)
          pendingLoad = { identity, position: Math.max(0, position - mediaStart) }
          loadedIdentity = identity
          video.src = new URL(data.url, window.__BAANDER_API_URL__ || window.location.origin).href
          // Native playback may begin before loadedmetadata; restoration below also
          // reapplies the committed pause state and cursor once seeking is possible.
          applyPlayback()
        } else if (previous?.revision !== data.revision || settledStatus) {
          applyPlayback()
        }
      } catch {
        if (active() && revision >= appliedRequest) fail()
      }
    }

    const control = (action: 'seek' | 'pause' | 'resume' | 'progress', globalPosition = mediaStart + video.currentTime, nativeTarget?: NativeSeekTarget) => {
      const current = snapshot
      const sessionId = current?.sessionId
      if (!sessionId || !current || !active()) return
      if (action === 'progress' && (pendingControls > 0 || pendingLoad || !current.ready
        || current.status !== 'running' || video.paused || video.seeking
        || Math.abs(globalPosition - (progressPosition ?? current.position)) < 1)) return
      const position = globalPosition
      const statusRevision = action === 'pause' || action === 'resume' ? ++localStatusRevision : null
      if (statusRevision !== null) {
        localStatus = action === 'pause' ? 'paused' : 'running'
        if (nativeSeek) nativeSeek.pendingTarget = null
      }
      pendingControls++
      controls = controls.then(async () => {
        if (!active()) return
        if (action === 'progress' && (snapshot?.sessionId !== sessionId
          || snapshot.generation !== current.generation || snapshot.revision !== current.revision
          || snapshot.status !== 'running' || video.paused || video.seeking)) return
        const chain = nativeTarget?.chain
        if (nativeTarget && chain) {
          if (!nativeTarget.first && nativeSeek !== chain) return
          if (nativeSeek === chain) chain.started = true
          if (chain.pendingTarget === nativeTarget) chain.pendingTarget = null
        }
        try {
          const { data } = await AXIOS_INSTANCE.post<{ data?: PostTranscodeSessionPosition200 }>(`/api/transcode/sessions/${sessionId}/position`, {
            position: nativeTarget?.position ?? position, action,
            ...(action === 'progress' ? { generation: current.generation, revision: current.revision } : {}),
            ...(chain ? { generation: chain.generation } : {}),
          }, { signal: abort.signal, timeout: 5000 })
          if (chain && nativeSeek === chain) {
            if (data.data?.acceptedGeneration === undefined) nativeSeek = null
            else chain.generation = data.data.acceptedGeneration
          }
        } catch (error) {
          if (chain && nativeSeek === chain) nativeSeek = null
          throw error
        } finally {
          if (chain) {
            chain.remaining--
            if (nativeSeek !== chain || chain.remaining === 0) {
              // Only a read started after acceptance may replace this attachment.
              appliedRequest = requestRevision + 1
              await refresh()
            }
          }
        }
        if (action === 'progress' && snapshot?.generation === current.generation) progressPosition = position
        if (!chain) await refresh()
      }).catch(fail).finally(() => {
        pendingControls--
        if (statusRevision !== null && statusRevision === localStatusRevision) {
          if (nativeSeek?.started) deferredStatusRevision = statusRevision
          else {
            localStatus = null
            applyPlayback()
          }
        }
      })
    }

    const restorePendingLoad = () => {
      if (!active()) return
      const load = pendingLoad
      if (load && snapshot?.ready && `${snapshot.jobId}:${snapshot.generation}:${snapshot.renditionSet}` === load.identity) {
        if (video.readyState < HTMLMediaElement.HAVE_METADATA) return
        if (video.seeking) {
          applyPlayback()
          return
        }
        // Seeking an already starting native HLS stream can flush its initial
        // buffer before the next fragment is available. Allow small native preroll.
        const alreadyAtStart = load.position === 0 && video.currentTime <= 0.05
        const atTarget = alreadyAtStart || Math.abs(video.currentTime - load.position) <= 0.05
        const seekable = Array.from({ length: video.seekable.length }, (_, index) =>
          load.position >= video.seekable.start(index) && load.position <= video.seekable.end(index)).some(Boolean)
        // Metadata can precede a usable native timeline. Keep the committed
        // cursor until the target is available rather than seeking into a clamp.
        if (!atTarget && !(Number.isFinite(video.duration) && video.duration >= load.position) && !seekable) {
          applyPlayback()
          return
        }
        restoredPosition = null
        if (!atTarget) {
          restoredPosition = load.position
          video.currentTime = load.position
        }
        applyPlayback()
        if (video.seeking || Math.abs(video.currentTime - load.position) > 0.05) return
        pendingLoad = null
        reloadCursor = null
      }
    }
    video.addEventListener('loadedmetadata', () => {
      if (!active()) return
      setDuration(snapshot?.duration ?? mediaStart + video.duration)
      restorePendingLoad()
      changeState('loaded')
    })
    for (const event of ['durationchange', 'loadeddata', 'canplay', 'progress']) {
      video.addEventListener(event, restorePendingLoad)
    }
    video.addEventListener('timeupdate', () => {
      const position = mediaStart + video.currentTime
      setCurrentTime(position)
      callbacks.current.onTimeUpdate?.(position, snapshot?.duration ?? mediaStart + video.duration)
    })
    video.addEventListener('play', () => {
      if (video.paused) return
      playbackStarted = true
      changeState('playing')
      if (snapshot?.ready && desiredStatus() === 'paused') control('resume')
    })
    video.addEventListener('pause', () => {
      if (!video.paused) return
      if (video.error) {
        fail()
        return
      }
      changeState('paused')
      if (snapshot?.ready && desiredStatus() === 'running') control('pause')
    })
    video.addEventListener('seeked', () => {
      if (!snapshot?.ready || loadedIdentity !== `${snapshot.jobId}:${snapshot.generation}:${snapshot.renditionSet}`) return
      if (pendingLoad) {
        if (Math.abs(video.currentTime - pendingLoad.position) <= 0.05) restorePendingLoad()
        return
      }
      restoredPosition = null
    })
    video.addEventListener('seeking', () => {
      if (!active() || !snapshot?.ready || !snapshot.sessionId || pendingLoad
        || loadedIdentity !== `${snapshot.jobId}:${snapshot.generation}:${snapshot.renditionSet}`) return
      if (restoredPosition !== null && Math.abs(video.currentTime - restoredPosition) <= 0.05) return
      restoredPosition = null
      // A paused encoder may not provide the data needed to finish this native seek.
      // Commit its intent before decoding completes so the new execution can start.
      if (nativeSeek || Math.abs(mediaStart + video.currentTime - snapshot.position) > 0.05) {
        if (nativeSeek?.pendingTarget) nativeSeek.pendingTarget.position = mediaStart + video.currentTime
        else {
          const first = nativeSeek === null
          if (!nativeSeek) {
            nativeSeek = {
              sessionId: snapshot.sessionId, jobId: snapshot.jobId,
              attachment: `${snapshot.jobId}:${snapshot.generation}:${snapshot.renditionSet}`,
              started: false, generation: snapshot.generation, remaining: 0, pendingTarget: null,
            }
          }
          const target: NativeSeekTarget = { chain: nativeSeek, position: mediaStart + video.currentTime, first }
          if (!first) nativeSeek.pendingTarget = target
          nativeSeek.remaining++
          control('seek', target.position, target)
        }
      }
    })
    const seek = (time: number) => {
      control('seek', time)
    }
    seekRef.current = seek
    video.addEventListener('ended', () => changeState('ended'))
    video.addEventListener('error', fail)
    refresh(true).catch(fail)
    const timer = window.setInterval(() => {
      control('progress')
      refresh().catch(fail)
    }, 1000)
    const cleanup = () => {
      if (abort.signal.aborted) return
      window.clearInterval(timer)
      nativeSeek = null
      abort.abort()
      video.pause()
      video.src = ''
      if (videoRef.current === video) videoRef.current = null
      video.remove()
      if (seekRef.current === seek) seekRef.current = null
      if (cleanupRef.current === cleanup) cleanupRef.current = null
    }
    cleanupRef.current = cleanup
    return cleanup
  }, [videoId, containerRef, autoPlay])

  const play = useCallback(() => { videoRef.current?.play().catch(() => setState('error')) }, [])
  const pause = useCallback(() => { videoRef.current?.pause() }, [])
  const seekTo = useCallback((time: number) => { seekRef.current?.(time) }, [])
  const destroy = useCallback(() => { cleanupRef.current?.() }, [])
  return { play, pause, seekTo, destroy, state, duration, currentTime }
}
