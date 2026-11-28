export class PlaybackClock {
  private readonly wallNow: () => number
  private readonly elapsedNow: () => number
  private anchor: { milliseconds: number; receivedAt: number; startedAt: number } | null = null
  private lastRead = Number.NEGATIVE_INFINITY
  private generation = 0

  constructor(wallNow = () => Date.now(), elapsedNow = () => performance.now()) {
    this.wallNow = wallNow
    this.elapsedNow = elapsedNow
  }

  elapsed(): number {
    return this.elapsedNow()
  }

  read(): { milliseconds: number; generation: number } {
    const milliseconds = Math.floor(this.project(this.elapsed()) ?? this.wallNow())
    if (milliseconds < this.lastRead) this.generation++
    this.lastRead = milliseconds
    return { milliseconds, generation: this.generation }
  }

  observe(date: unknown, startedAt: number): void {
    if (typeof date !== 'string' || startedAt < (this.anchor?.startedAt ?? 0)) return
    const serverTime = Date.parse(date)
    const receivedAt = this.elapsed()
    const roundTrip = receivedAt - startedAt
    if (!Number.isFinite(serverTime) || !Number.isFinite(roundTrip) || roundTrip < 0) return

    // HTTP Date truncates to seconds. The time it takes to reach us is bounded by the round trip.
    const upper = serverTime + 1_000 + roundTrip
    const fits = (time: number) => time >= serverTime && time <= upper
    const projected = this.project(receivedAt)
    const wall = this.wallNow()
    // A skewed client must not date an event past a manual mark observed later in the same second.
    let milliseconds = serverTime
    if (projected !== null && fits(projected)) milliseconds = projected
    else if (fits(wall)) milliseconds = wall
    this.anchor = { milliseconds, receivedAt, startedAt }
  }

  private project(elapsed: number): number | null {
    return this.anchor && this.anchor.milliseconds + elapsed - this.anchor.receivedAt
  }
}

export const playbackClock = new PlaybackClock()

export function observePlaybackDate(date: unknown, startedAt: number, baseUrl?: string): void {
  if (typeof date !== 'string') return
  if (new URL(baseUrl || window.location.href, window.location.href).origin === window.location.origin) {
    playbackClock.observe(date, startedAt)
  }
}
