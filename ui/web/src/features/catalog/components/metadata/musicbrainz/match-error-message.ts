import { isAxiosError } from 'axios'

export const MATCH_STALE = 'This item changed since you opened the comparison.'
export const MATCH_BUSY = 'Another change to this item was being saved at the same time. Nothing was written; apply again.'
export const COVER_REFUSED = 'The Cover Art Archive refused the cover. Nothing was written; untick the cover to apply the rest.'
export const MUSICBRAINZ_UNAVAILABLE = 'MusicBrainz is unavailable. Try again later.'
export const MUSICBRAINZ_REJECTED = 'MusicBrainz rejected the request.'
export const MATCH_CONFLICT = 'The match could not be completed.'
export const ITEM_NOT_FOUND = 'This item no longer exists. Close this dialog and reload the page.'
export const CANDIDATE_NOT_FOUND = 'MusicBrainz no longer has this candidate. Go back and search again.'
export const SELECTION_NOT_ACCEPTED = 'The server did not accept the selection. Reload the page and match again.'

const STALE_REASON = 'match_stale'

/** The message for each cause a 409 names in `error.details.reason`. */
const CONFLICT_MESSAGES: ReadonlyMap<string, string> = new Map([
  [STALE_REASON, MATCH_STALE],
  ['match_busy', MATCH_BUSY],
  ['cover_refused', COVER_REFUSED],
  ['musicbrainz_rejected', MUSICBRAINZ_REJECTED],
])

/** The message for each cause a 404 names in `error.details.reason`. */
const NOT_FOUND_MESSAGES: ReadonlyMap<string, string> = new Map([
  ['item_not_found', ITEM_NOT_FOUND],
  ['candidate_not_found', CANDIDATE_NOT_FOUND],
])

/**
 * The message for a failed candidate search, comparison or apply. The API answers 503 when
 * MusicBrainz is unavailable, and 409 with a reason when MusicBrainz rejected the request, the item
 * changed since the comparison, the apply ran into another writer, or the Cover Art Archive refused
 * the ticked cover. A 409 without a reason named here gets a generic message. A 404 names whether
 * the item or the MusicBrainz candidate is gone, and a 422 means the server did not accept what
 * the dialog sent. Anything else, a 404 without a reason named here included, gets the fallback.
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
    return reasonMessage(CONFLICT_MESSAGES, error.response?.data) ?? MATCH_CONFLICT
  }

  if (status === 404) {
    return reasonMessage(NOT_FOUND_MESSAGES, error.response?.data) ?? fallback
  }

  if (status === 422) {
    return SELECTION_NOT_ACCEPTED
  }

  return fallback
}

/** Whether an apply failed because the item changed since the comparison, which a reload of the comparison resolves. */
export function isMatchStale(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 409 && errorReason(error.response.data) === STALE_REASON
}

/** The message the map holds for the reason an API error body names, when it names one the map knows. */
function reasonMessage(messages: ReadonlyMap<string, string>, body: unknown): string | undefined {
  const reason = errorReason(body)

  return reason === undefined ? undefined : messages.get(reason)
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** The `error.details.reason` of an API error body, when it has one. */
function errorReason(body: unknown): string | undefined {
  if (!isRecord(body) || !isRecord(body.error) || !isRecord(body.error.details)) {
    return undefined
  }

  const { reason } = body.error.details

  return typeof reason === 'string' ? reason : undefined
}
