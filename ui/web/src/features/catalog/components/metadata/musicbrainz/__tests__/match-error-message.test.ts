import { AxiosError, AxiosHeaders } from 'axios'
import { describe, expect, it } from 'vitest'
import {
  CANDIDATE_NOT_FOUND,
  COVER_REFUSED,
  ITEM_NOT_FOUND,
  MATCH_BUSY,
  MATCH_CONFLICT,
  MATCH_STALE,
  MUSICBRAINZ_REJECTED,
  MUSICBRAINZ_UNAVAILABLE,
  SELECTION_NOT_ACCEPTED,
  isMatchStale,
  matchErrorMessage,
} from '../match-error-message'

function apiError(status: number, reason?: string): AxiosError {
  const body = { error: { code: status, message: 'Refused.', details: reason === undefined ? {} : { reason } } }

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

  it('says the item no longer exists when a 404 names the item', () => {
    expect(matchErrorMessage(apiError(404, 'item_not_found'), 'Failed')).toBe(ITEM_NOT_FOUND)
    expect(ITEM_NOT_FOUND).toBe('This item no longer exists. Close this dialog and reload the page.')
  })

  it('says to go back and search again when a 404 names the candidate', () => {
    expect(matchErrorMessage(apiError(404, 'candidate_not_found'), 'Failed')).toBe(CANDIDATE_NOT_FOUND)
    expect(CANDIDATE_NOT_FOUND).toBe('MusicBrainz no longer has this candidate. Go back and search again.')
  })

  it('says the server did not accept the selection and to reload the page on a 422', () => {
    expect(matchErrorMessage(apiError(422), 'Failed')).toBe(SELECTION_NOT_ACCEPTED)
    expect(SELECTION_NOT_ACCEPTED).toBe('The server did not accept the selection. Reload the page and match again.')
  })

  it('falls back for any other failure', () => {
    expect(matchErrorMessage(apiError(404), 'Search failed.')).toBe('Search failed.')
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
