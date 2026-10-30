import { useAdminMutationTools } from './use-admin-mutation'
import { useQuery, useMutation } from '@tanstack/react-query'
import { lyricsAdminApi } from '../api/lyrics-admin-api'

export function useLyricsCoverage() {
  return useQuery({
    queryKey: ['admin', 'lyrics', 'coverage'],
    queryFn: ({ signal }) => lyricsAdminApi.getCoverage(signal),
    staleTime: 60_000,
  })
}

export function useLyricsSyncStatus() {
  return useQuery({
    queryKey: ['admin', 'lyrics', 'sync-status'],
    queryFn: ({ signal }) => lyricsAdminApi.getSyncStatus(signal),
    staleTime: 30_000,
  })
}

export function useBulkFetchLyrics() {
  const { run, invalidate } = useAdminMutationTools(['admin-metadata', 'admin-jobs', 'lyrics'])

  return useMutation({
    mutationFn: () => run(() => lyricsAdminApi.triggerBulkFetch()),
    onSuccess: () => {
      invalidate(['admin', 'lyrics'])
    },
  })
}
