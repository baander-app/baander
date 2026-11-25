/** One response of an offset-paged list: its rows and the size of the whole list. */
export interface OffsetPage<T> {
  data?: T[]
  meta?: {
    total?: number
  }
}

/**
 * Reads an offset-paged list to its end, `limit` rows per request. It stops once the
 * rows read reach the reported total, or at the first page shorter than `limit`.
 */
export async function readAllPages<T>(
  readPage: (offset: number, limit: number) => Promise<OffsetPage<T>>,
  limit: number,
): Promise<T[]> {
  const rows: T[] = []

  for (;;) {
    const page = await readPage(rows.length, limit)
    const data = page.data ?? []
    rows.push(...data)

    const total = page.meta?.total
    if (data.length < limit || (total !== undefined && rows.length >= total)) {
      return rows
    }
  }
}
