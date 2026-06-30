import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { mediaAdminApi } from '../api/media-admin-api'

export function useMediaStorageStats() {
  return useQuery({
    queryKey: ['admin-media-storage-stats'],
    queryFn: mediaAdminApi.getStorageStats,
    staleTime: 30_000,
    retry: false,
  })
}

export function useCheckMissingImages() {
  return useQuery({
    queryKey: ['admin-media-missing-check'],
    queryFn: mediaAdminApi.checkMissing,
    staleTime: 0,
    retry: false,
    enabled: false,
  })
}

export function usePruneMissingImages() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: mediaAdminApi.pruneMissing,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-media-storage-stats'] })
      queryClient.invalidateQueries({ queryKey: ['admin-media-missing-check'] })
    },
  })
}
