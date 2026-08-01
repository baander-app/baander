import { describe, it, expect, beforeEach, vi } from 'vitest'
import MockAdapter from 'axios-mock-adapter'
import { AXIOS_INSTANCE, customInstance } from '@/shared/api-client/axios-instance'

let mock: MockAdapter

beforeEach(() => {
  mock = new MockAdapter(AXIOS_INSTANCE)
})

describe('customInstance', () => {
  it('returns body data with status and headers', async () => {
    mock.onGet('/test').reply(200, { data: { id: 1, name: 'Album' } })

    const result = (await customInstance('/test', {})) as Record<string, unknown>

    expect(result.data).toEqual({ id: 1, name: 'Album' })
    expect(result.status).toBe(200)
    expect(result.headers).toBeDefined()
  })

  it('preserves top-level body properties alongside status/headers', async () => {
    mock.onGet('/paginated').reply(200, {
      data: [{ id: 1 }, { id: 2 }],
      current_page: 1,
      last_page: 3,
    })

    const result = (await customInstance('/paginated', {})) as Record<string, unknown>

    expect(result.data).toEqual([{ id: 1 }, { id: 2 }])
    expect(result.current_page).toBe(1)
    expect(result.status).toBe(200)
  })

  it('handles 204 No Content', async () => {
    mock.onDelete('/test/1').reply(204)

    const result = (await customInstance('/test/1', {
      method: 'DELETE',
    })) as Record<string, unknown>

    expect(result.status).toBe(204)
  })
})

describe('error handling', () => {
  it('rejects with AxiosError on error responses', async () => {
    mock.onGet('/fail').reply(422, {
      error: { message: 'Validation failed', code: 'VALIDATION_ERROR' },
    })

    await expect(customInstance('/fail', {})).rejects.toThrow('Request failed with status code 422')
  })

  it('rejects on server errors', async () => {
    mock.onGet('/server-error').reply(500, 'Internal Server Error')

    await expect(customInstance('/server-error', {})).rejects.toThrow()
  })
})
