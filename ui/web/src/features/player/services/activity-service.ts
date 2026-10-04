import { postActivityPlay } from '@/shared/api-client/gen/endpoints'

interface PlayPayload {
  songId: string
  albumId?: string
}

class ActivityService {
  /** Record one caller-owned playback attempt; pauses and resumes do not call this. */
  async recordPlay(payload: PlayPayload): Promise<void> {
    try {
      await postActivityPlay({
        songId: payload.songId,
        albumId: payload.albumId ?? null,
        artistId: null,
        movieId: null,
        platform: 'web',
        player: 'baander-web',
      })
    } catch (error) {
      // Silently fail — activity recording shouldn't block playback
      console.error('[ActivityService] Failed to record play:', error)
    }
  }
}

export const activityService = new ActivityService()
