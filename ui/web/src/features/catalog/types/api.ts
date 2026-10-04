import type {
  PaginatedResponse as ApiPaginatedResponse,
  CursorPaginatedResponse as ApiCursorPaginatedResponse,
} from '@/shared/api-client/gen/endpoints'

export type PaginatedResponse<T> = Omit<ApiPaginatedResponse, 'data'> & { data: T[] }

export type CursorPaginatedResponse<T> = Omit<ApiCursorPaginatedResponse, 'data'> & { data: T[] }
