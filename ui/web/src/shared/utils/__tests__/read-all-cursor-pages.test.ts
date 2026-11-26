import { CanceledError } from 'axios'
import { describe, expect, it, vi } from 'vitest'
import { advanceAuthorization, captureOwnership } from '@/shared/live/ownership'
import { readAllCursorPages, type CursorPage } from '../read-all-cursor-pages'

function rows(from: number, count: number): number[] {
  return Array.from({ length: count }, (_, index) => from + index)
}

/** Serves `total` rows `pageSize` at a time; the cursor is the offset of the next page. */
function cursorList(total: number, pageSize: number) {
  return vi.fn(async (cursor: string | undefined): Promise<CursorPage<number>> => {
    const offset = cursor === undefined ? 0 : Number(cursor)
    const next = offset + pageSize

    return {
      data: rows(offset, Math.min(pageSize, total - offset)),
      meta: {
        has_next_page: next < total,
        next_cursor: next < total ? String(next) : null,
      },
    }
  })
}

describe('readAllCursorPages', () => {
  it('follows next_cursor until has_next_page is false', async () => {
    const readPage = cursorList(5, 2)

    await expect(readAllCursorPages(readPage, {
      maxRows: 100,
      owner: captureOwnership(),
    })).resolves.toEqual({
      rows: [0, 1, 2, 3, 4],
      truncated: false,
    })
    expect(readPage.mock.calls).toEqual([[undefined], ['2'], ['4']])
  })

  it('stops on an empty page even when the server reports a next page', async () => {
    const readPage = vi.fn(async (cursor: string | undefined): Promise<CursorPage<number>> => ({
      data: cursor === undefined ? [1, 2] : [],
      meta: {
        has_next_page: true,
        next_cursor: 'again',
      },
    }))

    await expect(readAllCursorPages(readPage, {
      maxRows: 100,
      owner: captureOwnership(),
    })).resolves.toEqual({
      rows: [1, 2],
      truncated: false,
    })
    expect(readPage).toHaveBeenCalledTimes(2)
  })

  it('stops at maxRows and reports the list was cut when more rows exist', async () => {
    const readPage = cursorList(25, 10)

    await expect(readAllCursorPages(readPage, {
      maxRows: 15,
      owner: captureOwnership(),
    })).resolves.toEqual({
      rows: rows(0, 15),
      truncated: true,
    })
    expect(readPage).toHaveBeenCalledTimes(2)
  })

  it('does not report a cut when the list ends exactly at maxRows', async () => {
    const readPage = cursorList(20, 10)

    await expect(readAllCursorPages(readPage, {
      maxRows: 20,
      owner: captureOwnership(),
    })).resolves.toEqual({
      rows: rows(0, 20),
      truncated: false,
    })
  })

  it('aborts when the ownership changes between pages', async () => {
    const owner = captureOwnership()
    const readPage = vi.fn(async (cursor: string | undefined): Promise<CursorPage<number>> => {
      if (cursor !== undefined) {
        advanceAuthorization()
      }

      return {
        data: [cursor === undefined ? 0 : 1],
        meta: {
          has_next_page: true,
          next_cursor: 'next',
        },
      }
    })

    await expect(readAllCursorPages(readPage, {
      maxRows: 100,
      owner,
    })).rejects.toBeInstanceOf(CanceledError)
    expect(readPage).toHaveBeenCalledTimes(2)
  })
})
