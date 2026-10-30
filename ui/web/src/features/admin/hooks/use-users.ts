import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  deleteAdminUserSettingsReset,
  getGetAdminUserSettingsIndexQueryKey,
  putAdminUserSettingsSet,
  useGetAdminUserSettingsIndex,
  type GetAdminUserSettingsIndex200,
  type SetAdminUserSettingRequest,
} from '@/shared/api-client/gen/endpoints'
import { useAdminMutationTools } from './use-admin-mutation'
import { userAdminApi, type AdminUserListParams } from '../api/user-admin-api'

const USERS_KEY = ['admin-users']

export function useUsers(params?: AdminUserListParams) {
  return useQuery({
    queryKey: [...USERS_KEY, params],
    queryFn: ({ signal }) => userAdminApi.list(params, signal),
  })
}

export function useCreateUser() {
  const live = useAdminMutationTools(['admin-users'])
  return useMutation({
    mutationFn: (payload: Parameters<typeof userAdminApi.create>[0]) => live.run(() => userAdminApi.create(payload)),
    onSuccess: () => live.invalidate(USERS_KEY),
  })
}

export function useUpdateUser() {
  const live = useAdminMutationTools(['admin-users'])
  return useMutation({
    mutationFn: ({ id, ...payload }: { id: string; email?: string; name?: string }) =>
      live.run(() => userAdminApi.update(id, payload)),
    onSuccess: () => live.invalidate(USERS_KEY),
  })
}

export function useDeleteUser() {
  const live = useAdminMutationTools(['admin-users'])
  return useMutation({
    mutationFn: (id: string) => live.run(() => userAdminApi.delete(id)),
    onSuccess: () => live.invalidate(USERS_KEY),
  })
}

export function useAssignRoles() {
  const live = useAdminMutationTools(['admin-users'])
  return useMutation({
    mutationFn: ({ id, roles }: { id: string; roles: string[] }) =>
      live.run(() => userAdminApi.assignRoles(id, roles)),
    onSuccess: () => live.invalidate(USERS_KEY),
  })
}

export function useResetPassword() {
  const live = useAdminMutationTools(['admin-users'])
  return useMutation({
    mutationFn: ({ id, password }: { id: string; password: string }) =>
      live.run(() => userAdminApi.resetPassword(id, password)),
  })
}

export function useToggleUser() {
  const live = useAdminMutationTools(['admin-users'])
  return useMutation({
    mutationFn: ({ id, disabled }: { id: string; disabled: boolean }) => live.run(async () => {
      if (disabled) {
        await userAdminApi.enable(id)
      } else {
        await userAdminApi.disable(id)
      }
    }),
    onSuccess: () => live.invalidate(USERS_KEY),
  })
}

/** A user's settings as administrators see them, including stored values that are no longer allowed. */
export function useUserSettings(id: string) {
  return useGetAdminUserSettingsIndex(id, {
    query: {
      retry: false,
      select: (response) => response.data ?? [],
    },
  })
}

export type UserSettingChange =
  | { id: string; key: string; action: 'set'; value: SetAdminUserSettingRequest['value'] }
  | { id: string; key: string; action: 'reset' }

/** Sets or resets one of a user's settings and stores the setting the server answers with. */
export function useChangeUserSetting() {
  const qc = useQueryClient()
  const live = useAdminMutationTools(['admin-users'])
  return useMutation({
    mutationFn: (change: UserSettingChange) =>
      live.run(() => change.action === 'set'
        ? putAdminUserSettingsSet(change.id, change.key, { value: change.value })
        : deleteAdminUserSettingsReset(change.id, change.key)),
    onSuccess: (response, change) => {
      const setting = response.data
      const queryKey = getGetAdminUserSettingsIndexQueryKey(change.id)
      if (setting) {
        qc.setQueryData<GetAdminUserSettingsIndex200>(queryKey, (current) => current && {
          ...current,
          data: current.data?.map((candidate) => (candidate.key === setting.key ? setting : candidate)),
        })
      }

      return live.invalidate(queryKey)
    },
  })
}
