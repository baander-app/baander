import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { metadataAdminApi } from '../api/metadata-admin-api'

export function useMetadataSyncStatus() {
  return useQuery({
    queryKey: ['admin-metadata-sync-status'],
    queryFn: metadataAdminApi.getSyncStatus,
    refetchInterval: (q) => (q.state.data ? 30_000 : false),
    retry: false,
  })
}

export function useMetadataProviders() {
  return useQuery({
    queryKey: ['admin-metadata-providers'],
    queryFn: metadataAdminApi.getProviders,
    staleTime: 60_000,
    retry: false,
  })
}

export function useTriggerMetadataSync() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (source?: string) => metadataAdminApi.triggerSync(source),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-metadata-sync-status'] })
    },
  })
}

export function useTriggerGenreSync() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: metadataAdminApi.triggerGenreSync,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-metadata-sync-status'] })
    },
  })
}
