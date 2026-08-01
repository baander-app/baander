import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface ActivitySummary {
  total_plays: number
  unique_tracks: number
  unique_artists: number
  total_listening_time: number
}

export interface TopTrack {
  track_name: string
  artist_name: string | null
  album_name: string | null
  play_count: number
}

export interface TopArtist {
  artist_name: string
  play_count: number
}

export interface Engagement {
  active_users: number
  avg_plays_per_user: number
  avg_session_length: number
}

export const activityAdminApi = {
  getSummary: (params?: { from?: string; to?: string }) =>
    AXIOS_INSTANCE.get<{ data: ActivitySummary }>('/api/admin/activity/summary', { params }),
  getTopTracks: (params?: { from?: string; to?: string; limit?: number }) =>
    AXIOS_INSTANCE.get<{ data: TopTrack[] }>('/api/admin/activity/top-tracks', { params }),
  getTopArtists: (params?: { from?: string; to?: string; limit?: number }) =>
    AXIOS_INSTANCE.get<{ data: TopArtist[] }>('/api/admin/activity/top-artists', { params }),
  getEngagement: (params?: { from?: string; to?: string }) =>
    AXIOS_INSTANCE.get<{ data: Engagement }>('/api/admin/activity/engagement', { params }),
}
