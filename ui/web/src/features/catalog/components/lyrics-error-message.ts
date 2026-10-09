import { isAxiosError } from 'axios'

export const LYRICS_PROVIDER_UNAVAILABLE = 'LRCLIB is unavailable. Try again later.'
export const LYRICS_ALREADY_EXIST = 'This song already has lyrics. They were left unchanged.'

/**
 * The message for a failed lyrics fetch, search or apply. The API answers 503 when LRCLIB is
 * unavailable, and 409 when an apply targets a song that has lyrics or a result another song
 * has; the 409 message says which.
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
    return conflictMessage(error.response?.data) ?? LYRICS_ALREADY_EXIST
  }

  return fallback
}

function conflictMessage(body: unknown): string | undefined {
  if (typeof body !== 'object' || body === null || !('error' in body)) {
    return undefined
  }

  const { error } = body
  if (typeof error !== 'object' || error === null || !('message' in error)) {
    return undefined
  }

  return typeof error.message === 'string' && error.message !== '' ? error.message : undefined
}
