import { isAxiosError } from 'axios'

export const LYRICS_PROVIDER_UNAVAILABLE = 'LRCLIB is unavailable. Try again later.'
export const LYRICS_ALREADY_EXIST = 'This song already has lyrics. They were left unchanged.'
export const LYRICS_SONG_HAS_NO_ARTIST = 'This song has no artist, which LRCLIB needs to look up its lyrics.'
export const LYRICS_SONG_HAS_NO_LENGTH = 'This song has no length, which LRCLIB needs to look up its lyrics.'
export const LYRICS_REQUEST_REJECTED = 'LRCLIB rejected the request.'
export const LYRICS_CONFLICT = 'The lyrics request could not be completed.'

/** The message for each cause a 409 names in `error.details.reason`. */
const CONFLICT_MESSAGES: ReadonlyMap<string, string> = new Map([
  ['song_has_lyrics', LYRICS_ALREADY_EXIST],
  ['song_has_no_artist', LYRICS_SONG_HAS_NO_ARTIST],
  ['song_has_no_length', LYRICS_SONG_HAS_NO_LENGTH],
  ['lrclib_rejected', LYRICS_REQUEST_REJECTED],
])

/**
 * The message for a failed lyrics fetch, search or apply. The API answers 503 when LRCLIB is
 * unavailable, and 409 with a reason when a fetch cannot be attempted, LRCLIB rejected the
 * request, or an apply targets a song that has lyrics. A 409 without a reason named here gets
 * a generic message.
 */
export function lyricsErrorMessage(error: unknown, fallback: string): string {
  if (!isAxiosError(error)) {
    return fallback
  }

  const status = error.response?.status

  if (status === 503) {
    return LYRICS_PROVIDER_UNAVAILABLE
  }

  if (status === 409) {
    const reason = conflictReason(error.response?.data)
    const message = reason === undefined ? undefined : CONFLICT_MESSAGES.get(reason)

    return message ?? LYRICS_CONFLICT
  }

  return fallback
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
