import { useAdminMutationTools } from './use-admin-mutation'
import { useQueryClient } from '@tanstack/react-query'
import {
  getGetAdminSettingsIndexQueryKey,
  patchAdminSettingsUpdate,
  deleteAdminSettingsReset,
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
  const live = useAdminMutationTools(['admin-settings'])
  const queryClient = useQueryClient()
  const queryKey = getGetAdminSettingsIndexQueryKey()

  return usePatchAdminSettingsUpdate({
    mutation: {
      mutationFn: ({ data }) => live.run(() => patchAdminSettingsUpdate(data)),
      // The response holds every system setting, so the page shows it at once; the refetch keeps
      // the list current with changes made elsewhere, as the frontend rules ask after mutations.
      onSuccess: (response) => {
        queryClient.setQueryData<GetAdminSettingsIndex200>(queryKey, response)

        return live.invalidate(queryKey)
      },
    },
  })
}

/** Removes the explicit value of one system setting so its default applies again. */
export function useResetSystemSetting() {
  const live = useAdminMutationTools(['admin-settings'])
  const queryClient = useQueryClient()
  const queryKey = getGetAdminSettingsIndexQueryKey()

  return useDeleteAdminSettingsReset({
    mutation: {
      mutationFn: ({ key }) => live.run(() => deleteAdminSettingsReset(key)),
      // The response holds the reset setting, which replaces its entry in the cached list.
      onSuccess: (response) => {
        const reset = response.data
        if (reset) {
          queryClient.setQueryData<GetAdminSettingsIndex200>(queryKey, (current) => current && {
            ...current,
            data: current.data?.map((setting) => (setting.key === reset.key ? reset : setting)),
          })
        }

        return live.invalidate(queryKey)
      },
    },
  })
}
