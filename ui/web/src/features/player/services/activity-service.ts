import type { QueryClient } from '@tanstack/react-query'
import { captureOwnership, isCurrentOwnership } from '@/shared/live/ownership'
import type { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { postActivityPlay } from '@/shared/api-client/gen/endpoints'

interface PlayPayload {
  songId: string
  albumId?: string
}

class ActivityService {
  private refresh: { client: QueryClient; coordinator?: LiveQueryCoordinator; principal: string | null } | undefined

  attachRefresh(client: QueryClient, coordinator?: LiveQueryCoordinator): () => void {
    const binding = { client, coordinator, principal: captureOwnership().principal }
    this.refresh = binding
    return () => { if (this.refresh === binding) this.refresh = undefined }
  }

  /** Record one caller-owned playback attempt; pauses and resumes do not call this. */
  async recordPlay(payload: PlayPayload): Promise<void> {
    const stamp = captureOwnership()
    const refresh = this.refresh
    try {
      await postActivityPlay({
        songId: payload.songId,
        albumId: payload.albumId ?? null,
        artistId: null,
        movieId: null,
        platform: 'web',
        player: 'baander-web',
      })
      if (!isCurrentOwnership(stamp) || !refresh || refresh !== this.refresh || refresh.principal !== stamp.principal) return
      if (refresh.coordinator) refresh.coordinator.hint(['activity', 'recommendations'])
      else await refresh.client.invalidateQueries({ predicate: query =>
        ['/api/user/recent', '/api/activity/history', '/api/recommendations/', '/api/recommendations/for-you'].includes(String(query.queryKey[0])),
      })
    } catch (error) {
      // Silently fail — activity recording shouldn't block playback
      console.error('[ActivityService] Failed to record play:', error)
    }
  }
}

export const activityService = new ActivityService()
