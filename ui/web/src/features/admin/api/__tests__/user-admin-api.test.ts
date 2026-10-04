import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { userAdminApi } from '../user-admin-api'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: vi.fn() } }))

describe('admin user list API', () => {
  beforeEach(() => vi.clearAllMocks())

  it('preserves offset metadata and forwards filters, page bounds and cancellation', async () => {
    const response = { data: [], meta: { total: 101, limit: 50, offset: 50 } }
    vi.mocked(AXIOS_INSTANCE.get).mockResolvedValue({ data: response })
    const params = { role: 'ROLE_ADMIN', disabled: false, limit: 50, offset: 50 }
    const signal = new AbortController().signal

    expect(await userAdminApi.list(params, signal)).toEqual(response)
    expect(AXIOS_INSTANCE.get).toHaveBeenCalledWith('/api/admin/users', { params, signal })
  })

  it('propagates request failures instead of returning an empty list', async () => {
    vi.mocked(AXIOS_INSTANCE.get).mockRejectedValue(new Error('Unavailable'))
    await expect(userAdminApi.list()).rejects.toThrow('Unavailable')
  })
})
