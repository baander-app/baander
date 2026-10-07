import { useQueryClient } from '@tanstack/react-query'
import {
  getGetAdminSettingsIndexQueryKey,
  useDeleteAdminSettingsReset,
  useGetAdminSettingsDefinitions,
  useGetAdminSettingsIndex,
  usePatchAdminSettingsUpdate,
  type GetAdminSettingsIndex200,
  type SettingDefinitionResource,
} from '@/shared/api-client/gen/endpoints'

export type SystemSettingValue = boolean | number | string

/** Every system setting with its effective and stored value. */
export function useSystemSettings() {
  return useGetAdminSettingsIndex({
    query: {
      staleTime: 30_000,
      retry: false,
      select: (response) => response.data ?? [],
    },
  })
}

/** The definitions of server-wide settings; per-user definitions are left out. */
export function useSystemSettingDefinitions() {
  return useGetAdminSettingsDefinitions({
    query: {
      staleTime: 5 * 60_000,
      retry: false,
      select: (response) => (response.data ?? []).filter(isSystemDefinition),
    },
  })
}

function isSystemDefinition(definition: SettingDefinitionResource): boolean {
  return definition.scope === 'system'
}

/** Saves system settings and stores the settings the server answers with. */
export function useUpdateSystemSettings() {
  const queryClient = useQueryClient()
  const queryKey = getGetAdminSettingsIndexQueryKey()

  return usePatchAdminSettingsUpdate({
    mutation: {
      onSuccess: (response) => {
        queryClient.setQueryData<GetAdminSettingsIndex200>(queryKey, response)

        return queryClient.invalidateQueries({ queryKey })
      },
    },
  })
}

/** Removes the explicit value of one system setting so its default applies again. */
export function useResetSystemSetting() {
  const queryClient = useQueryClient()
  const queryKey = getGetAdminSettingsIndexQueryKey()

  return useDeleteAdminSettingsReset({
    mutation: {
      onSuccess: (response) => {
        const reset = response.data
        if (reset) {
          queryClient.setQueryData<GetAdminSettingsIndex200>(queryKey, (current) => current && {
            ...current,
            data: current.data?.map((setting) => (setting.key === reset.key ? reset : setting)),
          })
        }

        return queryClient.invalidateQueries({ queryKey })
      },
    },
  })
}
