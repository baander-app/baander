import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { userAdminApi } from '../api/user-admin-api'

const USERS_KEY = ['admin-users']

export function useUsers(params?: { role?: string; disabled?: boolean }) {
  return useQuery({
    queryKey: [...USERS_KEY, params],
    queryFn: () => userAdminApi.list(params),
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
