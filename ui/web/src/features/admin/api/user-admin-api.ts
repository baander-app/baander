import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { AdminUserSettingResource } from '@/shared/api-client/gen/endpoints'

export type AdminUserSetting = AdminUserSettingResource

export interface AdminUser {
  id: string
  email: string
  name: string
  roles: string[]
  disabled: boolean
  createdAt: string
}

/** One library and whether the user may see it. */
export interface AdminUserLibraryAccess {
  libraryId: string
  name: string
  slug: string
  type: string
  granted: boolean
}

interface DataEnvelope<T> {
  data: T
}

export interface AdminUserListResponse {
  data: AdminUser[]
  meta: { total: number; limit: number; offset: number }
}

export interface AdminUserListParams {
  role?: string
  disabled?: boolean
  limit?: number
  offset?: number
}

export const userAdminApi = {
  list: async (params?: AdminUserListParams, signal?: AbortSignal): Promise<AdminUserListResponse> => {
    const { data } = await AXIOS_INSTANCE.get<AdminUserListResponse>('/api/admin/users', { params, signal })
    return data
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

  /** Every library in display order, each with whether the user has access. */
  libraryAccess: async (id: string, signal?: AbortSignal): Promise<AdminUserLibraryAccess[]> => {
    const { data } = await AXIOS_INSTANCE.get<DataEnvelope<AdminUserLibraryAccess[]>>(
      `/api/admin/users/${id}/libraries`,
      { signal },
    )
    return data.data
  },

  grantLibraryAccess: async (id: string, libraryId: string): Promise<AdminUserLibraryAccess> => {
    const { data } = await AXIOS_INSTANCE.put<DataEnvelope<AdminUserLibraryAccess>>(
      `/api/admin/users/${id}/libraries/${libraryId}`,
    )
    return data.data
  },

  revokeLibraryAccess: async (id: string, libraryId: string): Promise<AdminUserLibraryAccess> => {
    const { data } = await AXIOS_INSTANCE.delete<DataEnvelope<AdminUserLibraryAccess>>(
      `/api/admin/users/${id}/libraries/${libraryId}`,
    )
    return data.data
  },
}
