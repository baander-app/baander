import { useQuery } from '@tanstack/react-query'
import { radioAdminApi } from '../api/radio-admin-api'

/** The first page of the matching stations and how many match in all. */
export function useRadioStations(params?: { country?: string; q?: string }) {
  return useQuery({
    queryKey: ['admin', 'radio', 'stations', params],
    queryFn: async ({ signal }) => {
      const { data } = await radioAdminApi.getStations(params, signal)
      return { stations: data.data, total: data.meta.total }
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
