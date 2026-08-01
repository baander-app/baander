import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getStations,
  getSubscriptions,
  getStarredStations,
  starStation,
  unstarStation,
} from '@/features/radio/api/radio-api'

const STATIONS_KEY = ['radio', 'stations'] as const
const SUBSCRIPTIONS_KEY = ['radio', 'subscriptions'] as const
const STARRED_KEY = ['radio', 'starred'] as const

export function useStations(country: string, query: string, enabled: boolean) {
  return useQuery({
    queryKey: [...STATIONS_KEY, { country, query }],
    queryFn: () => getStations(country || undefined, query || undefined),
    enabled,
    placeholderData: () => [],
    retry: false,
  })
}

export function useSubscriptions() {
  return useQuery({
    queryKey: [...SUBSCRIPTIONS_KEY],
    queryFn: getSubscriptions,
    retry: false,
  })
}

export function useStarredStations() {
  return useQuery({
    queryKey: [...STARRED_KEY],
    queryFn: getStarredStations,
    retry: false,
  })
}

export function useStarStation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (stationId: string) => starStation(stationId),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...STARRED_KEY] }),
  })
}

export function useUnstarStation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (stationId: string) => unstarStation(stationId),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...STARRED_KEY] }),
  })
}
