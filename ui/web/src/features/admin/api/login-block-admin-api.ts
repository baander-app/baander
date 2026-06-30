import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface LoginBlock {
  id: string
  ipAddress: string
  email: string
  fieldValue: string
  userAgent: string
  createdAt: string
}

export interface LoginBlockListResponse {
  data: LoginBlock[]
  meta: { total: number; limit: number; offset: number }
}

export const loginBlockAdminApi = {
  list: async (params?: { limit?: number; offset?: number }): Promise<LoginBlockListResponse> => {
    const { data: response } = await AXIOS_INSTANCE.get('/api/admin/login-blocks', { params })
    return response
  },

  delete: async (id: string): Promise<void> => {
    await AXIOS_INSTANCE.delete(`/api/admin/login-blocks/${id}`)
  },

  deleteAll: async (): Promise<void> => {
    await AXIOS_INSTANCE.delete('/api/admin/login-blocks')
  },
}
