import { useEffect, useRef, useCallback, useState } from 'react'
import { isAxiosError, isCancel } from 'axios'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { attachHlsPlayback } from './hls-playback'
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

interface PlaybackSeekChain {
  sessionId: string
  jobId: string
  attachment: string
  started: boolean
  generation: number
  remaining: number
  pendingTarget: PlaybackSeekTarget | null
}

interface PlaybackSeekTarget {
  chain: PlaybackSeekChain
  position: number
  first: boolean
}

export function useBaanderPlayer({ videoId, containerRef, autoPlay = false, onTimeUpdate, onStateChange, onError }: UseBaanderPlayerOptions) {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const cleanupRef = useRef<(() => void) | null>(null)
  const playRef = useRef<(() => void) | null>(null)
  const pauseRef = useRef<(() => void) | null>(null)
  const seekRef = useRef<((time: number) => void) | null>(null)
  const callbacks = useRef({ onTimeUpdate, onStateChange, onError })
  useEffect(() => { callbacks.current = { onTimeUpdate, onStateChange, onError } }, [onTimeUpdate, onStateChange, onError])
  const [state, setState] = useState<string | null>(null)
  const [duration, setDuration] = useState(0)
  const [currentTime, setCurrentTime] = useState(0)
  const [pendingSeekPosition, setPendingSeekPosition] = useState<number | null>(null)

  useEffect(() => {
    const container = containerRef.current
    if (!container || !videoId) return
    setDuration(0)
    setCurrentTime(0)
    setPendingSeekPosition(null)
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
    let seekChain: PlaybackSeekChain | null = null
    let sourceSeekPosition: number | null = null
    let sourceDuration = 0
    let restoredSourceEnd: number | null = null
    let disposeAttachment: (() => void) | null = null
    let attachmentRevision = 0
    let attachmentReady = false
    const playbackPosition = () => restoredSourceEnd !== null && Math.abs(video.currentTime - restoredSourceEnd) <= 0.1
      ? sourceDuration : mediaStart + video.currentTime
    const clearSourceSeek = () => {
      sourceSeekPosition = null
      setPendingSeekPosition(null)
    }
    const active = () => !abort.signal.aborted && videoRef.current === video
    const changeState = (next: string) => {
      if (!active()) return
      setState(next)
      callbacks.current.onStateChange?.(next)
    }
    const fail = () => {
      if (!active()) return
      clearSourceSeek()
      seekChain = null
      pendingLoad = null
      reloadCursor = null
      restoredPosition = null
      restoredSourceEnd = null
      attachmentReady = false
      attachmentRevision++
      disposeAttachment?.()
      disposeAttachment = null
      changeState('error')
      callbacks.current.onError?.(video.error)
    }
    const playVideo = () => {
      if (!attachmentReady) { playbackStarted = true; return }
      const revision = attachmentRevision
      video.play().catch(() => {
        if (revision === attachmentRevision) fail()
      })
    }
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
        if (seekChain) {
          // Only this seek's acknowledgement can carry pending intent forward.
          // Even a refresh started before the seek must leave its source attached.
          if (seekChain.sessionId !== data.sessionId || seekChain.jobId !== data.jobId) seekChain = null
          else if (seekChain.started && seekChain.remaining > 0) return
          else if (seekChain.started) seekChain = null
          else if (seekChain.attachment !== identity) seekChain = null
        }
        // Let an active native seek finish before replacing its source to add
        // renditions. Committed execution changes still apply immediately.
        if (previous?.jobId === data.jobId && previous.generation === data.generation
          && previous.revision === data.revision && identity !== loadedIdentity
          && loadedIdentity?.startsWith(`${data.jobId}:${data.generation}:`)
          && (sourceSeekPosition !== null || pendingLoad || video.seeking)) return
        appliedRequest = revision
        snapshot = data
        if (data.duration !== null && Number.isFinite(data.duration) && data.duration > 0) {
          sourceDuration = data.duration
          setDuration(data.duration)
        }
        const settledStatus = !seekChain && deferredStatusRevision === localStatusRevision
        if (settledStatus) {
          localStatus = null
          deferredStatusRevision = null
        }
        // Preparing snapshots can already describe the next generation. Its
        // cursor must be compared with the attached execution, whose native
        // clock may have reset after its retired resources became unreadable.
        const executionChanged = !loadedIdentity?.startsWith(`${data.jobId}:${data.generation}:`)
        if (identity !== loadedIdentity && (executionChanged || reloadCursor !== null || previous?.position !== data.position)) {
          reloadCursor = data.position
        }
        if (!data.ready || data.url === null || data.startTime === null || data.duration === null) {
          pendingLoad = null
          if (data.status === 'cancelled') { clearSourceSeek(); video.pause(); video.src = ''; changeState('ended'); return }
          if (desiredStatus() !== 'running') applyPlayback()
          changeState('preparing')
          return
        }
        if (identity !== loadedIdentity) {
          restoredSourceEnd = null
          progressPosition = data.position
          const attachedPosition = mediaStart + (pendingLoad?.position ?? video.currentTime)
          const position = reloadCursor ?? Math.max(data.position, attachedPosition)
          mediaStart = data.startTime
          setDuration(data.duration)
          pendingLoad = { identity, position: Math.max(0, position - mediaStart) }
          loadedIdentity = identity
          const attachment = ++attachmentRevision
          attachmentReady = false
          disposeAttachment?.()
          video.removeAttribute('src')
          video.load()
          disposeAttachment = attachHlsPlayback({
            video,
            url: new URL(data.url, window.__BAANDER_API_URL__ || window.location.origin).href,
            position: pendingLoad.position,
            onReady: () => {
              if (active() && attachment === attachmentRevision) {
                attachmentReady = true
                applyPlayback()
              }
            },
            onError: () => {
              if (attachment === attachmentRevision) fail()
            },
          })
          // Attachment readiness applies playback intent; metadata restoration
          // retains the committed cursor until the actual timeline is seekable.
        } else if (previous?.revision !== data.revision || settledStatus) {
          applyPlayback()
        }
        if (sourceSeekPosition !== null && !seekChain && !pendingLoad && identity === loadedIdentity) {
          pendingLoad = { identity, position: Math.max(0, data.position - mediaStart) }
          restorePendingLoad()
        }
      } catch (error) {
        if (!active() || revision < appliedRequest) return
        const status = isAxiosError(error) ? error.response?.status : undefined
        const transient = isAxiosError(error) && !isCancel(error)
          && (error.response === undefined || (status !== undefined && status >= 500 && status <= 599))
        // A failed control-plane poll does not invalidate usable media. The
        // next scheduled poll can reconcile its generation and cursor normally.
        if (!initial && attachmentReady && transient) return
        fail()
      }
    }

    const control = (action: 'seek' | 'pause' | 'resume' | 'progress', globalPosition = sourceSeekPosition ?? playbackPosition(), seekTarget?: PlaybackSeekTarget) => {
      const current = snapshot
      const sessionId = current?.sessionId
      if (!sessionId || !current || !active()) return
      if (action === 'progress' && (sourceSeekPosition !== null || pendingControls > 0 || pendingLoad || !current.ready
        || current.status !== 'running' || video.paused || video.seeking
        || Math.abs(globalPosition - (progressPosition ?? current.position)) < 1)) return
      const position = globalPosition
      const statusRevision = action === 'pause' || action === 'resume' ? ++localStatusRevision : null
      if (statusRevision !== null) {
        localStatus = action === 'pause' ? 'paused' : 'running'
        if (seekChain) seekChain.pendingTarget = null
      }
      pendingControls++
      controls = controls.then(async () => {
        if (!active()) return
        if (action === 'progress' && (sourceSeekPosition !== null || snapshot?.sessionId !== sessionId
          || snapshot.generation !== current.generation || snapshot.revision !== current.revision
          || snapshot.status !== 'running' || video.paused || video.seeking)) return
        const chain = seekTarget?.chain
        if (seekTarget && chain) {
          if (!seekTarget.first && seekChain !== chain) return
          if (seekChain === chain) chain.started = true
          if (chain.pendingTarget === seekTarget) chain.pendingTarget = null
        }
        try {
          const { data } = await AXIOS_INSTANCE.post<{ data?: PostTranscodeSessionPosition200 }>(`/api/transcode/sessions/${sessionId}/position`, {
            position: seekTarget?.position ?? position, action,
            ...(action === 'progress' ? { generation: current.generation, revision: current.revision } : {}),
            ...(chain ? { generation: chain.generation } : {}),
          }, { signal: abort.signal, timeout: 5000 })
          if (chain && seekChain === chain) {
            if (data.data?.acceptedGeneration === undefined) seekChain = null
            else chain.generation = data.data.acceptedGeneration
          }
        } catch (error) {
          if (chain && seekChain === chain) seekChain = null
          throw error
        } finally {
          if (chain) {
            chain.remaining--
            if (seekChain !== chain || chain.remaining === 0) {
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
          if (seekChain?.started) deferredStatusRevision = statusRevision
          else {
            localStatus = null
            applyPlayback()
          }
        }
      })
    }

    const restorePendingLoad = () => {
      if (!active() || !attachmentReady) return
      if (sourceSeekPosition !== null && seekChain) return
      const load = pendingLoad
      if (load && snapshot?.ready && `${snapshot.jobId}:${snapshot.generation}:${snapshot.renditionSet}` === load.identity) {
        if (video.readyState < HTMLMediaElement.HAVE_METADATA) return
        if (video.seeking) {
          applyPlayback()
          return
        }
        // Seeking an already starting native HLS stream can flush its initial
        // buffer before the next fragment is available. Allow small native preroll.
        let nativeTarget = load.position
        const sourceEnd = Math.abs(mediaStart + load.position - sourceDuration) < 0.000001
        const lastEnd = video.seekable.length > 0 ? video.seekable.end(video.seekable.length - 1) : null
        // Only the known source EOF may round back to a published native end.
        // Ordinary seeks must wait for their actual target to become seekable.
        if (sourceEnd && Number.isFinite(video.duration) && video.duration > 0 && lastEnd !== null
          && nativeTarget > lastEnd && nativeTarget - lastEnd <= 0.1) nativeTarget = lastEnd
        const alreadyAtStart = nativeTarget === 0 && video.currentTime <= 0.05
        const atTarget = alreadyAtStart || Math.abs(video.currentTime - nativeTarget) <= 0.05
        const seekable = Array.from({ length: video.seekable.length }, (_, index) =>
          nativeTarget >= video.seekable.start(index) && nativeTarget <= video.seekable.end(index)).some(Boolean)
        // Metadata can precede a usable native timeline. Keep the committed
        // cursor until the target is available rather than seeking into a clamp.
        if (!atTarget && !seekable) {
          applyPlayback()
          return
        }
        restoredPosition = null
        restoredSourceEnd = sourceEnd ? nativeTarget : null
        if (!atTarget) {
          restoredPosition = nativeTarget
          video.currentTime = nativeTarget
        }
        applyPlayback()
        if (video.seeking || Math.abs(video.currentTime - nativeTarget) > 0.05) return
        pendingLoad = null
        reloadCursor = null
        clearSourceSeek()
        setCurrentTime(playbackPosition())
      }
    }
    const enqueueSeek = (position: number) => {
      if (!snapshot?.sessionId) return
      restoredSourceEnd = null
      if (seekChain?.pendingTarget) seekChain.pendingTarget.position = position
      else {
        const first = seekChain === null
        if (!seekChain) {
          seekChain = {
            sessionId: snapshot.sessionId, jobId: snapshot.jobId,
            attachment: loadedIdentity ?? '', started: false,
            generation: snapshot.generation, remaining: 0, pendingTarget: null,
          }
        }
        const target: PlaybackSeekTarget = { chain: seekChain, position, first }
        if (!first) seekChain.pendingTarget = target
        seekChain.remaining++
        control('seek', position, target)
      }
    }
    video.addEventListener('loadedmetadata', () => {
      if (!active()) return
      if (snapshot?.duration !== null && snapshot?.duration !== undefined) setDuration(snapshot.duration)
      restorePendingLoad()
      changeState('loaded')
    })
    for (const event of ['durationchange', 'loadeddata', 'canplay', 'progress']) {
      video.addEventListener(event, restorePendingLoad)
    }
    video.addEventListener('timeupdate', () => {
      if (sourceSeekPosition !== null || pendingLoad) return
      const position = playbackPosition()
      setCurrentTime(position)
      callbacks.current.onTimeUpdate?.(position, snapshot?.duration ?? mediaStart + video.duration)
    })
    video.addEventListener('play', () => {
      if (video.paused) return
      playbackStarted = true
      changeState('playing')
      if (attachmentReady && snapshot?.ready && desiredStatus() === 'paused') control('resume')
    })
    video.addEventListener('pause', () => {
      if (!video.paused) return
      if (video.error) {
        fail()
        return
      }
      changeState('paused')
      if (attachmentReady && snapshot?.ready && desiredStatus() === 'running') control('pause')
    })
    video.addEventListener('seeked', () => {
      if (!snapshot?.ready || loadedIdentity !== `${snapshot.jobId}:${snapshot.generation}:${snapshot.renditionSet}`) return
      if (pendingLoad) {
        if (Math.abs(video.currentTime - pendingLoad.position) <= 0.05
          || (restoredSourceEnd !== null && Math.abs(video.currentTime - restoredSourceEnd) <= 0.05)) restorePendingLoad()
        return
      }
      restoredPosition = null
    })
    video.addEventListener('seeking', () => {
      if (!active() || sourceSeekPosition !== null || !snapshot?.ready || !snapshot.sessionId || pendingLoad
        || loadedIdentity !== `${snapshot.jobId}:${snapshot.generation}:${snapshot.renditionSet}`) return
      if (restoredPosition !== null && Math.abs(video.currentTime - restoredPosition) <= 0.05) return
      if (restoredSourceEnd !== null && Math.abs(video.currentTime - restoredSourceEnd) <= 0.05) return
      restoredPosition = null
      // A paused encoder may not provide the data needed to finish this native seek.
      // Commit its intent before decoding completes so the new execution can start.
      if (seekChain || Math.abs(mediaStart + video.currentTime - snapshot.position) > 0.05) {
        enqueueSeek(mediaStart + video.currentTime)
      }
    })
    const seek = (time: number) => {
      if (!active() || !snapshot?.sessionId || !Number.isFinite(time) || sourceDuration <= 0) return
      const position = Math.max(0, Math.min(sourceDuration, time))
      sourceSeekPosition = position
      setPendingSeekPosition(position)
      enqueueSeek(position)
    }
    const requestPlay = () => {
      if (!active()) return
      playbackStarted = true
      if (snapshot?.ready && desiredStatus() === 'paused') control('resume')
      if (attachmentReady) playVideo()
    }
    const requestPause = () => {
      if (!active()) return
      playbackStarted = false
      if (snapshot?.ready && desiredStatus() === 'running') control('pause')
      video.pause()
    }
    seekRef.current = seek
    playRef.current = requestPlay
    pauseRef.current = requestPause
    video.addEventListener('ended', () => changeState('ended'))
    video.addEventListener('error', () => { if (attachmentReady) fail() })
    refresh(true).catch(fail)
    const timer = window.setInterval(() => {
      control('progress')
      refresh().catch(fail)
    }, 1000)
    const cleanup = () => {
      if (abort.signal.aborted) return
      window.clearInterval(timer)
      seekChain = null
      clearSourceSeek()
      abort.abort()
      attachmentRevision++
      disposeAttachment?.()
      disposeAttachment = null
      video.pause()
      video.src = ''
      if (videoRef.current === video) videoRef.current = null
      video.remove()
      if (seekRef.current === seek) seekRef.current = null
      if (playRef.current === requestPlay) playRef.current = null
      if (pauseRef.current === requestPause) pauseRef.current = null
      if (cleanupRef.current === cleanup) cleanupRef.current = null
    }
    cleanupRef.current = cleanup
    return cleanup
  }, [videoId, containerRef, autoPlay])

  const play = useCallback(() => { playRef.current?.() }, [])
  const pause = useCallback(() => { pauseRef.current?.() }, [])
  const seekTo = useCallback((time: number) => { seekRef.current?.(time) }, [])
  const destroy = useCallback(() => { cleanupRef.current?.() }, [])
  return { play, pause, seekTo, destroy, state, duration, currentTime, pendingSeekPosition }
}
