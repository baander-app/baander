export interface PaginatedResponse<T> {
  data: T[]
  currentPage: number
  lastPage: number
  perPage: number
  total: number
}

export interface CursorPaginatedResponse<T> {
  data: T[]
  nextCursor: string | null
  prevCursor: string | null
  hasNextPage: boolean
  hasPreviousPage: boolean
  total: number
  staleCursor: boolean
  perPage: number
}
