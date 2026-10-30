import { useAdminMutationTools } from './use-admin-mutation'
import { useQuery, useMutation } from '@tanstack/react-query'
import { mediaAdminApi } from '../api/media-admin-api'

export function useMediaStorageStats() {
  return useQuery({
    queryKey: ['admin-media-storage-stats'],
    queryFn: ({ signal }) => mediaAdminApi.getStorageStats(signal),
    staleTime: 30_000,
    retry: false,
  })
}

export function useCheckMissingImages() {
  return useQuery({
    queryKey: ['admin-media-missing-check'],
    queryFn: ({ signal }) => mediaAdminApi.checkMissing(signal),
    staleTime: 0,
    retry: false,
    enabled: false,
  })
}

export function usePruneMissingImages() {
  const { run, invalidate } = useAdminMutationTools(['admin-media', 'admin-jobs', 'catalog'])

  return useMutation({
    mutationFn: () => run(() => mediaAdminApi.pruneMissing()),
    onSuccess: () => {
      invalidate(['admin-media-storage-stats'])
      invalidate(['admin-media-missing-check'])
    },
  })
}
