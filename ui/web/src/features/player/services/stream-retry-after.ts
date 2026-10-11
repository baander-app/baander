/**
 * Retry-After delays from 503 stream answers, keyed by stream URL.
 *
 * A media element never exposes the response that failed it. The stream service worker
 * sees the response, so it reports a busy encoder's Retry-After here, and playback
 * recovery reads it when the same source fails.
 */

/** A note older than this no longer describes the source's latest failure. */
const NOTE_LIFETIME_MS = 60_000

/** Each URL maps to the time it was noted and the earliest time to request it again. */
const notes = new Map<string, { notedAt: number; retryAt: number }>()

function key(url: string): string | null {
  try {
    return new URL(url, document.baseURI).href
  } catch {
    return null
  }
}

export function noteStreamRetryAfter(url: string, seconds: number): void {
  const noted = key(url)
  if (noted === null || !Number.isFinite(seconds) || seconds < 0) return
  const now = Date.now()
  for (const [href, note] of notes) {
    if (now - note.notedAt > NOTE_LIFETIME_MS) notes.delete(href)
  }
  notes.set(noted, { notedAt: now, retryAt: now + seconds * 1000 })
}

/** Milliseconds still to wait before requesting `url` again, or null when no recent 503 named a delay. */
export function takeStreamRetryAfter(url: string): number | null {
  const noted = key(url)
  if (noted === null) return null
  const note = notes.get(noted)
  if (!note) return null
  notes.delete(noted)
  const now = Date.now()
  if (now - note.notedAt > NOTE_LIFETIME_MS) return null
  return Math.max(0, note.retryAt - now)
}
