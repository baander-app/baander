import { useAdminMutationTools } from './use-admin-mutation'
import { useQuery, useMutation } from '@tanstack/react-query'
import { metadataAdminApi } from '../api/metadata-admin-api'

export function useMetadataSyncStatus() {
  return useQuery({
    queryKey: ['admin-metadata-sync-status'],
    queryFn: ({ signal }) => metadataAdminApi.getSyncStatus(signal),

    retry: false,
  })
}

export function useMetadataProviders() {
  return useQuery({
    queryKey: ['admin-metadata-providers'],
    queryFn: ({ signal }) => metadataAdminApi.getProviders(signal),
    staleTime: 60_000,
    retry: false,
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
