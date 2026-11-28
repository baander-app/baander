import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { metadataAdminApi, type MetadataProvider } from '../api/metadata-admin-api'
import { useAdminMutationTools } from './use-admin-mutation'

export const metadataProvidersKey = ['admin-metadata-providers'] as const

export function useMetadataSyncStatus() {
  return useQuery({
    queryKey: ['admin-metadata-sync-status'],
    queryFn: ({ signal }) => metadataAdminApi.getSyncStatus(signal),

    retry: false,
  })
}

export function useMetadataProviders() {
  return useQuery({
    queryKey: metadataProvidersKey,
    queryFn: ({ signal }) => metadataAdminApi.getProviders(signal),
    staleTime: 60_000,
    retry: false,
  })
}

export function useSetMetadataProviderEnabled(provider: string) {
  const queryClient = useQueryClient()
  const { run } = useAdminMutationTools(['admin-settings', 'admin-metadata'])

  return useMutation({
    mutationFn: (enabled: boolean) => run(() => metadataAdminApi.setProviderEnabled(provider, enabled)),
    onSuccess: async (updated) => {
      await queryClient.cancelQueries({ queryKey: metadataProvidersKey, exact: true })
      queryClient.setQueryData<MetadataProvider[]>(
        metadataProvidersKey,
        (current) => current?.map((entry) => entry.id === updated.id ? updated : entry),
      )
    },
  })
}

export function useTriggerMetadataSync() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'admin-jobs', 'catalog'])

  return useMutation({
    mutationFn: (source?: string) => run(() => metadataAdminApi.triggerSync(source)),
    onSuccess: () => {
      invalidate(['admin-metadata-sync-status'])
    },
  })
}

export function useTriggerGenreSync() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'admin-jobs', 'catalog'])

  return useMutation({
    mutationFn: () => run(() => metadataAdminApi.triggerGenreSync()),
    onSuccess: () => {
      invalidate(['admin-metadata-sync-status'])
    },
  })
}
