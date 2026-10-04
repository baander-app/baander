import { describe, expect, it } from 'vitest'
import { extractCursorMeta, extractPaginatedMeta } from '../api-adapters'

describe('extractPaginatedMeta', () => {
  it('reads backend snake case metadata and preserves the full collection total', () => {
    expect(extractPaginatedMeta({
      data: [{ publicId: 'album-1' }],
      meta: { current_page: 2, last_page: 4, per_page: 24, total: 83 },
    })).toEqual({ currentPage: 2, lastPage: 4, perPage: 24, total: 83 })
  })

  it('does not use obsolete root pagination fields', () => {
    expect(extractPaginatedMeta({
      data: [], currentPage: 2, lastPage: 4, perPage: 24, total: 83,
    })).toEqual({ currentPage: 1, lastPage: 1, perPage: 0, total: 0 })
  })

  it('provides defaults while the response is loading', () => {
    expect(extractPaginatedMeta(undefined)).toEqual({
      currentPage: 1, lastPage: 1, perPage: 0, total: 0,
    })
  })
})


describe('extractCursorMeta', () => {
  it('reads the cursor, next page flag, and collection total from backend metadata', () => {
    expect(extractCursorMeta({ data: [], meta: {
      next_cursor: 'page-2', prev_cursor: null,
      has_next_page: true, has_previous_page: false,
      total: 125, stale_cursor: false, per_page: 100,
    } })).toEqual({ next_cursor: 'page-2', has_next_page: true, total: 125 })
  })

  it('preserves the null cursor and false next page flag on the last page', () => {
    expect(extractCursorMeta({ data: [], meta: {
      next_cursor: null, has_next_page: false, total: 125,
    } })).toEqual({ next_cursor: null, has_next_page: false, total: 125 })
  })

  it('validates unknown metadata without accepting obsolete root fields', () => {
    expect(extractCursorMeta({
      nextCursor: 'page-2', hasNextPage: true, total: 125,
      meta: { next_cursor: 12, has_next_page: 'true', total: '125' },
    })).toEqual({ next_cursor: null, has_next_page: false, total: 0 })
    expect(extractCursorMeta(undefined)).toEqual({ next_cursor: null, has_next_page: false, total: 0 })
  })
})
