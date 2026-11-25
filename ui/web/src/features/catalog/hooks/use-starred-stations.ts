import { useQuery } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { getStationsByIds, type RadioStation, type StarredStation } from '@/features/radio/api/radio-api'

/**
 * Starred radio stations for the catalog home: the user's starred-station records,
 * then those stations read by public ID. Returns an empty list when nothing is
 * starred so the section is hidden.
 */
export function useStarredStations() {
  return useQuery({
    queryKey: ['catalog', 'starred-stations'],
    queryFn: async ({ signal }): Promise<RadioStation[]> => {
      const { data: starredRes } = await AXIOS_INSTANCE.get('/api/radio/starred', { signal })
      signal.throwIfAborted()
      const starred = (starredRes?.data ?? []) as StarredStation[]
      if (starred.length === 0) return []

      return getStationsByIds(starred.map((s) => s.stationId), signal)
    },
    staleTime: 60_000,
  })
}
