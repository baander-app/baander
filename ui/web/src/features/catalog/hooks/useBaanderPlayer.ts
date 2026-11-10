import { useEffect, useRef, useCallback, useState } from 'react'
import { isAxiosError, isCancel } from 'axios'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { attachNativePlayback } from './native-playback'
import { attachHlsPlayback, type SubtitlePreference } from './hls-playback'
import type { PlaybackObservation } from '@/features/session/services/playback-execution'
import type { PostTranscodeSessionPosition200 } from '@/shared/api-client/gen/endpoints'
import { detectDestinationCapabilities, negotiateMediaDelivery, MediaCompatibilityError, type NegotiatedDelivery } from '@/features/player/services/media-delivery'
import { getPlaybackClientRequestId } from '@/features/session/services/playback-session-coordinator'

interface UseBaanderPlayerOptions {
  videoId: string
  containerRef: React.RefObject<HTMLDivElement | null>
  autoPlay?: boolean
  coordinated?: boolean
  onTimeUpdate?: (currentTime: number, duration: number) => void
  onStateChange?: (state: string) => void
  onError?: (error: MediaError | null) => void
}

type VideoExecutionAction = 'play' | 'pause' | 'seek' | 'volume' | 'stop'

interface VideoExecution {
  localRecovery: {
    readonly eligible: boolean
    fence(): PlaybackObservation | null
    rearmAfterRegistration(): void
  }
  ready(signal: AbortSignal): Promise<void>
  /** Brings the player to a cursor before it is told to play or pause there. */
  prepare(position: number, playing: boolean, signal: AbortSignal): Promise<void>
  execute(action: VideoExecutionAction, value: number | undefined, signal: AbortSignal): Promise<PlaybackObservation>
  quiesce(signal: AbortSignal): Promise<PlaybackObservation>
  observe(): PlaybackObservation
}

