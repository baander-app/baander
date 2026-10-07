import { useQueryClient } from '@tanstack/react-query'
import {
  getGetUserSettingsIndexQueryKey,
  useDeleteUserSettingsReset,
  useGetUserSettingsIndex,
  usePutUserSettingsSet,
  type GetUserSettingsIndex200,
  type UserSettingResource,
} from '@/shared/api-client/gen/endpoints'

/** One of the signed-in user's settings, with its choice, effective value and reset value. */
export function useUserSetting(key: string) {
  return useGetUserSettingsIndex({
    query: {
      staleTime: 30_000,
      retry: false,
      select: (response) => response.data?.find((setting) => setting.key === key) ?? null,
    },
  })
}

/** Stores the setting the server answered with, then refetches the user's settings. */
function useStoreUserSetting() {
  const queryClient = useQueryClient()
  const queryKey = getGetUserSettingsIndexQueryKey()

  return (saved: UserSettingResource | undefined) => {
    if (saved) {
      queryClient.setQueryData<GetUserSettingsIndex200>(queryKey, (current) => current && {
        ...current,
        data: current.data?.map((setting) => (setting.key === saved.key ? saved : setting)),
      })
    }

    return queryClient.invalidateQueries({ queryKey })
  }
}

/** Saves the user's explicit choice for one setting. */
export function useSetUserSetting() {
  const store = useStoreUserSetting()

  return usePutUserSettingsSet({
    mutation: {
      onSuccess: (response) => store(response.data),
    },
  })
}

/** Removes the user's choice for one setting so its default applies again. */
export function useResetUserSetting() {
  const store = useStoreUserSetting()

  return useDeleteUserSettingsReset({
    mutation: {
      onSuccess: (response) => store(response.data),
    },
  })
}
