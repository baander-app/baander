import { postActivityPlay } from '@/shared/api-client/gen/endpoints'

interface PlayPayload {
  songId: string
  albumId?: string
}

class ActivityService {
  private lastRecordedSongId: string | null = null

  /**
   * Record a play event for a song.
   * Debounces duplicate recordings for the same song.
   */
  async recordPlay(payload: PlayPayload): Promise<void> {
    // Avoid duplicate recordings for the same song in succession
    if (this.lastRecordedSongId === payload.songId) {
      return
    }

    this.lastRecordedSongId = payload.songId

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
      // Reset on error so retry is possible
      this.lastRecordedSongId = null
    }
  }

  /**
   * Reset the last recorded song ID.
   * Call this when the queue is cleared or user explicitly navigates away.
   */
  reset() {
    this.lastRecordedSongId = null
  }
}

export const activityService = new ActivityService()
