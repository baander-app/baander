import { expect, type Page, type Request } from '@playwright/test'

export interface RenderReceipt {
  count: number
  titles: string[]
  browserEpochMs: number
  hostEpochMs: number
  frameGapMs: number
  visible: boolean
}
export interface HttpReceipt {
  path: string
  startEpochMs: number
  endEpochMs: number
  responseObservedEpochMs: number
  browserTiming: ReturnType<Request['timing']>
  status: number
  failed: boolean
}
export interface VisibilityReceipt { epochMs: number; state: string }

export class CapacityObserver {
  readonly renders: RenderReceipt[] = []
  readonly http: HttpReceipt[] = []
  readonly visibility: VisibilityReceipt[] = []
  readonly socketFrames: { epochMs: number; type: string }[] = []
  readonly resources: unknown[] = []
  ready = false
  maxInFlight = 0
  private readonly active = new Map<Request, number>()
  constructor(readonly page: Page, readonly userId: string) {
    page.on('websocket', (socket) => {
      socket.on('close', () => { this.ready = false })
      socket.on('framereceived', ({ payload }) => {
        try {
          const frame = JSON.parse(String(payload)) as { type?: string }
          if (frame.type?.startsWith('live.')) this.socketFrames.push({ epochMs: Date.now(), type: frame.type })
          if (frame.type === 'live.ready') this.ready = true
        } catch { /* Binary media frames are outside this measurement. */ }
      })
    })
    page.on('request', (request) => {
      if (request.method() !== 'GET' || !new URL(request.url()).pathname.startsWith('/api/')) return
      this.active.set(request, Date.now())
      this.maxInFlight = Math.max(this.maxInFlight, this.active.size)
    })
    const finished = async (request: Request, failed: boolean) => {
      const endEpochMs = Date.now()
      const browserTiming = request.timing()
      const start = this.active.get(request)
      if (start === undefined) return
      this.active.delete(request)
      const response = await request.response()
      this.http.push({ path: new URL(request.url()).pathname, startEpochMs: start,
        endEpochMs, responseObservedEpochMs: Date.now(), browserTiming,
        status: response?.status() ?? 0, failed })
    }
    page.on('requestfinished', (request) => { finished(request, false).catch(() => {}) })
    page.on('requestfailed', (request) => { finished(request, true).catch(() => {}) })
  }
  startMeasurement() {
    this.maxInFlight = this.active.size
  }
  async install() {
    await this.page.exposeBinding('__capacityPaint', (_source, receipt: Omit<RenderReceipt, 'hostEpochMs'>) => {
      this.renders.push({ ...receipt, hostEpochMs: Date.now() })
    })
    await this.page.exposeBinding('__capacityVisibility', (_source, state: string) => {
      this.visibility.push({ epochMs: Date.now(), state })
    })
    await this.page.evaluate(() => {
      const bindings = window as unknown as {
        __capacityPaint: (receipt: Omit<RenderReceipt, 'hostEpochMs'>) => Promise<void>
        __capacityVisibility: (state: string) => Promise<void>
      }
      const reportVisibility = () => { bindings.__capacityVisibility(document.visibilityState).catch(console.error) }
      reportVisibility()
      document.addEventListener('visibilitychange', reportVisibility)
      let previous = ''
      let lastFrame = performance.now()
      const frame = () => {
        const now = performance.now()
        const bell = document.querySelector<HTMLButtonElement>('button[aria-label$=" unread notifications"]')
        const count = Number(bell?.getAttribute('aria-label')?.split(' ')[0] ?? -1)
        const titles = [...document.querySelectorAll('button p:first-child')]
          .filter((node) => node.checkVisibility() && node.getBoundingClientRect().top >= 0
            && node.getBoundingClientRect().bottom <= innerHeight)
          .map((node) => node.textContent?.trim() ?? '')
        const signature = JSON.stringify([count, titles])
        if (signature !== previous && bell?.checkVisibility()) {
          previous = signature
          bindings.__capacityPaint({ count, titles, browserEpochMs: performance.timeOrigin + now,
            frameGapMs: now - lastFrame, visible: document.visibilityState === 'visible' }).catch(console.error)
        }
        lastFrame = now
        requestAnimationFrame(frame)
      }
      requestAnimationFrame(frame)
    })
  }
  async waitForCount(count: number, title?: string, timeout = 15_000) {
    await expect.poll(() => this.renders.findLast((receipt) => receipt.count === count
      && receipt.visible && (!title || receipt.titles.includes(title))), { timeout }).toBeTruthy()
    return this.renders.findLast((receipt) => receipt.count === count && receipt.visible
      && (!title || receipt.titles.includes(title)))!
  }
  async sample() {
    this.resources.push({ hostEpochMs: Date.now(), ...await this.page.evaluate(async () => {
      const frameStart = performance.now()
      const frameDelayMs = await new Promise<number>((resolveFrame) => requestAnimationFrame(() => resolveFrame(performance.now() - frameStart)))
      return { frameDelayMs,
      visibility: document.visibilityState,
      memory: (performance as Performance & { memory?: { usedJSHeapSize: number; totalJSHeapSize: number } }).memory
        ? { used: (performance as Performance & { memory: { usedJSHeapSize: number } }).memory.usedJSHeapSize } : null,
      resources: performance.getEntriesByType('resource').length,
      }
    }) })
  }
  snapshot() {
    return { userId: this.userId, renders: this.renders, http: this.http, visibility: this.visibility,
      socketFrames: this.socketFrames, maxInFlight: this.maxInFlight, resources: this.resources }
  }
}

export const percentile95 = (values: number[]) => {
  if (!values.length) throw new Error('A latency distribution requires samples')
  return [...values].sort((a, b) => a - b)[Math.ceil(values.length * 0.95) - 1]!
}
