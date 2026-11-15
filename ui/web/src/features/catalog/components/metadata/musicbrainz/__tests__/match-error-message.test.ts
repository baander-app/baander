import { AxiosError, AxiosHeaders } from 'axios'
import { describe, expect, it } from 'vitest'
import {
  COVER_REFUSED,
  MATCH_BUSY,
  MATCH_CONFLICT,
  MATCH_STALE,
  MUSICBRAINZ_REJECTED,
  MUSICBRAINZ_UNAVAILABLE,
  isMatchStale,
  matchErrorMessage,
} from '../match-error-message'

function apiError(status: number, reason?: string): AxiosError {
  const body = { error: { code: 'CONFLICT', message: 'Refused.', details: reason === undefined ? {} : { reason } } }

  return new AxiosError('Request failed', 'ERR_BAD_RESPONSE', undefined, undefined, {
    status,
    statusText: '',
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: body,
  })
}

describe('matchErrorMessage', () => {
  it('says the item changed when an apply finds it stale', () => {
    expect(matchErrorMessage(apiError(409, 'match_stale'), 'Failed')).toBe(MATCH_STALE)
    expect(MATCH_STALE).toBe('This item changed since you opened the comparison.')
  })

  it('says the cover was refused and that unticking it applies the rest', () => {
    expect(matchErrorMessage(apiError(409, 'cover_refused'), 'Failed')).toBe(COVER_REFUSED)
    expect(COVER_REFUSED).toBe('The Cover Art Archive refused the cover. Nothing was written; untick the cover to apply the rest.')
  })

  it('names each other conflict the match routes answer', () => {
    expect(matchErrorMessage(apiError(409, 'match_busy'), 'Failed')).toBe(MATCH_BUSY)
    expect(matchErrorMessage(apiError(409, 'musicbrainz_rejected'), 'Failed')).toBe(MUSICBRAINZ_REJECTED)
    expect(matchErrorMessage(apiError(409), 'Failed')).toBe(MATCH_CONFLICT)
  })

  it('says MusicBrainz is unavailable on a 503', () => {
    expect(matchErrorMessage(apiError(503, 'musicbrainz_unavailable'), 'Failed')).toBe(MUSICBRAINZ_UNAVAILABLE)
  })

  it('falls back for any other failure', () => {
    expect(matchErrorMessage(apiError(500), 'Search failed.')).toBe('Search failed.')
    expect(matchErrorMessage(new Error('offline'), 'Search failed.')).toBe('Search failed.')
  })
})

describe('isMatchStale', () => {
  it('recognises only the stale conflict', () => {
    expect(isMatchStale(apiError(409, 'match_stale'))).toBe(true)
    expect(isMatchStale(apiError(409, 'match_busy'))).toBe(false)
    expect(isMatchStale(apiError(503, 'match_stale'))).toBe(false)
    expect(isMatchStale(new Error('match_stale'))).toBe(false)
  })
})
