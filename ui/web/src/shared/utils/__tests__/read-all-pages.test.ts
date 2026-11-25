import { describe, expect, it, vi } from 'vitest'
import { readAllPages } from '../read-all-pages'

function rows(from: number, count: number): number[] {
  return Array.from({ length: count }, (_, index) => from + index)
}

describe('readAllPages', () => {
  it('reads page after page until the reported total', async () => {
    const readPage = vi.fn(async (offset: number, limit: number) => ({
      data: rows(offset, Math.min(limit, 5 - offset)),
      meta: { total: 5 },
    }))

    await expect(readAllPages(readPage, 2)).resolves.toEqual([0, 1, 2, 3, 4])
    expect(readPage.mock.calls).toEqual([[0, 2], [2, 2], [4, 2]])
  })

  it('stops after a full last page when the total is reached', async () => {
    const readPage = vi.fn(async (offset: number, limit: number) => ({
      data: rows(offset, limit),
      meta: { total: 4 },
    }))

    await expect(readAllPages(readPage, 2)).resolves.toEqual([0, 1, 2, 3])
    expect(readPage).toHaveBeenCalledTimes(2)
  })

  it('stops at a short page when the server reports no total', async () => {
    const readPage = vi.fn(async () => ({ data: [7] }))

    await expect(readAllPages(readPage, 100)).resolves.toEqual([7])
    expect(readPage).toHaveBeenCalledTimes(1)
  })
})
