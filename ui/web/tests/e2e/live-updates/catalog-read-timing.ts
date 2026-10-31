export interface NativeReadTiming {
  startSeconds: number
  endSeconds: number
}

interface ReadReceipt {
  requestId: number
  path: string
  timing: NativeReadTiming | null
  failed: boolean | null
}

export class CatalogReadTiming {
  readonly requests: ReadReceipt[] = []
  readonly hostEvents: { requestId: number; phase: 'request' | 'requestfinished' | 'requestfailed'; epochMs: number }[] = []
  hostMaxInFlight = 0
  private readonly active = new Set<number>()

  start(requestId: number, path: string, epochMs: number): void {
    this.requests.push({ requestId, path, timing: null, failed: null })
    this.active.add(requestId)
    this.hostMaxInFlight = Math.max(this.hostMaxInFlight, this.active.size)
    this.hostEvents.push({ requestId, phase: 'request', epochMs })
  }

  finish(requestId: number, timing: NativeReadTiming, failed: boolean, epochMs: number): void {
    const receipt = this.requests.find(request => request.requestId === requestId)
    if (!receipt) throw new Error(`Unknown catalog request ${requestId}`)
    receipt.timing = timing
    receipt.failed = failed
    this.active.delete(requestId)
    this.hostEvents.push({ requestId, phase: failed ? 'requestfailed' : 'requestfinished', epochMs })
  }

  get pendingRequests(): number { return this.active.size }

  get concurrency(): { maxInFlight: number | null; errors: string[] } {
    const errors: string[] = []
    const boundaries: { timestamp: number; change: number }[] = []
    for (const request of this.requests) {
      const timing = request.timing
      if (!timing || !Number.isFinite(timing.startSeconds) || timing.startSeconds <= 0
        || !Number.isFinite(timing.endSeconds) || timing.endSeconds <= timing.startSeconds) {
        errors.push(`Missing or invalid native timing for catalog request ${request.requestId}: ${request.path}`)
        continue
      }
      boundaries.push({ timestamp: timing.startSeconds, change: 1 },
        { timestamp: timing.endSeconds, change: -1 })
    }
    if (errors.length) return { maxInFlight: null, errors }
    // A completed transfer and a new start at the same instant do not overlap.
    boundaries.sort((a, b) => a.timestamp - b.timestamp || a.change - b.change)
    let active = 0
    let maxInFlight = 0
    for (const boundary of boundaries) {
      active += boundary.change
      maxInFlight = Math.max(maxInFlight, active)
    }
    return { maxInFlight, errors }
  }
}
