import { isAxiosError } from 'axios'

export const MATCH_STALE = 'This item changed since you opened the comparison.'
export const MATCH_BUSY = 'Another change to this item was being saved at the same time. Nothing was written; apply again.'
export const MUSICBRAINZ_UNAVAILABLE = 'MusicBrainz is unavailable. Try again later.'
export const MUSICBRAINZ_REJECTED = 'MusicBrainz rejected the request.'
export const MATCH_CONFLICT = 'The match could not be completed.'

const STALE_REASON = 'match_stale'

/** The message for each cause a 409 names in `error.details.reason`. */
const CONFLICT_MESSAGES: ReadonlyMap<string, string> = new Map([
  [STALE_REASON, MATCH_STALE],
  ['match_busy', MATCH_BUSY],
  ['musicbrainz_rejected', MUSICBRAINZ_REJECTED],
])

/**
 * The message for a failed candidate search, comparison or apply. The API answers 503 when
 * MusicBrainz is unavailable, and 409 with a reason when MusicBrainz rejected the request, the item
 * changed since the comparison, or the apply ran into another writer. A 409 without a reason named
 * here gets a generic message.
 */
export function matchErrorMessage(error: unknown, fallback: string): string {
  if (!isAxiosError(error)) {
    return fallback
  }

  const status = error.response?.status

  if (status === 503) {
    return MUSICBRAINZ_UNAVAILABLE
  }

  if (status === 409) {
    const reason = conflictReason(error.response?.data)
    const message = reason === undefined ? undefined : CONFLICT_MESSAGES.get(reason)

    return message ?? MATCH_CONFLICT
  }

  return fallback
}

/** Whether an apply failed because the item changed since the comparison, which a reload of the comparison resolves. */
export function isMatchStale(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 409 && conflictReason(error.response.data) === STALE_REASON
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** The `error.details.reason` of an API error body, when it has one. */
function conflictReason(body: unknown): string | undefined {
  if (!isRecord(body) || !isRecord(body.error) || !isRecord(body.error.details)) {
    return undefined
  }

  const { reason } = body.error.details

  return typeof reason === 'string' ? reason : undefined
}
