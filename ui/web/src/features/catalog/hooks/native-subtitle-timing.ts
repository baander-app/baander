export function observeNativeSubtitleTiming(video: HTMLVideoElement) {
  let disposed = false
  let refreshing = false
  const update = () => {
    const now = video.currentTime
    if (disposed || refreshing || video.seeking || !Number.isFinite(now)) return
    const activeCues = Array.from(video.textTracks).flatMap(track => {
      if (track.mode !== 'showing' || (track.kind !== 'subtitles' && track.kind !== 'captions')) return []
      return Array.from(track.activeCues ?? []).map(cue => ({ track, cue }))
    })
    refreshing = true
    try {
      for (const { track, cue } of activeCues) {
        if (disposed || video.seeking) break
        const currentTime = video.currentTime
        if (!Array.from(video.textTracks).includes(track)
          || track.mode !== 'showing' || cue.track !== track || !Array.from(track.cues ?? []).includes(cue)
          || !Number.isFinite(cue.endTime) || !Number.isFinite(currentTime) || currentTime <= cue.endTime + 0.1) continue
        // Refresh native cue activity when an expired caption remains displayed.
        track.removeCue(cue)
        try {
          track.addCue(cue)
        } catch (error) {
          if (cue.track !== track) track.addCue(cue)
          throw error
        }
      }
    } finally {
      refreshing = false
    }
  }
  video.addEventListener('timeupdate', update)
  return () => {
    if (disposed) return
    disposed = true
    video.removeEventListener('timeupdate', update)
  }
}
