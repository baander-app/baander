import { useQuery } from '@tanstack/react-query'
import { radioAdminApi } from '../api/radio-admin-api'

export function useRadioStations(params?: { country?: string; q?: string }) {
  return useQuery({
    queryKey: ['admin', 'radio', 'stations', params],
    queryFn: async ({ signal }) => {
      const { data } = await radioAdminApi.getStations(params, signal)
      return data.data
    },
  })
}

export function useRadioCountries() {
  return useQuery({
    queryKey: ['admin', 'radio', 'countries'],
    queryFn: async ({ signal }) => {
      const { data } = await radioAdminApi.getCountries(signal)
      return data.data
    },
    staleTime: 60_000,
  })
}

export function useRadioSources() {
  return useQuery({
    queryKey: ['admin', 'radio', 'sources'],
    queryFn: async ({ signal }) => {
      const { data } = await radioAdminApi.getSources(signal)
      return data.data
    },
  })
}
