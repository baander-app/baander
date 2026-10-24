import { useEffect, useRef, useCallback, useState } from 'react'
import { isAxiosError, isCancel } from 'axios'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { attachHlsPlayback, type SubtitlePreference } from './hls-playback'
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
  generation: number | null
  remaining: number
  pendingTarget: PlaybackSeekTarget | null
}

interface PlaybackSeekTarget {
  chain: PlaybackSeekChain
  position: number
  first: boolean
}

interface SeekReconciliation {
  chain: PlaybackSeekChain
  mode: 'rebase' | 'fallback'
  minimumReadRequest: number
  intentRevision: number
  resolve: (snapshot: PlaybackSnapshot | null) => void
}

function isTransientControlFailure(error: unknown): boolean {
  if (!isAxiosError(error) || isCancel(error)) return false
  if (error.response === undefined) return ['ERR_NETWORK', 'ECONNABORTED', 'ETIMEDOUT'].includes(error.code ?? '')
  return error.response.status >= 500 && error.response.status <= 599
}

interface StatusReconciliation {
  sessionId: string
  jobId: string
  statusRevision: number
  minimumReadRequest: number
}

interface PlaybackCapability {
  expiresAt: number
  renewAt: number
}

function playbackCapability(url: string): PlaybackCapability | null {
  const value = new URL(url, window.__BAANDER_API_URL__ || window.location.origin).searchParams.get('exp')
  if (value === null || !/^[0-9]+$/.test(value)) return null
  const seconds = Number(value)
  const expiresAt = (seconds + 1) * 1000
  if (!Number.isSafeInteger(seconds) || seconds <= 0 || !Number.isSafeInteger(expiresAt)) return null
  // Delivery accepts the last signed integer second. Renew before its end,
  // allowing short leases to retain most of their useful lifetime.
  return { expiresAt, renewAt: expiresAt - Math.min(30000, Math.max(0, expiresAt - Date.now()) / 4) }
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
    let subtitlePreference: SubtitlePreference | undefined
    let pendingLoad: { identity: string, position: number } | null = null
    let reloadCursor: number | null = null
    let localStatus: 'running' | 'paused' | null = null
    let localStatusOwner: { sessionId: string, jobId: string } | null = null
    let localStatusRevision = 0
    let deferredStatusRevision: number | null = null
    let statusReconciliation: StatusReconciliation | null = null
    let playbackStarted = autoPlay
    let controls = Promise.resolve()
    let pendingControls = 0
    let progressPosition: number | null = null
    let mediaStart = 0
    let restoredPosition: number | null = null
    let seekChain: PlaybackSeekChain | null = null
    let seekReconciliation: SeekReconciliation | null = null
    let seekIntentRevision = 0
    let canonicalSeekRecovery: number | null = null
    let sourceSeekPosition: number | null = null
    let sourceDuration = 0
    let restoredSourceEnd: number | null = null
    let disposeAttachment: (() => void) | null = null
    let attachmentRevision = 0
    let retiringPlayback: { attachment: number, suppressPause: boolean } | null = null
    let attachmentReady = false
    let attachmentRestored = false
    let attachedCapability: PlaybackCapability | null = null
    let recoveryPosition: number | null = null
    let terminal = false
    let observedDeliveryInterruption = false
    let completion: { sessionId: string, jobId: string, generation: number } | null = null
    const completed = () => completion !== null && snapshot !== null && completion.sessionId === snapshot.sessionId
      && completion.jobId === snapshot.jobId && completion.generation === snapshot.generation
      && (snapshot.status === 'running' || snapshot.status === 'paused')
    const playbackPosition = () => restoredSourceEnd !== null && Math.abs(video.currentTime - restoredSourceEnd) <= 0.1
      ? sourceDuration : mediaStart + video.currentTime
    const clearSourceSeek = () => {
      sourceSeekPosition = null
      setPendingSeekPosition(null)
    }
    const active = () => !abort.signal.aborted && videoRef.current === video
    const changeState = (next: string) => {
      if (!active() || (terminal && next !== 'error')) return
      setState(next)
      callbacks.current.onStateChange?.(next)
    }
    const releaseAttachment = () => {
      pendingLoad = null
      reloadCursor = null
      restoredPosition = null
      restoredSourceEnd = null
      attachmentReady = false
      attachmentRestored = false
      retiringPlayback = null
      disposeAttachment?.()
      attachmentRevision++
      disposeAttachment = null
    }
    const cancelSeekReconciliation = () => {
      const pending = seekReconciliation
      seekReconciliation = null
      pending?.resolve(null)
    }
    const fail = (terminalFailure = true) => {
      if (!active() || terminal) return
      terminal = terminalFailure
      completion = null
      statusReconciliation = null
      cancelSeekReconciliation()
      canonicalSeekRecovery = null
      recoveryPosition = null
      clearSourceSeek()
      seekChain = null
      releaseAttachment()
      changeState('error')
      callbacks.current.onError?.(video.error)
    }
    const nativeFailure = () => video.error?.code === 2 ? 'network' as const : undefined
    const failAttachment = (failure?: 'network') => {
      if (!active() || terminal || recoveryPosition !== null) return
      // Retiring media can fail before its replacement seek is acknowledged.
      // Keep the submitted controls and latest target owned until they settle.
      if (seekReconciliation || (seekChain?.started && seekChain.remaining > 0)
        || (snapshot && loadedIdentity && !loadedIdentity.startsWith(`${snapshot.jobId}:${snapshot.generation}:`))) {
        releaseAttachment()
        changeState('preparing')
      } else if (failure === 'network' && attachedCapability
        && (observedDeliveryInterruption || Date.now() >= attachedCapability.expiresAt)) {
        recoveryPosition = pendingLoad ? mediaStart + pendingLoad.position : playbackPosition()
        releaseAttachment()
        changeState('preparing')
      } else fail()
    }
    const playVideo = () => {
      if (terminal || retiringPlayback?.attachment === attachmentRevision) return
      if (!attachmentReady) { playbackStarted = true; return }
      const revision = attachmentRevision
      video.play().catch(() => {
        if (revision === attachmentRevision) failAttachment()
      })
    }
    const desiredStatus = () => localStatus ?? snapshot?.status
    const canControlStatus = () => snapshot?.ready || (localStatus !== null
      && localStatusOwner?.sessionId === snapshot?.sessionId && localStatusOwner?.jobId === snapshot?.jobId)
    const applyPlayback = () => {
      if (!active() || terminal) return
      if (completed() || retiringPlayback?.attachment === attachmentRevision || !playbackStarted || desiredStatus() !== 'running') video.pause()
      else if (video.paused) playVideo()
    }

    const refresh = async (initial = false) => {
      if (!active() || terminal) return
      const revision = ++requestRevision
      try {
        const { data } = await AXIOS_INSTANCE.post<PlaybackSnapshot>('/api/stream/sign', {
          path: `/api/transcode/${videoId}/master.m3u8`,
          ...(initial ? {} : { refresh: true }),
        }, { signal: abort.signal, timeout: 5000 })
        if (!active() || terminal || revision < appliedRequest) return
        const previous = snapshot
        // A later response cannot move an attached job backwards in durable state.
        if (previous?.jobId === data.jobId && (data.generation < previous.generation || data.revision < previous.revision)) return
        const identity = `${data.jobId}:${data.generation}:${data.renditionSet}`
        const seekRead = seekReconciliation
        if (seekRead && (data.sessionId !== seekRead.chain.sessionId || data.jobId !== seekRead.chain.jobId
          || (data.status !== 'running' && data.status !== 'paused'))) {
          cancelSeekReconciliation()
          seekChain = null
          canonicalSeekRecovery = null
          clearSourceSeek()
        }
        const settlesSeek = seekRead !== null && seekReconciliation === seekRead && revision >= seekRead.minimumReadRequest
        if (data.status === 'cancelled') {
          seekChain = null
          canonicalSeekRecovery = null
        }
        if (seekChain) {
          // Only this seek's acknowledgement can carry pending intent forward.
          // Even a refresh started before the seek must leave its source attached.
          if (seekChain.sessionId !== data.sessionId || seekChain.jobId !== data.jobId) seekChain = null
          else if (seekChain.started && seekChain.remaining > 0 && !settlesSeek) return
          else if (seekChain.started && !settlesSeek) seekChain = null
          else if (!seekChain.started && seekChain.generation !== null && seekChain.attachment !== identity) seekChain = null
        }
        const recoverAcceptedSeek = recoveryPosition !== null && sourceSeekPosition !== null && !seekChain
        // Let an active native seek finish before replacing its source to add
        // renditions. Committed execution changes still apply immediately.
        if (data.status !== 'cancelled' && previous?.jobId === data.jobId && previous.generation === data.generation
          && previous.revision === data.revision && identity !== loadedIdentity
          && loadedIdentity?.startsWith(`${data.jobId}:${data.generation}:`)
          && !settlesSeek && canonicalSeekRecovery === null
          && ((sourceSeekPosition !== null && !recoverAcceptedSeek) || pendingLoad || video.seeking)) return
        const ownerChanged = previous !== null && (previous.jobId !== data.jobId || previous.sessionId !== data.sessionId)
        if (ownerChanged) subtitlePreference = undefined
        appliedRequest = revision
        snapshot = data
        if (!completed()) completion = null
        if (data.duration !== null && Number.isFinite(data.duration) && data.duration > 0) {
          sourceDuration = data.duration
          setDuration(data.duration)
        }
        if (settlesSeek && seekRead) {
          seekReconciliation = null
          seekRead.resolve(data)
          return
        }
        let reconciledStatus = false
        const reconciliation = statusReconciliation
        if (reconciliation) {
          if (data.sessionId !== reconciliation.sessionId || data.jobId !== reconciliation.jobId
            || reconciliation.statusRevision !== localStatusRevision || data.status === 'cancelled') {
            statusReconciliation = null
            if (reconciliation.statusRevision === localStatusRevision) localStatus = null
          } else if (revision >= reconciliation.minimumReadRequest && (data.status === 'running' || data.status === 'paused')) {
            statusReconciliation = null
            if (data.status === 'running') playbackStarted = true
            reconciledStatus = true
          }
        }
        const settledStatus = reconciledStatus || (!seekChain && deferredStatusRevision === localStatusRevision)
        if (settledStatus) {
          localStatus = null
          deferredStatusRevision = null
        }
        if (completed()) return
        // Preparing snapshots can already describe the next generation. Its
        // cursor must be compared with the attached execution, whose native
        // clock may have reset after its retired resources became unreadable.
        const executionChanged = !loadedIdentity?.startsWith(`${data.jobId}:${data.generation}:`)
        if (identity !== loadedIdentity && (executionChanged || reloadCursor !== null || previous?.position !== data.position)) {
          reloadCursor = data.position
        }
        if (data.ready === false && !executionChanged && disposeAttachment !== null
          && attachmentReady && attachmentRestored && pendingLoad === null && (data.status === 'running' || data.status === 'paused')) {
          observedDeliveryInterruption = true
        }
        if (!data.ready || data.url === null || data.startTime === null || data.duration === null) {
          pendingLoad = null
          if (data.status === 'cancelled') { clearSourceSeek(); video.pause(); video.src = ''; changeState('ended'); return }
          if (reconciledStatus || desiredStatus() !== 'running') applyPlayback()
          changeState('preparing')
          return
        }
        const restoringSeek = canonicalSeekRecovery === seekIntentRevision && !seekChain
        const capability = playbackCapability(data.url)
        const newerCapability = attachedCapability !== null && capability !== null
          && capability.expiresAt > attachedCapability.expiresAt && capability.expiresAt > Date.now()
        // Recovery of the attached execution needs renewed authority even when
        // newly published renditions would otherwise replace its identity.
        if (recoveryPosition !== null && !executionChanged && !newerCapability) return
        const renewCapability = identity === loadedIdentity && newerCapability && attachedCapability !== null
          && (recoveryPosition !== null || Date.now() >= attachedCapability.renewAt)
          && !seekChain && (sourceSeekPosition === null || recoverAcceptedSeek) && !pendingLoad && !video.seeking
        if (ownerChanged || identity !== loadedIdentity || renewCapability || (restoringSeek && disposeAttachment === null)) {
          const preserveAttachedPosition = renewCapability || (recoveryPosition !== null && !executionChanged)
          // An acknowledged seek submitted after release owns the replacement
          // cursor; its response fence keeps older polling intent out.
          const renewalPosition = recoverAcceptedSeek ? data.position : recoveryPosition ?? playbackPosition()
          recoveryPosition = null
          attachedCapability = capability
          observedDeliveryInterruption = false
          restoredSourceEnd = null
          progressPosition = data.position
          const attachedPosition = mediaStart + (pendingLoad?.position ?? video.currentTime)
          const position = restoringSeek ? data.position : preserveAttachedPosition ? renewalPosition : reloadCursor ?? Math.max(data.position, attachedPosition)
          mediaStart = data.startTime
          setDuration(data.duration)
          attachmentRestored = false
          pendingLoad = { identity, position: Math.max(0, position - mediaStart) }
          loadedIdentity = identity
          retiringPlayback = null
          attachmentReady = false
          disposeAttachment?.()
          const attachment = ++attachmentRevision
          video.removeAttribute('src')
          video.load()
          disposeAttachment = attachHlsPlayback({
            video,
            url: new URL(data.url, window.__BAANDER_API_URL__ || window.location.origin).href,
            position: pendingLoad.position,
            getSubtitlePreference: () => subtitlePreference,
            onSubtitlePreferenceChange: preference => {
              if (active() && !terminal && attachment === attachmentRevision
                && snapshot?.jobId === data.jobId && snapshot.sessionId === data.sessionId) subtitlePreference = preference
            },
            onReady: () => {
              if (active() && !terminal && attachment === attachmentRevision) {
                attachmentReady = true
                applyPlayback()
              }
            },
            onError: (failure) => {
              if (attachment === attachmentRevision) failAttachment(failure)
            },
          })
          // Attachment readiness applies playback intent; metadata restoration
          // retains the committed cursor until the actual timeline is seekable.
        } else if (previous?.revision !== data.revision || settledStatus) {
          applyPlayback()
        }
        if ((sourceSeekPosition !== null || restoringSeek) && !seekChain && !pendingLoad && identity === loadedIdentity) {
          attachmentRestored = false
          pendingLoad = { identity, position: Math.max(0, data.position - mediaStart) }
          restorePendingLoad()
        }
      } catch (error) {
        if (!active() || terminal || revision < appliedRequest) return
        const status = isAxiosError(error) ? error.response?.status : undefined
        const transient = isAxiosError(error) && !isCancel(error)
          && (error.response === undefined || (status !== undefined && status >= 500 && status <= 599))
        // Once signing accepted ownership, transport failures preserve its
        // preparation and cursor. A delayed media failure can still belong to
        // this outage after polling resumes; the next attachment consumes it.
        if (!initial && snapshot && transient) {
          observedDeliveryInterruption = true
          return
        }
        fail()
      }
    }

    const readSeekState = (chain: PlaybackSeekChain, mode: SeekReconciliation['mode']) => {
      let pending!: SeekReconciliation
      const state = new Promise<PlaybackSnapshot | null>(resolve => {
        pending = { chain, mode, minimumReadRequest: requestRevision + 1, intentRevision: seekIntentRevision, resolve }
        seekReconciliation = pending
      })
      appliedRequest = Math.max(appliedRequest, pending.minimumReadRequest)
      refresh().catch(() => fail())
      return { pending, state }
    }

    const control = (action: 'seek' | 'pause' | 'resume' | 'progress', globalPosition = sourceSeekPosition ?? recoveryPosition ?? playbackPosition(), seekTarget?: PlaybackSeekTarget) => {
      const current = snapshot
      const sessionId = current?.sessionId
      if (!sessionId || !current || !active() || terminal) return
      if (action === 'progress' && (completed() || recoveryPosition !== null || sourceSeekPosition !== null || pendingControls > 0 || pendingLoad || !current.ready
        || current.status !== 'running' || video.paused || video.seeking
        || Math.abs(globalPosition - (progressPosition ?? current.position)) < 1)) return
      const position = globalPosition
      const statusRevision = action === 'pause' || action === 'resume' ? ++localStatusRevision : null
      if (statusRevision !== null) {
        statusReconciliation = null
        localStatus = action === 'pause' ? 'paused' : 'running'
        localStatusOwner = { sessionId, jobId: current.jobId }
        if (seekChain) seekChain.pendingTarget = null
      }
      pendingControls++
      controls = controls.then(async () => {
        if (!active() || terminal) return
        const owned = () => active() && !terminal && snapshot?.sessionId === sessionId && snapshot.jobId === current.jobId
          && (snapshot.status === 'running' || snapshot.status === 'paused')
        if ((statusRevision !== null || seekTarget) && !owned()) return
        if (action === 'progress' && (completed() || recoveryPosition !== null || sourceSeekPosition !== null || snapshot?.sessionId !== sessionId
          || snapshot.generation !== current.generation || snapshot.revision !== current.revision
          || snapshot.status !== 'running' || video.paused || video.seeking)) return
        const chain = seekTarget?.chain
        if (seekTarget && chain) {
          if (!seekTarget.first && seekChain !== chain) return
          if (seekChain === chain) chain.started = true
          if (chain.pendingTarget === seekTarget) chain.pendingTarget = null
        }
        let seekHandled = false
        let uncertainSeek = false
        try {
          if (chain?.generation === null) {
            if (!snapshot || !owned()) { seekHandled = true; return }
            chain.generation = snapshot.generation
          }
          const payload = { position: seekTarget?.position ?? position, action,
            ...(action === 'progress' ? { generation: current.generation, revision: current.revision } : {}),
            ...(chain ? { generation: chain.generation, requestId: crypto.randomUUID() } : {}),
          }
          const send = () => AXIOS_INSTANCE.post<{ data?: PostTranscodeSessionPosition200 }>(
            `/api/transcode/sessions/${sessionId}/position`, payload, { signal: abort.signal, timeout: 5000 })
          let response
          try { response = await send() }
          catch (error) {
            if (!chain || !isTransientControlFailure(error)) throw error
            uncertainSeek = true
            if (!owned()) { seekHandled = true; return }
            try { response = await send() }
            catch (retryError) {
              if (!isTransientControlFailure(retryError)) throw retryError
              seekHandled = true
              if (!owned()) return
              if (seekChain === chain) seekChain = null
              chain.pendingTarget = null
              const waiting = readSeekState(chain, 'fallback')
              const canonical = await waiting.state
              if (!canonical || !owned()) return
              if (waiting.pending.intentRevision === seekIntentRevision) {
                canonicalSeekRecovery = seekIntentRevision
                restoredPosition = null
                appliedRequest = requestRevision + 1
                await refresh()
              }
              return
            }
          }
          if (chain && !owned()) { seekHandled = true; return }
          const { data } = response
          if (chain && uncertainSeek && data.data?.acceptedGeneration === undefined && seekChain === chain) {
            const waiting = readSeekState(chain, 'rebase')
            const canonical = await waiting.state
            if (!canonical || !owned() || seekChain !== chain) { seekHandled = true; return }
            chain.generation = canonical.generation
          } else if (chain && seekChain === chain) {
            if (data.data?.acceptedGeneration === undefined) seekChain = null
            else chain.generation = data.data.acceptedGeneration
          }
        } catch (error) {
          if (chain && !owned()) { seekHandled = true; return }
          if (chain && seekChain === chain) seekChain = null
          throw error
        } finally {
          if (chain) {
            chain.remaining--
            if (!seekHandled && (seekChain !== chain || chain.remaining === 0)) {
              // Only a read started after acceptance may replace this attachment.
              if (uncertainSeek) {
                canonicalSeekRecovery = seekIntentRevision
                restoredPosition = null
              }
              appliedRequest = requestRevision + 1
              await refresh()
            }
          }
        }
        if (action === 'progress' && snapshot?.generation === current.generation) progressPosition = position
        if (!chain) await refresh()
      }).catch(async error => {
        if ((action === 'progress' || statusRevision !== null) && isTransientControlFailure(error)) {
          if (statusRevision === null) return
          if (!active() || terminal || snapshot?.sessionId !== sessionId || snapshot.jobId !== current.jobId
            || statusRevision !== localStatusRevision) return
          statusReconciliation = { sessionId, jobId: current.jobId, statusRevision, minimumReadRequest: requestRevision + 1 }
          await refresh()
          return
        }
        // A failed control belongs to the session that submitted it.
        if (snapshot?.sessionId === sessionId && snapshot.jobId === current.jobId) fail(false)
      }).finally(() => {
        pendingControls--
        if (statusRevision !== null && statusRevision === localStatusRevision
          && statusReconciliation?.statusRevision !== statusRevision) {
          if (seekChain?.started) deferredStatusRevision = statusRevision
          else {
            localStatus = null
            applyPlayback()
          }
        }
      })
    }

    const restorePendingLoad = () => {
      if (!active() || terminal || !attachmentReady) return
      if (sourceSeekPosition !== null && seekChain) return
      const load = pendingLoad
      if (load && snapshot?.ready && `${snapshot.jobId}:${snapshot.generation}:${snapshot.renditionSet}` === load.identity) {
        if (video.readyState < HTMLMediaElement.HAVE_METADATA) return
        if (video.seeking && canonicalSeekRecovery !== seekIntentRevision) {
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
        if (video.seeking && canonicalSeekRecovery === seekIntentRevision && restoredPosition !== null
          && Math.abs(restoredPosition - nativeTarget) <= 0.05) {
          applyPlayback()
          return
        }
        restoredPosition = null
        restoredSourceEnd = sourceEnd ? nativeTarget : null
        if (!atTarget || (video.seeking && canonicalSeekRecovery === seekIntentRevision)) {
          restoredPosition = nativeTarget
          video.currentTime = nativeTarget
        }
        applyPlayback()
        if (video.seeking || Math.abs(video.currentTime - nativeTarget) > 0.05) return
        if (retiringPlayback?.attachment === attachmentRevision) {
          retiringPlayback = null
          applyPlayback()
        }
        pendingLoad = null
        canonicalSeekRecovery = null
        attachmentRestored = true
        if (sourceEnd && snapshot.status === 'paused' && desiredStatus() !== 'running' && snapshot.sessionId) {
          completion = { sessionId: snapshot.sessionId, jobId: snapshot.jobId, generation: snapshot.generation }
          changeState('ended')
        }
        reloadCursor = null
        clearSourceSeek()
        setCurrentTime(playbackPosition())
      }
    }
    const enqueueSeek = (position: number) => {
      if (!snapshot?.sessionId) return
      completion = null
      seekIntentRevision++
      if (canonicalSeekRecovery !== null) {
        canonicalSeekRecovery = null
        pendingLoad = null
        restoredPosition = null
      }
      restoredSourceEnd = null
      if (seekChain?.pendingTarget) seekChain.pendingTarget.position = position
      else {
        const first = seekChain === null
        if (!seekChain) {
          seekChain = {
            sessionId: snapshot.sessionId, jobId: snapshot.jobId,
            attachment: loadedIdentity ?? '', started: false,
            generation: seekReconciliation?.mode === 'fallback' ? null : snapshot.generation,
            remaining: 0, pendingTarget: null,
          }
        }
        const target: PlaybackSeekTarget = { chain: seekChain, position, first }
        if (!first || seekChain.generation === null) seekChain.pendingTarget = target
        seekChain.remaining++
        control('seek', position, target)
      }
    }
    const finish = () => {
      if (completed()) return true
      if (!active() || terminal || !snapshot?.sessionId || video.error || !video.ended || video.seeking
        || !attachmentReady || !attachmentRestored || pendingLoad || seekChain || sourceSeekPosition !== null
        || loadedIdentity !== `${snapshot.jobId}:${snapshot.generation}:${snapshot.renditionSet}`
        || sourceDuration <= 0 || Math.abs(mediaStart + video.currentTime - sourceDuration) > 0.1) return false
      completion = { sessionId: snapshot.sessionId, jobId: snapshot.jobId, generation: snapshot.generation }
      restoredSourceEnd = video.currentTime
      setCurrentTime(sourceDuration)
      callbacks.current.onTimeUpdate?.(sourceDuration, sourceDuration)
      changeState('ended')
      if (canControlStatus() && desiredStatus() === 'running') control('pause', sourceDuration)
      return true
    }
    const replay = () => {
      if (!completed() || !active() || terminal) return false
      completion = null
      playbackStarted = true
      retiringPlayback = { attachment: attachmentRevision, suppressPause: !video.paused }
      video.pause()
      seek(0)
      if (canControlStatus() && desiredStatus() === 'paused') control('resume', 0)
      return true
    }
    video.addEventListener('loadedmetadata', () => {
      if (!active()) return
      if (snapshot?.duration !== null && snapshot?.duration !== undefined) setDuration(snapshot.duration)
      restorePendingLoad()
      if (!completed()) changeState('loaded')
    })
    for (const event of ['durationchange', 'loadeddata', 'canplay', 'progress']) {
      video.addEventListener(event, restorePendingLoad)
    }
    video.addEventListener('timeupdate', () => {
      if (terminal || recoveryPosition !== null || sourceSeekPosition !== null || pendingLoad) return
      const position = completed() ? sourceDuration : playbackPosition()
      setCurrentTime(position)
      callbacks.current.onTimeUpdate?.(position, snapshot?.duration ?? mediaStart + video.duration)
    })
    video.addEventListener('play', () => {
      if (video.paused) return
      if (replay()) return
      if (retiringPlayback?.attachment === attachmentRevision) {
        retiringPlayback.suppressPause = true
        video.pause()
      }
      playbackStarted = true
      changeState('playing')
      if (attachmentReady && canControlStatus() && desiredStatus() === 'paused') control('resume')
    })
    video.addEventListener('pause', () => {
      if (!video.paused) return
      if (video.error) {
        failAttachment(nativeFailure())
        return
      }
      if (retiringPlayback?.attachment === attachmentRevision && retiringPlayback.suppressPause) {
        retiringPlayback.suppressPause = false
        return
      }
      if (finish()) return
      changeState('paused')
      if (attachmentReady && canControlStatus() && desiredStatus() === 'running') control('pause')
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
      if (completed() && !video.paused && video.currentTime <= 0.05 && replay()) return
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
      if (!active() || terminal || !snapshot?.sessionId || !Number.isFinite(time) || sourceDuration <= 0) return
      const position = Math.max(0, Math.min(sourceDuration, time))
      sourceSeekPosition = position
      setPendingSeekPosition(position)
      enqueueSeek(position)
    }
    const requestPlay = () => {
      if (!active() || terminal) return
      if (replay()) return
      playbackStarted = true
      if (canControlStatus() && desiredStatus() === 'paused') control('resume')
      if (attachmentReady) playVideo()
    }
    const requestPause = () => {
      if (!active() || terminal) return
      playbackStarted = false
      if (canControlStatus() && desiredStatus() === 'running') control('pause')
      video.pause()
    }
    seekRef.current = seek
    playRef.current = requestPlay
    pauseRef.current = requestPause
    video.addEventListener('ended', () => {
      if (finish()) return
      if (active() && !terminal && attachmentReady && video.ended && !seekChain
        && sourceSeekPosition === null && !pendingLoad && (snapshot?.status === 'running' || snapshot?.status === 'paused')) changeState('ended')
    })
    video.addEventListener('error', () => {
      if (attachmentReady) failAttachment(nativeFailure())
    })
    refresh(true).catch(() => fail())
    const timer = window.setInterval(() => {
      control('progress')
      refresh().catch(() => fail())
    }, 1000)
    const cleanup = () => {
      if (abort.signal.aborted) return
      window.clearInterval(timer)
      statusReconciliation = null
      cancelSeekReconciliation()
      canonicalSeekRecovery = null
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
