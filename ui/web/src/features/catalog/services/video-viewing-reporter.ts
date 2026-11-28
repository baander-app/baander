import type {
  PlaybackViewingContextResource,
  RecordVideoProgressRequest
} from '@/shared/api-client/gen/endpoints'
import { playbackClock, type PlaybackClock } from '@/features/session/services/playback-clock'

export type ViewingAction = RecordVideoProgressRequest['action']
export const VIEWING_FLUSH_TIMEOUT_MS = 500

interface ViewingReporterPorts {
  current(): boolean
  isWatching(videoId: string, signal: AbortSignal): Promise<boolean>
  report(videoId: string, report: RecordVideoProgressRequest, signal: AbortSignal): Promise<unknown>
  clock?: Pick<PlaybackClock, 'read' | 'elapsed'>
}

export class VideoViewingReporter {
  private readonly videoId: string
  private readonly viewing: PlaybackViewingContextResource
  private readonly ports: ViewingReporterPorts
  private pending = new AbortController()
  private disposed = false
  private readonly flushes = new Set<() => void>()
  private readonly clock: Pick<PlaybackClock, 'read' | 'elapsed'>
  private tail: Promise<void> = Promise.resolve()
  private lastSample: number | null = null
  private wasPlaying = false
  private playedMilliseconds = 0
  private reportedAt: ReturnType<PlaybackClock['read']> | null = null

  constructor(
    videoId: string,
    viewing: PlaybackViewingContextResource,
    ports: ViewingReporterPorts
  ) {
    this.videoId = videoId
    this.viewing = viewing
    this.ports = ports
    this.clock = ports.clock ?? playbackClock
  }

  tick(positionSeconds: number, playing: boolean): void {
    if (!this.current()) {
      this.lastSample = null
      this.wasPlaying = false
      this.playedMilliseconds = 0
      return
    }
    const now = this.clock.elapsed()
    if (this.lastSample !== null && this.wasPlaying && playing) {
      this.playedMilliseconds += Math.max(0, now - this.lastSample)
    }
    this.lastSample = now
    this.wasPlaying = playing
    if (playing && this.playedMilliseconds >= 30_000) {
      this.playedMilliseconds = 0
      this.action('progress', positionSeconds)
    }
  }

  action(action: ViewingAction, positionSeconds: number): void {
    if (!this.current() || !Number.isFinite(positionSeconds) || positionSeconds < 0) return
    if (action !== 'progress') this.playedMilliseconds = 0
    const { milliseconds, generation } = this.clock.read()
    const previous = this.reportedAt
    const reportedAt = previous?.generation === generation
      ? Math.max(milliseconds, previous.milliseconds + 1)
      : milliseconds
    this.reportedAt = { milliseconds: reportedAt, generation }
    const report: RecordVideoProgressRequest = {
      ...this.viewing,
      positionSeconds,
      action,
      reportedAt: new Date(reportedAt).toISOString()
    }
    const signal = this.pending.signal
    this.tail = this.tail.then(async () => {
      if (!this.current() || signal.aborted) return
      const watching = await this.cancellable(this.ports.isWatching(this.videoId, signal), signal)
      if (!watching && this.current() && !signal.aborted) {
        await this.cancellable(this.ports.report(this.videoId, report, signal), signal)
      }
    }).catch(() => {})
  }

  dispose(): void {
    this.disposed = true
    this.pending.abort()
    for (const finish of this.flushes) finish()
    this.wasPlaying = false
    this.lastSample = null
  }

  settled(): Promise<void> {
    return this.tail
  }

  flush(signal?: AbortSignal): Promise<void> {
    if (this.disposed) return Promise.resolve()
    const pending = this.pending
    const work = this.tail
    return new Promise(resolve => {
      const finish = () => {
        clearTimeout(timer)
        signal?.removeEventListener('abort', cancel)
        this.flushes.delete(finish)
        resolve()
      }
      const cancel = () => {
        pending.abort()
        if (this.pending === pending) this.pending = new AbortController()
        finish()
      }
      const timer = setTimeout(cancel, VIEWING_FLUSH_TIMEOUT_MS)
      this.flushes.add(finish)
      signal?.addEventListener('abort', cancel, { once: true })
      if (signal?.aborted) cancel()
      else work.then(finish, finish)
    })
  }

  private cancellable<T>(work: Promise<T>, signal: AbortSignal): Promise<T> {
    return new Promise((resolve, reject) => {
      const cancel = () => reject(new DOMException('Viewing report was cancelled.', 'AbortError'))
      signal.addEventListener('abort', cancel, { once: true })
      work.then(value => {
        signal.removeEventListener('abort', cancel)
        resolve(value)
      }, error => {
        signal.removeEventListener('abort', cancel)
        reject(error)
      })
      if (signal.aborted) cancel()
    })
  }

  private current(): boolean {
    return !this.disposed && this.ports.current()
  }
}
