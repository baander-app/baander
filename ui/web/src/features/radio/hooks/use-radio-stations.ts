import { useLiveCoordinator } from '@/shared/live/context'
import { assertCurrentOwnership, captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  type RadioStation,
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
  return useQuery<RadioStation[]>({
    queryKey: [...STATIONS_KEY, { country, query }],
    queryFn: ({ signal }) => getStations(country || undefined, query || undefined, signal),
    enabled,
    placeholderData: [],
    retry: false,
  })
}

export function useSubscriptions() {
  return useQuery({
    queryKey: [...SUBSCRIPTIONS_KEY],
    queryFn: ({ signal }) => getSubscriptions(signal),
    retry: false,
  })
}

export function useStarredStations() {
  return useQuery({
    queryKey: [...STARRED_KEY],
    queryFn: ({ signal }) => getStarredStations(signal),
    retry: false,
  })
}

/** Holds snapshots until a local command settles, including when the socket is down. */
export function useRadioOperation() {
  const qc = useQueryClient()
  const coordinator = useLiveCoordinator()
  return async <T,>(operation: () => Promise<T>): Promise<T> => {
    const owner = captureOwnership()
    const release = coordinator?.beginMutation(['radio'])
    try {
      const result = await operation()
      assertCurrentOwnership(owner)
      return result
    } finally {
      release?.()
      if (!coordinator && isCurrentOwnership(owner)) {
        await Promise.all([
          qc.invalidateQueries({ queryKey: ['radio'] }),
          qc.invalidateQueries({ queryKey: ['catalog', 'starred-stations'] }),
        ])
      }
    }
  }
}

export function useStarStation() {
  const run = useRadioOperation()
  return useMutation({ mutationFn: (stationId: string) => run(() => starStation(stationId)) })
}

export function useUnstarStation() {
  const run = useRadioOperation()
  return useMutation({ mutationFn: (stationId: string) => run(() => unstarStation(stationId)) })
}
