import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import MockAdapter from 'axios-mock-adapter'
import { AXIOS_INSTANCE, customInstance } from '@/shared/api-client/axios-instance'
import { getAlbumIndex } from '@/shared/api-client/gen/endpoints'

let mock: MockAdapter
beforeEach(() => { mock = new MockAdapter(AXIOS_INSTANCE) })
afterEach(() => { mock.restore() })

describe('customInstance', () => {
  it.each([
    { data: { id: 1, name: 'Album' } },
    { status: 'healthy', headers: { label: 'Body metadata' } },
    [{ id: 1 }, { id: 2 }],
    'plain text', 42, false, null,
  ])('returns the response body unchanged: %j', async (body) => {
    mock.onGet('/test').reply(200, body)
    expect(await customInstance('/test', {})).toEqual(body)
  })

  it('keeps pagination metadata at the documented level through generated functions', async () => {
    const body = { data: [{ publicId: 'album-1' }], meta: { current_page: 1, last_page: 3, per_page: 1, total: 3 } }
    mock.onGet('/api/albums/?page=1&limit=1').reply(200, body)
    const result = await getAlbumIndex({ page: 1, limit: 1 })
    expect(result).toEqual(body)
    expect(result.meta.last_page).toBe(3)
  })

  it('returns undefined for a 204 response', async () => {
    mock.onDelete('/test/1').reply(204)
    expect(await customInstance<void>('/test/1', { method: 'DELETE' })).toBeUndefined()
  })

  it('forwards the method, body, headers, and cancellation signal', async () => {
    const controller = new AbortController()
    mock.onPost('/test').reply((config) => {
      expect(config.data).toBe('{"title":"Updated"}')
      expect(config.headers?.['X-Test']).toBe('baander')
      expect(config.signal).toBe(controller.signal)
      return [201, { saved: true }]
    })
    expect(await customInstance('/test', {
      method: 'POST', body: '{"title":"Updated"}', headers: { 'X-Test': 'baander' }, signal: controller.signal,
    })).toEqual({ saved: true })
  })
})

describe('error handling', () => {
  it('rejects with the numeric validation error envelope', async () => {
    const data = { error: { message: 'Validation failed', code: 422 } }
    mock.onGet('/fail').reply(422, data)
    await expect(customInstance('/fail', {})).rejects.toMatchObject({ response: { status: 422, data } })
  })

  it('rejects on server errors', async () => {
    mock.onGet('/server-error').reply(500, 'Internal Server Error')
    await expect(customInstance('/server-error', {})).rejects.toThrow()
  })
})
