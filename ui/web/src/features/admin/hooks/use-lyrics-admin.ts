import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { lyricsAdminApi } from '../api/lyrics-admin-api'

export function useLyricsCoverage() {
  return useQuery({
    queryKey: ['admin', 'lyrics', 'coverage'],
    queryFn: lyricsAdminApi.getCoverage,
    staleTime: 60_000,
  })
}

export function useLyricsSyncStatus() {
  return useQuery({
    queryKey: ['admin', 'lyrics', 'sync-status'],
    queryFn: lyricsAdminApi.getSyncStatus,
    staleTime: 30_000,
  })
}

export function useBulkFetchLyrics() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: lyricsAdminApi.triggerBulkFetch,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'lyrics'] })
    },
  })
}
