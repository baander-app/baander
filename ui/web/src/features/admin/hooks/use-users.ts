import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  userAdminApi,
  type AdminUserListParams,
  type AdminUserSetting,
  type AdminUserSettingValue,
} from '../api/user-admin-api'

const USERS_KEY = ['admin-users']
const USER_SETTINGS_KEY = ['admin-user-settings']

export function useUsers(params?: AdminUserListParams) {
  return useQuery({
    queryKey: [...USERS_KEY, params],
    queryFn: ({ signal }) => userAdminApi.list(params, signal),
  })
}

export function useCreateUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: userAdminApi.create,
    onSuccess: () => qc.invalidateQueries({ queryKey: USERS_KEY }),
  })
}

export function useUpdateUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...payload }: { id: string; email?: string; name?: string }) =>
      userAdminApi.update(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: USERS_KEY }),
  })
}

export function useDeleteUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: userAdminApi.delete,
    onSuccess: () => qc.invalidateQueries({ queryKey: USERS_KEY }),
  })
}

export function useAssignRoles() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, roles }: { id: string; roles: string[] }) =>
      userAdminApi.assignRoles(id, roles),
    onSuccess: () => qc.invalidateQueries({ queryKey: USERS_KEY }),
  })
}

export function useResetPassword() {
  return useMutation({
    mutationFn: ({ id, password }: { id: string; password: string }) =>
      userAdminApi.resetPassword(id, password),
  })
}

export function useToggleUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, disabled }: { id: string; disabled: boolean }) => {
      if (disabled) {
        await userAdminApi.enable(id)
      } else {
        await userAdminApi.disable(id)
      }
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: USERS_KEY }),
  })
}

/** A user's settings as administrators see them, including stored values that are no longer allowed. */
export function useUserSettings(id: string) {
  return useQuery({
    queryKey: [...USER_SETTINGS_KEY, id],
    queryFn: ({ signal }) => userAdminApi.settings(id, signal),
    retry: false,
  })
}

export type UserSettingChange =
  | { id: string; key: string; action: 'set'; value: AdminUserSettingValue }
  | { id: string; key: string; action: 'reset' }

/** Sets or resets one of a user's settings and stores the setting the server answers with. */
export function useChangeUserSetting() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (change: UserSettingChange) =>
      change.action === 'set'
        ? userAdminApi.setSetting(change.id, change.key, change.value)
        : userAdminApi.resetSetting(change.id, change.key),
    onSuccess: (setting, change) => {
      qc.setQueryData<AdminUserSetting[]>([...USER_SETTINGS_KEY, change.id], (current) =>
        current?.map((candidate) => (candidate.key === setting.key ? setting : candidate)),
      )

      return qc.invalidateQueries({ queryKey: [...USER_SETTINGS_KEY, change.id] })
    },
  })
}
