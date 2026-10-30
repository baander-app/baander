import { CanceledError } from 'axios'
import type { LiveFamily } from './protocol'

export type LiveReadPriority = 'background' | 'foreground'
export interface LiveReadContext {
  family: LiveFamily
  priority: LiveReadPriority
}

const contexts = new WeakMap<object, LiveReadContext>()

/** A shared query signal carries this context across all infinite-query pages and retries. */
export function bindLiveReadContext(signal: AbortSignal, context: LiveReadContext): void {
  contexts.set(signal, context)
}

export function getLiveReadContext(signal: object | undefined): LiveReadContext | undefined {
  return signal ? contexts.get(signal) : undefined
}

const liveReadSignals: AbortSignal[] = []

/** Generated request options cannot replace the owning query's cancellation signal. */
export function withLiveReadSignal<T>(signal: AbortSignal, callback: () => T): T {
  liveReadSignals.push(signal)
  try {
    return callback()
  } finally {
    liveReadSignals.pop()
  }
}

export function currentLiveReadSignal(): AbortSignal | undefined {
  return liveReadSignals.at(-1)
}

type RequestSignal = {
  aborted: boolean
  addEventListener?: (type: 'abort', listener: () => void) => void
  removeEventListener?: (type: 'abort', listener: () => void) => void
}
type Release = () => void
interface WaitingAttempt {
  priority: LiveReadPriority
  signal?: RequestSignal
  resolve: (release: Release) => void
  reject: (error: CanceledError<unknown>) => void
  abort: () => void
}

/** Admission applies to actual HTTP attempts, including retries, before expensive proof signing. */
export class LiveHttpAdmission {
  private readonly waiting: WaitingAttempt[] = []
  private starts: number[] = []
  private activeBackground = 0
  private active = 0
  private timer: ReturnType<typeof setTimeout> | undefined

  acquire(signal: RequestSignal | undefined, priority: LiveReadPriority): Promise<Release> {
    if (signal?.aborted) return Promise.reject(new CanceledError('Resource request aborted'))
    if (this.waiting.length + this.active >= 256) {
      return Promise.reject(new CanceledError('Resource refresh deferred at admission capacity'))
    }
    return new Promise((resolve, reject) => {
      const attempt: WaitingAttempt = {
        signal, priority, resolve, reject,
        abort: () => {
          const index = this.waiting.indexOf(attempt)
          if (index < 0) return
          this.waiting.splice(index, 1)
          signal?.removeEventListener?.('abort', attempt.abort)
          reject(new CanceledError('Resource request aborted'))
          this.pump()
        },
      }
      this.waiting.push(attempt)
      signal?.addEventListener?.('abort', attempt.abort)
      this.pump()
    })
  }

  getSnapshot(): { active: number; queued: number; background: number } {
    return { active: this.active, queued: this.waiting.length, background: this.activeBackground }
  }

  private pump(): void {
    if (this.timer !== undefined) clearTimeout(this.timer)
    this.timer = undefined
    const now = Date.now()
    this.starts = this.starts.filter(start => now - start < 1000)
    while (this.waiting.length > 0) {
      const foreground = this.waiting.findIndex(attempt => attempt.priority === 'foreground')
      const index = foreground >= 0 ? foreground : this.activeBackground < 4 ? 0 : -1
      if (index < 0) return
      if (this.starts.length >= 20) {
        this.timer = setTimeout(() => this.pump(), Math.max(1, this.starts[0] + 1000 - now))
        return
      }
      const [attempt] = this.waiting.splice(index, 1)
      attempt.signal?.removeEventListener?.('abort', attempt.abort)
      if (attempt.signal?.aborted) {
        attempt.reject(new CanceledError('Resource request aborted'))
        continue
      }
      this.starts.push(now)
      this.active++
      if (attempt.priority === 'background') this.activeBackground++
      let released = false
      const release = () => {
        if (released) return
        released = true
        attempt.signal?.removeEventListener?.('abort', release)
        this.active--
        if (attempt.priority === 'background') this.activeBackground--
        this.pump()
      }
      attempt.signal?.addEventListener?.('abort', release)
      attempt.resolve(release)
    }
  }
}

export const liveHttpAdmission = new LiveHttpAdmission()
