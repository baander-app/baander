import { useQuery } from '@tanstack/react-query'
import { activityAdminApi } from '../api/activity-admin-api'

function defaultRange() {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - 30)
  return {
    from: from.toISOString().slice(0, 10),
    to: to.toISOString().slice(0, 10),
  }
}

export function useActivitySummary(params?: { from?: string; to?: string }) {
  const range = { ...defaultRange(), ...params }
  return useQuery({
    queryKey: ['admin', 'activity', 'summary', range],
    queryFn: async () => {
      const { data } = await activityAdminApi.getSummary(range)
      return data.data
    },
  })
}

export function useTopTracks(params?: { from?: string; to?: string; limit?: number }) {
  const range = { ...defaultRange(), ...params }
  return useQuery({
    queryKey: ['admin', 'activity', 'top-tracks', range],
    queryFn: async () => {
      const { data } = await activityAdminApi.getTopTracks(range)
      return data.data
    },
  })
}

export function useTopArtists(params?: { from?: string; to?: string; limit?: number }) {
  const range = { ...defaultRange(), ...params }
  return useQuery({
    queryKey: ['admin', 'activity', 'top-artists', range],
    queryFn: async () => {
      const { data } = await activityAdminApi.getTopArtists(range)
      return data.data
    },
  })
}

export function useEngagement(params?: { from?: string; to?: string }) {
  const range = { ...defaultRange(), ...params }
  return useQuery({
    queryKey: ['admin', 'activity', 'engagement', range],
    queryFn: async () => {
      const { data } = await activityAdminApi.getEngagement(range)
      return data.data
    },
  })
}