interface PlaybackSnapshot {
  url: string | null
  renditionSet: string
  jobId: string
  jobIds: string[]
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

// How close to the source duration a cursor counts as standing at its end.
const SOURCE_END_TOLERANCE = 0.1
// How long a restore at the source end waits for its native seek before it settles without it.
const SOURCE_END_SEEK_GRACE_MS = 1000

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

function supportsLocalVideoRecovery(): boolean {
  const video = document.createElement('video')
  return /Linux/.test(navigator.userAgent) && /(?:Chrome|Chromium)\//.test(navigator.userAgent)
    && !/Android/.test(navigator.userAgent) && 'disableRemotePlayback' in video
    && 'disablePictureInPicture' in video && 'remote' in video
}

export function useBaanderPlayer({ videoId, containerRef, autoPlay = false, coordinated = false, onTimeUpdate, onStateChange, onError }: UseBaanderPlayerOptions) {
  const recoveryFenced = useRef(false)
  const [recoverySupported] = useState(supportsLocalVideoRecovery)
  const executionRef = useRef<VideoExecution | null>(null)
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const cleanupRef = useRef<(() => void) | null>(null)
  const playRef = useRef<(() => void) | null>(null)
  const pauseRef = useRef<(() => void) | null>(null)
  const seekRef = useRef<((time: number) => void) | null>(null)
  const callbacks = useRef({ onTimeUpdate, onStateChange, onError })
  useEffect(() => { callbacks.current = { onTimeUpdate, onStateChange, onError } }, [onTimeUpdate, onStateChange, onError])
  const [state, setState] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [duration, setDuration] = useState(0)
  const [currentTime, setCurrentTime] = useState(0)
  const [pendingSeekPosition, setPendingSeekPosition] = useState<number | null>(null)

  useEffect(() => {
    const container = containerRef.current
    if (!container || !videoId) return
    setError(null)
    setDuration(0)
    setCurrentTime(0)
    setPendingSeekPosition(null)
    const video = document.createElement('video')
    video.style.width = '100%'
    video.style.height = '100%'
    video.controls = !coordinated
    if (coordinated) {
      video.disableRemotePlayback = true
      video.disablePictureInPicture = true
      video.setAttribute('x-webkit-airplay', 'deny')
      video.setAttribute('controlsList', 'noremoteplayback')
    }
    container.appendChild(video)
    videoRef.current = video
    const abort = new AbortController()
    let clientRequestId: Promise<string> | null = null
    let delivery: NegotiatedDelivery | null = null
    let requestRevision = 0
    let appliedRequest = 0
    let snapshot: PlaybackSnapshot | null = null
    let loadedIdentity: string | null = null
    let loadedSet: string | null = null
    // Sessions that expired are replaced; the cursor and status to continue with wait here for the
    // new session. Until the new session has answered a second signing or accepted a seek it is
    // unproven, and its loss is an error, so sessions that are gone at once cannot loop.
    let restart: { position: number, paused: boolean } | null = null
    let restartUnproven = false
    let subtitlePreference: SubtitlePreference | undefined
    let pendingLoad: { identity: string, position: number } | null = null
    let reloadCursor: number | null = null
    let localStatus: 'running' | 'paused' | null = null
    let localStatusOwner: { sessionId: string, jobId: string } | null = null
    let localStatusRevision = 0
    let deferredStatusRevision: number | null = null
    let statusReconciliation: StatusReconciliation | null = null
    let playbackStarted = autoPlay
    let executionPermitted = !recoveryFenced.current && (!coordinated || autoPlay)
    let executionEpoch = 0
    let nativePlayRevision = 0
    let nativePlayPending = false
    let nativePlay: Promise<void> = Promise.resolve()
    let nativePlayError: unknown = null
    let controlError: unknown = null
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
    let sourceEndSeek: { attachment: number, since: number } | null = null
    let settledWhileSeeking = false
    let disposeAttachment: (() => void) | null = null
    let attachmentRevision = 0
    let retiringPlayback: { attachment: number, suppressPause: boolean } | null = null
    let attachmentReady = false
    let attachmentRestored = false
    let attachedCapability: PlaybackCapability | null = null
    let recoveryPosition: number | null = null
    let terminal = false
    let terminalError: Error | null = null
    let observedDeliveryInterruption = false
    let completion: { sessionId: string, jobId: string, generation: number } | null = null
    const completed = () => completion !== null && snapshot !== null && completion.sessionId === snapshot.sessionId
      && completion.jobId === snapshot.jobId && completion.generation === snapshot.generation
      && (snapshot.status === 'running' || snapshot.status === 'paused')
    const playbackPosition = () => restoredSourceEnd !== null && Math.abs(video.currentTime - restoredSourceEnd) <= 0.1
      ? sourceDuration : mediaStart + video.currentTime
    // Where playback is headed: a seek in flight, then the cursor kept while a failed attachment
    // is replaced, then a restore in flight, then the native clock.
    const cursor = () => sourceSeekPosition ?? recoveryPosition ?? (pendingLoad ? mediaStart + pendingLoad.position : playbackPosition())
    const atSourceEnd = (position: number) => sourceDuration > 0 && position >= sourceDuration - SOURCE_END_TOLERANCE
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
      sourceEndSeek = null
      settledWhileSeeking = false
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
      if (recoveryFenced.current || (coordinated && !executionPermitted) || terminal || retiringPlayback?.attachment === attachmentRevision) return
      if (!attachmentReady) { playbackStarted = true; return }
      const revision = attachmentRevision
      const epoch = executionEpoch
      const playRevision = ++nativePlayRevision
      nativePlayPending = true
      nativePlay = video.play().then(() => {
        if (!active() || epoch !== executionEpoch || revision !== attachmentRevision || !playbackStarted) video.pause()
      }).catch(error => {
        if (epoch === executionEpoch) {
          nativePlayError = error
          if (revision === attachmentRevision) failAttachment()
        }
      }).finally(() => {
        if (playRevision === nativePlayRevision) nativePlayPending = false
      })
    }
    const desiredStatus = () => snapshot?.sessionId === null ? (playbackStarted ? 'running' : 'paused') : localStatus ?? snapshot?.status
    const canControlStatus = () => Boolean(snapshot?.sessionId) && (snapshot?.ready || (localStatus !== null
      && localStatusOwner?.sessionId === snapshot?.sessionId && localStatusOwner?.jobId === snapshot?.jobId))
    const applyPlayback = () => {
      if (!active() || terminal) return
      if (recoveryFenced.current || (coordinated && !executionPermitted) || completed() || retiringPlayback?.attachment === attachmentRevision || !playbackStarted || desiredStatus() !== 'running') video.pause()
      else if (video.paused) playVideo()
    }

    const refresh = async (initial = false) => {
      if (!active() || terminal || recoveryFenced.current) return
      if (!initial && snapshot === null) return
      const revision = ++requestRevision
      try {
        const requestId = await (clientRequestId ??= getPlaybackClientRequestId(abort.signal))
        if (!active() || terminal || recoveryFenced.current) return
        const producerless = delivery?.kind === 'original' || delivery?.kind === 'cached'
        if (!delivery || (producerless && (recoveryPosition !== null || (attachedCapability && Date.now() >= attachedCapability.renewAt)))) {
          const capabilities = await detectDestinationCapabilities(video, 'video')
          const decision = await negotiateMediaDelivery({ kind: 'video', id: videoId, capabilities, requestId }, abort.signal)
          if (!active() || terminal || revision < appliedRequest) return
          delivery = decision
        }
        if (delivery.kind === 'incompatible') throw new MediaCompatibilityError(delivery.reason)
        let data: PlaybackSnapshot
        if (delivery.kind === 'original' || delivery.kind === 'cached') {
          data = {
            url: delivery.url, renditionSet: delivery.url, jobId: `delivery:${videoId}`, jobIds: [], sessionId: null,
            generation: 0, revision: 0, ready: true, position: restart?.position ?? (snapshot ? playbackPosition() : 0),
            status: playbackStarted ? 'running' : 'paused', startTime: 0, duration: sourceDuration || video.duration || 0,
          }
        } else {
          const result = await AXIOS_INSTANCE.post<PlaybackSnapshot>('/api/stream/sign', {
            path: `/api/transcode/${videoId}/master.m3u8`,
            requestId,
            ...(delivery.kind === 'encoded' && (initial || snapshot === null || snapshot.sessionId === null) ? {} : {
              refresh: true, jobIds: snapshot && snapshot.sessionId !== null ? snapshot.jobIds : [delivery.jobId!],
              // An attached rendition stays in the signed set on a smaller lead than one that has to join.
              ...(disposeAttachment !== null && loadedSet ? { attachedSet: loadedSet } : {}),
            }),
          }, { signal: abort.signal, timeout: 5000 })
          data = result.data
        }
        if (!active() || terminal || revision < appliedRequest) return
        const previous = snapshot
        // A later response cannot move an attached job backwards in durable state.
        if (previous?.jobId === data.jobId && (data.generation < previous.generation || data.revision < previous.revision)) return
        // A session that answers a signing after its first one is held: a later expiry is a new one.
        if (previous?.sessionId && previous.sessionId === data.sessionId) restartUnproven = false
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
        const resumed = restart
        restart = null
        // A delivery without a session has none that could end again: nothing is left to prove.
        if (resumed && !data.sessionId) restartUnproven = false
        const transferredCursor = !data.sessionId ? null : resumed ? resumed.position : previous?.sessionId === null ? playbackPosition() : null
        snapshot = data
        if (transferredCursor !== null && transferredCursor > 0) {
          sourceSeekPosition = transferredCursor
          setPendingSeekPosition(transferredCursor)
          enqueueSeek(transferredCursor)
          // A start cannot carry a status: the new session runs until it is told to pause. The
          // pause follows the seek, so the session ends paused at the kept cursor.
          if (resumed?.paused) control('pause', transferredCursor)
          return
        }
        if (resumed?.paused && data.sessionId) control('pause', 0)
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
          // Without a session the set is the delivery's URL, which is no rendition set to sign.
          loadedSet = data.sessionId ? data.renditionSet : null
          sourceEndSeek = null
          settledWhileSeeking = false
          retiringPlayback = null
          attachmentReady = false
          disposeAttachment?.()
          const attachment = ++attachmentRevision
          video.removeAttribute('src')
          video.load()
          disposeAttachment = (delivery.kind === 'original' ? attachNativePlayback : attachHlsPlayback)({
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
        if (error instanceof MediaCompatibilityError && active()) {
          terminalError = error
          setError(error.message)
        }
        const status = isAxiosError(error) ? error.response?.status : undefined
        if (status === 410 && snapshot?.sessionId) {
          endSessions((error as { response?: { data?: { reason?: unknown } } }).response?.data?.reason)
          return
        }
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

    // The server no longer holds any of the signed sessions. Sessions that expired are replaced by
    // new ones at the same cursor, unless the ended ones were themselves an unproven replacement;
    // a failed or cancelled encode is not started again.
    const endSessions = (reason: unknown) => {
      const message = reason === 'failed' ? 'The video could not be converted for playback.'
        : reason === 'cancelled' ? 'Playback of this video was cancelled.'
          : reason !== 'expired' || restartUnproven ? 'Playback could not continue on a new session.' : null
      if (message !== null) {
        terminalError = new Error(message)
        setError(message)
        fail()
        return
      }
      const position = cursor()
      // A paused player continues paused. Its element must not start on the new session's
      // running status before the pause has reached it, so it waits for a new request to play.
      const paused = desiredStatus() === 'paused'
      if (paused) playbackStarted = false
      restartUnproven = true
      restart = { position, paused }
      controlError = null
      statusReconciliation = null
      cancelSeekReconciliation()
      canonicalSeekRecovery = null
      seekChain = null
      recoveryPosition = null
      localStatus = null
      localStatusOwner = null
      deferredStatusRevision = null
      completion = null
      progressPosition = null
      sourceSeekPosition = null
      setPendingSeekPosition(position)
      releaseAttachment()
      loadedIdentity = null
      loadedSet = null
      attachedCapability = null
      observedDeliveryInterruption = false
      snapshot = null
      // The negotiated delivery names the ended jobs; a new one is negotiated with the new start.
      delivery = null
      appliedRequest = requestRevision + 1
      changeState('preparing')
      refresh(true).catch(() => fail())
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
          if (chain) performance.mark('baander:seek-send', { detail: { position: payload.position, generation: payload.generation,
            targetPosition: seekTarget?.position ?? null, remaining: chain.remaining } })
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
          // The endpoint also answers 200 for a session that has ended. Only a seek the session
          // accepted into a generation shows that it is held, so a later expiry is a new one.
          if (snapshot?.sessionId === sessionId && data.data?.acceptedGeneration !== undefined) restartUnproven = false
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
        // A control that fails after its session was replaced says nothing about the new one.
        if (snapshot?.sessionId !== sessionId) return
        controlError = error
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
        // Seeking an already starting native HLS stream can flush its initial
        // buffer before the next fragment is available. Allow small native preroll.
        let nativeTarget = load.position
        const sourceEnd = sourceDuration > 0 && Math.abs(mediaStart + load.position - sourceDuration) < 0.000001
        const lastEnd = video.seekable.length > 0 ? video.seekable.end(video.seekable.length - 1) : null
        // Only the known source EOF may round back to a published native end.
        // Ordinary seeks must wait for their actual target to become seekable.
        if (sourceEnd && Number.isFinite(video.duration) && video.duration > 0 && lastEnd !== null
          && nativeTarget > lastEnd && nativeTarget - lastEnd <= 0.1) nativeTarget = lastEnd
        // A native seek to the source end can stay pending for good: the published media may end
        // a few milliseconds short of the source duration, and nothing follows it. The element then
        // stands at the end without ever reporting the seek done, so the restore settles without it.
        const pendingAtSourceEnd = sourceEnd && video.seeking && sourceEndSeek?.attachment === attachmentRevision
          && Math.abs(video.currentTime - nativeTarget) <= 0.05
          && Date.now() - sourceEndSeek.since >= SOURCE_END_SEEK_GRACE_MS
        if (video.seeking && canonicalSeekRecovery !== seekIntentRevision && !pendingAtSourceEnd) {
          applyPlayback()
          return
        }
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
          if (sourceEnd && sourceEndSeek?.attachment !== attachmentRevision) sourceEndSeek = { attachment: attachmentRevision, since: Date.now() }
        }
        applyPlayback()
        if ((video.seeking && !pendingAtSourceEnd) || Math.abs(video.currentTime - nativeTarget) > 0.05) return
        settledWhileSeeking = video.seeking
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
      performance.mark('baander:seek-intent', { detail: { position, chain: seekChain === null ? 'new' : 'existing',
        started: seekChain?.started ?? null, remaining: seekChain?.remaining ?? null,
        pendingTarget: seekChain?.pendingTarget?.position ?? null } })
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
    // Play on a movie that stands at its source end starts it again from zero. A player that
    // reached the end itself remembers its completion; any other one, such as a fresh player
    // prepared at the end, is told so by the caller.
    const replay = (standsAtSourceEnd = false) => {
      if ((!completed() && !standsAtSourceEnd) || !active() || terminal) return false
      completion = null
      playbackStarted = true
      retiringPlayback = { attachment: attachmentRevision, suppressPause: !video.paused }
      video.pause()
      seek(0)
      if (canControlStatus() && desiredStatus() === 'paused') control('resume', 0)
      return true
    }
    const updateDuration = () => {
      if (!active()) return
      if (snapshot?.sessionId === null && Number.isFinite(video.duration) && video.duration > 0) {
        sourceDuration = video.duration
        snapshot.duration = video.duration
      }
      if (snapshot?.duration !== null && snapshot?.duration !== undefined) setDuration(snapshot.duration)
    }
    video.addEventListener('durationchange', updateDuration)
    video.addEventListener('loadedmetadata', () => {
      if (!active()) return
      updateDuration()
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
      if (recoveryFenced.current || (coordinated && !executionPermitted)) { video.pause(); return }
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
      if (snapshot?.sessionId === null) playbackStarted = false
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
      settledWhileSeeking = false
    })
    video.addEventListener('seeking', () => {
      if (completed() && !video.paused && video.currentTime <= 0.05 && replay()) return
      if (!active() || sourceSeekPosition !== null || !snapshot?.ready || !snapshot.sessionId || pendingLoad
        || loadedIdentity !== `${snapshot.jobId}:${snapshot.generation}:${snapshot.renditionSet}`) return
      if (restoredPosition !== null && Math.abs(video.currentTime - restoredPosition) <= 0.05) return
      if (restoredSourceEnd !== null && Math.abs(video.currentTime - restoredSourceEnd) <= 0.05) return
      // Player-internal seeks (buffer-gap corrections, attachment restores) must not
      // become server seeks: a server seek restarts the encoders into a new generation
      // and replaces the attachment, which flushes the buffer and invites the next
      // correction. The moved cursor reaches the server through progress reports.
      performance.mark('baander:native-seek', { detail: { currentTime: video.currentTime, snapshotPosition: snapshot.position } })
    })
    const seek = (time: number) => {
      performance.mark('baander:seek-entry', { detail: { time, active: active(), terminal, snapshot: Boolean(snapshot),
        session: snapshot?.sessionId ?? null, sourceDuration } })
      if (!active() || terminal || !snapshot || !Number.isFinite(time) || sourceDuration <= 0) return
      const position = Math.max(0, Math.min(sourceDuration, time))
      if (!snapshot.sessionId) {
        video.currentTime = position
        setCurrentTime(position)
        return
      }
      sourceSeekPosition = position
      setPendingSeekPosition(position)
      enqueueSeek(position)
    }
    const requestPlay = () => {
      if (!active() || terminal || recoveryFenced.current) return
      executionPermitted = true
      // Without a transcode session the element restarts an ended source by itself.
      if (replay(Boolean(snapshot?.sessionId) && !seekChain && atSourceEnd(cursor()))) return
      playbackStarted = true
      if (canControlStatus() && desiredStatus() === 'paused') control('resume')
      if (attachmentReady) playVideo()
    }
    const requestPause = () => {
      if (!active() || terminal) return
      executionPermitted = false
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
    const observe = (): PlaybackObservation => ({
      observedState: terminal ? 'unknown' : video.paused ? 'paused' : 'playing',
      position: playbackPosition(),
      volume: video.volume,
    })
    const waitFor = async (condition: () => boolean, signal: AbortSignal, epoch = executionEpoch) => {
      while (true) {
        signal.throwIfAborted()
        if (!active() || recoveryFenced.current || epoch !== executionEpoch) throw new DOMException('Video execution was replaced.', 'AbortError')
        if (nativePlayError) throw nativePlayError
        if (controlError) throw controlError
        if (terminal) throw terminalError ?? new Error('Video source could not be prepared.')
        if (condition()) return
        await new Promise<void>(resolve => window.setTimeout(resolve, 20))
      }
    }
    const ready = async (signal: AbortSignal) => {
      await waitFor(() => {
        restorePendingLoad()
        return attachmentReady && attachmentRestored && !pendingLoad && !seekChain && sourceSeekPosition === null
          && (!video.seeking || settledWhileSeeking)
      }, signal)
    }
    // Waits until a cursor can be named. A session takes one before any restore has settled, once
    // its duration is known; that arrives with the first prepared snapshot.
    const readyToSeek = async (signal: AbortSignal) => {
      await waitFor(() => snapshot !== null, signal)
      // Without a transcode session the element is its own clock and has to be loaded first.
      if (snapshot?.sessionId === null) await ready(signal)
      else await waitFor(() => sourceDuration > 0, signal)
    }
    const locallyControlled = () => recoverySupported && coordinated && video.disableRemotePlayback
      && video.disablePictureInPicture && !video.controls && video.getAttribute('x-webkit-airplay') === 'deny'
      && video.remote?.state === 'disconnected'
    const fence = (): PlaybackObservation | null => {
      recoveryFenced.current = true
      executionEpoch++
      executionPermitted = false
      playbackStarted = false
      const position = playbackPosition()
      video.pause()
      return video.paused && locallyControlled() ? { observedState: 'stopped', position, volume: video.volume } : null
    }
    const execution: VideoExecution = {
      localRecovery: {
        get eligible() { return locallyControlled() },
        fence,
        rearmAfterRegistration: () => {
          recoveryFenced.current = false
          if (!snapshot) refresh(true).catch(() => fail())
        },
      },
      ready,
      observe,
      prepare: async (position, playing, signal) => {
        signal.throwIfAborted()
        await readyToSeek(signal)
        // Playing from the source end is a replay; a paused cursor stays at the end.
        const target = playing && atSourceEnd(position) ? 0 : sourceDuration > 0 ? Math.max(0, Math.min(sourceDuration, position)) : position
        if (Math.abs(cursor() - target) > 0.05) await execution.execute('seek', target, signal)
        else await ready(signal)
      },
      execute: async (action, value, signal) => {
        signal.throwIfAborted()
        const epoch = executionEpoch
        controlError = null
        nativePlayError = null
        if (action === 'stop') {
          const observation = await execution.quiesce(signal)
          cleanup()
          return { ...observation, observedState: 'stopped' }
        }
        // A seek names a new target, so it does not wait for a restore of the old one to settle;
        // it needs the session's duration, which arrives with the first prepared snapshot.
        if (action !== 'seek') await ready(signal)
        else await readyToSeek(signal)
        const cancel = () => {
          executionEpoch++
          executionPermitted = false
          playbackStarted = false
          video.pause()
        }
        signal.addEventListener('abort', cancel, { once: true })
        try {
          if (action === 'play') requestPlay()
          else if (action === 'pause') requestPause()
          else if (action === 'seek') {
            if (value === undefined || !Number.isFinite(value)) throw new Error('Video seek position is invalid.')
            seek(value)
          } else if (action === 'volume') {
            if (value === undefined || !Number.isFinite(value) || value < 0 || value > 1) throw new Error('Video volume is invalid.')
            video.volume = value
          }
          await waitFor(() => pendingControls === 0 && statusReconciliation === null && !seekReconciliation, signal, epoch)
          if (action === 'seek') await ready(signal)
          await waitFor(() => !nativePlayPending, signal, epoch)
          await nativePlay
          await waitFor(() => action === 'play' ? !video.paused : action === 'pause' ? video.paused : true, signal, epoch)
          return observe()
        } finally {
          signal.removeEventListener('abort', cancel)
        }
      },
      quiesce: async signal => {
        signal.throwIfAborted()
        executionEpoch++
        executionPermitted = false
        playbackStarted = false
        video.pause()
        while (pendingControls > 0) {
          signal.throwIfAborted()
          if (!active()) throw new DOMException('Video execution was replaced.', 'AbortError')
          await new Promise<void>(resolve => window.setTimeout(resolve, 20))
        }
        signal.throwIfAborted()
        video.pause()
        return observe()
      },
    }
    executionRef.current = execution
    window.addEventListener('pagehide', fence)
    refresh(true).catch(() => fail())
    const timer = window.setInterval(() => {
      if (recoveryFenced.current) return
      if (snapshot?.sessionId === null && recoveryPosition === null && (!attachedCapability || Date.now() < attachedCapability.renewAt)) return
      control('progress')
      restorePendingLoad()
      refresh().catch(() => fail())
    }, 1000)
    const cleanup = () => {
      if (abort.signal.aborted) return
      window.clearInterval(timer)
      window.removeEventListener('pagehide', fence)
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
      if (executionRef.current === execution) executionRef.current = null
      if (cleanupRef.current === cleanup) cleanupRef.current = null
    }
    cleanupRef.current = cleanup
    return cleanup
  }, [videoId, containerRef, autoPlay, coordinated, recoverySupported])

  const play = useCallback(() => { playRef.current?.() }, [])
  const pause = useCallback(() => { pauseRef.current?.() }, [])
  const seekTo = useCallback((time: number) => { seekRef.current?.(time) }, [])
  const destroy = useCallback(() => { cleanupRef.current?.() }, [])
  const [execution] = useState<VideoExecution>(() => ({
    localRecovery: {
      get eligible() { return executionRef.current?.localRecovery.eligible ?? (recoverySupported && coordinated) },
      fence: () => {
        recoveryFenced.current = true
        const current = executionRef.current
        return current ? current.localRecovery.fence() : recoverySupported && coordinated ? { observedState: 'stopped', position: 0 } : null
      },
      rearmAfterRegistration: () => {
        recoveryFenced.current = false
        executionRef.current?.localRecovery.rearmAfterRegistration()
      },
    },
    ready: signal => executionRef.current?.ready(signal) ?? Promise.reject(new Error('Video runtime is unavailable.')),
    prepare: (position, playing, signal) => executionRef.current?.prepare(position, playing, signal) ?? Promise.reject(new Error('Video runtime is unavailable.')),
    execute: (action, value, signal) => executionRef.current?.execute(action, value, signal) ?? Promise.reject(new Error('Video runtime is unavailable.')),
    quiesce: signal => executionRef.current?.quiesce(signal) ?? Promise.resolve({ observedState: 'stopped', position: 0 }),
    observe: () => executionRef.current?.observe() ?? { observedState: 'stopped', position: 0 },
  }))
  return { execution, play, pause, seekTo, destroy, state, error, duration, currentTime, pendingSeekPosition }
}
