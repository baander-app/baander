import { useQuery } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import type { RadioStation, StarredStation } from '@/features/radio/api/radio-api'

/**
 * Starred radio stations for the catalog home.
 *
 * Replicates the original two-step effect: fetch the user's starred-station
 * records, then fetch every station and keep only the starred ones. Returns
 * an empty list when nothing is starred so the section is hidden.
 */
export function useStarredStations() {
  return useQuery({
    queryKey: ['catalog', 'starred-stations'],
    queryFn: async (): Promise<RadioStation[]> => {
      const { data: starredRes } = await AXIOS_INSTANCE.get('/api/radio/starred')
      const starred = (starredRes?.data ?? []) as StarredStation[]
      if (starred.length === 0) return []

      const { data: stationsRes } = await AXIOS_INSTANCE.get('/api/radio/stations')
      const allStations = (stationsRes?.data ?? []) as RadioStation[]

      const starredIds = new Set(starred.map((s) => s.stationId))
      return allStations.filter((s) => starredIds.has(s.id))
    },
    staleTime: 60_000,
  })
}
