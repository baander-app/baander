import { getSongIndex } from '@/shared/api-client/gen/endpoints'
import type { PlayerSliceContext, Track } from './player-types'
import { getPlaybackSelectionGeneration, invalidatePlaybackSelection } from './player-playback-runtime'

export function readTrackResource(value: unknown): Track | null {
  if (typeof value !== 'object' || value === null || !('publicId' in value) || !('title' in value)
    || typeof value.publicId !== 'string' || typeof value.title !== 'string') return null
  const resource = value as Record<string, unknown>
  return {
    publicId: value.publicId,
    title: value.title,
    available: resource.available === true,
    duration: typeof resource.length === 'number' && Number.isFinite(resource.length) && resource.length > 0 ? resource.length : 0,
    artistName: typeof resource.artistName === 'string' ? resource.artistName : undefined,
    albumName: typeof resource.albumName === 'string' ? resource.albumName : undefined,
    albumLanguage: typeof resource.albumLanguage === 'string' ? resource.albumLanguage : undefined,
    albumPublicId: typeof resource.albumId === 'string' ? resource.albumId : undefined,
  }
}

/** Refresh restored catalogue observations without discarding the listener's queue or metadata. */
export function hydrateQueueAvailability(
  { get, commit }: PlayerSliceContext,
  onReady: () => void,
) {
  const { queue, currentTrack } = get()
  invalidatePlaybackSelection()
  const generation = getPlaybackSelectionGeneration()
  const batches: string[][] = []
  const publicIds = [...new Set(queue.map((track) => track.publicId))]
  for (let offset = 0; offset < publicIds.length; offset += 100) {
    batches.push(publicIds.slice(offset, offset + 100))
  }
  Promise.all(batches.map((ids) => getSongIndex({ publicIds: ids.join(','), limit: ids.length }))).then((responses) => {
    if (get().queue !== queue || get().currentTrack !== currentTrack
      || generation !== getPlaybackSelectionGeneration()) return
    const observations = new Map<string, boolean>()
    for (const value of responses.flatMap((response) => response.data ?? [])) {
      if (typeof value !== 'object' || value === null || !('publicId' in value)) continue
      if (typeof value.publicId !== 'string') continue
      observations.set(value.publicId, 'available' in value && value.available === true)
    }
    const hydrated: Track[] = queue.map((track) => ({ ...track, available: observations.get(track.publicId) === true }))
    const selected = hydrated[get().currentIndex] ?? null
    commit({ queue: hydrated, currentTrack: selected })
    onReady()
  }).catch(() => {
    // A failed lookup leaves the restored selection and position intact, with playback blocked.
  })
}
