import { assertCurrentOwnership, type OwnershipStamp } from '@/shared/live/ownership'

/** One response of a cursor-paged list: its rows and where the next page starts. */
export interface CursorPage<T> {
  data?: T[]
  meta?: {
    has_next_page?: boolean
    next_cursor?: string | null
  }
}

export interface CursorReadOptions {
  /** The most rows to return; the read stops once it holds this many. */
  maxRows: number
  /** The ownership the read belongs to; a change between pages aborts it. */
  owner: OwnershipStamp
}

export interface CursorRead<T> {
  rows: T[]
  /** The read stopped at `maxRows` while the list held more rows. */
  truncated: boolean
}

/**
 * Reads a cursor-paged list to its end. It follows `meta.next_cursor` while
 * `meta.has_next_page` holds, and stops on an empty page or at `maxRows`. A changed
 * ownership rejects with a `CanceledError`; a failed page rejects with its error.
 */
export async function readAllCursorPages<T>(
  readPage: (cursor: string | undefined) => Promise<CursorPage<T>>,
  { maxRows, owner }: CursorReadOptions,
): Promise<CursorRead<T>> {
  const rows: T[] = []
  let cursor: string | undefined

  for (;;) {
    const page = await readPage(cursor)
    assertCurrentOwnership(owner)

    const data = page.data ?? []
    rows.push(...data)

    const next = page.meta?.has_next_page === true
      ? page.meta.next_cursor ?? undefined
      : undefined
    if (rows.length >= maxRows) {
      return {
        rows: rows.slice(0, maxRows),
        truncated: rows.length > maxRows || next !== undefined,
      }
    }
    if (data.length === 0 || next === undefined) {
      return {
        rows,
        truncated: false,
      }
    }

    cursor = next
  }
}
