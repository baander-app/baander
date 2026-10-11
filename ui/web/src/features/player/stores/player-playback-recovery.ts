import { activityService } from '../services/activity-service'
import { probeServerLiveness } from '../services/server-liveness'
import { takeStreamRetryAfter } from '../services/stream-retry-after'
import { createLogger } from '@/shared/lib/logger'
import { getCurrentTime, updateTime } from './player-time-tracker'
import type { PlaybackInterruption, PlaybackRecovery, PlayerState, Track } from './player-types'

const logger = createLogger('PlaybackRecovery')

/** One budget covers both waiting for the server and waiting for a transcode to complete. */
const RECOVERY_BUDGET_MS = 120_000
const FIRST_BACKOFF_MS = 1_000
const MAX_BACKOFF_MS = 15_000
/** A reload or a resumed play that neither fails nor progresses within this time counts as a stall. */
const STEP_TIMEOUT_MS = 10_000

export interface PlaybackRecoveryHost {
  get: () => PlayerState
  commit: (patch: Partial<PlayerState>) => void
  /** Shows the existing failure notice for a source that could not be resumed. */
  notifyFailure: (src: string) => void
}

type Phase =
  | 'probing' // asking the server whether it answers
  | 'waiting' // backing off before the next probe or request
  | 'loading' // requesting the same source again
  | 'catching-up' // the transcode is still progressive, so it is requested again later
  | 'starting' // seeked to the position, native play pending
  | 'gesture' // the browser wants a user gesture before it plays
  | 'paused' // the listener paused; the position is kept
  | 'stopped' // the budget ran out or the source failed; the position is kept

interface Episode {
  host: PlaybackRecoveryHost
  element: HTMLAudioElement
  track: Track
  src: string
  position: number
  deadline: number
  backoff: number
  phase: Phase
  /** A probe found the server unreachable since the last request for the source. */
  outage: boolean
  /** The source has been requested again, so a later error is that request failing. */
  reloaded: boolean
  /** The interrupted selection had not recorded its play yet, so the resume records it. */
  recordPlay: boolean
  /** Sends the original error down the player's existing paths when it was not an outage. */
  onServerUp: () => void
  step: number
  release?: () => void
}

/** At most one track is being recovered: the active element's current track. */
let episode: Episode | null = null

function viewOf(phase: Phase, isPlaying: boolean): PlaybackRecovery {
  if (phase === 'gesture') return 'tap-to-resume'
  if (phase === 'paused' || phase === 'stopped' || !isPlaying) return 'none'
  return 'reconnecting'
}

function close(ep: Episode): void {
  ep.release?.()
  ep.release = undefined
  ep.step++
  if (episode === ep) episode = null
  if (ep.host.get().playbackRecovery !== 'none') ep.host.commit({ playbackRecovery: 'none' })
}

/** The episode, while its element and track are still the player's current ones. */
function live(): Episode | null {
  const ep = episode
  if (!ep) return null
  const { audioElement, currentTrack } = ep.host.get()
  if (audioElement === ep.element && currentTrack === ep.track) return ep
  close(ep)
  return null
}

function current(ep: Episode, step: number): boolean {
  return episode === ep && ep.step === step && live() === ep
}

/** Moves to `phase`, releasing the previous step's timer and listeners, and publishes the player's state. */
function enter(ep: Episode, phase: Phase, patch: Partial<PlayerState> = {}): number {
  ep.release?.()
  ep.release = undefined
  ep.phase = phase
  ep.step++
  const state = ep.host.get()
  const playbackRecovery = viewOf(phase, patch.isPlaying ?? state.isPlaying)
  const next = playbackRecovery === state.playbackRecovery ? patch : { ...patch, playbackRecovery }
  if (Object.keys(next).length > 0) ep.host.commit(next)
  return ep.step
}

function giveUp(ep: Episode): void {
  enter(ep, 'stopped', { isPlaying: false })
  ep.element.pause()
  ep.host.notifyFailure(ep.src)
}

/** Waits with jittered exponential backoff, or at least `minimumMs`, within the episode's budget. */
function schedule(ep: Episode, phase: 'waiting' | 'catching-up', next: () => void, minimumMs = 0): void {
  const remaining = ep.deadline - Date.now()
  if (remaining <= 0) {
    giveUp(ep)
    return
  }
  const delay = Math.max(minimumMs, ep.backoff * (0.5 + Math.random() / 2))
  ep.backoff = Math.min(ep.backoff * 2, MAX_BACKOFF_MS)
  const step = enter(ep, phase)
  const timer = setTimeout(() => {
    if (!current(ep, step)) return
    if (Date.now() >= ep.deadline) giveUp(ep)
    else next()
  }, Math.min(delay, remaining))
  ep.release = () => clearTimeout(timer)
}

function probe(ep: Episode, cause: PlaybackInterruption): void {
  const step = enter(ep, 'probing')
  probeServerLiveness().then((liveness) => {
    if (!current(ep, step)) return
    if (liveness === 'down') {
      ep.outage = true
      if (ep.host.get().isPlaying) schedule(ep, 'waiting', () => probe(ep, cause))
      else enter(ep, 'paused')
      return
    }
    const retryAfter = takeStreamRetryAfter(ep.src)
    if (ep.outage) ep.backoff = FIRST_BACKOFF_MS
    if (retryAfter !== null) {
      schedule(ep, 'waiting', () => reload(ep), retryAfter)
    } else if (ep.outage || cause === 'stall') {
      reload(ep)
    } else if (!ep.reloaded) {
      close(ep)
      ep.onServerUp()
    } else {
      // The server answers, but refused the source when it was requested again.
      giveUp(ep)
    }
  }).catch((err: unknown) => {
    logger.error('Playback recovery failed:', err)
  })
}

