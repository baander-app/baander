import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface AdminUser {
  id: string
  email: string
  name: string
  roles: string[]
  disabled: boolean
  createdAt: string
  libraryAccess: string[]
}

export interface AdminUserListResponse {
  data: AdminUser[]
  nextCursor: string | null
}

export const userAdminApi = {
  list: async (params?: { role?: string; disabled?: boolean; cursor?: string }): Promise<AdminUserListResponse> => {
    const { data: response } = await AXIOS_INSTANCE.get('/api/admin/users', { params })
    // API returns { data: AdminUser[], meta: { total, limit, offset } }
    return { data: response.data, nextCursor: null }
  },

  create: async (payload: { email: string; password: string; name: string; roles?: string[] }): Promise<AdminUser> => {
    const { data } = await AXIOS_INSTANCE.post('/api/admin/users', payload)
    return data.data
  },

  update: async (id: string, payload: { email?: string; name?: string }): Promise<AdminUser> => {
    const { data } = await AXIOS_INSTANCE.patch(`/api/admin/users/${id}`, payload)
    return data.data
  },

  delete: async (id: string): Promise<void> => {
    await AXIOS_INSTANCE.delete(`/api/admin/users/${id}`)
  },

  assignRoles: async (id: string, roles: string[]): Promise<void> => {
    await AXIOS_INSTANCE.post(`/api/admin/users/${id}/roles`, { roles })
  },

  resetPassword: async (id: string, password: string): Promise<void> => {
    await AXIOS_INSTANCE.post(`/api/admin/users/${id}/reset-password`, { password })
  },

  disable: async (id: string): Promise<void> => {
    await AXIOS_INSTANCE.post(`/api/admin/users/${id}/disable`)
  },

  enable: async (id: string): Promise<void> => {
    await AXIOS_INSTANCE.post(`/api/admin/users/${id}/enable`)
  },
}