function reload(ep: Episode, patch?: Partial<PlayerState>): void {
  if (Date.now() >= ep.deadline) {
    giveUp(ep)
    return
  }
  const step = enter(ep, 'loading', patch)
  ep.outage = false
  ep.reloaded = true
  const { element } = ep
  const onMetadata = () => {
    if (!current(ep, step)) return
    // A progressive transcode cannot seek. Only the complete rendition has a duration.
    if (Number.isFinite(element.duration) && element.duration > 0) start(ep)
    else schedule(ep, 'catching-up', () => reload(ep))
  }
  const timer = setTimeout(() => {
    if (current(ep, step)) probe(ep, 'stall')
  }, STEP_TIMEOUT_MS)
  element.addEventListener('loadedmetadata', onMetadata)
  ep.release = () => {
    clearTimeout(timer)
    element.removeEventListener('loadedmetadata', onMetadata)
  }
  element.load()
}

function start(ep: Episode): void {
  if (!ep.host.get().isPlaying) {
    enter(ep, 'paused')
    return
  }
  enter(ep, 'starting')
  play(ep)
}

function play(ep: Episode): void {
  const step = ep.step
  const { element } = ep
  if (element.currentTime !== ep.position) element.currentTime = ep.position
  // Chromium can wait silently on the range request for the position.
  const timer = setTimeout(() => {
    if (current(ep, step)) probe(ep, 'stall')
  }, STEP_TIMEOUT_MS)
  ep.release = () => clearTimeout(timer)
  element.play().then(() => {
    if (current(ep, step)) finish(ep)
  }, (err: unknown) => {
    if (!current(ep, step)) return
    if (err instanceof DOMException && err.name === 'NotAllowedError') {
      enter(ep, 'gesture', { isPlaying: false })
      return
    }
    logger.warn('Resuming the interrupted track failed:', err)
    probe(ep, 'stall')
  })
}

function finish(ep: Episode): void {
  close(ep)
  if (!ep.host.get().isPlaying) ep.host.commit({ isPlaying: true })
  if (ep.recordPlay) {
    // ActivityService handles its own request errors; those are not playback errors.
    activityService.recordPlay({ songId: ep.track.publicId, albumId: ep.track.albumPublicId })
  }
}

/** Whether the recovery owns this element's notifications and the playback clock. */
export function isRecoveringPlayback(element: HTMLAudioElement | null): boolean {
  return element !== null && episode?.element === element
}

/**
 * The active element's stream failed or stalled. Save the position, freeze the clock, and ask the
 * server whether it answers before deciding between retrying and the existing error paths.
 */
export function beginPlaybackRecovery(
  host: PlaybackRecoveryHost,
  element: HTMLAudioElement,
  track: Track,
  cause: PlaybackInterruption,
  onServerUp: () => void,
  recordPlay: boolean,
): void {
  cancelPlaybackRecovery()
  const ep: Episode = {
    host,
    element,
    track,
    src: element.src,
    position: getCurrentTime(),
    deadline: Date.now() + RECOVERY_BUDGET_MS,
    backoff: FIRST_BACKOFF_MS,
    phase: 'probing',
    outage: false,
    reloaded: false,
    recordPlay,
    onServerUp,
    step: 0,
  }
  episode = ep
  // The element's own pause notification arrives while the recovery owns it.
  element.pause()
  probe(ep, cause)
}

/** The source failed or stalled again while the recovery requested it. */
export function continuePlaybackRecovery(element: HTMLAudioElement, cause: PlaybackInterruption): void {
  const ep = live()
  if (!ep || ep.element !== element) return
  if (ep.phase === 'loading' || ep.phase === 'catching-up' || ep.phase === 'starting') probe(ep, cause)
  else if (ep.phase === 'gesture') enter(ep, 'paused')
}

/** The listener paused during the recovery: no automatic resume, and the position is kept. */
export function pausePlaybackRecovery(): boolean {
  const ep = live()
  if (!ep) return false
  enter(ep, 'paused', { isPlaying: false })
  return true
}

/** The listener pressed play: try at once from the saved position with a fresh budget. */
export function resumePlaybackRecovery(): boolean {
  const ep = live()
  if (!ep) return false
  if (ep.phase === 'gesture') {
    // Play inside the listener's gesture, on the source that is already loaded.
    enter(ep, 'starting', { isPlaying: true })
    play(ep)
    return true
  }
  ep.deadline = Date.now() + RECOVERY_BUDGET_MS
  ep.backoff = FIRST_BACKOFF_MS
  reload(ep, { isPlaying: true })
  return true
}

/** A seek during the recovery moves the position it resumes at. */
export function seekPlaybackRecovery(time: number): boolean {
  const ep = live()
  if (!ep) return false
  ep.position = time
  if (ep.phase === 'starting') ep.element.currentTime = time
  updateTime(time)
  return true
}

/** Another selection took the element, or the player released it. */
export function cancelPlaybackRecovery(): void {
  if (episode) close(episode)
}
